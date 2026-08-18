#!/usr/bin/env python3
"""Resolve Nextcloud share rows through configured storage adapters."""

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


def sql_literal(value: str) -> str:
    return "'" + str(value).replace("'", "''") + "'"


def read_config(path: Path) -> dict[str, list[tuple[str, Path, str]]]:
    result: dict[str, list[tuple[str, Path, str]]] = {}
    with path.open(encoding="utf-8") as handle:
        for number, line in enumerate(handle, 1):
            line = line.rstrip("\n")
            if not line or line.startswith("#"):
                continue
            fields = line.split("\t")
            if len(fields) not in {3, 4}:
                raise ValueError(f"{path}:{number}: expected storage_id, source_prefix, local_mount and optional include_prefix")
            storage_id, prefix, mount = fields[:3]
            include_prefix = fields[3] if len(fields) == 4 else ""
            storage_id = storage_id.strip()
            prefix = prefix.strip("/")
            include_prefix = include_prefix.strip("/")
            if not storage_id:
                raise ValueError(f"{path}:{number}: storage_id is empty")
            if ".." in PurePosixPath(prefix).parts or ".." in PurePosixPath(include_prefix).parts:
                raise ValueError(f"{path}:{number}: unsafe prefix")
            result.setdefault(storage_id, []).append((prefix, Path(mount), include_prefix))
    return result


def run_query(args: argparse.Namespace, env: dict[str, str], sql: str) -> subprocess.CompletedProcess[str]:
    command = [
        "psql", "-X", "-A", "-F", "\t", "-t", "-v", "ON_ERROR_STOP=1",
        "-h", args.host, "-p", str(args.port), "-U", args.user, "-d", args.database, "-c", sql,
    ]
    return subprocess.run(command, env=env, check=False, text=True, capture_output=True)


def query_rows(args: argparse.Namespace) -> list[list[str]]:
    password = args.password_file.read_text(encoding="utf-8").strip()
    env = os.environ.copy()
    env["PGPASSWORD"] = password
    view = validate_view(args.view)
    where = ""
    if args.access_user:
        where = " WHERE lower(access_user) = lower(" + sql_literal(args.access_user) + ")"
    modern_sql = f"SELECT share_id, item_type, display_name, storage_id, source_path FROM {view}{where} ORDER BY share_id"
    completed = run_query(args, env, modern_sql)
    if completed.returncode == 0:
        return list(csv.reader(completed.stdout.splitlines(), delimiter="\t"))
    if args.access_user:
        raise RuntimeError(completed.stderr.strip() or "Nextcloud user-filtered source query failed")
    if "item_type" not in completed.stderr or "does not exist" not in completed.stderr:
        raise RuntimeError(completed.stderr.strip() or "Nextcloud source view query failed")
    legacy_sql = f"SELECT share_id, display_name, storage_id, source_path FROM {view} ORDER BY share_id"
    completed = run_query(args, env, legacy_sql)
    if completed.returncode != 0:
        raise RuntimeError(completed.stderr.strip() or "legacy Nextcloud source view query failed")
    rows: list[list[str]] = []
    for row in csv.reader(completed.stdout.splitlines(), delimiter="\t"):
        if len(row) == 4:
            share_id, display_name, storage_id, source_path = row
            rows.append([share_id, "", display_name, storage_id, source_path])
        else:
            rows.append(row)
    return rows


def contained_join(root: Path, relative: str) -> Path:
    parts = PurePosixPath(relative).parts
    if relative.startswith("/") or ".." in parts:
        raise ValueError(f"unsafe source path: {relative}")
    return root.joinpath(*parts)


def matches_prefix(path: str, prefix: str) -> bool:
    return not prefix or path == prefix or path.startswith(prefix + "/")


def resolve_adapter(adapters: dict[str, list[tuple[str, Path, str]]], storage_id: str, source_path: str) -> tuple[str, Path, str] | None:
    relative = source_path.strip("/")
    matches: list[tuple[str, Path, str]] = []
    for prefix, mount, include_prefix in adapters.get(storage_id, []):
        if not matches_prefix(relative, prefix):
            continue
        mapped_relative = relative[len(prefix):].lstrip("/") if prefix else relative
        if not matches_prefix(mapped_relative, include_prefix):
            continue
        matches.append((prefix, mount, include_prefix))
    if not matches:
        return None
    matches.sort(key=lambda item: (len(item[0]), len(item[2])), reverse=True)
    best_score = (len(matches[0][0]), len(matches[0][2]))
    best = {(item[0], str(item[1]), item[2]): item for item in matches if (len(item[0]), len(item[2])) == best_score}
    if len(best) != 1:
        raise RuntimeError(f"storage adapter is ambiguous for {storage_id}")
    return next(iter(best.values()))


def build(args: argparse.Namespace) -> dict[str, object]:
    validate_view(args.view)
    adapters = read_config(args.storage_config)
    rows = query_rows(args)
    if not rows and not args.allow_empty:
        raise RuntimeError("Nextcloud returned no matching sources; refusing an empty manifest")
    manifest: list[str] = []
    errors: list[str] = []
    folder_count = 0
    file_count = 0
    for row in rows:
        if len(row) != 5:
            errors.append(f"invalid database row with {len(row)} columns")
            continue
        share_id, item_type, display_name, storage_id, source_path = row
        item_type = item_type.strip().lower()
        relative = source_path.strip("/")
        if item_type and item_type not in {"folder", "file"}:
            errors.append(f"source {share_id}: unsupported item_type {item_type!r}")
            continue
        try:
            adapter = resolve_adapter(adapters, storage_id, relative)
        except RuntimeError as error:
            errors.append(f"source {share_id}: {error}")
            continue
        if not adapter:
            continue
        prefix, mount, _include_prefix = adapter
        if prefix:
            relative = relative[len(prefix):].lstrip("/")
        source = contained_join(mount, relative)
        if not mount.is_mount():
            errors.append(f"source {share_id}: storage mount unavailable: {mount}")
            continue
        if not item_type:
            if source.is_dir():
                item_type = "folder"
            elif source.is_file():
                item_type = "file"
            else:
                errors.append(f"source {share_id}: source unavailable: {source}")
                continue
        if item_type == "folder":
            if not source.is_dir():
                errors.append(f"source {share_id}: source directory unavailable: {source}")
                continue
            folder_count += 1
        else:
            if not source.is_file():
                errors.append(f"source {share_id}: source file unavailable: {source}")
                continue
            file_count += 1
        if any(char in display_name for char in ("\t", "\n", "\r")):
            errors.append(f"source {share_id}: display name contains unsupported control characters")
            continue
        source_text = str(source)
        if any(char in source_text for char in ("\t", "\n", "\r")):
            errors.append(f"source {share_id}: source path contains unsupported control characters")
            continue
        manifest.append(f"{share_id}\t{item_type}\t{display_name.lstrip('/')}\t{source}")
    if errors:
        raise RuntimeError("; ".join(errors))
    if not manifest and rows and not args.allow_empty:
        raise RuntimeError("no Nextcloud sources match the configured storage adapters")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=args.output.parent, delete=False) as handle:
        handle.write("\n".join(manifest) + ("\n" if manifest else ""))
        temporary = Path(handle.name)
    temporary.replace(args.output)
    return {"sources": len(manifest), "folders": folder_count, "files": file_count, "manifest": str(args.output)}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=5432)
    parser.add_argument("--database", required=True)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password-file", required=True, type=Path)
    parser.add_argument("--view", required=True)
    parser.add_argument("--access-user", default="")
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
