<?php
/**
 * Launch: argv -> [subcommand, hermes passthrough args] for bin/pikkumies (ADR-0022).
 *
 * The user-facing command is `pikkumies`. No arguments, or `run` with no further words, starts a
 * plain interactive session. Anything else that is not one of the known subcommands is free text:
 * it is sent to Hermes as `chat --query="<text>"`, which seeds an interactive session on a real TTY and
 * answers-and-exits otherwise (Hermes' own behaviour, not ours). `run [hermes args]` is the
 * documented Hermes passthrough and keeps all its words. Any other subcommand wins only when every
 * word after it is a flag (`pikkumies detect --refresh`): a prompt such as "update my plex" stays a
 * prompt instead of starting a self-update. `pikkumies -- <text>` always forces a prompt.
 * `pikkumies tmux [N]` opens window pmN of the tmux session "pikkumies" (ADR-0032).
 * Leading `-m <model>` / `--reasoning <level>` / `--resume <id>` / `--prompt-file <file|->` pairs set them for one session (RunFlags, ADR-0052);
 * `pikkumies configure` reviews the settings, with the agent on a terminal (ADR-0052). `pikkumies configure undo`, the
 * person's own, sets the last settings change back, asking y/N first only for a change in the asked tier (ADR-0059).
 *
 * First start (ADR-0032): an interactive `run` on a terminal asks the person to accept the terms and privacy policy;
 * nothing else is asked, the agent's name included (it is Pikkumies until renamed in chat or with configure). Without a
 * terminal nothing is asked and nothing is blocked; install flags answer every question.
 */
class Launch
{
    /** Subcommands bin/pikkumies dispatches on its own. */
    const SUBCOMMANDS = ['install', 'update', 'post-update', 'detect', 'doctor', 'generate', 'run', 'cron-tick', 'docs-fill', 'tmux', 'browse', 'access', 'websearch', 'configure', 'status-line', 'eternal', 'ask', 'ui'];
    const HELP = ['help', '--help', '-h'];
    /** `pikkumies configure` on a terminal: a session with the configure skill loaded and this first prompt (ADR-0052). */
    const CONFIGURE_ARGS = ['chat', '-s', 'pikkumies-configure', '--query=Review my setup and help me configure Pikkumies'];
    const TMUX_SESSION = 'pikkumies';
    const TERMS_URL = 'https://pulsedmedia.com/terms.php';
    const PRIVACY_URL = 'https://wiki.pulsedmedia.com/index.php/Pulsed_Media_Privacy_Policy';
    /** The first start's last line: what the agent does, as a statement. */
    const READY = "Ready. Pikkumies works from plain words: it checks disk space, fixes stuck apps, adds torrents, looks after containers and backups, researches and explains, and changes its own settings, its name too, when told to.\n";
    /** install flag => the setting it writes (Config::SETTABLE); --accept-terms is separate. */
    const INSTALL_FLAGS = ['name' => 'agent.name', 'telemetry' => 'privacy.telemetry', 'perf-logging' => 'privacy.perfLogging', 'retention-days' => 'sessions.retentionDays', 'channel' => 'update.channel'];

    /**
     * [cmd, passArgs]: cmd is one of SUBCOMMANDS, 'help', or 'error' with passArgs [one line for the person]. For 'run',
     * passArgs is [] for a plain interactive session, or ['chat', (RunFlags::peel's args,) '--query=text']. $argv is the
     * script's own argv (index 0 is the script path, same shape PHP gives bin/pikkumies).
     */
    public static function route(array $argv)
    {
        if (!isset($argv[1])) {
            return ['run', []];
        }
        if (in_array($argv[1], self::HELP, true)) {
            return ['help', []];
        }
        if ($argv[1] === '--') {
            return ['run', self::promptArgs(array_slice($argv, 2))];
        }
        // Leading RunFlags pairs: everything after them is prompt text, a subcommand name too; `--` may end them. With a prompt file, no words.
        if (RunFlags::isFlag($argv[1])) {
            list($flags, $words, $error) = RunFlags::peel(array_slice($argv, 1));
            if ($error !== '') {
                return ['error', [$error]];
            }
            $words = isset($words[0]) && $words[0] === '--' ? array_slice($words, 1) : $words;
            return ['run', array_merge(['chat'], $flags, array_slice(self::promptArgs($words), 1))];
        }
        $rest = array_slice($argv, 2);
        // `tmux` or `tmux <number>`; "tmux kill my session" is a prompt.
        if ($argv[1] === 'tmux') {
            return $rest === [] || (count($rest) === 1 && ctype_digit($rest[0])) ? ['tmux', $rest] : ['run', self::promptArgs(array_slice($argv, 1))];
        }
        // `browse up|down|status`: the isolated browser (tools/system/browse); "browse the web for X" is a prompt.
        if ($argv[1] === 'browse') {
            return count($rest) === 1 && in_array($rest[0], ['up', 'down', 'status'], true) ? ['browse', $rest] : ['run', self::promptArgs(array_slice($argv, 1))];
        }
        // `access status|rotate|remove`: the agent's own panel login (PanelAccess, ADR-0079); "access my files" is a prompt.
        if ($argv[1] === 'access') {
            return count($rest) === 1 && in_array($rest[0], ['status', 'rotate', 'remove'], true) ? ['access', $rest] : ['run', self::promptArgs(array_slice($argv, 1))];
        }
        // `websearch on|off|status`: the person's switch for web search (WebSearch, ADR-0082); "websearch cats" is a prompt.
        if ($argv[1] === 'websearch') {
            return count($rest) === 1 && in_array($rest[0], ['on', 'off', 'status'], true) ? ['websearch', $rest] : ['run', self::promptArgs(array_slice($argv, 1))];
        }
        if ($argv[1] === 'ask' && ($rest === [] || (count($rest) === 1 && strncmp($rest[0], '--nonce=', 8) === 0 && strlen($rest[0]) > 8))) {
            return ['ask', $rest];
        }
        if ($argv[1] === 'ui' && $rest === ['start']) {
            return ['ui', $rest];
        }
        if ($argv[1] === 'eternal' && (in_array($rest, [[], ['list'], ['list', '--json'], ['ensure'], ['on'], ['off'], ['new']], true)
            || (count($rest) === 2 && $rest[0] === 'quit' && preg_match('/^pm[1-9]$/D', $rest[1]) === 1)
            || (count($rest) === 3 && $rest[0] === 'hold' && $rest[1] === '--until' && ctype_digit($rest[2])))) {
            return ['eternal', $rest];
        }
        // `configure undo`: the person's own undo of the last settings change, no session (ADR-0059); other words after configure are a prompt.
        if ($argv[1] === 'configure' && $rest === ['undo']) {
            return ['configure', $rest];
        }
        // `run [hermes args]` is a documented passthrough (README, ADR-0033) and keeps every word.
        if ($argv[1] === 'run' || (in_array($argv[1], self::SUBCOMMANDS, true)
            && !in_array($argv[1], ['ask', 'ui', 'eternal'], true) && self::allFlags($rest))) {
            return [$argv[1], $rest];
        }
        return ['run', self::promptArgs(array_slice($argv, 1))];
    }

    /** True when every word is a flag (starts with "-"); an empty list counts as all flags. */
    public static function allFlags(array $words)
    {
        foreach ($words as $w) {
            if ($w === '' || $w[0] !== '-') {
                return false;
            }
        }
        return true;
    }

    /**
     * $words joined with a single space -> [] (nothing to say) or ['chat', '--query=text']. One token,
     * so a prompt that starts with "-v" or "-m" is never read by Hermes' parser as one of its flags.
     */
    public static function promptArgs(array $words)
    {
        $text = trim(implode(' ', $words));
        return $text === '' ? [] : ['chat', '--query=' . $text];
    }

    /**
     * tmux commands for `pikkumies tmux N`, as argv lists to run in order; the last one attaches
     * in a new client when already inside tmux. Window pmN of session "pikkumies" runs $cmd, made
     * when missing; named windows, so tmux's base-index and other windows never matter. $windows: the
     * session's window names, or null when the session does not exist. $statusCmd, when given, is a shell command
     * whose output leads the session's status-right, refreshed every minute: the credits line (ADR-0040). Set on
     * this session only, never with -g, so the person's other tmux sessions keep their own status line. $paneCmd, when given,
     * is run before it with `--pane=#{pane_id}` added: the memories and the active pane's counters (ADR-0061). tmux cuts
     * status-right from the left when the terminal is too narrow, so the credits line and the clock stay longest. With a
     * status command the status line is also pinned to the bottom row, below the prompt, whatever the person's tmux.conf
     * says, and coloured with StatusTheme unless $colour is false (NO_COLOR) (ADR-0061, 2026-10-19 section).
     */
    public static function tmuxPlan($n, $windows, $insideTmux, $cmd, $statusCmd = '', $paneCmd = '', $colour = true)
    {
        $s = '=' . self::TMUX_SESSION;
        $w = 'pm' . $n;
        $plan = [];
        if ($windows === null) {
            $plan[] = ['tmux', 'new-session', '-d', '-s', self::TMUX_SESSION, '-n', $w, $cmd];
        } elseif (!in_array($w, $windows, true)) {
            $plan[] = ['tmux', 'new-window', '-d', '-t', $s . ':', '-n', $w, $cmd];
        }
        if ($statusCmd !== '') {
            // tmux expands formats (#) and strftime (%) inside #() too: doubled, a path holding them still runs; #{pane_id} is
            // added after the doubling, so tmux puts the active pane's id there.
            $double = ['%' => '%%', '#' => '##'];
            $pane = $paneCmd === '' ? '' : '#(' . strtr($paneCmd, $double) . ' --pane=#{pane_id}) ';
            $plan[] = ['tmux', 'set-option', '-t', $s . ':', 'status-right', $pane . '#(' . strtr($statusCmd, $double) . ') %H:%M'];
            $plan[] = ['tmux', 'set-option', '-t', $s . ':', 'status-right-length', $paneCmd === '' ? '90' : '150'];
            $plan[] = ['tmux', 'set-option', '-t', $s . ':', 'status-interval', '60'];
            $plan[] = ['tmux', 'set-option', '-t', $s . ':', 'status-position', 'bottom'];
            if ($colour) {
                $plan[] = ['tmux', 'set-option', '-t', $s . ':', 'status-style', StatusTheme::tmuxStyle()];
            }
        }
        $plan[] = ['tmux', 'select-window', '-t', "$s:$w"];
        $plan[] = ['tmux', 'attach-session', '-t', $s];
        return $plan;
    }

    /** True when STDIN is a terminal: only then may Pikkumies ask anything. */
    public static function isTty($stream = null)
    {
        $stream = $stream ?: (defined('STDIN') ? STDIN : null);
        if (!is_resource($stream)) {
            return false;
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream);
        }
        return function_exists('posix_isatty') && @posix_isatty($stream);
    }

    /** True for a session someone types into: plain `run` or `run chat ...`; `run sessions list` is not one. */
    public static function isSession(array $passArgs)
    {
        return $passArgs === [] || $passArgs[0] === 'chat';
    }

    /**
     * Why a session cannot start for lack of a model key, or '' when it can: without one Hermes would start and fail
     * on its first request with the provider's own error. Names where a key comes from (ADR-0004).
     */
    public static function missingKeyMessage(array $prov)
    {
        if ($prov['key'] !== '') {
            return '';
        }
        return 'Not starting: no model key yet. A platform key arrives in ~/.virsilipasKey (on Pulsed Media it is also shown in the client area);'
            . ' for your own provider put endpoint= and key= lines in ~/.pikkumiesKey. Then run `pikkumies doctor`.';
    }

    /**
     * install flags -> [settings [key => typed value], acceptTerms, errors]. Only --name=, --telemetry=,
     * --perf-logging=, --retention-days=, --channel= and --accept-terms; anything else is an error, so a mistyped
     * flag never installs without the setting the person meant.
     */
    public static function installFlags(array $flags)
    {
        $settings = [];
        $terms = false;
        $errors = [];
        foreach ($flags as $f) {
            if ($f === '--accept-terms') {
                $terms = true;
                continue;
            }
            if (!preg_match('/^--([a-z-]+)=(.*)$/s', $f, $m) || !isset(self::INSTALL_FLAGS[$m[1]])) {
                $errors[] = "unknown flag $f (flags: --name=, --accept-terms, --telemetry=on|off, --perf-logging=on|off, --retention-days=N, --channel=release|git/<branch>)";
                continue;
            }
            $key = self::INSTALL_FLAGS[$m[1]];
            try {
                $settings[$key] = Config::parse($key, $m[2]);
            } catch (InvalidArgumentException $x) {
                $errors[] = '--' . $m[1] . ': ' . $x->getMessage();
            }
        }
        return [$settings, $terms, $errors];
    }

    /** The privacy settings as shown at the first interactive start (AGENTS.md privacy rule, ADR-0032). */
    public static function settingsNote(array $config)
    {
        $onOff = function ($v) {
            return $v ? 'on' : 'off';
        };
        $days = (int) $config['sessions']['retentionDays'];
        return 'Settings: performance telemetry ' . $onOff(!empty($config['privacy']['telemetry'])) . ' (a setting only: nothing is sent), performance logging ' . $onOff(!empty($config['privacy']['perfLogging']))
            . ' (when on, it keeps on this machine only, never sent: the words of memory searches, result counts and lesson saves)'
            . ', session history kept ' . ($days > 0 ? "$days days" : 'forever')
            . ". Change them with the pikkumies-configure skill (ask your agent) or `pikkumies install --telemetry=off` and the other flags.\n";
    }

    /** After the terms are accepted: the settings review's warnings, if any, and what the agent does (ADR-0052); a statement, never an invitation. */
    public static function setupHint(array $review)
    {
        $text = '';
        foreach ($review as $r) {
            if (isset($r['warning'])) {
                $text .= '  ' . $r['key'] . ': ' . $r['warning'] . "\n";
            }
        }
        return $text . self::READY;
    }

    /** Show the terms and privacy policy and ask until yes or no; true only for yes. End of input is a no. */
    public static function askTerms(callable $read, callable $write)
    {
        $write("Before the first session, please read and accept:\n  Terms of service: " . self::TERMS_URL . "\n  Privacy policy:   " . self::PRIVACY_URL . "\n");
        while (true) {
            $write('Do you accept them? [yes/no]: ');
            $line = $read();
            if ($line === false) {
                return false;
            }
            $a = strtolower(trim($line));
            if (in_array($a, ['yes', 'y'], true)) {
                return true;
            }
            if (in_array($a, ['no', 'n'], true)) {
                return false;
            }
        }
    }
}
