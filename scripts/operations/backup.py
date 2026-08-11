#!/usr/bin/env python3
"""Encrypted off-host backup helper for MySQL + media."""

from __future__ import annotations

import argparse
import gzip
import json
import os
import shutil
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable

PROTECTED_DB_NAMES = {"information_schema", "mysql", "performance_schema", "sys"}
PROTECTED_PATHS = {Path("/")}


@dataclass(frozen=True)
class BackupPaths:
    dest_dir: Path
    bundle_dir: Path
    db_archive: Path
    media_archive: Path
    checksum_file: Path
    manifest_file: Path


def eprint(message: str) -> None:
    print(message, file=sys.stderr)


def fail(message: str, code: int = 1) -> None:
    eprint(message)
    raise SystemExit(code)


def now_token() -> str:
    return datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")


def norm_path(raw: str) -> Path:
    return Path(raw).expanduser().resolve()


def ensure_nonempty(value: str | None, label: str) -> str:
    if value is None or not str(value).strip():
        fail(f"{label} is required")
    return str(value).strip()


def refuse_protected_path(path: Path, label: str) -> None:
    if path in PROTECTED_PATHS:
        fail(f"{label} refuses protected path: {path}")


def refuse_blank_or_existing_output(path: Path, label: str) -> None:
    if path.exists():
        fail(f"{label} already exists: {path}")


def must_exist(path: Path, label: str) -> Path:
    if not path.exists():
        fail(f"{label} does not exist: {path}")
    return path


def must_be_directory(path: Path, label: str) -> Path:
    must_exist(path, label)
    if not path.is_dir():
        fail(f"{label} must be a directory: {path}")
    return path


def checksum_hex(path: Path) -> str:
    import hashlib

    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def write_bytes(path: Path, data: bytes) -> None:
    path.write_bytes(data)


def run_command(argv: list[str], *, stdin_path: Path | None = None, stdout_path: Path | None = None, env: dict[str, str] | None = None) -> None:
    stdin_handle = stdin_path.open("rb") if stdin_path else None
    stdout_handle = stdout_path.open("wb") if stdout_path else None
    try:
        subprocess.run(
            argv,
            check=True,
            stdin=stdin_handle,
            stdout=stdout_handle,
            stderr=subprocess.PIPE,
            env=env,
        )
    except FileNotFoundError as exc:
        fail(f"missing required command: {argv[0]} ({exc})")
    except subprocess.CalledProcessError as exc:
        stderr = exc.stderr.decode("utf-8", errors="replace") if exc.stderr else ""
        fail(f"command failed: {' '.join(argv)}\n{stderr}".rstrip())
    finally:
        if stdin_handle:
            stdin_handle.close()
        if stdout_handle:
            stdout_handle.close()


def read_secret_file(path: Path) -> str:
    must_exist(path, "secret file")
    value = path.read_text(encoding="utf-8").strip()
    if not value:
        fail(f"secret file is empty: {path}")
    return value


def build_paths(dest_dir: Path, stem: str, tier: str) -> BackupPaths:
    bundle_dir = dest_dir / f"{stem}.{tier}"
    return BackupPaths(
        dest_dir=dest_dir,
        bundle_dir=bundle_dir,
        db_archive=bundle_dir / f"{stem}.sql.gz.enc",
        media_archive=bundle_dir / f"{stem}.media.tar.gz.enc",
        checksum_file=bundle_dir / f"{stem}.sha256",
        manifest_file=bundle_dir / f"{stem}.manifest.json",
    )


def gzip_file(source: Path, target: Path) -> None:
    with source.open("rb") as src, gzip.open(target, "wb") as dst:
        shutil.copyfileobj(src, dst)


def encrypt_file(source: Path, target: Path, secret_file: Path) -> None:
    refuse_blank_or_existing_output(target, "archive output")
    target.parent.mkdir(parents=True, exist_ok=True)
    key = read_secret_file(secret_file)
    with tempfile.NamedTemporaryFile(prefix="backup-secret-", delete=False) as tmp:
        tmp.write(key.encode("utf-8"))
        tmp_path = Path(tmp.name)
    try:
        run_command(
            [
                "openssl",
                "enc",
                "-aes-256-cbc",
                "-pbkdf2",
                "-salt",
                "-pass",
                f"file:{tmp_path}",
                "-in",
                str(source),
                "-out",
                str(target),
            ]
        )
    finally:
        try:
            tmp_path.unlink(missing_ok=True)
        except OSError:
            pass


def build_manifest(payload: dict[str, str | int]) -> bytes:
    return json.dumps(payload, indent=2, sort_keys=True).encode("utf-8") + b"\n"


def prune_retention(dest_dir: Path, stem: str, tier: str, retain_days: int) -> None:
    if retain_days <= 0:
        return
    cutoff = datetime.now(timezone.utc).timestamp() - retain_days * 86400
    prefix = f"{stem}.{tier}."
    for child in dest_dir.iterdir():
        if not child.is_dir() or not child.name.startswith(prefix):
            continue
        if child.stat().st_mtime < cutoff:
            shutil.rmtree(child)


def self_check() -> None:
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        dest = root / "offhost"
        dest.mkdir()
        source = root / "source"
        source.mkdir()
        media = source / "media"
        media.mkdir()
        (media / "asset.txt").write_text("x", encoding="utf-8")
        secret = root / "secret.txt"
        secret.write_text("secret", encoding="utf-8")

        try:
            refuse_protected_path(Path("/"), "destination")
        except SystemExit:
            pass
        else:
            fail("self-check failed: protected path was accepted")

        occupied = dest / "bundle" / "file.enc"
        occupied.parent.mkdir(parents=True, exist_ok=True)
        occupied.write_text("x", encoding="utf-8")
        try:
            refuse_blank_or_existing_output(occupied, "archive output")
        except SystemExit:
            pass
        else:
            fail("self-check failed: pre-existing output check did not run")

        print("backup self-check ok")


def parse_args(argv: Iterable[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-db", default=os.getenv("BACKUP_DB_NAME"))
    parser.add_argument("--source-media-dir", default=os.getenv("BACKUP_MEDIA_DIR"))
    parser.add_argument("--dest-dir", default=os.getenv("BACKUP_DEST_DIR"))
    parser.add_argument("--db-host", default=os.getenv("BACKUP_DB_HOST"))
    parser.add_argument("--db-port", default=os.getenv("BACKUP_DB_PORT", "3306"))
    parser.add_argument("--db-user", default=os.getenv("BACKUP_DB_USER"))
    parser.add_argument("--db-password-file", default=os.getenv("BACKUP_DB_PASSWORD_FILE"))
    parser.add_argument("--encryption-key-file", default=os.getenv("BACKUP_ENCRYPTION_KEY_FILE"))
    parser.add_argument("--tier", choices=("daily", "monthly"), default="daily")
    parser.add_argument("--retain-days", type=int, default=30)
    parser.add_argument("--protect-source-prefix", default="")
    parser.add_argument("--self-check", action="store_true")
    return parser.parse_args(list(argv))


def main(argv: Iterable[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    if args.self_check:
        self_check()
        return 0

    source_db = ensure_nonempty(args.source_db, "source db")
    source_media_dir = must_be_directory(norm_path(ensure_nonempty(args.source_media_dir, "source media dir")), "source media dir")
    dest_dir = norm_path(ensure_nonempty(args.dest_dir, "destination dir"))
    db_host = ensure_nonempty(args.db_host, "db host")
    db_port = ensure_nonempty(args.db_port, "db port")
    db_user = ensure_nonempty(args.db_user, "db user")
    db_password_file = norm_path(ensure_nonempty(args.db_password_file, "db password file"))
    encryption_key_file = norm_path(ensure_nonempty(args.encryption_key_file, "encryption key file"))

    if source_db in PROTECTED_DB_NAMES:
        fail(f"source db is protected: {source_db}")
    if args.protect_source_prefix and source_db.startswith(args.protect_source_prefix):
        fail(f"source db matches protected prefix: {source_db}")
    refuse_protected_path(dest_dir, "destination dir")
    if dest_dir == source_media_dir or str(dest_dir).startswith(str(source_media_dir) + os.sep):
        fail("destination dir must be outside the source media tree")

    if not db_password_file.exists():
        fail(f"db password file does not exist: {db_password_file}")
    if not encryption_key_file.exists():
        fail(f"encryption key file does not exist: {encryption_key_file}")

    if shutil.which("mysqldump") is None:
        fail("missing required command: mysqldump")
    if shutil.which("openssl") is None:
        fail("missing required command: openssl")

    dest_dir.mkdir(parents=True, exist_ok=True)
    stem = f"{source_db}.{args.tier}.{now_token()}"
    paths = build_paths(dest_dir, stem, args.tier)
    paths.bundle_dir.mkdir(parents=True, exist_ok=False)

    mysql_defaults = {
        "MYSQL_PWD": db_password_file.read_text(encoding="utf-8").strip(),
        "MYSQL_HOST": db_host,
        "MYSQL_PORT": str(db_port),
        "MYSQL_USER": db_user,
    }

    sql_dump = paths.bundle_dir / f"{stem}.sql"
    media_tar = paths.bundle_dir / f"{stem}.media.tar"
    try:
        run_command(
            [
                "mysqldump",
                "--single-transaction",
                "--quick",
                "--routines",
                "--triggers",
                "--events",
                "--set-gtid-purged=OFF",
                "--no-tablespaces",
                "--host",
                db_host,
                "--port",
                str(db_port),
                "--user",
                db_user,
                source_db,
            ],
            stdout_path=sql_dump,
            env={**os.environ, **mysql_defaults},
        )
        run_command(["tar", "-cf", str(media_tar), "-C", str(source_media_dir), "."])
        sql_gz = paths.bundle_dir / f"{stem}.sql.gz"
        media_gz = paths.bundle_dir / f"{stem}.media.tar.gz"
        refuse_blank_or_existing_output(sql_gz, "compressed sql output")
        refuse_blank_or_existing_output(media_gz, "compressed media output")
        gzip_file(sql_dump, sql_gz)
        gzip_file(media_tar, media_gz)
        encrypt_file(sql_gz, paths.db_archive, encryption_key_file)
        encrypt_file(media_gz, paths.media_archive, encryption_key_file)
        db_checksum = checksum_hex(paths.db_archive)
        media_checksum = checksum_hex(paths.media_archive)
        checksums = build_manifest(
            {
                "source_db": source_db,
                "source_media_dir": str(source_media_dir),
                "dest_dir": str(dest_dir),
                "tier": args.tier,
                "created_at": now_token(),
                "db_archive": paths.db_archive.name,
                "db_sha256": db_checksum,
                "media_archive": paths.media_archive.name,
                "media_sha256": media_checksum,
            }
        )
        write_bytes(paths.checksum_file, f"{db_checksum}  {paths.db_archive.name}\n{media_checksum}  {paths.media_archive.name}\n".encode("utf-8"))
        write_bytes(paths.manifest_file, checksums)
        prune_retention(dest_dir, source_db, args.tier, args.retain_days)
    finally:
        for temp_file in (sql_dump, media_tar):
            try:
                temp_file.unlink(missing_ok=True)
            except OSError:
                pass

    print(paths.bundle_dir)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
