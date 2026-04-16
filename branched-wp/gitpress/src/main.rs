use anyhow::{Context, Result, anyhow, bail};
use clap::{ArgAction, Args, Parser, Subcommand};
use flate2::read::GzDecoder;
use std::ffi::OsStr;
use std::fs::{self, File, OpenOptions};
use std::io::{Cursor, Write};
use std::net::{TcpStream, ToSocketAddrs};
use std::path::{Path, PathBuf};
use std::process::{Child, Command, ExitStatus, Stdio};
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::thread;
use std::time::{Duration, Instant};
use zip::ZipArchive;

const RUNTIME_BUNDLE: &[u8] = include_bytes!(env!("GITPRESS_RUNTIME_BUNDLE"));
const STARTUP_WARNING_FILTER: &str = "Missing arginfo";

#[derive(Parser, Debug)]
#[command(
    name = "gitpress",
    version,
    about = "Single-binary wrapper for BranchFS WordPress + git"
)]
struct Cli {
    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand, Debug)]
enum Commands {
    Start(StartArgs),
    Branch(BranchPassthrough),
    #[command(alias = "branchctl")]
    Branchctl(BranchPassthrough),
}

#[derive(Args, Debug, Clone)]
struct SharedPaths {
    #[arg(long, default_value = ".gitpress")]
    work_dir: PathBuf,

    #[arg(long, default_value = "php")]
    php_bin: String,

    #[arg(long, default_value = "dolt")]
    dolt_bin: String,

    #[arg(long, default_value_t = 13306)]
    dolt_port: u16,
}

#[derive(Args, Debug, Clone)]
struct StartArgs {
    #[command(flatten)]
    shared: SharedPaths,

    #[arg(long, default_value = "127.0.0.1")]
    host: String,

    #[arg(long, default_value_t = 18080)]
    port: u16,

    #[arg(long, default_value = "localhost")]
    root_host: String,

    #[arg(long, default_value = "GitPress")]
    site_title: String,
}

#[derive(Args, Debug, Clone)]
struct BranchPassthrough {
    #[command(flatten)]
    shared: SharedPaths,

    #[arg(trailing_var_arg = true, allow_hyphen_values = true, action = ArgAction::Append)]
    args: Vec<String>,
}

#[derive(Debug, Clone)]
struct Layout {
    work_dir: PathBuf,
    runtime_dir: PathBuf,
    logs_dir: PathBuf,
    db_path: PathBuf,
    wp_root: PathBuf,
    dolt_data_dir: PathBuf,
    dolt_repo_dir: PathBuf,
    debug_log: PathBuf,
    php_error_log: PathBuf,
    php_server_log: PathBuf,
    dolt_server_log: PathBuf,
    runtime_ready_marker: PathBuf,
    bootstrap_marker: PathBuf,
}

struct ChildGuard {
    name: &'static str,
    child: Child,
}

impl ChildGuard {
    fn try_wait(&mut self) -> Result<Option<ExitStatus>> {
        self.child
            .try_wait()
            .with_context(|| format!("failed to poll {}", self.name))
    }
}

impl Drop for ChildGuard {
    fn drop(&mut self) {
        if self.child.try_wait().ok().flatten().is_none() {
            let _ = self.child.kill();
            let _ = self.child.wait();
        }
    }
}

fn main() {
    let code = match run() {
        Ok(code) => code,
        Err(err) => {
            eprintln!("gitpress: {err:#}");
            1
        }
    };
    std::process::exit(code);
}

fn run() -> Result<i32> {
    let cli = Cli::parse();
    match cli.command {
        Commands::Start(args) => start_command(args),
        Commands::Branch(args) | Commands::Branchctl(args) => branch_command(args),
    }
}

fn start_command(args: StartArgs) -> Result<i32> {
    let layout = Layout::new(args.shared.work_dir.clone())?;
    prepare_runtime(&layout)?;
    ensure_ports_available(&args)?;

    let mut dolt = start_dolt_server(
        &layout,
        &args.shared.php_bin,
        &args.shared.dolt_bin,
        args.shared.dolt_port,
        false,
    )?;

    ensure_bootstrapped(&layout, &args)?;

    let mut php = start_php_server(&layout, &args)?;

    println!("Main site:  http://{}:{}/", args.root_host, args.port);
    println!(
        "Branch site: http://<branch>.{}:{}/",
        args.root_host, args.port
    );
    println!(
        "Git remote: http://{}:{}/site.git",
        args.root_host, args.port
    );
    println!("Logs:       {}", layout.logs_dir.display());
    println!("Press Ctrl+C to stop.");

    let stop = Arc::new(AtomicBool::new(false));
    let handler_flag = Arc::clone(&stop);
    ctrlc::set_handler(move || {
        handler_flag.store(true, Ordering::SeqCst);
    })
    .context("failed to install Ctrl+C handler")?;

    loop {
        if stop.load(Ordering::SeqCst) {
            println!("Stopping servers...");
            break;
        }

        if let Some(status) = php.try_wait()? {
            bail!(
                "php server exited unexpectedly with status {status}. Check {}",
                layout.php_server_log.display()
            );
        }

        if let Some(status) = dolt.try_wait()? {
            bail!(
                "dolt sql-server exited unexpectedly with status {status}. Check {}",
                layout.dolt_server_log.display()
            );
        }

        thread::sleep(Duration::from_millis(250));
    }

    Ok(0)
}

fn branch_command(args: BranchPassthrough) -> Result<i32> {
    if args.args.is_empty() {
        bail!("branch requires branchctl arguments, e.g. `gitpress branch create marketing`");
    }

    let layout = Layout::new(args.shared.work_dir.clone())?;
    prepare_runtime(&layout)?;

    if !layout.db_path.exists() || !layout.bootstrap_marker.exists() {
        bail!(
            "no bootstrapped site found in {}. Run `gitpress start` first",
            layout.work_dir.display()
        );
    }

    let _dolt = start_dolt_server(
        &layout,
        &args.shared.php_bin,
        &args.shared.dolt_bin,
        args.shared.dolt_port,
        true,
    )?;

    let mut command = php_base_command(&layout, &args.shared.php_bin);
    command.arg(layout.runtime_dir.join("scripts/branchctl.php"));
    for arg in &args.args {
        command.arg(arg);
    }
    command.env("BRANCHFS_DB", &layout.db_path);
    command.env("DOLT_HOST", "127.0.0.1");
    command.env("DOLT_PORT", args.shared.dolt_port.to_string());
    command.env("DOLT_DB", "wordpress");
    command.env("BRANCHFS_ROOT_HOST", "localhost");
    command.env("PORT", "80");

    let output = command
        .output()
        .with_context(|| format!("failed to run {}", args.shared.php_bin))?;

    write_filtered_output(&output.stdout, &output.stderr)?;

    Ok(output.status.code().unwrap_or(1))
}

impl Layout {
    fn new(work_dir: PathBuf) -> Result<Self> {
        let work_dir = absolutize(work_dir)?;
        Ok(Self {
            runtime_dir: work_dir.join("runtime"),
            logs_dir: work_dir.join("logs"),
            db_path: work_dir.join("branchfs.db"),
            wp_root: work_dir.join("wproot"),
            dolt_data_dir: work_dir.join("dolt-data"),
            dolt_repo_dir: work_dir.join("dolt-data/wordpress"),
            debug_log: work_dir.join("logs/wp-debug.log"),
            php_error_log: work_dir.join("logs/php-errors.log"),
            php_server_log: work_dir.join("logs/php-server.log"),
            dolt_server_log: work_dir.join("logs/dolt-server.log"),
            runtime_ready_marker: work_dir.join("runtime/.gitpress-runtime-ready"),
            bootstrap_marker: work_dir.join(".gitpress-bootstrap-complete"),
            work_dir,
        })
    }
}

fn absolutize(path: PathBuf) -> Result<PathBuf> {
    if path.is_absolute() {
        return Ok(path);
    }
    Ok(std::env::current_dir()
        .context("failed to read current working directory")?
        .join(path))
}

fn prepare_runtime(layout: &Layout) -> Result<()> {
    fs::create_dir_all(&layout.work_dir)?;
    fs::create_dir_all(&layout.logs_dir)?;
    fs::create_dir_all(&layout.wp_root)?;
    fs::create_dir_all(&layout.dolt_repo_dir)?;

    if !layout.runtime_ready_marker.exists() {
        if layout.runtime_dir.exists() {
            fs::remove_dir_all(&layout.runtime_dir)
                .with_context(|| format!("failed to clear {}", layout.runtime_dir.display()))?;
        }
        fs::create_dir_all(&layout.runtime_dir)?;

        let decoder = GzDecoder::new(Cursor::new(RUNTIME_BUNDLE));
        let mut archive = tar::Archive::new(decoder);
        archive.unpack(&layout.runtime_dir).with_context(|| {
            format!(
                "failed to unpack runtime into {}",
                layout.runtime_dir.display()
            )
        })?;

        ensure_wp_source_unzipped(layout)?;
        File::create(&layout.runtime_ready_marker)?;
    }

    Ok(())
}

fn ensure_wp_source_unzipped(layout: &Layout) -> Result<()> {
    let wp_src_dir = layout.runtime_dir.join("e2e/wp-src");
    if wp_src_dir.join("wp-load.php").exists() {
        return Ok(());
    }

    fs::create_dir_all(&wp_src_dir)?;
    let zip_file = File::open(layout.runtime_dir.join("e2e/wp.zip"))
        .context("failed to open embedded WordPress archive")?;
    let mut zip = ZipArchive::new(zip_file).context("failed to read embedded WordPress zip")?;

    for i in 0..zip.len() {
        let mut entry = zip.by_index(i)?;
        let enclosed = entry
            .enclosed_name()
            .ok_or_else(|| anyhow!("zip entry had invalid path"))?;
        let stripped = enclosed
            .strip_prefix("wordpress")
            .unwrap_or(enclosed.as_path());

        if stripped.as_os_str().is_empty() {
            continue;
        }

        let out_path = wp_src_dir.join(stripped);

        if entry.is_dir() {
            fs::create_dir_all(&out_path)?;
            continue;
        }

        if let Some(parent) = out_path.parent() {
            fs::create_dir_all(parent)?;
        }

        let mut out = File::create(&out_path)?;
        std::io::copy(&mut entry, &mut out)?;
    }

    Ok(())
}

fn ensure_ports_available(args: &StartArgs) -> Result<()> {
    if tcp_port_open("127.0.0.1", args.shared.dolt_port) {
        bail!("dolt port {} is already in use", args.shared.dolt_port);
    }
    if tcp_port_open(&args.host, args.port) {
        bail!(
            "http server port {} is already in use on {}",
            args.port,
            args.host
        );
    }
    Ok(())
}

fn ensure_bootstrapped(layout: &Layout, args: &StartArgs) -> Result<()> {
    if !layout.db_path.exists() {
        run_php_script(
            layout,
            &args.shared.php_bin,
            "scripts/init_db.php",
            [layout.db_path.as_os_str()],
        )?;
    }

    if !layout.bootstrap_marker.exists() {
        run_php_script(
            layout,
            &args.shared.php_bin,
            "scripts/import_wp.php",
            [
                layout.runtime_dir.join("e2e/wp-src").as_os_str(),
                layout.db_path.as_os_str(),
                OsStr::new("main"),
            ],
        )?;

        run_php_script(
            layout,
            &args.shared.php_bin,
            "e2e/bootstrap_wp.php",
            [
                layout.db_path.as_os_str(),
                layout.wp_root.as_os_str(),
                OsStr::new(&args.shared.dolt_port.to_string()),
                OsStr::new(&args.site_title),
                layout
                    .runtime_dir
                    .join("wp-plugin/branchfs-wp.php")
                    .as_os_str(),
                layout.debug_log.as_os_str(),
            ],
        )?;

        File::create(&layout.bootstrap_marker)?;
    }

    Ok(())
}

fn start_dolt_server(
    layout: &Layout,
    php_bin: &str,
    dolt_bin: &str,
    port: u16,
    allow_existing: bool,
) -> Result<ChildGuard> {
    if tcp_port_open("127.0.0.1", port) {
        if allow_existing {
            let child = Command::new("true")
                .spawn()
                .context("failed to spawn noop guard")?;
            return Ok(ChildGuard {
                name: "dolt sql-server",
                child,
            });
        }
        bail!("dolt port {port} is already in use");
    }

    ensure_dolt_repo(layout, dolt_bin)?;

    let log = OpenOptions::new()
        .create(true)
        .append(true)
        .open(&layout.dolt_server_log)?;
    let log_err = log.try_clone()?;

    let child = Command::new(dolt_bin)
        .args([
            "sql-server",
            "--host=127.0.0.1",
            &format!("--port={port}"),
            "--data-dir",
        ])
        .arg(&layout.dolt_data_dir)
        .stdout(Stdio::from(log))
        .stderr(Stdio::from(log_err))
        .spawn()
        .with_context(|| format!("failed to start dolt using `{dolt_bin}`"))?;

    let guard = ChildGuard {
        name: "dolt sql-server",
        child,
    };

    wait_for_dolt(layout, php_bin, guard.child.id(), port)?;
    Ok(guard)
}

fn ensure_dolt_repo(layout: &Layout, dolt_bin: &str) -> Result<()> {
    if layout.dolt_repo_dir.join(".dolt").exists() {
        return Ok(());
    }

    fs::create_dir_all(&layout.dolt_repo_dir)?;
    let output = Command::new(dolt_bin)
        .args(["init", "--name", "gitpress", "--email", "gitpress@local"])
        .current_dir(&layout.dolt_repo_dir)
        .output()
        .with_context(|| format!("failed to initialize dolt repo using `{dolt_bin}`"))?;

    if !output.status.success() {
        bail!(
            "dolt init failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        );
    }

    Ok(())
}

fn wait_for_dolt(layout: &Layout, php_bin: &str, pid: u32, port: u16) -> Result<()> {
    let deadline = Instant::now() + Duration::from_secs(30);
    while Instant::now() < deadline {
        if !process_alive(pid) {
            bail!(
                "dolt sql-server exited before becoming ready. Check {}",
                layout.dolt_server_log.display()
            );
        }

        let status = Command::new(php_bin)
            .args([
                "-r",
                &format!(
                    "mysqli_report(MYSQLI_REPORT_OFF); $c = @mysqli_init(); if(!$c) exit(1); if(!@mysqli_real_connect($c, '127.0.0.1', 'root', '', 'wordpress', {})) exit(1); $c->close();",
                    port
                ),
            ])
            .status();

        if matches!(status, Ok(s) if s.success()) {
            return Ok(());
        }
        thread::sleep(Duration::from_millis(500));
    }

    bail!(
        "timed out waiting for dolt sql-server on port {port}. Check {}",
        layout.dolt_server_log.display()
    );
}

fn start_php_server(layout: &Layout, args: &StartArgs) -> Result<ChildGuard> {
    let log = OpenOptions::new()
        .create(true)
        .append(true)
        .open(&layout.php_server_log)?;
    let log_err = log.try_clone()?;

    let child = php_base_command(layout, &args.shared.php_bin)
        .arg("-d")
        .arg("log_errors=On")
        .arg("-d")
        .arg(format!("error_log={}", layout.php_error_log.display()))
        .arg("-d")
        .arg("post_max_size=100M")
        .arg("-d")
        .arg("upload_max_filesize=100M")
        .arg("-S")
        .arg(format!("{}:{}", args.host, args.port))
        .arg("-t")
        .arg(&layout.wp_root)
        .arg(layout.runtime_dir.join("e2e/router.php"))
        .env("BRANCHFS_DB", &layout.db_path)
        .env("BRANCHFS_WP_ROOT", &layout.wp_root)
        .env("BRANCHFS_ROOT_HOST", &args.root_host)
        .env("DOLT_HOST", "127.0.0.1")
        .env("DOLT_PORT", args.shared.dolt_port.to_string())
        .env("DOLT_DB", "wordpress")
        .stdout(Stdio::from(log))
        .stderr(Stdio::from(log_err))
        .spawn()
        .with_context(|| format!("failed to start php using `{}`", args.shared.php_bin))?;

    let mut guard = ChildGuard {
        name: "php server",
        child,
    };

    wait_for_tcp(&args.host, args.port, Duration::from_secs(30))
        .with_context(|| format!("php server did not open {}:{}", args.host, args.port))?;

    if let Some(status) = guard.try_wait()? {
        bail!(
            "php server exited early with status {status}. Check {}",
            layout.php_server_log.display()
        );
    }

    Ok(guard)
}

fn php_base_command(layout: &Layout, php_bin: &str) -> Command {
    let mut command = Command::new(php_bin);
    command
        .arg("-d")
        .arg(format!(
            "extension={}",
            layout.runtime_dir.join("ext/branchfs.so").display()
        ))
        .arg("-d")
        .arg("display_errors=Off")
        .arg("-d")
        .arg("display_startup_errors=Off");
    command
}

fn run_php_script<I, S>(layout: &Layout, php_bin: &str, script_rel: &str, args: I) -> Result<()>
where
    I: IntoIterator<Item = S>,
    S: AsRef<OsStr>,
{
    let mut command = php_base_command(layout, php_bin);
    command.arg(layout.runtime_dir.join(script_rel));
    for arg in args {
        command.arg(arg);
    }

    let output = command
        .output()
        .with_context(|| format!("failed to run {} {}", php_bin, script_rel))?;

    write_filtered_output(&output.stdout, &output.stderr)?;

    if !output.status.success() {
        bail!("{script_rel} exited with status {}", output.status);
    }

    Ok(())
}

fn write_filtered_output(stdout: &[u8], stderr: &[u8]) -> Result<()> {
    let mut out = std::io::stdout().lock();
    out.write_all(stdout)?;

    let stderr_text = String::from_utf8_lossy(stderr);
    for line in stderr_text.lines() {
        if !line.contains(STARTUP_WARNING_FILTER) {
            writeln!(std::io::stderr().lock(), "{line}")?;
        }
    }
    Ok(())
}

fn wait_for_tcp(host: &str, port: u16, timeout: Duration) -> Result<()> {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if tcp_port_open(host, port) {
            return Ok(());
        }
        thread::sleep(Duration::from_millis(250));
    }
    bail!("timed out waiting for {host}:{port}");
}

fn tcp_port_open(host: &str, port: u16) -> bool {
    let addrs = (host, port).to_socket_addrs();
    let Ok(addrs) = addrs else {
        return false;
    };

    addrs
        .into_iter()
        .any(|addr| TcpStream::connect_timeout(&addr, Duration::from_millis(250)).is_ok())
}

fn process_alive(pid: u32) -> bool {
    Path::new("/proc").join(pid.to_string()).exists()
}
