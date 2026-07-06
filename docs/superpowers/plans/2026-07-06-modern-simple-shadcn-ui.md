# Modern Simple shadcn-style UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the admin UI to feel modern, simple, and consistent using shadcn/ui conventions while preserving current Laravel, Inertia, React, Tailwind, routes, permissions, forms, filters, and pagination behavior.

**Architecture:** Keep the existing Laravel/Inertia/React app and replace the UI surface from the shared component layer outward. Add shadcn-compatible theme tokens, keep `@/Components/ui` as the import path, refresh the admin shell, then rewrite pages in staged groups so each commit remains buildable.

**Tech Stack:** Laravel 12.62.0, Inertia Laravel 2.0.24, @inertiajs/react 2.3.27, React 18.3.1, Tailwind CSS 3.4.19, TypeScript 5.x, shadcn/ui conventions, existing `clsx` + `tailwind-merge` `cn()` helper.

## Global Constraints

- Keep Inertia + React + Tailwind.
- Keep `@/Components/ui` as the primary UI import path.
- Reuse existing `cn()` helper in `resources/js/lib/utils.ts`.
- Prefer existing installed dependencies first.
- Add dependencies only when needed by a shadcn-style component that cannot be safely covered by existing code.
- Avoid one large risky rewrite.
- Ship the full redesign through staged commits.
- Preserve backend/API behavior, route names, permissions, Inertia form payloads, filters, pagination, and dark-mode toggle behavior.
- Keep Indonesian labels where pages already use Indonesian copy.
- Use `rtk` prefix for shell commands.

---

## File Structure

### Create

- `components.json` — shadcn/ui project metadata pointing at the existing Vite/Tailwind/React paths.

### Modify: foundations

- `tailwind.config.js` — add shadcn variable-backed tokens while keeping existing `brand` and `surface` colors during migration.
- `resources/css/app.css` — add light/dark CSS variables and base shadcn styles.

### Modify: shared UI primitives

- `resources/js/Components/ui/Button.tsx` — shadcn-style button variants with existing props.
- `resources/js/Components/ui/IconButton.tsx` — square button wrapper using `Button` classes.
- `resources/js/Components/ui/Label.tsx` — accessible label with required marker.
- `resources/js/Components/ui/Input.tsx` — shadcn input styles, errors, hints, icons.
- `resources/js/Components/ui/Textarea.tsx` — shadcn textarea styles, errors, hints.
- `resources/js/Components/ui/Select.tsx` — native select with shadcn field styling.
- `resources/js/Components/ui/Checkbox.tsx` — shadcn checkbox styling with current props.
- `resources/js/Components/ui/Switch.tsx` — existing switch behavior with token classes.
- `resources/js/Components/ui/RadioGroup.tsx` — existing radio behavior with token classes.
- `resources/js/Components/ui/Badge.tsx` — neutral/brand/success/warning/danger/info/muted badges mapped to tokens.
- `resources/js/Components/ui/Avatar.tsx` — neutral avatar with status ring.
- `resources/js/Components/ui/Spinner.tsx` — current spinner with foreground tokens.
- `resources/js/Components/ui/Skeleton.tsx` — muted shimmer blocks.
- `resources/js/Components/ui/Divider.tsx` — border token separator.
- `resources/js/Components/ui/Card.tsx` — shadcn card/header/content/footer classes.
- `resources/js/Components/ui/StatCard.tsx` — stat card built from `Card`.
- `resources/js/Components/ui/Alert.tsx` — tokenized alert variants.
- `resources/js/Components/ui/EmptyState.tsx` — compact empty state.
- `resources/js/Components/ui/Breadcrumb.tsx` — tokenized breadcrumb links.
- `resources/js/Components/ui/Pagination.tsx` — tokenized pagination buttons.
- `resources/js/Components/ui/Sidebar.tsx` — modern sidebar item/section/shell.
- `resources/js/Components/ui/Topbar.tsx` — sticky topbar using theme tokens.
- `resources/js/Components/ui/Tabs.tsx` — tokenized tabs.
- `resources/js/Components/ui/Modal.tsx` — Headless UI dialog with shadcn panel/backdrop classes.
- `resources/js/Components/ui/Dropdown.tsx` — Headless UI dropdown with shadcn menu classes.
- `resources/js/Components/ui/Toast.tsx` — tokenized toast.
- `resources/js/Components/ui/Table.tsx` — shadcn table wrapper.
- `resources/js/Components/ui/Tooltip.tsx` — tokenized tooltip.
- `resources/js/Components/ui/index.ts` — export list stays stable.

### Modify: composite components

- `resources/js/Components/composite/PageHeader.tsx` — consistent title/subtitle/breadcrumb/actions area.
- `resources/js/Components/composite/FormField.tsx` — shadcn field spacing.
- `resources/js/Components/composite/StatusBadge.tsx` — badge variant mapping.
- `resources/js/Components/composite/MoneyInput.tsx` — input wrapper token styles.
- `resources/js/Components/composite/DateRangeFilter.tsx` — compact filter layout.
- `resources/js/Components/composite/DataTable.tsx` — filter card + table + pagination pattern.
- `resources/js/Components/composite/index.ts` — export list stays stable.

### Modify: shell

- `resources/js/Layouts/AdminLayout.tsx` — fixed desktop sidebar, mobile drawer, topbar, content spacing, active nav state.
- `resources/js/Layouts/AuthenticatedLayout.tsx` — convert Breeze profile layout to the same neutral tokens.
- `resources/js/Layouts/GuestLayout.tsx` — convert auth pages wrapper to the same neutral tokens.
- `resources/js/Layouts/SetupLayout.tsx` — convert setup wizard wrapper to the same neutral tokens.

### Modify: admin pages

High-traffic pages first:

- `resources/js/Pages/Admin/Dashboard/Index.tsx`
- `resources/js/Pages/Admin/Customers/Index.tsx`
- `resources/js/Pages/Admin/Subscriptions/Index.tsx`
- `resources/js/Pages/Admin/Billing/Invoices/Index.tsx`
- `resources/js/Pages/Admin/Billing/Receivables.tsx`
- `resources/js/Pages/Admin/Tickets/Index.tsx`

High-traffic form/detail pages:

- `resources/js/Pages/Admin/Customers/Create.tsx`
- `resources/js/Pages/Admin/Customers/Edit.tsx`
- `resources/js/Pages/Admin/Customers/Show.tsx`
- `resources/js/Pages/Admin/Subscriptions/Show.tsx`
- `resources/js/Pages/Admin/Billing/Invoices/Create.tsx`
- `resources/js/Pages/Admin/Billing/Invoices/GenerateDialog.tsx`
- `resources/js/Pages/Admin/Billing/Invoices/Show.tsx`
- `resources/js/Pages/Admin/Tickets/Create.tsx`
- `resources/js/Pages/Admin/Tickets/Show.tsx`

Remaining admin pages:

- `resources/js/Pages/Admin/Permissions/Index.tsx`
- `resources/js/Pages/Admin/Company/Settings.tsx`
- `resources/js/Pages/Admin/Company/Profile.tsx`
- `resources/js/Pages/Admin/Users/Index.tsx`
- `resources/js/Pages/Admin/Users/Create.tsx`
- `resources/js/Pages/Admin/Users/Edit.tsx`
- `resources/js/Pages/Admin/Users/Show.tsx`
- `resources/js/Pages/Admin/Roles/Index.tsx`
- `resources/js/Pages/Admin/Roles/Create.tsx`
- `resources/js/Pages/Admin/Roles/Edit.tsx`
- `resources/js/Pages/Admin/Locations/Index.tsx`
- `resources/js/Pages/Admin/CustomerAddresses/Index.tsx`
- `resources/js/Pages/Admin/CustomerContacts/Index.tsx`
- `resources/js/Pages/Admin/Service/BandwidthProfiles/Index.tsx`
- `resources/js/Pages/Admin/Service/BandwidthProfiles/Create.tsx`
- `resources/js/Pages/Admin/Service/BandwidthProfiles/Edit.tsx`
- `resources/js/Pages/Admin/Service/SpeedProfiles/Index.tsx`
- `resources/js/Pages/Admin/Service/SpeedProfiles/Create.tsx`
- `resources/js/Pages/Admin/Service/SpeedProfiles/Edit.tsx`
- `resources/js/Pages/Admin/Service/SLATiers/Index.tsx`
- `resources/js/Pages/Admin/Service/SLATiers/Create.tsx`
- `resources/js/Pages/Admin/Service/SLATiers/Edit.tsx`
- `resources/js/Pages/Admin/Service/Packages/Index.tsx`
- `resources/js/Pages/Admin/Service/Packages/Create.tsx`
- `resources/js/Pages/Admin/Service/Packages/Edit.tsx`
- `resources/js/Pages/Admin/Service/Packages/Show.tsx`
- `resources/js/Pages/Admin/Inventory/Stocks/Index.tsx`
- `resources/js/Pages/Admin/Inventory/Movements/Index.tsx`
- `resources/js/Pages/Admin/Inventory/Find.tsx`
- `resources/js/Pages/Admin/Inventory/Products/Create.tsx`
- `resources/js/Pages/Admin/Inventory/Products/Show.tsx`
- `resources/js/Pages/Admin/Inventory/Products/Edit.tsx`
- `resources/js/Pages/Admin/Inventory/Products/Index.tsx`
- `resources/js/Pages/Admin/Inventory/Units/Index.tsx`
- `resources/js/Pages/Admin/Inventory/Categories/Index.tsx`
- `resources/js/Pages/Admin/NetworkAssets/Index.tsx`
- `resources/js/Pages/Admin/NetworkAssets/Create.tsx`
- `resources/js/Pages/Admin/NetworkAssets/Edit.tsx`
- `resources/js/Pages/Admin/NetworkAssets/Show.tsx`
- `resources/js/Pages/Admin/NetworkAssets/Trace.tsx`
- `resources/js/Pages/Admin/SPK/Index.tsx`
- `resources/js/Pages/Admin/SPK/Create.tsx`
- `resources/js/Pages/Admin/SPK/Show.tsx`
- `resources/js/Pages/Admin/Evaluations/Index.tsx`
- `resources/js/Pages/Admin/Evaluations/Create.tsx`
- `resources/js/Pages/Admin/Evaluations/Show.tsx`
- `resources/js/Pages/Admin/Evaluations/Edit.tsx`
- `resources/js/Pages/Admin/Reports/Index.tsx`
- `resources/js/Pages/Admin/Reports/Business.tsx`
- `resources/js/Pages/Admin/Reports/Technician.tsx`
- `resources/js/Pages/Admin/Reports/Asset.tsx`
- `resources/js/Pages/Admin/Reports/Sla.tsx`
- `resources/js/Pages/Admin/Reports/StockCard.tsx`
- `resources/js/Pages/Admin/Reports/AuditLog.tsx`
- `resources/js/Pages/Admin/Documents/Index.tsx`
- `resources/js/Pages/Admin/Employees/Index.tsx`
- `resources/js/Pages/Admin/NumberSequences/Index.tsx`
- `resources/js/Pages/Admin/Organizations/Index.tsx`
- `resources/js/Pages/Admin/Vehicles/Index.tsx`
- `resources/js/Pages/Admin/Components.tsx`

---

### Task 1: shadcn theme foundation

**Files:**
- Create: `components.json`
- Modify: `tailwind.config.js`
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: existing `@/*` alias from `tsconfig.json`; existing `cn()` helper in `resources/js/lib/utils.ts`.
- Produces: Tailwind tokens `background`, `foreground`, `card`, `card-foreground`, `popover`, `popover-foreground`, `primary`, `primary-foreground`, `secondary`, `secondary-foreground`, `muted`, `muted-foreground`, `accent`, `accent-foreground`, `destructive`, `destructive-foreground`, `border`, `input`, `ring`; CSS radius variable `--radius`.

- [ ] **Step 1: Add shadcn project metadata**

Create `components.json` with this exact content:

```json
{
    "$schema": "https://ui.shadcn.com/schema.json",
    "style": "new-york",
    "rsc": false,
    "tsx": true,
    "tailwind": {
        "config": "tailwind.config.js",
        "css": "resources/css/app.css",
        "baseColor": "slate",
        "cssVariables": true,
        "prefix": ""
    },
    "aliases": {
        "components": "@/Components",
        "utils": "@/lib/utils",
        "ui": "@/Components/ui",
        "lib": "@/lib",
        "hooks": "@/hooks"
    },
    "iconLibrary": "lucide"
}
```

- [ ] **Step 2: Add shadcn tokens to Tailwind config**

In `tailwind.config.js`, keep existing `brand` and `surface` colors and add these keys inside `theme.extend`:

```js
borderRadius: {
    lg: 'var(--radius)',
    md: 'calc(var(--radius) - 2px)',
    sm: 'calc(var(--radius) - 4px)',
},
colors: {
    border: 'hsl(var(--border))',
    input: 'hsl(var(--input))',
    ring: 'hsl(var(--ring))',
    background: 'hsl(var(--background))',
    foreground: 'hsl(var(--foreground))',
    primary: {
        DEFAULT: 'hsl(var(--primary))',
        foreground: 'hsl(var(--primary-foreground))',
    },
    secondary: {
        DEFAULT: 'hsl(var(--secondary))',
        foreground: 'hsl(var(--secondary-foreground))',
    },
    destructive: {
        DEFAULT: 'hsl(var(--destructive))',
        foreground: 'hsl(var(--destructive-foreground))',
    },
    muted: {
        DEFAULT: 'hsl(var(--muted))',
        foreground: 'hsl(var(--muted-foreground))',
    },
    accent: {
        DEFAULT: 'hsl(var(--accent))',
        foreground: 'hsl(var(--accent-foreground))',
    },
    popover: {
        DEFAULT: 'hsl(var(--popover))',
        foreground: 'hsl(var(--popover-foreground))',
    },
    card: {
        DEFAULT: 'hsl(var(--card))',
        foreground: 'hsl(var(--card-foreground))',
    },
    brand: {
        50: '#eff6ff',
        100: '#dbeafe',
        200: '#bfdbfe',
        300: '#93c5fd',
        400: '#60a5fa',
        500: '#3b82f6',
        600: '#2563eb',
        700: '#1d4ed8',
        800: '#1e40af',
        900: '#1e3a8a',
        950: '#172554',
    },
    surface: {
        50: '#f8fafc',
        100: '#f1f5f9',
        200: '#e2e8f0',
        300: '#cbd5e1',
        400: '#94a3b8',
        500: '#64748b',
        600: '#475569',
        700: '#334155',
        800: '#1e293b',
        900: '#0f172a',
        950: '#020617',
    },
    success: '#16a34a',
    warning: '#d97706',
    danger: '#dc2626',
},
```

- [ ] **Step 3: Add shadcn CSS variables**

Replace `resources/css/app.css` with:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
    :root {
        --background: 0 0% 100%;
        --foreground: 222.2 84% 4.9%;
        --card: 0 0% 100%;
        --card-foreground: 222.2 84% 4.9%;
        --popover: 0 0% 100%;
        --popover-foreground: 222.2 84% 4.9%;
        --primary: 221.2 83.2% 53.3%;
        --primary-foreground: 210 40% 98%;
        --secondary: 210 40% 96.1%;
        --secondary-foreground: 222.2 47.4% 11.2%;
        --muted: 210 40% 96.1%;
        --muted-foreground: 215.4 16.3% 46.9%;
        --accent: 210 40% 96.1%;
        --accent-foreground: 222.2 47.4% 11.2%;
        --destructive: 0 84.2% 60.2%;
        --destructive-foreground: 210 40% 98%;
        --border: 214.3 31.8% 91.4%;
        --input: 214.3 31.8% 91.4%;
        --ring: 221.2 83.2% 53.3%;
        --radius: 0.5rem;
    }

    .dark {
        --background: 222.2 84% 4.9%;
        --foreground: 210 40% 98%;
        --card: 222.2 84% 4.9%;
        --card-foreground: 210 40% 98%;
        --popover: 222.2 84% 4.9%;
        --popover-foreground: 210 40% 98%;
        --primary: 217.2 91.2% 59.8%;
        --primary-foreground: 222.2 47.4% 11.2%;
        --secondary: 217.2 32.6% 17.5%;
        --secondary-foreground: 210 40% 98%;
        --muted: 217.2 32.6% 17.5%;
        --muted-foreground: 215 20.2% 65.1%;
        --accent: 217.2 32.6% 17.5%;
        --accent-foreground: 210 40% 98%;
        --destructive: 0 62.8% 30.6%;
        --destructive-foreground: 210 40% 98%;
        --border: 217.2 32.6% 17.5%;
        --input: 217.2 32.6% 17.5%;
        --ring: 224.3 76.3% 48%;
    }

    * {
        @apply border-border;
    }

    body {
        @apply bg-background text-foreground antialiased;
    }
}
```

- [ ] **Step 4: Verify foundation builds**

Run:

```bash
rtk npm run typecheck
rtk npm run build
```

Expected: both commands pass.

- [ ] **Step 5: Commit foundation**

Run:

```bash
rtk git add components.json tailwind.config.js resources/css/app.css
rtk git commit -m "feat(ui): add shadcn theme foundation"
```

Expected: commit succeeds.

---

### Task 2: shared UI primitives

**Files:**
- Modify: all files listed under “Modify: shared UI primitives”.
- Modify: all files listed under “Modify: composite components”.

**Interfaces:**
- Consumes: tokens from Task 1 and existing `cn(...inputs: ClassValue[]): string`.
- Produces: stable exports from `resources/js/Components/ui/index.ts` and `resources/js/Components/composite/index.ts`; existing page imports continue to compile.

- [ ] **Step 1: Replace base component class vocabulary**

For each shared UI primitive, replace `surface-*` classes with token classes where the component is already being edited:

```txt
bg-white dark:bg-surface-900       -> bg-card text-card-foreground
text-surface-900 dark:text-surface-100 -> text-foreground
text-surface-500 dark:text-surface-400 -> text-muted-foreground
border-surface-200 dark:border-surface-800 -> border-border
bg-surface-50 dark:bg-surface-900  -> bg-muted/40
focus:ring-brand-500/50            -> focus-visible:ring-ring
```

Keep `brand-*` only where it communicates app identity or active navigation.

- [ ] **Step 2: Update `Button` contract and classes**

Keep this interface in `resources/js/Components/ui/Button.tsx`:

```tsx
export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: 'default' | 'primary' | 'secondary' | 'ghost' | 'danger' | 'destructive' | 'outline';
    size?: 'sm' | 'md' | 'lg' | 'icon';
    loading?: boolean;
    leftIcon?: React.ReactNode;
    rightIcon?: React.ReactNode;
}
```

Use these variant classes:

```tsx
const variants = {
    default: 'bg-primary text-primary-foreground shadow hover:bg-primary/90',
    primary: 'bg-primary text-primary-foreground shadow hover:bg-primary/90',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    danger: 'bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90',
    destructive: 'bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90',
    outline: 'border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground',
} as const;

const sizes = {
    sm: 'h-8 rounded-md px-3 text-xs',
    md: 'h-9 px-4 py-2 text-sm',
    lg: 'h-10 rounded-md px-8 text-sm',
    icon: 'h-9 w-9',
} as const;
```

Use this base class:

```tsx
'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50'
```

- [ ] **Step 3: Update field components**

For `Input`, `Textarea`, and `Select`, keep current `label`, `error`, and `hint` props. Use this input class pattern:

```tsx
'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'
```

For textareas, use:

```tsx
'min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'
```

For errors, append:

```tsx
'border-destructive focus-visible:ring-destructive'
```

- [ ] **Step 4: Update card and table components**

`Card` base class:

```tsx
'rounded-lg border bg-card text-card-foreground shadow-sm'
```

`CardHeader` class:

```tsx
'flex flex-col space-y-1.5 p-6'
```

`CardContent` class:

```tsx
'p-6 pt-0'
```

`Table` outer wrapper:

```tsx
<div className="w-full overflow-auto rounded-md border border-border">
```

`table` class:

```tsx
'w-full caption-bottom text-sm'
```

`THead` class:

```tsx
'[&_tr]:border-b bg-muted/50'
```

`TR` class:

```tsx
'border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted'
```

`TH` class:

```tsx
'h-10 px-3 text-left align-middle text-xs font-medium uppercase tracking-wide text-muted-foreground'
```

`TD` class:

```tsx
'p-3 align-middle text-sm'
```

- [ ] **Step 5: Update composite `PageHeader`**

Keep this interface in `resources/js/Components/composite/PageHeader.tsx`:

```tsx
interface PageHeaderProps {
    title: string;
    subtitle?: string;
    breadcrumbs?: BreadcrumbItem[];
    actions?: React.ReactNode;
}
```

Use this layout:

```tsx
<div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div className="space-y-2">
        {breadcrumbs && breadcrumbs.length > 0 && <Breadcrumb items={breadcrumbs} />}
        <div className="space-y-1">
            <h1 className="text-2xl font-semibold tracking-tight text-foreground">{title}</h1>
            {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
        </div>
    </div>
    {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
</div>
```

- [ ] **Step 6: Verify shared components compile**

Run:

```bash
rtk npm run typecheck
rtk npm run build
```

Expected: both commands pass.

- [ ] **Step 7: Commit shared primitives**

Run:

```bash
rtk git add resources/js/Components/ui resources/js/Components/composite
rtk git commit -m "feat(ui): refresh shared primitives"
```

Expected: commit succeeds.

---

### Task 3: admin shell refresh

**Files:**
- Modify: `resources/js/Layouts/AdminLayout.tsx`
- Modify: `resources/js/Layouts/AuthenticatedLayout.tsx`
- Modify: `resources/js/Layouts/GuestLayout.tsx`
- Modify: `resources/js/Layouts/SetupLayout.tsx`
- Modify: `resources/js/Components/ui/Sidebar.tsx`
- Modify: `resources/js/Components/ui/Topbar.tsx`

**Interfaces:**
- Consumes: `Button`, `Sidebar`, `SidebarItem`, `SidebarSection`, `Topbar`, `useCompany()`, `usePermission()`, `useToast()`.
- Produces: `AdminLayout({ title, children }: { title?: string; children: React.ReactNode })` with active sidebar states, mobile drawer, sticky topbar, and tokenized content area.

- [ ] **Step 1: Add active sidebar detection in `AdminLayout`**

Inside `AdminLayout`, import `usePage` from `@inertiajs/react` and add:

```tsx
const { url } = usePage();
const isActive = (href: string) => url === href || url.startsWith(`${href}/`);
```

Pass `active={isActive('/target')}` to each `SidebarItem`.

- [ ] **Step 2: Update admin page shell classes**

Use this outer shell in `AdminLayout`:

```tsx
<div className="flex min-h-screen bg-muted/30 text-foreground dark:bg-background">
```

Use this content wrapper:

```tsx
<div className="flex min-w-0 flex-1 flex-col">
```

Use this main element:

```tsx
<main className="flex-1 overflow-auto p-4 md:p-6">
    <div className="mx-auto w-full max-w-[1600px] space-y-6">{children}</div>
</main>
```

- [ ] **Step 3: Update `Sidebar` classes**

Use this aside base in `resources/js/Components/ui/Sidebar.tsx`:

```tsx
'fixed inset-y-0 left-0 z-50 flex w-72 transform flex-col border-r border-border bg-card p-4 text-card-foreground shadow-xl transition-transform dark:bg-card lg:static lg:translate-x-0 lg:shadow-none'
```

Use this brand block:

```tsx
<div className="mb-6 flex items-center gap-3 px-2">
    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary text-sm font-semibold text-primary-foreground">in</div>
    <div>
        <p className="text-sm font-semibold leading-none">inbils</p>
        <p className="text-xs text-muted-foreground">ISP ERP</p>
    </div>
</div>
```

Use this item base:

```tsx
'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring'
```

Active item:

```tsx
'bg-primary text-primary-foreground shadow-sm'
```

Inactive item:

```tsx
'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
```

- [ ] **Step 4: Update `Topbar` classes**

Use this header class in `resources/js/Components/ui/Topbar.tsx`:

```tsx
'sticky top-0 z-30 flex h-14 items-center justify-between border-b border-border bg-background/80 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/60 md:px-6'
```

Use title class:

```tsx
'text-sm font-medium text-foreground md:text-base'
```

- [ ] **Step 5: Convert non-admin layouts to tokens**

In `AuthenticatedLayout.tsx`, `GuestLayout.tsx`, and `SetupLayout.tsx`, replace gray/surface background and text classes with token equivalents:

```txt
bg-gray-100 dark:bg-gray-900 -> bg-muted/30 dark:bg-background
bg-white dark:bg-gray-800 -> bg-card text-card-foreground
text-gray-800 dark:text-gray-200 -> text-foreground
text-gray-500 dark:text-gray-400 -> text-muted-foreground
border-gray-100 dark:border-gray-700 -> border-border
```

- [ ] **Step 6: Verify shell compile**

Run:

```bash
rtk npm run typecheck
rtk npm run build
```

Expected: both commands pass.

- [ ] **Step 7: Commit shell refresh**

Run:

```bash
rtk git add resources/js/Layouts resources/js/Components/ui/Sidebar.tsx resources/js/Components/ui/Topbar.tsx
rtk git commit -m "feat(ui): refresh admin shell"
```

Expected: commit succeeds.

---

### Task 4: high-traffic list pages

**Files:**
- Modify: `resources/js/Pages/Admin/Dashboard/Index.tsx`
- Modify: `resources/js/Pages/Admin/Customers/Index.tsx`
- Modify: `resources/js/Pages/Admin/Subscriptions/Index.tsx`
- Modify: `resources/js/Pages/Admin/Billing/Invoices/Index.tsx`
- Modify: `resources/js/Pages/Admin/Billing/Receivables.tsx`
- Modify: `resources/js/Pages/Admin/Tickets/Index.tsx`

**Interfaces:**
- Consumes: `AdminLayout`, `PageHeader`, `Card`, `CardHeader`, `CardTitle`, `CardContent`, `Button`, `Input`, `Select`, `Badge`, `StatusBadge`, `Table`, `THead`, `TBody`, `TR`, `TH`, `TD`, `Pagination`, `router`, `route()`.
- Produces: six modern list/dashboard pages with unchanged route calls, filter keys, pagination keys, delete behavior, and create/show/edit navigation.

- [ ] **Step 1: Apply list page skeleton**

For each list page, use this structure around existing page-specific content:

```tsx
<AdminLayout title="Page title">
    <div className="space-y-6">
        <PageHeader
            title="Page title"
            subtitle="One sentence describing the page."
            actions={actionButtons}
        />

        <Card>
            <CardContent className="space-y-4 pt-6">
                {filtersForm}
                {tableContent}
                {paginationControl}
            </CardContent>
        </Card>
    </div>
</AdminLayout>
```

Use real page titles:

```txt
Dashboard -> Dashboard
Customers -> Customers
Subscriptions -> Subscriptions
Invoices -> Invoices
Receivables -> Tunggakan
Tickets -> Tickets
```

- [ ] **Step 2: Preserve exact filter payloads**

Keep these `router.get` payload keys unchanged:

```tsx
// Customers
router.get(route('admin.customers.index'), { search, type, is_active: isActive }, { preserveState: true });

// Tickets
router.get(route('admin.tickets.index'), { search, status, source, category_id: categoryId }, { preserveState: true });
```

For invoices, subscriptions, and receivables, copy their existing payload object exactly and only change layout/classes.

- [ ] **Step 3: Add empty states to mapped tables**

Where a page maps `rows.data.map(...)`, render this branch before mapping:

```tsx
{rows.data.length === 0 ? (
    <TR>
        <TD colSpan={columnCount} className="py-10 text-center text-muted-foreground">
            No data found.
        </TD>
    </TR>
) : (
    rows.data.map((row) => tableRow(row))
)}
```

Use `columnCount` as the actual number of visible `<TH>` cells on that page.

- [ ] **Step 4: Verify high-traffic lists compile**

Run:

```bash
rtk npm run typecheck
rtk npm run build
```

Expected: both commands pass.

- [ ] **Step 5: Commit high-traffic lists**

Run:

```bash
rtk git add resources/js/Pages/Admin/Dashboard/Index.tsx resources/js/Pages/Admin/Customers/Index.tsx resources/js/Pages/Admin/Subscriptions/Index.tsx resources/js/Pages/Admin/Billing/Invoices/Index.tsx resources/js/Pages/Admin/Billing/Receivables.tsx resources/js/Pages/Admin/Tickets/Index.tsx
rtk git commit -m "feat(ui): refresh primary admin lists"
```

Expected: commit succeeds.

---

### Task 5: high-traffic forms and detail pages

**Files:**
- Modify: `resources/js/Pages/Admin/Customers/Create.tsx`
- Modify: `resources/js/Pages/Admin/Customers/Edit.tsx`
- Modify: `resources/js/Pages/Admin/Customers/Show.tsx`
- Modify: `resources/js/Pages/Admin/Subscriptions/Show.tsx`
- Modify: `resources/js/Pages/Admin/Billing/Invoices/Create.tsx`
- Modify: `resources/js/Pages/Admin/Billing/Invoices/GenerateDialog.tsx`
- Modify: `resources/js/Pages/Admin/Billing/Invoices/Show.tsx`
- Modify: `resources/js/Pages/Admin/Tickets/Create.tsx`
- Modify: `resources/js/Pages/Admin/Tickets/Show.tsx`

**Interfaces:**
- Consumes: existing Inertia form hooks and route calls in each page.
- Produces: modern form/detail pages with unchanged request payload names, validation error rendering, modal behavior, and submit/delete actions.

- [ ] **Step 1: Apply form page skeleton**

For create/edit pages, use this structure and keep existing field names and submit handlers:

```tsx
<AdminLayout title="Page title">
    <div className="space-y-6">
        <PageHeader title="Page title" subtitle="Fill required fields, then save." />
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Details</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 md:grid-cols-2">
                    {fields}
                </CardContent>
            </Card>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={() => history.back()}>Cancel</Button>
                <Button type="submit" loading={processing}>Save</Button>
            </div>
        </form>
    </div>
</AdminLayout>
```

- [ ] **Step 2: Apply detail page skeleton**

For show pages, use this structure and keep existing action routes:

```tsx
<AdminLayout title="Page title">
    <div className="space-y-6">
        <PageHeader title="Page title" subtitle="Record details and related activity." actions={actions} />
        <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
            <Card>
                <CardHeader>
                    <CardTitle>Details</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {details}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Status</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {statusContent}
                </CardContent>
            </Card>
        </div>
        {relatedContent}
    </div>
</AdminLayout>
```

- [ ] **Step 3: Preserve validation errors**

For every field with an existing error value, pass it to the UI component using current prop names:

```tsx
<Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} />
<Select label="Status" value={data.status} onChange={(e) => setData('status', e.target.value)} error={errors.status}>
    {options}
</Select>
```

- [ ] **Step 4: Keep generate invoice modal behavior**

In `GenerateDialog.tsx`, keep existing open/close/submit props and route calls. Only change panel/footer/button classes to use `Modal`, `Card`, `Button`, `Input`, and token text classes.

- [ ] **Step 5: Verify high-traffic forms compile**

Run:

```bash
rtk npm run typecheck
rtk npm run build
```

Expected: both commands pass.

- [ ] **Step 6: Commit high-traffic forms**

Run:

```bash
rtk git add resources/js/Pages/Admin/Customers/Create.tsx resources/js/Pages/Admin/Customers/Edit.tsx resources/js/Pages/Admin/Customers/Show.tsx resources/js/Pages/Admin/Subscriptions/Show.tsx resources/js/Pages/Admin/Billing/Invoices/Create.tsx resources/js/Pages/Admin/Billing/Invoices/GenerateDialog.tsx resources/js/Pages/Admin/Billing/Invoices/Show.tsx resources/js/Pages/Admin/Tickets/Create.tsx resources/js/Pages/Admin/Tickets/Show.tsx
rtk git commit -m "feat(ui): refresh primary admin forms"
```

Expected: commit succeeds.

---

### Task 6: remaining admin pages

**Files:**
- Modify: every file listed under “Remaining admin pages”.

**Interfaces:**
- Consumes: refreshed shared UI and shell from Tasks 1-5.
- Produces: all remaining admin pages using the same page header/card/table/form visual pattern with unchanged routes, props, and form payloads.

- [ ] **Step 1: Convert remaining index pages**

For each remaining `Index.tsx` page, use this structure and keep current imports needed by the page:

```tsx
<AdminLayout title={title}>
    <div className="space-y-6">
        <PageHeader title={title} subtitle={subtitle} actions={actions} />
        <Card>
            <CardContent className="space-y-4 pt-6">
                {filters}
                {tableOrCards}
                {pagination}
            </CardContent>
        </Card>
    </div>
</AdminLayout>
```

Rules:

```txt
- Keep existing `router.get`, `router.post`, `router.put`, `router.patch`, `router.delete` calls unchanged.
- Keep existing `route('...')` names unchanged.
- Keep existing request payload keys unchanged.
- Replace inline headings with `PageHeader`.
- Replace loose table wrappers with `Card` + `Table`.
- Add empty table row when data array length is 0.
```

- [ ] **Step 2: Convert remaining create/edit pages**

For each remaining `Create.tsx` and `Edit.tsx` page, use the form skeleton from Task 5 and keep field names, `setData` keys, submit method, and route name unchanged.

- [ ] **Step 3: Convert remaining show/trace/report pages**

For `Show.tsx`, `Trace.tsx`, and report pages, use this structure:

```tsx
<AdminLayout title={title}>
    <div className="space-y-6">
        <PageHeader title={title} subtitle={subtitle} actions={actions} />
        <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
            {primaryCards}
            {sideCards}
        </div>
        {wideTablesOrSections}
    </div>
</AdminLayout>
```

For report pages with only one table, use:

```tsx
<Card>
    <CardContent className="space-y-4 pt-6">
        {filters}
        {table}
    </CardContent>
</Card>
```

- [ ] **Step 4: Verify remaining pages compile**

Run:

```bash
rtk npm run typecheck
rtk npm run build
```

Expected: both commands pass.

- [ ] **Step 5: Commit remaining pages**

Run:

```bash
rtk git add resources/js/Pages/Admin
rtk git commit -m "feat(ui): refresh remaining admin pages"
```

Expected: commit succeeds.

---

### Task 7: component gallery and final verification

**Files:**
- Modify: `resources/js/Pages/Admin/Components.tsx`
- Read during browser verification: generated app pages in browser.

**Interfaces:**
- Consumes: final shared UI components from Tasks 1-6.
- Produces: component gallery reflecting final primitives and final verification results.

- [ ] **Step 1: Update component gallery sections**

In `resources/js/Pages/Admin/Components.tsx`, keep the existing `sections` array and update demos to show final variants:

```tsx
<Button>Default</Button>
<Button variant="secondary">Secondary</Button>
<Button variant="outline">Outline</Button>
<Button variant="ghost">Ghost</Button>
<Button variant="destructive">Destructive</Button>
<Button loading>Loading</Button>
<Button disabled>Disabled</Button>
```

Keep demos for `Input`, `Textarea`, `Select`, `Checkbox`, `Switch`, `RadioGroup`, `Badge`, `Avatar`, `Card`, `StatCard`, `Table`, `Breadcrumb`, `Pagination`, `Tabs`, `Modal`, `Dropdown`, `Tooltip`, `Alert`, `EmptyState`, `Spinner`, and `Skeleton`.

- [ ] **Step 2: Run final static checks**

Run:

```bash
rtk npm run typecheck
rtk npm run build
```

Expected: both commands pass.

- [ ] **Step 3: Run browser smoke checks**

Use the app in browser and verify these pages render without console errors and keep basic actions visible:

```txt
/admin/dashboard
/admin/customers
/admin/invoices
/admin/tickets
/admin/customers/create
/admin/components
```

Expected:

```txt
- Sidebar visible on desktop.
- Topbar visible.
- Dark mode toggle works.
- Tables render rows or empty state.
- Create buttons visible where permissions allow.
- One create form shows inputs with labels.
- Browser console has no runtime errors.
```

- [ ] **Step 4: Run PHP tests only if route/form behavior changed**

If any task changed a route name, request payload key, controller-facing form field, or backend behavior, run:

```bash
rtk php artisan test
```

Expected: tests pass.

If only visual classes/layout changed, skip PHP tests and record:

```txt
Skipped PHP tests: UI-only changes, no route/form/backend behavior changed.
```

- [ ] **Step 5: Commit gallery and verification cleanup**

Run:

```bash
rtk git add resources/js/Pages/Admin/Components.tsx
rtk git commit -m "feat(ui): update component gallery"
```

Expected: commit succeeds.

- [ ] **Step 6: Final status check**

Run:

```bash
rtk git status --short
```

Expected: only pre-existing `.claude/` untracked files remain, or working tree clean if those files were handled separately.
