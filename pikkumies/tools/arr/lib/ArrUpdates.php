<?php
/**
 * ArrUpdates: after a host-config change on an app the PMSS installer manages (found in its lowercase folder), puts
 * the config.xml tag UpdateMechanism back to Script and restarts the app the provider's way (ArrRestart), as the
 * installer does after it sets a login (etc/skel/install-media-stack.sh servarr_auth_seed; servarr_config_xml_tag_converge:
 * every <UpdateMechanism>...</UpdateMechanism> becomes Script, or the tag is added before </Config> when absent). The
 * app refuses "script" with no script path over its API (ArrAuth::putHost sends builtIn), so this is the one tag the
 * tool writes, and only in a config.xml this account owns: never through a link, never a non-regular file, written to a
 * temporary file with the file's own mode and renamed; nothing is written when the tag already reads Script.
 */
class ArrUpdates
{
    const TAG = 'UpdateMechanism';
    const VALUE = 'Script';

    /**
     * [] off the installer's folder or when the tag already read Script, else ['update_mechanism' => 'Script', 'restart' =>
     * record]. When config.xml cannot be written or the restart fails, throws: ok:false, because the app may still update
     * itself (on PMSS its updater has broken the .NET runtime the other media apps share). $done: what already changed.
     */
    public static function restore(array $app, callable $locate, callable $fresh, $done)
    {
        $risk = "$done, but {$app['name']} may still update itself: ";
        try {
            $w = $locate(true);
            if ($w['source'] !== 'pmss' || !self::converge($w['config'], (int) $w['uid'])) {
                return [];
            }
        } catch (CliError $e) {
            ArrAuth::log('update-mechanism', $app['id'], 'not-restored');
            throw new CliError($risk . 'UpdateMechanism could not be set back to Script in config.xml (' . $e->getMessage() . ')',
                'run `bash ~/install-media-stack.sh --dry-run`, then the same without --dry-run: it sets it back');
        }
        ArrAuth::log('update-mechanism', $app['id'], 'script');
        try {
            $r = ArrRestart::run($app, $locate(true), $fresh);
        } catch (CliError $e) {
            ArrAuth::log('update-mechanism', $app['id'], 'restart-failed');
            throw new CliError($risk . 'config.xml reads Script again, but the restart that applies it failed (' . $e->getMessage() . ')',
                'run `restart`; if that fails too, `bash ~/install-media-stack.sh --dry-run`, then the same without --dry-run');
        }
        return ['update_mechanism' => self::VALUE, 'restart' => $r];
    }

    /** Sets the tag in $path; true when the file was rewritten, false when it already read Script. Throws, nothing written, on doubt. */
    public static function converge($path, $uid)
    {
        clearstatcache(true, $path);
        $st = @lstat($path);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || (int) $st['uid'] !== (int) $uid) {
            throw new CliError("not writing UpdateMechanism to $path: it is missing, a link, not a regular file, or not this account's own",
                'the installer sets it back on its next run (`bash ~/install-media-stack.sh --dry-run` first)');
        }
        $text = @file_get_contents($path, false, null, 0, ArrConfig::MAX + 1);
        if (!is_string($text) || strlen($text) > ArrConfig::MAX || strpos($text, '</Config>') === false) {
            throw new CliError("not writing UpdateMechanism to $path: it could not be read whole or has no </Config>", 'run `login-check`');
        }
        $tag = self::TAG;
        $new = strpos($text, "<$tag>") !== false
            ? preg_replace("#<$tag>[^<]*</$tag>#", "<$tag>" . self::VALUE . "</$tag>", $text)
            : preg_replace('#</Config>#', "  <$tag>" . self::VALUE . "</$tag>\n</Config>", $text, 1);
        if (!is_string($new) || $new === $text) {
            return false;
        }
        $tmp = $path . '.pk' . bin2hex(random_bytes(6));
        $old = umask(0077);
        $ok = @file_put_contents($tmp, $new) === strlen($new) && @chmod($tmp, $st['mode'] & 07777) && @rename($tmp, $path);
        umask($old);
        if (!$ok) {
            @unlink($tmp);
            throw new CliError("could not write UpdateMechanism to $path", 'check that its folder is writable');
        }
        return true;
    }
}
