#!/usr/bin/env python3
"""
OMS — ONU export uploader.

Runs on a server that can reach the network management system's share (the UAT server, 10.32.28.32), and sends the
newest ONU export dump to OMS over HTTPS. OMS reads it (oms:sync-onu-export): the customers' OLT, slot, port, ONU
type and physical address are brought up to date, every change kept in the customer's ONU history.

    CS-Reports_12_00_00_20261021_120000_p1.csv   →   POST {OMS_URL}/api/integrations/onu-export/{source}

What it does, each run:
  1. finds the newest complete dump in the folder (all its parts; a file written in the last two minutes waits);
  2. skips it when it was already sent (state file) — OMS also reads a dump only once;
  3. sends each part gzip-compressed, with its SHA-256, the shared secret as a Bearer token, over HTTPS (the
     certificate is checked); retries a few times on network errors and 5xx / 429 answers;
  4. remembers the dump once OMS has taken every part.

Only the Python 3 standard library (3.8+). The secret is read from the environment or a root-only file, and never
printed. Settings (environment variables, or KEY=value lines in the file given with --config):

    OMS_URL          https://oms.savannacrm.co.ug:2023        (https only, unless --allow-http)
    OMS_ONU_TOKEN    the same long secret as OMS_ONU_EXPORT_TOKEN in OMS's .env
    ONU_EXPORT_DIR   the dump folder, e.g. /mnt/umc_reports/onustatusreports/Resource_Statistics/ONU_Export
    ONU_SOURCE       fiberhome (default)
    STATE_FILE       /var/lib/oms-onu-uploader/state.json (default)
    CA_FILE          optional: a CA bundle for OMS's certificate (default: the system's)

Usage:
    onu_export_uploader.py --config /etc/oms-onu-uploader.env            send the newest dump if not sent yet
    onu_export_uploader.py --config … --dry-run                          only say what would be sent
    onu_export_uploader.py --config … --force                            send the newest dump again
    onu_export_uploader.py --config … --file /path/CS-Reports_…_p1.csv   send this file

Exit code 0: sent, or nothing new. 1: not sent (the reason is logged). 2: wrong settings.
"""

from __future__ import annotations

import argparse
import gzip
import hashlib
import json
import logging
import os
import re
import ssl
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

FILE_PATTERN = re.compile(r"^CS-Reports_\d{2}_\d{2}_\d{2}_(\d{8})_(\d{6})_p(\d+)\.csv$", re.IGNORECASE)
SETTLE_SECONDS = 120          # a file written more recently may still be being written
TIMEOUT_SECONDS = 300         # per request
RETRY_WAITS = (30, 90, 180)   # seconds between attempts
SOURCE_PATTERN = re.compile(r"^[a-z0-9_]+$")

log = logging.getLogger("onu-uploader")


class SettingsError(Exception):
    pass


def load_settings(config_path: str | None) -> dict[str, str]:
    """Environment variables, overridden by KEY=value lines of the config file (which must not be world-readable)."""
    settings = {key: os.environ.get(key, "") for key in ("OMS_URL", "OMS_ONU_TOKEN", "ONU_EXPORT_DIR", "ONU_SOURCE", "STATE_FILE", "CA_FILE")}

    if config_path:
        path = Path(config_path)
        if not path.is_file():
            raise SettingsError(f"config file not found: {path}")
        if os.name == "posix" and path.stat().st_mode & 0o077:
            raise SettingsError(f"{path} can be read by other users: chmod 600 it (it holds the secret)")
        for line in path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            settings[key.strip()] = value.strip().strip('"').strip("'")

    settings["ONU_SOURCE"] = settings.get("ONU_SOURCE") or "fiberhome"
    settings["STATE_FILE"] = settings.get("STATE_FILE") or "/var/lib/oms-onu-uploader/state.json"
    return settings


def check_settings(settings: dict[str, str], allow_http: bool, need_dir: bool) -> None:
    url = settings.get("OMS_URL", "").rstrip("/")
    if not url:
        raise SettingsError("OMS_URL is not set")
    if not url.startswith("https://") and not (allow_http and url.startswith("http://")):
        raise SettingsError("OMS_URL must start with https:// (the secret is sent with every request)")
    if len(settings.get("OMS_ONU_TOKEN", "")) < 32:
        raise SettingsError("OMS_ONU_TOKEN is not set or too short (use the same long secret as OMS_ONU_EXPORT_TOKEN)")
    if not SOURCE_PATTERN.match(settings["ONU_SOURCE"]):
        raise SettingsError("ONU_SOURCE may only hold a-z, 0-9 and _")
    if need_dir and not Path(settings.get("ONU_EXPORT_DIR", "")).is_dir():
        raise SettingsError(f"ONU_EXPORT_DIR is not a folder (is the share mounted?): {settings.get('ONU_EXPORT_DIR')}")


def latest_dump(directory: Path) -> tuple[str, list[Path]] | None:
    """The newest complete dump: its stamp (YYYYMMDDHHMMSS) and its parts, p1 first."""
    dumps: dict[str, dict[int, Path]] = {}
    now = time.time()

    for entry in directory.iterdir():
        match = FILE_PATTERN.match(entry.name)
        if not match or not entry.is_file() or entry.stat().st_mtime > now - SETTLE_SECONDS:
            continue
        dumps.setdefault(match.group(1) + match.group(2), {})[int(match.group(3))] = entry

    if not dumps:
        return None

    stamp = max(dumps)
    parts = dumps[stamp]
    return stamp, [parts[number] for number in sorted(parts)]


def read_state(path: Path) -> dict:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (FileNotFoundError, ValueError):
        return {}


def write_state(path: Path, state: dict) -> None:
    """Written to a temporary file first, then moved over the old one: never left half-written."""
    path.parent.mkdir(parents=True, exist_ok=True)
    handle, temporary = tempfile.mkstemp(dir=str(path.parent), prefix=".state-")
    with os.fdopen(handle, "w", encoding="utf-8") as out:
        json.dump(state, out, indent=2)
    os.replace(temporary, path)


def ssl_context(ca_file: str) -> ssl.SSLContext:
    context = ssl.create_default_context(cafile=ca_file or None)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    return context


def send_part(settings: dict[str, str], path: Path, parts: int, context: ssl.SSLContext | None) -> dict:
    data = path.read_bytes()
    if not data:
        raise RuntimeError(f"{path.name} is empty")

    body = gzip.compress(data, compresslevel=6)
    url = f"{settings['OMS_URL'].rstrip('/')}/api/integrations/onu-export/{settings['ONU_SOURCE']}"
    headers = {
        "Authorization": f"Bearer {settings['OMS_ONU_TOKEN']}",
        "Content-Type": "application/gzip",
        "Accept": "application/json",
        "User-Agent": "oms-onu-uploader/1.0",
        "X-Onu-File": path.name,
        "X-Onu-Sha256": hashlib.sha256(data).hexdigest(),
        "X-Onu-Parts": str(parts),
    }
    log.info("sending %s (%.1f MB, %.1f MB compressed)", path.name, len(data) / 1e6, len(body) / 1e6)

    for attempt in range(len(RETRY_WAITS) + 1):
        request = urllib.request.Request(url, data=body, headers=headers, method="POST")
        try:
            with urllib.request.urlopen(request, timeout=TIMEOUT_SECONDS, context=context) as response:
                answer = json.loads(response.read().decode("utf-8") or "{}")
                log.info("OMS answered %s: %s", response.status, answer.get("status", answer))
                return answer
        except urllib.error.HTTPError as error:
            detail = error.read().decode("utf-8", "replace")[:300]
            # Settings or file problems do not get better by trying again.
            if error.code not in (429, 500, 502, 503, 504):
                raise RuntimeError(f"OMS refused {path.name}: HTTP {error.code} {detail}") from None
            problem = f"HTTP {error.code} {detail}"
        except (urllib.error.URLError, TimeoutError, ConnectionError) as error:
            problem = str(getattr(error, "reason", error))

        if attempt == len(RETRY_WAITS):
            raise RuntimeError(f"could not send {path.name}: {problem}")
        log.warning("attempt %d failed (%s); trying again in %ds", attempt + 1, problem, RETRY_WAITS[attempt])
        time.sleep(RETRY_WAITS[attempt])

    raise RuntimeError("unreachable")


def acquire_lock(state_file: Path):
    """One uploader at a time (a slow upload must not overlap the next run)."""
    state_file.parent.mkdir(parents=True, exist_ok=True)
    handle = open(state_file.with_suffix(".lock"), "w")
    try:
        if os.name == "posix":
            import fcntl
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        else:
            import msvcrt
            msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
    except OSError:
        handle.close()
        return None
    return handle


def main() -> int:
    parser = argparse.ArgumentParser(description="Send the newest ONU export dump to OMS.")
    parser.add_argument("--config", help="KEY=value settings file (chmod 600)")
    parser.add_argument("--file", action="append", help="send this file (repeat for every part of one dump) instead of the newest dump")
    parser.add_argument("--dry-run", action="store_true", help="only say what would be sent")
    parser.add_argument("--force", action="store_true", help="send even if this dump was sent before")
    parser.add_argument("--allow-http", action="store_true", help="allow an http:// OMS_URL (not recommended)")
    parser.add_argument("--log-file", help="also write the log to this file")
    args = parser.parse_args()

    handlers: list[logging.Handler] = [logging.StreamHandler(sys.stdout)]
    if args.log_file:
        handlers.append(logging.FileHandler(args.log_file, encoding="utf-8"))
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s", handlers=handlers)

    try:
        settings = load_settings(args.config)
        check_settings(settings, args.allow_http, need_dir=not args.file)
    except SettingsError as error:
        log.error("settings: %s", error)
        return 2

    state_file = Path(settings["STATE_FILE"])
    lock = acquire_lock(state_file)
    if lock is None:
        log.info("another upload is running; nothing to do")
        return 0

    try:
        if args.file:
            parts = [Path(name) for name in args.file]
            match = FILE_PATTERN.match(parts[0].name)
            if not match or not all(part.is_file() for part in parts):
                log.error("--file must name existing dump files (CS-Reports_HH_MM_SS_YYYYMMDD_HHMMSS_pN.csv)")
                return 2
            stamp = match.group(1) + match.group(2)
        else:
            dump = latest_dump(Path(settings["ONU_EXPORT_DIR"]))
            if dump is None:
                log.info("no complete dump in %s yet", settings["ONU_EXPORT_DIR"])
                return 0
            stamp, parts = dump

        state = read_state(state_file)
        if state.get("last_sent") == stamp and not args.force:
            log.info("dump %s (%s) was already sent; nothing new", stamp, parts[0].name)
            return 0

        if args.dry_run:
            log.info("would send %s", ", ".join(part.name for part in parts))
            return 0

        context = ssl_context(settings.get("CA_FILE", "")) if settings["OMS_URL"].startswith("https://") else None
        for part in parts:
            answer = send_part(settings, part, len(parts), context)

        status = answer.get("status")
        outcomes = {
            "queued": "is reading it",
            "stored": "has kept it (automatic reading is off in OMS until a first dump is applied by hand)",
            "already_read": "had already read it",
        }
        if status not in outcomes:
            log.error("OMS did not take the dump: %s", answer)
            return 1

        write_state(state_file, {"last_sent": stamp, "file": parts[0].name, "sent_at": time.strftime("%Y-%m-%d %H:%M:%S"), "answer": status})
        log.info("dump %s sent: OMS %s", parts[0].name, outcomes[status])
        return 0
    except Exception as error:  # noqa: BLE001 — logged, and the exit code tells cron / systemd
        log.error("%s", error)
        return 1
    finally:
        lock.close()


if __name__ == "__main__":
    sys.exit(main())
