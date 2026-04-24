/* tslint:disable */
/* eslint-disable */

export class WasmSnapshotFs {
    free(): void;
    [Symbol.dispose](): void;
    branch_info_json(): string;
    branch_names_json(): string;
    checkout_branch(branch_name: string): void;
    clone_snapshot(snapshot_name: string, branch_name: string): void;
    create_dir(path: string): void;
    current_branch(): string;
    delete(path: string): void;
    exists(path: string): boolean;
    exists_in_snapshot(snapshot_name: string, path: string): boolean;
    list_dir_in_snapshot_json(snapshot_name: string, path: string): string;
    list_dir_json(path: string): string;
    constructor();
    read_file(path: string): Uint8Array;
    read_file_in_snapshot(snapshot_name: string, path: string): Uint8Array;
    rollback(snapshot_name: string): void;
    snapshot(name: string): void;
    snapshot_info_json(): string;
    snapshot_names_json(): string;
    stats_json(): string;
    write_file(path: string, data: Uint8Array): void;
}

export type InitInput = RequestInfo | URL | Response | BufferSource | WebAssembly.Module;

export interface InitOutput {
    readonly memory: WebAssembly.Memory;
    readonly __wbg_wasmsnapshotfs_free: (a: number, b: number) => void;
    readonly wasmsnapshotfs_branch_info_json: (a: number) => [number, number];
    readonly wasmsnapshotfs_branch_names_json: (a: number) => [number, number];
    readonly wasmsnapshotfs_checkout_branch: (a: number, b: number, c: number) => [number, number];
    readonly wasmsnapshotfs_clone_snapshot: (a: number, b: number, c: number, d: number, e: number) => [number, number];
    readonly wasmsnapshotfs_create_dir: (a: number, b: number, c: number) => [number, number];
    readonly wasmsnapshotfs_current_branch: (a: number) => [number, number];
    readonly wasmsnapshotfs_delete: (a: number, b: number, c: number) => [number, number];
    readonly wasmsnapshotfs_exists: (a: number, b: number, c: number) => number;
    readonly wasmsnapshotfs_exists_in_snapshot: (a: number, b: number, c: number, d: number, e: number) => [number, number, number];
    readonly wasmsnapshotfs_list_dir_in_snapshot_json: (a: number, b: number, c: number, d: number, e: number) => [number, number, number, number];
    readonly wasmsnapshotfs_list_dir_json: (a: number, b: number, c: number) => [number, number, number, number];
    readonly wasmsnapshotfs_new: () => number;
    readonly wasmsnapshotfs_read_file: (a: number, b: number, c: number) => [number, number, number, number];
    readonly wasmsnapshotfs_read_file_in_snapshot: (a: number, b: number, c: number, d: number, e: number) => [number, number, number, number];
    readonly wasmsnapshotfs_rollback: (a: number, b: number, c: number) => [number, number];
    readonly wasmsnapshotfs_snapshot: (a: number, b: number, c: number) => [number, number];
    readonly wasmsnapshotfs_snapshot_info_json: (a: number) => [number, number];
    readonly wasmsnapshotfs_snapshot_names_json: (a: number) => [number, number];
    readonly wasmsnapshotfs_stats_json: (a: number) => [number, number];
    readonly wasmsnapshotfs_write_file: (a: number, b: number, c: number, d: number, e: number) => [number, number];
    readonly __wbindgen_externrefs: WebAssembly.Table;
    readonly __wbindgen_free: (a: number, b: number, c: number) => void;
    readonly __wbindgen_malloc: (a: number, b: number) => number;
    readonly __wbindgen_realloc: (a: number, b: number, c: number, d: number) => number;
    readonly __externref_table_dealloc: (a: number) => void;
    readonly __wbindgen_start: () => void;
}

export type SyncInitInput = BufferSource | WebAssembly.Module;

/**
 * Instantiates the given `module`, which can either be bytes or
 * a precompiled `WebAssembly.Module`.
 *
 * @param {{ module: SyncInitInput }} module - Passing `SyncInitInput` directly is deprecated.
 *
 * @returns {InitOutput}
 */
export function initSync(module: { module: SyncInitInput } | SyncInitInput): InitOutput;

/**
 * If `module_or_path` is {RequestInfo} or {URL}, makes a request and
 * for everything else, calls `WebAssembly.instantiate` directly.
 *
 * @param {{ module_or_path: InitInput | Promise<InitInput> }} module_or_path - Passing `InitInput` directly is deprecated.
 *
 * @returns {Promise<InitOutput>}
 */
export default function __wbg_init (module_or_path?: { module_or_path: InitInput | Promise<InitInput> } | InitInput | Promise<InitInput>): Promise<InitOutput>;
