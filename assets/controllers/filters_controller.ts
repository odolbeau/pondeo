import { Controller } from '@hotwired/stimulus';
import { savePreference } from '../lib/cookie';

const DEBOUNCE_MS = 200;
// Query params making up the filter. One param per destination city: trainMaxMinutes[paris]=120.
const TRAIN_PARAM_PREFIX = 'trainMaxMinutes[';
const DEFAULT_PRICE_TYPE = 'global';
const PRICE_CODES: Record<string, string> = { global: 'prix_m2', house: 'prix_m2_maison', apartment: 'prix_m2_appartement' };
const PRICE_LABELS: Record<string, string> = { global: 'Appartements et maisons', house: 'Maisons', apartment: 'Appartements' };
// Share of the unités urbaines a threshold keeps, with the percentile it sits at.
const STAT_CHIPS: { key: 'p25' | 'median' | 'p75'; keptByMin: number }[] = [
    { key: 'p25', keptByMin: 75 },
    { key: 'median', keptByMin: 50 },
    { key: 'p75', keptByMin: 25 },
];

// The last filter used, so that every page starts from it: the server reads it
// to send a page without filters to the same page with them in its address.
const COOKIE_NAME = 'pondeo_filters';

type Stats = { min: number; p10: number; p25: number; median: number; p75: number; p90: number; max: number };

// Why the filters changed: the visitor (user), the back and forward buttons (history), or the page loading (initial).
export type FiltersChange = { params: string; count: number; reason: 'initial' | 'user' | 'history' };

// Round-number steps ("paliers") the population slider snaps to, rather
// than letting it select an arbitrary population to the exact inhabitant —
// unreadable and not meaningfully more useful. Exact values are typed in the
// number inputs instead. Steps below the smallest unité urbaine (~2 000
// inhabitants) are dropped in connect(). Capped at 1M: distinguishing among
// the handful of agglomérations above that isn't useful on a slider, so its
// top step stands in for everything up to populationMaxValue.
const POPULATION_STEPS = [0, 2_000, 5_000, 10_000, 20_000, 50_000, 100_000, 200_000, 500_000, 1_000_000];

type Chip = { label: string; clear: () => void };

// The filters panel shared by the map, the rankings and the personal ranking.
// It reads and writes the filter as query params, and says when it changes
// (`filters:change`); the page decides what to do with it. On a page of
// results inside a form (`submit` value), the form is submitted with the
// filter instead, so the results are refreshed in place.
export default class extends Controller {
    static targets = ['minRange', 'maxRange', 'minInput', 'maxInput', 'excludeCouronne', 'excludePolesSecondaires', 'commerceDensityMin', 'restaurantDensityMin', 'nuclearExclusionKm', 'priceMax', 'priceTypeButton', 'priceHelp', 'statsHint', 'withStation', 'withBoardGameAssociation', 'trainCity', 'trainHours', 'trainMaxTransfers', 'summary', 'chips', 'hidden', 'toggle', 'drawer'];
    static values = {
        populationMin: Number,
        populationMax: Number,
        stats: Object,
        submit: Boolean,
        // Params of the page, besides the filter, that a submission keeps (the order of a ranking, for instance).
        keep: Array,
    };

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
    declare readonly nuclearExclusionKmTarget: HTMLInputElement;
    declare readonly withStationTarget: HTMLInputElement;
    declare readonly withBoardGameAssociationTarget: HTMLInputElement;
    declare readonly trainCityTargets: HTMLSelectElement[];
    declare readonly trainHoursTargets: HTMLInputElement[];
    declare readonly trainMaxTransfersTarget: HTMLInputElement;
    declare readonly hasSummaryTarget: boolean;
    declare readonly summaryTarget: HTMLElement;
    declare readonly hasChipsTarget: boolean;
    declare readonly chipsTarget: HTMLElement;
    declare readonly hasHiddenTarget: boolean;
    declare readonly hiddenTarget: HTMLElement;
    declare readonly hasToggleTarget: boolean;
    declare readonly toggleTarget: HTMLElement;
    declare readonly hasDrawerTarget: boolean;
    declare readonly drawerTarget: HTMLElement;
    declare readonly populationMinValue: number;
    declare readonly populationMaxValue: number;
    declare readonly statsValue: Record<string, Stats>;
    declare readonly submitValue: boolean;
    declare readonly keepValue: string[];

    private steps!: number[];
    private debounceHandle?: ReturnType<typeof setTimeout>;
    private priceType = DEFAULT_PRICE_TYPE;

    connect() {
        this.steps = POPULATION_STEPS.filter((s) => s > this.populationMinValue && s < this.populationMaxValue);
        this.steps.unshift(this.populationMinValue);
        this.steps.push(this.populationMaxValue);

        this.minRangeTarget.max = this.maxRangeTarget.max = String(this.steps.length - 1);
        this.applyParams(new URLSearchParams(window.location.search));
        window.addEventListener('popstate', this.onPopState);

        this.announce('initial');
    }

    disconnect() {
        window.removeEventListener('popstate', this.onPopState);
        clearTimeout(this.debounceHandle);
    }

    // Back/forward: the URL is the source of truth, so the inputs are rebuilt
    // from it without writing the cookie or submitting anything.
    private onPopState = () => {
        this.applyParams(new URLSearchParams(window.location.search));
        this.announce('history');
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

    onRangeInput() {
        const a = this.steps[Number(this.minRangeTarget.value)];
        const b = this.steps[Number(this.maxRangeTarget.value)];
        this.writeInputs(Math.min(a, b), Math.max(a, b));

        this.touch();
    }

    // Fires on every keystroke: the sliders follow, but the typed text is
    // left alone so the user isn't fought while typing.
    onNumberInput() {
        const [min, max] = this.valuesFromInputs();
        this.syncRanges(min, max);

        this.touch();
    }

    // Once editing is done, the inputs are rewritten with the clamped and
    // ordered values that are actually applied.
    onNumberChange() {
        const [min, max] = this.valuesFromInputs();
        this.writeInputs(min, max);
        this.syncRanges(min, max);

        this.touch();
    }

    onPriceTypeClick(event: Event) {
        const type = (event.currentTarget as HTMLElement).dataset.priceType ?? DEFAULT_PRICE_TYPE;
        this.priceType = type in PRICE_CODES ? type : DEFAULT_PRICE_TYPE;
        this.renderPriceType();
        this.renderStatsHints();
        this.touch();
    }

    // Any field changed: the filter is announced shortly after, once the visitor stops.
    touch() {
        clearTimeout(this.debounceHandle);
        this.debounceHandle = setTimeout(() => this.changed(), DEBOUNCE_MS);
    }

    // The filters shown by a page of results, which is not always open.
    toggle() {
        const open = this.drawerTarget.hidden;
        this.drawerTarget.hidden = !open;
        this.toggleTarget.setAttribute('aria-expanded', String(open));
    }

    clear() {
        this.applyParams(new URLSearchParams());
        this.changed();
    }

    changed() {
        savePreference(COOKIE_NAME, this.params());
        this.announce('user');

        if (this.submitValue) {
            this.element.closest('form')?.requestSubmit();
        }
    }

    announce(reason: FiltersChange['reason']) {
        const chips = this.chips();
        this.renderChips(chips);
        this.syncHidden();
        this.dispatch('change', { detail: { params: this.params().toString(), count: chips.length, reason } satisfies FiltersChange });
    }

    // The enclosing form carries the filter as hidden fields, whoever submits it
    // (the filters, or the sliders of the personal ranking): the visible fields
    // are not named, since they do not hold the query's values as such (a
    // population at its bound is no filter, a train city and its hours are two fields).
    syncHidden() {
        if (!this.hasHiddenTarget) {
            return;
        }

        const current = new URLSearchParams(window.location.search);
        const fields = [...this.params()];
        for (const name of this.keepValue) {
            const value = current.get(name);
            if (null !== value) {
                fields.push([name, value]);
            }
        }

        this.hiddenTarget.replaceChildren(
            ...fields.map(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;

                return input;
            }),
        );
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
                    this.touch();
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

    // What is filtered, one removable label each: the filter is never hidden.
    chips(): Chip[] {
        const number = (value: number) => new Intl.NumberFormat('fr-FR').format(value);
        const chips: Chip[] = [];

        const [min, max] = this.valuesFromInputs();
        const hasMin = min !== this.populationMinValue;
        const hasMax = max !== this.populationMaxValue;
        if (hasMin || hasMax) {
            const label = hasMin && hasMax ? `${number(min)} – ${number(max)} habitants` : hasMin ? `Au moins ${number(min)} habitants` : `Au plus ${number(max)} habitants`;
            chips.push({
                label,
                clear: () => {
                    this.writeInputs(this.populationMinValue, this.populationMaxValue);
                    this.syncRanges(this.populationMinValue, this.populationMaxValue);
                },
            });
        }

        const toggles: [HTMLInputElement, string][] = [
            [this.excludeCouronneTarget, "Hors couronnes d'une aire d'attraction"],
            [this.excludePolesSecondairesTarget, 'Hors pôles secondaires'],
            [this.withStationTarget, 'Avec une gare'],
            [this.withBoardGameAssociationTarget, 'Avec une association de jeux'],
        ];
        for (const [input, label] of toggles) {
            if (input.checked) {
                chips.push({ label, clear: () => (input.checked = false) });
            }
        }

        this.trainCityTargets.forEach((select, row) => {
            const hours = this.positiveNumber(this.trainHoursTargets[row].value);
            if ('' === select.value || null === hours) {
                return;
            }
            const city = select.selectedOptions[0].textContent?.trim() ?? select.value;
            chips.push({
                label: `${city} à moins de ${number(hours)} h`,
                clear: () => {
                    select.value = '';
                    this.trainHoursTargets[row].value = '';
                },
            });
        });
        const transfers = this.transfers();
        if (null !== transfers && this.trainLimits().length > 0) {
            chips.push({
                label: 0 === transfers ? 'Trains directs' : `${transfers} changement${transfers > 1 ? 's' : ''} au plus`,
                clear: () => (this.trainMaxTransfersTarget.value = ''),
            });
        }

        const numbers: [HTMLInputElement, (value: number) => string][] = [
            [this.commerceDensityMinTarget, (value) => `Au moins ${number(value)} commerces pour 1000 hab.`],
            [this.restaurantDensityMinTarget, (value) => `Au moins ${number(value)} restaurants pour 1000 hab.`],
            [this.priceMaxTarget, (value) => `${PRICE_LABELS[this.priceType]} à ${number(value)} €/m² au plus`],
            [this.nuclearExclusionKmTarget, (value) => `À plus de ${number(value)} km d'une centrale`],
        ];
        for (const [input, label] of numbers) {
            const value = this.positiveNumber(input.value);
            if (null !== value) {
                chips.push({ label: label(value), clear: () => (input.value = '') });
            }
        }

        return chips;
    }

    renderChips(chips: Chip[]) {
        if (this.hasSummaryTarget) {
            this.summaryTarget.hidden = 0 === chips.length;
        }
        if (!this.hasChipsTarget) {
            return;
        }

        this.chipsTarget.replaceChildren(
            ...chips.map((chip) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'rounded-full border border-emerald-600 bg-emerald-50 px-3 py-1 text-xs text-emerald-800 hover:bg-emerald-100';
                button.textContent = `${chip.label} ×`;
                button.setAttribute('aria-label', `Retirer le filtre : ${chip.label}`);
                button.addEventListener('click', () => {
                    chip.clear();
                    this.changed();
                });

                return button;
            }),
        );
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

    transfers(): number | null {
        const transfers = this.trainMaxTransfersTarget.value.trim();

        return '' !== transfers && Number.isInteger(Number(transfers)) && Number(transfers) >= 0 ? Number(transfers) : null;
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

    // An empty or invalid field falls back to its bound; the pair is then
    // ordered, so typing a min above the max doesn't produce an empty result.
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

    orderedOrFull(rawMin: string | null, rawMax: string | null): [number, number] {
        const min = this.clamp(rawMin, this.populationMinValue);
        const max = this.clamp(rawMax, this.populationMaxValue);

        return min <= max ? [min, max] : [this.populationMinValue, this.populationMaxValue];
    }

    // The filter as query params, leaving out whatever is at its default.
    params(): URLSearchParams {
        const [min, max] = this.valuesFromInputs();
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
        // The price type and the transfers only qualify a price or a train limit: alone, they are no filter.
        const priceMax = this.priceMax();
        if (null !== priceMax) {
            params.set('priceMax', String(priceMax));
            if (DEFAULT_PRICE_TYPE !== this.priceType) {
                params.set('priceType', this.priceType);
            }
        }
        const trainLimits = this.trainLimits();
        const transfers = this.transfers();
        if (null !== transfers && trainLimits.length > 0) {
            params.set('trainMaxTransfers', String(transfers));
        }
        for (const [destination, minutes] of trainLimits) {
            params.set(`${TRAIN_PARAM_PREFIX}${destination}]`, String(minutes));
        }

        return params;
    }
}
