<?php
/** Submit one local question into the Primary pane without putting its text in argv or logs. */
class EternalAsk
{
    public static function clean($text)
    {
        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', '', $text);
    }

    public static function send($text, $nonce = '')
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return [1, 'Ask runs as the account owner, not root.'];
        }
        $text = trim(self::clean($text));
        $length = preg_match_all('/./us', $text);
        if ($text === '' || $length === false || $length > 4000) {
            return [1, 'Ask needs 1 to 4000 characters on stdin.'];
        }
        if ($nonce !== '' && preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $nonce) !== 1) {
            return [1, 'Ask nonce is invalid.'];
        }
        if (empty(Config::load()['terms']['accepted']) || Eternal::ready() !== '') {
            return [2, 'Setup is not finished. Open Primary to accept the terms.'];
        }
        $lock = Eternal::lock();
        if (!$lock) {
            return [5, 'Primary is starting. Try again shortly.'];
        }
        list($code, $pane) = Eternal::tmux(['display-message', '-p', '-t', '=pikkumies:pm1', '#{pane_start_command}|#{pane_tty}|#{pane_in_mode}']);
        $paneParts = explode('|', $pane, 3);
        if ($code !== 0 || count($paneParts) !== 3 || !Eternal::paneOwns($paneParts[0], 'pm1')) {
            return [5, 'Primary is starting. Try again shortly.'];
        }
        $primary = EternalRecord::read('pm1');
        if ($primary === null || Eternal::pointer($paneParts[1], $primary['lastStart']) === '') {
            return [5, 'Primary is starting. Try again shortly.'];
        }
        list($code, $screen) = Eternal::tmux(['capture-pane', '-p', '-t', '=pikkumies:pm1', '-S', '-20']);
        if ($code !== 0) {
            return [5, 'Primary is starting. Try again shortly.'];
        }
        if (preg_match('/approval|approve|allow this|\[y\/n\]/i', $screen)) {
            return [3, 'Primary has an approval prompt open. Answer it there first.'];
        }
        if (self::heldAtInput($screen)) {
            return [3, 'Primary has unsent text at its input. Send or clear it there first.'];
        }
        if (!self::idleAtInput($screen)) {
            return [6, 'Primary is busy. Try again shortly.'];
        }
        $path = Config::localDir() . '/eternals/ask-rate';
        $noncePath = Config::localDir() . '/eternals/ask-nonces';
        $nonces = json_decode((string) @file_get_contents($noncePath), true);
        $nonces = is_array($nonces) ? array_filter($nonces, function ($at) { return is_int($at) && $at >= time() - 3600; }) : [];
        if ($nonce !== '' && isset($nonces[$nonce])) {
            return [0, 'Already sent.'];
        }
        $state = json_decode((string) @file_get_contents($path), true);
        $now = time();
        $state = is_array($state) && isset($state['minute'], $state['count']) && $state['minute'] === (int) floor($now / 60)
            ? $state : ['minute' => (int) floor($now / 60), 'count' => 0];
        if ($state['count'] >= 5) {
            return [4, 'Ask is rate capped. Try again next minute.'];
        }
        if (!self::cancelCopyMode($paneParts[2])) {
            return [1, 'Could not send to Primary.'];
        }
        $process = @proc_open(implode(' ', array_map('escapeshellarg', array_merge(['timeout', '5'], Eternal::tmuxArgv(['load-buffer', '-'])))), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return [1, 'Could not send to Primary.'];
        }
        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || Eternal::tmux(['paste-buffer', '-p', '-d', '-t', '=pikkumies:pm1'])[0] !== 0
            || Eternal::tmux(['send-keys', '-t', '=pikkumies:pm1', 'Enter'])[0] !== 0) {
            return [1, 'Could not send to Primary.'];
        }
        $state['count']++;
        $tmp = $path . '.tmp-' . getmypid();
        if (@file_put_contents($tmp, json_encode($state)) !== false) {
            @rename($tmp, $path);
        }
        if ($nonce !== '') {
            $nonces[$nonce] = $now;
            $tmp = $noncePath . '.tmp-' . getmypid();
            if (@file_put_contents($tmp, json_encode($nonces)) !== false) {
                @rename($tmp, $noncePath);
            }
        }
        @file_put_contents(Config::localDir() . '/update.log', gmdate('c') . ' [ask] sent ' . strlen($text) . " bytes\n", FILE_APPEND | LOCK_EX);
        return [0, 'Sent to Primary.'];
    }

    public static function idleAtInput($screen)
    {
        return self::inputLine($screen) === 'empty';
    }

    public static function heldAtInput($screen)
    {
        return self::inputLine($screen) === 'held';
    }

    public static function inputLine($screen)
    {
        // The input widget sits between rule lines below the status bar. Only its normal,
        // empty prompt is a positive signal; spinner/question/approval glyphs stay busy.
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $screen)), function ($line) {
            return $line !== '';
        }));
        $tail = array_slice($lines, -3);
        $count = count($tail);
        for ($i = 0; $i < $count; $i++) {
            if (preg_match('/^(?:❯|>)(?:\s*(\S.*))?$/u', $tail[$i], $match) !== 1) {
                continue;
            }
            $above = $i > 0 && preg_match('/^─{3,}$/u', $tail[$i - 1]) === 1;
            $below = $i + 1 < $count && preg_match('/^─{3,}$/u', $tail[$i + 1]) === 1;
            if ($above && ($below || $i === $count - 1)) {
                return isset($match[1]) && $match[1] !== '' ? 'held' : 'empty';
            }
        }
        return 'unknown';
    }

    public static function cancelCopyMode($paneMode)
    {
        return $paneMode !== '1' || Eternal::tmux(['send-keys', '-t', '=pikkumies:pm1', '-X', 'cancel'])[0] === 0;
    }
}
