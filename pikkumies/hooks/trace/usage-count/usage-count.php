<?php
/**
 * Hook unit (post_tool_call, run by hooks/orchestrator.php, ADR-0030): counts tool, skill and
 * Pikkumies-unit-verb invocations into the local telemetry store (TelemetryLog, ADR-0086), so "how
 * many times was X used" can be answered without Hermes' own insights database (`/insights` reads
 * `state.db`, which PHP cannot open on every host and whose schema Pikkumies does not own, ADR-0061).
 * See docs/adr/0087-tool-skill-and-verb-usage-counts-kept-on-this-machine.md.
 *
 * Every tool call writes one line to source `usage-tool`, field = the Hermes tool name (`skill_view`,
 * `terminal`, `read_file`, ...), value 1 (succeeded) or 0 (failed). A `skill_view` call that names a
 * skill also writes source `usage-skill`, field = the skill name. A `terminal` call whose command
 * runs a `*.php` script of THIS Pikkumies install's own `tools/` tree also writes source
 * `usage-u-<tool>`, field = the verb -- only when it is one of that unit's own verbs, from its
 * USAGE.md verbs table (security review F-TEL/F1), never a bare word that merely came first (a
 * password, a path, a username): everything else recorded here becomes `other`. The `php` call
 * must start the command or follow `&&`, `;` or `|` (F-TEL/F4d: a command that only *mentions* a
 * unit, such as `echo 'php ... show'`, is never counted). The path must realpath() under
 * `Units::toolsDir()`, resolving a relative path against the tool call's own `cwd` from the payload
 * (never this hook's own, F-TEL/F2) and a leading `~/` against this process's `HOME` (the same
 * account the call itself runs as); with no `cwd` on the payload, a relative path is never matched,
 * so a look-alike elsewhere is never guessed into the real unit. Names are lowercased, "-" and "/" become "_"; a name that still fails
 * `TelemetryLog::FIELD_RE` (over 32 characters, an MCP or plugin computed name, an odd skill name)
 * is recorded as `other`. These are counter-style writes (TelemetryLog::record's `$counter` flag):
 * many small writes a minute is what this hook is for, not abuse, and this hook is never reachable
 * through a remote `serve` peer, so the per-minute cap built for that (F9c) does not apply here; the
 * per-file size cap still does.
 *
 * Prints nothing: post_tool_call is an observer. Respects `privacy.perfLogging` through
 * `TelemetryLog::record` itself (off: nothing is written, nothing here is an error about it; never
 * `privacy.telemetry`, which stays unread). Each of the up to three writes is its own try/catch ->
 * `TelemetryLog::noteFailure(that source)`: one source's failure never stops the others, and none of
 * them can fail the tool call being counted.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/Units.php';
require_once dirname(__DIR__, 3) . '/tools/system/telemetry/TelemetryLog.php';
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

/** lowercase, "-"/"/" -> "_", else "other" (TelemetryLog::FIELD_RE); never empty, never the reserved "ts". */
function usageCountField($name)
{
    $n = strtolower(str_replace(['-', '/'], '_', (string) $name));
    return $n !== '' && $n !== 'ts' && preg_match(TelemetryLog::FIELD_RE, $n) ? $n : 'other';
}

/** The decoded `extra.result` of a post_tool_call payload (a JSON string, or already an array), or null. */
function usageCountResult(array $p)
{
    $result = isset($p['extra']['result']) ? $p['extra']['result'] : null;
    $r = is_string($result) ? json_decode($result, true) : $result;
    return is_array($r) ? $r : null;
}

/** 1 (succeeded) or 0 (failed): a non-empty `error`, or for `terminal` a non-zero `exit_code`. */
function usageCountOk($tool, $r)
{
    if (is_array($r) && !empty($r['error'])) {
        return 0;
    }
    if ($tool === 'terminal' && is_array($r) && isset($r['exit_code']) && is_numeric($r['exit_code']) && (int) $r['exit_code'] !== 0) {
        return 0;
    }
    return 1;
}

/**
 * [tool, verb] when a segment of $command -- split on `&&`, `;` and `|`, so only a real `php` call
 * is read, never text a command merely echoes or quotes (security review F-TEL/F4d) -- starts with
 * `php` and runs a `*.php` unit script of this install's own `tools/` tree (every unit follows
 * `<tool>/<tool>.php`), else null. The path is resolved: absolute as given; a leading `~/` against
 * this process's own HOME; otherwise (a plain relative path) against $cwd, the tool call's own
 * working directory (never this hook's, F-TEL/F2) -- with no $cwd, a relative path is not matched
 * at all, rather than guessed against the wrong directory. realpath() must still land under
 * Units::toolsDir(); a look-alike path elsewhere is never matched. $verb is the first lowercase
 * word after the path in that segment, kept only when it is one of the unit's own verbs (its
 * USAGE.md verbs table, Units::verbs(), F-TEL/F1) -- anything else becomes "other", never the word
 * itself, and no word at all leaves this call to the generic `usage-tool` line only.
 */
function usageCountUnit($command, $cwd)
{
    foreach (preg_split('/&&|;|\|/', (string) $command) as $segment) {
        $segment = ltrim($segment);
        if (!preg_match('/^php(?:[0-9.]*)\s+(?:-\S+\s+)*(["\']?)(\S+\.php)\1(?:\s+([a-z][a-z0-9-]*))?/', $segment, $m)) {
            continue;
        }
        $path = $m[2];
        if ($path === '') {
            continue;
        } elseif (strncmp($path, '~/', 2) === 0) {
            $path = rtrim((string) getenv('HOME'), '/') . '/' . substr($path, 2);
        } elseif ($path[0] !== '/') {
            if (!is_string($cwd) || $cwd === '') {
                continue; // no known cwd for this call: never guess at a relative path (F-TEL/F2)
            }
            $path = rtrim($cwd, '/') . '/' . $path;
        }
        $real = @realpath($path);
        $toolsDir = @realpath(Units::toolsDir());
        if ($real === false || $toolsDir === false || strpos($real . '/', rtrim($toolsDir, '/') . '/') !== 0) {
            continue;
        }
        $tool = PathName::base($real, '.php');
        if (PathName::base(dirname($real)) !== $tool) {
            continue; // not <tool>/<tool>.php, the one shape every unit script follows
        }
        if (!isset($m[3]) || $m[3] === '') {
            return null; // nothing named after the path: nothing to attribute, not even "other"
        }
        $usage = dirname($real) . '/USAGE.md';
        $verbs = is_file($usage) ? array_column(Units::verbs((string) file_get_contents($usage)), 'verb') : [];
        return [$tool, in_array($m[3], $verbs, true) ? $m[3] : 'other'];
    }
    return null;
}

/** Writes one field => $value into $source as a counter (never rate-limited, F9c note above); never throws. */
function usageCountRecord($source, $field, $value)
{
    try {
        TelemetryLog::record($source, [$field => $value], null, null, true);
    } catch (Throwable $e) {
        TelemetryLog::noteFailure($source);
    }
}

if (realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $payload = json_decode((string) stream_get_contents(STDIN), true);
    if (is_array($payload) && isset($payload['tool_name']) && is_string($payload['tool_name']) && $payload['tool_name'] !== '') {
        $tool = $payload['tool_name'];
        $in = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
        $ok = usageCountOk($tool, usageCountResult($payload));
        usageCountRecord('usage-tool', usageCountField($tool), $ok);
        if ($tool === 'skill_view' && isset($in['name']) && is_string($in['name']) && $in['name'] !== '') {
            usageCountRecord('usage-skill', usageCountField($in['name']), $ok);
        }
        if ($tool === 'terminal' && isset($in['command']) && is_string($in['command'])) {
            $cwd = isset($payload['cwd']) && is_string($payload['cwd']) ? $payload['cwd'] : null;
            $unit = usageCountUnit($in['command'], $cwd);
            if ($unit !== null) {
                list($unitTool, $verb) = $unit;
                $source = 'usage-u-' . $unitTool;
                if (preg_match(TelemetryLog::SOURCE_RE, $source)) {
                    usageCountRecord($source, usageCountField($verb), $ok);
                }
            }
        }
    }
}
