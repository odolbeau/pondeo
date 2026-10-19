import { Controller } from '@hotwired/stimulus';
import { session } from '@hotwired/turbo';
import { Map, Popup, setWorkerUrl, type GeoJSONSource } from 'maplibre-gl';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

// MapLibre finds its worker through a relative `import.meta.url`, which the
// production build can't follow: it would never emit the worker.
setWorkerUrl(workerUrl);

const DEBOUNCE_MS = 200;
const ZONES_SOURCE_ID = 'unites-urbaines';
const SELECTED_LAYER_ID = `${ZONES_SOURCE_ID}-selected`;
const UNITE_CODE_PATTERN = /^[0-9A-Za-z]{5}$/;
// Query params making up the filter — in the page URL and in localStorage.
const FILTER_PARAMS = ['populationMin', 'populationMax', 'excludeCouronne', 'excludePolesSecondaires', 'commerceDensityMin', 'nuclearExclusionKm', 'priceMax', 'priceType', 'restaurantDensityMin', 'withStation', 'withBoardGameAssociation', 'trainMaxTransfers'];
// One param per destination city: trainMaxMinutes[paris]=120.
const TRAIN_PARAM_PREFIX = 'trainMaxMinutes[';
const PLANTS_SOURCE_ID = 'nuclear-plants';
const PLANTS_RADIUS_SOURCE_ID = `${PLANTS_SOURCE_ID}-radius`;
const PLANT_ICON_ID = 'nuclear-plant-icon';
const EARTH_RADIUS_KM = 6371.0088;
const STORAGE_KEY = 'pondeo:map:population';
const DEFAULT_PRICE_TYPE = 'global';
const PRICE_CODES: Record<string, string> = { global: 'prix_m2', house: 'prix_m2_maison', apartment: 'prix_m2_appartement' };
const PRICE_LABELS: Record<string, string> = { global: 'Appartements et maisons', house: 'Maisons', apartment: 'Appartements' };
// Share of the unités urbaines a threshold keeps, with the percentile it sits at.
const STAT_CHIPS: { key: 'p25' | 'median' | 'p75'; keptByMin: number }[] = [
    { key: 'p25', keptByMin: 75 },
    { key: 'median', keptByMin: 50 },
    { key: 'p75', keptByMin: 25 },
];

type Stats = { min: number; p10: number; p25: number; median: number; p75: number; p90: number; max: number };

// Round-number steps ("paliers") the population slider snaps to, rather
// than letting it select an arbitrary population to the exact inhabitant —
// unreadable and not meaningfully more useful. Exact values are typed in the
// number inputs instead. Steps below the smallest unité urbaine (~2 000
// inhabitants) are dropped in connect(). Capped at 1M: distinguishing among
// the handful of agglomérations above that isn't useful on a slider, so its
// top step stands in for everything up to populationMaxValue.
const POPULATION_STEPS = [0, 2_000, 5_000, 10_000, 20_000, 50_000, 100_000, 200_000, 500_000, 1_000_000];

export default class extends Controller {
    static targets = ['mapContainer', 'minRange', 'maxRange', 'minInput', 'maxInput', 'excludeCouronne', 'excludePolesSecondaires', 'commerceDensityMin', 'restaurantDensityMin', 'nuclearExclusionKm', 'priceMax', 'priceTypeButton', 'priceHelp', 'statsHint', 'withStation', 'withBoardGameAssociation', 'trainCity', 'trainHours', 'trainMaxTransfers', 'panel', 'frame', 'filters', 'filtersButton'];
    static values = {
        dataUrl: String,
        plantsUrl: String,
        detailUrl: String,
        populationMin: Number,
        populationMax: Number,
        stats: Object,
    };

    declare readonly mapContainerTarget: HTMLDivElement;
    declare readonly minRangeTarget: HTMLInputElement;
    declare readonly maxRangeTarget: HTMLInputElement;
    declare readonly minInputTarget: HTMLInputElement;
    declare readonly maxInputTarget: HTMLInputElement;
    declare readonly excludeCouronneTarget: HTMLInputElement;
    declare readonly excludePolesSecondairesTarget: HTMLInputElement;
    declare readonly commerceDensityMinTarget: HTMLInputElement;
    declare readonly restaurantDensityMinTarget: HTMLInputElement;
    declare readonly priceMaxTarget: HTMLInputElement;
    declare readonly priceTypeButtonTargets: HTMLButtonElement[];
    declare readonly priceHelpTarget: HTMLElement;
    declare readonly statsHintTargets: HTMLElement[];
    declare readonly filtersTarget: HTMLElement;
    declare readonly filtersButtonTarget: HTMLButtonElement;
    declare readonly nuclearExclusionKmTarget: HTMLInputElement;
    declare readonly withStationTarget: HTMLInputElement;
    declare readonly withBoardGameAssociationTarget: HTMLInputElement;
    declare readonly trainCityTargets: HTMLSelectElement[];
    declare readonly trainHoursTargets: HTMLInputElement[];
    declare readonly trainMaxTransfersTarget: HTMLInputElement;
    declare readonly panelTarget: HTMLElement;
    declare readonly frameTarget: HTMLElement & { src: string | null };
    declare readonly dataUrlValue: string;
    declare readonly plantsUrlValue: string;
    declare readonly detailUrlValue: string;
    declare readonly populationMinValue: number;
    declare readonly populationMaxValue: number;
    declare readonly statsValue: Record<string, Stats>;

    private map!: Map;
    private steps!: number[];
    private selectedCode: string | null = null;
    private plants: GeoJSON.FeatureCollection<GeoJSON.Point> = { type: 'FeatureCollection', features: [] };
    private debounceHandle?: ReturnType<typeof setTimeout>;
    private priceType = DEFAULT_PRICE_TYPE;

    connect() {
        this.steps = POPULATION_STEPS.filter((s) => s > this.populationMinValue && s < this.populationMaxValue);
        this.steps.unshift(this.populationMinValue);
        this.steps.push(this.populationMaxValue);

        this.minRangeTarget.max = this.maxRangeTarget.max = String(this.steps.length - 1);
        this.applyParams(this.initialParams());
        window.addEventListener('popstate', this.onPopState);

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
                paint: { 'fill-color': '#059669', 'fill-opacity': 0.4 },
            });
            this.map.addLayer({
                id: `${ZONES_SOURCE_ID}-outline`,
                type: 'line',
                source: ZONES_SOURCE_ID,
                paint: { 'line-color': '#059669', 'line-width': 1 },
            });

            this.map.addLayer({
                id: SELECTED_LAYER_ID,
                type: 'line',
                source: ZONES_SOURCE_ID,
                filter: ['==', ['get', 'code'], ''],
                paint: { 'line-color': '#ffffff', 'line-width': 3 },
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

            const fromUrl = this.uniteFromUrl();
            if (null !== fromUrl) {
                this.applySelection(fromUrl);
            }

            this.refresh(false);
        });
    }

    disconnect() {
        window.removeEventListener('popstate', this.onPopState);
        clearTimeout(this.debounceHandle);
        this.map?.remove();
    }

    // Back/forward: the URL is the source of truth, so the inputs, the
    // selection and the map are rebuilt from it without pushing a new entry.
    // Ignored until the map layers exist; the initial load reads the URL itself.
    private onPopState = () => {
        if (!this.map.getLayer(SELECTED_LAYER_ID)) {
            return;
        }

        this.applyParams(new URLSearchParams(window.location.search));
        this.drawPlantRadii();
        this.applySelection(this.uniteFromUrl());
        this.refresh(false);
    };

    applyParams(params: { get(name: string): string | null }) {
        const [min, max] = this.orderedOrFull(params.get('populationMin'), params.get('populationMax'));
        this.excludeCouronneTarget.checked = '1' === params.get('excludeCouronne');
        this.excludePolesSecondairesTarget.checked = '1' === params.get('excludePolesSecondaires');
        this.withStationTarget.checked = '1' === params.get('withStation');
        this.withBoardGameAssociationTarget.checked = '1' === params.get('withBoardGameAssociation');
        this.commerceDensityMinTarget.value = params.get('commerceDensityMin') ?? '';
        this.nuclearExclusionKmTarget.value = params.get('nuclearExclusionKm') ?? '';
        this.priceMaxTarget.value = params.get('priceMax') ?? '';
        this.restaurantDensityMinTarget.value = params.get('restaurantDensityMin') ?? '';
        const priceType = params.get('priceType') ?? DEFAULT_PRICE_TYPE;
        this.priceType = priceType in PRICE_CODES ? priceType : DEFAULT_PRICE_TYPE;
        this.renderPriceType();
        this.renderStatsHints();
        this.applyTrainParams(params);
        this.trainMaxTransfersTarget.value = params.get('trainMaxTransfers') ?? '';
        this.writeInputs(min, max);
        this.syncRanges(min, max);
    }

    // The rows are filled in order with the destinations the params mention.
    applyTrainParams(params: { get(name: string): string | null }) {
        const destinations = [...this.trainCityTargets[0].options].map((option) => option.value).filter((value) => '' !== value);
        const limits = destinations
            .map((destination): [string, string | null] => [destination, params.get(`${TRAIN_PARAM_PREFIX}${destination}]`)])
            .filter((limit): limit is [string, string] => null !== limit[1]);

        this.trainCityTargets.forEach((select, row) => {
            const limit = limits[row];
            select.value = limit?.[0] ?? '';
            this.trainHoursTargets[row].value = limit ? String(Number(limit[1]) / 60) : '';
        });
    }

    uniteFromUrl(): string | null {
        const code = new URLSearchParams(window.location.search).get('unite');

        return null !== code && UNITE_CODE_PATTERN.test(code) ? code : null;
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
        this.applySelection(code);
        this.updateUrl(...this.valuesFromInputs(), true);
    }

    closePanel() {
        this.applySelection(null);
        this.updateUrl(...this.valuesFromInputs(), true);
    }

    // The panel and the highlight only; the URL is the caller's concern.
    applySelection(code: string | null) {
        this.selectedCode = code;
        if (null === code) {
            this.panelTarget.classList.add('hidden');
            this.frameTarget.removeAttribute('src');
            this.frameTarget.innerHTML = '';
        } else {
            this.frameTarget.src = this.detailUrlValue.replace('XXXXX', code);
            this.panelTarget.classList.remove('hidden');
        }
        this.map.setFilter(SELECTED_LAYER_ID, ['==', ['get', 'code'], code ?? '']);
    }

    onToggleChange() {
        this.scheduleRefresh();
    }

    scheduleRefresh() {
        clearTimeout(this.debounceHandle);
        this.debounceHandle = setTimeout(() => this.refresh(), DEBOUNCE_MS);
    }

    async refresh(pushHistory = true) {
        const [min, max] = this.valuesFromInputs();

        this.updateUrl(min, max, pushHistory);
        this.saveFilter(min, max);

        const activeFilters = [...this.filterParams(min, max).keys()].length;
        this.filtersButtonTarget.textContent = 0 === activeFilters ? 'Filtres' : `Filtres (${activeFilters})`;

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

    onRestaurantInput() {
        this.scheduleRefresh();
    }

    onPriceInput() {
        this.scheduleRefresh();
    }

    onPriceTypeClick(event: Event) {
        const type = (event.currentTarget as HTMLElement).dataset.priceType ?? DEFAULT_PRICE_TYPE;
        this.priceType = type in PRICE_CODES ? type : DEFAULT_PRICE_TYPE;
        this.renderPriceType();
        this.renderStatsHints();
        this.scheduleRefresh();
    }

    // The filter drawer below md: the map stays the default view.
    openFilters() {
        this.filtersTarget.classList.remove('hidden');
    }

    closeFilters() {
        this.filtersTarget.classList.add('hidden');
    }

    renderPriceType() {
        for (const button of this.priceTypeButtonTargets) {
            button.setAttribute('aria-pressed', String(button.dataset.priceType === this.priceType));
        }
        this.priceHelpTarget.textContent = `${PRICE_LABELS[this.priceType]}, dernière année (DVF)`;
    }

    // Reference points under a filter: where the median sits, and clickable
    // thresholds that each keep a given share of the unités urbaines.
    renderStatsHints() {
        for (const hint of this.statsHintTargets) {
            const code = hint.dataset.statsCode ?? PRICE_CODES[this.priceType];
            const stats = this.statsValue[code];
            hint.replaceChildren();
            if (undefined === stats) {
                continue;
            }

            const input = this[`${hint.dataset.statsInput}Target` as 'priceMaxTarget'];
            const round = this.rounder(Number(hint.dataset.statsDecimals));
            const format = (value: number) => new Intl.NumberFormat('fr-FR').format(round(value));
            const unit = hint.dataset.statsUnit ?? '';
            const isMin = 'min' === hint.dataset.statsDirection;

            const summary = document.createElement('p');
            summary.textContent = `Médiane ${format(stats.median)} ${unit} · 80 % des villes entre ${format(stats.p10)} et ${format(stats.p90)}`;
            const chips = document.createElement('p');
            chips.className = 'mt-1 flex flex-wrap items-center gap-1';
            chips.append('Seuils :');
            for (const chip of STAT_CHIPS) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'rounded border border-stone-300 px-1.5 py-0.5 text-stone-700 hover:border-emerald-600';
                button.textContent = `${isMin ? '≥' : '≤'} ${format(stats[chip.key])}`;
                button.dataset.tooltip = `Garde ${isMin ? chip.keptByMin : 100 - chip.keptByMin} % des villes`;
                button.addEventListener('click', () => {
                    input.value = String(round(stats[chip.key]));
                    this.scheduleRefresh();
                });
                chips.append(button);
            }
            hint.append(summary, chips);
        }
    }

    // Positive decimals: that many digits; negative: prices, to the nearest 50 €.
    rounder(decimals: number): (value: number) => number {
        if (decimals < 0) {
            return (value) => Math.round(value / 50) * 50;
        }
        const factor = 10 ** decimals;

        return (value) => Math.round(value * factor) / factor;
    }

    onTrainInput() {
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

    restaurantDensityMin(): number | null {
        return this.positiveNumber(this.restaurantDensityMinTarget.value);
    }

    priceMax(): number | null {
        return this.positiveNumber(this.priceMaxTarget.value);
    }

    nuclearExclusionKm(): number | null {
        return this.positiveNumber(this.nuclearExclusionKmTarget.value);
    }

    // Rows left empty are off; a city picked twice keeps its strictest limit.
    trainLimits(): [string, number][] {
        const limits: Record<string, number> = {};
        this.trainCityTargets.forEach((select, row) => {
            const hours = this.positiveNumber(this.trainHoursTargets[row].value);
            if ('' === select.value || null === hours) {
                return;
            }
            limits[select.value] = Math.min(limits[select.value] ?? Infinity, Math.round(hours * 60));
        });

        return Object.entries(limits);
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
            text.className = 'text-sm text-stone-900';
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
    updateUrl(min: number, max: number, pushHistory: boolean) {
        const params = this.filterParams(min, max);
        if (null !== this.selectedCode) {
            params.set('unite', this.selectedCode);
        }

        const query = params.toString();
        const target = query ? `?${query}` : window.location.pathname;
        if (target === window.location.search || target === window.location.pathname + window.location.search) {
            return;
        }

        if (pushHistory) {
            // Through Turbo, not history.pushState(): an entry without Turbo's state is
            // ignored when coming back to it from another page, which then keeps showing.
            session.history.push(new URL(target, window.location.href));

            return;
        }
        // Keep the current state: it carries Turbo's restoration data, without
        // which the back button silently does nothing on this entry.
        history.replaceState(history.state, '', target);
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
        if (this.withBoardGameAssociationTarget.checked) {
            params.set('withBoardGameAssociation', '1');
        }
        const density = this.commerceDensityMin();
        if (null !== density) {
            params.set('commerceDensityMin', String(density));
        }
        const nuclearKm = this.nuclearExclusionKm();
        if (null !== nuclearKm) {
            params.set('nuclearExclusionKm', String(nuclearKm));
        }
        const restaurantDensity = this.restaurantDensityMin();
        if (null !== restaurantDensity) {
            params.set('restaurantDensityMin', String(restaurantDensity));
        }
        const priceMax = this.priceMax();
        if (null !== priceMax) {
            params.set('priceMax', String(priceMax));
        }
        if (DEFAULT_PRICE_TYPE !== this.priceType) {
            params.set('priceType', this.priceType);
        }
        const transfers = this.trainMaxTransfersTarget.value.trim();
        if ('' !== transfers && Number.isInteger(Number(transfers)) && Number(transfers) >= 0) {
            params.set('trainMaxTransfers', transfers);
        }
        for (const [destination, minutes] of this.trainLimits()) {
            params.set(`${TRAIN_PARAM_PREFIX}${destination}]`, String(minutes));
        }

        return params;
    }

    // The URL wins so a shared link shows what its author saw; otherwise the
    // last filter from this browser is restored, then the defaults.
    initialParams(): { get(name: string): string | null } {
        const params = new URLSearchParams(window.location.search);
        if ([...params.keys()].some((name) => FILTER_PARAMS.includes(name) || name.startsWith(TRAIN_PARAM_PREFIX))) {
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
