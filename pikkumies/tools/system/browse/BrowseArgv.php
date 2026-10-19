<?php
/**
 * BrowseArgv: what the agent-browser wrapper runs for one call, decided from the argv (and a batch's stdin) alone.
 *
 * - Every call is pinned to one session (BrowsePaths::SESSION), so it reaches the daemon the guard is attached to.
 *   Hermes names a session per task (tools/browser_tool_session.py, "--session <name>"); left as is, a new name
 *   would make agent-browser spawn a second daemon whose Chrome the guard never sees.
 * - Options that attach to, launch or configure another browser (--cdp, --auto-connect, --provider, --profile,
 *   --executable-path, --args, --extension, --proxy, --config, ...) and the commands that leave the guarded browser
 *   or the machine (connect, chat, dashboard, install, upgrade) are refused. An unknown option before the command is
 *   refused too: the allowed ones are exactly what Hermes sends (--session, --json, --headed, --engine chrome).
 * - `tab new <url>` navigates before the guard attaches to the new tab (verified against 0.26.0), so it becomes
 *   `tab new` then `open <url>`. Each open's URL is handed to the guard as the intent first.
 * - `close` is answered without closing: the daemon is shared by every session of this account, Hermes closes its
 *   session after each task, and the watcher stops the browser after 15 idle minutes anyway.
 * - --version and --help run the binary directly: they start no daemon.
 * Returns ['refuse' => text] or ['mode' => direct|close|run, 'json' => bool, 'calls' => [['argv' => [...],
 * 'stdin' => string|null, 'intent' => url|null], ...]].
 */
class BrowseArgv
{
    const DENIED_FLAGS = ['--cdp', '--auto-connect', '-p', '--provider', '--profile', '--executable-path', '--args', '--extension', '--proxy',
        '--proxy-bypass', '--config', '--allow-file-access', '--state', '--session-name', '--device', '--allowed-domains', '--action-policy'];
    const DENIED_COMMANDS = ['connect', 'chat', 'dashboard', 'install', 'upgrade'];
    const DIRECT = ['--version', '-V', '--help', '-h', 'help'];

    public static function plan(array $args, $stdin = null)
    {
        if (!$args || (count($args) === 1 && in_array($args[0], self::DIRECT, true))) {
            return ['mode' => 'direct', 'json' => false, 'calls' => [['argv' => $args, 'stdin' => null, 'intent' => null]]];
        }
        $globals = [];
        $json = false;
        $i = 0;
        for (; $i < count($args) && strncmp($args[$i], '-', 1) === 0; $i++) {
            $a = $args[$i];
            $name = strpos($a, '=') !== false ? substr($a, 0, strpos($a, '=')) : $a;
            $value = strpos($a, '=') !== false ? substr($a, strpos($a, '=') + 1) : (isset($args[$i + 1]) ? $args[$i + 1] : null);
            $inline = strpos($a, '=') !== false;
            if ($name === '--json') {
                $json = true;
            } elseif ($name === '--session') {
                $i += $inline ? 0 : 1; // replaced by the one pinned session
            } elseif ($name === '--engine') {
                if ($value !== 'chrome') {
                    return self::refuse("--engine $value: only the installed Chromium runs here");
                }
                $i += $inline ? 0 : 1;
            } elseif ($name === '--headed') {
                $i += !$inline && in_array($value, ['true', 'false'], true) ? 1 : 0; // no display on a server: always headless
            } else {
                return self::refuse(in_array($name, self::DENIED_FLAGS, true) ? "$name would leave the isolated browser" : "$name is not passed to the isolated browser");
            }
        }
        $words = array_slice($args, $i);
        if (!$words) {
            return self::refuse('no command given');
        }
        $why = self::deniedIn($words);
        if ($why !== '') {
            return self::refuse($why);
        }
        $head = array_merge(['--session', BrowsePaths::SESSION], $json ? ['--json'] : []);
        if ($words[0] === 'close') {
            return ['mode' => 'close', 'json' => $json, 'calls' => []];
        }
        if ($words[0] === 'tab' && isset($words[1], $words[2]) && $words[1] === 'new') {
            return ['mode' => 'run', 'json' => $json, 'calls' => [
                ['argv' => array_merge($head, ['tab', 'new']), 'stdin' => null, 'intent' => null],
                ['argv' => array_merge($head, ['open'], array_slice($words, 2)), 'stdin' => null, 'intent' => $words[2]],
            ]];
        }
        if ($words[0] === 'batch') {
            return self::batch($head, $words, $json, $stdin);
        }
        $intent = $words[0] === 'open' && isset($words[1]) ? $words[1] : null;
        return ['mode' => 'run', 'json' => $json, 'calls' => [['argv' => array_merge($head, $words), 'stdin' => null, 'intent' => $intent]]];
    }

    /** '' or why: a denied command first, or a denied option anywhere in $words (also as --name=value). */
    public static function deniedIn(array $words)
    {
        if (in_array($words[0], self::DENIED_COMMANDS, true)) {
            return "`{$words[0]}` would leave the isolated browser";
        }
        foreach ($words as $w) {
            $name = strpos($w, '=') !== false ? substr($w, 0, strpos($w, '=')) : $w;
            if (in_array($name, self::DENIED_FLAGS, true)) {
                return "$name would leave the isolated browser";
            }
        }
        return '';
    }

    /**
     * A batch: commands as JSON on stdin ([["open", "https://..."], ["snapshot"]], how Hermes sends a command whose
     * arguments hold a newline or %), or as words after `batch`, which agent-browser splits itself; words are only
     * checked, a `tab new <url>` among them is refused (it cannot be split safely). The intent is the first open's URL.
     */
    private static function batch(array $head, array $words, $json, $stdin)
    {
        $flags = array_values(array_filter(array_slice($words, 1), function ($w) {
            return $w === '--bail';
        }));
        $cmds = array_values(array_diff(array_slice($words, 1), ['--bail']));
        if ($cmds) {
            foreach ($cmds as $c) {
                $split = preg_split('/\s+/', trim($c));
                if (self::deniedIn($split) !== '' || ($split[0] === 'tab' && isset($split[1], $split[2]) && $split[1] === 'new')) {
                    return self::refuse(self::deniedIn($split) ?: 'tab new <url> inside a batch: run tab new, then open <url>');
                }
            }
            return ['mode' => 'run', 'json' => $json, 'calls' => [['argv' => array_merge($head, $words), 'stdin' => null, 'intent' => null]]];
        }
        $list = json_decode((string) $stdin, true);
        if (!is_array($list)) {
            return self::refuse('batch: stdin is not a JSON list of commands');
        }
        $out = [];
        $intent = null;
        foreach ($list as $cmd) {
            if (!is_array($cmd) || !$cmd || $cmd !== array_values(array_filter($cmd, 'is_string'))) {
                return self::refuse('batch: every command must be a list of strings');
            }
            $why = self::deniedIn($cmd);
            if ($why !== '' || $cmd[0] === 'close') {
                return self::refuse($why !== '' ? $why : '`close` inside a batch: the shared browser stops itself when idle');
            }
            if ($cmd[0] === 'tab' && isset($cmd[1], $cmd[2]) && $cmd[1] === 'new') {
                $out[] = ['tab', 'new'];
                $cmd = array_merge(['open'], array_slice($cmd, 2));
            }
            if ($intent === null && $cmd[0] === 'open' && isset($cmd[1])) {
                $intent = $cmd[1];
            }
            $out[] = $cmd;
        }
        return ['mode' => 'run', 'json' => $json, 'calls' => [['argv' => array_merge($head, ['batch'], $flags),
            'stdin' => json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'intent' => $intent]]];
    }

    private static function refuse($why)
    {
        return ['refuse' => $why];
    }
}
