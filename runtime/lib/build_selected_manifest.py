#!/usr/bin/env python3
"""Build a manifest from WebDAV-authorized Nextcloud file IDs.

The selected roots are resolved through a generic Nextcloud file view and then
mapped through configured storage adapters. No user name, path layout or storage
protocol is assumed here.
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path, PurePosixPath

IDENTIFIER = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$")


def validate_view(name: str) -> str:
    value = str(name).strip()
    if not IDENTIFIER.fullmatch(value):
        raise ValueError(f"invalid SQL view name: {value!r}")
    return value


def safe_relative(value: str) -> str:
    value = str(value).strip("/")
    if ".." in PurePosixPath(value).parts:
        raise ValueError(f"unsafe relative path: {value!r}")
    return value


def read_roots(path: Path) -> dict[int, str]:
    roots: dict[int, str] = {}
    with path.open(encoding="utf-8") as handle:
        for number, raw in enumerate(handle, 1):
            line = raw.rstrip("\n")
            if not line or line.startswith("#"):
                continue
            fields = line.split("\t")
            if len(fields) != 2:
                raise ValueError(f"{path}:{number}: expected fileid and display_name")
            fileid_text, display_name = fields
            if not fileid_text.isdigit() or int(fileid_text) < 1:
                raise ValueError(f"{path}:{number}: invalid fileid")
            if any(char in display_name for char in ("\t", "\n", "\r")):
                raise ValueError(f"{path}:{number}: invalid display name")
            roots[int(fileid_text)] = display_name or f"Element_{fileid_text}"
    if not roots:
        raise ValueError("no selected Nextcloud roots configured")
    return roots


def read_adapters(path: Path) -> dict[str, list[tuple[str, Path]]]:
    result: dict[str, list[tuple[str, Path]]] = {}
    with path.open(encoding="utf-8") as handle:
        for number, raw in enumerate(handle, 1):
            line = raw.rstrip("\n")
            if not line or line.startswith("#"):
                continue
            fields = line.split("\t")
            if len(fields) not in {3, 4}:
                raise ValueError(f"{path}:{number}: expected storage_id, source_prefix, local_mount and optional include_prefix")
            storage_id, source_prefix, local_mount = fields[:3]
            storage_id = storage_id.strip()
            source_prefix = safe_relative(source_prefix)
            mount = Path(local_mount)
            if not storage_id:
                raise ValueError(f"{path}:{number}: empty storage_id")
            if not mount.is_absolute():
                raise ValueError(f"{path}:{number}: local_mount must be absolute")
            result.setdefault(storage_id, []).append((source_prefix, mount))
    if not result:
        raise ValueError("no storage adapters configured")
    return result


def matches_prefix(path: str, prefix: str) -> bool:
    return not prefix or path == prefix or path.startswith(prefix + "/")


def resolve_adapter(adapters: dict[str, list[tuple[str, Path]]], storage_id: str, source_path: str) -> tuple[str, Path]:
    relative = safe_relative(source_path)
    matches = [(prefix, mount) for prefix, mount in adapters.get(storage_id, []) if matches_prefix(relative, prefix)]
    if not matches:
        raise RuntimeError(f"no storage adapter configured for {storage_id}")
    matches.sort(key=lambda item: len(item[0]), reverse=True)
    best_length = len(matches[0][0])
    best = {(prefix, str(mount)): (prefix, mount) for prefix, mount in matches if len(prefix) == best_length}
    if len(best) != 1:
        raise RuntimeError(f"storage adapter is ambiguous for {storage_id}")
    return next(iter(best.values()))


def query_rows(args: argparse.Namespace, roots: dict[int, str]) -> dict[int, tuple[str, str]]:
    password = args.password_file.read_text(encoding="utf-8").strip()
    env = os.environ.copy()
    env["PGPASSWORD"] = password
    ids = ",".join(str(fileid) for fileid in sorted(roots))
    view = validate_view(args.view)
    sql = f"SELECT fileid, storage_id, source_path FROM {view} WHERE fileid IN ({ids}) ORDER BY fileid"
    command = [
        "psql", "-X", "-A", "-F", "\t", "-t", "-v", "ON_ERROR_STOP=1",
        "-h", args.host, "-p", str(args.port), "-U", args.user, "-d", args.database, "-c", sql,
    ]
    completed = subprocess.run(command, env=env, check=False, text=True, capture_output=True)
    if completed.returncode != 0:
        raise RuntimeError(completed.stderr.strip() or "Nextcloud file view query failed")
    result: dict[int, tuple[str, str]] = {}
    for row in csv.reader(completed.stdout.splitlines(), delimiter="\t"):
        if len(row) != 3 or not row[0].isdigit():
            raise RuntimeError("invalid row returned by Nextcloud file view")
        result[int(row[0])] = (row[1], row[2])
    missing = sorted(set(roots) - set(result))
    if missing:
        raise RuntimeError("selected Nextcloud file IDs are no longer resolvable: " + ", ".join(map(str, missing)))
    return result


def contained_join(root: Path, relative: str) -> Path:
    relative = safe_relative(relative)
    candidate = root.joinpath(*PurePosixPath(relative).parts) if relative else root
    root_real = root.resolve()
    candidate_real = candidate.resolve()
    try:
        candidate_real.relative_to(root_real)
    except ValueError as error:
        raise ValueError(f"resolved source escapes configured mount: {candidate}") from error
    return candidate


def build(args: argparse.Namespace) -> dict[str, object]:
    roots = read_roots(args.roots_config)
    adapters = read_adapters(args.storage_config)
    rows = query_rows(args, roots)
    manifest: list[str] = []

    for fileid, display_name in roots.items():
        storage_id, source_path = rows[fileid]
        prefix, mount = resolve_adapter(adapters, storage_id, source_path)
        if not mount.is_mount():
            raise RuntimeError(f"storage mount unavailable: {mount}")
        relative = safe_relative(source_path)
        if prefix:
            relative = relative[len(prefix):].lstrip("/")
        source = contained_join(mount, relative)
        if source.is_dir():
            item_type = "folder"
        elif source.is_file():
            item_type = "file"
        else:
            raise RuntimeError(f"selected source unavailable: {source}")
        if any(char in str(source) for char in ("\t", "\n", "\r")):
            raise ValueError("resolved source path contains unsupported control characters")
        manifest.append(f"fileid:{fileid}\t{item_type}\t{display_name}\t{source}")

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=args.output.parent, delete=False) as handle:
        handle.write("\n".join(manifest) + "\n")
        temporary = Path(handle.name)
    temporary.replace(args.output)
    return {"roots": len(manifest), "manifest": str(args.output)}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=5432)
    parser.add_argument("--database", required=True)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password-file", required=True, type=Path)
    parser.add_argument("--view", required=True)
    parser.add_argument("--storage-config", required=True, type=Path)
    parser.add_argument("--roots-config", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    try:
        print(json.dumps(build(args), ensure_ascii=False))
    except Exception as error:
        print(f"selected-manifest: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
