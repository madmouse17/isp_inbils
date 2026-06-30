# inbils Admin Dashboard — UI/UX Implementation Plan

> **For executor (OpenCode):** Read `AGENTS.md` first. Implement components under
> `resources/js/Components/ui/`. Every component MUST: be TypeScript, support
> dark mode via `dark:` classes, be accessible (keyboard + aria), and use the
> design tokens from `tailwind.config.js` (`brand`, `surface`, `success`,
> `warning`, `danger`). Never inline primitives in pages.

**Goal:** A reusable, modern flat React component library for the inbils admin
dashboard — composable primitives that every dashboard page builds on.

**Design language:** Modern flat. Restrained palette, soft rounded corners
(`rounded-lg`/`rounded-xl`), thin borders (`border-surface-200`), subtle
surfaces (`bg-surface-50` / `dark:bg-surface-900`), single elevation layer
(`shadow-sm`), generous whitespace, clear focus rings, full dark mode.

**Tech:** React 18 + TypeScript + Tailwind v3. Icons via `@heroicons/react`
(install if missing). Variant plumbing via `clsx` + `tailwind-merge`
(install if missing; expose a `cn` helper). Compose with `cva` only if a
component grows >3 variants — prefer plain maps for simplicity.

---

## Setup Tasks

### Task 0a: Install UI dependencies

**Files:** modify `package.json`

Run:
```bash
npm install @heroicons/react clsx tailwind-merge
npm install -D @types/react @types/react-dom
```

### Task 0b: Create `cn` helper

**File:** create `resources/js/lib/utils.ts`

```ts
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
```

### Task 0c: Create barrel export

**File:** create `resources/js/Components/ui/index.ts`

Re-export every component so pages import from one path:
`import { Button, Card, Input } from '@/Components/ui';`.

Update this file as each component is added.

---

## Component Specs

All files under `resources/js/Components/ui/`. One component per file.
Named exports. PascalCase file name = component name. `React.forwardRef`
for inputs/buttons/selects/textarea.

### 1. Button — `Button.tsx`

Variants: `primary` (brand-600), `secondary` (surface-100), `ghost`
(transparent, hover surface-100), `danger` (danger), `outline`
(border surface-300, transparent). Sizes: `sm`, `md`, `lg`. Props:
`variant`, `size`, `loading?` (shows spinner, disables), `leftIcon?`,
`rightIcon?`, plus native button attrs. Base: `inline-flex items-center
justify-center gap-2 rounded-lg font-medium transition-colors focus:outline-none
focus:ring-2 focus:ring-brand-500/50 disabled:opacity-60 disabled:cursor-not-allowed`.

### 2. IconButton — `IconButton.tsx`

Square button, icon-only. Props: `label` (required, → `aria-label`),
`variant`, `size`. Same variants as Button minus `outline`.

### 3. Input — `Input.tsx`

`forwardRef<HTMLInputElement>`. Props: `label?`, `error?` (string),
`hint?`, `leftIcon?` (e.g. search icon), native input attrs. Wrapper
div with label above, error text below in `text-danger text-sm`.
Base input: `w-full rounded-lg border border-surface-300 bg-white px-3
py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30
dark:bg-surface-900 dark:border-surface-700 dark:text-surface-100`.
Error state: border `danger`.

### 4. Textarea — `Textarea.tsx`

Same shape as Input but `<textarea>`. Props: `label?`, `error?`, `hint?`,
`rows?`, native attrs. Auto-resize NOT required (keep simple).

### 5. Select — `Select.tsx`

`forwardRef<HTMLSelectElement>`. Styled native `<select>` with chevron
icon overlay. Props: `label?`, `error?`, `options?` (`{value,label}[]`),
native attrs. Children also accepted for `<option>` groups.

### 6. Checkbox — `Checkbox.tsx`

`forwardRef<HTMLInputElement>`, type=checkbox. Props: `label?`,
`description?`, `error?`, native attrs. Custom check style via
`peer` + `peer-checked` pattern. Label clickable.

### 7. Switch — `Switch.tsx`

Controlled toggle. Props: `checked`, `onCheckedChange`, `label?`,
`disabled?`. Accessible: `role="switch"`, `aria-checked`, keyboard
Space/Enter toggle. Track + thumb, brand when on.

### 8. RadioGroup — `RadioGroup.tsx` + `Radio.tsx`

`RadioGroup`: context for name + value. Props: `name`, `value?`,
`onChange?`, `label?`. `Radio`: `forwardRef`, uses context, label +
description. Accessible grouping via `role="radiogroup"`.

### 9. Badge — `Badge.tsx`

Variants: `neutral`, `brand`, `success`, `warning`, `danger`. Sizes:
`sm`, `md`. Optional `dot` (status dot). Base: `inline-flex items-center
gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium`.

### 10. Avatar — `Avatar.tsx`

Props: `src?`, `name?` (→ initials fallback), `size?` (`sm`/`md`/`lg`),
`status?` (online/offline dot). Rounded full, `bg-surface-200` fallback,
initials centered. Image `alt` = name.

### 11. Card — `Card.tsx` (+ `CardHeader`, `CardTitle`, `CardDescription`,
`CardContent`, `CardFooter`)

Composable container. Card base: `rounded-xl border border-surface-200
bg-white shadow-sm dark:bg-surface-900 dark:border-surface-800`.
Sub-components for structure. No padding on Card itself; padding on
sub-components (`p-5`/`px-5 py-4`).

### 12. StatCard — `StatCard.tsx`

Dashboard metric tile. Props: `label`, `value`, `delta?` (number %),
`deltaDirection?` ('up'|'down'), `icon?`, `accent?` (brand/success/
warning/danger). Shows value big, delta with arrow + color. Uses Card.

### 13. Table — `Table.tsx` (+ `THead`, `TBody`, `TR`, `TH`, `TD`)

Composable table primitives. Base table: `w-full text-sm`. TH:
`px-4 py-3 text-left font-semibold text-surface-500 uppercase text-xs
tracking-wide border-b border-surface-200 dark:border-surface-800`.
TD: `px-4 py-3 border-b border-surface-100 dark:border-surface-800`.
TR hover: `hover:bg-surface-50 dark:hover:bg-surface-800/50`. Wrap in
overflow-x-auto at page level, not here.

### 14. Pagination — `Pagination.tsx`

Props: `currentPage`, `lastPage`, `onPageChange`. Prev/Next buttons +
page number window (show first, last, ±2 around current, ellipsis).
Use `route()` from Inertia for links if `links` prop provided (Laravel
paginator shape) — support both callback and Inertia-link modes.

### 15. Modal — `Modal.tsx`

Props: `open`, `onClose`, `title?`, `size?` (`sm`/`md`/`lg`/`xl`),
`children`. Portal to `document.body`. Backdrop `bg-black/50` click to
close. Focus trap + Esc to close + `aria-modal`. Body scroll lock when
open. Animations optional (keep simple, no extra deps).

### 16. Dropdown — `Dropdown.tsx` (+ `DropdownTrigger`, `DropdownContent`,
`DropdownItem`, `DropdownSeparator`)

Headless UI powered (already installed: `@headlessui/react`). Trigger
opens content; click outside / Esc closes. Items: keyboard navigable,
`role="menuitem"`. Use for row actions, user menu.

### 17. Tabs — `Tabs.tsx` (+ `TabList`, `Tab`, `TabPanel`)

Headless UI powered. Props on `Tab`: `children`. `aria` handled by
Headless. Active tab underline in brand color.

### 18. Alert — `Alert.tsx`

Inline banner. Variants: `info`, `success`, `warning`, `danger`. Props:
`title?`, `children`, `onDismiss?`. Icon per variant. Base: `flex gap-3
rounded-lg border p-4` with variant-tinted bg.

### 19. Toast — `Toast.tsx` + `ToastProvider.tsx`

Context-based toast system. `ToastProvider` wraps app, exposes
`useToast()` returning `toast({ title, description?, variant? })`.
Renders stack top-right, auto-dismiss 4s, manual close. Headless UI
`Transition` for enter/leave. Wire provider in `resources/js/app.tsx`
around `<App>`.

### 20. Breadcrumb — `Breadcrumb.tsx` (+ `BreadcrumbItem`)

Props on list: `items: {label, href?}[]`. Renders separators between.
Last item is current (no link, `aria-current="page"`).

### 21. Sidebar — `Sidebar.tsx` (+ `SidebarItem`, `SidebarSection`)

Dashboard navigation. `SidebarItem`: `href`, `icon`, `label`, `active?`,
`badge?`. Active state: `bg-brand-50 text-brand-700 dark:bg-brand-900/30
dark:text-brand-300` + left border. Collapsible sections optional (keep
flat for v1). Responsive: hidden on mobile, shown via drawer in
`AdminLayout`.

### 22. Topbar — `Topbar.tsx`

Header bar. Slots: `left` (mobile menu toggle + page title), `right`
(search, notifications, user dropdown). Sticky top, `h-16 border-b
border-surface-200 bg-white/80 backdrop-blur dark:bg-surface-900/80`.

### 23. Spinner — `Spinner.tsx`

SVG spinner, `animate-spin`. Props: `size?` (`sm`/`md`/`lg`), `className?`.
Color: `text-brand-500` default, inherit via className.

### 24. Skeleton — `Skeleton.tsx`

Props: `className?`. Base: `animate-pulse rounded-md bg-surface-200
dark:bg-surface-800`. Caller sets width/height via className.

### 25. EmptyState — `EmptyState.tsx`

Props: `icon?`, `title`, `description?`, `action?` (ReactNode, e.g. Button).
Centered, muted text. Used by tables/lists when no data.

### 26. Tooltip — `Tooltip.tsx`

Headless UI powered. Props: `label`, `children`, `side?` (`top`/`bottom`).
Minimal: shows on hover/focus. `role="tooltip"`.

### 27. Label — `Label.tsx`

Form label. Props: `htmlFor?`, `children`, `required?` (shows red `*`).
Base: `block text-sm font-medium text-surface-700 dark:text-surface-300`.

### 28. Divider — `Divider.tsx`

Props: `orientation?` (`horizontal`|`vertical`), `className?`. Horizontal:
`h-px w-full bg-surface-200 dark:bg-surface-800`.

---

## Layout Task

### 29. AdminLayout — `resources/js/Layouts/AdminLayout.tsx`

Shell: fixed Sidebar (desktop) + Topbar + `<main>` content slot.
Mobile: sidebar in a drawer toggled from Topbar menu button. Dark mode
toggle button in Topbar (persist to `localStorage`, toggle `dark` class
on `<html>`). Inertia `<Head>` for page title. Use Inertia `Link` for
nav. Sidebar nav items: Dashboard, Users (placeholder routes).

---

## Verification Task

### 30. Showcase page + build

**File:** create `resources/js/Pages/Admin/Components.tsx`

A single page rendering every component once (a living styleguide) so we
can eyeball consistency. Wire a route `GET /admin/components` returning
Inertia view. Run:

```bash
npm run build   # tsc + vite build must pass clean
```

Expected: 0 TypeScript errors, build succeeds, page renders all
components in light + dark.

---

## Implementation Order (do in this sequence)

1. Task 0a/0b/0c (deps + cn + barrel)
2. Primitives: Button, IconButton, Label, Input, Textarea, Select,
   Checkbox, Switch, Radio, Badge, Avatar, Spinner, Skeleton, Divider
3. Containers: Card (+ subs), StatCard, Alert, EmptyState
4. Navigation: Breadcrumb, Pagination, Sidebar (+ subs), Topbar, Tabs
5. Overlay: Modal, Dropdown, Tooltip, Toast (+ provider)
6. Data: Table (+ subs)
7. Layout: AdminLayout
8. Showcase: Components.tsx + route + build verify

After each group, run `npx tsc --noEmit` to catch type errors early.
Commit per group.

## Pitfalls

- Do NOT add `@tailwindcss/vite` (stack is v3 PostCSS).
- Do NOT use `any` in props — type everything.
- Do NOT hardcode hex in components — use `brand`/`surface`/etc. tokens.
- Do NOT forget `dark:` variants on every colored surface.
- Headless UI components: keep their default a11y, don't strip `aria-*`.
- `forwardRef` ref types: `HTMLButtonElement`, `HTMLInputElement`,
  `HTMLSelectElement`, `HTMLTextAreaElement`.
- Keep components presentational — no fetch/axios inside `ui/*`.
