import { useEffect, useId, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { Button } from '@/Components/ui/Button';
import {
    JAKARTA_CENTER,
    resolveCenter,
    toLatLngStrings,
    type LatLngStrings,
} from './latLngMapPicker.helpers';

// Avoid broken Leaflet default icon asset paths under Vite: use a lightweight divIcon.
const DefaultIcon = L.divIcon({
    className: '',
    html: '<svg xmlns="http://www.w3.org/2000/svg" width="25" height="41" viewBox="0 0 25 41" aria-hidden="true"><path fill="#2563eb" stroke="#1e3a8a" stroke-width="1" d="M12.5 0C5.6 0 0 5.6 0 12.5 0 21.9 12.5 41 12.5 41S25 21.9 25 12.5C25 5.6 19.4 0 12.5 0z"/><circle fill="#fff" cx="12.5" cy="12.5" r="5"/></svg>',
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
});
L.Marker.prototype.options.icon = DefaultIcon;

export type LatLngMapPickerProps = {
    lat?: string | null;
    lng?: string | null;
    onChange: (value: LatLngStrings) => void;
    defaultCenter?: { lat: number; lng: number };
    defaultZoom?: number;
    disabled?: boolean;
    heightClassName?: string;
    className?: string;
};

export function LatLngMapPicker({
    lat,
    lng,
    onChange,
    defaultCenter = JAKARTA_CENTER,
    defaultZoom = 13,
    disabled = false,
    heightClassName = 'h-64',
    className,
}: LatLngMapPickerProps) {
    const mapId = useId().replace(/:/g, '');
    const containerRef = useRef<HTMLDivElement | null>(null);
    const mapRef = useRef<L.Map | null>(null);
    const markerRef = useRef<L.Marker | null>(null);
    const onChangeRef = useRef(onChange);
    const disabledRef = useRef(disabled);

    useEffect(() => {
        onChangeRef.current = onChange;
    }, [onChange]);

    useEffect(() => {
        disabledRef.current = disabled;
        if (markerRef.current) {
            if (disabled) {
                markerRef.current.dragging?.disable();
            } else {
                markerRef.current.dragging?.enable();
            }
        }
    }, [disabled]);

    useEffect(() => {
        const el = containerRef.current;
        if (!el || mapRef.current) {
            return;
        }

        const center = resolveCenter(lat, lng, defaultCenter);
        const map = L.map(el, {
            center: [center.lat, center.lng],
            zoom: defaultZoom,
            zoomControl: true,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19,
        }).addTo(map);

        const marker = L.marker([center.lat, center.lng], {
            draggable: !disabledRef.current,
        }).addTo(map);

        const emit = (latLng: L.LatLng) => {
            if (disabledRef.current) {
                return;
            }
            onChangeRef.current(toLatLngStrings(latLng.lat, latLng.lng));
        };

        marker.on('dragend', () => {
            emit(marker.getLatLng());
        });

        map.on('click', (e: L.LeafletMouseEvent) => {
            if (disabledRef.current) {
                return;
            }
            marker.setLatLng(e.latlng);
            emit(e.latlng);
        });

        mapRef.current = map;
        markerRef.current = marker;

        // Needed when map mounts in modal/dialog with zero initial size.
        const t1 = window.setTimeout(() => map.invalidateSize(), 0);
        const t2 = window.setTimeout(() => map.invalidateSize(), 200);

        return () => {
            window.clearTimeout(t1);
            window.clearTimeout(t2);
            map.remove();
            mapRef.current = null;
            markerRef.current = null;
        };
        // Mount once; lat/lng sync handled below.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Keep marker/view in sync when controlled lat/lng change.
    useEffect(() => {
        const map = mapRef.current;
        const marker = markerRef.current;
        if (!map || !marker) {
            return;
        }
        const center = resolveCenter(lat, lng, defaultCenter);
        const current = marker.getLatLng();
        if (current.lat === center.lat && current.lng === center.lng) {
            return;
        }
        marker.setLatLng([center.lat, center.lng]);
        map.panTo([center.lat, center.lng]);
    }, [lat, lng, defaultCenter]);

    const useMyLocation = () => {
        if (disabled || !navigator.geolocation) {
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const next = toLatLngStrings(pos.coords.latitude, pos.coords.longitude);
                onChange(next);
                const map = mapRef.current;
                const marker = markerRef.current;
                if (map && marker) {
                    const latLng: L.LatLngExpression = [pos.coords.latitude, pos.coords.longitude];
                    marker.setLatLng(latLng);
                    map.setView(latLng, Math.max(map.getZoom(), 15));
                }
            },
            () => {
                // soft-fail: permission denied / unavailable
            },
            { enableHighAccuracy: true, timeout: 10000 },
        );
    };

    return (
        <div className={className}>
            <div className="mb-2 flex items-center justify-between gap-2">
                <p className="text-xs text-muted-foreground">
                    Click the map or drag the marker to set coordinates.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled}
                    onClick={useMyLocation}
                >
                    Use my location
                </Button>
            </div>
            <div
                id={`latlng-map-${mapId}`}
                ref={containerRef}
                className={`w-full overflow-hidden rounded-md border border-border ${heightClassName} ${
                    disabled ? 'pointer-events-none opacity-60' : ''
                }`}
                aria-label="Latitude and longitude map picker"
                role="application"
            />
        </div>
    );
}

export default LatLngMapPicker;
