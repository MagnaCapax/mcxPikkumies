<?php
/**
 * PathInstall: put `pikkumies` on the user's PATH, idempotently (ADR-0022).
 *
 * Debian's own skeleton already adds ~/bin and ~/.local/bin to PATH from ~/.profile when either
 * directory exists (login shells; non-login shells inherit the exported PATH). When one of them is
 * already on PATH we only need a symlink there. Otherwise we fall back to a marked block in
 * ~/.bashrc and ~/.profile that exports PATH itself, so the command works without a relogin and on
 * shells that never source the Debian skeleton.
 */
class PathInstall
{
    const MARK_START = '# >>> pikkumies >>>';
    const MARK_END = '# <<< pikkumies <<<';

    /** ~/.local/bin preferred (XDG, and Debian's own skeleton orders it last so it wins on PATH). */
    public static function candidates($home)
    {
        $home = rtrim((string) $home, '/');
        return [$home . '/.local/bin', $home . '/bin'];
    }

    /** [dir, alreadyOnPath]: the first candidate already present in $pathEnv, else the preferred one. */
    public static function binDir($home, $pathEnv)
    {
        $on = array_map(function ($p) {
            return rtrim($p, '/');
        }, array_filter(explode(':', (string) $pathEnv), 'strlen'));
        foreach (self::candidates($home) as $dir) {
            if (in_array($dir, $on, true)) {
                return [$dir, true];
            }
        }
        return [self::candidates($home)[0], false];
    }

    /** Symlink $dir/pikkumies -> $target. Leaves an existing non-symlink alone (never overwritten). */
    public static function ensureLink($dir, $target)
    {
        @mkdir($dir, 0700, true);
        $link = $dir . '/pikkumies';
        if (is_link($link)) {
            if (readlink($link) === $target) {
                return $link . ' (already linked)';
            }
            @unlink($link);
        } elseif (file_exists($link)) {
            return $link . ' exists and is not a symlink; left alone';
        }
        if (@symlink($target, $link)) {
            return "linked $link -> $target";
        }
        // Two installs at once can both reach here between the checks above and this call; if the
        // other one already won the race with the same target, that is success, not a failure.
        return is_link($link) && readlink($link) === $target ? "$link (already linked)" : "could not link $link";
    }

    /** The marked PATH block for $dir, as shell source lines. */
    public static function block($dir)
    {
        // Single quotes: a $ or backtick in the path must never run at login.
        $quoted = "'" . str_replace("'", "'\\''", $dir) . "'";
        return self::MARK_START . "\n" . 'export PATH=' . $quoted . ':"$PATH"' . "\n" . self::MARK_END . "\n";
    }

    /**
     * $current with any existing marked block removed (wherever it was, whatever it said) and the
     * fresh $block appended. Every other line is kept exactly as it was — same contract as
     * Update::cronMerge, so a shell file is never duplicated or reordered on re-run.
     */
    public static function mergeBlock($current, $block)
    {
        $kept = '';
        $inBlock = false;
        foreach (preg_split('/(?<=\n)/', (string) $current, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $bare = rtrim($line, "\n");
            if ($bare === self::MARK_START) {
                $inBlock = true;
                continue;
            }
            if ($bare === self::MARK_END) {
                $inBlock = false;
                continue;
            }
            if (!$inBlock) {
                $kept .= $line;
            }
        }
        return $kept . ($kept !== '' && substr($kept, -1) !== "\n" ? "\n" : '') . $block;
    }

    /**
     * Ensure $path exports PATH for $dir, under a lock (the file mixes our block with the user's
     * own lines, so a read-merge-write race must not interleave with a concurrent install). Returns
     * whether anything was written. Keeps $path's existing mode (0644 for a new file).
     */
    public static function ensureFile($path, $dir)
    {
        // A dotfile manager often makes ~/.bashrc a symlink; write the file it points to, so the
        // rename below never replaces the link with a plain file. A dangling link is left alone.
        if (is_link($path)) {
            $real = realpath($path);
            if ($real === false) {
                return false;
            }
            $path = $real;
        }
        @mkdir(dirname($path), 0700, true);
        $lock = @fopen($path . '.pikkumies-lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new Exception("cannot take the lock for $path");
        }
        try {
            $current = is_file($path) ? (string) file_get_contents($path) : '';
            $merged = self::mergeBlock($current, self::block($dir));
            if ($merged === $current) {
                return false;
            }
            $mode = is_file($path) ? (fileperms($path) & 0777) : 0644;
            $tmp = $path . '.tmp-' . getmypid();
            if (@file_put_contents($tmp, $merged) !== strlen($merged) || !@chmod($tmp, $mode) || !@rename($tmp, $path)) {
                @unlink($tmp);
                throw new Exception("could not write $path (disk full?)");
            }
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Put `pikkumies` on PATH: a symlink in $dir (created if needed), plus a PATH block in
     * ~/.bashrc and ~/.profile when $dir is not already on PATH some other way. Returns a status
     * line for `pikkumies install` to print.
     */
    public static function install($home, $pathEnv, $target)
    {
        list($dir, $onPath) = self::binDir($home, $pathEnv);
        $linked = self::ensureLink($dir, $target);
        if ($onPath) {
            return "$dir ($linked); already on PATH";
        }
        $home = rtrim((string) $home, '/');
        $wrote = [];
        foreach ([$home . '/.bashrc', $home . '/.profile'] as $rc) {
            if (self::ensureFile($rc, $dir)) {
                $wrote[] = basename($rc);
            }
        }
        $note = $wrote ? 'PATH line added to ' . implode(' and ', $wrote) . ' (open a new shell)' : 'PATH line already present';
        return "$dir ($linked); $note";
    }
}
