import { Controller } from '@hotwired/stimulus';
import { Map, type GeoJSONSource } from 'maplibre-gl';

const DEBOUNCE_MS = 200;
const ZONES_SOURCE_ID = 'communes';

// Round-number steps ("paliers") the population slider snaps to, rather
// than letting it select an arbitrary population to the exact inhabitant —
// unreadable and not meaningfully more useful. Density is highest at the
// low end because that's where almost all communes are (a few tens of
// thousands at most; Paris alone is at 2.1M). Capped at 200k: distinguishing
// among the handful of cities above that isn't useful here, so the top step
// is an open-ended "200 000 et plus" bucket instead of more steps up to
// Paris's exact population.
const POPULATION_STEPS = [0, 100, 200, 500, 1_000, 2_000, 5_000, 10_000, 20_000, 50_000, 100_000, 200_000];

export default class extends Controller {
    static targets = ['mapContainer', 'minRange', 'maxRange', 'minLabel', 'maxLabel'];
    static values = {
        dataUrl: String,
        populationMin: Number,
        populationMax: Number,
    };

    declare readonly mapContainerTarget: HTMLDivElement;
    declare readonly minRangeTarget: HTMLInputElement;
    declare readonly maxRangeTarget: HTMLInputElement;
    declare readonly minLabelTarget: HTMLElement;
    declare readonly maxLabelTarget: HTMLElement;
    declare readonly dataUrlValue: string;
    declare readonly populationMinValue: number;
    declare readonly populationMaxValue: number;

    private map!: Map;
    private steps!: number[];
    private topStepIsCapped!: boolean;
    private debounceHandle?: ReturnType<typeof setTimeout>;

    connect() {
        this.steps = POPULATION_STEPS.filter((s) => s >= this.populationMinValue && s <= this.populationMaxValue);
        if (0 === this.steps.length || this.steps[0] > this.populationMinValue) {
            this.steps.unshift(this.populationMinValue);
        }
        // Whether the last step is a real bound or an open-ended "and
        // above" bucket standing in for everything up to populationMaxValue.
        this.topStepIsCapped = this.steps[this.steps.length - 1] < this.populationMaxValue;

        this.minRangeTarget.max = this.maxRangeTarget.max = String(this.steps.length - 1);
        const [initialLo, initialHi] = this.indexesFromUrl();
        this.minRangeTarget.value = String(initialLo);
        this.maxRangeTarget.value = String(initialHi);
        this.updateLabels();

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

            this.refresh();
        });
    }

    disconnect() {
        clearTimeout(this.debounceHandle);
        this.map?.remove();
    }

    onRangeInput() {
        this.updateLabels();

        clearTimeout(this.debounceHandle);
        this.debounceHandle = setTimeout(() => this.refresh(), DEBOUNCE_MS);
    }

    updateLabels() {
        const [lo, hi] = this.sortedIndexes();

        this.minLabelTarget.textContent = this.stepLabel(lo);
        this.maxLabelTarget.textContent = this.stepLabel(hi);
    }

    async refresh() {
        const [lo, hi] = this.sortedIndexes();

        this.updateUrl(lo, hi);

        const url = new URL(this.dataUrlValue, window.location.origin);
        url.searchParams.set('populationMin', String(this.stepValue(lo)));
        url.searchParams.set('populationMax', String(this.stepValue(hi)));

        const response = await fetch(url);
        const geojson: GeoJSON.FeatureCollection = await response.json();

        (this.map.getSource(ZONES_SOURCE_ID) as GeoJSONSource | undefined)?.setData(geojson);
    }

    sortedIndexes(): [number, number] {
        const a = Number(this.minRangeTarget.value);
        const b = Number(this.maxRangeTarget.value);

        return a <= b ? [a, b] : [b, a];
    }

    // Makes the current filter shareable: reflected in the page URL, and
    // read back from it on load. A bound left at its default (the full
    // range) is omitted entirely, so an untouched filter keeps a clean URL.
    updateUrl(lo: number, hi: number) {
        const params = new URLSearchParams();
        if (lo !== 0) {
            params.set('populationMin', String(this.stepValue(lo)));
        }
        if (hi !== this.steps.length - 1) {
            params.set('populationMax', String(this.stepValue(hi)));
        }

        const query = params.toString();
        history.replaceState(null, '', query ? `?${query}` : window.location.pathname);
    }

    // Reads populationMin/populationMax back from the page URL (see
    // updateUrl()) to restore a shared filter on load. Falls back to the
    // full range — including when a value doesn't match any step, e.g. a
    // hand-edited URL — rather than guessing the closest step.
    indexesFromUrl(): [number, number] {
        const params = new URLSearchParams(window.location.search);
        const lo = this.indexForUrlValue(params.get('populationMin')) ?? 0;
        const hi = this.indexForUrlValue(params.get('populationMax')) ?? this.steps.length - 1;

        return lo <= hi ? [lo, hi] : [0, this.steps.length - 1];
    }

    indexForUrlValue(raw: string | null): number | null {
        if (null === raw) {
            return null;
        }

        const value = Number(raw);
        const index = this.steps.findIndex((_, i) => this.stepValue(i) === value);

        return -1 === index ? null : index;
    }

    // The population to actually filter on for this step: the last step is
    // an open-ended bucket (see topStepIsCapped in connect()), so it maps to
    // the real max rather than its own face value.
    stepValue(index: number): number {
        if (index === this.steps.length - 1 && this.topStepIsCapped) {
            return this.populationMaxValue;
        }

        return this.steps[index];
    }

    stepLabel(index: number): string {
        if (index === this.steps.length - 1 && this.topStepIsCapped) {
            return `${this.steps[index]} et plus`;
        }

        return String(this.steps[index]);
    }

    emptyFeatureCollection(): GeoJSON.FeatureCollection {
        return { type: 'FeatureCollection', features: [] };
    }
}
