import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import L from 'leaflet';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Badge, badgeVariants } from '@/Components/ui/Badge';
import { Button, buttonVariants } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { NativeSelect } from '@/Components/composite/NativeSelect';
import { ACTIVE_STATE, LOCATION_TYPE, optionsOf } from '@/lib/statuses';
import { cn } from '@/lib/utils';
import { useMapDefault } from '@/hooks/useMapDefault';

/* ---------- Types ---------- */

export type LocationType = keyof typeof LOCATION_TYPE;

export interface MapMarker {
    id: string;
    code: string;
    name: string;
    type: LocationType;
    lat: number | string;
    lng: number | string;
    is_active: boolean;
}

export interface LocationsMapViewProps {
    markers: MapMarker[];
    can: { create: boolean; update: boolean; move: boolean; delete: boolean };
    onMarkerEdit?: (marker: MapMarker) => void;
    onMarkerDelete?: (marker: MapMarker) => void;
    onMapClick?: (lat: number, lng: number, type: 'pop' | 'site') => void;
    className?: string;
}

/* ---------- Marker look ---------- */

/** One shape per type, so the type reads without colour: the marker and the legend draw the same paths (24 x 24). */
const TYPE_SHAPES: Record<LocationType, string> = {
    region: 'M12 2L2 22h20L12 2Z',
    area: 'M4 4h16v16H4V4Z',
    pop: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2Z',
    rack: 'M3 3h18v18H3V3ZM6 7h12v2H6V7ZM6 11h12v2H6v-2ZM6 15h12v2H6v-2Z',
    site: 'M12 0L24 12 12 24 0 12Z',
};

const MARKER_BODY =
    'M14 0C6.27 0 0 6.27 0 14c0 10.5 14 22 14 22s14-11.5 14-22C28 6.27 21.73 0 14 0Z';
const MARKER_ICON_WIDTH = 28;
const MARKER_ICON_HEIGHT = 36;

/** The status tokens of the app: an active place is `info`, an inactive one `neutral`, a pin not saved yet `success`. */
const ACTIVE_FILL = 'var(--status-info)';
const INACTIVE_FILL = 'var(--status-neutral)';
const DRAFT_FILL = 'var(--status-success)';

const DRAFT_LABEL = 'Lokasi baru, belum disimpan';

function buildDivIcon(type: LocationType, isActive: boolean): L.DivIcon {
    const fill = isActive ? ACTIVE_FILL : INACTIVE_FILL;

    return L.divIcon({
        className: '',
        html: `<svg xmlns="http://www.w3.org/2000/svg" width="${MARKER_ICON_WIDTH}" height="${MARKER_ICON_HEIGHT}" viewBox="0 0 28 36" role="img" aria-label="${LOCATION_TYPE[type].label}">
  <path style="fill:${fill};stroke:var(--background)" stroke-width="1.5" d="${MARKER_BODY}"/>
  <path style="fill:var(--background)" transform="translate(2,2) scale(0.83)" d="${TYPE_SHAPES[type]}"/>
</svg>`,
        iconSize: [MARKER_ICON_WIDTH, MARKER_ICON_HEIGHT],
        iconAnchor: [MARKER_ICON_WIDTH / 2, MARKER_ICON_HEIGHT],
        popupAnchor: [0, -MARKER_ICON_HEIGHT],
    });
}

function buildDraftIcon(): L.DivIcon {
    return L.divIcon({
        className: '',
        html: `<svg xmlns="http://www.w3.org/2000/svg" width="${MARKER_ICON_WIDTH}" height="${MARKER_ICON_HEIGHT}" viewBox="0 0 28 36" role="img" aria-label="${DRAFT_LABEL}">
  <path style="fill:${DRAFT_FILL};stroke:var(--background)" stroke-width="1.5" stroke-dasharray="4 2" d="${MARKER_BODY}"/>
  <path style="fill:var(--background)" d="M12.5 8h3v4.5H20v3h-4.5V20h-3v-4.5H8v-3h4.5V8Z"/>
</svg>`,
        iconSize: [MARKER_ICON_WIDTH, MARKER_ICON_HEIGHT],
        iconAnchor: [MARKER_ICON_WIDTH / 2, MARKER_ICON_HEIGHT],
        popupAnchor: [0, -MARKER_ICON_HEIGHT],
    });
}

/*
 * The map follows the theme. The tiles are inverted in dark mode (a light OpenStreetMap tile would clash with
 * the tokens), and the popup and the zoom buttons wear the popover and background tokens, so text keeps the
 * contrast of the rest of the app in both themes. Utilities sit in a later layer than leaflet.css, so they win.
 */
const MAP_THEME = cn(
    'font-sans',
    'dark:[&_.leaflet-tile-pane]:brightness-90 dark:[&_.leaflet-tile-pane]:contrast-90 dark:[&_.leaflet-tile-pane]:hue-rotate-180 dark:[&_.leaflet-tile-pane]:invert',
    '[&_.leaflet-popup-content-wrapper]:rounded-lg [&_.leaflet-popup-content-wrapper]:border [&_.leaflet-popup-content-wrapper]:border-border [&_.leaflet-popup-content-wrapper]:bg-popover [&_.leaflet-popup-content-wrapper]:text-popover-foreground [&_.leaflet-popup-content-wrapper]:shadow-md',
    '[&_.leaflet-popup-tip]:bg-popover [&_.leaflet-popup-tip]:text-popover-foreground',
    '[&_.leaflet-popup-content]:m-3 [&_.leaflet-popup-content]:text-sm',
    '[&_.leaflet-popup-close-button]:text-muted-foreground',
    '[&_.leaflet-bar]:border [&_.leaflet-bar]:border-border [&_.leaflet-bar]:shadow-md',
    '[&_.leaflet-bar_a]:border-border [&_.leaflet-bar_a]:bg-background [&_.leaflet-bar_a]:text-foreground [&_.leaflet-bar_a:hover]:bg-accent',
);

/** A node with a class and optional text. The popups are built with it, never with HTML strings, so a name cannot inject markup. */
function element<K extends keyof HTMLElementTagNameMap>(
    tag: K,
    className: string,
    text?: string,
): HTMLElementTagNameMap[K] {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
}

function popupButton(label: string, className: string, onClick: () => void): HTMLButtonElement {
    const button = element('button', className, label);
    button.type = 'button';
    button.addEventListener('click', onClick);

    return button;
}

const BADGE_BASE = 'inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold';

/* ---------- Component ---------- */

export function LocationsMapView({
    markers,
    can,
    onMarkerEdit,
    onMarkerDelete,
    onMapClick,
    className,
}: LocationsMapViewProps) {
    const mapDefault = useMapDefault();
    const mapDefaultRef = useRef(mapDefault);
    const mapId = useId().replace(/:/g, '');
    const containerRef = useRef<HTMLDivElement | null>(null);
    const mapRef = useRef<L.Map | null>(null);
    const tileLayerRef = useRef<L.TileLayer | null>(null);
    const markersLayerRef = useRef<L.LayerGroup | null>(null);
    const savedMarkerElementsRef = useRef(new Map<HTMLElement, L.Marker>());
    const onMapClickRef = useRef(onMapClick);
    const onMarkerEditRef = useRef(onMarkerEdit);
    const onMarkerDeleteRef = useRef(onMarkerDelete);
    const canRef = useRef(can);

    const [filterText, setFilterText] = useState('');
    const [filterType, setFilterType] = useState<LocationType | ''>('');
    const [filterStatus, setFilterStatus] = useState<'active' | 'inactive' | ''>('');
    const [legendOpen, setLegendOpen] = useState(true);
    const [draftPin, setDraftPin] = useState<{ lat: number; lng: number } | null>(null);
    const [tileStatus, setTileStatus] = useState<'ok' | 'error' | 'retrying'>('ok');
    const tileRetryTimeoutRef = useRef<number | null>(null);

    useEffect(() => {
        onMapClickRef.current = onMapClick;
    }, [onMapClick]);
    useEffect(() => {
        onMarkerEditRef.current = onMarkerEdit;
    }, [onMarkerEdit]);
    useEffect(() => {
        onMarkerDeleteRef.current = onMarkerDelete;
    }, [onMarkerDelete]);
    useEffect(() => {
        canRef.current = can;
    }, [can]);

    /* Filter markers */
    const filteredMarkers = useMemo(
        () =>
            markers.filter((m) => {
                if (filterType && m.type !== filterType) return false;
                if (filterStatus === 'active' && !m.is_active) return false;
                if (filterStatus === 'inactive' && m.is_active) return false;
                if (filterText) {
                    const q = filterText.toLowerCase();
                    if (!m.code.toLowerCase().includes(q) && !m.name.toLowerCase().includes(q))
                        return false;
                }
                return true;
            }),
        [markers, filterText, filterType, filterStatus],
    );

    /* Init map once */
    useEffect(() => {
        const el = containerRef.current;
        if (!el || mapRef.current) return;
        const savedMarkerElements = savedMarkerElementsRef.current;

        const map = L.map(el, {
            center: [mapDefaultRef.current.center.lat, mapDefaultRef.current.center.lng],
            zoom: mapDefaultRef.current.zoom,
            zoomControl: false,
        });
        // Bottom right: the search and the filters own the top left, the legend the bottom left.
        L.control
            .zoom({ position: 'bottomright', zoomInTitle: 'Perbesar', zoomOutTitle: 'Perkecil' })
            .addTo(map);

        const tileLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19,
        });

        tileLayer.on('tileerror', () => setTileStatus('error'));
        tileLayer.on('tileload', () =>
            setTileStatus((status) => (status === 'retrying' ? 'ok' : status)),
        );
        tileLayer.addTo(map);

        const layerGroup = L.layerGroup().addTo(map);

        const handleSavedMarkerClick = (event: Event) => {
            const eventTarget = event.target;
            if (!(eventTarget instanceof Element)) return;

            const markerElement = eventTarget.closest('.leaflet-marker-icon');
            if (!(markerElement instanceof HTMLElement)) return;

            const marker = savedMarkerElements.get(markerElement);
            if (!marker) return;

            event.stopImmediatePropagation();
            setDraftPin(null);
            marker.openPopup();
        };
        const mapContainer = map.getContainer();
        const handleSavedMarkerMouseDown = (event: MouseEvent) => {
            if (!(event.target instanceof Element)) return;

            const markerElement = event.target.closest('.leaflet-marker-icon');
            if (!(markerElement instanceof HTMLElement) || !savedMarkerElements.has(markerElement))
                return;

            mapContainer.focus({ preventScroll: true });
        };
        mapContainer.addEventListener('mousedown', handleSavedMarkerMouseDown, true);
        mapContainer.addEventListener('click', handleSavedMarkerClick, true);

        /* Empty map clicks create draft pins; saved markers own their clicks below. */
        map.on('click', (e: L.LeafletMouseEvent) => {
            if (!canRef.current.create) return;

            const eventTarget = e.originalEvent.target;
            if (eventTarget instanceof Node) {
                let markerHit: L.Marker | null = null;

                markersLayerRef.current?.eachLayer((layer) => {
                    if (markerHit !== null || !(layer instanceof L.Marker)) return;

                    const markerElement = layer.getElement();
                    if (markerElement?.contains(eventTarget)) {
                        markerHit = layer;
                    }
                });

                const markerToOpen = markerHit as L.Marker | null;
                if (markerToOpen !== null) {
                    setDraftPin(null);
                    markerToOpen.openPopup();
                    return;
                }
            }

            if (e.sourceTarget !== map) return;

            const clickPoint = map.latLngToContainerPoint(e.latlng);
            let nearestSavedMarker: L.Marker | null = null;
            let nearestDistanceSquared = Number.POSITIVE_INFINITY;

            markersLayerRef.current?.eachLayer((layer) => {
                if (!(layer instanceof L.Marker)) return;

                const markerPoint = map.latLngToContainerPoint(layer.getLatLng());
                const deltaX = clickPoint.x - markerPoint.x;
                const deltaY = clickPoint.y - markerPoint.y;
                const distanceSquared = deltaX * deltaX + deltaY * deltaY;
                const withinIconFootprint =
                    Math.abs(deltaX) <= MARKER_ICON_WIDTH / 2 &&
                    Math.abs(deltaY) <= MARKER_ICON_HEIGHT;

                if (withinIconFootprint && distanceSquared < nearestDistanceSquared) {
                    nearestSavedMarker = layer;
                    nearestDistanceSquared = distanceSquared;
                }
            });

            const markerToOpen = nearestSavedMarker as L.Marker | null;
            if (markerToOpen !== null) {
                setDraftPin(null);
                markerToOpen.openPopup();
                return;
            }

            setDraftPin({ lat: e.latlng.lat, lng: e.latlng.lng });
        });

        mapRef.current = map;
        tileLayerRef.current = tileLayer;
        markersLayerRef.current = layerGroup;

        const t1 = window.setTimeout(() => map.invalidateSize(), 0);
        const t2 = window.setTimeout(() => map.invalidateSize(), 200);

        return () => {
            window.clearTimeout(t1);
            window.clearTimeout(t2);
            if (tileRetryTimeoutRef.current !== null) {
                window.clearTimeout(tileRetryTimeoutRef.current);
            }
            mapContainer.removeEventListener('mousedown', handleSavedMarkerMouseDown, true);
            map.getContainer().removeEventListener('click', handleSavedMarkerClick, true);
            savedMarkerElements.clear();
            map.remove();
            mapRef.current = null;
            tileLayerRef.current = null;
            markersLayerRef.current = null;
        };
    }, []);

    const retryTiles = useCallback(() => {
        const tileLayer = tileLayerRef.current;
        if (!tileLayer) return;

        if (tileRetryTimeoutRef.current !== null) {
            window.clearTimeout(tileRetryTimeoutRef.current);
        }

        setTileStatus('retrying');
        tileLayer.redraw();
        tileRetryTimeoutRef.current = window.setTimeout(() => {
            setTileStatus((status) => (status === 'retrying' ? 'error' : status));
        }, 5000);
    }, []);

    /* Sync markers to map */
    useEffect(() => {
        const layer = markersLayerRef.current;
        const map = mapRef.current;
        if (!layer || !map) return;

        savedMarkerElementsRef.current.clear();
        layer.clearLayers();
        const validMarkers = filteredMarkers.filter((m) => {
            const lat = Number(m.lat);
            const lng = Number(m.lng);
            return Number.isFinite(lat) && Number.isFinite(lng);
        });

        if (validMarkers.length === 0) return;

        const bounds = L.latLngBounds([]);

        validMarkers.forEach((m) => {
            const lat = Number(m.lat);
            const lng = Number(m.lng);
            const icon = buildDivIcon(m.type, m.is_active);
            const marker = L.marker([lat, lng], {
                icon,
                bubblingMouseEvents: false,
                autoPanOnFocus: false,
            });
            marker.on('click', (e: L.LeafletMouseEvent) => {
                L.DomEvent.stopPropagation(e.originalEvent);
                setDraftPin(null);
                marker.openPopup();
            });

            /* Popup: the same tokens and button styles as the rest of the app */
            const popupDiv = element('div', 'locations-map-popup min-w-44 space-y-2');
            const heading = element('div', 'space-y-0.5');
            heading.append(
                element('p', 'font-semibold text-foreground', m.code),
                element('p', 'text-muted-foreground', m.name),
            );
            const tags = element('div', 'flex flex-wrap gap-1');
            tags.append(
                element(
                    'span',
                    `${BADGE_BASE} ${badgeVariants.neutral}`,
                    LOCATION_TYPE[m.type].label,
                ),
            );
            if (!m.is_active) {
                tags.append(
                    element(
                        'span',
                        `${BADGE_BASE} ${badgeVariants.neutral}`,
                        ACTIVE_STATE.false.label,
                    ),
                );
            }
            const actionsDiv = element('div', 'flex flex-wrap gap-2');
            popupDiv.append(
                heading,
                tags,
                element(
                    'p',
                    'text-xs text-muted-foreground tabular-nums',
                    `${lat.toFixed(6)}, ${lng.toFixed(6)}`,
                ),
                actionsDiv,
            );

            if (canRef.current.update) {
                actionsDiv.append(
                    popupButton('Ubah', buttonVariants({ variant: 'outline', size: 'sm' }), () =>
                        onMarkerEditRef.current?.(m),
                    ),
                );
            }

            if (canRef.current.delete) {
                actionsDiv.append(
                    popupButton(
                        'Hapus',
                        buttonVariants({
                            variant: 'outline',
                            size: 'sm',
                            className: 'text-destructive-text',
                        }),
                        () => onMarkerDeleteRef.current?.(m),
                    ),
                );
            }

            marker.bindPopup(popupDiv, { maxWidth: 280 });
            marker.addTo(layer);
            const markerElement = marker.getElement();
            if (markerElement) {
                savedMarkerElementsRef.current.set(markerElement, marker);
            }
            bounds.extend([lat, lng]);
        });

        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15, animate: false });
        }
    }, [filteredMarkers]);

    /* Draft pin marker */
    const draftMarkerRef = useRef<L.Marker | null>(null);
    useEffect(() => {
        const map = mapRef.current;
        if (!map) return;

        if (draftMarkerRef.current) {
            draftMarkerRef.current.remove();
            draftMarkerRef.current = null;
        }

        if (!draftPin) return;

        const marker = L.marker([draftPin.lat, draftPin.lng], {
            icon: buildDraftIcon(),
            draggable: true,
        }).addTo(map);

        marker.on('dragend', () => {
            const pos = marker.getLatLng();
            setDraftPin({ lat: pos.lat, lng: pos.lng });
        });

        /* Draft popup with type choices */
        const popupDiv = element('div', 'min-w-40 space-y-2');
        popupDiv.append(
            element('p', 'font-semibold text-foreground', 'Buat lokasi baru di sini'),
            element(
                'p',
                'text-xs text-muted-foreground tabular-nums',
                `${draftPin.lat.toFixed(6)}, ${draftPin.lng.toFixed(6)}`,
            ),
        );
        const actionsDiv = element('div', 'flex flex-wrap gap-2');
        (['pop', 'site'] as const).forEach((t) => {
            actionsDiv.append(
                popupButton(
                    `Tambah ${LOCATION_TYPE[t].label}`,
                    buttonVariants({ variant: 'primary', size: 'sm' }),
                    () => {
                        onMapClickRef.current?.(draftPin.lat, draftPin.lng, t);
                        setDraftPin(null);
                    },
                ),
            );
        });
        actionsDiv.append(
            popupButton('Batal', buttonVariants({ variant: 'ghost', size: 'sm' }), () =>
                setDraftPin(null),
            ),
        );
        popupDiv.append(actionsDiv);

        marker.bindPopup(popupDiv, { maxWidth: 240 }).openPopup();
        draftMarkerRef.current = marker;
    }, [draftPin]);

    /* Fit bounds button */
    const fitBounds = useCallback(() => {
        const map = mapRef.current;
        if (!map) return;

        const validMarkers = filteredMarkers.filter((m) => {
            const lat = Number(m.lat);
            const lng = Number(m.lng);
            return Number.isFinite(lat) && Number.isFinite(lng);
        });

        if (validMarkers.length === 0) {
            map.setView([mapDefault.center.lat, mapDefault.center.lng], mapDefault.zoom);
            return;
        }

        const bounds = L.latLngBounds(
            validMarkers.map((m) => [Number(m.lat), Number(m.lng)] as L.LatLngExpression),
        );
        map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15, animate: false });
    }, [filteredMarkers, mapDefault]);

    return (
        <div className={cn('relative', className)}>
            {/* Search and filters: top left */}
            <div className="absolute top-3 right-3 left-3 z-1000 max-w-md md:right-auto">
                <div className="space-y-2 rounded-lg border border-border bg-background/95 p-2 backdrop-blur-sm">
                    <Input
                        value={filterText}
                        onChange={(e) => setFilterText(e.target.value)}
                        placeholder="Cari kode atau nama lokasi"
                        aria-label="Cari lokasi di peta"
                    />
                    <div className="grid grid-cols-2 gap-2">
                        <NativeSelect
                            aria-label="Tipe lokasi"
                            value={filterType}
                            onChange={(e) => setFilterType(e.target.value as LocationType | '')}
                            options={[
                                { value: '', label: 'Semua tipe' },
                                ...optionsOf(LOCATION_TYPE),
                            ]}
                        />
                        <NativeSelect
                            aria-label="Status lokasi"
                            value={filterStatus}
                            onChange={(e) =>
                                setFilterStatus(e.target.value as 'active' | 'inactive' | '')
                            }
                            options={[
                                { value: '', label: 'Semua status' },
                                { value: 'active', label: ACTIVE_STATE.true.label },
                                { value: 'inactive', label: ACTIVE_STATE.false.label },
                            ]}
                        />
                    </div>
                    <div className="flex items-center justify-between gap-2">
                        <Badge variant="secondary">{filteredMarkers.length} lokasi</Badge>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={fitBounds}
                            aria-label="Tampilkan semua lokasi di peta"
                        >
                            Tampilkan semua
                        </Button>
                    </div>
                </div>
            </div>

            {/* Legend: bottom left, the same shapes the markers use */}
            <div className="absolute bottom-3 left-3 z-1000">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setLegendOpen(!legendOpen)}
                    aria-expanded={legendOpen}
                    aria-label={legendOpen ? 'Sembunyikan legenda' : 'Tampilkan legenda'}
                    className="bg-background"
                    rightIcon={
                        legendOpen ? (
                            <ChevronDown className="size-4" aria-hidden="true" />
                        ) : (
                            <ChevronRight className="size-4" aria-hidden="true" />
                        )
                    }
                >
                    Legenda
                </Button>
                {legendOpen && (
                    <div className="mt-1 rounded-lg border border-border bg-background p-2">
                        <ul className="space-y-1 text-xs">
                            {(Object.keys(LOCATION_TYPE) as LocationType[]).map((type) => (
                                <li key={type} className="flex items-center gap-2">
                                    <MarkerShape type={type} color={ACTIVE_FILL} />
                                    <span className="text-foreground">
                                        {LOCATION_TYPE[type].label}
                                    </span>
                                </li>
                            ))}
                            <li className="flex items-center gap-2">
                                <MarkerShape type="pop" color={INACTIVE_FILL} />
                                <span className="text-foreground">{ACTIVE_STATE.false.label}</span>
                            </li>
                        </ul>
                    </div>
                )}
            </div>

            {/* Map container */}
            <div className="relative">
                <div
                    id={`locations-map-${mapId}`}
                    ref={containerRef}
                    className={cn(
                        'h-128 w-full rounded-lg border border-border md:h-160',
                        MAP_THEME,
                    )}
                    role="application"
                    aria-label="Peta lokasi"
                />
                {tileStatus !== 'ok' && (
                    <div
                        className="absolute inset-x-3 top-1/2 z-1100 -translate-y-1/2 rounded-lg border border-warning/50 bg-background/95 p-4 text-center backdrop-blur-sm sm:inset-x-1/4"
                        role={tileStatus === 'error' ? 'alert' : 'status'}
                        aria-live="polite"
                    >
                        <p className="text-sm font-semibold text-foreground">
                            {tileStatus === 'retrying'
                                ? 'Mencoba memuat ulang lapisan peta...'
                                : 'Lapisan peta tidak dapat dimuat'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Penanda dan kontrol peta tetap tersedia. Gunakan tab Tabel untuk melihat
                            daftar lokasi tanpa lapisan peta.
                        </p>
                        {tileStatus === 'error' && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="mt-3"
                                onClick={retryTiles}
                            >
                                Coba lagi
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {/* Tile failure fallback text */}
            <noscript>
                <p className="p-4 text-sm text-muted-foreground">
                    Peta memerlukan JavaScript aktif. Gunakan tab Tabel sebagai gantinya.
                </p>
            </noscript>
        </div>
    );
}

/** A type's marker shape in the legend, in the colour its marker has. */
function MarkerShape({ type, color }: { type: LocationType; color: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            aria-hidden="true"
            className="size-5 shrink-0"
            style={{ fill: color }}
        >
            <path d={TYPE_SHAPES[type]} />
        </svg>
    );
}

export default LocationsMapView;
