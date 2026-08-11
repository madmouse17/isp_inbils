# PLAN: Location lat/lng map picker (Leaflet + OpenStreetMap)

**Kanban:** `t_69586e5c`  
**Repo:** `C:/Users/MadMouse/Documents/Web/inbils` (branch `main` @ `c8af154`)  
**Stack:** Laravel 12 + Inertia 2 + React 19 + TS + Tailwind 4 + Vite  
**Date:** 2026-07-24  
**Planner profile:** planner (mantap)

---

## 1. Scope

### In scope
1. Reusable **map picker UI** for picking a point on an OpenStreetMap basemap (Leaflet).
2. Syncs **lat** + **lng** with existing form fields (controlled component).
3. First integration: **Customer Address** create/edit modal  
   `resources/js/Pages/Admin/CustomerAddresses/Index.tsx`.
4. Keep manual lat/lng inputs (map is an aid, not the only path).
5. Optional: browser **Geolocation** button ("Use my location") — no third-party geocoding.
6. Dark-mode safe map container (tiles stay OSM standard; chrome uses app tokens).
7. Docs note for reuse on future ODC/ODP forms (network assets already document lat/lng in `docs/business/network.md` but are **not** on `main` yet).

### Out of scope
- Geocoding / reverse geocoding (Nominatim, Google, Mapbox) — add later if needed.
- Multi-marker / polygon / cable route drawing.
- Backend schema changes (already `decimal(10,7)` nullable on `customer_addresses`).
- New FormRequest rules beyond existing `nullable|numeric` (optional tighten only).
- Network ODC/ODP/OLT pages (not present on `main`; reuse component when those land).
- Offline tile cache, self-hosted tiles, API keys, paid map providers.
- Full-page network topology map.

### Assumptions
- Working tree may be incomplete on disk; implementers checkout full tree from git (`main`).
- Inertia pages are client-rendered; Leaflet must still not run at module top-level in a way that breaks Vite SSR if enabled later — guard with `useEffect` + `typeof window`.
- Default map center: **Indonesia-ish** `[-6.2000000, 106.8166667]` (Jakarta) zoom 12 when lat/lng empty; override via props.
- OSM tile usage policy: reasonable traffic for internal admin tool; attribution required.
- No CSP currently blocking `*.tile.openstreetmap.org` (verify in deploy; document if CSP added).

### Dependencies
| Dep | Why | Notes |
|-----|-----|-------|
| `leaflet` | Map engine | Imperative API; stable |
| `@types/leaflet` | TS | devDependency |
| Existing UI | `Input`, `Button`, `Label`, `Modal` under `resources/js/Components/ui` | PascalCase filenames |
| Existing backend | `lat`/`lng` fillable, casts `decimal:7`, FormRequests | **No PHP changes required** for MVP |

**Package choice (lazy / YAGNI):** plain **`leaflet` only** — do **not** add `react-leaflet` (React 19 peer friction, extra abstraction for one marker + click). One `useEffect` + refs is enough.

---

## 2. Current state (evidence)

| Layer | Finding |
|-------|---------|
| DB | `customer_addresses.lat/lng` `decimal(10,7)` nullable — migration `2026_06_30_220001_create_customer_addresses_table.php` |
| Model | `CustomerAddress` fillable + casts `decimal:7` |
| Validation | `Store/UpdateCustomerAddressRequest`: `lat`/`lng` => `nullable\|numeric` |
| UI | Modal form: two plain `Input` fields for lat/lng only — no map |
| Types | `CustomerAddress.lat/lng: string \| null` in `@/types/models` |
| package.json | No leaflet/mapbox/google maps |
| Network docs | ODC/ODP lat/lng planned; models not on `main` |

---

## 3. Architecture

```
[User clicks map / drags marker / types lat-lng / geolocation]
                    |
                    v
        LatLngMapPicker (client-only, useEffect)
          - Leaflet map + OSM tiles
          - single draggable marker
          - onChange({ lat, lng })
                    |
                    v
   CustomerAddresses Index useForm({ lat, lng, ... })
                    |
                    v
   POST/PUT Admin CustomerAddress (existing FormRequest)
                    |
                    v
   customer_addresses.lat / .lng
```

### Component API (proposed)

`resources/js/Components/composite/LatLngMapPicker.tsx`

```ts
export type LatLngValue = {
  lat: string; // keep string to match useForm fields
  lng: string;
};

type Props = {
  lat: string;
  lng: string;
  onChange: (next: LatLngValue) => void;
  /** default [-6.2, 106.8166667] */
  defaultCenter?: [number, number];
  defaultZoom?: number; // 12
  heightClassName?: string; // default h-64
  disabled?: boolean;
  className?: string;
};
```

Behaviour:
1. Parse `lat`/`lng` with `Number`; if both finite → center + marker there; else defaultCenter, no marker until click (or show marker only after first pick).
2. Map click → set marker + `onChange` with fixed 7 decimal places (`toFixed(7)`).
3. Marker dragend → same.
4. When parent `lat`/`lng` change from inputs → move marker / pan if values parse.
5. Button "Use my location" → `navigator.geolocation.getCurrentPosition` → onChange; fail soft (toast or inline error text).
6. On mount inside Modal: `map.invalidateSize()` after short timeout / `requestAnimationFrame` (Modal open often has 0 height first frame).
7. Cleanup: `map.remove()` on unmount.
8. Import CSS: `import 'leaflet/dist/leaflet.css'` inside component file (Vite handles).
9. Fix default marker icon paths under Vite (known leaflet + bundler issue) via explicit `Icon.Default.mergeOptions` with imported marker PNGs **or** a tiny divIcon / circleMarker to avoid asset path bugs. Prefer **circleMarker** or custom `divIcon` — fewer assets, no broken pin images. (Decision: **draggable CircleMarker is wrong for drag** — use default marker with Vite-fixed URLs from `leaflet/dist/images/*` OR `L.marker` + icon fix. Implementer: use standard leaflet icon URL fix snippet.)

### Form integration (CustomerAddresses)

Replace standalone lat/lng inputs section with:
- `LatLngMapPicker lat={data.lat} lng={data.lng} onChange={({lat,lng}) => { setData('lat', lat); setData('lng', lng); }}`
- Keep two number inputs below/beside map bound to same `data.lat` / `data.lng` for power users / accessibility.
- `errors.lat` / `errors.lng` still show under inputs.

### Validation (optional small tighten — only if cheap)

Existing `nullable|numeric` accepts any number. Optional:

```php
'lat' => ['nullable', 'numeric', 'between:-90,90'],
'lng' => ['nullable', 'numeric', 'between:-180,180'],
```

Same on Store + Update. Not required for map picker to work; recommended.

### Multi-tenant
No map-specific tenant logic. Addresses already `BelongsToCompany`. Map is pure UI.

### Security / privacy
- Geolocation only on user gesture.
- No external geocoder → no address string leaves browser except existing form POST to app.
- OSM tiles: IP visible to OSM tile CDN (standard).

---

## 4. Files to touch

| Path | Action |
|------|--------|
| `package.json` / lockfile | Add `leaflet`, `@types/leaflet` |
| `resources/js/Components/composite/LatLngMapPicker.tsx` | **Create** |
| `resources/js/Components/composite/index.ts` | Export picker |
| `resources/js/Pages/Admin/CustomerAddresses/Index.tsx` | Wire picker into modal |
| `app/Http/Requests/Admin/StoreCustomerAddressRequest.php` | Optional between rules |
| `app/Http/Requests/Admin/UpdateCustomerAddressRequest.php` | Optional between rules |
| `docs/plans/2026-07-24-location-lat-lng-map-picker.md` | This plan (copy into repo) |

**Do not touch:** migrations, models, controllers, API resources (already expose lat/lng).

---

## 5. Task sequence (implementation)

### Task A — deps + component (ui-engineer)
1. `npm install leaflet` + `npm install -D @types/leaflet`
2. Create `LatLngMapPicker.tsx` with API above; client-only init; OSM tiles + attribution; marker; click/drag; geolocation button; invalidateSize.
3. Export from composite barrel.
4. Smoke: Story not required; render temporarily in page or unit-free manual check.

### Task B — wire CustomerAddresses (ui-engineer, after A)
1. Import picker into `CustomerAddresses/Index.tsx`.
2. Place above lat/lng inputs inside create/edit Modal.
3. Bidirectional sync with `useForm` `setData`.
4. Ensure modal open path calls invalidateSize (picker internal).
5. Manual QA on create + edit + clear + invalid type-in.

### Task C — optional validation tighten (backend / ui-engineer)
1. Add `between:-90,90` / `between:-180,180` on Store+Update address requests.
2. Feature test assert 422 on lat=999.

### Task D — verify (tester)
1. Manual: pick point → lat/lng filled → save → reload show values.
2. Edit existing address with coords → marker at point.
3. Empty coords → default center, no crash.
4. Drag marker updates fields.
5. Type fields updates marker.
6. Modal open map not grey (invalidateSize).
7. Dark mode: controls readable.
8. Mobile width: map usable, touch drag works.
9. Optional: geolocation accept/deny.
10. `npm run build` succeeds; no SSR/window errors in console.

### Task E — review (reviewer)
1. Diff size, no business logic in React beyond UI state.
2. No new paid deps / API keys.
3. Cleanup on unmount; no leaflet global leaks.
4. Attribution present.

---

## 6. Acceptance criteria

1. **AC1** Create address: click map sets lat/lng inputs to 7dp numbers within valid ranges; submit persists to DB.
2. **AC2** Edit address with existing lat/lng: map opens centered on marker at those coords.
3. **AC3** Drag marker updates both inputs without submit.
4. **AC4** Typing valid lat/lng moves marker (debounce optional ≤300ms; or on blur).
5. **AC5** Clearing both fields removes marker (or leaves default center); submit stores nulls.
6. **AC6** Leaflet CSS loaded; tiles visible; © OpenStreetMap attribution visible.
7. **AC7** Opening modal does not show grey/blank map after 300ms.
8. **AC8** No runtime error when `window` map APIs missing during Vite build.
9. **AC9** Disabled/read-only mode (if prop set) blocks map interaction (for future Show views).
10. **AC10** `npm run build` exit 0.

---

## 7. Validation requirements

| Check | How |
|-------|-----|
| Typecheck | `npm run types` or project equivalent if present |
| Build | `npm run build` |
| Manual map | Create/edit address flows above |
| Backend optional | `php artisan test --filter=Customer` if between-rules added |
| Regression | Address CRUD without touching map still works |

---

## 8. Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Leaflet default icon 404 under Vite | Broken pin | Icon URL mergeOptions **or** custom icon |
| Map grey inside Modal | UX fail | `invalidateSize` on open + ResizeObserver |
| React 19 + react-leaflet mismatch | Install fail | **Avoid react-leaflet**; use leaflet only |
| OSM tile rate / policy | Tiles blocked | Admin-only low volume; attribution; later self-host if needed |
| Geolocation denied / insecure context | Button fails | Soft error; map still works |
| Decimal string vs number drift | Validation noise | Always `toFixed(7)` string into form |
| Modal unmount race | Memory leak | `map.remove()` in effect cleanup |
| CSP blocks tiles (future) | Blank map | Document tile host allowlist |
| Working tree incomplete on agent host | Can't implement | Full `git checkout` / clean worktree from remote |

---

## 9. Rollback

1. Revert form wiring in `CustomerAddresses/Index.tsx`.
2. Delete `LatLngMapPicker.tsx` + barrel export.
3. `npm uninstall leaflet @types/leaflet`.
4. Revert optional FormRequest between rules.
5. No DB rollback needed.

---

## 10. Recommended assignees (kanban fan-out)

| Card | Assignee profile | Depends on |
|------|------------------|------------|
| IMPL: LatLngMapPicker component + leaflet dep | `ui-engineer` | this plan |
| IMPL: Wire picker into CustomerAddresses modal | `ui-engineer` | component card |
| TEST: Map picker manual + build gate | `tester` | wire card |
| REVIEW: Map picker PR | `reviewer` | wire card (can parallel test) |

---

## 11. Implementation sketch (not production — guidance)

```tsx
// LatLngMapPicker.tsx — sketch
import { useEffect, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
// fix default icon URLs for Vite...

export function LatLngMapPicker({ lat, lng, onChange, defaultCenter = [-6.2, 106.8166667], defaultZoom = 12, heightClassName = 'h-64', disabled }: Props) {
  const containerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<L.Map | null>(null);
  const markerRef = useRef<L.Marker | null>(null);

  useEffect(() => {
    if (!containerRef.current || mapRef.current) return;
    const map = L.map(containerRef.current).setView(defaultCenter, defaultZoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap',
      maxZoom: 19,
    }).addTo(map);
    map.on('click', (e) => {
      if (disabled) return;
      const { lat: la, lng: ln } = e.latlng;
      onChange({ lat: la.toFixed(7), lng: ln.toFixed(7) });
    });
    mapRef.current = map;
    requestAnimationFrame(() => map.invalidateSize());
    return () => { map.remove(); mapRef.current = null; markerRef.current = null; };
  }, []);

  useEffect(() => {
    const map = mapRef.current;
    if (!map) return;
    const la = Number(lat), ln = Number(lng);
    if (!Number.isFinite(la) || !Number.isFinite(ln)) {
      markerRef.current?.remove();
      markerRef.current = null;
      return;
    }
    if (!markerRef.current) {
      markerRef.current = L.marker([la, ln], { draggable: !disabled }).addTo(map);
      markerRef.current.on('dragend', () => {
        const p = markerRef.current!.getLatLng();
        onChange({ lat: p.lat.toFixed(7), lng: p.lng.toFixed(7) });
      });
    } else {
      markerRef.current.setLatLng([la, ln]);
    }
    map.panTo([la, ln]);
  }, [lat, lng, disabled]);

  return (
    <div className="space-y-2">
      <div ref={containerRef} className={heightClassName + ' w-full rounded-md border border-border z-0'} />
      {/* geolocation button */}
    </div>
  );
}
```

Wire:

```tsx
<LatLngMapPicker
  lat={data.lat}
  lng={data.lng}
  onChange={({ lat, lng }) => {
    setData('lat', lat);
    setData('lng', lng);
  }}
/>
```

---

## 12. ponytail (deliberate ceilings)

- `ponytail: no geocoding` — add Nominatim (with debounce + ToS UA) when users ask "search address".
- `ponytail: customer addresses only` — drop same component on ODC/ODP forms when network CRUD merges to main.
- `ponytail: no react-leaflet` — adopt if multi-layer declarative maps appear.
- `ponytail: no between validation` — add when bad coords appear in prod data.

---

## 13. Parent planner handoff summary

**Scope:** Leaflet/OSM map picker component + wire to Customer Address modal; backend already ready.  
**Out:** Geocoding, network assets pages, schema changes, paid maps.  
**Deps:** `leaflet` + types only.  
**Sequence:** component → wire → optional validation → test → review.  
**AC:** click/drag/type sync, persist, modal size fix, build green.  
**Risks:** Vite icon paths, modal invalidateSize, avoid react-leaflet.  
**Rollback:** uninstall dep + delete component + revert form.  
**Assignees:** `ui-engineer` ×2 sequential, then `tester` + `reviewer`.
