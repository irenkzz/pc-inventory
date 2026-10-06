import json
import os
import sys
import tempfile
import time
import unittest
from io import BytesIO
from pathlib import Path
from urllib import error

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "collector"))
import relay  # noqa: E402


def http_error(code):
    return error.HTTPError("http://x", code, "err", {}, BytesIO(b""))


class RelayTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.cfg = {
            "site_id": "S1", "collector_name": "c", "site_token": "t",
            "share_root": self.tmp.name, "server_base_url": "https://example.test",
        }
        relay.ensure_share_layout(self.tmp.name)
        self.paths = relay.share_paths(self.tmp.name)
        self._orig = (relay.http_json, relay.post_file)

    def tearDown(self):
        relay.http_json, relay.post_file = self._orig
        self.tmp.cleanup()

    def make_hb(self, name="R1.json", age=120):
        p = self.paths["heartbeats"] / name
        p.write_text(json.dumps({"runner_id": "R1"}))
        t = time.time() - age
        os.utime(p, (t, t))
        return p

    def set_http(self, exc=None):
        calls = []

        def fake(*a, **k):
            calls.append(a)
            if exc:
                raise exc
            return {}

        relay.http_json = fake
        return calls

    def test_transient_keeps_file(self):
        for exc in (error.URLError("down"), TimeoutError(), http_error(503), http_error(429)):
            p = self.make_hb()
            self.set_http(exc)
            self.assertEqual(relay.flush_heartbeats(self.cfg), 0)
            self.assertTrue(p.exists())
            self.assertFalse((self.paths["failed_heartbeats"] / p.name).exists())

    def test_transient_stops_batch(self):
        self.make_hb("A.json")
        self.make_hb("B.json")
        calls = self.set_http(http_error(500))
        relay.flush_heartbeats(self.cfg)
        self.assertEqual(len(calls), 1)

    def test_retry_limit_moves_to_failed(self):
        p = self.make_hb()
        self.set_http(http_error(500))
        for _ in range(relay.MAX_TRANSIENT_RETRIES):
            relay.flush_heartbeats(self.cfg)
        self.assertFalse(p.exists())
        self.assertTrue((self.paths["failed_heartbeats"] / p.name).exists())

    def test_4xx_moves_to_failed(self):
        p = self.make_hb()
        self.set_http(http_error(422))
        relay.flush_heartbeats(self.cfg)
        self.assertFalse(p.exists())
        self.assertTrue((self.paths["failed_heartbeats"] / p.name).exists())

    def test_success_archives(self):
        p = self.make_hb()
        self.set_http()
        self.assertEqual(relay.flush_heartbeats(self.cfg), 1)
        self.assertTrue((self.paths["archive_heartbeats"] / p.name).exists())

    def test_requeue(self):
        p = self.make_hb()
        self.set_http(http_error(400))
        relay.flush_heartbeats(self.cfg)
        self.assertEqual(relay.requeue_failed(self.cfg), 1)
        self.assertTrue(p.exists())
        self.assertFalse((self.paths["failed_heartbeats"] / p.name).exists())

    def test_young_file_skipped(self):
        p = self.make_hb(age=1)
        calls = self.set_http()
        self.assertEqual(relay.flush_heartbeats(self.cfg), 0)
        self.assertEqual(calls, [])
        self.assertTrue(p.exists())

    def test_invalid_runner_id_skipped(self):
        bad = self.paths["inventory"] / "bad id!__x.csv"
        bad.write_text("a,b")
        t = time.time() - 120
        os.utime(bad, (t, t))
        posted = []
        relay.post_file = lambda *a, **k: posted.append(a)
        self.assertEqual(relay.flush_inventory(self.cfg), 0)
        self.assertEqual(posted, [])
        self.assertTrue(bad.exists())

    def test_invalid_command_runner_id_skipped(self):
        relay.http_json = lambda *a, **k: [{"runner_id": "../evil"}, {"runner_id": "ok-1"}]
        self.assertEqual(relay.pull_commands(self.cfg), 1)
        self.assertTrue((self.paths["control"] / "ok-1.json").exists())
        self.assertFalse((Path(self.tmp.name) / "control" / "evil.json").exists())

    def test_http_url_refused(self):
        cfgfile = Path(self.tmp.name) / "cfg.json"
        base = dict(self.cfg, server_base_url="http://example.test")
        cfgfile.write_text(json.dumps(base))
        with self.assertRaises(SystemExit):
            relay.load_config(str(cfgfile))
        cfgfile.write_text(json.dumps(dict(base, server_base_url="http://localhost:8000")))
        relay.load_config(str(cfgfile))
        cfgfile.write_text(json.dumps(dict(base, allow_insecure_http=True)))
        relay.load_config(str(cfgfile))

    def test_write_json_atomic_no_leftover(self):
        p = Path(self.tmp.name) / "o.json"
        relay.write_json(p, {"a": 1})
        self.assertEqual(json.loads(p.read_text()), {"a": 1})
        self.assertEqual([f.name for f in Path(self.tmp.name).glob("o.json*")], ["o.json"])


class RunOnceResilienceTest(unittest.TestCase):
    def test_429_on_commands_and_status_does_not_crash(self):
        with tempfile.TemporaryDirectory() as tmp:
            cfg_path = Path(tmp) / "c.json"
            cfg_path.write_text(json.dumps({
                "site_id": "S1", "collector_name": "c", "site_token": "t",
                "share_root": str(Path(tmp) / "share"), "server_base_url": "https://example.test",
            }))
            orig = relay.http_json

            def always_429(*a, **k):
                raise http_error(429)

            relay.http_json = always_429
            try:
                relay.cmd_run_once(type("A", (), {"config": str(cfg_path)})())
            finally:
                relay.http_json = orig


if __name__ == "__main__":
    unittest.main()
