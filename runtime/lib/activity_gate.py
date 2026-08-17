#!/usr/bin/env python3
"""Debounce Nextcloud activity and request a periodic safety reconciliation."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import tempfile
import time
from pathlib import Path


def load(path: Path) -> dict[str, int]:
    if not path.exists():
        return {"processed": 0, "observed": 0, "pending_since": 0, "last_change": 0, "last_full": 0}
    data = json.loads(path.read_text(encoding="utf-8"))
    return {key: int(data.get(key, 0)) for key in ("processed", "observed", "pending_since", "last_change", "last_full")}


def save(path: Path, state: dict[str, int]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(dir=path.parent)
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        json.dump(state, handle, sort_keys=True)
        handle.write("\n")
    Path(name).replace(path)


def latest(args: argparse.Namespace) -> int:
    env = os.environ.copy()
    env["PGPASSWORD"] = args.password_file.read_text(encoding="utf-8").strip()
    sql = f"SELECT COALESCE(MAX(activity_id), 0) FROM {args.view}"
    command = ["psql", "-XAt", "-h", args.host, "-p", str(args.port), "-U", args.user, "-d", args.database, "-c", sql]
    return int(subprocess.run(command, env=env, check=True, text=True, capture_output=True).stdout.strip())


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("check", "commit"))
    parser.add_argument("--state", required=True, type=Path)
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=5432)
    parser.add_argument("--database", required=True)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password-file", required=True, type=Path)
    parser.add_argument("--view", default="piwigo_showcase_activity")
    parser.add_argument("--quiet", type=int, default=120)
    parser.add_argument("--max-wait", type=int, default=900)
    parser.add_argument("--full-after", type=int, default=86400)
    args = parser.parse_args()

    try:
        state = load(args.state)
        now = int(time.time())
        current = latest(args)
        if args.action == "commit":
            state.update(processed=current, observed=current, pending_since=0, last_change=0, last_full=now)
            save(args.state, state)
            return 0

        if not state["last_full"]:
            return 0
        if current > state["observed"]:
            state["observed"] = current
            state["last_change"] = now
            state["pending_since"] = state["pending_since"] or now
            save(args.state, state)
        if now - state["last_full"] >= args.full_after:
            return 0
        if state["observed"] <= state["processed"]:
            return 3
        if now - state["last_change"] >= args.quiet or now - state["pending_since"] >= args.max_wait:
            return 0
        return 3
    except Exception as error:
        print(f"activity-gate: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
