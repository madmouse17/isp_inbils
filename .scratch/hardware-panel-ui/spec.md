# Hardware panel UI: devices drawn like the physical unit (ports, slots, links)

Status: ready-for-agent
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `hardware-panel-ui`
Source: owner 2026-10-17: "improve untuk ui nas/router/mikrotik atau hardware lainnya buat lebih bagus, kalau bisa menyerupai hardwarenya kalau ada colokan atau port-portnya, biar lebih bagus informatif dan user jaringan gampang memahami jadi UI bisa dibuat seperti fisiknya kalau bisa".

## What exists

- `network_assets.asset_type` ∈ router, switch, olt, onu_ont, radio, antenna, fiber, odp, odc, rack, power, other (`StoreNetworkAssetRequest.php:35`). No port table for routers/switches/ONT; only ODN has ports: `odn_ports` (kind `pon` on an OLT, `splitter_out` on ODC/ODP, `subscription_id` per drop) and `odn_splitters` (`Modules/NetworkAsset/database/migrations/2026_10_13_10000{4,5}`).
- Pages: `Pages/Admin/NetworkAssets/{Index,Show,Trace}.tsx`, `NasDevices/Show.tsx` (RADIUS: online sessions, expected vs written state), `Odn/{Index,Asset}.tsx` (list of splitters/ports as tables).
- Live data today comes only from RADIUS (`radacct` sessions per PPPoE user, usage), never per physical port. No SNMP, no RouterOS API.

## Owner decisions (recommended, accepted by the standing "ikuti saran" instruction; revisit if the owner objects)

1. Scope phase 1 = a **front-panel view** for every asset type that has ports: OLT (PON + uplink), ODC/ODP (splitter outputs), router/NAS (ETH/SFP/SFP+), switch, ONT (LAN/PON/POTS/WLAN), rack (U slots with the assets inside). Drawn as an SVG panel from a **port template** (`ports` JSON on the asset: groups of ports with kind, count, label pattern, optional speed), chosen from a small built-in catalogue of common units (MikroTik hAP/RB/CCR/CRS families, ZTE/Huawei/Fiberhome OLT 8/16 PON, generic 5/8/16/24/48-port, generic ONT) or entered as "N ports of kind X". Templates are data, not code, so new models need no deploy.
2. A **port record per physical port** (`asset_ports`: asset_id, group, number, kind, label, admin_state, linked_port_id, subscription_id, vlan/note) created from the template; ODN keeps `odn_ports` as the source of truth for PON/splitter outputs and the panel reads both (no duplication: an OLT PON port is an `odn_ports` row, an uplink is an `asset_ports` row).
3. Port state shown from data we have: **occupied/free** (link or drop assigned), **customer online/offline** on PON/splitter-out via the RADIUS session of the drop's subscription, **isolir** colour, **admin down** flag. No fake "link up" LEDs: live link state comes only in phase 2 (SNMP/RouterOS API, separate lane, needs credentials per device).
4. Interactions: hover/tap a port = tooltip (label, what is connected, customer, online since, speed profile); click = side panel with actions that already exist (assign drop, release, trace, open customer/subscription, block/unblock); patch two ports = drag or two-click "Hubungkan" creating a link (both directions, same company, restrict on delete). Keyboard reachable (ports are buttons in a roving tabindex grid), 44 px targets on phones, colours from the `--status-*` tokens with text labels (not colour only).
5. Rack view: U-slots with the mounted assets as labelled bars; drag to reorder is out of scope; `rack_unit` + `height_u` columns on the asset.
6. Trace: the existing `Trace.tsx` path (OLT → splitter → ODP → customer) becomes a horizontal chain of mini-panels with the lit path, replacing the text list.

## Non-goals (phase 1)

SNMP/RouterOS polling, traffic graphs per port, Mikrotik configuration push, auto-discovery of ports from the device.

## Tickets

01-design (architect) · then slices per the design document.
