<?php
/**
 * SshWrappers: programs that run another command, for SshCommand (ADR-0068). A wrapper's options are skipped (the
 * letters and long names that take a value skip it too) and the word after them is the command; sh -c, su -c, flock -c,
 * env -S, eval and watch run text, which is read again as commands; a shell with no script reads stdin.
 */
class SshWrappers
{
    /** wrapper => [short options taking a value, long options taking a value, words before the command]. */
    const WRAPPERS = [
        'sudo' => ['ughpCDRTUrt', ['user', 'group', 'host', 'prompt', 'close-from', 'chdir', 'chroot', 'command-timeout', 'other-user', 'role', 'type'], 0],
        'env' => ['uC', ['unset', 'chdir'], 0], 'nohup' => ['', [], 0], 'setsid' => ['', [], 0], 'time' => ['fo', ['format', 'output'], 0],
        'command' => ['', [], 0], 'exec' => ['a', [], 0], 'nice' => ['n', ['adjustment'], 0], 'ionice' => ['cnp', ['class', 'classdata', 'pid'], 0],
        'timeout' => ['sk', ['signal', 'kill-after'], 1], 'stdbuf' => ['ioe', ['input', 'output', 'error'], 0],
        'xargs' => ['adEILnPs', ['arg-file', 'delimiter', 'eof', 'replace', 'max-lines', 'max-args', 'max-procs', 'max-chars', 'process-slot-var'], 0],
        'flock' => ['wE', ['wait', 'timeout', 'conflict-exit-code'], 1], 'torsocks' => ['uapP', ['user', 'pass', 'address', 'port'], 0],
        'proxychains' => ['f', [], 0], 'proxychains4' => ['f', [], 0], 'sshpass' => ['fdpP', [], 0],
    ];

    /** sh/bash ... -c 'text' reads the text; a script file is out of sight; no script, -s or - reads stdin (returns true). */
    public static function shell(array $words, $i, $depth, array &$found)
    {
        $stdin = false;
        for ($j = $i + 1, $n = count($words); $j < $n; $j++) {
            $t = $words[$j]['text'];
            if ($t === '' || ($t[0] !== '-' && $t[0] !== '+') || $t === '-' || $t === '--') {
                return $stdin || $t === '-';
            }
            $stdin = $stdin || SshUnreadable::matches('/^-[A-Za-z]*s/', $t) === 1;
            if (SshUnreadable::matches('/^[-+][A-Za-z]*c[A-Za-z]*\z/', $t)) {
                if (!isset($words[$j + 1])) {
                    throw new SshUnreadable('sh -c has no command');
                }
                SshCommand::merge($found, SshCommand::read($words[$j + 1]['text'], $depth + 1));
                return false;
            }
            $j += in_array($t, ['-o', '+o', '-O', '+O'], true) ? 1 : 0;
        }
        return true;
    }

    /** eval and watch run their words joined as one command; su runs its -c text. */
    public static function scriptOf($name, array $args, $depth, array &$found)
    {
        $texts = array_column($args, 'text');
        if ($name === 'su') {
            foreach ($texts as $k => $t) {
                if (SshUnreadable::matches('/^(-c|--command)(=?)(.*)\z/s', $t, $m)) {
                    $script = $m[2] === '=' || $m[3] !== '' ? $m[3] : (isset($texts[$k + 1]) ? $texts[$k + 1] : '');
                    SshCommand::merge($found, SshCommand::read($script, $depth + 1));
                }
            }
            return;
        }
        while ($name === 'watch' && $texts && $texts[0] !== '' && $texts[0][0] === '-') {
            $skip = in_array(array_shift($texts), ['-n', '--interval'], true);
            $skip ? array_shift($texts) : null;
        }
        SshCommand::merge($found, SshCommand::read(implode(' ', $texts), $depth + 1));
    }

    /** Skip a wrapper and its options; returns the index of the word it runs. flock and env -S texts are read too. */
    public static function wrapper($name, array $words, $i, $depth, array &$found)
    {
        list($short, $long, $positional) = self::WRAPPERS[$name];
        for ($i++, $n = count($words); $i < $n; $i++) {
            $t = $words[$i]['text'];
            if ($t === '--') {
                return $i + 1 + $positional;
            }
            if ($name === 'env' && SshCommand::assignment($t, $found)) {
                continue;
            }
            if ($name === 'env' && SshUnreadable::matches('/^(-S|--split-string)(=?)(.*)\z/s', $t, $m)) {
                // env -S 'ssh -p 2' host: the string is split into words in front of the rest.
                $script = $m[2] === '=' || $m[3] !== '' ? $m[3] : (isset($words[$i + 1]) ? $words[++$i]['text'] : '');
                SshCommand::merge($found, SshCommand::read($script . ' ' . implode(' ', array_column(array_slice($words, $i + 1), 'text')), $depth + 1));
                return $n;
            }
            if ($t === '' || $t[0] !== '-' || $t === '-') {
                break;
            }
            if (strncmp($t, '--', 2) === 0) {
                $i += strpos($t, '=') === false && in_array(substr($t, 2), $long, true) ? 1 : 0;
                continue;
            }
            for ($k = 1; $k < strlen($t); $k++) {
                if (strpos($short, $t[$k]) !== false) {
                    $i += $k === strlen($t) - 1 ? 1 : 0; // the value is the next word unless it is attached
                    break;
                }
            }
        }
        $i += $positional;
        if ($name === 'flock' && isset($words[$i]) && $words[$i]['text'] === '-c') {
            SshCommand::merge($found, SshCommand::read(isset($words[$i + 1]) ? $words[$i + 1]['text'] : '', $depth + 1));
            return $n;
        }
        return $i;
    }
}
