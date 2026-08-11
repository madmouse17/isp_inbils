import type { KeyboardEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import { Button } from './Button';
import { Input } from './Input';

export interface MapPickerProps {
    latitude: string | number | null;
    longitude: string | number | null;
    onChange: (latitude: number, longitude: number) => void;
    latitudeError?: string;
    longitudeError?: string;
    searchResults?: GeocodeResult[];
    searching?: boolean;
    onSearch?: (query: string) => void;
    onSelectSearchResult?: (result: GeocodeResult) => void;
}

export interface GeocodeResult {
    display_name: string;
    lat: string;
    lng: string;
    postal_code: string;
    province_code: string;
    city_code: string;
    district_code: string;
    village_code: string;
    city: string;
}

export function MapPicker({
    latitude,
    longitude,
    onChange,
    latitudeError,
    longitudeError,
    searchResults = [],
    searching = false,
    onSearch,
    onSelectSearchResult,
}: MapPickerProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<L.Map | null>(null);
    const markerRef = useRef<L.Marker | null>(null);
    const onChangeRef = useRef(onChange);
    const [locationError, setLocationError] = useState('');
    const [searchQuery, setSearchQuery] = useState('');
    const [hasSearched, setHasSearched] = useState(false);
    const [searchMode, setSearchMode] = useState<'address' | 'coordinates'>('address');
    const [coordinateLatitude, setCoordinateLatitude] = useState(String(latitude ?? ''));
    const [coordinateLongitude, setCoordinateLongitude] = useState(String(longitude ?? ''));
    const parsedLatitude = Number(latitude);
    const parsedLongitude = Number(longitude);
    const hasCoordinates =
        latitude !== '' &&
        latitude !== null &&
        longitude !== '' &&
        longitude !== null &&
        Number.isFinite(parsedLatitude) &&
        Number.isFinite(parsedLongitude);

    useEffect(() => {
        onChangeRef.current = onChange;
    }, [onChange]);

    useEffect(() => {
        if (!containerRef.current || mapRef.current) {
            return;
        }

        const map = L.map(containerRef.current).setView([-2.5489, 118.0149], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);
        map.on('click', ({ latlng }) => onChangeRef.current(latlng.lat, latlng.lng));
        mapRef.current = map;

        return () => {
            map.remove();
            mapRef.current = null;
            markerRef.current = null;
        };
    }, []);

    useEffect(() => {
        const map = mapRef.current;

        if (!map || !hasCoordinates) {
            return;
        }

        markerRef.current?.remove();
        markerRef.current = L.marker([parsedLatitude, parsedLongitude], {
            draggable: true,
            icon: L.divIcon({
                className: 'map-picker-marker',
                html: '<svg viewBox="0 0 24 32" aria-hidden="true"><path fill="currentColor" d="M12 0C5.37 0 0 5.37 0 12c0 9 12 20 12 20s12-11 12-20C24 5.37 18.63 0 12 0Z"/><circle cx="12" cy="12" r="5" fill="white"/></svg>',
                iconSize: [30, 40],
                iconAnchor: [15, 40],
            }),
        }).addTo(map);
        markerRef.current.on('dragend', ({ target }) => {
            const position = (target as L.Marker).getLatLng();
            onChangeRef.current(position.lat, position.lng);
        });
        map.setView([parsedLatitude, parsedLongitude], Math.max(map.getZoom(), 15));
    }, [hasCoordinates, parsedLatitude, parsedLongitude]);

    const useCurrentLocation = () => {
        setLocationError('');
        navigator.geolocation.getCurrentPosition(
            ({ coords }) => onChange(coords.latitude, coords.longitude),
            () => setLocationError('Current location could not be detected.'),
            { enableHighAccuracy: true, timeout: 10000 },
        );
    };

    const searchLocation = () => {
        if (searchQuery.trim().length < 3 || !onSearch) {
            return;
        }

        setHasSearched(true);
        onSearch(searchQuery.trim());
    };

    const handleSearchKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchLocation();
        }
    };

    const useCoordinates = () => {
        const nextLatitude = Number(coordinateLatitude);
        const nextLongitude = Number(coordinateLongitude);

        if (
            !Number.isFinite(nextLatitude) ||
            !Number.isFinite(nextLongitude) ||
            nextLatitude < -90 ||
            nextLatitude > 90 ||
            nextLongitude < -180 ||
            nextLongitude > 180
        ) {
            setLocationError('Latitude must be -90 to 90 and longitude -180 to 180.');
            return;
        }

        setLocationError('');
        onChange(nextLatitude, nextLongitude);
    };

    return (
        <div className="space-y-3 md:col-span-2">
            <div className="relative h-96 w-full overflow-hidden rounded-lg border border-input bg-muted">
                <div
                    ref={containerRef}
                    className="h-full w-full"
                    aria-label="Map for selecting customer address coordinates"
                />
                {onSearch ? (
                    <div className="absolute left-3 right-3 top-3 z-[1000] max-w-2xl space-y-2 md:right-auto md:w-[36rem]">
                        <div className="rounded-lg border border-border bg-background/95 p-2 shadow-sm backdrop-blur dark:bg-background/95">
                            <div className="mb-2 flex gap-2" aria-label="Location search mode">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={searchMode === 'address' ? 'success' : 'ghost'}
                                    onClick={() => setSearchMode('address')}
                                >
                                    Address
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={searchMode === 'coordinates' ? 'success' : 'ghost'}
                                    onClick={() => {
                                        setSearchMode('coordinates');
                                        setHasSearched(false);
                                        setCoordinateLatitude(String(latitude ?? ''));
                                        setCoordinateLongitude(String(longitude ?? ''));
                                    }}
                                >
                                    Lat / Long
                                </Button>
                            </div>
                            {searchMode === 'address' ? (
                                <div className="flex gap-2">
                                    <Input
                                        value={searchQuery}
                                        onChange={(event) => setSearchQuery(event.target.value)}
                                        onKeyDown={handleSearchKeyDown}
                                        placeholder="Search a location or address"
                                        className="flex-1"
                                        aria-label="Search a location or address"
                                    />
                                    <Button
                                        type="button"
                                        variant="success"
                                        loading={searching}
                                        onClick={searchLocation}
                                    >
                                        Search
                                    </Button>
                                </div>
                            ) : (
                                <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                                    <Input
                                        inputMode="decimal"
                                        value={coordinateLatitude}
                                        onChange={(event) => setCoordinateLatitude(event.target.value)}
                                        placeholder="Latitude"
                                        aria-label="Latitude"
                                    />
                                    <Input
                                        inputMode="decimal"
                                        value={coordinateLongitude}
                                        onChange={(event) => setCoordinateLongitude(event.target.value)}
                                        placeholder="Longitude"
                                        aria-label="Longitude"
                                    />
                                    <Button type="button" variant="success" onClick={useCoordinates}>
                                        Find
                                    </Button>
                                </div>
                            )}
                        </div>
                        {searchMode === 'address' && hasSearched && searchResults.length > 0 ? (
                            <div
                                className="max-h-56 overflow-y-auto rounded-lg border border-border bg-background/95 p-1 shadow-sm backdrop-blur dark:bg-background/95"
                                aria-label="Location search results"
                            >
                                {searchResults.map((result) => (
                                    <Button
                                        key={`${result.lat}-${result.lng}`}
                                        type="button"
                                        variant="ghost"
                                        className="h-auto w-full justify-start whitespace-normal text-left"
                                        onClick={() => {
                                            setSearchQuery(result.display_name);
                                            setHasSearched(false);
                                            onSelectSearchResult?.(result);
                                        }}
                                    >
                                        {result.display_name}
                                    </Button>
                                ))}
                            </div>
                        ) : searchMode === 'address' && hasSearched && !searching ? (
                            <div className="rounded-lg border border-border bg-background/95 p-3 text-sm text-muted-foreground shadow-sm">
                                Location not found. Try a more specific address.
                            </div>
                        ) : null}
                    </div>
                ) : null}
                <div className="absolute bottom-3 right-3 z-[1000]">
                    <Button type="button" variant="success" size="sm" onClick={useCurrentLocation}>
                        Current Location
                    </Button>
                </div>
            </div>
            <p className="text-sm text-muted-foreground">
                Click the map, drag the red pin, search an address, or enter coordinates.
            </p>
            <div className="grid gap-4 md:grid-cols-2">
                <Input label="Latitude" value={latitude ?? ''} readOnly error={latitudeError} required />
                <Input label="Longitude" value={longitude ?? ''} readOnly error={longitudeError} required />
            </div>
            {locationError ? <p className="text-sm text-destructive">{locationError}</p> : null}
        </div>
    );
}
