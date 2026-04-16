use anyhow::{Context, Result, bail};
use flate2::Compression;
use flate2::write::GzEncoder;
use std::collections::BTreeMap;
use std::env;
use std::fs::File;
use std::path::{Path, PathBuf};
use std::process::Command;
use tar::Builder;
use walkdir::WalkDir;

fn main() -> Result<()> {
    let manifest_dir = PathBuf::from(env::var("CARGO_MANIFEST_DIR")?);
    let repo_root = manifest_dir
        .parent()
        .context("gitpress crate should live directly under the repo root")?;

    println!("cargo:rerun-if-env-changed=GITPRESS_PHP_BIN");
    println!("cargo:rerun-if-env-changed=GITPRESS_DOLT_BIN");
    println!("cargo:rerun-if-env-changed=GITPRESS_RUNTIME_DIR");

    let out_dir = PathBuf::from(env::var("OUT_DIR")?);
    let bundle_path = out_dir.join("gitpress-runtime.tar.gz");

    // Two modes:
    //   1. GITPRESS_RUNTIME_DIR is set → CI/cross-compile mode. A pre-staged
    //      directory contains bin/php, bin/dolt, lib/branchfs.so already
    //      built for the target platform. We just tar it up alongside the
    //      PHP/WP/vendor sources. Host tooling (make, ldd, readelf) is
    //      never invoked — critical when building for a foreign arch.
    //   2. Unset → local-dev fallback. Build ext/branchfs.so via `make`,
    //      resolve host php/dolt via PATH, and capture their shared-lib
    //      closure with ldd + readelf (Linux glibc only).
    if let Ok(runtime_dir) = env::var("GITPRESS_RUNTIME_DIR") {
        let runtime_dir = PathBuf::from(runtime_dir);
        build_runtime_bundle_from_dir(repo_root, &runtime_dir, &bundle_path)?;
    } else {
        ensure_branchfs_binary(repo_root)?;
        build_runtime_bundle(repo_root, &bundle_path)?;
    }

    println!(
        "cargo:rustc-env=GITPRESS_RUNTIME_BUNDLE={}",
        bundle_path.display()
    );

    Ok(())
}

fn ensure_branchfs_binary(repo_root: &Path) -> Result<()> {
    let ext_so = repo_root.join("ext/branchfs.so");
    println!(
        "cargo:rerun-if-changed={}",
        repo_root.join("ext/branchfs.c").display()
    );
    println!(
        "cargo:rerun-if-changed={}",
        repo_root.join("ext/branchfs.h").display()
    );
    println!("cargo:rerun-if-changed={}", ext_so.display());

    if ext_so.exists() {
        return Ok(());
    }

    let status = Command::new("make")
        .arg("ext/branchfs.so")
        .current_dir(repo_root)
        .status()
        .context("failed to invoke make for ext/branchfs.so")?;

    if !status.success() {
        bail!("make ext/branchfs.so failed with status {status}");
    }

    if !ext_so.exists() {
        bail!("ext/branchfs.so was not produced by make");
    }

    Ok(())
}

fn build_runtime_bundle(repo_root: &Path, bundle_path: &Path) -> Result<()> {
    let file = File::create(bundle_path).with_context(|| {
        format!(
            "failed to create runtime bundle at {}",
            bundle_path.display()
        )
    })?;
    let encoder = GzEncoder::new(file, Compression::default());
    let mut tar = Builder::new(encoder);

    add_tree(&mut tar, repo_root, "scripts")?;
    add_tree(&mut tar, repo_root, "sql")?;
    add_tree(&mut tar, repo_root, "vendor")?;
    add_tree(&mut tar, repo_root, "wp-plugin")?;
    add_file(&mut tar, repo_root, "ext/branchfs.so")?;
    add_file(&mut tar, repo_root, "e2e/router.php")?;
    add_file(&mut tar, repo_root, "e2e/bootstrap_wp.php")?;
    add_file(&mut tar, repo_root, "e2e/wp.zip")?;
    add_portable_runtime(&mut tar, repo_root)?;

    tar.finish()?;
    let encoder = tar.into_inner()?;
    encoder.finish()?;
    Ok(())
}

/// Pre-staged runtime mode: the caller has already produced
///   $GITPRESS_RUNTIME_DIR/bin/php
///   $GITPRESS_RUNTIME_DIR/bin/dolt
///   $GITPRESS_RUNTIME_DIR/lib/branchfs.so
/// for the target platform. We just tar the PHP/WP sources + those three
/// artifacts, without invoking make/ldd/readelf on the host.
fn build_runtime_bundle_from_dir(
    repo_root: &Path,
    runtime_dir: &Path,
    bundle_path: &Path,
) -> Result<()> {
    let php_bin = runtime_dir.join("bin/php");
    let dolt_bin = runtime_dir.join("bin/dolt");
    let branchfs_so = runtime_dir.join("lib/branchfs.so");

    for (label, path) in [
        ("bin/php", &php_bin),
        ("bin/dolt", &dolt_bin),
        ("lib/branchfs.so", &branchfs_so),
    ] {
        if !path.exists() {
            bail!(
                "GITPRESS_RUNTIME_DIR={} is missing {}",
                runtime_dir.display(),
                label
            );
        }
    }

    println!("cargo:rerun-if-changed={}", php_bin.display());
    println!("cargo:rerun-if-changed={}", dolt_bin.display());
    println!("cargo:rerun-if-changed={}", branchfs_so.display());

    let file = File::create(bundle_path).with_context(|| {
        format!(
            "failed to create runtime bundle at {}",
            bundle_path.display()
        )
    })?;
    let encoder = GzEncoder::new(file, Compression::default());
    let mut tar = Builder::new(encoder);

    add_tree(&mut tar, repo_root, "scripts")?;
    add_tree(&mut tar, repo_root, "sql")?;
    add_tree(&mut tar, repo_root, "vendor")?;
    add_tree(&mut tar, repo_root, "wp-plugin")?;
    add_file_as(&mut tar, &branchfs_so, "ext/branchfs.so")?;
    add_file(&mut tar, repo_root, "e2e/router.php")?;
    add_file(&mut tar, repo_root, "e2e/bootstrap_wp.php")?;
    add_file(&mut tar, repo_root, "e2e/wp.zip")?;

    // With a statically-linked PHP and a statically-linked dolt we have no
    // shared-lib closure to capture — just drop the two binaries at the
    // same portable-runtime/ paths the runtime extractor expects.
    add_file_as(&mut tar, &php_bin, "portable-runtime/bin/php")?;
    add_file_as(&mut tar, &dolt_bin, "portable-runtime/bin/dolt")?;

    // If the caller staged any extra shared libraries under
    // $GITPRESS_RUNTIME_DIR/lib/ (not branchfs.so itself), include them.
    // Static PHP builds won't ship any; dynamic builds will.
    let lib_dir = runtime_dir.join("lib");
    if lib_dir.is_dir() {
        for entry in WalkDir::new(&lib_dir).min_depth(1).max_depth(1) {
            let entry = entry?;
            if !entry.file_type().is_file() {
                continue;
            }
            let name = entry.file_name().to_string_lossy().to_string();
            if name == "branchfs.so" {
                continue;
            }
            add_file_as(
                &mut tar,
                entry.path(),
                &format!("portable-runtime/lib/{name}"),
            )?;
        }
    }

    tar.finish()?;
    let encoder = tar.into_inner()?;
    encoder.finish()?;
    Ok(())
}

fn add_portable_runtime(tar: &mut Builder<GzEncoder<File>>, repo_root: &Path) -> Result<()> {
    let php_bin = resolve_tool("GITPRESS_PHP_BIN", "php")?;
    let dolt_bin = resolve_tool("GITPRESS_DOLT_BIN", "dolt")?;
    let branchfs_so = repo_root.join("ext/branchfs.so");

    add_file_as(tar, &php_bin, "portable-runtime/bin/php")?;
    add_file_as(tar, &dolt_bin, "portable-runtime/bin/dolt")?;

    let mut libs = BTreeMap::<String, PathBuf>::new();
    let php_loader = elf_interpreter(&php_bin)?;
    libs.insert(file_name_string(&php_loader)?, php_loader);

    for lib in shared_libraries(&php_bin)?
        .into_iter()
        .chain(shared_libraries(&branchfs_so)?)
    {
        libs.entry(file_name_string(&lib)?).or_insert(lib);
    }

    for (name, path) in libs {
        add_file_as(tar, &path, &format!("portable-runtime/lib/{name}"))?;
    }

    Ok(())
}

fn add_tree(tar: &mut Builder<GzEncoder<File>>, repo_root: &Path, rel: &str) -> Result<()> {
    let root = repo_root.join(rel);
    println!("cargo:rerun-if-changed={}", root.display());

    for entry in WalkDir::new(&root) {
        let entry = entry?;
        let path = entry.path();
        let rel_path = path
            .strip_prefix(repo_root)
            .with_context(|| format!("{} is not under {}", path.display(), repo_root.display()))?;

        if entry.file_type().is_dir() {
            tar.append_dir(rel_path, path)?;
        } else if entry.file_type().is_file() {
            tar.append_path_with_name(path, rel_path)?;
        }
    }

    Ok(())
}

fn add_file(tar: &mut Builder<GzEncoder<File>>, repo_root: &Path, rel: &str) -> Result<()> {
    let path = repo_root.join(rel);
    println!("cargo:rerun-if-changed={}", path.display());
    tar.append_path_with_name(&path, rel)?;
    Ok(())
}

fn add_file_as(tar: &mut Builder<GzEncoder<File>>, source: &Path, dest: &str) -> Result<()> {
    println!("cargo:rerun-if-changed={}", source.display());
    tar.append_path_with_name(source, dest)?;
    Ok(())
}

fn resolve_tool(env_var: &str, default_name: &str) -> Result<PathBuf> {
    if let Ok(path) = env::var(env_var) {
        return Ok(PathBuf::from(path));
    }

    let output = Command::new("sh")
        .args(["-c", &format!("command -v {default_name}")])
        .output()
        .with_context(|| format!("failed to resolve `{default_name}` on PATH"))?;

    if !output.status.success() {
        bail!("could not find `{default_name}` on PATH");
    }

    let path = String::from_utf8(output.stdout)
        .context("tool path was not valid UTF-8")?
        .trim()
        .to_owned();

    if path.is_empty() {
        bail!("resolved `{default_name}` path was empty");
    }

    Ok(PathBuf::from(path))
}

fn shared_libraries(binary: &Path) -> Result<Vec<PathBuf>> {
    let output = Command::new("ldd").arg(binary).output().with_context(|| {
        format!(
            "failed to inspect shared libraries for {}",
            binary.display()
        )
    })?;

    if !output.status.success() {
        bail!("ldd failed for {}", binary.display());
    }

    let stdout = String::from_utf8(output.stdout).context("ldd output was not valid UTF-8")?;
    let mut libs = Vec::new();

    for line in stdout.lines() {
        for token in line.split_whitespace() {
            if token.starts_with('/') {
                libs.push(PathBuf::from(token));
            }
        }
    }

    Ok(libs)
}

fn elf_interpreter(binary: &Path) -> Result<PathBuf> {
    let output = Command::new("readelf")
        .args(["-l"])
        .arg(binary)
        .output()
        .with_context(|| format!("failed to inspect ELF interpreter for {}", binary.display()))?;

    if !output.status.success() {
        bail!("readelf failed for {}", binary.display());
    }

    let stdout = String::from_utf8(output.stdout).context("readelf output was not valid UTF-8")?;
    for line in stdout.lines() {
        if let Some(start) = line.find('[') {
            if let Some(end) = line[start + 1..].find(']') {
                let raw = &line[start + 1..start + 1 + end];
                let path = raw
                    .split(':')
                    .next_back()
                    .map(str::trim)
                    .filter(|value| value.starts_with('/'))
                    .unwrap_or(raw);
                return Ok(PathBuf::from(path));
            }
        }
    }

    bail!(
        "could not determine ELF interpreter for {}",
        binary.display()
    )
}

fn file_name_string(path: &Path) -> Result<String> {
    path.file_name()
        .and_then(|name| name.to_str())
        .map(ToOwned::to_owned)
        .with_context(|| format!("{} did not have a valid UTF-8 file name", path.display()))
}
