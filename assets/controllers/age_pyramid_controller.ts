import { Controller } from '@hotwired/stimulus';

// Switches the population pyramid between its bracket widths (5 or 10 years):
// every width is rendered, only the chosen one is shown.
export default class extends Controller {
    static targets = ['panel', 'button'];

    declare readonly panelTargets: HTMLElement[];
    declare readonly buttonTargets: HTMLButtonElement[];

    show(event: Event & { params: { width: number } }) {
        const { width } = event.params;

        for (const panel of this.panelTargets) {
            panel.hidden = Number(panel.dataset.width) !== width;
        }

        for (const button of this.buttonTargets) {
            button.setAttribute('aria-pressed', String(Number(button.dataset.agePyramidWidthParam) === width));
        }
    }
}
