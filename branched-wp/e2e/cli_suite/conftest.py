"""
Pytest fixtures + CLI driver for the ForkPress CLI contract test suite.

Every test in this suite drives the compiled `forkpress` binary. No
script-level shims, no internal introspection — the binary is the
unit under test. If the binary isn't available the whole suite errors
out rather than running a fake.

Binary discovery (first match wins):
    1. $FORKPRESS_BIN
    2. ./target/release/forkpress
    3. ./forkpress/target/release/forkpress
    4. `forkpress` on PATH

Build it with:
    scripts/build-dist.sh && cargo build --release -p forkpress
"""

from __future__ import annotations

import os
import shutil
import signal
import socket
import subprocess
import time
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]


@dataclass
class CLIResult:
    """A subprocess.CompletedProcess wrapper with ergonomic asserts."""

    args: list[str]
    returncode: int
    stdout: str
    stderr: str

    @property
    def ok(self) -> bool:
        return self.returncode == 0

    def expect_ok(self, hint: str = "") -> "CLIResult":
        if self.returncode != 0:
            msg = (
                f"command failed (rc={self.returncode}): {' '.join(self.args)}\n"
                f"stdout:\n{self.stdout}\nstderr:\n{self.stderr}"
            )
            if hint:
                msg += f"\nhint: {hint}"
            raise AssertionError(msg)
        return self

    def expect_fail(self, hint: str = "") -> "CLIResult":
        if self.returncode == 0:
            raise AssertionError(
                f"command unexpectedly succeeded: {' '.join(self.args)}\n"
                f"stdout:\n{self.stdout}\nstderr:\n{self.stderr}"
                + (f"\nhint: {hint}" if hint else "")
            )
        return self

    def combined(self) -> str:
        return self.stdout + self.stderr


class ForkpressCLI:
    """
    Drives the `forkpress` binary. Every verb is a method that returns
    a CLIResult so tests can assert on what the user actually sees.
    """

    def __init__(self, binary: Path, work_dir: Path):
        self.binary = binary
        self.work_dir = work_dir
        self.work_dir.mkdir(parents=True, exist_ok=True)

    # ---- common paths --------------------------------------------------

    @property
    def site_fp(self) -> Path:
        return self.work_dir / "site.fp"

    @property
    def wp_root(self) -> Path:
        return self.work_dir / "wproot"

    @property
    def logs_dir(self) -> Path:
        return self.work_dir / "logs"

    # ---- core runner ---------------------------------------------------

    def _run(self, *args: str, env: Optional[dict] = None,
             timeout: Optional[float] = 180.0) -> CLIResult:
        cmd = [str(self.binary), *args]
        proc = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            env={**os.environ, **(env or {})},
            timeout=timeout,
        )
        return CLIResult(cmd, proc.returncode, proc.stdout, proc.stderr)

    # ---- verbs ---------------------------------------------------------

    def init(self, site_title: str = "ForkPress CLI Suite",
             admin_password: Optional[str] = None) -> CLIResult:
        args = ["init", "--work-dir", str(self.work_dir),
                "--site-title", site_title]
        if admin_password is not None:
            args += ["--admin-password", admin_password]
        return self._run(*args)

    def branch(self, *args: str) -> CLIResult:
        return self._run("branch", "--work-dir", str(self.work_dir), *args)

    def user(self, *args: str) -> CLIResult:
        return self._run("user", "--work-dir", str(self.work_dir), *args)

    def backup(self, src: Path, dst: Path) -> CLIResult:
        return self._run("backup", "--work-dir", str(self.work_dir),
                         str(src), str(dst))

    def export(self, src: Path, out_dir: Path) -> CLIResult:
        return self._run("export", "--work-dir", str(self.work_dir),
                         str(src), str(out_dir))

    def import_(self, in_dir: Path, dst: Path) -> CLIResult:
        return self._run("import", "--work-dir", str(self.work_dir),
                         str(in_dir), str(dst))

    def start(self, host: str, port: int, root_host: str) -> "ServerHandle":
        self.logs_dir.mkdir(parents=True, exist_ok=True)
        log_path = self.logs_dir / "server.log"
        log_fp = open(log_path, "wb")
        proc = subprocess.Popen(
            [
                str(self.binary), "start",
                "--work-dir", str(self.work_dir),
                "--host", host,
                "--port", str(port),
                "--root-host", root_host,
                "--no-fileserver",
            ],
            stdout=log_fp,
            stderr=log_fp,
            env=os.environ.copy(),
        )
        return ServerHandle(proc=proc, log_path=log_path,
                            host=host, port=port, root_host=root_host)


@dataclass
class ServerHandle:
    proc: subprocess.Popen
    log_path: Path
    host: str
    port: int
    root_host: str

    @property
    def base_url(self) -> str:
        return f"http://{self.host}:{self.port}"

    def stop(self) -> None:
        if self.proc.poll() is None:
            self.proc.send_signal(signal.SIGTERM)
            try:
                self.proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                self.proc.kill()
                self.proc.wait(timeout=5)

    def assert_alive(self) -> None:
        if self.proc.poll() is not None:
            log = self.log_path.read_text(errors="replace") if self.log_path.exists() else "(no log)"
            raise AssertionError(
                f"forkpress start exited with rc={self.proc.returncode}\n"
                f"----- server log -----\n{log}"
            )


# ---------------------------------------------------------------------------
# Discovery
# ---------------------------------------------------------------------------


def _find_binary() -> Optional[Path]:
    env_val = os.environ.get("FORKPRESS_BIN")
    if env_val:
        p = Path(env_val)
        if p.is_file() and os.access(p, os.X_OK):
            return p
        raise pytest.UsageError(
            f"FORKPRESS_BIN={env_val!r} is not an executable file"
        )
    for candidate in (
        REPO_ROOT / "target" / "release" / "forkpress",
        REPO_ROOT / "forkpress" / "target" / "release" / "forkpress",
    ):
        if candidate.is_file() and os.access(candidate, os.X_OK):
            return candidate
    from_path = shutil.which("forkpress")
    return Path(from_path) if from_path else None


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(scope="session")
def forkpress_binary() -> Path:
    binary = _find_binary()
    if not binary:
        pytest.fail(
            "forkpress binary not found. Build it:\n"
            "    scripts/build-dist.sh && cargo build --release -p forkpress\n"
            "Or point at an existing build:\n"
            "    export FORKPRESS_BIN=/path/to/forkpress",
            pytrace=False,
        )
    return binary


@pytest.fixture
def fresh_site(tmp_path, forkpress_binary) -> ForkpressCLI:
    """A brand-new work_dir with no init run yet — for tests of `init` itself."""
    return ForkpressCLI(forkpress_binary, tmp_path / ".forkpress")


@pytest.fixture
def site(tmp_path, forkpress_binary) -> ForkpressCLI:
    """A work_dir with `forkpress init` already run."""
    cli = ForkpressCLI(forkpress_binary, tmp_path / ".forkpress")
    cli.init().expect_ok()
    return cli


def _free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def _wait_ready(url: str, handle: ServerHandle, timeout: float = 300.0) -> None:
    deadline = time.monotonic() + timeout
    last_err = None
    while time.monotonic() < deadline:
        handle.assert_alive()
        try:
            with urllib.request.urlopen(url, timeout=5) as resp:
                if resp.status in (200, 301, 302):
                    return
        except Exception as e:
            last_err = e
        time.sleep(1.5)
    handle.assert_alive()
    raise AssertionError(f"server never answered at {url} (last error: {last_err})")


@pytest.fixture(scope="session")
def served_site(tmp_path_factory, forkpress_binary):
    """
    One initialised + bootstrapped + HTTP-serving site, reused across tests.
    Tests share this site — create branches with unique names to avoid
    collisions.
    """
    work_dir = tmp_path_factory.mktemp("fp-served") / ".forkpress"
    cli = ForkpressCLI(forkpress_binary, work_dir)
    cli.init().expect_ok()

    port = _free_port()
    handle = cli.start("127.0.0.1", port, "localhost")
    served = ServedSite(cli=cli, server=handle)
    try:
        _wait_ready(f"{handle.base_url}/", handle, timeout=300)
        yield served
    finally:
        handle.stop()


@dataclass
class ServedSite:
    cli: ForkpressCLI
    server: ServerHandle

    @property
    def base_url(self) -> str:
        return self.server.base_url

    def host_for_branch(self, branch: str) -> str:
        if branch == "main":
            return "localhost"
        return f"{branch}.localhost"

    def get(self, path: str, branch: str = "main", **kw):
        import requests
        headers = dict(kw.pop("headers", {}))
        headers.setdefault("Host", self.host_for_branch(branch))
        return requests.get(self.base_url + path, headers=headers,
                            allow_redirects=kw.pop("allow_redirects", False),
                            timeout=kw.pop("timeout", 30), **kw)

    def post(self, path: str, branch: str = "main", **kw):
        import requests
        headers = dict(kw.pop("headers", {}))
        headers.setdefault("Host", self.host_for_branch(branch))
        return requests.post(self.base_url + path, headers=headers,
                             allow_redirects=kw.pop("allow_redirects", False),
                             timeout=kw.pop("timeout", 30), **kw)


# ---------------------------------------------------------------------------
# Banner
# ---------------------------------------------------------------------------


def pytest_report_header(config):
    try:
        b = _find_binary()
    except pytest.UsageError as e:
        return [f"forkpress binary: ERROR — {e}"]
    return [f"forkpress binary: {b if b else 'MISSING (suite will fail)'}"]
