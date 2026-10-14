<?php
/**
 * Backup: the verbs every backup unit shares (tools/backup/README.md). A unit implements BackupTool
 * and calls Backup::run(); login, the keep options, the --expect rule, the restore-target rule
 * and "one backup job at a time" live here once, so restic and borg behave the same.
 */
$backupLib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config'] as $backupClass) {
    require_once "$backupLib/$backupClass.php";
}
require_once dirname(__DIR__, 2) . '/storage/lib/ToolProc.php';
require_once dirname(__DIR__, 2) . '/storage/lib/HomePath.php';
require_once __DIR__ . '/BackupTool.php';
require_once __DIR__ . '/BackupStore.php';
require_once __DIR__ . '/BackupRules.php';

class Backup
{
    /** $ctx: store (BackupStore), make (fn() => BackupTool), script, mountinfo, procRoot, uid. */
    public static function run(array $argv, array $ctx, $usage)
    {
        $made = null;
        $t = function () use (&$made, $ctx) {
            return $made !== null ? $made : ($made = call_user_func($ctx['make']));
        };
        Cli::run($argv, [
            'login' => function ($args, $opt) use ($t, $ctx) {
                $repo = isset($opt['repo']) && is_string($opt['repo']) ? $opt['repo'] : '';
                if ($repo === '' || $repo[0] === '-' || preg_match('/[\x00-\x1f]/', $repo)) {
                    throw new CliError('login needs --repo <repository>', 'e.g. --repo sftp:user@host:backup, or a local path');
                }
                $t()->bin();
                return self::login($ctx['store'], $t(), $repo, BackupStore::prompt('Repository password (not shown): ', $ctx['script']));
            },
            'list' => function ($args, $opt) use ($t) {
                $path = isset($opt['path']) && is_string($opt['path']) ? $opt['path'] : null;
                $all = array_values(array_filter($t()->snapshots(), function ($s) use ($path) {
                    return $path === null || in_array($path, (array) $s['paths'], true);
                }));
                usort($all, function ($a, $b) {
                    return (int) $b['time'] - (int) $a['time'];
                });
                return $all;
            },
            'show' => function ($args, $opt) use ($t) {
                if (!empty($opt['prune-plan'])) {
                    $plan = $t()->prunePlan(BackupRules::keep($opt));
                    $n = count($plan['remove']);
                    return ['records' => $plan['remove'], 'status' => ['remove' => $n, 'keep' => $plan['keep'], 'expect' => $n]];
                }
                $id = BackupRules::id($args);
                $path = isset($args[1]) ? (string) $args[1] : null;
                return !empty($opt['files']) ? $t()->files($id, $path, BackupRules::limit($opt)) : [$t()->details($id)];
            },
            'stats' => function ($args, $opt) use ($t) {
                return [$t()->stats()];
            },
            'check' => function ($args, $opt) use ($t) {
                $subset = isset($opt['read-data-subset']) ? (string) $opt['read-data-subset'] : null;
                if ($subset !== null && !preg_match('#^(\d+/\d+|\d+(\.\d+)?%)$#', $subset)) {
                    throw new CliError('--read-data-subset is n/m or a percentage, e.g. 5%', 'or --read-data for everything');
                }
                return [$t()->check(!empty($opt['read-data']), $subset)];
            },
            'backup' => function ($args, $opt) use ($t, $ctx) {
                if (!$args) {
                    throw new CliError('backup needs at least one path', 'backup ~/data/important');
                }
                $home = HomePath::home();
                $paths = array_map(function ($p) use ($home) {
                    return HomePath::existing($p, $home);
                }, $args);
                BackupRules::idle($ctx);
                return [$t()->backup(array_values(array_unique($paths)))];
            },
            'restore' => function ($args, $opt) use ($t, $ctx) {
                return self::restore($t(), $args, $opt, $ctx);
            },
            'prune' => function ($args, $opt) use ($t) {
                $plan = $t()->prunePlan(BackupRules::keep($opt));
                $n = count($plan['remove']);
                $expect = isset($opt['expect']) && is_string($opt['expect']) ? $opt['expect'] : '';
                if ($expect === '') {
                    throw new CliError("nothing removed: --expect is missing (prune would remove $n snapshots)",
                        "run `show --prune-plan` with the same --keep options, show the user the list, then add --expect $n");
                }
                if (!preg_match('/^\d+$/', $expect) || (int) $expect !== $n) {
                    throw new CliError("nothing removed: the policy now removes $n snapshots, not $expect",
                        'run `show --prune-plan` again and show the user the new list');
                }
                if ($n === 0) {
                    return ['records' => [], 'status' => ['removed' => 0, 'verified' => true]];
                }
                $res = $t()->prune($plan['remove']);
                $gone = array_column($plan['remove'], 'snapshot_id');
                $left = array_intersect($gone, array_column($t()->snapshots(), 'snapshot_id'));
                return ['records' => $res['records'], 'status' => $res['status'] + ['removed' => $n, 'verified' => !$left]];
            },
        ], $usage, ['files', 'prune-plan', 'read-data'], array_merge(['repo', 'target', 'include', 'expect', 'read-data-subset', 'path'],
            BackupRules::KEEP));
    }

    /**
     * Checks a typed login against the repository before it replaces anything: refused, it is dropped and the stored
     * login stays; not checkable (network, lock), it replaces a stored login never, and is stored only when there is none.
     */
    public static function login(BackupStore $store, BackupTool $tool, $repo, $pw)
    {
        $store->stage($repo, $pw);
        try {
            $res = $tool->verify();
        } catch (Exception $e) {
            $store->discard();
            throw $e;
        }
        $had = $store->loggedIn();
        if ($res === 'wrong' || ($res !== 'ok' && $had)) {
            $store->discard();
            $why = $res === 'wrong' ? 'the repository refused that password' : 'the new login could not be checked: ' . $res;
            throw new CliError("$why; nothing stored" . ($had ? ', the stored login is unchanged' : ''),
                'run login again with the repository password');
        }
        $kept = $store->commit();
        $r = ['repo' => ToolProc::redact($repo), 'stored' => $store->secretPath(), 'verified' => $res === 'ok']
            + ($kept !== null ? ['previous_password_kept' => $kept] : []);
        return ['records' => [$res === 'ok' ? $r : $r + ['note' => 'stored without a check: ' . $res]],
            'status' => ['verified' => $res === 'ok']];
    }

    private static function restore(BackupTool $tool, array $args, array $opt, array $ctx)
    {
        $id = BackupRules::id($args);
        $include = isset($opt['include']) && is_string($opt['include']) ? $opt['include'] : null;
        if ($include !== null && ($include === '' || $include[0] !== '/' || preg_match('/[\x00-\x1f]/', $include))) {
            throw new CliError('--include takes one path as stored in the snapshot, starting with /',
                'see the paths with `show <id> --files`');
        }
        $tool->bin();
        $target = isset($opt['target']) ? $opt['target'] : '';
        list($target, $created) = HomePath::newTarget($target, HomePath::home(), HomePath::fuseMounts($ctx['mountinfo']));
        try {
            $r = $tool->restore($id, $target, $include);
        } catch (Exception $e) {
            if ($created) {
                @rmdir($target); // only our own new directory, and only while it is still empty
            }
            throw $e;
        }
        $n = count((array) @scandir($target)) - 2;
        return ['records' => [$r + ['target' => $target, 'entries' => $n]], 'status' => ['verified' => $n > 0]];
    }
}
