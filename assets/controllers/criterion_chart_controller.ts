import { Controller } from '@hotwired/stimulus';
import uPlot from 'uplot';

interface CriterionSeries {
    label: string;
    unit: string | null;
    periods: number[];
    values: (number | null)[];
    medians: (number | null)[] | null;
}

// Tailwind emerald-400 / amber-400 / slate-400 / slate-800.
const UNIT_COLOR = '#34d399';
const MEDIAN_COLOR = '#fbbf24';
const AXIS_COLOR = '#94a3b8';
const GRID_COLOR = '#1e293b';
const CHART_HEIGHT = 220;
const INDEX_BASE = 100;

const numberFormat = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 });

// A criterion row that unfolds a chart of its evolution next to the median of
// every unité urbaine, fetched the first time the row is opened.
export default class extends Controller {
    static targets = ['header', 'panel', 'chart', 'status', 'indexButton', 'chevron'];
    static values = { url: String };

    declare readonly headerTarget: HTMLElement;
    declare readonly panelTarget: HTMLElement;
    declare readonly chartTarget: HTMLElement;
    declare readonly statusTarget: HTMLElement;
    declare readonly indexButtonTarget: HTMLButtonElement;
    declare readonly chevronTarget: HTMLElement;
    declare readonly urlValue: string;

    private series?: CriterionSeries;
    private plot?: uPlot;
    private resizeObserver?: ResizeObserver;
    private indexed = false;
    private loading = false;

    disconnect() {
        this.destroyPlot();
    }

    async toggle(event: Event) {
        event.preventDefault();

        const open = this.panelTarget.hasAttribute('hidden');
        this.panelTarget.toggleAttribute('hidden', !open);
        this.headerTarget.setAttribute('aria-expanded', String(open));
        this.chevronTarget.classList.toggle('rotate-180', open);

        if (open && !this.series) {
            await this.load();
        }
    }

    toggleIndex() {
        this.indexed = !this.indexed;
        this.indexButtonTarget.setAttribute('aria-pressed', String(this.indexed));
        this.draw();
    }

    private async load() {
        if (this.loading) {
            return;
        }

        this.loading = true;
        this.statusTarget.textContent = 'Chargement…';

        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            this.series = (await response.json()) as CriterionSeries;
            this.statusTarget.textContent = '';
            this.indexButtonTarget.hidden = this.indexBase() === null;
            this.draw();
        } catch {
            this.statusTarget.textContent = 'Impossible de charger la courbe.';
        } finally {
            this.loading = false;
        }
    }

    private draw() {
        if (!this.series) {
            return;
        }

        this.destroyPlot();

        const { periods, values, medians } = this.series;
        const base = this.indexed ? this.indexBase() : null;
        const scale = (points: (number | null)[], reference: number | null) =>
            base === null || reference === null ? points : points.map((point) => (point === null ? null : (point / reference) * INDEX_BASE));

        const data: uPlot.AlignedData = [periods, scale(values, base?.value ?? null)];
        if (medians) {
            data.push(scale(medians, base?.median ?? null));
        }

        const suffix = this.indexed || !this.series.unit ? '' : ` ${this.series.unit}`;
        const format = (_u: uPlot, value: number | null) => (value === null ? '—' : `${numberFormat.format(value)}${suffix}`);

        const series: uPlot.Series[] = [
            { label: 'Année' },
            { label: 'Cette unité urbaine', stroke: UNIT_COLOR, width: 2, spanGaps: true, value: format },
        ];
        if (medians) {
            series.push({ label: 'Médiane des unités urbaines', stroke: MEDIAN_COLOR, width: 2, dash: [6, 4], spanGaps: true, value: format });
        }

        const axis = { stroke: AXIS_COLOR, grid: { stroke: GRID_COLOR, width: 1 }, ticks: { stroke: GRID_COLOR, width: 1 } };

        this.plot = new uPlot(
            {
                width: this.chartTarget.clientWidth,
                height: CHART_HEIGHT,
                scales: { x: { time: false } },
                series,
                axes: [
                    { ...axis, incrs: [1, 2, 5, 10], values: (_u, ticks) => ticks.map((tick) => String(tick)) },
                    { ...axis, values: (_u, ticks) => ticks.map((tick) => numberFormat.format(tick)), size: 60 },
                ],
                cursor: { drag: { x: false, y: false } },
            },
            data,
            this.chartTarget,
        );

        this.resizeObserver = new ResizeObserver(() => {
            const width = this.chartTarget.clientWidth;
            if (this.plot && width > 0 && width !== this.plot.width) {
                this.plot.setSize({ width, height: CHART_HEIGHT });
            }
        });
        this.resizeObserver.observe(this.chartTarget);
    }

    // The first period where the unit and the median both have a non-zero
    // value: the index is 100 there for both curves, so they stay comparable
    // even when their levels differ widely (a city of 80 000 vs a median of 6 000).
    private indexBase(): { value: number; median: number } | null {
        if (!this.series?.medians) {
            return null;
        }

        for (let i = 0; i < this.series.periods.length; i++) {
            const value = this.series.values[i];
            const median = this.series.medians[i];
            if (value && median) {
                return { value, median };
            }
        }

        return null;
    }

    private destroyPlot() {
        this.resizeObserver?.disconnect();
        this.resizeObserver = undefined;
        this.plot?.destroy();
        this.plot = undefined;
    }
}
