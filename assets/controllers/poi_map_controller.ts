import { Controller } from '@hotwired/stimulus';
import { LngLatBounds, Map, Popup, setWorkerUrl } from 'maplibre-gl';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

// See map_controller.ts: the production build can't follow MapLibre's own worker URL.
setWorkerUrl(workerUrl);

const SOURCE_ID = 'pois';
const LAYER_ID = 'pois-circles';
const PALETTE = ['#dc2626', '#2563eb', '#d97706', '#059669', '#7c3aed', '#db2777', '#0891b2', '#65a30d'];
const FOCUS_ZOOM = 13;
// Shared by every unité urbaine's page: a category hidden on one stays hidden on the others.
const STORAGE_KEY = 'pondeo:poi:hidden-categories';

// The POIs list drives the map: each item carries its coordinates, and its
// category's colour. Hovering an item highlights its point, and conversely.
export default class extends Controller {
    static targets = ['map', 'item', 'dot', 'toggle', 'group'];

    declare readonly mapTarget: HTMLDivElement;
    declare readonly itemTargets: HTMLElement[];
    declare readonly dotTargets: HTMLElement[];
    declare readonly toggleTargets: HTMLInputElement[];
    declare readonly groupTargets: HTMLElement[];

    private map!: Map;
    private ready = false;
    private hidden = new Set<string>();
    private activeId: number | null = null;
    private popup = new Popup({ closeButton: false, offset: 10 });

    connect() {
        this.hidden = this.loadHidden();
        for (const dot of this.dotTargets) {
            dot.style.backgroundColor = this.color(dot);
        }

        const features: GeoJSON.Feature<GeoJSON.Point>[] = this.itemTargets.map((item, id) => ({
            type: 'Feature',
            id,
            properties: { color: this.color(item), category: item.dataset.code },
            geometry: { type: 'Point', coordinates: [Number(item.dataset.lon), Number(item.dataset.lat)] },
        }));

        this.map = new Map({
            container: this.mapTarget,
            style: 'https://tiles.openfreemap.org/styles/liberty',
            center: [2.5, 46.6],
            zoom: 5,
        });

        this.map.on('load', () => {
            this.map.addSource(SOURCE_ID, { type: 'geojson', data: { type: 'FeatureCollection', features } });
            this.map.addLayer({
                id: LAYER_ID,
                type: 'circle',
                source: SOURCE_ID,
                paint: {
                    'circle-color': ['get', 'color'],
                    'circle-radius': ['case', ['boolean', ['feature-state', 'active'], false], 10, 6],
                    'circle-stroke-color': ['case', ['boolean', ['feature-state', 'active'], false], '#111827', '#ffffff'],
                    'circle-stroke-width': ['case', ['boolean', ['feature-state', 'active'], false], 3, 1.5],
                },
            });

            this.map.on('mousemove', LAYER_ID, (event) => {
                this.map.getCanvas().style.cursor = 'pointer';
                const id = event.features?.[0]?.id;
                if (typeof id === 'number') {
                    this.activate(id);
                    this.itemTargets[id].scrollIntoView({ block: 'nearest' });
                }
            });
            this.map.on('mouseleave', LAYER_ID, () => {
                this.map.getCanvas().style.cursor = '';
                this.activate(null);
            });
            this.map.on('click', LAYER_ID, (event) => {
                const id = event.features?.[0]?.id;
                if (typeof id === 'number') {
                    this.show(id);
                }
            });

            this.ready = true;
            this.applyHidden();
        });

        this.applyHidden();
        this.fitVisible(false);
    }

    disconnect() {
        this.map?.remove();
    }

    highlight(event: Event) {
        this.activate(this.idOf(event));
    }

    unhighlight() {
        this.activate(null);
    }

    toggle(event: Event) {
        const input = event.currentTarget as HTMLInputElement;
        const code = input.dataset.code as string;
        if (input.checked) {
            this.hidden.delete(code);
        } else {
            this.hidden.add(code);
        }

        this.saveHidden();
        this.activate(null);
        this.popup.remove();
        this.applyHidden();
        this.fitVisible(true);
    }

    select(event: Event) {
        this.show(this.idOf(event));
    }

    private applyHidden() {
        for (const toggle of this.toggleTargets) {
            toggle.checked = !this.hidden.has(toggle.dataset.code as string);
        }
        for (const group of this.groupTargets) {
            group.hidden = this.hidden.has(group.dataset.code as string);
        }
        if (this.ready) {
            this.map.setFilter(LAYER_ID, ['!', ['in', ['get', 'category'], ['literal', [...this.hidden]]]]);
        }
    }

    private fitVisible(animate: boolean) {
        const bounds = new LngLatBounds();
        for (const item of this.itemTargets) {
            if (!this.hidden.has(item.dataset.code as string)) {
                bounds.extend([Number(item.dataset.lon), Number(item.dataset.lat)]);
            }
        }
        if (!bounds.isEmpty()) {
            this.map.fitBounds(bounds, { padding: 40, maxZoom: FOCUS_ZOOM, animate });
        }
    }

    private loadHidden(): Set<string> {
        try {
            return new Set(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]'));
        } catch {
            return new Set();
        }
    }

    private saveHidden() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify([...this.hidden]));
        } catch {
            // Storage unavailable (private mode): the setting just won't outlive the page.
        }
    }

    private show(id: number) {
        const item = this.itemTargets[id];
        const center: [number, number] = [Number(item.dataset.lon), Number(item.dataset.lat)];

        const content = document.createElement('div');
        content.className = 'text-sm';
        const name = document.createElement('strong');
        name.textContent = item.dataset.name ?? '';
        content.append(name);
        if (item.dataset.address) {
            const address = document.createElement('div');
            address.className = 'text-xs text-stone-600';
            address.textContent = item.dataset.address;
            content.append(address);
        }

        this.popup.setLngLat(center).setDOMContent(content).addTo(this.map);
        this.map.easeTo({ center, zoom: Math.max(this.map.getZoom(), FOCUS_ZOOM) });
    }

    private activate(id: number | null) {
        if (id === this.activeId) {
            return;
        }

        if (null !== this.activeId) {
            this.itemTargets[this.activeId].toggleAttribute('data-active', false);
            this.setActiveState(this.activeId, false);
        }
        if (null !== id) {
            this.itemTargets[id].toggleAttribute('data-active', true);
            this.setActiveState(id, true);
        }
        this.activeId = id;
    }

    private setActiveState(id: number, active: boolean) {
        if (this.ready) {
            this.map.setFeatureState({ source: SOURCE_ID, id }, { active });
        }
    }

    private idOf(event: Event): number {
        return this.itemTargets.indexOf((event.currentTarget as HTMLElement).closest('li') as HTMLElement);
    }

    private color(element: HTMLElement): string {
        return PALETTE[Number(element.dataset.category) % PALETTE.length];
    }
}
