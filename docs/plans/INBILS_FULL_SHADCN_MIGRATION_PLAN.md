# INBILS Full shadcn Migration Plan

Depends on:  
- `docs/plans/INBILS_FULL_SHADCN_AUDIT.md`  
- `docs/plans/INBILS_FULL_SHADCN_COMPONENT_MATRIX.md`  
- `docs/plans/INBILS_FULL_SHADCN_ROLLBACK.md`

## 0. Goals / non-goals

**Goals**

1. Core primitives = official `new-york` registry via CLI  
2. App composites stay app-owned under `composite/` or feature folders  
3. Green `typecheck` + `build`; lint clean or only pre-agreed exceptions  
4. Preserve Inertia, dark class, tenant/RBAC behavior, capital-C paths  

**Non-goals**

- Tailwind v4  
- Full visual redesign  
- Rewriting business pages beyond import/API adapts  
- Commit/push from agents without human  

## 1. Preconditions (Batch 0 gate)

Kanban child: `t_e17c7b80` — Batch 0 foundation (ui-engineer).

### Tasks

1. Confirm `components.json` (already new-york / capital aliases). Do not flip to lowercase `components` without full import rewrite.  
2. Ensure deps install via CLI (`class-variance-authority`, radix packages, `lucide-react`, toast stack as registry dictates). Declare in package.json.  
3. Keep `resources/js/lib/utils.ts` `cn()`.  
4. Align CSS variables in `resources/css/app.css` with registry tokens without breaking existing semantic colors.  
5. Fix baseline blockers **before** mass swaps:  
   - `ui/index.ts` Toast/Label/Select/Spinner export mismatches  
   - `FileUpload.tsx` FilePond prop types  
6. Optional: fix `app.tsx` lint (2 errors) so lint gate usable.  
7. Add only foundation registry components needed by later batches (button, input, label, card, separator as minimum).  
8. Run gates: `npm run typecheck`, `npm run lint`, `npm run build`.

### Batch 0 acceptance

- [ ] typecheck exit 0  
- [ ] build exit 0  
- [ ] lint exit 0 or documented residual only in non-UI files with owner  
- [ ] `components.json` unchanged in path aliases unless approved  
- [ ] No page behavior change required  
- [ ] Unrelated WIP preserved  
- [ ] Touched files list recorded on card  

### Batch 0 rollback

Restore listed files from git; `npm install` if lockfile changed. See rollback doc.

## 2. Serial batches (smallest safe slices)

Do not parallelize batches that edit same `ui/*` or barrel.

### Batch 1 — Low-risk primitives (L)

**Add:** `skeleton`, `separator`, `avatar`, `alert`, `tooltip`, `badge`  
**Paths:** corresponding `resources/js/Components/ui/*`  
**Call sites:** low usage except Badge (37) — Badge needs visual QA  
**Acceptance:** typecheck/build green; Badge statuses match previous colors on Admin list pages; dark mode spot-check.

### Batch 2 — Form core (H)

**Add:** `button`, `input`, `label`, `textarea`, `checkbox`, `radio-group`, `switch`  
**Also migrate wrappers:** Primary/Secondary/DangerButton, TextInput, InputLabel, root Checkbox → thin shims or delete.  
**Acceptance:** Login + one admin create form; keyboard tab order; disabled/loading Button still works; no RBAC button removal.

### Batch 3 — Select family (H)

**Add:** `select`  
**Migrate:** `ui/Select.tsx` (33 usages)  
**Strategy:** keep exported `Select` name; map options API with temporary adapter if needed.  
**Acceptance:** product/customer forms select fields; empty/disabled/error states.

### Batch 4 — Overlay core (H)

**Add:** `dialog`, `dropdown-menu`, `toast`  
**Migrate:** `ui/Modal.tsx` (17), `ui/Dropdown.tsx` (2), `ui/Toast.tsx` (5)  
**Strategy:**  
- Modal = thin app wrapper over Dialog  
- Toast = adopt registry toast + preserve `useToast()` facade one batch  
**Acceptance:** open/close focus trap; Esc; stacked toasts; no double providers in AdminLayout.

### Batch 5 — Data display (H)

**Add:** `table`, `pagination`, `tabs`, `card` (if not already)  
**Migrate:** `ui/Table.tsx` (41), `ui/Pagination.tsx` (25), `ui/Tabs.tsx`, Card aliases  
**Keep:** `TBody`/`TD`/… re-exports as deprecated aliases until codemod.  
**Pagination:** map Laravel `links` array → registry Pagination items; Inertia `router.get`.  
**Acceptance:** customers/products index tables; page change keeps query string; empty/loading rows.

### Batch 6 — SearchSelect rebuild (H)

**Add:** `popover`, `command`  
**Migrate:** `ui/SearchSelect.tsx` (7, 282 lines)  
**Do not** invent registry name `search-select`.  
**Acceptance:** async/local search, keyboard, clear, form binding on 2 real pages.

### Batch 7 — Shell (H)

**Add:** `sidebar`, `breadcrumb`  
**Migrate:** `ui/Sidebar.tsx`, `ui/Topbar.tsx`, `ui/Breadcrumb.tsx`, AdminLayout glue  
**Acceptance:** collapse/expand, active route, mobile drawer, permission-hidden items unchanged.

### Batch 8 — Composites + cleanup (M)

- Point DataTable/FilterBar/PageHeader/StatusBadge/StatCard/EmptyState at new primitives  
- Remove raw `<button` in DataTable + AuthenticatedLayout  
- Delete Divider if still unused  
- Remove dead legacy wrappers  
- Icon pass: heroicons → lucide where touched  
- FileUpload stays custom; only type/style align  

### Batch 9 — Hardening

- Docs: update `docs/ui/components.md`, `docs/ui/design-system.md`  
- E2E smoke `@smoke`  
- Final matrix check: no hand-written core primitive left masquerading as registry  

## 3. Per-batch work recipe (implementers)

1. Read matrix row + this batch scope  
2. `npx shadcn@latest add <names>` only  
3. Adapt app wrapper if API must stay stable  
4. Update barrel `ui/index.ts` to real exports  
5. Migrate call sites in batch scope only  
6. `npm run typecheck && npm run lint && npm run build`  
7. Manual dark + one mobile width check  
8. Record files + residual risks on Kanban card  
9. Stop; do not start next batch if gates red  

## 4. Path & import conventions

- Prefer `@/Components/ui` barrel or direct `@/Components/ui/<Name>` consistent with existing pages  
- Composites: `@/Components/composite`  
- Do not introduce parallel `resources/js/components/ui` lowercase tree  
- If CLI emits lowercase path, move/fix config immediately — single tree only  

## 5. Testing requirements

| Level | When |
|---|---|
| typecheck | every batch |
| lint | every batch |
| build | every batch |
| Manual form/dialog | batches 2–4 |
| Index table/pagination | batch 5 |
| Admin shell nav | batch 7 |
| `npm run test:e2e:smoke` | after batch 7 or 8 |

## 6. Recommended assignees

| Batch | Assignee profile |
|---|---|
| 0 foundation | ui-engineer (`t_e17c7b80`) |
| 1–5 primitives | ui-engineer |
| 6 SearchSelect | ui-engineer (+ optional reviewer) |
| 7 shell | ui-engineer |
| 8 composites | ui-engineer |
| 9 docs/e2e | docs + qa |
| Review gates | Hermes reviewer / architecture-review |

## 7. Stop conditions

- Gate red and not fixed in same batch  
- Need guessed registry component → block for human  
- CLI wants Tailwind v4-only path → block; stay v3  
- Tenant/RBAC regression → rollback batch  

## 8. Success definition (program)

- Core kit = registry-generated files + thin documented facades only  
- Custom = FileUpload, SearchSelect (if still domain), StatCard, EmptyState, shell composites, domain filters/tables  
- Baseline gates green  
- Matrix updated to final state  
- Rollback doc still valid  
