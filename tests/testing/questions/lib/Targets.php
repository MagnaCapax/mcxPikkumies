<?php
/**
 * Targets: who is asked and with what (ADR-0039): the targets file, the settings the PIKKUMIES_CONFIG overlay
 * forces for one target (ADR-0038), and the environment every command in the sandbox runs with.
 */
class Targets
{
    /**
     * The targets file: a PHP file only this account can change, returning ['targets' => [name => ['endpoint', 'keyFile',
     * 'model', 'lightModel'?, 'family'?]], 'judge' => ['endpoint', 'keyFile', 'model']]. A key file is the INI file
     * pikkumies reads (endpoint= and key=); ~/ is the account's real home. With $keys, also 'keys' => [name => key]
     * for the targets in use ($only, or all) and the judge; a missing key stops here, before anything is asked.
     */
    public static function load($file, $keys, $only = '')
    {
        $st = $file !== '' ? @stat($file) : false;
        if (!$st || $st['uid'] !== posix_geteuid() || ($st['mode'] & 0022)) {
            throw new Exception("--targets: no file, or not owned by this account, or writable by others: '$file'");
        }
        $t = require $file;
        if (!is_array($t) || empty($t['targets']) || !is_array($t['targets'])) {
            throw new Exception("--targets $file: returns no 'targets' list");
        }
        $t['targets'] = $only !== '' ? array_intersect_key($t['targets'], [$only => 1]) : $t['targets'];
        if (!$t['targets']) {
            throw new Exception("--targets $file: no target named '$only'");
        }
        $home = posix_getpwuid(posix_geteuid())['dir'];
        $t['keys'] = [];
        foreach (array_merge($t['targets'], isset($t['judge']) ? ['judge' => $t['judge']] : []) as $name => $s) {
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $name) || empty($s['endpoint']) || empty($s['keyFile']) || empty($s['model'])) {
                throw new Exception("--targets $file: '$name' needs a plain name and endpoint, keyFile and model");
            }
            $s['keyFile'] = preg_replace('#^~/#', $home . '/', $s['keyFile']);
            if ($name === 'judge') {
                $t['judge'] = $s;
            } else {
                $t['targets'][$name] = $s;
            }
            $t['keys'][$name] = $keys ? Provider::readKeyFile($s['keyFile'])['key'] : '';
            if ($keys && $t['keys'][$name] === '') {
                throw new Exception("$name: no key= in {$s['keyFile']} (a file owned by this account, mode 0600)");
            }
        }
        return $t;
    }

    /** What the overlay forces for one target and host family ('' = the family pikkumies detects). */
    public static function settings(array $target, $family)
    {
        $s = ['provider' => ['endpoint' => $target['endpoint'], 'keyFile' => $target['keyFile'], 'model' => $target['model']]];
        if (!empty($target['lightModel'])) {
            $s['hooks'] = ['model' => $target['lightModel']];
        }
        if ($family !== '') {
            $s['host'] = ['family' => $family];
        }
        return $s;
    }

    /** Settings forced for the next command (Config::load merges this file last, ADR-0038). Returns its path. */
    public static function overlay(Sandbox $box, array $settings)
    {
        $path = $box->root . '/overlay.php';
        file_put_contents($path, "<?php\nreturn " . var_export($settings, true) . ";\n");
        chmod($path, 0600);
        return $path;
    }

    /** The environment of every command: HOME and PATH point into the sandbox; provider, Hermes, SSH-agent and XDG variables are dropped. */
    public static function env(Sandbox $box, $overlay)
    {
        $env = [];
        foreach (getenv() as $k => $v) {
            if (!preg_match('/^(PIKKUMIES_|HERMES_|XDG_|SSH_AUTH_SOCK$)/', $k)) {
                $env[$k] = $v;
            }
        }
        $env['HOME'] = $box->home;
        $env['PATH'] = $box->home . '/.qa-bin:' . (isset($env['PATH']) ? $env['PATH'] : '/usr/bin:/bin');
        if ($overlay !== '') {
            $env['PIKKUMIES_CONFIG'] = $overlay;
        }
        return $env;
    }
}
