#!/usr/bin/env python3
"""Build a Piwigo-safe directory tree without copying media files."""

from __future__ import annotations

import argparse
import json
import shutil
import sys
import unicodedata
from pathlib import Path

ALLOWED = frozenset("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_.")
GERMAN = str.maketrans({"ä": "ae", "ö": "oe", "ü": "ue", "Ä": "Ae", "Ö": "Oe", "Ü": "Ue", "ß": "ss", "ẞ": "SS"})


def safe_name(name: str) -> str:
    name = name.translate(GERMAN).replace(" ", "_")
    name = unicodedata.normalize("NFKD", name).encode("ascii", "ignore").decode("ascii")
    cleaned = "".join(char for char in name if char in ALLOWED)
    while "__" in cleaned:
        cleaned = cleaned.replace("__", "_")
    cleaned = cleaned.strip("_.-")
    return cleaned or "Element"


def add_suffix(name: str, number: int, is_file: bool) -> str:
    if is_file:
        suffixes = Path(name).suffixes
        extension = "".join(suffixes)
        stem = name[: -len(extension)] if extension else name
        return f"{stem}_{number}{extension}"
    return f"{name}_{number}"


def unique_name(preferred: str, used: set[str], is_file: bool) -> str:
    candidate = preferred
    number = 2
    while candidate.casefold() in used:
        candidate = add_suffix(preferred, number, is_file)
        number += 1
    used.add(candidate.casefold())
    return candidate


def load_manifest(path: Path) -> list[dict[str, str]]:
    entries: list[dict[str, str]] = []
    with path.open(encoding="utf-8") as handle:
        for line_number, raw_line in enumerate(handle, 1):
            line = raw_line.rstrip("\n")
            if not line or line.startswith("#"):
                continue
            fields = line.split("\t")
            if len(fields) != 4:
                raise ValueError(f"{path}:{line_number}: expected share_id, item_type, display_name and source_path")
            share_id, item_type, display_name, source_path = fields
            if item_type not in {"folder", "file"}:
                raise ValueError(f"{path}:{line_number}: unsupported item_type {item_type!r}")
            entries.append({
                "share_id": share_id,
                "item_type": item_type,
                "display_name": display_name,
                "source_path": source_path,
            })
    return entries


def load_map(path: Path) -> dict[str, str]:
    if not path.exists():
        return {}
    with path.open(encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        raise ValueError(f"invalid mapping in {path}")
    return {str(key): str(value) for key, value in data.items()}


def preferred_target(source_key: str, raw_name: str, parent_target: Path, old_map: dict[str, str]) -> str:
    old_target = old_map.get(source_key)
    if old_target:
        old_path = Path(old_target)
        if old_path.parent == parent_target:
            return old_path.name
    return safe_name(raw_name)


def mirror_directory(source: Path, target: Path, source_key: str, target_key: Path, old_map: dict[str, str], new_map: dict[str, str]) -> None:
    target.mkdir(parents=True, exist_ok=True)
    used: set[str] = set()
    children = sorted(source.iterdir(), key=lambda item: (item.name.casefold(), item.name))
    for child in children:
        if child.is_symlink():
            continue
        child_source_key = f"{source_key}/{child.name}"
        preferred = preferred_target(child_source_key, child.name, target_key, old_map)
        child_name = unique_name(preferred, used, child.is_file())
        child_target = target / child_name
        child_target_key = target_key / child_name
        new_map[child_source_key] = child_target_key.as_posix()
        if child.is_dir():
            mirror_directory(child, child_target, child_source_key, child_target_key, old_map, new_map)
        elif child.is_file():
            child_target.symlink_to(child.resolve())


def build(manifest: Path, destination: Path, state_file: Path) -> None:
    entries = load_manifest(manifest)
    old_map = load_map(state_file)
    new_map: dict[str, str] = {}
    staging = destination.with_name(f".{destination.name}.next")
    previous = destination.with_name(f".{destination.name}.previous")

    if staging.exists():
        shutil.rmtree(staging)
    staging.mkdir(parents=True)

    used_roots: set[str] = set()
    for entry in sorted(entries, key=lambda item: (item["display_name"].casefold(), item["share_id"])):
        source = Path(entry["source_path"])
        source_key = f"share:{entry['share_id']}"
        preferred = preferred_target(source_key, entry["display_name"], Path("."), old_map)
        is_file_share = entry["item_type"] == "file"
        root_name = unique_name(preferred, used_roots, is_file_share)
        root_key = Path(root_name)
        new_map[source_key] = root_key.as_posix()

        if is_file_share:
            if not source.is_file():
                raise FileNotFoundError(f"source is not a readable file: {source}")
            (staging / root_name).symlink_to(source.resolve())
            continue

        if not source.is_dir():
            raise FileNotFoundError(f"source is not a readable directory: {source}")
        mirror_directory(source, staging / root_name, source_key, root_key, old_map, new_map)

    state_staging = state_file.with_suffix(state_file.suffix + ".next")
    state_staging.parent.mkdir(parents=True, exist_ok=True)
    with state_staging.open("w", encoding="utf-8") as handle:
        json.dump(new_map, handle, ensure_ascii=False, indent=2, sort_keys=True)
        handle.write("\n")

    if previous.exists():
        shutil.rmtree(previous)
    if destination.exists():
        destination.rename(previous)
    staging.rename(destination)
    state_staging.replace(state_file)
    if previous.exists():
        shutil.rmtree(previous)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--manifest", required=True, type=Path)
    parser.add_argument("--destination", required=True, type=Path)
    parser.add_argument("--state", required=True, type=Path)
    args = parser.parse_args()
    try:
        build(args.manifest, args.destination, args.state)
    except Exception as error:
        print(f"shadow-tree: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
