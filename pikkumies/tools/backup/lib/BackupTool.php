<?php
/**
 * BackupTool: what each backup unit implements for the shared verbs (tools/backup/README.md).
 * Records follow the README: a snapshot is `id` (what show, restore and prune take), `time` (unix),
 * `host`, `paths`, `tags`. Failures throw CliError with a hint; nothing here prompts.
 */
interface BackupTool
{
    /** The program's path, or a "not installed" CliError. */
    public function bin();

    /** One record: binary version and what it supports, repository, reachable, counts, latest time. */
    public function stats();

    /** After login: 'ok', 'wrong' (the repository refused the password), or an error text. */
    public function verify();

    /** Every snapshot or archive as a record. */
    public function snapshots();

    /** One snapshot's details. */
    public function details($id);

    /** Up to $max entries of a snapshot (under $path when given): path, type, bytes, modified_at. */
    public function files($id, $path, $max);

    /** What prune with $keep would remove: ['remove' => [snapshot records], 'keep' => int]. */
    public function prunePlan(array $keep);

    /** Removes exactly these snapshots and frees the space: ['records' => [...], 'status' => [...]]. */
    public function prune(array $remove);

    /** Repository check: metadata, or the data too. One record. */
    public function check($readData, $subset);

    /** Backs up $paths (real paths inside the home). One record (the new snapshot). */
    public function backup(array $paths);

    /** Restores snapshot $id into the new directory $target ($include: one path, or null). One record. */
    public function restore($id, $target, $include);
}
