<?php
/**
 * SecretsUse: `secrets use <handle> --env NAME -- <command...>` (ADR-0085). The command gets the value in one
 * environment variable; the agent sees only the handle and the command's output with the value taken out. By default
 * the command must be a Pikkumies tool: `php <script>` where the script's realpath is a .php file under this
 * install's tools/ directory, run with this PHP. Any other command needs --any-command and the vault passphrase
 * typed at the user's terminal, because a terminal is no proof a person is there (the agent can open one, Hermes
 * pty=true, ADR-0053) but the passphrase is something the agent never has.
 */
class SecretsUse
{
    const OUT_MAX = 8000;
    const ERR_MAX = 3000;
    const ENV_RE = '/^[A-Z][A-Z0-9_]{0,63}\z/';
    /** Variables that steer how programs start or where they look; never set to a secret. */
    const ENV_DENY = ['PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'IFS', 'ENV', 'BASH_ENV', 'CDPATH', 'PS4', 'PROMPT_COMMAND', 'TMPDIR',
        'PHPRC', 'PHP_INI_SCAN_DIR', 'PERL5OPT', 'PERL5LIB', 'PYTHONPATH', 'PYTHONSTARTUP', 'PYTHONHOME', 'NODE_OPTIONS', 'RUBYOPT',
        'GIT_SSH', 'GIT_SSH_COMMAND', 'SSH_AUTH_SOCK', 'XDG_RUNTIME_DIR', 'TERM', 'LANG'];
    /** Removed from the child's environment (with LD_* and DYLD_*): they make PHP or a shell load code the tool did not choose. */
    const ENV_SCRUB = ['PHPRC', 'PHP_INI_SCAN_DIR', 'BASH_ENV', 'ENV', 'PERL5OPT', 'PERL5LIB', 'PYTHONPATH', 'PYTHONSTARTUP', 'PYTHONHOME',
        'NODE_OPTIONS', 'RUBYOPT'];

    /** Test seam: the tools directory a default command must be under. */
    public static $toolsDir = null;

    public static function envName($name)
    {
        $name = is_string($name) ? $name : '';
        if (!preg_match(self::ENV_RE, $name) || in_array($name, self::ENV_DENY, true)
            || preg_match('/^(LD_|DYLD_|HERMES_|PIKKUMIES_|LC_)/', $name)) {
            throw new CliError("--env must name one plain variable for the value, not $name",
                'upper-case letters, digits and _, e.g. --env API_KEY; never PATH, HOME, LD_*, PHPRC or another variable that changes how programs start');
        }
        return $name;
    }

    /** The argv to run: a Pikkumies tool (default) or, with $any, the command as given. Throws for anything else. */
    public static function argv(array $cmd, $any)
    {
        if (!$cmd) {
            throw new CliError('no command given after --', 'e.g. use mybox-pass --env PASS -- php ~/pikkumies/pikkumies/tools/<category>/<tool>/<tool>.php <verb>');
        }
        $cmd = array_values(array_map('strval', $cmd));
        if ($any) {
            $bin = strpos($cmd[0], '/') === false ? ToolProc::which($cmd[0]) : $cmd[0];
            if ($bin === null || !is_file($bin) || !is_executable($bin)) {
                throw new CliError("not a program found in PATH or by its path: {$cmd[0]}", 'give the full path of the program');
            }
            $cmd[0] = $bin;
            return $cmd;
        }
        $tools = realpath(self::$toolsDir !== null ? self::$toolsDir : Config::root() . '/tools');
        $script = isset($cmd[1]) ? self::expandHome($cmd[1]) : '';
        $real = $script !== '' ? realpath($script) : false;
        if (!in_array(basename($cmd[0]), ['php', basename(PHP_BINARY)], true) || $tools === false || $real === false || !is_file($real)
            || substr($real, -4) !== '.php' || strpos($real, $tools . '/') !== 0) {
            throw new CliError('use runs only a Pikkumies tool (php <script under ' . Config::shown((string) $tools) . '/>) unless --any-command is given; nothing was run',
                'run the tool that needs the secret, e.g. -- php ' . Config::shown((string) $tools) . '/<category>/<tool>/<tool>.php <verb>; '
                . 'another program needs --any-command and the user typing the vault passphrase at their own terminal');
        }
        return array_merge([PHP_BINARY, $real], array_slice($cmd, 2));
    }

    /** Confirms --any-command with the vault passphrase at the terminal; the key must equal the cached one. */
    public static function confirm(array $argv, $handle, $name, array $env, $key)
    {
        $shown = self::visible(ToolProc::line($argv));
        if (!SecretsInput::say("Run with secret $handle in \$$name:\n  $shown\n")) {
            throw new CliError('--any-command needs the user at a terminal to confirm, and there is none; nothing was run',
                'the user runs this command in their own terminal, or the agent uses a Pikkumies tool command instead');
        }
        $phrase = SecretsInput::phrase('Type the vault passphrase to allow this command (Enter to cancel): ');
        $typed = $phrase === '' ? '' : Secrets::deriveKey($phrase, $env['kdf']);
        sodium_memzero($phrase);
        $same = $typed !== '' && hash_equals($key, $typed);
        if ($typed !== '') {
            sodium_memzero($typed);
        }
        if (!$same) {
            throw new CliError('not confirmed (empty or wrong passphrase); nothing was run', 'run it again and type the vault passphrase to allow it');
        }
    }

    /** $s with control, C1, zero-width and bidi characters written as visible \xNN escapes, so a shown command cannot hide itself. */
    public static function visible($s)
    {
        return (string) preg_replace_callback('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]|\xE2\x80[\x8B-\x8F\xAA-\xAE]|\xE2\x81[\xA6-\xA9]|\xEF\xBB\xBF/',
            function ($m) {
                return '\\x' . strtoupper(bin2hex($m[0]));
            }, (string) $s);
    }

    /**
     * Runs $argv with the value in $name, set only for the child, and with the variables that make PHP or a shell load code
     * removed; returns the record. Stdout is redacted line by line as it arrives and only then cut to its tail; stderr,
     * which ToolProc keeps the first MAX_ERR bytes of, loses its last bytes when it was cut there, so a value cut in half at
     * that edge never shows.
     */
    public static function run(array $argv, $name, $value)
    {
        $env = [$name => $value];
        foreach (array_keys(getenv()) as $k) {
            if (in_array($k, self::ENV_SCRUB, true) || preg_match('/^(LD_|DYLD_)/', $k)) {
                $env[$k] = null;
            }
        }
        $tail = '';
        $bytes = 0;
        $r = ToolProc::run($argv, $env, function ($line) use (&$tail, &$bytes, $value) {
            $clean = SecretsUse::redact($line . "\n", $value);
            $bytes += strlen($clean);
            $tail .= $clean;
            if (strlen($tail) > 4 * SecretsUse::OUT_MAX) {
                $tail = substr($tail, -2 * SecretsUse::OUT_MAX);
            }
        });
        $out = self::redact($tail, $value); // a value with a newline in it spans lines
        $err = $r['err'];
        if (strlen($err) >= ToolProc::MAX_ERR) {
            $err = substr($err, 0, -self::longest($value)) . "\n[cut]";
        }
        $err = self::redact($err, $value);
        return ['exit_code' => $r['code'], 'stdout' => self::tail($out, self::OUT_MAX), 'stderr' => self::tail($err, self::ERR_MAX),
            'stdout_cut' => $bytes > self::OUT_MAX, 'stderr_cut' => strlen($err) > self::ERR_MAX];
    }

    /**
     * $text cleaned the way Cli prints it (control and zero-width characters removed), with every exact occurrence of
     * $secret and of its base64, base64url, hex, URL- and JSON-encoded forms replaced: once before the cleaning and once
     * after, also for the cleaned form of $secret, so the cleaning cannot put back together a value the first pass missed.
     */
    public static function redact($text, $secret)
    {
        $secret = (string) $secret;
        $map = self::forms($secret);
        $text = Cli::cleanString($map ? strtr((string) $text, $map) : (string) $text); // strtr tries the longest form first
        return $map ? strtr($text, $map + self::forms(Cli::cleanString($secret))) : $text;
    }

    /** form => '[REDACTED]' for $secret. */
    private static function forms($secret)
    {
        if ($secret === '') {
            return [];
        }
        $b64 = base64_encode($secret);
        $hex = bin2hex($secret);
        $json = substr((string) json_encode($secret, JSON_UNESCAPED_UNICODE), 1, -1);
        $map = [];
        foreach ([$secret, $b64, rtrim($b64, '='), rtrim(strtr($b64, '+/', '-_'), '='), strtr($b64, '+/', '-_'), $hex, strtoupper($hex),
            rawurlencode($secret), urlencode($secret), $json, (string) json_encode($secret)] as $f) {
            if ($f !== '' && $f !== '""') {
                $map[$f] = '[REDACTED]';
            }
        }
        return $map;
    }

    private static function longest($secret)
    {
        $forms = array_keys(self::forms((string) $secret));
        return $forms ? max(array_map('strlen', $forms)) + 1 : 1;
    }

    private static function tail($s, $max)
    {
        return strlen($s) > $max ? substr($s, -$max) : $s;
    }

    private static function expandHome($p)
    {
        return strncmp($p, '~/', 2) === 0 ? rtrim((string) getenv('HOME'), '/') . substr($p, 1) : $p;
    }
}
