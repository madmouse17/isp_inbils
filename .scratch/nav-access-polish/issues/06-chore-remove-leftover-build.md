# CHORE: remove public/.build-root-owned-20261012

Status: done
Role: orchestrator
Blocked by: none
Spec: `../spec.md`

## Scope

- Delete the directory and its `.git/info/exclude` line.

## Acceptance criteria

- [x] Directory gone, git status clean.

## Comments

- 2026-10-12 (orchestrator): the files were root-owned (a host `rm -rf` was refused), so the directory was removed as root inside the Sail container (`sail exec -u root laravel.test rm -rf /var/www/html/public/.build-root-owned-20261012`). `ls -d public/.build-root-owned-20261012` → "No such file or directory"; `git status --untracked-files=all` lists nothing for it. The `/public/.build-root-owned-*/` line was removed from `.git/info/exclude`.
