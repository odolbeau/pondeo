import { Controller } from '@hotwired/stimulus';
import { savePreference } from '../lib/cookie';

// The last importances chosen, so that the page starts from them (see the filters' cookie).
const COOKIE_NAME = 'pondeo_weights';

// Personal ranking form: refreshes the results shortly after a slider moves,
// and shows the slider's value. Without JavaScript the submit button does it.
export default class extends Controller {
    static targets = ['form', 'output', 'slider'];

    declare readonly formTarget: HTMLFormElement;
    declare readonly outputTargets: HTMLOutputElement[];
    declare readonly sliderTargets: HTMLInputElement[];

    private timeout?: number;

    update(event: Event) {
        const slider = event.target as HTMLInputElement;
        const output = this.outputTargets.find((candidate) => candidate.htmlFor.contains(slider.id));
        if (output) {
            output.value = slider.value;
        }

        window.clearTimeout(this.timeout);
        this.timeout = window.setTimeout(() => {
            this.save();
            this.formTarget.requestSubmit();
        }, 300);
    }

    // Only what counts: a slider at 0 is ignored.
    save() {
        const weights = new URLSearchParams();
        for (const slider of this.sliderTargets) {
            if ('0' !== slider.value) {
                weights.set(slider.name, slider.value);
            }
        }
        savePreference(COOKIE_NAME, weights);
    }

    disconnect() {
        window.clearTimeout(this.timeout);
    }
}
