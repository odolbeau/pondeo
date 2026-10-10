import { Controller } from '@hotwired/stimulus';
import { Map, type GeoJSONSource } from 'maplibre-gl';

const DEBOUNCE_MS = 200;
const ZONES_SOURCE_ID = 'unites-urbaines';
const SELECTED_LAYER_ID = `${ZONES_SOURCE_ID}-selected`;
const UNITE_CODE_PATTERN = /^[0-9A-Za-z]{5}$/;
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
    static targets = ['mapContainer', 'minRange', 'maxRange', 'minInput', 'maxInput', 'panel', 'frame'];
    static values = {
        dataUrl: String,
        detailUrl: String,
        populationMin: Number,
        populationMax: Number,
    };

    declare readonly mapContainerTarget: HTMLDivElement;
    declare readonly minRangeTarget: HTMLInputElement;
    declare readonly maxRangeTarget: HTMLInputElement;
    declare readonly minInputTarget: HTMLInputElement;
    declare readonly maxInputTarget: HTMLInputElement;
    declare readonly panelTarget: HTMLElement;
    declare readonly frameTarget: HTMLElement & { src: string | null };
    declare readonly dataUrlValue: string;
    declare readonly detailUrlValue: string;
    declare readonly populationMinValue: number;
    declare readonly populationMaxValue: number;

    private map!: Map;
    private steps!: number[];
    private selectedCode: string | null = null;
    private debounceHandle?: ReturnType<typeof setTimeout>;

    connect() {
        this.steps = POPULATION_STEPS.filter((s) => s > this.populationMinValue && s < this.populationMaxValue);
        this.steps.unshift(this.populationMinValue);
        this.steps.push(this.populationMaxValue);

        this.minRangeTarget.max = this.maxRangeTarget.max = String(this.steps.length - 1);
        const [min, max] = this.initialValues();
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

    scheduleRefresh() {
        clearTimeout(this.debounceHandle);
        this.debounceHandle = setTimeout(() => this.refresh(), DEBOUNCE_MS);
    }

    async refresh() {
        const [min, max] = this.valuesFromInputs();

        this.updateUrl(min, max);
        this.saveFilter(min, max);

        const url = new URL(this.dataUrlValue, window.location.origin);
        url.searchParams.set('populationMin', String(min));
        url.searchParams.set('populationMax', String(max));

        const response = await fetch(url);
        const geojson: GeoJSON.FeatureCollection = await response.json();

        (this.map.getSource(ZONES_SOURCE_ID) as GeoJSONSource | undefined)?.setData(geojson);
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
        const params = new URLSearchParams();
        if (min !== this.populationMinValue) {
            params.set('populationMin', String(min));
        }
        if (max !== this.populationMaxValue) {
            params.set('populationMax', String(max));
        }

        if (null !== this.selectedCode) {
            params.set('unite', this.selectedCode);
        }

        const query = params.toString();
        history.replaceState(null, '', query ? `?${query}` : window.location.pathname);
    }

    // The URL wins so a shared link shows what its author saw; otherwise the
    // last filter from this browser is restored, then the full range.
    initialValues(): [number, number] {
        const params = new URLSearchParams(window.location.search);
        if (params.has('populationMin') || params.has('populationMax')) {
            return this.orderedOrFull(params.get('populationMin'), params.get('populationMax'));
        }

        const saved = this.loadFilter();

        return this.orderedOrFull(saved?.populationMin ?? null, saved?.populationMax ?? null);
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
            if (min === this.populationMinValue && max === this.populationMaxValue) {
                localStorage.removeItem(STORAGE_KEY);

                return;
            }
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ populationMin: String(min), populationMax: String(max) }));
        } catch {
            // Not persisting is an acceptable degradation.
        }
    }

    loadFilter(): { populationMin?: string; populationMax?: string } | null {
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
