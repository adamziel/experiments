use seq_macro::seq;
use zfs_wasm::{EntryKind, SnapshotFs};

fn payload(seed: usize, len: usize) -> Vec<u8> {
    (0..len)
        .map(|index| (((seed * 37) + (index * 17) + (seed % 11)) % 251) as u8)
        .collect()
}

fn entry_names(fs: &SnapshotFs, path: &str) -> Vec<(String, EntryKind)> {
    fs.list_dir(path)
        .unwrap()
        .into_iter()
        .map(|entry| (entry.name, entry.kind))
        .collect()
}

#[test]
fn cannot_overwrite_directory_with_file() {
    let mut fs = SnapshotFs::new();
    fs.create_dir("/etc").unwrap();
    assert!(fs.write_file("/etc", b"nope").is_err());
}

#[test]
fn cannot_create_directory_over_file() {
    let mut fs = SnapshotFs::new();
    fs.write_file("/root.txt", b"file").unwrap();
    assert!(fs.create_dir("/root.txt").is_err());
}

#[test]
fn cannot_delete_root() {
    let mut fs = SnapshotFs::new();
    assert!(fs.delete("/").is_err());
}

#[test]
fn duplicate_branch_names_are_rejected() {
    let mut fs = SnapshotFs::new();
    fs.snapshot("seed").unwrap();
    fs.clone_snapshot("seed", "feature").unwrap();
    assert!(fs.clone_snapshot("seed", "feature").is_err());
}

#[test]
fn write_file_creates_all_missing_parent_directories() {
    let mut fs = SnapshotFs::new();
    fs.write_text_case_helper("/alpha/beta/gamma.txt", "ok");
    assert!(fs.exists("/alpha"));
    assert!(fs.exists("/alpha/beta"));
    assert_eq!(fs.read_file("/alpha/beta/gamma.txt").unwrap(), b"ok");
}

#[test]
fn create_dir_creates_all_missing_parent_directories() {
    let mut fs = SnapshotFs::new();
    fs.create_dir("/root/branch/leaf").unwrap();
    assert!(fs.exists("/root"));
    assert!(fs.exists("/root/branch"));
    assert!(fs.exists("/root/branch/leaf"));
}

#[test]
fn auto_parent_creation_still_rejects_existing_file_as_parent() {
    let mut fs = SnapshotFs::new();
    fs.write_file("/root.txt", b"file").unwrap();
    assert!(fs.write_file("/root.txt/child.txt", b"x").is_err());
    assert!(fs.create_dir("/root.txt/child").is_err());
}

#[test]
fn snapshot_can_be_taken_on_feature_branch_without_mutating_main() {
    let mut fs = SnapshotFs::new();
    fs.create_dir("/cfg").unwrap();
    fs.write_file("/cfg/app.ini", b"main").unwrap();
    fs.snapshot("base").unwrap();
    fs.clone_snapshot("base", "feature").unwrap();
    fs.checkout_branch("feature").unwrap();
    fs.write_file("/cfg/app.ini", b"feature").unwrap();
    fs.snapshot("feature-snap").unwrap();
    fs.write_file("/cfg/app.ini", b"feature-2").unwrap();
    fs.rollback("feature-snap").unwrap();
    assert_eq!(fs.read_file("/cfg/app.ini").unwrap(), b"feature");
    fs.checkout_branch("main").unwrap();
    assert_eq!(fs.read_file("/cfg/app.ini").unwrap(), b"main");
}

#[test]
fn listing_remains_sorted_after_overwrites_and_deletes() {
    let mut fs = SnapshotFs::new();
    fs.create_dir("/dir").unwrap();
    fs.write_file("/dir/c.txt", b"c").unwrap();
    fs.write_file("/dir/a.txt", b"a").unwrap();
    fs.write_file("/dir/b.txt", b"b").unwrap();
    fs.write_file("/dir/b.txt", b"b2").unwrap();
    fs.delete("/dir/c.txt").unwrap();
    assert_eq!(
        entry_names(&fs, "/dir"),
        vec![
            ("a.txt".to_string(), EntryKind::File),
            ("b.txt".to_string(), EntryKind::File),
        ]
    );
}

trait TestWriteText {
    fn write_text_case_helper(&mut self, path: &str, text: &str);
}

impl TestWriteText for SnapshotFs {
    fn write_text_case_helper(&mut self, path: &str, text: &str) {
        self.write_file(path, text.as_bytes()).unwrap();
    }
}

seq!(N in 0..96 {
    #[test]
    fn invalid_path_case_~N() {
        let mut fs = SnapshotFs::new();
        let invalid = match N % 12 {
            0 => format!("relative-{}", N),
            1 => format!("/double//slash/{}", N),
            2 => format!("/trailing/{}/", N),
            3 => format!("/dot/./{}", N),
            4 => format!("/dotdot/../{}", N),
            5 => format!("/mix//{}/tail/", N),
            6 => format!("/../rootish/{}", N),
            7 => format!("/./rootish/{}", N),
            8 => format!(""),
            9 => format!("/a//b//c/{}", N),
            10 => format!("/a/{}/./b", N),
            _ => format!("/a/{}/../b", N),
        };

        assert!(fs.create_dir(&invalid).is_err(), "create_dir accepted {invalid:?}");
        assert!(fs.write_file(&invalid, payload(N, (N % 5) + 1)).is_err(), "write_file accepted {invalid:?}");
        assert!(fs.read_file(&invalid).is_err(), "read_file accepted {invalid:?}");
        assert!(fs.delete(&invalid).is_err(), "delete accepted {invalid:?}");
        assert!(fs.list_dir(&invalid).is_err(), "list_dir accepted {invalid:?}");
        assert!(!fs.exists(&invalid), "exists returned true for {invalid:?}");
    }
});

seq!(N in 0..128 {
    #[test]
    fn roundtrip_write_read_case_~N() {
        let mut fs = SnapshotFs::new();
        let bucket = format!("/bucket{}", N % 8);
        let nested = format!("{bucket}/nested{}", N % 5);
        fs.create_dir(&bucket).unwrap();
        fs.create_dir(&nested).unwrap();

        let leaf_dir = if N % 2 == 0 {
            nested.clone()
        } else {
            let deep = format!("{nested}/deep{}", N % 7);
            fs.create_dir(&deep).unwrap();
            deep
        };

        let file_path = format!("{}/file-{}.bin", leaf_dir, N);
        let original = payload(N, (N * 7) % 64);
        fs.write_file(&file_path, &original).unwrap();
        assert_eq!(fs.read_file(&file_path).unwrap(), original);
        assert!(fs.exists(&file_path));

        if N % 3 == 0 {
            let replacement = payload(N + 1_000, ((N * 11) % 96) + 1);
            fs.write_file(&file_path, &replacement).unwrap();
            assert_eq!(fs.read_file(&file_path).unwrap(), replacement);
        }

        let listing = entry_names(&fs, &leaf_dir);
        assert!(listing.iter().any(|(name, kind)| name == &format!("file-{}.bin", N) && *kind == EntryKind::File));
    }
});

seq!(N in 0..128 {
    #[test]
    fn snapshot_rollback_case_~N() {
        let mut fs = SnapshotFs::new();
        let root = format!("/pool{}", N % 9);
        let branch = format!("{root}/branch{}", N % 7);
        fs.create_dir(&root).unwrap();
        fs.create_dir(&branch).unwrap();

        let target = format!("{}/target-{}.dat", branch, N);
        let sibling = format!("{}/sibling-{}.dat", branch, N);
        let original = payload(N + 5, (N % 40) + 1);
        fs.write_file(&target, &original).unwrap();
        fs.write_file(&sibling, payload(N + 6, (N % 13) + 2)).unwrap();

        let snapshot_name = format!("snap-{}", N);
        fs.snapshot(&snapshot_name).unwrap();

        match N % 4 {
            0 => {
                fs.write_file(&target, payload(N + 10_000, (N % 19) + 3)).unwrap();
            }
            1 => {
                fs.delete(&target).unwrap();
                fs.write_file(&format!("{}/replacement-{}.dat", branch, N), payload(N + 11_000, 7)).unwrap();
            }
            2 => {
                fs.write_file(&format!("{}/extra-{}.dat", branch, N), payload(N + 12_000, 9)).unwrap();
                fs.delete(&sibling).unwrap();
            }
            _ => {
                let subdir = format!("{}/dir-{}", branch, N);
                fs.create_dir(&subdir).unwrap();
                fs.write_file(&format!("{subdir}/nested.txt"), payload(N + 13_000, 15)).unwrap();
                fs.delete(&target).unwrap();
            }
        }

        fs.rollback(&snapshot_name).unwrap();
        assert_eq!(fs.read_file(&target).unwrap(), original);
        assert!(fs.read_file(&sibling).is_ok());
        assert!(!fs.exists(&format!("{}/replacement-{}.dat", branch, N)));
        assert!(!fs.exists(&format!("{}/extra-{}.dat", branch, N)));
        assert!(!fs.exists(&format!("{}/dir-{}/nested.txt", branch, N)));
    }
});

seq!(N in 0..128 {
    #[test]
    fn clone_isolation_case_~N() {
        let mut fs = SnapshotFs::new();
        let root = format!("/vol{}", N % 6);
        let dir = format!("{root}/tree{}", N % 8);
        fs.create_dir(&root).unwrap();
        fs.create_dir(&dir).unwrap();

        let base_file = format!("{}/base-{}.txt", dir, N);
        let stable_file = format!("{}/stable-{}.txt", dir, N);
        let original = payload(N + 100, (N % 31) + 1);
        fs.write_file(&base_file, &original).unwrap();
        fs.write_file(&stable_file, payload(N + 200, 5)).unwrap();

        let snapshot_name = format!("seed-{}", N);
        let branch_name = format!("feature-{}", N);
        fs.snapshot(&snapshot_name).unwrap();
        fs.clone_snapshot(&snapshot_name, &branch_name).unwrap();

        fs.checkout_branch(&branch_name).unwrap();
        match N % 4 {
            0 => {
                fs.write_file(&base_file, payload(N + 20_000, (N % 17) + 2)).unwrap();
            }
            1 => {
                fs.delete(&base_file).unwrap();
                fs.write_file(&format!("{}/new-{}.txt", dir, N), payload(N + 21_000, 8)).unwrap();
            }
            2 => {
                let child = format!("{}/child-{}", dir, N);
                fs.create_dir(&child).unwrap();
                fs.write_file(&format!("{child}/deep.txt"), payload(N + 22_000, 11)).unwrap();
            }
            _ => {
                fs.write_file(&stable_file, payload(N + 23_000, 13)).unwrap();
                fs.write_file(&format!("{}/audit-{}.log", dir, N), payload(N + 24_000, 3)).unwrap();
            }
        }

        fs.checkout_branch("main").unwrap();
        assert_eq!(fs.read_file(&base_file).unwrap(), original);
        assert!(fs.read_file(&stable_file).is_ok());
        assert!(!fs.exists(&format!("{}/new-{}.txt", dir, N)));
        assert!(!fs.exists(&format!("{}/child-{}/deep.txt", dir, N)));
        assert!(!fs.exists(&format!("{}/audit-{}.log", dir, N)));

        fs.checkout_branch(&branch_name).unwrap();
        match N % 4 {
            0 => {
                assert_ne!(fs.read_file(&base_file).unwrap(), original);
            }
            1 => {
                assert!(!fs.exists(&base_file));
                assert!(fs.exists(&format!("{}/new-{}.txt", dir, N)));
            }
            2 => {
                assert!(fs.exists(&format!("{}/child-{}/deep.txt", dir, N)));
            }
            _ => {
                assert!(fs.exists(&format!("{}/audit-{}.log", dir, N)));
            }
        }
    }
});

seq!(N in 0..96 {
    #[test]
    fn delete_and_restore_case_~N() {
        let mut fs = SnapshotFs::new();
        let root = format!("/fs{}", N % 5);
        let parent = format!("{root}/parent{}", N % 7);
        let child = format!("{parent}/child{}", N % 9);
        fs.create_dir(&root).unwrap();
        fs.create_dir(&parent).unwrap();
        fs.create_dir(&child).unwrap();

        let keep_file = format!("{}/keep-{}.bin", child, N);
        let drop_file = format!("{}/drop-{}.bin", child, N);
        let nested_dir = format!("{}/nested-{}", child, N);
        fs.write_file(&keep_file, payload(N + 300, 4)).unwrap();
        fs.write_file(&drop_file, payload(N + 301, 6)).unwrap();
        fs.create_dir(&nested_dir).unwrap();
        fs.write_file(&format!("{nested_dir}/deep.bin"), payload(N + 302, 10)).unwrap();

        let snapshot_name = format!("restore-{}", N);
        fs.snapshot(&snapshot_name).unwrap();

        if N % 2 == 0 {
            fs.delete(&drop_file).unwrap();
            assert!(!fs.exists(&drop_file));
        } else {
            fs.delete(&nested_dir).unwrap();
            assert!(!fs.exists(&format!("{nested_dir}/deep.bin")));
        }

        assert!(fs.delete(&format!("{}/missing-{}.bin", child, N)).is_err());

        fs.rollback(&snapshot_name).unwrap();
        assert!(fs.exists(&keep_file));
        assert!(fs.exists(&drop_file));
        assert!(fs.exists(&format!("{nested_dir}/deep.bin")));
    }
});
