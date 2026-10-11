import { Controller } from '@hotwired/stimulus';
import { Map, Popup, type GeoJSONSource } from 'maplibre-gl';

const DEBOUNCE_MS = 200;
const ZONES_SOURCE_ID = 'unites-urbaines';
const SELECTED_LAYER_ID = `${ZONES_SOURCE_ID}-selected`;
const UNITE_CODE_PATTERN = /^[0-9A-Za-z]{5}$/;
// Query params making up the filter — in the page URL and in localStorage.
const FILTER_PARAMS = ['populationMin', 'populationMax', 'excludeCouronne', 'excludePolesSecondaires', 'commerceDensityMin', 'nuclearExclusionKm', 'priceMax', 'withStation'];
const PLANTS_SOURCE_ID = 'nuclear-plants';
const PLANTS_RADIUS_SOURCE_ID = `${PLANTS_SOURCE_ID}-radius`;
const PLANT_ICON_ID = 'nuclear-plant-icon';
const EARTH_RADIUS_KM = 6371.0088;
const STORAGE_KEY = 'pondeo:map:population';

// Round-number steps ("paliers") the population slider snaps to, rather
// than letting it select an arbitrary population to the exact inhabitant —
// unreadable and not meaningfully more useful. Exact values are typed in the
// number inputs instead. Steps below the smallest unité urbaine (~2 000
// inhabitants) are dropped in connect(). Capped at 1M: distinguishing among
// the handful of agglomérations above that isn't useful on a slider, so its
// top step stands in for everything up to populationMaxValue.
const POPULATION_STEPS = [0, 2_000, 5_000, 10_000, 20_000, 50_000, 100_000, 200_000, 500_000, 1_000_000];

export default class extends Controller {
    static targets = ['mapContainer', 'minRange', 'maxRange', 'minInput', 'maxInput', 'excludeCouronne', 'excludePolesSecondaires', 'commerceDensityMin', 'nuclearExclusionKm', 'priceMax', 'withStation', 'panel', 'frame'];
    static values = {
        dataUrl: String,
        plantsUrl: String,
        detailUrl: String,
        populationMin: Number,
        populationMax: Number,
    };

    declare readonly mapContainerTarget: HTMLDivElement;
    declare readonly minRangeTarget: HTMLInputElement;
    declare readonly maxRangeTarget: HTMLInputElement;
    declare readonly minInputTarget: HTMLInputElement;
    declare readonly maxInputTarget: HTMLInputElement;
    declare readonly excludeCouronneTarget: HTMLInputElement;
    declare readonly excludePolesSecondairesTarget: HTMLInputElement;
    declare readonly commerceDensityMinTarget: HTMLInputElement;
    declare readonly priceMaxTarget: HTMLInputElement;
    declare readonly nuclearExclusionKmTarget: HTMLInputElement;
    declare readonly withStationTarget: HTMLInputElement;
    declare readonly panelTarget: HTMLElement;
    declare readonly frameTarget: HTMLElement & { src: string | null };
    declare readonly dataUrlValue: string;
    declare readonly plantsUrlValue: string;
    declare readonly detailUrlValue: string;
    declare readonly populationMinValue: number;
    declare readonly populationMaxValue: number;

    private map!: Map;
    private steps!: number[];
    private selectedCode: string | null = null;
    private plants: GeoJSON.FeatureCollection<GeoJSON.Point> = { type: 'FeatureCollection', features: [] };
    private debounceHandle?: ReturnType<typeof setTimeout>;

    connect() {
        this.steps = POPULATION_STEPS.filter((s) => s > this.populationMinValue && s < this.populationMaxValue);
        this.steps.unshift(this.populationMinValue);
        this.steps.push(this.populationMaxValue);

        this.minRangeTarget.max = this.maxRangeTarget.max = String(this.steps.length - 1);
        const initial = this.initialParams();
        const [min, max] = this.orderedOrFull(initial.get('populationMin'), initial.get('populationMax'));
        this.excludeCouronneTarget.checked = '1' === initial.get('excludeCouronne');
        this.excludePolesSecondairesTarget.checked = '1' === initial.get('excludePolesSecondaires');
        this.withStationTarget.checked = '1' === initial.get('withStation');
        this.commerceDensityMinTarget.value = initial.get('commerceDensityMin') ?? '';
        this.nuclearExclusionKmTarget.value = initial.get('nuclearExclusionKm') ?? '';
        this.priceMaxTarget.value = initial.get('priceMax') ?? '';
        this.writeInputs(min, max);
        this.syncRanges(min, max);

        this.map = new Map({
            container: this.mapContainerTarget,
            style: 'https://tiles.openfreemap.org/styles/liberty',
            center: [2.5, 46.6],
            zoom: 5,
        });

        this.map.on('load', () => {
            this.map.addSource(ZONES_SOURCE_ID, { type: 'geojson', data: this.emptyFeatureCollection() });
            this.map.addLayer({
                id: `${ZONES_SOURCE_ID}-fill`,
                type: 'fill',
                source: ZONES_SOURCE_ID,
                paint: { 'fill-color': '#34d399', 'fill-opacity': 0.4 },
            });
            this.map.addLayer({
                id: `${ZONES_SOURCE_ID}-outline`,
                type: 'line',
                source: ZONES_SOURCE_ID,
                paint: { 'line-color': '#34d399', 'line-width': 1 },
            });

            this.map.addLayer({
                id: SELECTED_LAYER_ID,
                type: 'line',
                source: ZONES_SOURCE_ID,
                filter: ['==', ['get', 'code'], ''],
                paint: { 'line-color': '#f8fafc', 'line-width': 3 },
            });

            this.map.on('click', `${ZONES_SOURCE_ID}-fill`, (event) => {
                const code = event.features?.[0]?.properties?.code;
                if (code) {
                    this.select(String(code));
                }
            });
            this.map.on('mouseenter', `${ZONES_SOURCE_ID}-fill`, () => (this.map.getCanvas().style.cursor = 'pointer'));
            this.map.on('mouseleave', `${ZONES_SOURCE_ID}-fill`, () => (this.map.getCanvas().style.cursor = ''));

            this.loadPlants();

            const fromUrl = new URLSearchParams(window.location.search).get('unite');
            if (null !== fromUrl && UNITE_CODE_PATTERN.test(fromUrl)) {
                this.select(fromUrl);
            }

            this.refresh();
        });
    }

    disconnect() {
        clearTimeout(this.debounceHandle);
        this.map?.remove();
    }

    onRangeInput() {
        const a = this.steps[Number(this.minRangeTarget.value)];
        const b = this.steps[Number(this.maxRangeTarget.value)];
        this.writeInputs(Math.min(a, b), Math.max(a, b));

        this.scheduleRefresh();
    }

    // Fires on every keystroke: the sliders follow, but the typed text is
    // left alone so the user isn't fought while typing.
    onNumberInput() {
        const [min, max] = this.valuesFromInputs();
        this.syncRanges(min, max);

        this.scheduleRefresh();
    }

    // Once editing is done, the inputs are rewritten with the clamped and
    // ordered values that are actually applied.
    onNumberChange() {
        const [min, max] = this.valuesFromInputs();
        this.writeInputs(min, max);
        this.syncRanges(min, max);

        this.scheduleRefresh();
    }

    select(code: string) {
        this.selectedCode = code;
        this.frameTarget.src = this.detailUrlValue.replace('XXXXX', code);
        this.panelTarget.classList.remove('hidden');
        this.map.setFilter(SELECTED_LAYER_ID, ['==', ['get', 'code'], code]);

        this.updateUrl(...this.valuesFromInputs());
    }

    closePanel() {
        this.selectedCode = null;
        this.panelTarget.classList.add('hidden');
        this.frameTarget.removeAttribute('src');
        this.frameTarget.innerHTML = '';
        this.map.setFilter(SELECTED_LAYER_ID, ['==', ['get', 'code'], '']);

        this.updateUrl(...this.valuesFromInputs());
    }

    onToggleChange() {
        this.scheduleRefresh();
    }

    scheduleRefresh() {
        clearTimeout(this.debounceHandle);
        this.debounceHandle = setTimeout(() => this.refresh(), DEBOUNCE_MS);
    }

    async refresh() {
        const [min, max] = this.valuesFromInputs();

        this.updateUrl(min, max);
        this.saveFilter(min, max);

        const url = new URL(this.dataUrlValue, window.location.origin);
        for (const [name, value] of this.filterParams(min, max)) {
            url.searchParams.set(name, value);
        }
        // The endpoint's population bounds default to the full range anyway,
        // but sending both keeps the request explicit.
        url.searchParams.set('populationMin', String(min));
        url.searchParams.set('populationMax', String(max));

        const response = await fetch(url);
        const geojson: GeoJSON.FeatureCollection = await response.json();

        (this.map.getSource(ZONES_SOURCE_ID) as GeoJSONSource | undefined)?.setData(geojson);
    }

    onCommerceInput() {
        this.scheduleRefresh();
    }

    onPriceInput() {
        this.scheduleRefresh();
    }

    onNuclearInput() {
        this.drawPlantRadii();
        this.scheduleRefresh();
    }

    // Null (filter off) for an empty or invalid field.
    commerceDensityMin(): number | null {
        return this.positiveNumber(this.commerceDensityMinTarget.value);
    }

    priceMax(): number | null {
        return this.positiveNumber(this.priceMaxTarget.value);
    }

    nuclearExclusionKm(): number | null {
        return this.positiveNumber(this.nuclearExclusionKmTarget.value);
    }

    positiveNumber(raw: string): number | null {
        if ('' === raw.trim()) {
            return null;
        }

        const value = Number(raw);

        return Number.isFinite(value) && value > 0 ? value : null;
    }

    // The plants are always shown; their radius circles only while the
    // exclusion filter is set.
    async loadPlants() {
        const response = await fetch(this.plantsUrlValue);
        this.plants = await response.json();

        this.map.addImage(PLANT_ICON_ID, this.plantIcon());
        this.map.addSource(PLANTS_SOURCE_ID, { type: 'geojson', data: this.plants });
        this.map.addSource(PLANTS_RADIUS_SOURCE_ID, { type: 'geojson', data: this.emptyFeatureCollection() });
        this.map.addLayer({
            id: `${PLANTS_RADIUS_SOURCE_ID}-fill`,
            type: 'fill',
            source: PLANTS_RADIUS_SOURCE_ID,
            paint: { 'fill-color': '#dc2626', 'fill-opacity': 0.18 },
        });
        this.map.addLayer({
            id: `${PLANTS_RADIUS_SOURCE_ID}-line`,
            type: 'line',
            source: PLANTS_RADIUS_SOURCE_ID,
            paint: { 'line-color': '#dc2626', 'line-width': 1.5 },
        });
        this.map.addLayer({
            id: `${PLANTS_SOURCE_ID}-icon`,
            type: 'symbol',
            source: PLANTS_SOURCE_ID,
            layout: { 'icon-image': PLANT_ICON_ID, 'icon-size': 0.3, 'icon-allow-overlap': true },
        });

        const popup = new Popup({ closeButton: false, offset: 16 });
        this.map.on('mouseenter', `${PLANTS_SOURCE_ID}-icon`, (event) => {
            const feature = event.features?.[0];
            if (!feature || 'Point' !== feature.geometry.type) {
                return;
            }
            const { name, reactors, powerMw } = feature.properties;
            const details = null === reactors || undefined === reactors ? '' : ` (${reactors} réacteurs, ${powerMw} MW)`;
            const text = document.createElement('span');
            // The popup is white whatever the page theme: the page's light text would vanish on it.
            text.className = 'text-sm text-slate-900';
            text.textContent = `${name}${details}`;
            popup.setLngLat(feature.geometry.coordinates as [number, number]).setDOMContent(text).addTo(this.map);
        });
        this.map.on('mouseleave', `${PLANTS_SOURCE_ID}-icon`, () => popup.remove());

        this.drawPlantRadii();
    }

    drawPlantRadii() {
        const km = this.nuclearExclusionKm();
        const features = null === km ? [] : this.plants.features.map((plant) => this.circle(plant.geometry.coordinates as [number, number], km));

        (this.map.getSource(PLANTS_RADIUS_SOURCE_ID) as GeoJSONSource | undefined)?.setData({ type: 'FeatureCollection', features });
    }

    // A circle of a given ground radius, as a polygon: MapLibre's own circle
    // layer is sized in pixels, not kilometres.
    circle([lon, lat]: [number, number], km: number): GeoJSON.Feature<GeoJSON.Polygon> {
        const angular = km / EARTH_RADIUS_KM;
        const lat1 = (lat * Math.PI) / 180;
        const lon1 = (lon * Math.PI) / 180;
        const ring: [number, number][] = [];
        for (let i = 0; i <= 64; i++) {
            const bearing = (2 * Math.PI * i) / 64;
            const lat2 = Math.asin(Math.sin(lat1) * Math.cos(angular) + Math.cos(lat1) * Math.sin(angular) * Math.cos(bearing));
            const lon2 = lon1 + Math.atan2(Math.sin(bearing) * Math.sin(angular) * Math.cos(lat1), Math.cos(angular) - Math.sin(lat1) * Math.sin(lat2));
            ring.push([(lon2 * 180) / Math.PI, (lat2 * 180) / Math.PI]);
        }

        return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [ring] }, properties: {} };
    }

    // The ☢️ emoji rendered to a bitmap: a symbol layer only takes images.
    plantIcon(): ImageData {
        const size = 64;
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = size;
        const context = canvas.getContext('2d');
        if (null === context) {
            throw new Error('Canvas 2D context unavailable.');
        }
        context.font = `${size * 0.85}px sans-serif`;
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillText('☢️', size / 2, size / 2 + size * 0.05);

        return context.getImageData(0, 0, size, size);
    }

    // An empty or invalid field falls back to its bound; the pair is then
    // ordered, so typing a min above the max doesn't produce an empty map.
    valuesFromInputs(): [number, number] {
        const min = this.clamp(this.minInputTarget.value, this.populationMinValue);
        const max = this.clamp(this.maxInputTarget.value, this.populationMaxValue);

        return min <= max ? [min, max] : [max, min];
    }

    clamp(raw: string | null, fallback: number): number {
        if (null === raw || '' === raw.trim()) {
            return fallback;
        }

        const value = Math.round(Number(raw));
        if (!Number.isFinite(value)) {
            return fallback;
        }

        return Math.min(Math.max(value, this.populationMinValue), this.populationMaxValue);
    }

    writeInputs(min: number, max: number) {
        this.minInputTarget.value = String(min);
        this.maxInputTarget.value = String(max);
    }

    // The slider only shows the nearest step; the exact value lives in the
    // inputs.
    syncRanges(min: number, max: number) {
        this.minRangeTarget.value = String(this.nearestStepIndex(min));
        this.maxRangeTarget.value = String(this.nearestStepIndex(max));
    }

    nearestStepIndex(value: number): number {
        let nearest = 0;
        this.steps.forEach((step, i) => {
            if (Math.abs(step - value) < Math.abs(this.steps[nearest] - value)) {
                nearest = i;
            }
        });

        return nearest;
    }

    // Makes the current filter shareable: reflected in the page URL, and
    // read back from it on load (the selected unité urbaine too). A bound left at its default (the full
    // range) is omitted entirely, so an untouched filter keeps a clean URL.
    updateUrl(min: number, max: number) {
        const params = this.filterParams(min, max);
        if (null !== this.selectedCode) {
            params.set('unite', this.selectedCode);
        }

        const query = params.toString();
        // Keep the current state: it carries Turbo's restoration data, without
        // which the back button silently does nothing on this entry.
        history.replaceState(history.state, '', query ? `?${query}` : window.location.pathname);
    }

    // The filter as query params, leaving out whatever is at its default.
    filterParams(min: number, max: number): URLSearchParams {
        const params = new URLSearchParams();
        if (min !== this.populationMinValue) {
            params.set('populationMin', String(min));
        }
        if (max !== this.populationMaxValue) {
            params.set('populationMax', String(max));
        }
        if (this.excludeCouronneTarget.checked) {
            params.set('excludeCouronne', '1');
        }
        if (this.excludePolesSecondairesTarget.checked) {
            params.set('excludePolesSecondaires', '1');
        }
        if (this.withStationTarget.checked) {
            params.set('withStation', '1');
        }
        const density = this.commerceDensityMin();
        if (null !== density) {
            params.set('commerceDensityMin', String(density));
        }
        const nuclearKm = this.nuclearExclusionKm();
        if (null !== nuclearKm) {
            params.set('nuclearExclusionKm', String(nuclearKm));
        }
        const priceMax = this.priceMax();
        if (null !== priceMax) {
            params.set('priceMax', String(priceMax));
        }

        return params;
    }

    // The URL wins so a shared link shows what its author saw; otherwise the
    // last filter from this browser is restored, then the defaults.
    initialParams(): { get(name: string): string | null } {
        const params = new URLSearchParams(window.location.search);
        if (FILTER_PARAMS.some((name) => params.has(name))) {
            return params;
        }

        const saved = this.loadFilter();

        return { get: (name) => saved?.[name] ?? null };
    }

    orderedOrFull(rawMin: string | null, rawMax: string | null): [number, number] {
        const min = this.clamp(rawMin, this.populationMinValue);
        const max = this.clamp(rawMax, this.populationMaxValue);

        return min <= max ? [min, max] : [this.populationMinValue, this.populationMaxValue];
    }

    // localStorage can throw (private mode, blocked site data) or hold
    // garbage, and the filter must work without it.
    saveFilter(min: number, max: number) {
        try {
            const params = this.filterParams(min, max);
            if (0 === [...params].length) {
                localStorage.removeItem(STORAGE_KEY);

                return;
            }
            localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.fromEntries(params)));
        } catch {
            // Not persisting is an acceptable degradation.
        }
    }

    loadFilter(): Record<string, string> | null {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);

            return null === raw ? null : JSON.parse(raw);
        } catch {
            return null;
        }
    }

    emptyFeatureCollection(): GeoJSON.FeatureCollection {
        return { type: 'FeatureCollection', features: [] };
    }
}
