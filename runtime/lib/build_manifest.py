#!/usr/bin/env python3
"""Resolve Nextcloud view rows through explicitly configured storage mounts."""

from __future__ import annotations

import argparse
import csv
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path, PurePosixPath


def read_config(path: Path) -> dict[str, tuple[str, Path]]:
    result: dict[str, tuple[str, Path]] = {}
    with path.open(encoding="utf-8") as handle:
        for number, line in enumerate(handle, 1):
            line = line.rstrip("\n")
            if not line or line.startswith("#"):
                continue
            fields = line.split("\t")
            if len(fields) != 3:
                raise ValueError(f"{path}:{number}: expected storage_id, source_prefix and local_mount")
            storage_id, prefix, mount = fields
            result[storage_id] = (prefix.strip("/"), Path(mount))
    return result


def query_rows(args: argparse.Namespace) -> list[list[str]]:
    password = args.password_file.read_text(encoding="utf-8").strip()
    env = os.environ.copy()
    env["PGPASSWORD"] = password
    sql = f"SELECT share_id, display_name, storage_id, source_path FROM {args.view} ORDER BY share_id"
    command = [
        "psql", "-X", "-A", "-F", "\t", "-t",
        "-h", args.host, "-p", str(args.port), "-U", args.user, "-d", args.database,
        "-c", sql,
    ]
    completed = subprocess.run(command, env=env, check=True, text=True, capture_output=True)
    return list(csv.reader(completed.stdout.splitlines(), delimiter="\t"))


def contained_join(root: Path, relative: str) -> Path:
    parts = PurePosixPath(relative).parts
    if relative.startswith("/") or ".." in parts:
        raise ValueError(f"unsafe source path: {relative}")
    return root.joinpath(*parts)


def build(args: argparse.Namespace) -> dict[str, object]:
    adapters = read_config(args.storage_config)
    rows = query_rows(args)
    if not rows and not args.allow_empty:
        raise RuntimeError("Nextcloud returned no Showcase shares; refusing an empty manifest")

    manifest: list[str] = []
    errors: list[str] = []
    for row in rows:
        if len(row) != 4:
            errors.append(f"invalid database row with {len(row)} columns")
            continue
        share_id, display_name, storage_id, source_path = row
        adapter = adapters.get(storage_id)
        if not adapter:
            errors.append(f"share {share_id}: unknown storage {storage_id}")
            continue
        prefix, mount = adapter
        relative = source_path.strip("/")
        if prefix:
            expected = prefix + "/"
            if relative != prefix and not relative.startswith(expected):
                errors.append(f"share {share_id}: path does not match configured prefix")
                continue
            relative = relative[len(prefix):].lstrip("/")
        source = contained_join(mount, relative)
        if not mount.is_mount():
            errors.append(f"share {share_id}: storage mount unavailable: {mount}")
            continue
        if not source.is_dir():
            errors.append(f"share {share_id}: source directory unavailable: {source}")
            continue
        manifest.append(f"{share_id}\t{display_name.lstrip('/')}\t{source}")

    if errors:
        raise RuntimeError("; ".join(errors))
    if len(manifest) != len(rows):
        raise RuntimeError("not all Showcase shares could be resolved")

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=args.output.parent, delete=False) as handle:
        handle.write("\n".join(manifest) + ("\n" if manifest else ""))
        temporary = Path(handle.name)
    temporary.replace(args.output)
    return {"shares": len(manifest), "manifest": str(args.output)}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=5432)
    parser.add_argument("--database", required=True)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password-file", required=True, type=Path)
    parser.add_argument("--view", default="piwigo_showcase_sources")
    parser.add_argument("--storage-config", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--allow-empty", action="store_true")
    args = parser.parse_args()
    try:
        print(json.dumps(build(args), ensure_ascii=False))
    except Exception as error:
        print(f"manifest: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
