#!/usr/bin/env python3
"""Non-destructive contract checks for backup and restore tooling."""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path
from typing import Iterable

ROOT = Path(__file__).resolve().parents[2]
BACKUP = ROOT / "scripts" / "operations" / "backup.py"
RESTORE = ROOT / "scripts" / "operations" / "restore.py"


def run_checked(argv: list[str]) -> None:
    subprocess.run(argv, check=True)


def main(argv: Iterable[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(list(argv or sys.argv[1:]))

    if args.self_check:
        run_checked([sys.executable, str(BACKUP), "--self-check"])
        run_checked([sys.executable, str(RESTORE), "--self-check"])
        print("verify-backup-restore self-check ok")
        return 0

    parser.error("use --self-check for the non-destructive verification wrapper")
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
