import { Controller } from '@hotwired/stimulus';

const OFFSET = 14;

// An instant tooltip for the SVG charts: the native `<title>` one only shows
// after a delay and nothing hints at it. Any element with a `data-tooltip`
// attribute shows its text, a line per `\n`, next to the pointer: the first
// line is the title of the figure (the column, row or bracket), in bold.
export default class extends Controller {
    static targets = ['tooltip'];

    declare readonly tooltipTarget: HTMLElement;

    move(event: PointerEvent) {
        const text = (event.target as Element).closest<HTMLElement | SVGElement>('[data-tooltip]')?.getAttribute('data-tooltip');
        if (!text) {
            this.hide();
            return;
        }

        const tooltip = this.tooltipTarget;
        tooltip.replaceChildren(
            ...text.split('\n').map((line, index) => {
                const row = document.createElement('div');
                row.textContent = line;
                row.classList.toggle('font-semibold', index === 0);

                return row;
            }),
        );
        tooltip.hidden = false;

        const bounds = this.element.getBoundingClientRect();
        const x = event.clientX - bounds.left + OFFSET;
        const y = event.clientY - bounds.top + OFFSET;

        // Kept inside the chart horizontally: to the left of the pointer when it would overflow on the right.
        const overflowsRight = x + tooltip.offsetWidth > bounds.width;
        tooltip.style.left = `${Math.max(0, overflowsRight ? event.clientX - bounds.left - OFFSET - tooltip.offsetWidth : x)}px`;
        tooltip.style.top = `${y}px`;
    }

    hide() {
        this.tooltipTarget.hidden = true;
    }
}
