from __future__ import annotations

import argparse
import json
import re
import os
import shutil
import sys
import tempfile
import time
from datetime import UTC, datetime
from pathlib import Path
from urllib import error, parse, request

VERSION = "1.1.0"
MAX_TRANSIENT_RETRIES = 20
MIN_FILE_AGE_SECONDS = 30
ID_RE = re.compile(r"^[A-Za-z0-9._-]{1,64}$")


def now_iso() -> str:
    return datetime.now(UTC).replace(microsecond=0).isoformat()


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=path.parent, prefix=path.name + ".", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write(json.dumps(payload, ensure_ascii=False, indent=2))
        os.replace(tmp, path)
    except BaseException:
        Path(tmp).unlink(missing_ok=True)
        raise


def load_config(path: str) -> dict:
    cfg = read_json(Path(path))
    required = ["site_id", "collector_name", "share_root", "server_base_url", "site_token"]
    missing = [key for key in required if not str(cfg.get(key, "")).strip()]
    if missing:
        raise SystemExit(f"Missing config keys: {', '.join(missing)}")
    url = parse.urlparse(str(cfg["server_base_url"]).strip())
    insecure_ok = url.hostname in ("localhost", "127.0.0.1") or cfg.get("allow_insecure_http") is True
    if url.scheme != "https" and not (url.scheme == "http" and insecure_ok):
        raise SystemExit("server_base_url must be https:// (http only for localhost or allow_insecure_http: true); refusing to send site token.")
    cfg.setdefault("collector_version", VERSION)
    cfg.setdefault("poll_interval_minutes", 5)
    return cfg


def share_paths(share_root: str) -> dict[str, Path]:
    root = Path(share_root)
    return {
        "root": root,
        "inventory": root / "data" / "inventory-results",
        "heartbeats": root / "data" / "runner-heartbeats",
        "archive_inventory": root / "archive" / "inventory-results",
        "archive_heartbeats": root / "archive" / "runner-heartbeats",
        "failed_inventory": root / "failed" / "inventory-results",
        "failed_heartbeats": root / "failed" / "runner-heartbeats",
        "control": root / "control" / "commands",
        "command_acks": root / "control" / "command-acks",
        "archive_command_acks": root / "archive" / "command-acks",
        "failed_command_acks": root / "failed" / "command-acks",
        "packages": root / "packages" / "runner",
        "manifest": root / "packages" / "runner" / "runner-manifest.json",
        "logs": root / "logs",
    }


def ensure_share_layout(share_root: str) -> None:
    for path in share_paths(share_root).values():
        if path.suffix:
            path.parent.mkdir(parents=True, exist_ok=True)
        else:
            path.mkdir(parents=True, exist_ok=True)


def http_json(method: str, url: str, headers: dict[str, str], payload: dict | None = None):
    data = None
    final_headers = dict(headers)
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        final_headers["Content-Type"] = "application/json"
    req = request.Request(url, data=data, headers=final_headers, method=method)
    with request.urlopen(req, timeout=20) as resp:
        body = resp.read().decode("utf-8")
        return json.loads(body) if body else {}


def multipart_encode(fields: dict[str, str], file_field: str, file_name: str, file_bytes: bytes) -> tuple[bytes, str]:
    boundary = "----InventoryCollectorBoundary7MA4YWxkTrZu0gW"
    chunks: list[bytes] = []
    for key, value in fields.items():
        chunks.append(f"--{boundary}\r\n".encode())
        chunks.append(f'Content-Disposition: form-data; name="{key}"\r\n\r\n'.encode())
        chunks.append(str(value).encode("utf-8"))
        chunks.append(b"\r\n")
    chunks.append(f"--{boundary}\r\n".encode())
    chunks.append(f'Content-Disposition: form-data; name="{file_field}"; filename="{file_name}"\r\n'.encode())
    chunks.append(b"Content-Type: text/csv\r\n\r\n")
    chunks.append(file_bytes)
    chunks.append(b"\r\n")
    chunks.append(f"--{boundary}--\r\n".encode())
    body = b"".join(chunks)
    return body, boundary


def post_file(url: str, headers: dict[str, str], fields: dict[str, str], file_path: Path) -> dict:
    body, boundary = multipart_encode(fields, "file", file_path.name, file_path.read_bytes())
    final_headers = dict(headers)
    final_headers["Content-Type"] = f"multipart/form-data; boundary={boundary}"
    req = request.Request(url, data=body, headers=final_headers, method="POST")
    with request.urlopen(req, timeout=60) as resp:
        body_text = resp.read().decode("utf-8")
        return json.loads(body_text) if body_text else {}


def send_collector_status(cfg: dict) -> None:
    paths = share_paths(cfg["share_root"])
    payload = {
        "site_id": cfg["site_id"],
        "site_name": cfg.get("site_name", ""),
        "collector_name": cfg["collector_name"],
        "collector_version": cfg.get("collector_version", VERSION),
        "share_root_hint": cfg["share_root"],
        "queue_depth_csv": len(list(paths["inventory"].glob("*.csv"))),
        "queue_depth_heartbeat": len(list(paths["heartbeats"].glob("*.json"))),
        "last_seen_at": now_iso(),
        "last_status": "ok",
        "last_error": "",
        "poll_interval_minutes": int(cfg.get("poll_interval_minutes", 5) or 5),
    }
    http_json(
        "POST",
        cfg["server_base_url"].rstrip("/") + "/api/collector/status",
        {"X-Site-Id": cfg["site_id"], "X-Site-Token": cfg["site_token"]},
        payload,
    )


def is_transient(exc: Exception) -> bool:
    """Network error/timeout/5xx/429 are retryable; other HTTP 4xx are permanent."""
    if isinstance(exc, error.HTTPError):
        return exc.code >= 500 or exc.code == 429
    return True


def _retry_path(path: Path) -> Path:
    return path.with_name(path.name + ".retries")


def _bump_retries(path: Path) -> int:
    rp = _retry_path(path)
    try:
        count = int(rp.read_text().strip() or 0)
    except (OSError, ValueError):
        count = 0
    count += 1
    rp.write_text(str(count))
    return count


def _move(path: Path, target_dir: Path) -> None:
    target_dir.mkdir(parents=True, exist_ok=True)
    shutil.move(str(path), str(target_dir / path.name))
    _retry_path(path).unlink(missing_ok=True)


def _is_young(path: Path) -> bool:
    return time.time() - path.stat().st_mtime < MIN_FILE_AGE_SECONDS


def deliver(path: Path, send, archive_dir: Path, failed_dir: Path) -> str:
    """Returns 'sent', 'failed' (moved to failed/) or 'stop' (transient; file left for next run)."""
    try:
        send()
    except Exception as exc:
        if is_transient(exc):
            if _bump_retries(path) < MAX_TRANSIENT_RETRIES:
                print(f"transient error for {path.name}: {type(exc).__name__}; will retry", file=sys.stderr)
                return "stop"
            print(f"{path.name}: retry limit reached; moving to failed", file=sys.stderr)
        _move(path, failed_dir)
        return "failed"
    _move(path, archive_dir)
    return "sent"


def valid_id(value: str) -> bool:
    return bool(ID_RE.match(value)) and ".." not in value


def flush_heartbeats(cfg: dict) -> int:
    paths = share_paths(cfg["share_root"])
    sent = 0
    for path in sorted(paths["heartbeats"].glob("*.json")):
        if _is_young(path):
            continue

        def send(path=path):
            payload = read_json(path)
            payload.setdefault("site_id", cfg["site_id"])
            payload.setdefault("site_name", cfg.get("site_name", ""))
            payload.setdefault("collector_name", cfg["collector_name"])
            http_json(
                "POST",
                cfg["server_base_url"].rstrip("/") + "/api/collector/heartbeat",
                {"X-Site-Id": cfg["site_id"], "X-Site-Token": cfg["site_token"]},
                payload,
            )

        result = deliver(path, send, paths["archive_heartbeats"], paths["failed_heartbeats"])
        if result == "stop":
            break
        sent += result == "sent"
    return sent


def flush_inventory(cfg: dict) -> int:
    paths = share_paths(cfg["share_root"])
    sent = 0
    for path in sorted(paths["inventory"].glob("*.csv")):
        if _is_young(path):
            continue
        parts = path.stem.split("__")
        runner_id = parts[0] if parts else path.stem
        if "__" not in path.stem:
            runner_id = re.sub(r"-\d{8}-\d{6}$", "", runner_id)
        if not valid_id(runner_id):
            print(f"skipping {path.name}: invalid runner_id", file=sys.stderr)
            continue
        heartbeat_path = paths["archive_heartbeats"] / f"{runner_id}.json"
        if not heartbeat_path.exists():
            live_heartbeat_path = paths["heartbeats"] / f"{runner_id}.json"
            if live_heartbeat_path.exists():
                heartbeat_path = live_heartbeat_path

        def send(path=path, runner_id=runner_id, heartbeat_path=heartbeat_path):
            state = read_json(heartbeat_path) if heartbeat_path.exists() else {"runner_id": runner_id, "hostname": runner_id}
            fields = {
                "site_name": cfg.get("site_name", ""),
                "collector_name": cfg["collector_name"],
                "runner_id": state.get("runner_id", runner_id),
                "hostname": state.get("hostname", runner_id),
                "runner_version": state.get("runner_version", state.get("version", "")),
                "last_successful_inventory_at": state.get("last_successful_inventory_at", state.get("last_scan_time", "")),
                "last_inventory_status": state.get("last_inventory_status", state.get("last_status", "success")),
                "last_seen_at": state.get("last_seen_at", now_iso()),
            }
            post_file(
                cfg["server_base_url"].rstrip("/") + "/api/collector/intake/csv",
                {"X-Site-Id": cfg["site_id"], "X-Site-Token": cfg["site_token"]},
                fields,
                path,
            )

        result = deliver(path, send, paths["archive_inventory"], paths["failed_inventory"])
        if result == "stop":
            break
        sent += result == "sent"
    return sent


def flush_command_acks(cfg: dict) -> int:
    paths = share_paths(cfg["share_root"])
    sent = 0
    for path in sorted(paths["command_acks"].glob("*.json")):
        if _is_young(path):
            continue

        def send(path=path):
            http_json(
                "POST",
                cfg["server_base_url"].rstrip("/") + "/api/collector/command-ack",
                {"X-Site-Id": cfg["site_id"], "X-Site-Token": cfg["site_token"]},
                read_json(path),
            )

        result = deliver(path, send, paths["archive_command_acks"], paths["failed_command_acks"])
        if result == "stop":
            break
        sent += result == "sent"
    return sent


def pull_commands(cfg: dict) -> int:
    paths = share_paths(cfg["share_root"])
    commands = http_json(
        "GET",
        cfg["server_base_url"].rstrip("/") + f"/api/collector/commands?site_id={parse.quote(cfg['site_id'])}",
        {"X-Site-Id": cfg["site_id"], "X-Site-Token": cfg["site_token"]},
    )
    if not isinstance(commands, list):
        return 0
    count = 0
    for item in commands:
        if not isinstance(item, dict):
            continue
        runner_id = str(item.get("runner_id", "")).strip()
        if not runner_id:
            continue
        if not valid_id(runner_id):
            print("skipping command with invalid runner_id", file=sys.stderr)
            continue
        write_json(paths["control"] / f"{runner_id}.json", item)
        count += 1
    return count


def requeue_failed(cfg: dict) -> int:
    paths = share_paths(cfg["share_root"])
    count = 0
    for failed_key, inbox_key in (
        ("failed_inventory", "inventory"),
        ("failed_heartbeats", "heartbeats"),
        ("failed_command_acks", "command_acks"),
    ):
        paths[inbox_key].mkdir(parents=True, exist_ok=True)
        for path in paths[failed_key].glob("*"):
            if path.is_file() and path.suffix in (".csv", ".json"):
                shutil.move(str(path), str(paths[inbox_key] / path.name))
                _retry_path(paths[inbox_key] / path.name).unlink(missing_ok=True)
                count += 1
    return count


def cmd_requeue_failed(args: argparse.Namespace) -> None:
    cfg = load_config(args.config)
    print(json.dumps({"requeued": requeue_failed(cfg)}, indent=2))

def cmd_init_share(args: argparse.Namespace) -> None:
    cfg = load_config(args.config)
    ensure_share_layout(cfg["share_root"])
    paths = share_paths(cfg["share_root"])
    if not paths["manifest"].exists():
        write_json(paths["manifest"], {"runner_version": "1.0.0", "package_folder": "current"})
    print(f"Branch share initialized at: {cfg['share_root']}")


def best_effort(label: str, step, default):
    try:
        return step()
    except Exception as exc:
        print(f"{label} failed: {type(exc).__name__}: {exc}", file=sys.stderr)
        return default


def cmd_run_once(args: argparse.Namespace) -> None:
    cfg = load_config(args.config)
    ensure_share_layout(cfg["share_root"])
    hb = flush_heartbeats(cfg)
    inv = flush_inventory(cfg)
    ack = flush_command_acks(cfg)
    # Best effort: a 429/5xx here (for example while draining a backlog) must not crash the run.
    cmd = best_effort("pull_commands", lambda: pull_commands(cfg), 0)
    best_effort("send_collector_status", lambda: send_collector_status(cfg), None)
    print(json.dumps({"heartbeats_sent": hb, "inventory_sent": inv, "command_acks_sent": ack, "commands_pulled": cmd}, indent=2))


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Branch collector relay")
    sub = parser.add_subparsers(dest="command", required=True)
    p1 = sub.add_parser("init-share")
    p1.add_argument("--config", required=True)
    p1.set_defaults(func=cmd_init_share)
    p2 = sub.add_parser("run-once")
    p2.add_argument("--config", required=True)
    p2.set_defaults(func=cmd_run_once)
    p3 = sub.add_parser("requeue-failed")
    p3.add_argument("--config", required=True)
    p3.set_defaults(func=cmd_requeue_failed)
    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
