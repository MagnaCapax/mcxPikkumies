<?php
/**
 * Cli: the shared command line of every tool unit (ADR-0016 JSON Lines, ADR-0017 --human).
 *
 * A tool ends with Cli::run($argv, ['verb' => callable, ...], $usage). A verb receives
 * (array $args, array $opt) and returns a list of records (arrays), or
 * ['records' => [...], 'status' => [...]] to add fields to the status line (e.g. 'verified').
 * A status `total` (an int, or null for "unknown") is the app's own count of the whole list, printed instead of the
 * number of records read when the output is truncated: a unit that reads one record more than it prints passes it.
 * It throws CliError for a failure the user can act on. Output: one JSON object per line and a
 * final {"ok":...} line; --human prints the same records for people. Exit 0 ok, 1 error, 2 usage.
 */
class CliError extends Exception
{
    public $hint;

    public function __construct($message, $hint = '')
    {
        parent::__construct($message);
        $this->hint = $hint;
    }
}

class Cli
{
    const BUDGET = 16384;   // bytes of records per call: well under Hermes' 50,000-byte terminal cap, whole records only
    const LIMIT = 20;       // records per call unless --limit says otherwise
    /** On/off options every tool takes. --yes is the confirmation a person types by hand (ADR-0020). */
    const GLOBAL_FLAGS = ['human', 'jsonl', 'help', 'yes', 'verbs'];
    /** Field names (lower-cased) that are never printed: rpc_password, webUiPassword, x-api-key, Authorization... */
    const SECRET_KEY_RE = '/(pass|passwd|password|passkey|secret|token|apikey|api[-_]key|cookie|credentials?|authorization|private[-_]?key)$|^(sid|auth)$/';

    /**
     * argv -> [verb|null, positional args, options]; accepts --name value, --name=value and --flag.
     * $flags: the tool's on/off options; they never take the next word (`remove --with-data <id>`
     * keeps the id). An option followed by a known verb name ($verbs) before any verb is a flag too,
     * so an unknown option cannot swallow the verb.
     */
    public static function parse(array $argv, array $verbs = [], array $flags = [])
    {
        $verb = null;
        $args = [];
        $opt = [];
        $flags = array_merge(self::GLOBAL_FLAGS, $flags);
        for ($i = 1; $i < count($argv); $i++) {
            $a = (string) $argv[$i];
            if ($a === '--') {
                $args = array_merge($args, array_slice($argv, $i + 1));
                break;
            }
            if (strncmp($a, '--', 2) === 0) {
                $eq = strpos($a, '=');
                if ($eq !== false) {
                    $opt[substr($a, 2, $eq - 2)] = substr($a, $eq + 1);
                } elseif (in_array(substr($a, 2), $flags, true) || !isset($argv[$i + 1]) || strncmp((string) $argv[$i + 1], '--', 2) === 0
                    || ($verb === null && in_array((string) $argv[$i + 1], $verbs, true))) {
                    $opt[substr($a, 2)] = true;
                } else {
                    $opt[substr($a, 2)] = (string) $argv[++$i];
                }
            } elseif ($verb === null) {
                $verb = $a;
            } else {
                $args[] = $a;
            }
        }
        return [$verb, $args, $opt];
    }

    /**
     * $flags: the tool's on/off options; $options: the ones that take a value. Any other option is a
     * usage error, so a mistyped or invented one (--dry-run) never runs the verb without it.
     */
    public static function run(array $argv, array $verbs, $usage, array $flags = [], array $options = [])
    {
        list($verb, $args, $opt) = self::parse($argv, array_keys($verbs), $flags);
        $human = !empty($opt['human']);
        $known = array_merge(self::GLOBAL_FLAGS, $flags, ['limit', 'offset', 'fields'], $options);
        $unknown = array_diff(array_keys($opt), $known);
        foreach (array_merge(self::GLOBAL_FLAGS, $flags) as $f) {
            if (isset($opt[$f]) && $opt[$f] !== true) {
                $unknown[] = "$f=" . $opt[$f]; // an on/off option takes no value: --with-data=no must not mean yes
            }
        }
        if ($unknown) {
            fwrite(STDERR, rtrim($usage) . "\n");
            self::fail('unknown option: --' . implode(', --', $unknown) . '; nothing was done', 'options: --' . implode(', --', $known), $human);
            exit(2);
        }
        if ($verb === null && !empty($opt['verbs'])) {
            echo implode("\n", array_keys($verbs)), "\n"; // for the contract test: code verbs == USAGE.md verbs
            exit(0);
        }
        if (!empty($opt['help']) && ($verb === null || isset($verbs[$verb]))) {
            echo rtrim($usage), "\n";
            exit(0);
        }
        if ($verb === null || !isset($verbs[$verb])) {
            fwrite(STDERR, rtrim($usage) . "\n");
            self::fail($verb === null ? 'no verb given' : "unknown verb: $verb", 'verbs: ' . implode(', ', array_keys($verbs)), $human);
            exit(2);
        }
        try {
            $result = call_user_func($verbs[$verb], $args, $opt);
            $records = isset($result['records']) ? $result['records'] : (array) $result;
            if ($records && array_keys($records) !== range(0, count($records) - 1)) {
                $records = [$records]; // one record returned on its own
            }
            $extra = isset($result['status']) ? (array) $result['status'] : [];
            self::emit($records, $opt, $human, $extra);
            exit(0);
        } catch (CliError $e) {
            self::fail($e->getMessage(), $e->hint, $human);
            exit(1);
        } catch (Throwable $e) {
            // A bug, not a user error: keep the status line, never print a stack trace (it can hold arguments).
            fwrite(STDERR, 'internal error: ' . get_class($e) . ': ' . self::cleanString($e->getMessage()) . "\n");
            self::fail('internal error (' . get_class($e) . ')', 'the tool has a bug; the message is on stderr', $human);
            exit(1);
        }
    }

    /** Print records within --limit and the byte budget, then the status line. */
    public static function emit(array $records, array $opt, $human, array $extra = [])
    {
        $limit = isset($opt['limit']) ? max(0, (int) $opt['limit']) : self::LIMIT;
        $fields = isset($opt['fields']) && is_string($opt['fields']) ? array_flip(explode(',', $opt['fields'])) : null;
        $offset = isset($opt['offset']) ? max(0, (int) $opt['offset']) : 0; // the next page: status next_offset
        $total = count($records);
        $records = array_slice(array_values($records), $offset);
        $shown = [];
        $bytes = 0;
        $truncated = false;
        foreach (array_values($records) as $n => $r) {
            $r = self::clean(is_array($r) ? $r : ['value' => $r]);
            if ($fields !== null) {
                $r = array_intersect_key($r, $fields);
            }
            $line = self::json($r);
            if ($line === '' || strlen($line) > self::BUDGET) {
                $r = ['record' => $offset + $n, 'error' => $line === ''
                    ? 'record could not be encoded as JSON (a NaN or Infinite number?)'
                    : 'record larger than ' . self::BUDGET . ' bytes; ask for fewer fields with --fields'];
                $line = self::json($r);
            }
            if (($limit > 0 && count($shown) >= $limit) || $bytes + strlen($line) > self::BUDGET) {
                $truncated = true;
                break;
            }
            $bytes += strlen($line) + 1;
            $shown[] = $r;
        }
        if (array_key_exists('total', $extra) && ($extra['total'] === null || is_int($extra['total']))) {
            $total = $extra['total'] === null ? null : max($extra['total'], $total); // never fewer than were read
        }
        $extra = self::clean($extra);
        unset($extra['ok'], $extra['count'], $extra['truncated'], $extra['total'], $extra['next_offset']); // the status line and exit code always agree
        $status = array_merge(['ok' => true, 'count' => count($shown)], $truncated ? ['truncated' => true, 'total' => $total, 'next_offset' => $offset + count($shown)] : [], $extra);
        if ($human) {
            echo CliHuman::render($shown), CliHuman::statusLine($status), "\n";
            return;
        }
        foreach ($shown as $r) {
            echo self::json($r), "\n";
        }
        echo self::json($status), "\n";
    }

    public static function fail($error, $hint, $human)
    {
        $error = self::cleanString((string) $error);
        $hint = self::cleanString((string) $hint);
        if ($human) {
            fwrite(STDERR, "error: $error\n" . ($hint !== '' ? "hint: $hint\n" : ''));
            return;
        }
        echo self::json(['ok' => false, 'error' => $error, 'hint' => $hint]), "\n";
    }

    public static function json(array $r)
    {
        return (string) json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Drop secret-named fields and clean every string, recursively. */
    public static function clean(array $r)
    {
        $out = [];
        foreach ($r as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEY_RE, strtolower($k))) {
                continue;
            }
            $out[$k] = is_array($v) ? self::clean($v) : (is_string($v) ? self::cleanString($v) : $v);
        }
        return $out;
    }

    /**
     * Valid UTF-8 without control, C1, bidi-override or zero-width characters (tab and newline kept),
     * and with secrets inside text removed: passkeys and tokens in URLs, user:password@ (ADR-0019).
     */
    public static function cleanString($s)
    {
        if (!preg_match('//u', $s)) {
            $s = (string) json_decode(json_encode($s, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', '', $s);
        $s = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', (string) $s);
        return (string) preg_replace(
            // Tracker keys come as query values (passkey=, pid=, uk=, torrent_pass=...) or path segments
            // (/<key>/announce, /announce/<key>, /tracker/<key>): any long token in a URL is removed.
            ['/([?&;][A-Za-z_]*(?:pass|key|token|auth|secret|sid|pid|uk)[A-Za-z_]*=)[^&\s"\'<>]+/i', '#(\w+://[^\s"\'<>]*?/)(?=[0-9A-Za-z]*[0-9])[0-9A-Za-z]{16,}(?=[/?\s"\'<>]|$)#', '#(://[^/:@\s]*:)[^/@\s]+@#'],
            ['$1REDACTED', '$1REDACTED', '$1REDACTED@'],
            $s
        );
    }
}
