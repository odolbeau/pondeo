import { Controller } from '@hotwired/stimulus';
import { session } from '@hotwired/turbo';
import { Map, NavigationControl, Popup, setWorkerUrl, type GeoJSONSource } from 'maplibre-gl';
import type { FiltersChange } from './filters_controller';
import BasemapControl from '../lib/basemap_control';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

// MapLibre finds its worker through a relative `import.meta.url`, which the
// production build can't follow: it would never emit the worker.
setWorkerUrl(workerUrl);

const ZONES_SOURCE_ID = 'unites-urbaines';
const SELECTED_LAYER_ID = `${ZONES_SOURCE_ID}-selected`;
const UNITE_CODE_PATTERN = /^[0-9A-Za-z]{5}$/;
const PLANTS_SOURCE_ID = 'nuclear-plants';
const PLANTS_RADIUS_SOURCE_ID = `${PLANTS_SOURCE_ID}-radius`;
const PLANT_ICON_ID = 'nuclear-plant-icon';
const EARTH_RADIUS_KM = 6371.0088;

export default class extends Controller {
    static targets = ['mapContainer', 'panel', 'frame', 'filters', 'filtersButton', 'exportButton', 'exportHelp'];
    static values = {
        dataUrl: String,
        plantsUrl: String,
        detailUrl: String,
        exportUrl: String,
        exportLimit: Number,
    };

    declare readonly mapContainerTarget: HTMLDivElement;
    declare readonly filtersTarget: HTMLElement;
    declare readonly filtersButtonTarget: HTMLButtonElement;
    declare readonly exportButtonTarget: HTMLButtonElement;
    declare readonly exportHelpTarget: HTMLElement;
    declare readonly panelTarget: HTMLElement;
    declare readonly frameTarget: HTMLElement & { src: string | null };
    declare readonly dataUrlValue: string;
    declare readonly plantsUrlValue: string;
    declare readonly detailUrlValue: string;
    declare readonly exportUrlValue: string;
    declare readonly exportLimitValue: number;

    private map!: Map;
    private selectedCode: string | null = null;
    private plants: GeoJSON.FeatureCollection<GeoJSON.Point> = { type: 'FeatureCollection', features: [] };
    // The filter as the filters panel announced it, and whether the map layers exist yet.
    private filterQuery = new URLSearchParams();
    private ready = false;

    connect() {
        window.addEventListener('popstate', this.onPopState);

        this.map = new Map({
            container: this.mapContainerTarget,
            style: 'https://tiles.openfreemap.org/styles/liberty',
            center: [2.5, 46.6],
            zoom: 5,
        });

        this.map.addControl(new NavigationControl({ showCompass: false }), 'top-right');
        this.map.addControl(new BasemapControl(), 'top-right');

        this.map.on('load', () => {
            this.map.addSource(ZONES_SOURCE_ID, { type: 'geojson', data: this.emptyFeatureCollection() });
            this.map.addLayer({
                id: `${ZONES_SOURCE_ID}-fill`,
                type: 'fill',
                source: ZONES_SOURCE_ID,
                paint: { 'fill-color': '#059669', 'fill-opacity': 0.4 },
            });
            this.map.addLayer({
                id: `${ZONES_SOURCE_ID}-outline`,
                type: 'line',
                source: ZONES_SOURCE_ID,
                paint: { 'line-color': '#059669', 'line-width': 1 },
            });

            this.map.addLayer({
                id: SELECTED_LAYER_ID,
                type: 'line',
                source: ZONES_SOURCE_ID,
                filter: ['==', ['get', 'code'], ''],
                paint: { 'line-color': '#ffffff', 'line-width': 3 },
            });

            this.map.on('click', `${ZONES_SOURCE_ID}-fill`, (event) => {
                const code = event.features?.[0]?.properties?.code;
                if (code) {
                    this.select(String(code));
                }
            });
            this.map.on('mouseenter', `${ZONES_SOURCE_ID}-fill`, () => (this.map.getCanvas().style.cursor = 'pointer'));
            this.map.on('mouseleave', `${ZONES_SOURCE_ID}-fill`, () => (this.map.getCanvas().style.cursor = ''));

            this.loadPlants();

            const fromUrl = this.uniteFromUrl();
            if (null !== fromUrl) {
                this.applySelection(fromUrl);
            }

            this.ready = true;
            this.drawPlantRadii();
            this.updateUrl(false);
            this.refresh();
        });
    }

    disconnect() {
        window.removeEventListener('popstate', this.onPopState);
        this.map?.remove();
    }

    // Back/forward: the URL is the source of truth. The filters panel rebuilds
    // itself from it and says so (see onFiltersChange); the selection is the
    // map's concern. Ignored until the map layers exist; the initial load reads the URL itself.
    private onPopState = () => {
        if (!this.ready) {
            return;
        }

        this.applySelection(this.uniteFromUrl());
    };

    // The filters panel says the filter changed: the visitor did it (a new history
    // entry), or the page is loading or going back (the URL is already right).
    onFiltersChange(event: CustomEvent<FiltersChange>) {
        this.filterQuery = new URLSearchParams(event.detail.params);
        this.filtersButtonTarget.textContent = 0 === event.detail.count ? 'Filtres' : `Filtres (${event.detail.count})`;

        if (!this.ready) {
            return; // The first load draws everything once the layers exist.
        }

        this.drawPlantRadii();
        if ('history' !== event.detail.reason) {
            this.updateUrl('user' === event.detail.reason);
        }
        this.refresh();
    }

    uniteFromUrl(): string | null {
        const code = new URLSearchParams(window.location.search).get('unite');

        return null !== code && UNITE_CODE_PATTERN.test(code) ? code : null;
    }

    select(code: string) {
        this.applySelection(code);
        this.updateUrl(true);
    }

    closePanel() {
        this.applySelection(null);
        this.updateUrl(true);
    }

    // The panel and the highlight only; the URL is the caller's concern.
    applySelection(code: string | null) {
        this.selectedCode = code;
        if (null === code) {
            this.panelTarget.classList.add('hidden');
            this.frameTarget.removeAttribute('src');
            this.frameTarget.innerHTML = '';
        } else {
            this.frameTarget.src = this.detailUrlValue.replace('XXXXX', code);
            this.panelTarget.classList.remove('hidden');
        }
        this.map.setFilter(SELECTED_LAYER_ID, ['==', ['get', 'code'], code ?? '']);
    }

    async refresh() {
        const url = new URL(this.dataUrlValue, window.location.origin);
        for (const [name, value] of this.filterQuery) {
            url.searchParams.set(name, value);
        }

        const response = await fetch(url);
        const geojson: GeoJSON.FeatureCollection = await response.json();

        (this.map.getSource(ZONES_SOURCE_ID) as GeoJSONSource | undefined)?.setData(geojson);
        this.renderExport(geojson.features.length);
    }

    // The map already holds the matching cities: their count tells whether the export is within its cap.
    renderExport(count: number) {
        const allowed = count > 0 && count <= this.exportLimitValue;
        this.exportButtonTarget.disabled = !allowed;
        this.exportButtonTarget.textContent = allowed ? `Exporter en CSV (${count} villes)` : 'Exporter en CSV';
        this.exportHelpTarget.textContent = 0 === count ? 'Aucune ville ne correspond aux filtres.' : count > this.exportLimitValue ? `Affinez les filtres : ${this.exportLimitValue} villes au plus (${count} actuellement).` : '';
    }

    async exportCsv() {
        const url = new URL(this.exportUrlValue, window.location.origin);
        for (const [name, value] of this.filterQuery) {
            url.searchParams.set(name, value);
        }

        const response = await fetch(url);
        if (!response.ok) {
            const body = await response.json().catch(() => null);
            this.exportHelpTarget.textContent = body?.error ?? "L'export a échoué.";

            return;
        }

        const link = document.createElement('a');
        link.href = URL.createObjectURL(await response.blob());
        link.download = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? 'pondeo-export.csv';
        link.click();
        URL.revokeObjectURL(link.href);
    }

    // The filter drawer below md: the map stays the default view.
    openFilters() {
        this.filtersTarget.classList.remove('hidden');
    }

    closeFilters() {
        this.filtersTarget.classList.add('hidden');
    }

    // The plants are always shown; their radius circles only while the
    // exclusion filter is set.
    async loadPlants() {
        const response = await fetch(this.plantsUrlValue);
        this.plants = await response.json();

        this.map.addImage(PLANT_ICON_ID, this.plantIcon());
        this.map.addSource(PLANTS_SOURCE_ID, { type: 'geojson', data: this.plants });
        this.map.addSource(PLANTS_RADIUS_SOURCE_ID, { type: 'geojson', data: this.emptyFeatureCollection() });
        this.map.addLayer({
            id: `${PLANTS_RADIUS_SOURCE_ID}-fill`,
            type: 'fill',
            source: PLANTS_RADIUS_SOURCE_ID,
            paint: { 'fill-color': '#dc2626', 'fill-opacity': 0.18 },
        });
        this.map.addLayer({
            id: `${PLANTS_RADIUS_SOURCE_ID}-line`,
            type: 'line',
            source: PLANTS_RADIUS_SOURCE_ID,
            paint: { 'line-color': '#dc2626', 'line-width': 1.5 },
        });
        this.map.addLayer({
            id: `${PLANTS_SOURCE_ID}-icon`,
            type: 'symbol',
            source: PLANTS_SOURCE_ID,
            layout: { 'icon-image': PLANT_ICON_ID, 'icon-size': 0.3, 'icon-allow-overlap': true },
        });

        const popup = new Popup({ closeButton: false, offset: 16 });
        this.map.on('mouseenter', `${PLANTS_SOURCE_ID}-icon`, (event) => {
            const feature = event.features?.[0];
            if (!feature || 'Point' !== feature.geometry.type) {
                return;
            }
            const { name, reactors, powerMw } = feature.properties;
            const details = null === reactors || undefined === reactors ? '' : ` (${reactors} réacteurs, ${powerMw} MW)`;
            const text = document.createElement('span');
            // The popup is white whatever the page theme: the page's light text would vanish on it.
            text.className = 'text-sm text-stone-900';
            text.textContent = `${name}${details}`;
            popup.setLngLat(feature.geometry.coordinates as [number, number]).setDOMContent(text).addTo(this.map);
        });
        this.map.on('mouseleave', `${PLANTS_SOURCE_ID}-icon`, () => popup.remove());

        this.drawPlantRadii();
    }

    drawPlantRadii() {
        const km = Number(this.filterQuery.get('nuclearExclusionKm')) || null;
        const features = null === km ? [] : this.plants.features.map((plant) => this.circle(plant.geometry.coordinates as [number, number], km));

        (this.map.getSource(PLANTS_RADIUS_SOURCE_ID) as GeoJSONSource | undefined)?.setData({ type: 'FeatureCollection', features });
    }

    // A circle of a given ground radius, as a polygon: MapLibre's own circle
    // layer is sized in pixels, not kilometres.
    circle([lon, lat]: [number, number], km: number): GeoJSON.Feature<GeoJSON.Polygon> {
        const angular = km / EARTH_RADIUS_KM;
        const lat1 = (lat * Math.PI) / 180;
        const lon1 = (lon * Math.PI) / 180;
        const ring: [number, number][] = [];
        for (let i = 0; i <= 64; i++) {
            const bearing = (2 * Math.PI * i) / 64;
            const lat2 = Math.asin(Math.sin(lat1) * Math.cos(angular) + Math.cos(lat1) * Math.sin(angular) * Math.cos(bearing));
            const lon2 = lon1 + Math.atan2(Math.sin(bearing) * Math.sin(angular) * Math.cos(lat1), Math.cos(angular) - Math.sin(lat1) * Math.sin(lat2));
            ring.push([(lon2 * 180) / Math.PI, (lat2 * 180) / Math.PI]);
        }

        return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [ring] }, properties: {} };
    }

    // The ☢️ emoji rendered to a bitmap: a symbol layer only takes images.
    plantIcon(): ImageData {
        const size = 64;
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = size;
        const context = canvas.getContext('2d');
        if (null === context) {
            throw new Error('Canvas 2D context unavailable.');
        }
        context.font = `${size * 0.85}px sans-serif`;
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillText('☢️', size / 2, size / 2 + size * 0.05);

        return context.getImageData(0, 0, size, size);
    }

    // Makes the current filter shareable: reflected in the page URL, and read back
    // from it on load (the selected unité urbaine too). A filter left at its
    // default is omitted entirely, so an untouched filter keeps a clean URL.
    updateUrl(pushHistory: boolean) {
        const params = new URLSearchParams(this.filterQuery);
        if (null !== this.selectedCode) {
            params.set('unite', this.selectedCode);
        }

        const query = params.toString();
        const target = query ? `?${query}` : window.location.pathname;
        if (target === window.location.search || target === window.location.pathname + window.location.search) {
            return;
        }

        if (pushHistory) {
            // Through Turbo, not history.pushState(): an entry without Turbo's state is
            // ignored when coming back to it from another page, which then keeps showing.
            session.history.push(new URL(target, window.location.href));

            return;
        }
        // Keep the current state: it carries Turbo's restoration data, without
        // which the back button silently does nothing on this entry.
        history.replaceState(history.state, '', target);
    }

    emptyFeatureCollection(): GeoJSON.FeatureCollection {
        return { type: 'FeatureCollection', features: [] };
    }
}
