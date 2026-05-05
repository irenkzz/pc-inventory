from __future__ import annotations

import contextlib
import importlib.util
import io
import json
import sys
import traceback
from datetime import datetime
from pathlib import Path


ROOT = Path(__file__).resolve().parent
LOG_DIR = ROOT / "logs"
LOG_PATH = LOG_DIR / "collector-task.log"
RELAY_PATH = ROOT / "relay.py"
CONFIG_PATH = ROOT / "collector_config.json"


def write_log(message: str) -> None:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    line = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {message}"
    with LOG_PATH.open("a", encoding="utf-8") as handle:
        handle.write(line + "\n")


def load_relay_module():
    spec = importlib.util.spec_from_file_location("inventory_collector_relay", RELAY_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Could not load relay module: {RELAY_PATH}")

    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def main() -> int:
    write_log("Collector scheduled task started.")

    if not RELAY_PATH.exists():
        raise FileNotFoundError(f"relay.py not found: {RELAY_PATH}")

    if not CONFIG_PATH.exists():
        raise FileNotFoundError(f"collector_config.json not found: {CONFIG_PATH}")

    relay = load_relay_module()
    cfg = relay.load_config(str(CONFIG_PATH))

    relay.ensure_share_layout(cfg["share_root"])
    heartbeats = relay.flush_heartbeats(cfg)
    inventory = relay.flush_inventory(cfg)
    acknowledgements = relay.flush_command_acks(cfg)
    commands = relay.pull_commands(cfg)
    relay.send_collector_status(cfg)

    write_log(json.dumps({
        "heartbeats_sent": heartbeats,
        "inventory_sent": inventory,
        "command_acks_sent": acknowledgements,
        "commands_pulled": commands,
    }, ensure_ascii=False))
    write_log("Collector scheduled task completed successfully.")

    return 0


if __name__ == "__main__":
    stdout = io.StringIO()
    stderr = io.StringIO()

    with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
        try:
            exit_code = main()
        except Exception as exc:
            write_log("ERROR: " + str(exc))
            write_log(traceback.format_exc().rstrip())
            exit_code = 1

    for output in [stdout.getvalue(), stderr.getvalue()]:
        output = output.strip()
        if output:
            write_log(output)

    sys.exit(exit_code)
