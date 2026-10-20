<?php
/**
 * HookRunCount: which spool and which counter file an interval hook event belongs to, and the counts the goal review
 * gates and nudges on (ADR-0096).
 * - hash(): the spool hash. Under the launcher (a valid PIKKUMIES_RUN) one spool per run, depth and Hermes process,
 *   sha1('run:<key>:<PIKKUMIES_DEPTH>:<Hermes pid>:<its start time>'): Hermes runs hooks without a shell, so the
 *   orchestrator's parent is the Hermes process, which a compression (a new session id) keeps. So the spool, the
 *   cursor, the units' state and the pending notes survive it; a delegated agent in the same process shares it (its
 *   records are marked subagent); a `hermes` the agent starts from its terminal, which inherits the key and the depth,
 *   is another process and gets its own. Without the launcher (the desktop app, a gateway), the sha1 of the session id.
 * - key(): the run file (lib/SessionCounters.php): PIKKUMIES_RUN when set (every agent of the run counts its tokens
 *   there), else one derived from the spool hash, which a compression restarts with the session.
 * - record(): without the launcher, post_api_request adds the request's tokens to that derived file (with it,
 *   hooks/trace/session-context does, for every agent of the run); post_tool_call, and a deep-research skill turn,
 *   count memory and outside lookups (memory, memoryAt, outside, outsideAt), deterministically, so spool shedding
 *   never loses one.
 * Never throws; a counter it cannot write is a count lost, never a failed call.
 */
require_once __DIR__ . '/SessionCounters.php';
require_once __DIR__ . '/HookRecords.php';

class HookRunCount
{
    const RUN_ENV = 'PIKKUMIES_RUN';

    /** The launcher's run key, or '' when it is missing or not one. */
    public static function run()
    {
        $run = (string) getenv(self::RUN_ENV);
        return preg_match(SessionCounters::RUN_RE, $run) ? $run : '';
    }

    /** The spool hash for an event of $sessionId; $hermes is the Hermes process's identity (null: read it, hermes()). */
    public static function hash($sessionId, $hermes = null)
    {
        $run = self::run();
        if ($run === '') {
            return sha1((string) $sessionId);
        }
        $hermes = $hermes === null ? self::hermes() : (string) $hermes;
        return sha1('run:' . $run . ':' . (int) getenv('PIKKUMIES_DEPTH') . ($hermes === '' ? '' : ':' . $hermes));
    }

    /** The run key whose file counts for spool $hash. */
    public static function key($hash)
    {
        $run = self::run();
        return $run !== '' ? $run : substr(sha1('spool:' . $hash), 0, 16);
    }

    /** This process's parent as "<pid>:<start time in clock ticks>" from /proc, or '' when it cannot be read. */
    public static function hermes($proc = '/proc')
    {
        $self = self::stat("$proc/self/stat");
        $ppid = $self === null ? 0 : (int) $self[1];
        $parent = $ppid > 1 ? self::stat("$proc/$ppid/stat") : null;
        return $parent === null ? '' : $ppid . ':' . $parent[19];
    }

    /** The fields of a /proc stat file after the command name (state first, so field n of proc(5) is [n - 3]), or null. */
    private static function stat($file)
    {
        $s = (string) @file_get_contents($file);
        $at = strrpos($s, ')');
        $f = $at === false ? [] : preg_split('/\s+/', trim(substr($s, $at + 1)));
        return count($f) > 19 && ctype_digit($f[1]) && ctype_digit($f[19]) ? $f : null;
    }

    /** Count what $event says for spool $hash. */
    public static function record($localDir, $event, array $payload, $hash)
    {
        try {
            $key = self::key($hash);
            if ($key !== self::run() && SessionCounters::readRun($localDir, $key) === null) {
                SessionCounters::resetRun($localDir, $key); // no launcher: this spool's own counter starts now
            }
            if ($event === 'post_api_request' && $key !== self::run()) {
                $tokens = SessionCounters::requestTokens($payload);
                if ($tokens !== null) {
                    SessionCounters::updateRun($localDir, $key, ['tokens' => $tokens, 'reqs' => 1]);
                }
                return;
            }
            $kind = '';
            if ($event === 'post_tool_call') {
                $kind = self::lookup(isset($payload['tool_name']) ? (string) $payload['tool_name'] : '',
                    isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : []);
            } elseif ($event === 'pre_llm_call') {
                $kind = self::skillLookup($payload);
            }
            if ($kind !== '') {
                SessionCounters::updateRun($localDir, $key, [$kind => 1], [$kind . 'At' => time()]);
            }
        } catch (\Throwable $e) {
            // a count lost
        }
    }

    /** 'memory', 'outside' or '' for one tool call. */
    public static function lookup($tool, array $args)
    {
        $cmd = isset($args['command']) && is_string($args['command']) ? $args['command'] : '';
        $path = isset($args['path']) && is_string($args['path']) ? $args['path'] : '';
        if ($tool === 'session_search' || ($tool === 'terminal' && preg_match('#tools/memory/pikkumies/pikkumies\.php["\']?\s+search\b|tools/documentation/(kb/kb|wiki/wiki)\.php#', $cmd))
            || (in_array($tool, ['read_file', 'search_files'], true) && preg_match('#(^|/)memory/#', $path))) {
            return 'memory';
        }
        if (in_array($tool, ['web_search', 'web_extract', 'x_search', 'delegate_task'], true) || strpos($tool, 'browser') === 0
            || ($tool === 'skill_view' && isset($args['name']) && $args['name'] === 'deep-research')
            || ($tool === 'terminal' && (strpos($cmd, 'tools/system/browse/browse.php') !== false || preg_match('#\b(curl|wget)\b.*\bhttps?://#s', $cmd)))) {
            return 'outside';
        }
        return '';
    }

    /** 'outside' for the user's own deep-research skill turn, else ''. */
    private static function skillLookup(array $payload)
    {
        $msg = isset($payload['extra']['user_message']) ? HookRecords::text($payload['extra']['user_message']) : '';
        $skill = HookRecords::skillTurn($msg);
        return $skill !== null && $skill['skill'] === 'deep-research' ? 'outside' : '';
    }
}
