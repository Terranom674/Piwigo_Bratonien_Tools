#!/usr/bin/env python3
"""Build a placeholder-backed local source tree from Nextcloud WebDAV.

This creates only tiny placeholder files plus a metadata mapping; no Nextcloud
original media is stored locally. The authenticated Nextcloud user is never used
as an album name.

Each placeholder carries the original image dimensions. Dimensions are read
from Nextcloud metadata first. If those metadata are unavailable, only the
beginning of the original file is read through WebDAV and parsed locally.
"""

from __future__ import annotations

import argparse
import base64
import getpass
import json
import os
import shutil
import socket
import ssl
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from contextlib import contextmanager
from pathlib import Path, PurePosixPath

DAV = "DAV:"
OC = "http://owncloud.org/ns"
NC = "http://nextcloud.org/ns"
SUPPORTED_IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".gif", ".webp"}
PLACEHOLDER = base64.b64decode("R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==")
DIMENSION_PROBE_BYTES = 4 * 1024 * 1024


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


def parse_dimensions(prop: ET.Element) -> tuple[int, int]:
    for property_name in ("file-metadata-size", "metadata-photos-size"):
        raw = prop.findtext(f"{{{NC}}}{property_name}", default="").strip()
        if not raw:
            continue
        try:
            decoded = json.loads(raw)
        except (TypeError, ValueError, json.JSONDecodeError):
            continue
        if isinstance(decoded, dict) and isinstance(decoded.get("value"), dict):
            decoded = decoded["value"]
        if not isinstance(decoded, dict):
            continue
        try:
            width = int(decoded.get("width", 0) or 0)
            height = int(decoded.get("height", 0) or 0)
        except (TypeError, ValueError):
            continue
        if width > 0 and height > 0:
            return width, height
    return 0, 0


def parse_image_header_dimensions(data: bytes) -> tuple[int, int]:
    if len(data) >= 24 and data.startswith(b"\x89PNG\r\n\x1a\n") and data[12:16] == b"IHDR":
        return int.from_bytes(data[16:20], "big"), int.from_bytes(data[20:24], "big")

    if len(data) >= 10 and data[:6] in (b"GIF87a", b"GIF89a"):
        return int.from_bytes(data[6:8], "little"), int.from_bytes(data[8:10], "little")

    if len(data) >= 12 and data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        chunk = data[12:16]
        if chunk == b"VP8X" and len(data) >= 30:
            width = 1 + int.from_bytes(data[24:27], "little")
            height = 1 + int.from_bytes(data[27:30], "little")
            return width, height
        if chunk == b"VP8 " and len(data) >= 30:
            payload = 20
            if data[payload + 3:payload + 6] == b"\x9d\x01\x2a":
                width = int.from_bytes(data[payload + 6:payload + 8], "little") & 0x3FFF
                height = int.from_bytes(data[payload + 8:payload + 10], "little") & 0x3FFF
                return width, height
        if chunk == b"VP8L" and len(data) >= 25 and data[20] == 0x2F:
            b1, b2, b3, b4 = data[21:25]
            width = 1 + b1 + ((b2 & 0x3F) << 8)
            height = 1 + ((b2 & 0xC0) >> 6) + (b3 << 2) + ((b4 & 0x0F) << 10)
            return width, height

    if len(data) >= 4 and data[:2] == b"\xff\xd8":
        sof_markers = {
            0xC0, 0xC1, 0xC2, 0xC3,
            0xC5, 0xC6, 0xC7,
            0xC9, 0xCA, 0xCB,
            0xCD, 0xCE, 0xCF,
        }
        pos = 2
        while pos + 4 <= len(data):
            if data[pos] != 0xFF:
                pos += 1
                continue
            while pos < len(data) and data[pos] == 0xFF:
                pos += 1
            if pos >= len(data):
                break
            marker = data[pos]
            pos += 1
            if marker in (0xD8, 0xD9) or 0xD0 <= marker <= 0xD7:
                continue
            if marker == 0xDA:
                break
            if pos + 2 > len(data):
                break
            segment_length = int.from_bytes(data[pos:pos + 2], "big")
            if segment_length < 2:
                break
            if marker in sof_markers:
                if pos + 7 > len(data):
                    break
                height = int.from_bytes(data[pos + 3:pos + 5], "big")
                width = int.from_bytes(data[pos + 5:pos + 7], "big")
                return width, height
            pos += segment_length

    return 0, 0


def placeholder_bytes(width: int, height: int) -> bytes:
    if not (1 <= width <= 65535 and 1 <= height <= 65535):
        fail(f"unsupported image dimensions for placeholder: {width}x{height}")
    data = bytearray(PLACEHOLDER)
    data[6:8] = width.to_bytes(2, "little")
    data[8:10] = height.to_bytes(2, "little")
    return bytes(data)


@contextmanager
def pinned_resolution(host: str, ip: str):
    host = host.strip("[]").lower()
    ip = ip.strip("[]")
    if not host or not ip or host == ip:
        yield
        return

    original = socket.getaddrinfo

    def resolve(name, port, family=0, type=0, proto=0, flags=0):
        normalized = str(name).strip("[]").lower()
        if normalized == host:
            return original(ip, port, family, type, proto, flags)
        return original(name, port, family, type, proto, flags)

    socket.getaddrinfo = resolve
    try:
        yield
    finally:
        socket.getaddrinfo = original


class WebDavClient:
    def __init__(self, base_url: str, user: str, password: str, timeout: int = 30, connect_ip: str = "") -> None:
        self.base_url = base_url.rstrip("/")
        parsed = urllib.parse.urlparse(self.base_url)
        if parsed.scheme not in {"http", "https"} or not parsed.hostname:
            fail("Nextcloud base URL must use HTTP or HTTPS and contain a host")
        self.host = parsed.hostname.strip("[]")
        self.connect_ip = connect_ip.strip("[]") or self.host
        self.user = user
        self.password = password
        self.timeout = timeout
        token = base64.b64encode(f"{user}:{password}".encode("utf-8")).decode("ascii")
        self.auth_header = f"Basic {token}"
        self.context = ssl.create_default_context()

    def file_url(self, relative: str) -> str:
        user = urllib.parse.quote(self.user, safe="")
        suffix = quote_path(relative)
        return f"{self.base_url}/remote.php/dav/files/{user}/{suffix}"

    def collection_url(self, relative: str) -> str:
        url = self.file_url(relative)
        if not url.endswith("/"):
            url += "/"
        return url

    def probe_dimensions(self, relative: str) -> tuple[int, int]:
        request = urllib.request.Request(self.file_url(relative), method="GET")
        request.add_header("Authorization", self.auth_header)
        request.add_header("Range", f"bytes=0-{DIMENSION_PROBE_BYTES - 1}")
        try:
            with pinned_resolution(self.host, self.connect_ip):
                with urllib.request.urlopen(request, timeout=self.timeout, context=self.context) as response:
                    if response.status not in (200, 206):
                        fail(f"Nextcloud image header returned HTTP {response.status}")
                    data = response.read(DIMENSION_PROBE_BYTES)
        except urllib.error.HTTPError as error:
            if error.code in {401, 403}:
                fail("Nextcloud rejected the WebDAV credentials or file access")
            fail(f"Nextcloud image header request failed with HTTP {error.code}")
        except urllib.error.URLError as error:
            fail(f"Nextcloud WebDAV is unreachable while reading image dimensions: {error.reason}")

        width, height = parse_image_header_dimensions(data)
        if width < 1 or height < 1:
            fail(f"original image dimensions could not be read from Nextcloud file header: {relative}")
        return width, height

    def list_collection(self, relative: str) -> tuple[dict[str, object], list[dict[str, object]]]:
        relative = validate_relative(relative)
        url = self.collection_url(relative)
        body = (
            '<?xml version="1.0"?>'
            '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" '
            'xmlns:nc="http://nextcloud.org/ns">'
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
            with pinned_resolution(self.host, self.connect_ip):
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

        base_path = urllib.parse.unquote(urllib.parse.urlparse(url).path).rstrip("/")
        current: dict[str, object] | None = None
        children: list[dict[str, object]] = []
        try:
            root = ET.fromstring(payload)
        except ET.ParseError as error:
            raise RuntimeError("Nextcloud returned invalid WebDAV XML") from error

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
            width, height = parse_dimensions(prop)
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


def write_placeholder(target: Path, width: int, height: int) -> None:
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(placeholder_bytes(width, height))
    os.chmod(target, 0o644)


def build_root(client: WebDavClient, remote_root: str, local_root: Path, mapping: dict[str, dict[str, object]]) -> tuple[int, int, int, int]:
    files = 0
    folders = 0
    skipped = 0
    probed = 0
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

            width = int(child.get("width", 0) or 0)
            height = int(child.get("height", 0) or 0)
            dimension_source = "metadata"
            if width < 1 or height < 1:
                width, height = client.probe_dimensions(child_remote)
                dimension_source = "header"
                probed += 1

            write_placeholder(child_local, width, height)
            files += 1
            mapping[str(child_local)] = {
                "kind": "file",
                "fileid": int(child.get("fileid", 0)),
                "webdav_path": child_remote,
                "display_name": name,
                "content_type": str(child.get("content_type", "")),
                "size": int(child.get("size", 0)),
                "etag": str(child.get("etag", "")),
                "width": width,
                "height": height,
                "dimension_source": dimension_source,
            }
    return files, folders, skipped, probed


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
    parser.add_argument("--connect-ip", default="", help="IP address used for the TCP connection while preserving the URL host for HTTP Host and TLS SNI")
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
        source_dir.parent.mkdir(parents=True, exist_ok=True)
        if staging.exists():
            shutil.rmtree(staging)
        staging.mkdir(parents=True)

        client = WebDavClient(args.base_url, args.user, password, max(1, args.timeout), args.connect_ip)
        mapping: dict[str, dict[str, object]] = {}
        manifest: list[str] = []
        total_files = total_folders = total_skipped = total_probed = 0
        used_fileids: set[int] = set()

        for remote_root_raw in args.root:
            remote_root = validate_relative(remote_root_raw)
            current, root_children = client.list_collection(remote_root)
            fileid = int(current["fileid"])
            if fileid in used_fileids:
                fail(f"duplicate selected Nextcloud root fileid: {fileid}")
            used_fileids.add(fileid)
            local_name = f"root-{fileid}"
            local_root = staging / local_name
            files, folders, skipped, probed = build_root(client, remote_root, local_root, mapping)
            total_files += files
            total_folders += folders
            total_skipped += skipped
            total_probed += probed

            if remote_root == "":
                for child in sorted(root_children, key=lambda item: str(item.get("display_name", "")).casefold()):
                    name = safe_local_name(str(child.get("display_name", "")))
                    child_fileid = int(child.get("fileid", 0))
                    if child_fileid < 1:
                        fail(f"Nextcloud returned no stable fileid for root child {name!r}")
                    child_path = source_dir / local_name / name
                    if bool(child.get("is_dir")):
                        manifest.append(f"webdav:{child_fileid}\tfolder\t{name}\t{child_path}")
                    elif Path(name).suffix.lower() in SUPPORTED_IMAGE_EXTENSIONS:
                        manifest.append(f"webdav:{child_fileid}\tfile\t{name}\t{child_path}")
                continue

            display = str(current.get("display_name", "")).strip() or PurePosixPath(remote_root).name
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
            "version": 3,
            "base_url": args.base_url.rstrip("/"),
            "connect_ip": client.connect_ip,
            "user": args.user,
            "files": final_mapping,
        })
        print(json.dumps({
            "roots": len(args.root),
            "files": total_files,
            "folders": total_folders,
            "skipped": total_skipped,
            "dimension_header_probes": total_probed,
            "connect_ip": client.connect_ip,
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
