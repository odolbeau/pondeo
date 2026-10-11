import { Controller } from '@hotwired/stimulus';

// Menu button of the navigation on small screens: shows or hides the panel
// holding the links and the search box (always visible from `lg` up, in CSS).
export default class extends Controller {
    static targets = ['panel', 'button'];

    declare readonly panelTarget: HTMLElement;
    declare readonly buttonTarget: HTMLButtonElement;

    toggle() {
        const open = this.panelTarget.classList.contains('hidden');
        // `flex` is only for the open state: from `lg` up, CSS shows the panel whatever these classes say.
        this.panelTarget.classList.toggle('hidden', !open);
        this.panelTarget.classList.toggle('flex', open);
        this.buttonTarget.setAttribute('aria-expanded', String(open));
    }
}
