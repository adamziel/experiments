use anyhow::{Context, Result, bail};
use flate2::Compression;
use flate2::write::GzEncoder;
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

    ensure_branchfs_binary(repo_root)?;

    let out_dir = PathBuf::from(env::var("OUT_DIR")?);
    let bundle_path = out_dir.join("gitpress-runtime.tar.gz");
    build_runtime_bundle(repo_root, &bundle_path)?;

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

    tar.finish()?;
    let encoder = tar.into_inner()?;
    encoder.finish()?;
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
