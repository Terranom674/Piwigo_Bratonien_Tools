#!/usr/bin/env python3
"""Build a manifest from one connection's explicitly selected user filesystem roots.

This mode deliberately does not read the legacy Showcase source view. The storage
configuration must already resolve to the authenticated Nextcloud user's local
home/files tree. Only the selected include prefixes are exposed to Piwigo.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import tempfile
from pathlib import Path, PurePosixPath


def safe_relative(value: str) -> str:
    value = str(value).strip("/")
    parts = PurePosixPath(value).parts
    if ".." in parts:
        raise ValueError(f"unsafe relative path: {value!r}")
    return value


def contained(root: Path, relative: str) -> Path:
    relative = safe_relative(relative)
    candidate = root.joinpath(*PurePosixPath(relative).parts) if relative else root
    root_real = root.resolve()
    candidate_real = candidate.resolve()
    try:
        candidate_real.relative_to(root_real)
    except ValueError as error:
        raise ValueError(f"path escapes configured user root: {relative!r}") from error
    return candidate


def read_config(path: Path) -> list[tuple[str, str, Path, str]]:
    rows: list[tuple[str, str, Path, str]] = []
    with path.open(encoding="utf-8") as handle:
        for number, raw in enumerate(handle, 1):
            line = raw.rstrip("\n")
            if not line or line.startswith("#"):
                continue
            fields = line.split("\t")
            if len(fields) not in {3, 4}:
                raise ValueError(
                    f"{path}:{number}: expected storage_id, source_prefix, local_mount and optional include_prefix"
                )
            storage_id, source_prefix, local_mount = fields[:3]
            include_prefix = fields[3] if len(fields) == 4 else ""
            source_prefix = safe_relative(source_prefix)
            include_prefix = safe_relative(include_prefix)
            mount = Path(local_mount)
            if not storage_id.strip():
                raise ValueError(f"{path}:{number}: storage_id is empty")
            if not mount.is_absolute():
                raise ValueError(f"{path}:{number}: local_mount must be absolute")
            rows.append((storage_id.strip(), source_prefix, mount, include_prefix))
    if not rows:
        raise ValueError("no user storage mappings configured")
    return rows


def stable_id(source: Path) -> str:
    digest = hashlib.sha256(str(source.resolve()).encode("utf-8")).hexdigest()[:24]
    return f"user-{digest}"


def manifest_entry(source: Path) -> str:
    if source.is_symlink():
        raise ValueError(f"selected source must not be a symlink: {source}")
    if source.is_dir():
        item_type = "folder"
    elif source.is_file():
        item_type = "file"
    else:
        raise FileNotFoundError(f"selected source is unavailable: {source}")
    name = source.name
    if not name:
        raise ValueError(f"selected source has no display name: {source}")
    for value in (name, str(source)):
        if any(char in value for char in ("\t", "\n", "\r")):
            raise ValueError(f"selected source contains unsupported control characters: {source}")
    return f"{stable_id(source)}\t{item_type}\t{name}\t{source}"


def build(storage_config: Path, output: Path) -> dict[str, object]:
    mappings = read_config(storage_config)
    entries: dict[str, str] = {}

    for _storage_id, source_prefix, mount, include_prefix in mappings:
        if not mount.is_dir():
            raise FileNotFoundError(f"user storage mount unavailable: {mount}")
        user_root = contained(mount, source_prefix)
        if not user_root.is_dir():
            raise FileNotFoundError(f"user files root unavailable: {user_root}")

        if include_prefix:
            selected = contained(user_root, include_prefix)
            line = manifest_entry(selected)
            entries[str(selected.resolve())] = line
            continue

        # Empty selection means the user's root. Expose its direct children as
        # Piwigo roots instead of creating an artificial "files" album.
        for child in sorted(user_root.iterdir(), key=lambda item: (item.name.casefold(), item.name)):
            if child.is_symlink():
                continue
            if not (child.is_dir() or child.is_file()):
                continue
            line = manifest_entry(child)
            entries[str(child.resolve())] = line

    if not entries:
        raise RuntimeError("the selected Nextcloud directories contain no readable files or folders")

    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=output.parent, delete=False) as handle:
        for line in entries.values():
            handle.write(line + "\n")
        temporary = Path(handle.name)
    temporary.replace(output)
    return {"roots": len(entries), "manifest": str(output)}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--storage-config", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    try:
        print(json.dumps(build(args.storage_config, args.output), ensure_ascii=False))
    except Exception as error:
        print(f"user-manifest: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
