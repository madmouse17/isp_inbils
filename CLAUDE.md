<!-- rtk-instructions v2 -->
# RTK (Rust Token Killer) - Token-Optimized Commands

## Golden Rule

**Always prefix commands with `rtk`**. If RTK has a dedicated filter, it uses it. If not, it passes through unchanged. This means RTK is always safe to use.

**Important**: Even in command chains with `&&`, use `rtk`:
```bash
# ❌ Wrong
git add . && git commit -m "msg" && git push

# ✅ Correct
rtk git add . && rtk git commit -m "msg" && rtk git push
```

## RTK Commands by Workflow

### Build & Compile (80-90% savings)
```bash
rtk cargo build         # Cargo build output
rtk cargo check         # Cargo check output
rtk cargo clippy        # Clippy warnings grouped by file (80%)
rtk tsc                 # TypeScript errors grouped by file/code (83%)
rtk lint                # ESLint/Biome violations grouped (84%)
rtk prettier --check    # Files needing format only (70%)
rtk next build          # Next.js build with route metrics (87%)
```

### Test (60-99% savings)
```bash
rtk cargo test          # Cargo test failures only (90%)
rtk go test             # Go test failures only (90%)
rtk jest                # Jest failures only (99.5%)
rtk vitest              # Vitest failures only (99.5%)
rtk playwright test     # Playwright failures only (94%)
rtk pytest              # Python test failures only (90%)
rtk rake test           # Ruby test failures only (90%)
rtk rspec               # RSpec test failures only (60%)
rtk test <cmd>          # Generic test wrapper - failures only
```

### Git (59-80% savings)
```bash
rtk git status          # Compact status
rtk git log             # Compact log (works with all git flags)
rtk git diff            # Compact diff (80%)
rtk git show            # Compact show (80%)
rtk git add             # Ultra-compact confirmations (59%)
rtk git commit          # Ultra-compact confirmations (59%)
rtk git push            # Ultra-compact confirmations
rtk git pull            # Ultra-compact confirmations
rtk git branch          # Compact branch list
rtk git fetch           # Compact fetch
rtk git stash           # Compact stash
rtk git worktree        # Compact worktree
```

Note: Git passthrough works for ALL subcommands, even those not explicitly listed.

### GitHub (26-87% savings)
```bash
rtk gh pr view <num>    # Compact PR view (87%)
rtk gh pr checks        # Compact PR checks (79%)
rtk gh run list         # Compact workflow runs (82%)
rtk gh issue list       # Compact issue list (80%)
rtk gh api              # Compact API responses (26%)
```

### JavaScript/TypeScript Tooling (70-90% savings)
```bash
rtk pnpm list           # Compact dependency tree (70%)
rtk pnpm outdated       # Compact outdated packages (80%)
rtk pnpm install        # Compact install output (90%)
rtk npm run <script>    # Compact npm script output
rtk npx <cmd>           # Compact npx command output
rtk prisma              # Prisma without ASCII art (88%)
```

### Files & Search (60-75% savings)
```bash
rtk ls <path>           # Tree format, compact (65%)
rtk read <file>         # Code reading with filtering (60%)
rtk grep <pattern>      # Search grouped by file (75%). Format flags (-c, -l, -L, -o, -Z) run raw.
rtk find <pattern>      # Find grouped by directory (70%)
```

### Analysis & Debug (70-90% savings)
```bash
rtk err <cmd>           # Filter errors only from any command
rtk log <file>          # Deduplicated logs with counts
rtk json <file>         # JSON structure without values
rtk deps                # Dependency overview
rtk env                 # Environment variables compact
rtk summary <cmd>       # Smart summary of command output
rtk diff                # Ultra-compact diffs
```

### Infrastructure (85% savings)
```bash
rtk docker ps           # Compact container list
rtk docker images       # Compact image list
rtk docker logs <c>     # Deduplicated logs
rtk kubectl get         # Compact resource list
rtk kubectl logs        # Deduplicated pod logs
```

### Network (65-70% savings)
```bash
rtk curl <url>          # Compact HTTP responses (70%)
rtk wget <url>          # Compact download output (65%)
```

### Meta Commands
```bash
rtk gain                # View token savings statistics
rtk gain --history      # View command history with savings
rtk discover            # Analyze Claude Code sessions for missed RTK usage
rtk proxy <cmd>         # Run command without filtering (for debugging)
rtk init                # Add RTK instructions to CLAUDE.md
rtk init --global       # Add RTK to ~/.claude/CLAUDE.md
```

## Token Savings Overview

| Category | Commands | Typical Savings |
|----------|----------|-----------------|
| Tests | vitest, playwright, cargo test | 90-99% |
| Build | next, tsc, lint, prettier | 70-87% |
| Git | status, log, diff, add, commit | 59-80% |
| GitHub | gh pr, gh run, gh issue | 26-87% |
| Package Managers | pnpm, npm, npx | 70-90% |
| Files | ls, read, grep, find | 60-75% |
| Infrastructure | docker, kubectl | 85% |
| Network | curl, wget | 65-70% |

Overall average: **60-90% token reduction** on common development operations.
<!-- /rtk-instructions -->

## AI Temporary Artifact Cleanup (MANDATORY)

- Store temporary scan/debug/extraction/patch files outside the repository whenever possible.
- Delete AI-generated temporary artifacts immediately after their purpose is complete.
- AI evidence/dumps are temporary: delete them immediately after inspection or once no longer needed. Retain them only when explicitly required as a permanent deliverable.
- Before finishing, inspect Git status and remove only obsolete artifacts created by the current task.
- Preserve source code, official tests, migrations, project documentation, required evidence, and other active work.

## Docker Runtime Refresh (MANDATORY)

- Local verification uses Laravel Sail: `./vendor/bin/sail up -d`, then check `/up` and `/ready` on `http://localhost`. Source is bind-mounted (no rebuild); run `./vendor/bin/sail npm run build` when frontend assets change.
- Deploy images (`compose.yaml` + `Dockerfile`): after implementation work, when the Docker stack is available, rebuild the image and recreate only affected services so the running environment uses the latest source.
- Run `docker compose --env-file .env.docker ps` and verify `/up` and `/ready` (port 8080) before declaring completion.
- Never remove named volumes (`down -v`, `docker volume rm`, or `docker system prune --volumes`) unless the user explicitly requests data destruction.
<!-- antislop-inbils:start -->
## antislop inbils
Untuk perubahan rendered UI/UX, gunakan mode DURING tanpa menanyakan mode lagi. Muat `antislop` + `antislop-ui`; tambah `antislop-human` untuk interaction/forms/data views, `antislop-layoutmobile` untuk responsive work, dan `antislop-copywriting` hanya jika user-facing copy berubah. Mode AFTER hanya atas permintaan audit eksplisit dan read-only sampai pengguna menyetujui perubahan.
Precedence: instruksi pengguna dan ADR/docs proyek; Superpowers workflow; Laravel/Inertia/Tailwind 3/shadcn-Radix implementation rules; tokens dan vocabulary komponen existing; Anti-slop sebagai filter; contoh generik skill lain.
Enterprise overrides: familiarity dan consistency menang atas novelty; density desktop boleh; contained horizontal table scroll boleh; 44px diprioritaskan touch/mobile; breakpoint Tailwind existing; Lucide tetap library proyek; ketiadaan DESIGN.md tidak menahan perubahan kecil karena app.css, tailwind.config.js, docs/ui/design-system.md, dan incumbent UI adalah visual authority.
Muat antislop-code hanya saat menulis atau mengubah komentar; jangan hapus komentar business rule, architecture, security, concurrency, protocol, API contract, compliance, workaround, edge case, atau licensing.
<!-- antislop-inbils:end -->

## Agent skills

### Issue tracker

Issues and specs live as local markdown under `.scratch/<feature>/` (spec.md + `issues/NN-*.md`); the OMP ledger `docs/plans/INBILS_OMP_BACKLOG.md` stays the status checkpoint. See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context: domain docs in `docs/architecture/*` and `docs/business/*`, ADRs in `docs/architecture/decisions/`. See `docs/agents/domain.md`.
