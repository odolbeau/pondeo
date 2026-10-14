import { Controller } from '@hotwired/stimulus';
import { LngLatBounds, Map as MapLibreMap, Popup, setWorkerUrl } from 'maplibre-gl';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

// See map_controller.ts: the production build can't follow MapLibre's own worker URL.
setWorkerUrl(workerUrl);

const BOUNDARY_SOURCE_ID = 'boundary';
const SOURCE_ID = 'pois';
const LAYER_ID = 'pois-circles';
const PALETTE = ['#dc2626', '#2563eb', '#d97706', '#059669', '#7c3aed', '#db2777', '#0891b2', '#65a30d'];
const FOCUS_ZOOM = 13;
// Shared by every unité urbaine's page: a category hidden on one stays hidden on the others.
// The codes of the hidden categories; a category with children stands for all its descendants.
const STORAGE_KEY = 'pondeo:poi:hidden-categories';

// The POIs list drives the map: each item carries its coordinates, and its
// category's colour. Hovering an item highlights its point, and conversely.
export default class extends Controller {
    static targets = ['map', 'item', 'dot', 'group', 'groupCount', 'node', 'nodeCount'];
    static values = { boundary: String };

    declare readonly mapTarget: HTMLDivElement;
    declare readonly itemTargets: HTMLElement[];
    declare readonly dotTargets: HTMLElement[];
    declare readonly groupTargets: HTMLElement[];
    declare readonly groupCountTargets: HTMLElement[];
    declare readonly nodeTargets: HTMLInputElement[];
    declare readonly nodeCountTargets: HTMLElement[];
    declare readonly boundaryValue: string;
    declare readonly hasBoundaryValue: boolean;

    private map!: MapLibreMap;
    private ready = false;
    // The hidden categories, as leaves of the tree: a POI is filed in one.
    private hidden = new Set<string>();
    private leavesUnder = new Map<string, string[]>();
    private activeId: number | null = null;
    private popup = new Popup({ closeButton: false, offset: 10 });

    connect() {
        this.leavesUnder = this.buildLeavesUnder();
        this.hidden = this.loadHidden();
        for (const dot of this.dotTargets) {
            dot.style.backgroundColor = this.color(dot);
        }

        const features: GeoJSON.Feature<GeoJSON.Point>[] = this.itemTargets.map((item, id) => ({
            type: 'Feature',
            id,
            properties: { color: this.color(item), leaf: item.dataset.leaf },
            geometry: { type: 'Point', coordinates: [Number(item.dataset.lon), Number(item.dataset.lat)] },
        }));

        this.map = new MapLibreMap({
            container: this.mapTarget,
            style: 'https://tiles.openfreemap.org/styles/liberty',
            center: [2.5, 46.6],
            zoom: 5,
        });

        this.map.on('load', () => {
            // Under the points: the outline of the unité urbaine the POIs belong to.
            if (this.hasBoundaryValue) {
                this.map.addSource(BOUNDARY_SOURCE_ID, { type: 'geojson', data: JSON.parse(this.boundaryValue) });
                this.map.addLayer({ id: `${BOUNDARY_SOURCE_ID}-fill`, type: 'fill', source: BOUNDARY_SOURCE_ID, paint: { 'fill-color': '#059669', 'fill-opacity': 0.08 } });
                this.map.addLayer({ id: `${BOUNDARY_SOURCE_ID}-line`, type: 'line', source: BOUNDARY_SOURCE_ID, paint: { 'line-color': '#059669', 'line-width': 2.5 } });
            }

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
        this.fitInitial();
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

    toggleNode(event: Event) {
        const input = event.currentTarget as HTMLInputElement;
        for (const leaf of this.leavesUnder.get(input.dataset.code as string) ?? []) {
            if (input.checked) {
                this.hidden.delete(leaf);
            } else {
                this.hidden.add(leaf);
            }
        }

        this.update();
    }

    showAll() {
        this.hidden.clear();
        this.update();
    }

    hideAll() {
        this.hidden = new Set(this.nodeTargets.filter((node) => '1' === node.dataset.leafNode).map((node) => node.dataset.code as string));
        this.update();
    }

    select(event: Event) {
        this.show(this.idOf(event));
    }

    private update() {
        this.saveHidden();
        this.activate(null);
        this.popup.remove();
        this.applyHidden();
        this.fitVisible(true);
    }

    // A category that is not kept shows nowhere: not in the list, nor on the map.
    private applyHidden() {
        const visibleByGroup = new Map<HTMLElement, number>();
        const counts = new Map<string, number>();
        for (const item of this.itemTargets) {
            const leaf = item.dataset.leaf as string;
            counts.set(leaf, (counts.get(leaf) ?? 0) + 1);

            const hidden = this.hidden.has(leaf);
            item.hidden = hidden;
            const group = this.groupTargets.find((candidate) => candidate.contains(item)) as HTMLElement;
            visibleByGroup.set(group, (visibleByGroup.get(group) ?? 0) + (hidden ? 0 : 1));
        }

        this.groupTargets.forEach((group, index) => {
            const visible = visibleByGroup.get(group) ?? 0;
            group.hidden = 0 === visible;
            this.groupCountTargets[index].textContent = String(visible);
        });

        for (const node of this.nodeTargets) {
            const leaves = this.leavesUnder.get(node.dataset.code as string) ?? [];
            const kept = leaves.filter((leaf) => !this.hidden.has(leaf)).length;
            node.checked = kept === leaves.length;
            node.indeterminate = kept > 0 && kept < leaves.length;
        }

        for (const count of this.nodeCountTargets) {
            const total = (this.leavesUnder.get(count.dataset.code as string) ?? []).reduce((sum, leaf) => sum + (counts.get(leaf) ?? 0), 0);
            count.textContent = total > 0 ? `(${total})` : '';
        }

        if (this.ready) {
            this.map.setFilter(LAYER_ID, ['!', ['in', ['get', 'leaf'], ['literal', [...this.hidden]]]]);
        }
    }

    // The leaves of the category tree under each category (itself for a leaf), read from the settings tree.
    private buildLeavesUnder(): Map<string, string[]> {
        const parentOf = new Map<string, string>();
        const isLeaf = new Map<string, boolean>();
        for (const node of this.nodeTargets) {
            parentOf.set(node.dataset.code as string, node.dataset.parent as string);
            isLeaf.set(node.dataset.code as string, '1' === node.dataset.leafNode);
        }

        const leavesUnder = new Map<string, string[]>();
        for (const [code, leaf] of isLeaf) {
            if (!leaf) {
                continue;
            }

            for (let ancestor: string | undefined = code; ancestor; ancestor = parentOf.get(ancestor)) {
                leavesUnder.set(ancestor, [...(leavesUnder.get(ancestor) ?? []), code]);
            }
        }

        return leavesUnder;
    }

    // The whole unité urbaine when its outline is known, else the points.
    private fitInitial() {
        if (!this.hasBoundaryValue) {
            this.fitVisible(false);

            return;
        }

        const bounds = new LngLatBounds();
        const extend = (coordinates: unknown): void => {
            if (Array.isArray(coordinates) && typeof coordinates[0] === 'number') {
                bounds.extend(coordinates as [number, number]);
            } else if (Array.isArray(coordinates)) {
                coordinates.forEach(extend);
            }
        };
        extend((JSON.parse(this.boundaryValue) as GeoJSON.Geometry & { coordinates: unknown }).coordinates);

        if (bounds.isEmpty()) {
            this.fitVisible(false);

            return;
        }

        this.map.fitBounds(bounds, { padding: 40, animate: false });
    }

    private fitVisible(animate: boolean) {
        const bounds = new LngLatBounds();
        for (const item of this.itemTargets) {
            if (!this.hidden.has(item.dataset.leaf as string)) {
                bounds.extend([Number(item.dataset.lon), Number(item.dataset.lat)]);
            }
        }
        if (!bounds.isEmpty()) {
            this.map.fitBounds(bounds, { padding: 40, maxZoom: FOCUS_ZOOM, animate });
        }
    }

    private loadHidden(): Set<string> {
        try {
            const stored: string[] = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');

            return new Set(stored.flatMap((code) => this.leavesUnder.get(code) ?? []));
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
