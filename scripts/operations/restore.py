#!/usr/bin/env python3
"""Restore helper for encrypted MySQL + media backups."""

from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable

PROTECTED_DB_NAMES = {"information_schema", "mysql", "performance_schema", "sys"}


def eprint(message: str) -> None:
    print(message, file=sys.stderr)


def fail(message: str, code: int = 1) -> None:
    eprint(message)
    raise SystemExit(code)


def norm_path(raw: str) -> Path:
    return Path(raw).expanduser().resolve()


def ensure_nonempty(value: str | None, label: str) -> str:
    if value is None or not str(value).strip():
        fail(f"{label} is required")
    return str(value).strip()


def must_exist(path: Path, label: str) -> Path:
    if not path.exists():
        fail(f"{label} does not exist: {path}")
    return path


def must_be_empty_dir(path: Path, label: str) -> Path:
    if path.exists():
        if not path.is_dir():
            fail(f"{label} must be a directory: {path}")
        if any(path.iterdir()):
            fail(f"{label} must be empty: {path}")
    else:
        path.mkdir(parents=True, exist_ok=False)
    return path


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


def checksum_hex(path: Path) -> str:
    import hashlib

    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def verify_checksums(checksum_file: Path, db_archive: Path, media_archive: Path) -> None:
    expected = {}
    for raw_line in checksum_file.read_text(encoding="utf-8").splitlines():
        if not raw_line.strip():
            continue
        digest, filename = raw_line.split(maxsplit=1)
        expected[filename] = digest
    actual = {
        db_archive.name: checksum_hex(db_archive),
        media_archive.name: checksum_hex(media_archive),
    }
    for filename, digest in actual.items():
        if expected.get(filename) != digest:
            fail(f"checksum mismatch for {filename}")


def self_check() -> None:
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        source = root / "source.db.enc"
        source.write_text("x", encoding="utf-8")
        target = root / "target"
        target.mkdir()
        (target / "asset.txt").write_text("x", encoding="utf-8")

        try:
            must_be_empty_dir(target, "target media dir")
        except SystemExit:
            pass
        else:
            fail("self-check failed: non-empty target directory was accepted")

        protected = PROTECTED_DB_NAMES
        if "mysql" not in protected:
            fail("self-check failed: protected db set missing mysql")

        print("restore self-check ok")


def parse_args(argv: Iterable[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--db-archive", default=os.getenv("RESTORE_DB_ARCHIVE"))
    parser.add_argument("--media-archive", default=os.getenv("RESTORE_MEDIA_ARCHIVE"))
    parser.add_argument("--checksum-file", default=os.getenv("RESTORE_CHECKSUM_FILE"))
    parser.add_argument("--target-db-name", default=os.getenv("RESTORE_TARGET_DB_NAME"))
    parser.add_argument("--target-media-dir", default=os.getenv("RESTORE_TARGET_MEDIA_DIR"))
    parser.add_argument("--db-host", default=os.getenv("BACKUP_DB_HOST"))
    parser.add_argument("--db-port", default=os.getenv("BACKUP_DB_PORT", "3306"))
    parser.add_argument("--db-user", default=os.getenv("BACKUP_DB_USER"))
    parser.add_argument("--db-password-file", default=os.getenv("BACKUP_DB_PASSWORD_FILE"))
    parser.add_argument("--encryption-key-file", default=os.getenv("BACKUP_ENCRYPTION_KEY_FILE"))
    parser.add_argument("--source-db-name", default=os.getenv("RESTORE_SOURCE_DB_NAME"))
    parser.add_argument("--cleanup-after-verify", action="store_true")
    parser.add_argument("--proof-file", default=os.getenv("RESTORE_PROOF_FILE"))
    parser.add_argument("--self-check", action="store_true")
    return parser.parse_args(list(argv))


def restore_media(archive: Path, target_dir: Path, key_file: Path) -> None:
    key = read_secret_file(key_file)
    with tempfile.NamedTemporaryFile(prefix="restore-secret-", delete=False) as tmp:
        tmp.write(key.encode("utf-8"))
        tmp_path = Path(tmp.name)
    scratch = Path(tempfile.mkdtemp(prefix="restore-work-"))
    try:
        decrypted = scratch / archive.name.replace(".enc", "")
        run_command(
            [
                "openssl",
                "enc",
                "-d",
                "-aes-256-cbc",
                "-pbkdf2",
                "-salt",
                "-pass",
                f"file:{tmp_path}",
                "-in",
                str(archive),
                "-out",
                str(decrypted),
            ]
        )
        gzipped = scratch / decrypted.name.replace(".tar.gz", ".tar")
        with gzipped.open("wb") as handle:
            subprocess.run(["gzip", "-dc", str(decrypted)], check=True, stdout=handle, stderr=subprocess.PIPE)
        run_command(["tar", "-xf", str(gzipped), "-C", str(target_dir)])
    finally:
        try:
            shutil.rmtree(scratch)
        except OSError:
            pass
        try:
            tmp_path.unlink(missing_ok=True)
        except OSError:
            pass


def restore_db(archive: Path, db_name: str, host: str, port: str, user: str, password_file: Path, key_file: Path) -> None:
    password = read_secret_file(password_file)
    key = read_secret_file(key_file)
    with tempfile.NamedTemporaryFile(prefix="restore-secret-", delete=False) as tmp:
        tmp.write(key.encode("utf-8"))
        key_path = Path(tmp.name)
    scratch = Path(tempfile.mkdtemp(prefix="restore-db-"))
    try:
        decrypted = scratch / archive.name.replace(".enc", "")
        run_command(
            [
                "openssl",
                "enc",
                "-d",
                "-aes-256-cbc",
                "-pbkdf2",
                "-salt",
                "-pass",
                f"file:{key_path}",
                "-in",
                str(archive),
                "-out",
                str(decrypted),
            ]
        )
        env = {**os.environ, "MYSQL_PWD": password, "MYSQL_HOST": host, "MYSQL_PORT": str(port), "MYSQL_USER": user}
        run_command(
            [
                "mysql",
                "--host",
                host,
                "--port",
                str(port),
                "--user",
                user,
                db_name,
            ],
            stdin_path=decrypted,
            env=env,
        )
    finally:
        try:
            shutil.rmtree(scratch)
        except OSError:
            pass
        try:
            key_path.unlink(missing_ok=True)
        except OSError:
            pass


def cleanup_target(target_db_name: str, target_media_dir: Path, host: str, port: str, user: str, password_file: Path) -> None:
    password = read_secret_file(password_file)
    env = {**os.environ, "MYSQL_PWD": password, "MYSQL_HOST": host, "MYSQL_PORT": str(port), "MYSQL_USER": user}
    run_command(
        [
            "mysql",
            "--host",
            host,
            "--port",
            str(port),
            "--user",
            user,
            "--execute",
            f"DROP DATABASE IF EXISTS `{target_db_name}`;",
        ],
        env=env,
    )
    for child in list(target_media_dir.iterdir()):
        if child.is_dir():
            shutil.rmtree(child)
        else:
            child.unlink()


def write_proof(path: Path, payload: dict[str, str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def main(argv: Iterable[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    if args.self_check:
        self_check()
        return 0

    db_archive = norm_path(ensure_nonempty(args.db_archive, "db archive"))
    media_archive = norm_path(ensure_nonempty(args.media_archive, "media archive"))
    checksum_file = norm_path(ensure_nonempty(args.checksum_file, "checksum file"))
    target_db_name = ensure_nonempty(args.target_db_name, "target db name")
    target_media_dir = norm_path(ensure_nonempty(args.target_media_dir, "target media dir"))
    db_host = ensure_nonempty(args.db_host, "db host")
    db_port = ensure_nonempty(args.db_port, "db port")
    db_user = ensure_nonempty(args.db_user, "db user")
    db_password_file = norm_path(ensure_nonempty(args.db_password_file, "db password file"))
    encryption_key_file = norm_path(ensure_nonempty(args.encryption_key_file, "encryption key file"))
    source_db_name = ensure_nonempty(args.source_db_name, "source db name")
    proof_file = norm_path(args.proof_file) if args.proof_file else db_archive.with_suffix(".restore-proof.json")

    if target_db_name in PROTECTED_DB_NAMES:
        fail(f"target db is protected: {target_db_name}")
    if target_db_name == source_db_name:
        fail("target db name must not equal the source db name")
    must_exist(db_archive, "db archive")
    must_exist(media_archive, "media archive")
    must_exist(checksum_file, "checksum file")
    must_be_empty_dir(target_media_dir, "target media dir")
    verify_checksums(checksum_file, db_archive, media_archive)

    if shutil.which("openssl") is None:
        fail("missing required command: openssl")
    if shutil.which("mysql") is None:
        fail("missing required command: mysql")
    if shutil.which("tar") is None:
        fail("missing required command: tar")

    restore_media(media_archive, target_media_dir, encryption_key_file)
    restore_db(db_archive, target_db_name, db_host, db_port, db_user, db_password_file, encryption_key_file)

    proof = {
        "source_db_name": source_db_name,
        "target_db_name": target_db_name,
        "target_media_dir": str(target_media_dir),
        "db_archive": str(db_archive),
        "media_archive": str(media_archive),
        "checksum_file": str(checksum_file),
        "verified_at": datetime.now(timezone.utc).isoformat(),
        "cleanup_after_verify": str(bool(args.cleanup_after_verify)).lower(),
    }
    if args.cleanup_after_verify:
        cleanup_target(target_db_name, target_media_dir, db_host, db_port, db_user, db_password_file)
        proof["cleanup_proved"] = "true"
    else:
        proof["cleanup_proved"] = "false"

    write_proof(proof_file, proof)
    print(proof_file)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
