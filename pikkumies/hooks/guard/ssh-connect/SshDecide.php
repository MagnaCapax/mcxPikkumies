<?php
/**
 * SshDecide: the ssh-connect hook's answer once the call is read (ADR-0068). The deny list, then the intent, then
 * ssh.blockUnknown block; a name not on the allow list, a route this check cannot follow or a write to ~/.ssh/config is
 * a question for the orchestrator's ask policy (ADR-0073), naming the hosts, the routes and the agent's reason before
 * the command; every name on the allow list is no opinion. The question must fit what the ask review can show in full
 * (HookUnits::APPROVE_SHOWN), or it is a block: the user must be able to see all of what they are asked to clear. The
 * intent marker is written only for a call that is allowed or reaches the ask policy, and it is read only to save the
 * agent repeating its reason: it never allows, denies or asks by itself.
 */
class SshDecide
{
    const MARK_DAYS = 7;
    /** C0 controls but tab and newline, DEL, C1, zero-width marks and bidi controls (U+200B-200F, U+202A-202E, U+2066-2069). */
    const CONTROL_RE = '/[\x00-\x08\x0b-\x1f\x7f]|\xc2[\x80-\x9f]|\xe2\x80[\x8b-\x8f\xaa-\xae]|\xe2\x81[\xa6-\xa9]/';

    /** A block or approve answer, or null for no opinion. $call: intent, session, key (hashed), shown (in the question), configWrite. */
    public static function answer(array $found, array $config, array $call)
    {
        $names = [];
        foreach ($found['conns'] as $conn) {
            foreach ($conn['names'] as $name) {
                if (SshLists::decide($config, [$name]) === SshLists::DENY) {
                    return self::block("$name is on the user's SSH deny list, which only the user changes. Not run. Do not reach it another way; list it as skipped, with this reason, in the final answer.");
                }
                $names[$name] = true;
            }
        }
        $names = array_keys($names);
        $why = $call['intent'] !== '' ? $call['intent'] : self::remembered($call['session'], $names);
        if ($names && $why === '') {
            return self::block('say why before connecting to ' . implode(', ', $names) . ': end the command with `# intent: <why, in a few words>`'
                . ' (at least 8 characters). Not run.');
        }
        $unknown = [];
        $routes = $found['routes'];
        foreach ($found['conns'] as $conn) {
            foreach ($conn['names'] as $name) {
                $unknown = SshLists::decide($config, [$name]) === SshLists::ALLOW ? $unknown : array_merge($unknown, [$name]);
            }
            $routes = array_merge($routes, $conn['routes']);
        }
        $unknown = array_values(array_unique($unknown));
        $routes = array_values(array_unique($routes));
        $configNote = $call['configWrite'] ? 'writes ~/.ssh/config, which decides where an SSH host name really connects; check it is what you asked for' : '';
        if ($unknown || $routes) {
            $what = ($unknown ? 'SSH to ' . implode(', ', $unknown) . ', not on your SSH allow list' : 'an SSH connection')
                . ($routes ? ' (through ' . implode('; ', $routes) . ', which this check cannot follow)' : '');
            if (SshLists::blockUnknown($config)) {
                return self::block("$what, and the user refuses unknown hosts (ssh.blockUnknown, their own setting; ssh.allow is theirs too). Not run. Do not reach it another way; list it as skipped, with this reason, in the final answer.");
            }
            $what .= ($why !== '' ? ". The agent says why: $why" : '') . ($configNote !== '' ? "; it also $configNote" : '');
        } elseif ($configNote !== '') {
            $what = $configNote;
        } else {
            self::remember($call, $names);
            return null; // every name is on the allow list
        }
        if (SshUnreadable::matches(self::CONTROL_RE, $call['shown'])) {
            return self::block("$what, and the command holds control or direction characters, so the question could not show it as it runs. Not run. Remove them.");
        }
        $id = substr(sha1($call['key']), 0, 16);
        $message = "Pikkumies: $what. Command [$id]: {$call['shown']}";
        if (!HookUnits::approveShown($message)) {
            return self::block("$what, and the question is too long to show the user in full. Not run. Shorten the command or its intent, or split it.");
        }
        self::remember($call, $names);
        return ['action' => 'approve', 'rule_key' => 'pikkumies-ssh:' . $id, 'message' => $message];
    }

    /** The agent's words for a message: no control or direction characters, whitespace collapsed, at most 200 characters. */
    public static function clean($text)
    {
        $text = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace(self::CONTROL_RE, '', (string) $text)));
        return preg_match('/^.{0,200}/su', $text, $m) ? $m[0] : substr($text, 0, 200);
    }

    /** The command as the question shows it: a trailing `# intent:` comment is dropped, since the question names the reason. */
    public static function shown($command, $intent)
    {
        $split = $intent !== '' ? SshWords::split($command) : null;
        $last = $split && $split['comments'] ? end($split['comments']) : null;
        if ($last && SshUnreadable::matches('/^#\s*intent:/i', $last[2]) && trim(substr($command, $last[1])) === '') {
            return rtrim(substr($command, 0, $last[0]));
        }
        return $command;
    }

    private static function block($why)
    {
        return ['action' => 'block', 'message' => "Pikkumies SSH check: $why"];
    }

    /** The reason stated earlier in this session, when every one of $names has one; '' otherwise. */
    private static function remembered($session, array $names)
    {
        $why = '';
        foreach ($session === '' ? [] : $names as $name) {
            $text = @file_get_contents(self::marker($session, $name), false, null, 0, 1024);
            if (!is_string($text) || self::clean($text) === '') {
                return '';
            }
            $why = $why === '' ? self::clean($text) : $why;
        }
        return $why;
    }

    /** Write the reason for each name (directory 0700, files 0600), and remove markers older than MARK_DAYS. */
    private static function remember(array $call, array $names)
    {
        if ($call['session'] === '' || $call['intent'] === '' || !$names) {
            return;
        }
        $dir = dirname(self::marker($call['session'], ''));
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return; // the agent then states the reason again; nothing is allowed by a marker
        }
        foreach ((array) glob("$dir/*") as $old) {
            if (@filemtime($old) < time() - self::MARK_DAYS * 86400) {
                @unlink($old);
            }
        }
        foreach ($names as $name) {
            $file = self::marker($call['session'], $name);
            if (@file_put_contents("$file.tmp", $call['intent']) !== false && @chmod("$file.tmp", 0600)) {
                @rename("$file.tmp", $file);
            }
        }
    }

    private static function marker($session, $host)
    {
        return Config::localDir() . '/state/ssh-intent/' . sha1($session . "\0" . $host);
    }
}
