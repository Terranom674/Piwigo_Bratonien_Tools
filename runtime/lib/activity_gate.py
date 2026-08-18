#!/usr/bin/env python3
"""Debounce Nextcloud activity for one connector scope."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
import time
from pathlib import Path

IDENTIFIER = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$")


def validate_view(name: str) -> str:
    value = str(name).strip()
    if not IDENTIFIER.fullmatch(value):
        raise ValueError(f"invalid SQL view name: {value!r}")
    return value


def sql_literal(value: str) -> str:
    return "'" + str(value).replace("'", "''") + "'"


def load(path: Path) -> dict[str, object]:
    defaults: dict[str, object] = {
        "processed": 0, "observed": 0, "pending_since": 0,
        "last_change": 0, "last_full": 0, "source_signature": "",
    }
    if not path.exists():
        return defaults
    data = json.loads(path.read_text(encoding="utf-8"))
    return {
        "processed": int(data.get("processed", 0)),
        "observed": int(data.get("observed", 0)),
        "pending_since": int(data.get("pending_since", 0)),
        "last_change": int(data.get("last_change", 0)),
        "last_full": int(data.get("last_full", 0)),
        "source_signature": str(data.get("source_signature", "")),
    }


def save(path: Path, state: dict[str, object]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(dir=path.parent)
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        json.dump(state, handle, sort_keys=True)
        handle.write("\n")
    Path(name).replace(path)


def query(args: argparse.Namespace, sql: str) -> str:
    env = os.environ.copy()
    env["PGPASSWORD"] = args.password_file.read_text(encoding="utf-8").strip()
    command = [
        "psql", "-XAt", "-h", args.host, "-p", str(args.port),
        "-U", args.user, "-d", args.database, "-v", "ON_ERROR_STOP=1", "-c", sql,
    ]
    return subprocess.run(command, env=env, check=True, text=True, capture_output=True).stdout


def user_where(args: argparse.Namespace) -> str:
    if not args.access_user:
        return ""
    return " WHERE lower(access_user) = lower(" + sql_literal(args.access_user) + ")"


def latest(args: argparse.Namespace) -> int:
    view = validate_view(args.view)
    return int(query(args, f"SELECT COALESCE(MAX(activity_id), 0) FROM {view}{user_where(args)}").strip())


def source_signature(args: argparse.Namespace) -> str:
    if args.roots_config:
        return hashlib.sha256(args.roots_config.read_bytes()).hexdigest()
    if not args.source_view:
        return ""
    view = validate_view(args.source_view)
    payload = query(args, f"SELECT share_id, display_name, storage_id, source_path FROM {view}{user_where(args)} ORDER BY share_id")
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("check", "commit"))
    parser.add_argument("--state", required=True, type=Path)
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=5432)
    parser.add_argument("--database", required=True)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password-file", required=True, type=Path)
    parser.add_argument("--view", required=True)
    parser.add_argument("--source-view", default="")
    parser.add_argument("--roots-config", type=Path)
    parser.add_argument("--access-user", default="")
    parser.add_argument("--quiet", type=int, default=120)
    parser.add_argument("--max-wait", type=int, default=900)
    parser.add_argument("--full-after", type=int, default=86400)
    args = parser.parse_args()
    try:
        validate_view(args.view)
        if args.source_view:
            validate_view(args.source_view)
        state = load(args.state)
        now = int(time.time())
        current = latest(args)
        current_source = source_signature(args)
        if args.action == "commit":
            state.update(processed=current, observed=current, pending_since=0, last_change=0, last_full=now, source_signature=current_source)
            save(args.state, state)
            return 0
        if not int(state["last_full"]):
            return 0
        if current_source and current_source != str(state["source_signature"]):
            return 0
        if current > int(state["observed"]):
            state["observed"] = current
            state["last_change"] = now
            state["pending_since"] = int(state["pending_since"]) or now
            save(args.state, state)
        if now - int(state["last_full"]) >= args.full_after:
            return 0
        if int(state["observed"]) <= int(state["processed"]):
            return 3
        if now - int(state["last_change"]) >= args.quiet or now - int(state["pending_since"]) >= args.max_wait:
            return 0
        return 3
    except Exception as error:
        print(f"activity-gate: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
