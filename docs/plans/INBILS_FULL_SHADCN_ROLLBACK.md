# INBILS Full shadcn Migration — Rollback

## 1. Principles

1. Batches serial → roll back **one batch at a time**, newest first.  
2. Prefer `git checkout -- <paths>` / `git restore` for intentional batch files only. Shared dirty worktree: never `git reset --hard` unless human explicitly owns full tree.  
3. If `package.json` / lockfile changed, restore both + reinstall.  
4. Re-run gates after every rollback: `npm run typecheck`, `npm run lint`, `npm run build`.  
5. Evidence stays under `.hermes-evidence/shadcn-audit/`; do not delete baseline logs (needed for comparison).

## 2. Pre-batch snapshot checklist

Before each batch starts, implementer records:

- Branch name + `git status --short` summary  
- File list expected to touch  
- Whether lockfile will change  
- Current gate results (pass/fail)  

Optional safety:  
`git stash push -u -m "pre-shadcn-batch-N"` **only if human approves** (stash can hide unrelated WIP). Prefer explicit path restores.

## 3. Rollback by batch

### Batch 0 — foundation

**Likely paths**

- `components.json`  
- `package.json`, `package-lock.json`  
- `resources/css/app.css`  
- `tailwind.config.js`  
- `resources/js/lib/utils.ts`  
- `resources/js/Components/ui/index.ts`  
- any newly added registry files under `resources/js/Components/ui/`  
- Toast/FileUpload fixes if applied  

**Steps**

1. Restore listed files from last known good commit/WIP state.  
2. Remove untracked registry files added in batch if not wanted.  
3. `npm install`  
4. Gates.  
5. Mark Batch 0 card blocked with reason if foundation unstable.

### Batches 1–5 — primitives / forms / overlays / table

**Steps**

1. Identify files from batch handoff `changed_files`.  
2. `git restore -- <those paths>` (or checkout from pre-batch commit if batch isolated).  
3. Restore barrel `ui/index.ts` if half-migrated.  
4. `npm install` if deps added.  
5. Gates.  
6. Smoke affected pages (forms/dialogs/tables).

### Batch 6 — SearchSelect

High complexity. If broken:

1. Restore `SearchSelect.tsx` + consumers from pre-batch.  
2. Remove unused `command`/`popover` files only if nothing else depends on them.  
3. Gates + search fields on 2 pages.

### Batch 7 — Sidebar/shell

1. Restore `Sidebar.tsx`, `Topbar.tsx`, `Breadcrumb.tsx`, `Layouts/AdminLayout.tsx` (and related).  
2. Confirm nav permissions still render.  
3. Gates + manual nav click-through.

### Batch 8–9 — cleanup/docs

Low data risk. Restore deleted wrappers only if call sites still need them.

## 4. Dependency rollback

If CLI added packages:

```bash
git restore package.json package-lock.json
npm install
```

If install tree corrupted:

```bash
rm -rf node_modules
npm install
```

Do not hand-edit lockfile.

## 5. Partial-failure patterns

| Symptom | Action |
|---|---|
| typecheck fails only on barrel | Restore `ui/index.ts`; fix exports before continuing |
| Visual only regression | Keep code; fix variants; no full rollback unless UX blocks |
| Dialog focus trap broken | Restore Modal/Dialog files for batch 4 |
| Pagination drops query string | Restore Pagination + page consumers |
| Build fails mid-batch | Stop next work; rollback batch or fix forward within scope |
| Unrelated WIP mixed in diff | Restore only batch paths; leave foreign files alone |

## 6. Data / backend

UI-only migration. No DB migrations expected.  
If a batch accidentally changed PHP/routes: restore those paths immediately; treat as incident.

## 7. Communication

On rollback:

1. Kanban comment: batch id, reason, files restored, gate results  
2. Update matrix row status if component reverted  
3. Do not mark parent program complete until gates green and matrix final  

## 8. Emergency full UI kit revert

Only with human approval:

1. List all `resources/js/Components/ui` diffs vs last known good tag/commit  
2. Restore entire `ui/`, `composite/` imports if needed, `components.json`, CSS tokens, package files  
3. Full reinstall + gates  
4. Disable further batch cards until re-plan  

## 9. Acceptance after rollback

- [ ] App boots (`npm run dev` or build artifacts)  
- [ ] typecheck matches pre-batch or better  
- [ ] Critical admin pages render  
- [ ] No half-registry/half-custom broken imports  
- [ ] Card status reflects reality (blocked/ready), not silent fail  
