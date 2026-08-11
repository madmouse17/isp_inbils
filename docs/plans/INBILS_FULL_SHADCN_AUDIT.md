# INBILS Full shadcn Registry Migration — Audit

Date: 2026-07-22  
Project: `C:/Users/MadMouse/Documents/Web/inbils`  
Scope: read-only audit + approval plan. No production component rewrites in this phase.  
Stack: Laravel 12 + Inertia React TS + Tailwind v3 + dark `class` + multi-tenant/RBAC.

## 1. Decision (locked)

- Core UI must follow official shadcn docs: https://ui.shadcn.com/docs
- If registry equivalent exists → `npx shadcn@latest add <component>` + compose. Do not hand-write replacement core primitive.
- Custom code only when registry has no equivalent → documented app composite/feature, not fake registry core.
- Preserve Laravel/Inertia/Tailwind v3/dark class/tenant RBAC.
- Path casing is canonical: `resources/js/Components/ui` (capital `C`), alias `@/Components/ui`.

## 2. Current foundation evidence

| Item | Current state | Official target |
|---|---|---|
| `components.json` | Present: style `new-york`, `rsc:false`, `tsx:true`, baseColor `slate`, cssVariables `true`, aliases map to `@/Components*` | Keep style/aliases; ensure CLI writes into capital-C path |
| `iconLibrary` | Declared `lucide` | Official new-york default lucide. Repo still imports `@heroicons/react` widely |
| Utils | `resources/js/lib/utils.ts` → `cn()` via `clsx` + `tailwind-merge` | Keep |
| Theme | `resources/css/app.css` CSS vars + `tailwind.config.js` darkMode `class` | Keep Tailwind v3; do not jump Tailwind v4 unless separate project decision |
| Package surface | Runtime UI deps thin: heroicons, filepond, cva/tw-merge/clsx. Many `@radix-ui/*` present under `node_modules` but not declared cleanly in `package.json` dependencies | Install registry-required packages via shadcn CLI |
| Barrel | `resources/js/Components/ui/index.ts` re-exports app API; currently **broken** vs Toast/Label/Select/Spinner exports | Rebuild barrel after each batch against real exports |

### Official sources used

- https://ui.shadcn.com/docs
- https://ui.shadcn.com/docs/cli
- https://ui.shadcn.com/docs/components-json
- https://ui.shadcn.com/docs/components
- https://ui.shadcn.com/docs/installation/manual
- https://v3.shadcn.com/docs (v3 docs surface; Laravel install page)
- Registry style: `new-york` (`components.json`)
- CLI: `npx shadcn@latest add <name> ...`

Dry-run verified registry names on this repo (exit 0):  
`alert avatar badge breadcrumb button card checkbox dialog dropdown-menu input label pagination radio-group select separator sidebar skeleton switch table tabs textarea toast tooltip popover command`

Not used as guessed core replacements without docs proof:

- `empty` / `spinner` / `attachment` / base-combobox path: current docs surface differs (base/* pages). Treat as **optional later** only after explicit docs+CLI success for this stack. Do not force-add.

## 3. Inventory summary

Evidence helper: `.hermes-evidence/shadcn-audit/analyze_ui.py` → `inventory.json`

| Metric | Value |
|---|---|
| UI files under `resources/js/Components/ui` | 31 (30 components + `index.ts`) |
| Scanned TS/TSX sources | 163 |
| Composites under `resources/js/Components/composite` | 6 + barrel |
| Legacy Breeze-ish wrappers under `resources/js/Components/*` | Primary/Secondary/DangerButton, TextInput, InputLabel, Checkbox, Dropdown, Modal, NavLink, ResponsiveNavLink, ApplicationLogo |
| Raw `<button` outside ui kit (sample scan) | `composite/DataTable.tsx`, `Layouts/AuthenticatedLayout.tsx` (×2) |

### 3.1 Top barrel consumers (symbol → approx usage count)

| Count | Symbol |
|---:|---|
| 77 | Card, CardContent |
| 75 | Button |
| 63 | Input |
| 42 | CardHeader, CardTitle |
| 41 | Table / TBody / TD / TH / THead / TR |
| 37 | Badge |
| 33 | Select |
| 25 | Pagination |
| 22 | Switch, Textarea |
| 17 | Modal |
| 7 | SearchSelect |
| 5 | Checkbox, useToast |
| 4 | FileUpload, CardFooter |
| ≤2 | Breadcrumb, Dropdown*, Label, StatCard, Tabs*, Alert, Avatar, EmptyState, IconButton, Radio*, Skeleton, Spinner, Tooltip, Topbar, Sidebar* |

### 3.2 Zero / near-zero usage

- `Divider.tsx` — **0 consumers** (delete candidate after confirm)
- Many showcase-only: Alert, Avatar, EmptyState, IconButton, RadioGroup, Skeleton, Tooltip, Topbar, Sidebar (layout-owned)

## 4. Classification overview

Detailed matrix: `docs/plans/INBILS_FULL_SHADCN_COMPONENT_MATRIX.md`

| Class | Meaning | Count (approx) |
|---|---|---|
| A. Registry replaceable primitive | Official shadcn component exists; migrate via CLI | 20 |
| B. Registry-based composite rewrite | App API stays; internals built from registry pieces | 5 |
| C. Keep custom (no registry equivalent / domain) | Documented composite; not fake core | 6+ |
| D. Obsolete / delete / collapse | Unused or superseded | 1–3 + legacy wrappers |

## 5. High-risk findings

1. **Barrel type rot** — `index.ts` exports Toast/Label/Select/Spinner symbols that modules do not export. Blocks `tsc` and `vite build` (`build` runs `tsc && vite build`).
2. **FileUpload types** — FilePond prop typing error (`labelFileTypeNotAllowed`).
3. **Dual icon systems** — components.json lucide vs code heroicons. Migration must pick one (prefer lucide per components.json) or dual-support with plan.
4. **Dual modal/dropdown stacks** — `ui/Modal` (radix dialog) vs root `Modal.tsx`; `ui/Dropdown` vs root `Dropdown.tsx`.
5. **Custom Select/SearchSelect** — native/custom list UIs, not radix Select/Combobox. 33 + 7 call sites; high churn.
6. **Pagination** — app Laravel pagination shape (`links`, Inertia), not pure shadcn pagination demo.
7. **Toast** — custom provider/hook API (`ToastProvider`, `useToast`), not sonner/shadcn toast primitives. Barrel already anticipates shadcn toast names → incomplete prior attempt.
8. **Sidebar/Topbar** — admin shell; registry sidebar is large surface; treat as late batch.
9. **Missing declared radix deps in package.json** — CLI add must normalize package.json.
10. **Lint baseline red** — `resources/js/app.tsx` 2 ESLint errors (`no-unsafe-assignment`, `no-floating-promises`).

## 6. Baseline gates (captured 2026-07-22)

Evidence logs (local, not root dumps):

- `.hermes-evidence/shadcn-audit/baseline-typecheck.log`
- `.hermes-evidence/shadcn-audit/baseline-lint.log`
- `.hermes-evidence/shadcn-audit/baseline-build.log`

| Gate | Result | Notes |
|---|---|---|
| `npm run typecheck` | FAIL | FileUpload props + barrel export mismatches (LabelProps, SelectOption, SpinnerProps, toast/Toast*) |
| `npm run lint` | FAIL | 2 errors in `resources/js/app.tsx` |
| `npm run build` | FAIL | `tsc` step fails same as typecheck |

**Baseline is red before migration.** Batch 0 must restore green typecheck/build (or explicitly scope known pre-existing failures) before large component swaps.

## 7. Compatibility constraints

| Concern | Constraint |
|---|---|
| Tailwind | Stay on v3 (`tailwindcss ^3.2.1`). Use v3-compatible registry install path. |
| Dark mode | `class` strategy; CSS variables already present |
| Inertia | Links use `@inertiajs/react` in Breadcrumb/Dropdown/Sidebar — preserve navigation semantics |
| Tenant/RBAC | No UI migration may weaken route guards, permission-gated buttons, or page props |
| Responsive | Admin shell + tables must remain usable at mobile widths |
| a11y | Prefer radix primitives (focus trap, aria) over hand-rolled Modal/Dropdown |
| Path alias | Keep `@/` → `resources/js`; capital `Components` |
| Shared dirty worktree | Do not touch unrelated WIP; no commit/push from planner |

## 8. Out of scope (this audit)

- Production rewrites of components/pages
- Tailwind v4 migration
- Switching away from Inertia
- Redesign of product UX/brand
- Committing or pushing
- Editing `.env`

## 9. Deliverables produced

1. `docs/plans/INBILS_FULL_SHADCN_AUDIT.md` (this file)
2. `docs/plans/INBILS_FULL_SHADCN_COMPONENT_MATRIX.md`
3. `docs/plans/INBILS_FULL_SHADCN_MIGRATION_PLAN.md`
4. `docs/plans/INBILS_FULL_SHADCN_ROLLBACK.md`
5. Evidence: `.hermes-evidence/shadcn-audit/*` (inventory, baselines, analyzer)

## 10. Approval ask

Approve Batch 0 (foundation) only after:

1. Matrix + migration plan reviewed
2. Icon decision: lucide primary (recommended) vs keep heroicons temporarily
3. Accept that Batch 0 may rewrite barrel + fix Toast export mismatch without page UX change
4. Child card `t_e17c7b80` executes foundation under ui-engineer; later batches wait on green gates
