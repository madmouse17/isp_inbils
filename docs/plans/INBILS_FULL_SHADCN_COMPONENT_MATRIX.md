# INBILS Full shadcn Component Matrix

Source inventory: `.hermes-evidence/shadcn-audit/inventory.json` (31 ui files, 163 sources).  
Registry style: `new-york`. CLI form: `npx shadcn@latest add <name>`.  
Docs base: https://ui.shadcn.com/docs/components  

Legend  
- **Class A** = replace with official registry primitive(s)  
- **Class B** = keep app-facing API; rebuild on registry primitives  
- **Class C** = keep custom / composite (no forced registry swap)  
- **Class D** = obsolete / delete / collapse  
- **Risk** H/M/L  

## 1. `resources/js/Components/ui/*`

| Current path | Usage | Lines | Class | Official registry | Exact CLI | Notes / risks |
|---|---:|---:|---|---|---|---|
| `ui/Alert.tsx` | 1 | 79 | A | `alert` | `npx shadcn@latest add alert` | heroicons → lucide; low usage |
| `ui/Avatar.tsx` | 1 | 86 | A | `avatar` | `npx shadcn@latest add avatar` | custom image/fallback; low usage |
| `ui/Badge.tsx` | 37 | 59 | A | `badge` | `npx shadcn@latest add badge` | CVA variants; map status colors carefully (**H** visual) |
| `ui/Breadcrumb.tsx` | 2 | 57 | A/B | `breadcrumb` | `npx shadcn@latest add breadcrumb` | Inertia `Link` must remain (**M**) |
| `ui/Button.tsx` | 79 | 87 | A | `button` | `npx shadcn@latest add button` | Highest fan-out; Slot+CVA already; keep variants (**H**) |
| `ui/Card.tsx` | 78 | 82 | A | `card` | `npx shadcn@latest add card` | exports CardBody alias — keep shim if needed (**M**) |
| `ui/Checkbox.tsx` | 7 | 50 | A | `checkbox` | `npx shadcn@latest add checkbox` | also root `Components/Checkbox.tsx` wrapper (**M**) |
| `ui/Divider.tsx` | 0 | 22 | D | `separator` | `npx shadcn@latest add separator` | unused; delete or alias to Separator |
| `ui/Dropdown.tsx` | 2 | 110 | A | `dropdown-menu` | `npx shadcn@latest add dropdown-menu` | already radix; align API to registry (**M**) |
| `ui/EmptyState.tsx` | 1 | 39 | C/B | none exact as `empty` on dry-run | — | Keep composite; optional later empty pattern |
| `ui/FileUpload.tsx` | 4 | 145 | C | no FilePond equivalent | — | Keep domain composite; fix types (**H** typecheck) |
| `ui/IconButton.tsx` | 1 | 43 | B | `button` size/icon variant | (via button) | Collapse into Button `size="icon"` |
| `ui/Input.tsx` | 66 | 61 | A | `input` | `npx shadcn@latest add input` | High fan-out; wrapper TextInput exists (**H**) |
| `ui/Label.tsx` | 4 | 30 | A | `label` | `npx shadcn@latest add label` | Barrel exports missing `LabelProps` (**M** baseline) |
| `ui/Modal.tsx` | 17 | 104 | A/B | `dialog` | `npx shadcn@latest add dialog` | App Modal API vs Dialog primitives; keep wrapper (**H**) |
| `ui/Pagination.tsx` | 25 | 98 | B | `pagination` | `npx shadcn@latest add pagination` | Laravel/Inertia page links shape (**H**) |
| `ui/RadioGroup.tsx` | 1 | 80 | A | `radio-group` | `npx shadcn@latest add radio-group` | custom; low usage |
| `ui/SearchSelect.tsx` | 7 | 282 | B/C | combobox pattern via `popover`+`command` | `npx shadcn@latest add popover command` | Not a single registry name; rebuild carefully (**H**) |
| `ui/Select.tsx` | 33 | 82 | A | `select` | `npx shadcn@latest add select` | Currently native-ish; API break risk (**H**) |
| `ui/Sidebar.tsx` | 1 | 185 | B | `sidebar` | `npx shadcn@latest add sidebar` | Admin shell; large surface (**H**) |
| `ui/Skeleton.tsx` | 1 | 16 | A | `skeleton` | `npx shadcn@latest add skeleton` | trivial |
| `ui/Spinner.tsx` | 2 | 44 | C/B | no dry-run `spinner` | — | Keep or CSS spinner on Button loading; barrel SpinnerProps missing |
| `ui/StatCard.tsx` | 2 | 84 | C | none | — | App composite of Card+icons |
| `ui/Switch.tsx` | 22 | 48 | A | `switch` | `npx shadcn@latest add switch` | form density (**M**) |
| `ui/Table.tsx` | 41 | 137 | A/B | `table` | `npx shadcn@latest add table` | App aliases TBody/TD/TH… keep compat exports (**H**) |
| `ui/Tabs.tsx` | 2 | 86 | A | `tabs` | `npx shadcn@latest add tabs` | already radix |
| `ui/Textarea.tsx` | 22 | 53 | A | `textarea` | `npx shadcn@latest add textarea` | medium fan-out |
| `ui/Toast.tsx` | 5 | 134 | A/B | `toast` | `npx shadcn@latest add toast` | Custom provider API vs registry/sonner; barrel already broken (**H**) |
| `ui/Tooltip.tsx` | 1 | 48 | A | `tooltip` | `npx shadcn@latest add tooltip` | already radix |
| `ui/Topbar.tsx` | 1 | 36 | C | none | — | Layout chrome composite |
| `ui/index.ts` | — | 129 | B | n/a | n/a | Rebuild after each batch; currently type-broken |

## 2. Composites (`resources/js/Components/composite`)

| Path | Class | Registry deps | Action |
|---|---|---|---|
| `CustomerRelatedTables.tsx` | C | table, badge, button | Keep domain; swap primitives only |
| `DataTable.tsx` | C | table, button, checkbox, dropdown-menu | Keep; swap internal primitives only |
| `DateRangeFilter.tsx` | C | button, input, popover (later) | Keep domain |
| `FormField.tsx` | C | label, input, textarea | Keep form helper; use registry field bits |
| `MoneyInput.tsx` | C | input | Keep domain currency input |
| `PageHeader.tsx` | C | breadcrumb, button | Keep |
| `StatusBadge.tsx` | B | badge | Map to Badge variants |
| `composite/index.ts` | — | — | Re-export only |

## 3. Legacy / Breeze-ish wrappers (`resources/js/Components/*`)

| Path | Class | Action |
|---|---|---|
| `PrimaryButton.tsx` | D/B | Thin wrapper → `Button` variant; delete after call-site purge |
| `SecondaryButton.tsx` | D/B | same |
| `DangerButton.tsx` | D/B | same |
| `TextInput.tsx` | D/B | wrapper → `Input` |
| `InputLabel.tsx` | D/B | wrapper → `Label` |
| `Checkbox.tsx` | D/B | wrapper → ui Checkbox |
| `Dropdown.tsx` | D | collapse to ui dropdown-menu |
| `Modal.tsx` | D | collapse to ui Dialog wrapper |
| `NavLink.tsx` / `ResponsiveNavLink.tsx` | C | layout nav; not registry core |
| `ApplicationLogo.tsx` | C | brand |

Direct import sites still bypass barrel (from inventory):  
Login + wrappers import Button/Card/Checkbox/Input/Label paths directly.

## 4. Manual / raw controls to absorb

| Path | Line | Control | Target |
|---|---:|---|---|
| `composite/DataTable.tsx` | 75 | `<button` | `Button` |
| `Layouts/AuthenticatedLayout.tsx` | 44, 83 | `<button` | `Button` / IconButton |

## 5. Official CLI batch bundles (verified names only)

```bash
# Foundation / forms / feedback (Batch 0–2)
npx shadcn@latest add button input label textarea checkbox radio-group switch select separator

# Surfaces
npx shadcn@latest add card badge alert avatar skeleton table tabs tooltip

# Overlays
npx shadcn@latest add dialog dropdown-menu toast popover

# Navigation / chrome
npx shadcn@latest add breadcrumb pagination sidebar

# SearchSelect rebuild ingredients
npx shadcn@latest add command
```

Do **not** add guessed names (`empty`, `spinner`, `attachment`) until CLI+docs prove install path on this Tailwind v3 project.

## 6. Compatibility risk matrix

| Risk | Components | Mitigation |
|---|---|---|
| Prop API break | Select, Modal, Toast, Pagination, Table aliases | Compatibility wrappers in `ui/*` one release; codemod later |
| Visual drift | Badge, Button, Card, Table | Side-by-side Admin/Components page + dark mode check |
| Inertia nav | Breadcrumb, Dropdown, Sidebar, Pagination | Keep Link/`router` usage tests |
| Focus/a11y | Modal→Dialog, Dropdown, Sidebar | Prefer radix; manual keyboard pass |
| Icons | All heroicons usages | Batch icon swap lucide OR temporary dual |
| Types | FileUpload, barrel | Fix in Batch 0 before mass add |
| Tenant/RBAC UI | Buttons on admin pages | No permission UI removal; QA smoke `@smoke` |

## 7. Delete / obsolete candidates

1. `ui/Divider.tsx` if unused after Separator add  
2. Duplicate root wrappers once call sites moved  
3. Dead barrel exports that never existed on Toast module  

## 8. Acceptance for matrix completeness

- [x] Every file under `resources/js/Components/ui` listed  
- [x] Composites listed  
- [x] Legacy wrappers listed  
- [x] Official CLI names only when dry-run/docs supported  
- [x] Custom justified when no registry equivalent  
- [x] Risks + usage counts attached  
