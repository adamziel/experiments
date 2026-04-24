use zfs_wasm::{EntryKind, SnapshotFs};

fn names(fs: &SnapshotFs, path: &str) -> Vec<(String, EntryKind)> {
    fs.list_dir(path)
        .unwrap()
        .into_iter()
        .map(|entry| (entry.name, entry.kind))
        .collect()
}

#[test]
fn snapshot_preserves_prior_file_contents() {
    let mut fs = SnapshotFs::new();
    fs.create_dir("/docs").unwrap();
    fs.write_file("/docs/notes.txt", b"v1").unwrap();
    fs.snapshot("s1").unwrap();
    fs.write_file("/docs/notes.txt", b"v2").unwrap();

    assert_eq!(fs.read_file("/docs/notes.txt").unwrap(), b"v2");
    fs.rollback("s1").unwrap();
    assert_eq!(fs.read_file("/docs/notes.txt").unwrap(), b"v1");
}

#[test]
fn cloned_branch_diverges_without_mutating_main() {
    let mut fs = SnapshotFs::new();
    fs.create_dir("/cfg").unwrap();
    fs.write_file("/cfg/app.toml", b"main").unwrap();
    fs.snapshot("seed").unwrap();
    fs.clone_snapshot("seed", "feature").unwrap();

    fs.checkout_branch("feature").unwrap();
    fs.write_file("/cfg/app.toml", b"feature").unwrap();
    fs.write_file("/cfg/extra.toml", b"branch-only").unwrap();

    fs.checkout_branch("main").unwrap();
    assert_eq!(fs.read_file("/cfg/app.toml").unwrap(), b"main");
    assert!(!fs.exists("/cfg/extra.toml"));
}

#[test]
fn rollback_restores_deleted_subtrees() {
    let mut fs = SnapshotFs::new();
    fs.create_dir("/projects").unwrap();
    fs.create_dir("/projects/demo").unwrap();
    fs.write_file("/projects/demo/readme.md", b"hello").unwrap();
    fs.snapshot("before-delete").unwrap();

    fs.delete("/projects/demo").unwrap();
    assert!(!fs.exists("/projects/demo/readme.md"));

    fs.rollback("before-delete").unwrap();
    assert_eq!(fs.read_file("/projects/demo/readme.md").unwrap(), b"hello");
}

#[test]
fn directory_listings_are_sorted_and_typed() {
    let mut fs = SnapshotFs::new();
    fs.create_dir("/workspace").unwrap();
    fs.create_dir("/workspace/zeta").unwrap();
    fs.create_dir("/workspace/alpha").unwrap();
    fs.write_file("/workspace/middle.txt", b"x").unwrap();

    assert_eq!(
        names(&fs, "/workspace"),
        vec![
            ("alpha".to_string(), EntryKind::Directory),
            ("middle.txt".to_string(), EntryKind::File),
            ("zeta".to_string(), EntryKind::Directory),
        ]
    );
}

#[test]
fn duplicate_snapshot_names_are_rejected() {
    let mut fs = SnapshotFs::new();
    fs.snapshot("dup").unwrap();
    assert!(fs.snapshot("dup").is_err());
}

#[test]
fn missing_branch_checkout_is_rejected() {
    let mut fs = SnapshotFs::new();
    assert!(fs.checkout_branch("missing").is_err());
}

#[test]
fn invalid_paths_are_rejected() {
    let mut fs = SnapshotFs::new();
    assert!(fs.create_dir("relative").is_err());
    assert!(fs.create_dir("/a/../b").is_err());
    assert!(fs.write_file("/a/../b/file.txt", b"x").is_err());
}

#[test]
fn create_dir_creates_missing_parents() {
    let mut fs = SnapshotFs::new();
    fs.create_dir("/a/b/c").unwrap();

    assert!(fs.exists("/a"));
    assert!(fs.exists("/a/b"));
    assert!(fs.exists("/a/b/c"));
    assert_eq!(
        names(&fs, "/a/b"),
        vec![("c".to_string(), EntryKind::Directory)]
    );
}

#[test]
fn write_file_creates_missing_parents() {
    let mut fs = SnapshotFs::new();
    fs.write_file("/deep/tree/file.txt", b"x").unwrap();

    assert!(fs.exists("/deep"));
    assert!(fs.exists("/deep/tree"));
    assert_eq!(fs.read_file("/deep/tree/file.txt").unwrap(), b"x");
}

#[test]
fn stats_track_branches_and_snapshots() {
    let mut fs = SnapshotFs::new();
    fs.snapshot("base").unwrap();
    fs.clone_snapshot("base", "branch-a").unwrap();
    fs.clone_snapshot("base", "branch-b").unwrap();
    fs.checkout_branch("branch-a").unwrap();

    let stats = fs.stats();
    assert_eq!(stats.current_branch, "branch-a");
    assert_eq!(stats.branch_count, 3);
    assert_eq!(stats.snapshot_count, 1);
}

#[test]
fn branch_and_snapshot_metadata_preserve_hierarchy() {
    let mut fs = SnapshotFs::new();
    fs.write_file("/docs/readme.txt", b"main").unwrap();
    fs.snapshot("seed").unwrap();
    fs.clone_snapshot("seed", "feature").unwrap();
    fs.checkout_branch("feature").unwrap();
    fs.snapshot("feature-snap").unwrap();

    assert_eq!(
        fs.branch_info()
            .into_iter()
            .find(|branch| branch.name == "feature")
            .unwrap()
            .source_snapshot
            .as_deref(),
        Some("seed")
    );
    assert_eq!(
        fs.branch_info()
            .into_iter()
            .find(|branch| branch.name == "feature")
            .unwrap()
            .parent_branch
            .as_deref(),
        Some("main")
    );
    assert_eq!(
        fs.snapshot_info()
            .into_iter()
            .find(|snapshot| snapshot.name == "feature-snap")
            .unwrap()
            .source_branch,
        "feature"
    );
}

#[test]
fn snapshot_reads_are_detached_from_branch_mutation() {
    let mut fs = SnapshotFs::new();
    fs.write_file("/docs/readme.txt", b"main").unwrap();
    fs.snapshot("seed").unwrap();
    fs.clone_snapshot("seed", "feature").unwrap();
    fs.checkout_branch("feature").unwrap();
    fs.write_file("/docs/branch-only.txt", b"hello").unwrap();
    fs.write_file("/docs/readme.txt", b"feature").unwrap();

    assert_eq!(
        fs.read_file_in_snapshot("seed", "/docs/readme.txt").unwrap(),
        b"main"
    );
    assert!(!fs.exists_in_snapshot("seed", "/docs/branch-only.txt").unwrap());
    assert_eq!(fs.read_file("/docs/readme.txt").unwrap(), b"feature");
}
