import type { IControl, Map as MapLibreMap, RasterLayerSpecification } from 'maplibre-gl';

const STORAGE_KEY = 'pondeo:map:basemap';
const DEFAULT_BASE = 'plan';
const ATTRIBUTION = '© <a href="https://geoservices.ign.fr/" target="_blank" rel="noopener">IGN</a>';
const FOREST_LEGEND_URL = 'https://data.geopf.fr/annexes/ressources/legendes/LANDCOVER.FORESTINVENTORY.V2-legend.png';
const PARK_COLOR = 'rgb(219, 191, 146)';
const LAYERS_ICON = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/></svg>';

type RasterSource = { label: string; layer: string; format: 'png' | 'jpeg'; minzoom: number; maxzoom: number; opacity: number };

// IGN Géoplateforme WMTS (open, no key). The overlays only exist from zoom 6.
const SOURCES: Record<string, RasterSource> = {
    satellite: { label: 'Satellite', layer: 'ORTHOIMAGERY.ORTHOPHOTOS', format: 'jpeg', minzoom: 0, maxzoom: 19, opacity: 1 },
    planign: { label: 'Plan IGN', layer: 'GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2', format: 'png', minzoom: 0, maxzoom: 18, opacity: 1 },
    forests: { label: 'Forêts', layer: 'LANDCOVER.FORESTINVENTORY.V2', format: 'png', minzoom: 6, maxzoom: 16, opacity: 0.6 },
    parks: { label: 'Parcs naturels régionaux', layer: 'Patrinat_PNR', format: 'png', minzoom: 6, maxzoom: 16, opacity: 0.6 },
};
const BASES = ['satellite', 'planign'];
const OVERLAYS = ['forests', 'parks'];

type State = { base: string; overlays: string[] };

// Background picker (one base at a time) and optional overlays, drawn as
// raster layers under the labels of the vector style and under the app's
// own layers: this control must be added before the app adds its layers, so
// that its `load` handler runs first.
export default class BasemapControl implements IControl {
    private map?: MapLibreMap;
    private container?: HTMLDivElement;
    private legends: Record<string, HTMLElement> = {};
    private state: State = this.loadState();

    onAdd(map: MapLibreMap): HTMLElement {
        this.map = map;
        map.on('load', () => this.addRasterLayers());

        // The tooltip is a sibling of the group, not inside it, so the group's rounded box can't clip it.
        const container = document.createElement('div');
        container.className = 'maplibregl-ctrl relative';
        this.container = container;

        const group = document.createElement('div');
        group.className = 'maplibregl-ctrl-group';

        // MapLibre's own button rules win over utility classes: inline styles to centre the icon.
        const button = document.createElement('button');
        button.type = 'button';
        button.style.cssText = 'display:flex;align-items:center;justify-content:center;padding:0';
        button.setAttribute('aria-label', 'Fonds de carte et couches');
        button.setAttribute('aria-expanded', 'false');
        button.innerHTML = LAYERS_ICON;

        // Next to the icon once open, and part of the same button: clicking it closes the panel.
        const title = document.createElement('span');
        title.hidden = true;
        title.className = 'pr-3 text-sm font-semibold text-stone-900';
        title.textContent = 'Fonds de carte';
        button.append(title);

        // Instant, unlike the browser's `title`; shown for a mouse only, and not over the open panel.
        const tooltip = document.createElement('div');
        tooltip.hidden = true;
        tooltip.className = 'pointer-events-none absolute right-full top-0 z-10 mr-2 whitespace-nowrap rounded border border-stone-700 bg-stone-800 px-2 py-1 text-xs text-white shadow-lg';
        tooltip.textContent = 'Fond de carte et couches : satellite, forêts, parcs naturels';

        const panel = document.createElement('div');
        panel.hidden = true;
        panel.className = 'space-y-1 border-t border-stone-200 p-2 text-sm text-stone-900';
        panel.append(
            this.group('Fond', [['plan', 'Plan'], ...BASES.map((id): [string, string] => [id, SOURCES[id].label])], 'radio'),
            this.group('Couches', OVERLAYS.map((id): [string, string] => [id, SOURCES[id].label]), 'checkbox'),
            this.legend('forests', this.forestLegend()),
            this.legend('parks', this.parkLegend()),
        );
        this.updateLegends();

        const setOpen = (open: boolean) => {
            panel.hidden = !open;
            title.hidden = !open;
            button.style.width = open ? '100%' : '';
            button.style.justifyContent = open ? 'flex-start' : 'center';
            button.setAttribute('aria-expanded', String(open));
            tooltip.hidden = true;
        };
        button.addEventListener('click', () => setOpen(true === panel.hidden));
        // A click on the map closes it too: the panel would otherwise cover it.
        map.on('click', () => setOpen(false));
        button.addEventListener('pointerenter', (event) => (tooltip.hidden = 'mouse' !== event.pointerType || !panel.hidden));
        button.addEventListener('pointerleave', () => (tooltip.hidden = true));

        group.append(button, panel);
        container.append(group, tooltip);

        return container;
    }

    onRemove(): void {
        this.container?.remove();
        this.map = undefined;
    }

    private group(title: string, items: [string, string][], type: 'radio' | 'checkbox'): HTMLElement {
        const fieldset = document.createElement('div');
        const heading = document.createElement('div');
        heading.className = 'text-xs font-semibold uppercase tracking-wide text-stone-600';
        heading.textContent = title;
        fieldset.append(heading);

        for (const [id, label] of items) {
            const row = document.createElement('label');
            row.className = 'flex cursor-pointer items-center gap-2 whitespace-nowrap';
            const input = document.createElement('input');
            input.type = type;
            input.name = `basemap-${type}`;
            input.className = 'accent-emerald-600';
            input.checked = 'radio' === type ? this.state.base === id : this.state.overlays.includes(id);
            input.addEventListener('change', () => {
                if ('radio' === type) {
                    this.state.base = id;
                } else {
                    this.state.overlays = input.checked ? [...this.state.overlays, id] : this.state.overlays.filter((overlay) => overlay !== id);
                }
                this.applyVisibility();
                this.updateLegends();
                this.saveState();
            });
            row.append(input, label);
            fieldset.append(row);
        }

        return fieldset;
    }

    private legend(id: string, content: HTMLElement): HTMLElement {
        this.legends[id] = content;

        return content;
    }

    // The legend of a layer is only shown while the layer is.
    private updateLegends() {
        for (const [id, legend] of Object.entries(this.legends)) {
            legend.hidden = !this.state.overlays.includes(id);
        }
    }

    // The 32 classes of the IGN legend are too many to be redrawn here: the
    // official image is shown, and only fetched once opened.
    private forestLegend(): HTMLElement {
        const details = document.createElement('details');
        details.className = 'border-t border-stone-200 pt-1';
        const summary = document.createElement('summary');
        summary.className = 'cursor-pointer text-xs font-semibold uppercase tracking-wide text-stone-600';
        summary.textContent = 'Légende des forêts';
        const image = document.createElement('img');
        image.alt = 'Légende de la carte forestière : types de forêts, landes et formations herbacées';
        image.className = 'w-full';
        details.addEventListener('toggle', () => {
            if (details.open && !image.src) {
                image.src = FOREST_LEGEND_URL;
            }
        });
        const scroll = document.createElement('div');
        scroll.className = 'mt-1 max-h-64 w-72 max-w-full overflow-y-auto';
        scroll.append(image);
        details.append(summary, scroll);

        return details;
    }

    private parkLegend(): HTMLElement {
        const row = document.createElement('div');
        row.className = 'flex items-center gap-2 whitespace-nowrap border-t border-stone-200 pt-1 text-xs';
        const swatch = document.createElement('span');
        swatch.className = 'inline-block h-3 w-3 rounded-sm border border-stone-400';
        swatch.style.backgroundColor = PARK_COLOR;
        row.append(swatch, 'Parc naturel régional');

        return row;
    }

    private addRasterLayers() {
        const map = this.map;
        if (undefined === map) {
            return;
        }

        const labels = map.getStyle().layers.find((layer) => 'symbol' === layer.type)?.id;
        for (const id of [...BASES, ...OVERLAYS]) {
            const source = SOURCES[id];
            map.addSource(`basemap-${id}`, {
                type: 'raster',
                tiles: [`https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=${source.layer}&STYLE=normal&FORMAT=image/${source.format}&TILEMATRIXSET=PM&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}`],
                tileSize: 256,
                minzoom: source.minzoom,
                maxzoom: source.maxzoom,
                attribution: ATTRIBUTION,
            });
            const layer: RasterLayerSpecification = {
                id: `basemap-${id}`,
                type: 'raster',
                source: `basemap-${id}`,
                layout: { visibility: 'none' },
                paint: { 'raster-opacity': source.opacity },
            };
            map.addLayer(layer, labels);
        }

        this.applyVisibility();
    }

    // The tiles of a hidden layer are never requested.
    private applyVisibility() {
        for (const id of [...BASES, ...OVERLAYS]) {
            const visible = BASES.includes(id) ? this.state.base === id : this.state.overlays.includes(id);
            this.map?.setLayoutProperty(`basemap-${id}`, 'visibility', visible ? 'visible' : 'none');
        }
    }

    // localStorage can throw (private mode, blocked site data) or hold garbage.
    private loadState(): State {
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null');
            const base = BASES.includes(saved?.base) ? saved.base : DEFAULT_BASE;
            const overlays = Array.isArray(saved?.overlays) ? saved.overlays.filter((id: unknown): id is string => OVERLAYS.includes(id as string)) : [];

            return { base, overlays };
        } catch {
            return { base: DEFAULT_BASE, overlays: [] };
        }
    }

    private saveState() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.state));
        } catch {
            // Not persisting is an acceptable degradation.
        }
    }
}
