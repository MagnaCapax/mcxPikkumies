<?php
/**
 * WhisparrRun: one unit for both Whisparr majors (README.md "Which Whisparr"). Both serve /api/v3 with the
 * X-Api-Key header and keep config.xml in ~/.config/Whisparr, so finding the app is the same; the library
 * differs (v2: series = sites, episodes = scenes; v3: movies and scenes). The verb list is the same for both,
 * so it is built without contacting anything; when a verb runs, GET system/status gives `version`, and its
 * major number picks the table row: 2 -> whisparr2, 3 -> whisparr. Any other answer is an error, never a guess.
 */
class WhisparrRun
{
    public static function run(array $argv, $usage)
    {
        list($verbs, $flags, $options) = Arr::build(ArrApps::get('whisparr'));
        $lazy = [];
        foreach (array_keys($verbs) as $name) {
            $lazy[$name] = function ($args, $opt) use ($name) {
                list($real) = Arr::build(self::detect(in_array($name, ['restart', 'login-check', 'repair'], true)));
                return call_user_func($real[$name], $args, $opt);
            };
        }
        Cli::run($argv, $lazy, $usage, $flags, $options);
    }

    /**
     * The table row of the Whisparr that answers. $lenient (restart, login-check, repair): a Whisparr that does not answer is not an
     * error here (it may be the thing to start); the v3 row is used, and restart needs only the shared fields.
     */
    public static function detect($lenient = false)
    {
        $app = ArrApps::get('whisparr');
        $locate = Arr::locator($app);
        try {
            $s = (new ArrClient($locate($lenient), $locate, $app['api'], Arr::timeout()))->call('GET', 'system/status');
        } catch (CliError $e) {
            if ($lenient) {
                return $app;
            }
            throw $e;
        }
        $version = is_array($s) ? ArrRecords::s($s, 'version') : '';
        $major = preg_match('/^([0-9]+)\./', $version, $m) ? (int) $m[1] : 0;
        if ($major === 2 || $major === 3) {
            return ArrApps::get($major === 2 ? 'whisparr2' : 'whisparr');
        }
        throw new CliError('Whisparr answered with version "' . substr($version, 0, 40) . '", which this tool does not know'
            . ' (it reads 2.x and 3.x)',
            'nothing was changed; use the app\'s own web page, or report the version so the tool can learn it');
    }
}
