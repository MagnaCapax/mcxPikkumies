<?php
/**
 * BackupRules: the checks every backup verb shares (tools/backup/README.md): the keep options, snapshot
 * ids, the check record, and one backup job per account at a time (file listings page with ToolProc::want).
 */
class BackupRules
{
    const KEEP = ['keep-last', 'keep-hourly', 'keep-daily', 'keep-weekly', 'keep-monthly', 'keep-yearly', 'keep-within'];

    /** The --keep-* options, checked: whole numbers, keep-within like 7d (H, d, w, m, y). At least one. */
    public static function keep(array $opt)
    {
        $keep = [];
        foreach (self::KEEP as $k) {
            if (!isset($opt[$k])) {
                continue;
            }
            $v = (string) $opt[$k];
            if ($k === 'keep-within' ? !preg_match('/^\d+[Hdwmy]$/', $v) : !preg_match('/^[1-9]\d{0,5}$/', $v)) {
                throw new CliError("--$k $v is not valid", $k === 'keep-within' ? 'e.g. --keep-within 30d'
                    : 'a whole number of at least 1');
            }
            $keep[$k] = $v;
        }
        if (!$keep) {
            throw new CliError('name what to keep, e.g. --keep-daily 7 --keep-weekly 4', 'options: --' . implode(', --', self::KEEP));
        }
        return $keep;
    }

    public static function id(array $args)
    {
        $id = isset($args[0]) ? (string) $args[0] : '';
        if ($id === '' || $id[0] === '-' || strlen($id) > 200 || preg_match('#[\x00-\x1f/]#', $id)) {
            throw new CliError('name a snapshot: the id `list` prints', 'ids come from `list`');
        }
        return $id;
    }

    /** The shared check record: errors_found only for damage the program reported; a check that did not finish says nothing. */
    public static function checked($damage, $finished, array $r, $readData)
    {
        $rec = ['errors_found' => $damage, 'finished' => $finished, 'exit_code' => $r['code'], 'read_data' => $readData,
            'output' => ToolProc::tail($r['out'] . "\n" . $r['err'])];
        return $finished ? $rec
            : $rec + ['note' => 'the check did not finish (lock, network, login or option: see output), so it says nothing about damage'];
    }

    /** One backup job per account: refuses while a restic or borg process of this account runs. */
    public static function idle(array $ctx)
    {
        foreach ((array) glob($ctx['procRoot'] . '/[0-9]*', GLOB_ONLYDIR) as $dir) {
            $comm = trim((string) @file_get_contents("$dir/comm"));
            if (@fileowner($dir) === (int) $ctx['uid'] && in_array($comm, ['restic', 'borg', 'borgmatic'], true)
                && (int) basename($dir) !== getmypid()) {
                throw new CliError("a $comm job of this account is already running (pid " . basename($dir) . '); nothing started',
                    'wait for it to finish');
            }
        }
    }
}
