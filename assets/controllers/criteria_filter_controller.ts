import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'group', 'row'];

    declare readonly inputTarget: HTMLInputElement;
    declare readonly groupTargets: HTMLElement[];
    declare readonly rowTargets: HTMLElement[];

    filter() {
        const query = this.normalize(this.inputTarget.value);

        for (const row of this.rowTargets) {
            row.hidden = !this.normalize(row.dataset.label ?? '').includes(query);
        }

        // A group with no matching row is hidden as a whole, not left as an empty heading.
        for (const group of this.groupTargets) {
            group.hidden = group.querySelectorAll('[data-criteria-filter-target="row"]:not([hidden])').length === 0;
        }
    }

    // Accent- and case-insensitive: "demographie" finds "Démographie".
    private normalize(text: string): string {
        return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
    }
}
