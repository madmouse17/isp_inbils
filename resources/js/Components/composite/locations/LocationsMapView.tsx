import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import L from 'leaflet';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Badge } from '@/Components/ui/Badge';
import { cn } from '@/lib/utils';
import { useMapDefault } from '@/hooks/useMapDefault';

/* ---------- Types ---------- */

export interface MapMarker {
    id: number;
    code: string;
    name: string;
    type: 'region' | 'area' | 'pop' | 'rack' | 'site';
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

/* ---------- Marker icon config per type ---------- */

const TYPE_ICONS: Record<MapMarker['type'], { shape: string; label: string; ariaLabel: string }> = {
    region: {
        shape: 'M12 2L2 22h20L12 2Z',
        label: 'R',
        ariaLabel: 'Region',
    },
    area: {
        shape: 'M4 4h16v16H4V4Z',
        label: 'A',
        ariaLabel: 'Area',
    },
    pop: {
        shape: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2Z',
        label: 'P',
        ariaLabel: 'POP',
    },
    rack: {
        shape: 'M3 3h18v18H3V3ZM6 7h12v2H6V7ZM6 11h12v2H6v-2ZM6 15h12v2H6v-2Z',
        label: 'K',
        ariaLabel: 'Rack',
    },
    site: {
        shape: 'M12 0L24 12 12 24 0 12Z',
        label: 'S',
        ariaLabel: 'Site',
    },
};

const INACTIVE_COLOR = '#94a3b8'; // slate-400
const MARKER_ICON_WIDTH = 28;
const MARKER_ICON_HEIGHT = 36;

function buildDivIcon(type: MapMarker['type'], isActive: boolean): L.DivIcon {
    const config = TYPE_ICONS[type];
    const fill = isActive ? '#2563eb' : INACTIVE_COLOR;
    const stroke = isActive ? '#1e3a8a' : '#64748b';

    return L.divIcon({
        className: '',
        html: `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="36" viewBox="0 0 28 36" aria-label="${config.ariaLabel}">
  <path fill="${fill}" stroke="${stroke}" stroke-width="1.5" d="M14 0C6.27 0 0 6.27 0 14c0 10.5 14 22 14 22s14-11.5 14-22C28 6.27 21.73 0 14 0Z"/>
  <path fill="white" fill-opacity="0.9" transform="translate(2,2) scale(0.83)" d="${config.shape}"/>
  <text x="14" y="16" text-anchor="middle" dominant-baseline="central" fill="white" font-size="9" font-weight="bold" font-family="system-ui,sans-serif">${config.label}</text>
</svg>`,
        iconSize: [28, 36],
        iconAnchor: [14, 36],
        popupAnchor: [0, -36],
    });
}

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
    const [filterType, setFilterType] = useState<MapMarker['type'] | ''>('');
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
            zoomControl: true,
        });

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

            /* Build popup HTML */
            const popupDiv = document.createElement('div');
            popupDiv.className = 'locations-map-popup';
            popupDiv.innerHTML = `
                <div style="min-width:180px;font-family:system-ui,sans-serif;">
                    <p style="margin:0 0 4px;font-weight:600;font-size:14px;">${escapeHtml(m.code)}</p>
                    <p style="margin:0 0 4px;font-size:13px;color:#6b7280;">${escapeHtml(m.name)}</p>
                    <p style="margin:0 0 6px;font-size:12px;">
                        <span style="display:inline-block;padding:1px 6px;border-radius:4px;background:#e2e8f0;font-size:11px;">${m.type.toUpperCase()}</span>
                        ${!m.is_active ? '<span style="display:inline-block;padding:1px 6px;border-radius:4px;background:#fef3c7;color:#92400e;font-size:11px;margin-left:4px;">Inactive</span>' : ''}
                    </p>
                    <p style="margin:0 0 6px;font-size:11px;color:#9ca3af;">${lat.toFixed(6)}, ${lng.toFixed(6)}</p>
                    <div class="popup-actions" style="display:flex;gap:4px;"></div>
                </div>
            `;

            const actionsDiv = popupDiv.querySelector('.popup-actions') as HTMLDivElement;

            if (canRef.current.update) {
                const editBtn = document.createElement('button');
                editBtn.textContent = 'Edit';
                editBtn.type = 'button';
                editBtn.style.cssText =
                    'padding:2px 8px;font-size:12px;border:1px solid #d97706;color:#d97706;border-radius:4px;background:transparent;cursor:pointer;';
                editBtn.addEventListener('click', () => onMarkerEditRef.current?.(m));
                actionsDiv.appendChild(editBtn);
            }

            if (canRef.current.delete) {
                const delBtn = document.createElement('button');
                delBtn.textContent = 'Delete';
                delBtn.type = 'button';
                delBtn.style.cssText =
                    'padding:2px 8px;font-size:12px;border:1px solid #dc2626;color:#dc2626;border-radius:4px;background:transparent;cursor:pointer;';
                delBtn.addEventListener('click', () => onMarkerDeleteRef.current?.(m));
                actionsDiv.appendChild(delBtn);
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

        const draftIcon = L.divIcon({
            className: '',
            html: `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="36" viewBox="0 0 28 36" aria-label="Draft location">
  <path fill="#10b981" stroke="#065f46" stroke-width="1.5" stroke-dasharray="4 2" d="M14 0C6.27 0 0 6.27 0 14c0 10.5 14 22 14 22s14-11.5 14-22C28 6.27 21.73 0 14 0Z"/>
  <text x="14" y="15" text-anchor="middle" dominant-baseline="central" fill="white" font-size="12" font-weight="bold" font-family="system-ui,sans-serif">+</text>
</svg>`,
            iconSize: [28, 36],
            iconAnchor: [14, 36],
            popupAnchor: [0, -36],
        });

        const marker = L.marker([draftPin.lat, draftPin.lng], {
            icon: draftIcon,
            draggable: true,
        }).addTo(map);

        marker.on('dragend', () => {
            const pos = marker.getLatLng();
            setDraftPin({ lat: pos.lat, lng: pos.lng });
        });

        /* Draft popup with type choices */
        const popupDiv = document.createElement('div');
        popupDiv.style.cssText = 'min-width:160px;font-family:system-ui,sans-serif;';
        popupDiv.innerHTML = `
            <p style="margin:0 0 6px;font-weight:600;font-size:13px;">Tambah lokasi baru</p>
            <p style="margin:0 0 8px;font-size:11px;color:#9ca3af;">${draftPin.lat.toFixed(6)}, ${draftPin.lng.toFixed(6)}</p>
            <div class="draft-actions" style="display:flex;gap:4px;flex-wrap:wrap;"></div>
            <button class="draft-cancel" type="button" style="margin-top:6px;padding:2px 8px;font-size:11px;border:1px solid #d1d5db;border-radius:4px;background:transparent;cursor:pointer;color:#6b7280;">Cancel</button>
        `;

        const actionsDiv = popupDiv.querySelector('.draft-actions') as HTMLDivElement;
        (['pop', 'site'] as const).forEach((t) => {
            const btn = document.createElement('button');
            btn.textContent = `Tambah ${t.toUpperCase()}`;
            btn.type = 'button';
            btn.style.cssText =
                'padding:3px 10px;font-size:12px;border:1px solid #2563eb;color:#2563eb;border-radius:4px;background:transparent;cursor:pointer;';
            btn.addEventListener('click', () => {
                onMapClickRef.current?.(draftPin.lat, draftPin.lng, t);
                setDraftPin(null);
            });
            actionsDiv.appendChild(btn);
        });

        const cancelBtn = popupDiv.querySelector('.draft-cancel') as HTMLButtonElement;
        cancelBtn.addEventListener('click', () => {
            setDraftPin(null);
        });

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
            {/* Search / filter overlay */}
            <div className="absolute top-3 right-3 left-3 z-1000 flex max-w-md flex-col gap-2 md:right-auto">
                <div className="rounded-lg border border-border bg-background/95 p-2 shadow-xs backdrop-blur-sm dark:bg-background/95">
                    <Input
                        value={filterText}
                        onChange={(e) => setFilterText(e.target.value)}
                        placeholder="Cari lokasi..."
                        aria-label="Search locations on map"
                        className="text-sm"
                    />
                    <div className="mt-2 flex flex-wrap gap-1">
                        <FilterChip
                            label="All Types"
                            active={filterType === ''}
                            onClick={() => setFilterType('')}
                        />
                        {(['region', 'area', 'pop', 'rack', 'site'] as const).map((t) => (
                            <FilterChip
                                key={t}
                                label={t.toUpperCase()}
                                active={filterType === t}
                                onClick={() => setFilterType(filterType === t ? '' : t)}
                            />
                        ))}
                    </div>
                    <div className="mt-1 flex flex-wrap gap-1">
                        <FilterChip
                            label="All Status"
                            active={filterStatus === ''}
                            onClick={() => setFilterStatus('')}
                        />
                        <FilterChip
                            label="Active"
                            active={filterStatus === 'active'}
                            onClick={() =>
                                setFilterStatus(filterStatus === 'active' ? '' : 'active')
                            }
                        />
                        <FilterChip
                            label="Inactive"
                            active={filterStatus === 'inactive'}
                            onClick={() =>
                                setFilterStatus(filterStatus === 'inactive' ? '' : 'inactive')
                            }
                        />
                    </div>
                </div>
            </div>

            {/* Legend */}
            <div className="absolute bottom-3 left-3 z-1000">
                <button
                    type="button"
                    onClick={() => setLegendOpen(!legendOpen)}
                    className="rounded-lg border border-border bg-background px-2 py-1 text-xs font-medium text-foreground shadow-xs hover:bg-accent"
                    aria-label={legendOpen ? 'Hide legend' : 'Show legend'}
                >
                    {legendOpen ? 'Legenda ▾' : 'Legenda ▸'}
                </button>
                {legendOpen && (
                    <div className="mt-1 rounded-lg border border-border bg-background p-2 shadow-xs">
                        <ul className="space-y-1 text-xs">
                            {Object.entries(TYPE_ICONS).map(([type, config]) => (
                                <li key={type} className="flex items-center gap-2">
                                    <span className="inline-flex h-5 w-5 items-center justify-center rounded bg-primary/10 text-xs font-bold text-primary">
                                        {config.label}
                                    </span>
                                    <span className="text-foreground">{config.ariaLabel}</span>
                                </li>
                            ))}
                            <li className="flex items-center gap-2">
                                <span
                                    className="inline-block h-3 w-3 rounded"
                                    style={{ background: INACTIVE_COLOR }}
                                />
                                <span className="text-muted-foreground">Inactive</span>
                            </li>
                        </ul>
                    </div>
                )}
            </div>

            {/* Fit bounds + stats */}
            <div className="absolute right-3 bottom-3 z-1000 flex items-center gap-2">
                <Badge variant="secondary" className="text-xs">
                    {filteredMarkers.length} lokasi
                </Badge>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={fitBounds}
                    aria-label="Fit all markers in view"
                >
                    Fit All
                </Button>
            </div>

            {/* Map container */}
            <div className="relative">
                <div
                    id={`locations-map-${mapId}`}
                    ref={containerRef}
                    className="h-128 w-full rounded-lg border border-border md:h-160"
                    role="application"
                    aria-label="Peta lokasi"
                />
                {tileStatus !== 'ok' && (
                    <div
                        className="absolute inset-x-3 top-1/2 z-1100 -translate-y-1/2 rounded-lg border border-warning/50 bg-background/95 p-4 text-center shadow-xs backdrop-blur-sm sm:inset-x-1/4 dark:bg-background/95"
                        role={tileStatus === 'error' ? 'alert' : 'status'}
                        aria-live="polite"
                    >
                        <p className="text-sm font-semibold text-foreground">
                            {tileStatus === 'retrying'
                                ? 'Mencoba memuat ulang lapisan peta...'
                                : 'Lapisan peta tidak dapat dimuat'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Marker dan kontrol peta tetap tersedia. Gunakan tab Tabel untuk melihat
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
                    Peta memerlukan JavaScript aktif. Gunakan tabel di bawah sebagai fallback.
                </p>
            </noscript>
        </div>
    );
}

/* ---------- FilterChip ---------- */

function FilterChip({
    label,
    active,
    onClick,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors',
                active
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-muted text-muted-foreground hover:bg-accent hover:text-accent-foreground',
            )}
        >
            {label}
        </button>
    );
}

/* ---------- Helpers ---------- */

function escapeHtml(str: string): string {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

export default LocationsMapView;
