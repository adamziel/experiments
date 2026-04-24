use std::collections::{BTreeMap, BTreeSet};
use std::sync::Arc;

use serde::Serialize;
use wasm_bindgen::prelude::*;

pub type FsResult<T> = Result<T, FsError>;

#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum FsError {
    #[error("invalid path: {0}")]
    InvalidPath(String),
    #[error("invalid name: {0}")]
    InvalidName(String),
    #[error("path does not exist: {0}")]
    NotFound(String),
    #[error("expected directory at: {0}")]
    NotDirectory(String),
    #[error("expected file at: {0}")]
    NotFile(String),
    #[error("directory already exists at: {0}")]
    DirectoryExists(String),
    #[error("file already exists at: {0}")]
    FileExists(String),
    #[error("snapshot already exists: {0}")]
    SnapshotExists(String),
    #[error("snapshot does not exist: {0}")]
    SnapshotMissing(String),
    #[error("branch already exists: {0}")]
    BranchExists(String),
    #[error("branch does not exist: {0}")]
    BranchMissing(String),
    #[error("cannot delete filesystem root")]
    CannotDeleteRoot,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub enum EntryKind {
    File,
    Directory,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct DirEntry {
    pub name: String,
    pub kind: EntryKind,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct FsStats {
    pub current_branch: String,
    pub branch_count: usize,
    pub snapshot_count: usize,
    pub current_logical_bytes: usize,
    pub referenced_node_count: usize,
}

#[derive(Debug, Clone)]
pub struct SnapshotFs {
    branches: BTreeMap<String, BranchRecord>,
    snapshots: BTreeMap<String, SnapshotRecord>,
    current_branch: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct BranchInfo {
    pub name: String,
    pub parent_branch: Option<String>,
    pub source_snapshot: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct SnapshotInfo {
    pub name: String,
    pub source_branch: String,
}

#[derive(Debug, Clone)]
struct BranchRecord {
    root: Arc<Node>,
    parent_branch: Option<String>,
    source_snapshot: Option<String>,
}

#[derive(Debug, Clone)]
struct SnapshotRecord {
    root: Arc<Node>,
    source_branch: String,
}

#[derive(Debug, Clone)]
enum Node {
    File(Vec<u8>),
    Directory(BTreeMap<String, Arc<Node>>),
}

impl Default for SnapshotFs {
    fn default() -> Self {
        Self::new()
    }
}

impl SnapshotFs {
    pub fn new() -> Self {
        let mut branches = BTreeMap::new();
        branches.insert(
            "main".to_string(),
            BranchRecord {
                root: Arc::new(Node::Directory(BTreeMap::new())),
                parent_branch: None,
                source_snapshot: None,
            },
        );

        Self {
            branches,
            snapshots: BTreeMap::new(),
            current_branch: "main".to_string(),
        }
    }

    pub fn create_dir(&mut self, path: &str) -> FsResult<()> {
        let segments = parse_path(path)?;
        let root = self.current_root().clone();
        let updated = create_dir_at(&root, &segments)?;
        self.set_current_root(updated);
        Ok(())
    }

    pub fn write_file(&mut self, path: &str, data: impl AsRef<[u8]>) -> FsResult<()> {
        let segments = parse_path(path)?;
        let root = self.current_root().clone();
        let updated = write_file_at(&root, &segments, data.as_ref())?;
        self.set_current_root(updated);
        Ok(())
    }

    pub fn read_file(&self, path: &str) -> FsResult<Vec<u8>> {
        self.read_file_from_root(self.current_root(), path)
    }

    pub fn read_file_in_snapshot(&self, snapshot_name: &str, path: &str) -> FsResult<Vec<u8>> {
        let snapshot = self.snapshot_root(snapshot_name)?;
        self.read_file_from_root(snapshot, path)
    }

    fn read_file_from_root(&self, root: &Arc<Node>, path: &str) -> FsResult<Vec<u8>> {
        let segments = parse_path(path)?;
        let node = find_node(root, &segments)
            .ok_or_else(|| FsError::NotFound(path.to_string()))?;

        match node {
            Node::File(bytes) => Ok(bytes.clone()),
            Node::Directory(_) => Err(FsError::NotFile(path.to_string())),
        }
    }

    pub fn delete(&mut self, path: &str) -> FsResult<()> {
        let segments = parse_path(path)?;
        let root = self.current_root().clone();
        let updated = delete_at(&root, &segments)?;
        self.set_current_root(updated);
        Ok(())
    }

    pub fn exists(&self, path: &str) -> bool {
        self.exists_from_root(self.current_root(), path)
    }

    pub fn exists_in_snapshot(&self, snapshot_name: &str, path: &str) -> FsResult<bool> {
        let snapshot = self.snapshot_root(snapshot_name)?;
        Ok(self.exists_from_root(snapshot, path))
    }

    fn exists_from_root(&self, root: &Arc<Node>, path: &str) -> bool {
        parse_path(path)
            .ok()
            .and_then(|segments| find_node(root, &segments))
            .is_some()
    }

    pub fn list_dir(&self, path: &str) -> FsResult<Vec<DirEntry>> {
        self.list_dir_from_root(self.current_root(), path)
    }

    pub fn list_dir_in_snapshot(&self, snapshot_name: &str, path: &str) -> FsResult<Vec<DirEntry>> {
        let snapshot = self.snapshot_root(snapshot_name)?;
        self.list_dir_from_root(snapshot, path)
    }

    fn list_dir_from_root(&self, root: &Arc<Node>, path: &str) -> FsResult<Vec<DirEntry>> {
        let segments = parse_path(path)?;
        let node = find_node(root, &segments)
            .ok_or_else(|| FsError::NotFound(path.to_string()))?;

        match node {
            Node::Directory(entries) => Ok(entries
                .iter()
                .map(|(name, node)| DirEntry {
                    name: name.clone(),
                    kind: match node.as_ref() {
                        Node::File(_) => EntryKind::File,
                        Node::Directory(_) => EntryKind::Directory,
                    },
                })
                .collect()),
            Node::File(_) => Err(FsError::NotDirectory(path.to_string())),
        }
    }

    pub fn snapshot(&mut self, name: &str) -> FsResult<()> {
        validate_name(name)?;
        if self.snapshots.contains_key(name) {
            return Err(FsError::SnapshotExists(name.to_string()));
        }

        self.snapshots
            .insert(
                name.to_string(),
                SnapshotRecord {
                    root: self.current_root().clone(),
                    source_branch: self.current_branch.clone(),
                },
            );
        Ok(())
    }

    pub fn rollback(&mut self, snapshot_name: &str) -> FsResult<()> {
        let snapshot = self
            .snapshots
            .get(snapshot_name)
            .cloned()
            .ok_or_else(|| FsError::SnapshotMissing(snapshot_name.to_string()))?;
        self.set_current_root(snapshot.root);
        Ok(())
    }

    pub fn clone_snapshot(&mut self, snapshot_name: &str, branch_name: &str) -> FsResult<()> {
        validate_name(branch_name)?;
        if self.branches.contains_key(branch_name) {
            return Err(FsError::BranchExists(branch_name.to_string()));
        }

        let snapshot = self
            .snapshots
            .get(snapshot_name)
            .cloned()
            .ok_or_else(|| FsError::SnapshotMissing(snapshot_name.to_string()))?;
        self.branches.insert(
            branch_name.to_string(),
            BranchRecord {
                root: snapshot.root,
                parent_branch: Some(snapshot.source_branch),
                source_snapshot: Some(snapshot_name.to_string()),
            },
        );
        Ok(())
    }

    pub fn checkout_branch(&mut self, branch_name: &str) -> FsResult<()> {
        if !self.branches.contains_key(branch_name) {
            return Err(FsError::BranchMissing(branch_name.to_string()));
        }

        self.current_branch = branch_name.to_string();
        Ok(())
    }

    pub fn branch_names(&self) -> Vec<String> {
        self.branches.keys().cloned().collect()
    }

    pub fn branch_info(&self) -> Vec<BranchInfo> {
        self.branches
            .iter()
            .map(|(name, branch)| BranchInfo {
                name: name.clone(),
                parent_branch: branch.parent_branch.clone(),
                source_snapshot: branch.source_snapshot.clone(),
            })
            .collect()
    }

    pub fn snapshot_names(&self) -> Vec<String> {
        self.snapshots.keys().cloned().collect()
    }

    pub fn snapshot_info(&self) -> Vec<SnapshotInfo> {
        self.snapshots
            .iter()
            .map(|(name, snapshot)| SnapshotInfo {
                name: name.clone(),
                source_branch: snapshot.source_branch.clone(),
            })
            .collect()
    }

    pub fn current_branch(&self) -> &str {
        &self.current_branch
    }

    pub fn stats(&self) -> FsStats {
        let mut seen = BTreeSet::new();
        for branch in self.branches.values() {
            collect_nodes(&branch.root, &mut seen);
        }
        for snapshot in self.snapshots.values() {
            collect_nodes(&snapshot.root, &mut seen);
        }

        FsStats {
            current_branch: self.current_branch.clone(),
            branch_count: self.branches.len(),
            snapshot_count: self.snapshots.len(),
            current_logical_bytes: logical_bytes(self.current_root()),
            referenced_node_count: seen.len(),
        }
    }

    fn current_root(&self) -> &Arc<Node> {
        &self
            .branches
            .get(&self.current_branch)
            .expect("current branch should always exist")
            .root
    }

    fn snapshot_root(&self, snapshot_name: &str) -> FsResult<&Arc<Node>> {
        self.snapshots
            .get(snapshot_name)
            .map(|snapshot| &snapshot.root)
            .ok_or_else(|| FsError::SnapshotMissing(snapshot_name.to_string()))
    }

    fn set_current_root(&mut self, root: Arc<Node>) {
        if let Some(branch) = self.branches.get_mut(&self.current_branch) {
            branch.root = root;
        }
    }
}

#[wasm_bindgen]
pub struct WasmSnapshotFs {
    inner: SnapshotFs,
}

#[wasm_bindgen]
impl WasmSnapshotFs {
    #[wasm_bindgen(constructor)]
    pub fn new() -> Self {
        Self {
            inner: SnapshotFs::new(),
        }
    }

    pub fn create_dir(&mut self, path: &str) -> Result<(), JsValue> {
        self.inner.create_dir(path).map_err(js_error)
    }

    pub fn write_file(&mut self, path: &str, data: &[u8]) -> Result<(), JsValue> {
        self.inner.write_file(path, data).map_err(js_error)
    }

    pub fn read_file(&self, path: &str) -> Result<Vec<u8>, JsValue> {
        self.inner.read_file(path).map_err(js_error)
    }

    pub fn read_file_in_snapshot(&self, snapshot_name: &str, path: &str) -> Result<Vec<u8>, JsValue> {
        self.inner
            .read_file_in_snapshot(snapshot_name, path)
            .map_err(js_error)
    }

    pub fn delete(&mut self, path: &str) -> Result<(), JsValue> {
        self.inner.delete(path).map_err(js_error)
    }

    pub fn exists(&self, path: &str) -> bool {
        self.inner.exists(path)
    }

    pub fn exists_in_snapshot(&self, snapshot_name: &str, path: &str) -> Result<bool, JsValue> {
        self.inner
            .exists_in_snapshot(snapshot_name, path)
            .map_err(js_error)
    }

    pub fn list_dir_json(&self, path: &str) -> Result<String, JsValue> {
        let entries = self.inner.list_dir(path).map_err(js_error)?;
        serde_json::to_string(&entries)
            .map_err(|error| JsValue::from_str(&format!("serialization error: {error}")))
    }

    pub fn list_dir_in_snapshot_json(&self, snapshot_name: &str, path: &str) -> Result<String, JsValue> {
        let entries = self
            .inner
            .list_dir_in_snapshot(snapshot_name, path)
            .map_err(js_error)?;
        serde_json::to_string(&entries)
            .map_err(|error| JsValue::from_str(&format!("serialization error: {error}")))
    }

    pub fn snapshot(&mut self, name: &str) -> Result<(), JsValue> {
        self.inner.snapshot(name).map_err(js_error)
    }

    pub fn rollback(&mut self, snapshot_name: &str) -> Result<(), JsValue> {
        self.inner.rollback(snapshot_name).map_err(js_error)
    }

    pub fn clone_snapshot(&mut self, snapshot_name: &str, branch_name: &str) -> Result<(), JsValue> {
        self.inner
            .clone_snapshot(snapshot_name, branch_name)
            .map_err(js_error)
    }

    pub fn checkout_branch(&mut self, branch_name: &str) -> Result<(), JsValue> {
        self.inner.checkout_branch(branch_name).map_err(js_error)
    }

    pub fn current_branch(&self) -> String {
        self.inner.current_branch().to_string()
    }

    pub fn branch_names_json(&self) -> String {
        serde_json::to_string(&self.inner.branch_names()).unwrap_or_else(|_| "[]".to_string())
    }

    pub fn branch_info_json(&self) -> String {
        serde_json::to_string(&self.inner.branch_info()).unwrap_or_else(|_| "[]".to_string())
    }

    pub fn snapshot_names_json(&self) -> String {
        serde_json::to_string(&self.inner.snapshot_names()).unwrap_or_else(|_| "[]".to_string())
    }

    pub fn snapshot_info_json(&self) -> String {
        serde_json::to_string(&self.inner.snapshot_info()).unwrap_or_else(|_| "[]".to_string())
    }

    pub fn stats_json(&self) -> String {
        serde_json::to_string(&self.inner.stats()).unwrap_or_else(|_| "{}".to_string())
    }
}

fn js_error(error: FsError) -> JsValue {
    JsValue::from_str(&error.to_string())
}

fn parse_path(path: &str) -> FsResult<Vec<&str>> {
    if !path.starts_with('/') {
        return Err(FsError::InvalidPath(path.to_string()));
    }
    if path == "/" {
        return Ok(Vec::new());
    }

    let mut segments = Vec::new();
    for segment in path.split('/').skip(1) {
        if segment.is_empty() || segment == "." || segment == ".." {
            return Err(FsError::InvalidPath(path.to_string()));
        }
        segments.push(segment);
    }
    Ok(segments)
}

fn validate_name(name: &str) -> FsResult<()> {
    if name.is_empty() || name == "." || name == ".." || name.contains('/') {
        return Err(FsError::InvalidName(name.to_string()));
    }
    Ok(())
}

fn find_node<'a>(root: &'a Arc<Node>, segments: &[&str]) -> Option<&'a Node> {
    let mut current = root.as_ref();
    for segment in segments {
        current = match current {
            Node::Directory(entries) => entries.get(*segment)?.as_ref(),
            Node::File(_) => return None,
        };
    }
    Some(current)
}

fn create_dir_at(root: &Arc<Node>, segments: &[&str]) -> FsResult<Arc<Node>> {
    match root.as_ref() {
        Node::File(_) => Err(FsError::NotDirectory(render_path(segments))),
        Node::Directory(entries) => {
            if segments.is_empty() {
                return Ok(root.clone());
            }

            let name = segments[0];
            let mut next_entries = entries.clone();

            if segments.len() == 1 {
                match entries.get(name) {
                    Some(existing) => match existing.as_ref() {
                        Node::Directory(_) => Ok(root.clone()),
                        Node::File(_) => Err(FsError::FileExists(render_path(segments))),
                    },
                    None => {
                        next_entries.insert(name.to_string(), Arc::new(Node::Directory(BTreeMap::new())));
                        Ok(Arc::new(Node::Directory(next_entries)))
                    }
                }
            } else {
                let child = entries
                    .get(name)
                    .cloned()
                    .unwrap_or_else(|| Arc::new(Node::Directory(BTreeMap::new())));
                match child.as_ref() {
                    Node::File(_) => Err(FsError::NotDirectory(render_path(&segments[..1]))),
                    Node::Directory(_) => {
                        let updated_child = create_dir_at(&child, &segments[1..])?;
                        if entries.get(name).is_some() && Arc::ptr_eq(&child, &updated_child) {
                            return Ok(root.clone());
                        }
                        next_entries.insert(name.to_string(), updated_child);
                        Ok(Arc::new(Node::Directory(next_entries)))
                    }
                }
            }
        }
    }
}

fn write_file_at(root: &Arc<Node>, segments: &[&str], data: &[u8]) -> FsResult<Arc<Node>> {
    match root.as_ref() {
        Node::File(_) => Err(FsError::NotDirectory(render_path(segments))),
        Node::Directory(entries) => {
            if segments.is_empty() {
                return Err(FsError::NotFile("/".to_string()));
            }

            let name = segments[0];
            let mut next_entries = entries.clone();

            if segments.len() == 1 {
                match entries.get(name) {
                    Some(existing) if matches!(existing.as_ref(), Node::Directory(_)) => {
                        Err(FsError::DirectoryExists(render_path(segments)))
                    }
                    _ => {
                        next_entries.insert(name.to_string(), Arc::new(Node::File(data.to_vec())));
                        Ok(Arc::new(Node::Directory(next_entries)))
                    }
                }
            } else {
                let child = entries
                    .get(name)
                    .cloned()
                    .unwrap_or_else(|| Arc::new(Node::Directory(BTreeMap::new())));
                match child.as_ref() {
                    Node::File(_) => Err(FsError::NotDirectory(render_path(&segments[..1]))),
                    Node::Directory(_) => {
                        let updated_child = write_file_at(&child, &segments[1..], data)?;
                        next_entries.insert(name.to_string(), updated_child);
                        Ok(Arc::new(Node::Directory(next_entries)))
                    }
                }
            }
        }
    }
}

fn delete_at(root: &Arc<Node>, segments: &[&str]) -> FsResult<Arc<Node>> {
    match root.as_ref() {
        Node::File(_) => Err(FsError::NotDirectory(render_path(segments))),
        Node::Directory(entries) => {
            if segments.is_empty() {
                return Err(FsError::CannotDeleteRoot);
            }

            let name = segments[0];
            let mut next_entries = entries.clone();

            if segments.len() == 1 {
                if next_entries.remove(name).is_none() {
                    return Err(FsError::NotFound(render_path(segments)));
                }
                return Ok(Arc::new(Node::Directory(next_entries)));
            }

            let child = entries
                .get(name)
                .ok_or_else(|| FsError::NotFound(render_path(segments)))?;
            match child.as_ref() {
                Node::File(_) => Err(FsError::NotDirectory(render_path(&segments[..1]))),
                Node::Directory(_) => {
                    let updated_child = delete_at(child, &segments[1..])?;
                    next_entries.insert(name.to_string(), updated_child);
                    Ok(Arc::new(Node::Directory(next_entries)))
                }
            }
        }
    }
}

fn render_path(segments: &[&str]) -> String {
    if segments.is_empty() {
        "/".to_string()
    } else {
        format!("/{}", segments.join("/"))
    }
}

fn logical_bytes(node: &Arc<Node>) -> usize {
    match node.as_ref() {
        Node::File(bytes) => bytes.len(),
        Node::Directory(entries) => entries.values().map(logical_bytes).sum(),
    }
}

fn collect_nodes(node: &Arc<Node>, seen: &mut BTreeSet<usize>) {
    let pointer = Arc::as_ptr(node) as usize;
    if !seen.insert(pointer) {
        return;
    }

    if let Node::Directory(entries) = node.as_ref() {
        for child in entries.values() {
            collect_nodes(child, seen);
        }
    }
}
