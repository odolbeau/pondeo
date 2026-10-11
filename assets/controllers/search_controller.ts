import { Controller } from '@hotwired/stimulus';

const MAX_RESULTS = 8;

type Entry = { code: string; name: string; departement: string | null; folded: string };

// Folds case and accents so "orleans" finds "Orléans".
const fold = (text: string): string => text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

// Search box of the navigation: the index of every unité urbaine is fetched on
// first use and filtered in the browser, names starting with the query first.
export default class extends Controller {
    static targets = ['input', 'results'];
    static values = { indexUrl: String, detailUrl: String };

    declare readonly inputTarget: HTMLInputElement;
    declare readonly resultsTarget: HTMLUListElement;
    declare readonly indexUrlValue: string;
    declare readonly detailUrlValue: string;

    private entries: Promise<Entry[]> | null = null;
    private matches: Entry[] = [];
    private active = -1;

    load() {
        this.entries ??= fetch(this.indexUrlValue)
            .then((response) => response.json() as Promise<[string, string, string | null][]>)
            .then((rows) => rows.map(([code, name, departement]) => ({ code, name, departement, folded: fold(name) })))
            .catch(() => {
                this.entries = null;

                return [];
            });
    }

    async search() {
        const query = fold(this.inputTarget.value.trim());
        if (query === '') {
            this.close();

            return;
        }

        this.load();
        const entries = await this.entries!;
        const startsWith = entries.filter((entry) => entry.folded.startsWith(query));
        const contains = entries.filter((entry) => !entry.folded.startsWith(query) && entry.folded.includes(query));
        this.matches = [...startsWith, ...contains].slice(0, MAX_RESULTS);
        this.active = this.matches.length > 0 ? 0 : -1;
        this.render();
    }

    keydown(event: KeyboardEvent) {
        if (event.key === 'Escape') {
            this.close();

            return;
        }

        if (this.matches.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.active = (this.active + step + this.matches.length) % this.matches.length;
            this.render();
        } else if (event.key === 'Enter' && this.active >= 0) {
            event.preventDefault();
            this.go(this.matches[this.active]);
        }
    }

    pick(event: Event) {
        const code = (event.currentTarget as HTMLElement).dataset.code;
        const entry = this.matches.find((match) => match.code === code);
        if (entry) {
            this.go(entry);
        }
    }

    // Closing is deferred so that a click on a result lands before the list disappears.
    blur() {
        setTimeout(() => this.close(), 150);
    }

    private go(entry: Entry) {
        window.location.assign(this.detailUrlValue.replace('XXXXX', entry.code));
    }

    private close() {
        this.matches = [];
        this.active = -1;
        this.render();
    }

    private render() {
        this.resultsTarget.hidden = this.matches.length === 0;
        this.inputTarget.setAttribute('aria-expanded', String(this.matches.length > 0));
        this.resultsTarget.replaceChildren(
            ...this.matches.map((entry, index) => {
                const item = document.createElement('li');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', String(index === this.active));
                item.dataset.code = entry.code;
                item.dataset.action = 'mousedown->search#pick';
                item.className = `cursor-pointer px-3 py-2 ${index === this.active ? 'bg-emerald-50 text-emerald-800' : 'text-stone-700'}`;
                item.textContent = entry.name;
                if (entry.departement !== null) {
                    const departement = document.createElement('span');
                    departement.className = 'ml-2 text-xs text-stone-500';
                    departement.textContent = entry.departement;
                    item.append(departement);
                }

                return item;
            }),
        );
    }
}
