<?php
/**
 * DockerDump: `dump <container> [--file <path>]` (ADR-0034, amendment of 2026-10-19). A consistent copy of a
 * database that runs in a container, taken by the database's own dump program inside that container (`docker exec`,
 * nothing pulled, nothing started), written to a new file of this account: ~/docker/dumps/<name>-<UTC time>.sql by
 * default, mode 600. The file the account can read and back up, unlike the database's own files in the bind folder
 * (they belong to the container's sub-uid and change while the database runs).
 *
 * Recognised images: MariaDB and MySQL (mariadb-dump, else mysqldump, --all-databases --single-transaction) and
 * PostgreSQL (pg_dumpall). No secret appears on a command line, here or in the container: the script names the
 * container's own environment variables (MARIADB_ROOT_PASSWORD, MYSQL_ROOT_PASSWORD, their _FILE forms, POSTGRES_...)
 * and hands the value to the dump program in its environment (MYSQL_PWD, PGPASSWORD); this tool never prints it.
 * An existing file is never overwritten. The dump goes to <file>.partial and is renamed only when the program
 * exited 0 and wrote its own completion line; otherwise the .partial stays and the record says so.
 */
class DockerDump
{
    /** image name (last path part, no tag) => engine. */
    const IMAGES = ['mariadb' => 'mysql', 'mysql' => 'mysql', 'percona' => 'mysql', 'percona-server' => 'mysql',
        'postgres' => 'postgres', 'postgis' => 'postgres', 'timescaledb' => 'postgres'];
    /** The last line each dump program writes when it finished. */
    const DONE = ['mysql' => '-- Dump completed', 'postgres' => 'PostgreSQL database cluster dump complete'];
    const TAIL = 512;

    /** mode => script run as `sh -c` inside the container; only variable names, never a value. */
    const SCRIPTS = [
        'mysql-root' => 'p="${MARIADB_ROOT_PASSWORD:-${MYSQL_ROOT_PASSWORD:-}}"; f="${MARIADB_ROOT_PASSWORD_FILE:-${MYSQL_ROOT_PASSWORD_FILE:-}}"; '
            . 'if [ -z "$p" ] && [ -n "$f" ]; then p="$(cat "$f")"; fi; export MYSQL_PWD="$p"; d=mysqldump; '
            . 'if command -v mariadb-dump >/dev/null 2>&1; then d=mariadb-dump; fi; exec "$d" -uroot --all-databases --single-transaction --routines --events',
        'mysql-user' => 'u="${MARIADB_USER:-${MYSQL_USER:-}}"; p="${MARIADB_PASSWORD:-${MYSQL_PASSWORD:-}}"; f="${MARIADB_PASSWORD_FILE:-${MYSQL_PASSWORD_FILE:-}}"; '
            . 'if [ -z "$p" ] && [ -n "$f" ]; then p="$(cat "$f")"; fi; export MYSQL_PWD="$p"; d=mysqldump; '
            . 'if command -v mariadb-dump >/dev/null 2>&1; then d=mariadb-dump; fi; exec "$d" -u"$u" --single-transaction --routines --databases "${MARIADB_DATABASE:-${MYSQL_DATABASE:-}}"',
        'postgres' => 'p="${POSTGRES_PASSWORD:-}"; if [ -z "$p" ] && [ -n "${POSTGRES_PASSWORD_FILE:-}" ]; then p="$(cat "$POSTGRES_PASSWORD_FILE")"; fi; '
            . 'export PGPASSWORD="$p"; exec pg_dumpall -U "${POSTGRES_USER:-postgres}"',
    ];

    public static function dump(DockerCli $d, array $args, array $opt)
    {
        if (count($args) !== 1) {
            throw new CliError('dump takes one container: a database container from `list`; nothing was done', 'e.g. dump db');
        }
        $d->requireHealthy();
        $inv = DockerOps::inventory($d, ['ps', '--all', '--format', '{{json .}}']);
        $id = DockerRefs::resolve([(string) $args[0]], DockerOps::refList($inv))[0];
        $i = DockerOps::find($inv, $id);
        $name = DockerOps::nameOf($i);
        $image = isset($i['Config']['Image']) ? (string) $i['Config']['Image'] : '';
        $engine = self::engine($image);
        if ($engine === null) {
            throw new CliError("nothing was dumped: $name runs $image, which dump does not recognise (MariaDB, MySQL and PostgreSQL images are)", 'use the database\'s own dump command with `docker exec`, writing the output to a file');
        }
        if (empty($i['State']['Running'])) {
            throw new CliError("nothing was dumped: $name is not running (the dump runs inside it)", "start $name, then dump it");
        }
        $env = self::env($i);
        $mode = $engine === 'postgres' ? 'postgres' : self::mysqlMode(array_keys($env));
        if ($mode === null) {
            throw new CliError("nothing was dumped: $name keeps no database login in its environment (an image that made up a random root login prints it once, in its first log)", 'dump with the database\'s own client and the login the user has');
        }
        $file = self::file($d, $name, isset($opt['file']) ? $opt['file'] : null);
        $partial = "$file.partial";
        $old = umask(0077);
        $fh = @fopen($partial, 'x');
        umask($old);
        if ($fh === false) {
            throw new CliError("nothing was dumped: $partial exists or cannot be created", 'move it away, or give another --file');
        }
        @chmod($partial, 0600);
        $bytes = 0;
        $tail = '';
        $r = $d->run(['exec', $id, 'sh', '-c', self::SCRIPTS[$mode]], (int) max(10, floor($d->remaining() - 5)), function ($line) use ($fh, &$bytes, &$tail) {
            $bytes += (int) fwrite($fh, $line . "\n");
            $tail = substr($tail . $line . "\n", -self::TAIL);
        });
        fclose($fh);
        $done = $r['code'] === 0 && strpos($tail, self::DONE[$engine]) !== false;
        $error = null;
        if (!$done) {
            $err = DockerDiagnose::redact(trim((string) preg_split('/\r?\n/', trim((string) $r['err']))[0]), array_values(array_filter($env, function ($v) {
                return strlen($v) >= 4;
            })));
            $error = $r['timed_out'] ? 'the dump did not finish within the time limit' : ($r['code'] !== 0 ? substr($err !== '' ? $err : 'exit ' . $r['code'], 0, Cli::TEXT_MAX)
                : 'the dump program ended without its completion line');
            if ($bytes === 0) {
                @unlink($partial); // this run wrote nothing into it: an empty file of its own holds nothing to keep
            }
        } elseif (!@rename($partial, $file)) {
            $done = false;
            $error = "the dump is complete in $partial but could not be renamed to $file";
        }
        $path = $done ? $file : ($bytes > 0 ? $partial : null);
        return ['records' => [['kind' => 'dump', 'container' => $name, 'engine' => $engine, 'as' => $mode === 'mysql-user' ? 'the app\'s database user' : ($engine === 'postgres' ? 'the postgres user' : 'root'),
            'file' => $path, 'bytes' => $bytes, 'complete' => $done, 'sha256' => $done ? hash_file('sha256', $file) : null, 'error_text' => $error]],
            'status' => ['complete' => $done]];
    }

    /** mysql | postgres | null from the image name (registry and tag dropped). */
    public static function engine($image)
    {
        $bare = strtolower(preg_replace('/[@:][^\/]*$/', '', (string) $image));
        $last = substr($bare, (int) strrpos('/' . $bare, '/'));
        return isset(self::IMAGES[$last]) ? self::IMAGES[$last] : null;
    }

    /** mysql-root when root's login (or an allowed empty one) is set, mysql-user when an app user with its database is, else null. */
    public static function mysqlMode(array $names)
    {
        if (array_intersect($names, ['MARIADB_ROOT_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'MARIADB_ROOT_PASSWORD_FILE', 'MYSQL_ROOT_PASSWORD_FILE',
            'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD', 'MYSQL_ALLOW_EMPTY_PASSWORD'])) {
            return 'mysql-root';
        }
        $has = function (array $any) use ($names) {
            return (bool) array_intersect($names, $any);
        };
        return $has(['MARIADB_USER', 'MYSQL_USER']) && $has(['MARIADB_PASSWORD', 'MYSQL_PASSWORD', 'MARIADB_PASSWORD_FILE', 'MYSQL_PASSWORD_FILE'])
            && $has(['MARIADB_DATABASE', 'MYSQL_DATABASE']) ? 'mysql-user' : null;
    }

    /** The container's environment, name => value: the names decide the mode, the values only scrub docker's error text. */
    private static function env(array $i)
    {
        $out = [];
        foreach (isset($i['Config']['Env']) && is_array($i['Config']['Env']) ? $i['Config']['Env'] : [] as $e) {
            $kv = explode('=', (string) $e, 2);
            $out[$kv[0]] = isset($kv[1]) ? $kv[1] : '';
        }
        return $out;
    }

    /** The new dump file: --file (inside the home, its folder created), else ~/docker/dumps/<name>-<UTC time>.sql; never an existing one. */
    private static function file(DockerCli $d, $name, $given)
    {
        if ($given !== null) {
            $given = is_string($given) ? $given : '';
            $given = strncmp($given, '~/', 2) === 0 ? $d->home . substr($given, 1) : $given;
            if ($given === '' || substr($given, -1) === '/' || basename($given) === '..' || basename($given) === '.') {
                throw new CliError('--file takes the path of a new file; nothing was dumped', 'e.g. --file ~/docker/dumps/db.sql');
            }
            $file = DockerRecord::folder($d, dirname($given), '--file') . '/' . basename($given);
        } else {
            $file = DockerRecord::folder($d, $d->home . '/docker/dumps', 'dump') . '/' . $name . '-' . gmdate('Ymd-His') . 'Z.sql';
        }
        if (file_exists($file) || is_link($file)) {
            throw new CliError("nothing was dumped: $file already exists and is never overwritten", 'give another --file');
        }
        return $file;
    }
}
