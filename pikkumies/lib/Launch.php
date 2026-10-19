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
 * person's own, shows the last settings change and sets it back after they answer y (ADR-0059).
 *
 * First start (ADR-0032): on a terminal, `install` and the first interactive `run` ask for the
 * agent's name, and an interactive `run` asks the person to accept the terms and privacy policy.
 * Without a terminal nothing is asked and nothing is blocked; install flags answer every question.
 */
class Launch
{
    /** Subcommands bin/pikkumies dispatches on its own. */
    const SUBCOMMANDS = ['install', 'update', 'post-update', 'detect', 'doctor', 'generate', 'run', 'cron-tick', 'docs-fill', 'tmux', 'configure'];
    const HELP = ['help', '--help', '-h'];
    /** `pikkumies configure` on a terminal: a session with the configure skill loaded and this first prompt (ADR-0052). */
    const CONFIGURE_ARGS = ['chat', '-s', 'pikkumies-configure', '--query=Review my setup and help me configure Pikkumies'];
    const TMUX_SESSION = 'pikkumies';
    const TERMS_URL = 'https://pulsedmedia.com/terms.php';
    const PRIVACY_URL = 'https://wiki.pulsedmedia.com/index.php/Pulsed_Media_Privacy_Policy';
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
        // `configure undo`: the person's own undo of the last settings change, no session (ADR-0059); other words after configure are a prompt.
        if ($argv[1] === 'configure' && $rest === ['undo']) {
            return ['configure', $rest];
        }
        // `run [hermes args]` is a documented passthrough (README, ADR-0033) and keeps every word.
        if ($argv[1] === 'run' || (in_array($argv[1], self::SUBCOMMANDS, true) && self::allFlags($rest))) {
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
     * (switch-client when already inside tmux). Window pmN of session "pikkumies" runs $cmd, made
     * when missing; named windows, so tmux's base-index and other windows never matter. $windows: the
     * session's window names, or null when the session does not exist. $statusCmd, when given, is a shell command
     * whose output leads the session's status-right, refreshed every minute: the credits line (ADR-0040). Set on
     * this session only, never with -g, so the person's other tmux sessions keep their own status line.
     */
    public static function tmuxPlan($n, $windows, $insideTmux, $cmd, $statusCmd = '')
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
            // tmux expands formats (#) and strftime (%) inside #() too: doubled, a path holding them still runs.
            $plan[] = ['tmux', 'set-option', '-t', $s . ':', 'status-right', '#(' . strtr($statusCmd, ['%' => '%%', '#' => '##']) . ') %H:%M'];
            $plan[] = ['tmux', 'set-option', '-t', $s . ':', 'status-right-length', '90'];
            $plan[] = ['tmux', 'set-option', '-t', $s . ':', 'status-interval', '60'];
        }
        $plan[] = ['tmux', 'select-window', '-t', "$s:$w"];
        $plan[] = $insideTmux ? ['tmux', 'switch-client', '-t', $s] : ['tmux', 'attach-session', '-t', $s];
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

    /**
     * Ask the agent's name until it is valid; an empty answer keeps "Pikkumies". Returns the name, or
     * null when input ended. $read() returns a line or false; $write($text) prints.
     */
    public static function askName(callable $read, callable $write)
    {
        while (true) {
            $write("Name your Pikkumies agent (Enter keeps \"Pikkumies\"): ");
            $line = $read();
            if ($line === false) {
                return null;
            }
            $line = trim($line);
            if ($line === '') {
                return 'Pikkumies';
            }
            if (Config::validName($line)) {
                return $line;
            }
            $write('1-' . Config::NAME_MAX . " letters, digits, spaces, dots, dashes or underscores, starting with a letter or digit.\n");
        }
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

    /** After the first start's questions: the settings review's warnings, if any, and where to change things (ADR-0052). */
    public static function setupHint(array $review)
    {
        $text = '';
        foreach ($review as $r) {
            if (isset($r['warning'])) {
                $text .= '  ' . $r['key'] . ': ' . $r['warning'] . "\n";
            }
        }
        return $text . "Run `pikkumies configure` or ask me to review your setup.\n";
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
