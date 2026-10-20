<?php
/** Locks held by the resident process are close-on-exec, so children never keep them alive. */
class EternalLock
{
    public static function take($path, $wait = false, $exclusive = true)
    {
        @mkdir(dirname($path), 0700, true);
        $file = @fopen($path, 'ce');
        if (!$file || !@flock($file, ($exclusive ? LOCK_EX : LOCK_SH) | ($wait ? 0 : LOCK_NB))) {
            if ($file) {
                fclose($file);
            }
            return false;
        }
        return $file;
    }
}
