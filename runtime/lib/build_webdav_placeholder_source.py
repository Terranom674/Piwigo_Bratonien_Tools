#!/usr/bin/env python3
"""Build a placeholder-backed local source tree from Nextcloud WebDAV.

This is intentionally additive: it does not replace the existing local-storage
connector path. It creates only tiny placeholder files plus a metadata mapping;
no Nextcloud original media is downloaded.
"""

from __future__ import annotations

import argparse
import base64
import getpass
import json
import os
import shutil
import ssl
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from pathlib import Path, PurePosixPath

DAV = "DAV:"
OC = "http://owncloud.org/ns"
NC = "http://nextcloud.org/ns"
SUPPORTED_IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".gif", ".webp"}
# 1x1 transparent GIF, 34 bytes. The filename keeps the remote extension;
# the placeholder exists only so Piwigo can discover the logical image entry.
PLACEHOLDER = base64.b64decode("R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==")


def fail(message: str) -> None:
    raise RuntimeError(message)


def validate_relative(value: str) -> str:
    value = str(value).strip("/")
    parts = PurePosixPath(value).parts
    if ".." in parts:
        raise ValueError(f"unsafe WebDAV path: {value!r}")
    return value


def quote_path(value: str) -> str:
    value = validate_relative(value)
    return "/".join(urllib.parse.quote(part, safe="") for part in PurePosixPath(value).parts)


def safe_local_name(name: str) -> str:
    if not name or name in {".", ".."} or "/" in name or "\x00" in name:
        raise ValueError(f"unsafe remote name: {name!r}")
    return name


def parse_image_dimensions(prop: ET.Element) -> tuple[int, int]:
    for property_name in ("metadata-photos-size", "file-metadata-size"):
        element = prop.find(f"{{{NC}}}{property_name}")
        if element is None:
            continue

        width = 0
        height = 0
        for child in list(element):
            local_name = child.tag.rsplit("}", 1)[-1]
            text = (child.text or "").strip()
            if not text:
                continue
            try:
                value = int(text)
            except ValueError:
                continue
            if local_name == "width":
                width = value
            elif local_name == "height":
                height = value
        if width > 0 and height > 0:
            return width, height

        raw = (element.text or "").strip()
        if not raw:
            continue
        try:
            value = json.loads(raw)
        except (TypeError, ValueError, json.JSONDecodeError):
            continue
        if not isinstance(value, dict):
            continue
        try:
            width = int(value.get("width", 0) or 0)
            height = int(value.get("height", 0) or 0)
        except (TypeError, ValueError):
            continue
        if width > 0 and height > 0:
            return width, height
    return 0, 0


class WebDavClient:
    def __init__(self, base_url: str, user: str, password: str, timeout: int = 30) -> None:
        self.base_url = base_url.rstrip("/")
        self.user = user
        self.password = password
        self.timeout = timeout
        token = base64.b64encode(f"{user}:{password}".encode("utf-8")).decode("ascii")
        self.auth_header = f"Basic {token}"
        self.context = ssl.create_default_context()

    def collection_url(self, relative: str) -> str:
        user = urllib.parse.quote(self.user, safe="")
        suffix = quote_path(relative)
        url = f"{self.base_url}/remote.php/dav/files/{user}/"
        if suffix:
            url += suffix + "/"
        return url

    def list_collection(self, relative: str) -> tuple[dict[str, object], list[dict[str, object]]]:
        relative = validate_relative(relative)
        url = self.collection_url(relative)
        body = (
            '<?xml version="1.0"?>'
            '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
            '<d:prop><d:displayname/><d:resourcetype/><d:getcontenttype/>'
            '<d:getcontentlength/><d:getetag/><oc:fileid/>'
            '<nc:file-metadata-size/><nc:metadata-photos-size/>'
            '</d:prop></d:propfind>'
        ).encode("utf-8")
        request = urllib.request.Request(url, data=body, method="PROPFIND")
        request.add_header("Authorization", self.auth_header)
        request.add_header("Depth", "1")
        request.add_header("Content-Type", "application/xml; charset=utf-8")
        try:
            with urllib.request.urlopen(request, timeout=self.timeout, context=self.context) as response:
                status = response.status
                payload = response.read()
        except urllib.error.HTTPError as error:
            if error.code in {401, 403}:
                fail("Nextcloud rejected the WebDAV credentials or directory access")
            fail(f"Nextcloud PROPFIND failed with HTTP {error.code}")
        except urllib.error.URLError as error:
            fail(f"Nextcloud WebDAV is unreachable: {error.reason}")
        if status != 207:
            fail(f"Nextcloud PROPFIND returned HTTP {status}")

        try:
            root = ET.fromstring(payload)
        except ET.ParseError as error:
            raise RuntimeError("Nextcloud returned invalid WebDAV XML") from error

        base_path = urllib.parse.unquote(urllib.parse.urlparse(url).path).rstrip("/")
        current: dict[str, object] | None = None
        children: list[dict[str, object]] = []
        for response in root.findall(f"{{{DAV}}}response"):
            href = response.findtext(f"{{{DAV}}}href", default="")
            href_path = urllib.parse.unquote(urllib.parse.urlparse(href).path).rstrip("/")
            prop = None
            for propstat in response.findall(f"{{{DAV}}}propstat"):
                status_text = propstat.findtext(f"{{{DAV}}}status", default="")
                if " 200 " in status_text:
                    prop = propstat.find(f"{{{DAV}}}prop")
                    if prop is not None:
                        break
            if prop is None:
                continue
            display = prop.findtext(f"{{{DAV}}}displayname", default="")
            fileid_text = prop.findtext(f"{{{OC}}}fileid", default="").strip()
            resource_type = prop.find(f"{{{DAV}}}resourcetype")
            is_dir = resource_type is not None and resource_type.find(f"{{{DAV}}}collection") is not None
            width, height = parse_image_dimensions(prop)
            item = {
                "display_name": display,
                "fileid": int(fileid_text) if fileid_text.isdigit() else 0,
                "is_dir": is_dir,
                "content_type": prop.findtext(f"{{{DAV}}}getcontenttype", default=""),
                "size": int(prop.findtext(f"{{{DAV}}}getcontentlength", default="0") or 0),
                "etag": prop.findtext(f"{{{DAV}}}getetag", default="").strip('"'),
                "width": width,
                "height": height,
            }
            if href_path == base_path:
                current = item
                continue
            if display:
                children.append(item)
        if current is None or int(current.get("fileid", 0)) < 1:
            fail(f"Nextcloud returned no stable fileid for {relative or '/'}")
        return current, children


def link_placeholder(seed: Path, target: Path) -> None:
    target.parent.mkdir(parents=True, exist_ok=True)
    try:
        os.link(seed, target)
    except OSError:
        target.write_bytes(PLACEHOLDER)


def build_root(
    client: WebDavClient,
    remote_root: str,
    local_root: Path,
    seed: Path,
    mapping: dict[str, dict[str, object]],
) -> tuple[int, int, int]:
    files = 0
    folders = 0
    skipped = 0
    stack: list[tuple[str, Path]] = [(validate_relative(remote_root), local_root)]
    visited: set[str] = set()

    while stack:
        remote_dir, local_dir = stack.pop()
        if remote_dir in visited:
            continue
        visited.add(remote_dir)
        current, children = client.list_collection(remote_dir)
        local_dir.mkdir(parents=True, exist_ok=True)
        folders += 1
        mapping[str(local_dir)] = {
            "kind": "folder",
            "fileid": int(current["fileid"]),
            "webdav_path": remote_dir,
            "display_name": str(current.get("display_name", "")),
        }

        for child in children:
            name = safe_local_name(str(child["display_name"]))
            child_remote = name if remote_dir == "" else f"{remote_dir}/{name}"
            child_local = local_dir / name
            if bool(child["is_dir"]):
                stack.append((child_remote, child_local))
                continue
            extension = Path(name).suffix.lower()
            if extension not in SUPPORTED_IMAGE_EXTENSIONS:
                skipped += 1
                continue
            link_placeholder(seed, child_local)
            files += 1
            mapping[str(child_local)] = {
                "kind": "file",
                "fileid": int(child.get("fileid", 0)),
                "webdav_path": child_remote,
                "display_name": name,
                "content_type": str(child.get("content_type", "")),
                "size": int(child.get("size", 0)),
                "etag": str(child.get("etag", "")),
                "width": int(child.get("width", 0) or 0),
                "height": int(child.get("height", 0) or 0),
            }
    return files, folders, skipped


def atomic_json(path: Path, payload: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=path.parent, delete=False) as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2, sort_keys=True)
        handle.write("\n")
        temporary = Path(handle.name)
    temporary.replace(path)


def atomic_text(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=path.parent, delete=False) as handle:
        handle.write(text)
        temporary = Path(handle.name)
    temporary.replace(path)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password-file", type=Path)
    parser.add_argument("--root", action="append", required=True, help="WebDAV path relative to the authenticated user's files root")
    parser.add_argument("--source-dir", required=True, type=Path)
    parser.add_argument("--manifest", required=True, type=Path)
    parser.add_argument("--mapping", required=True, type=Path)
    parser.add_argument("--timeout", type=int, default=30)
    args = parser.parse_args()

    try:
        if args.password_file:
            password = args.password_file.read_text(encoding="utf-8").rstrip("\r\n")
        else:
            password = getpass.getpass("Nextcloud password: ")
        if not password:
            fail("Nextcloud password is empty")

        source_dir = args.source_dir.resolve()
        staging = source_dir.with_name(f".{source_dir.name}.next")
        previous = source_dir.with_name(f".{source_dir.name}.previous")
        seed = source_dir.parent / ".bratonien-webdav-placeholder.gif"
        source_dir.parent.mkdir(parents=True, exist_ok=True)
        seed.write_bytes(PLACEHOLDER)
        os.chmod(seed, 0o644)
        if staging.exists():
            shutil.rmtree(staging)
        staging.mkdir(parents=True)

        client = WebDavClient(args.base_url, args.user, password, max(1, args.timeout))
        mapping: dict[str, dict[str, object]] = {}
        manifest: list[str] = []
        total_files = total_folders = total_skipped = 0
        used_names: set[str] = set()

        for remote_root_raw in args.root:
            remote_root = validate_relative(remote_root_raw)
            current, _ = client.list_collection(remote_root)
            fileid = int(current["fileid"])
            display = str(current.get("display_name", "")).strip() or (PurePosixPath(remote_root).name if remote_root else args.user)
            local_name = f"root-{fileid}"
            if local_name in used_names:
                fail(f"duplicate selected Nextcloud root fileid: {fileid}")
            used_names.add(local_name)
            local_root = staging / local_name
            files, folders, skipped = build_root(client, remote_root, local_root, seed, mapping)
            total_files += files
            total_folders += folders
            total_skipped += skipped

            if remote_root == "":
                for child in sorted(local_root.iterdir(), key=lambda item: (item.name.casefold(), item.name)):
                    child_data = mapping.get(str(child))
                    if not child_data:
                        continue
                    child_fileid = int(child_data.get("fileid", 0))
                    child_display = str(child_data.get("display_name", "")).strip() or child.name
                    child_type = "folder" if child.is_dir() else "file"
                    manifest.append(
                        f"webdav:{child_fileid}\t{child_type}\t{child_display}\t{source_dir / local_name / child.name}"
                    )
            else:
                manifest.append(f"webdav:{fileid}\tfolder\t{display}\t{source_dir / local_name}")

        if previous.exists():
            shutil.rmtree(previous)
        if source_dir.exists():
            source_dir.rename(previous)
        try:
            staging.rename(source_dir)
        except Exception:
            if source_dir.exists():
                shutil.rmtree(source_dir)
            if previous.exists():
                previous.rename(source_dir)
            raise
        if previous.exists():
            shutil.rmtree(previous)

        final_mapping: dict[str, dict[str, object]] = {}
        staging_text = str(staging)
        source_text = str(source_dir)
        for path, data in mapping.items():
            final_path = source_text + path[len(staging_text):] if path.startswith(staging_text) else path
            final_mapping[final_path] = data

        atomic_text(args.manifest, "\n".join(manifest) + "\n")
        atomic_json(args.mapping, {
            "version": 1,
            "base_url": args.base_url.rstrip("/"),
            "user": args.user,
            "files": final_mapping,
        })
        print(json.dumps({
            "roots": len(args.root),
            "files": total_files,
            "folders": total_folders,
            "skipped": total_skipped,
            "source_dir": str(source_dir),
            "manifest": str(args.manifest),
            "mapping": str(args.mapping),
        }, ensure_ascii=False))
        return 0
    except Exception as error:
        print(f"webdav-placeholder: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
