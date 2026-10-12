import { Controller } from '@hotwired/stimulus';

// Personal ranking form: refreshes the results shortly after a slider moves,
// and shows the slider's value. Without JavaScript the submit button does it.
export default class extends Controller {
    static targets = ['form', 'output'];

    declare readonly formTarget: HTMLFormElement;
    declare readonly outputTargets: HTMLOutputElement[];

    private timeout?: number;

    update(event: Event) {
        const slider = event.target as HTMLInputElement;
        const output = this.outputTargets.find((candidate) => candidate.htmlFor.contains(slider.id));
        if (output) {
            output.value = slider.value;
        }

        window.clearTimeout(this.timeout);
        this.timeout = window.setTimeout(() => this.formTarget.requestSubmit(), 300);
    }

    disconnect() {
        window.clearTimeout(this.timeout);
    }
}
