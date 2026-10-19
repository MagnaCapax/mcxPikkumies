<?php
/**
 * HermesWebUi: `pikkumies run dashboard` builds Hermes' web UI first when the checkout has none (ADR-0033 addendum
 * 2026-10-19). `pikkumies install` leaves it out, because every account would pay minutes and Node packages for a page
 * few open. Hermes' own launch build cannot make it: with lazy installs off (ADR-0021) it prepares the Node packages
 * with --no-install and stops (hermes_cli/source_build.py prepare_source_dependencies). So Pikkumies runs Hermes' own
 * explicit builder, the one `hermes update` calls (source_build.py build_update_products, explicit=True), through the
 * launcher's --run-module: no git, no new release, lazy installs stay off. It writes the checkout, so it runs as an
 * update does: the Hermes lock alone, setpriv --no-new-privs, refused on a root-capable host without setpriv.
 */
class HermesWebUi
{
    const MODULE = 'hermes_cli.source_build';

    public static function indexFile()
    {
        return HermesInstall::treeDir() . '/hermes_cli/web_dist/index.html';
    }

    /** True for a dashboard start on a checkout without the built page (HERMES_WEB_DIST: the user's own build). */
    public static function needed(array $passArgs)
    {
        if (!HermesDashboardGate::starts($passArgs) || in_array('--skip-build', $passArgs, true)) {
            return false; // no server starts, or the user promised a page of their own
        }
        return (string) getenv('HERMES_WEB_DIST') === '' && !is_file(self::indexFile());
    }

    /** The build command: Hermes' environment (ADR-0062) and its builder, under setpriv; [] when it must not run. */
    public static function command($bin, $rootAvailable)
    {
        $build = CronTick::wrap([$bin, '--run-module', self::MODULE, '--source', HermesInstall::treeDir()], $rootAvailable);
        if (!$build) {
            return [];
        }
        $unset = $set = [];
        foreach (HermesConfig::env() as $name => $value) {
            if ($value === null) {
                array_push($unset, '-u', $name); // env takes its options before the first NAME=VALUE
            } else {
                $set[] = "$name=$value";
            }
        }
        return array_merge(['env'], $unset, $set, $build);
    }

    /**
     * Build it, saying first that it takes minutes; '' when the page is there afterwards, else why not. The output
     * goes to the user's terminal as the build runs ($say prints Pikkumies' own lines).
     */
    public static function build($bin, $rootAvailable, callable $say)
    {
        $argv = self::command($bin, $rootAvailable);
        if (!$argv) {
            return 'root is available here and setpriv is missing; the build may call sudo, so it runs only under setpriv --no-new-privs (ADR-0025, ADR-0033).';
        }
        $lock = HermesInstall::lock(true);
        if (!$lock) {
            return 'the web UI is not built yet, and building it needs Hermes alone: other Hermes sessions or a scheduled job are running.'
                . ' Close the sessions (or wait a minute for the job) and run `pikkumies run dashboard` again.';
        }
        try {
            $say("The dashboard's web page is not built yet. Building it now with Hermes' own builder: it fetches the page's"
                . " Node packages and takes several minutes (about 3 to 6 on a test account). This happens once; `pikkumies run update` keeps it current.");
            passthru(implode(' ', array_map('escapeshellarg', $argv)), $code);
        } finally {
            fclose($lock);
        }
        clearstatcache();
        if (!is_file(self::indexFile())) {
            return "the web UI build did not produce the page (exit $code); its output is above. `pikkumies run update` builds it too.";
        }
        $say('The web page is built.');
        return '';
    }
}
