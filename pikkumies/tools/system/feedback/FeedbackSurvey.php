<?php
/**
 * FeedbackSurvey (ADR-0097, ADR-0056 amendment 2026-10-20): the session rating, asked on the terminal by bin/pikkumies
 * after Hermes exits, never by the agent. It costs no tokens and the model cannot answer it. Shown only when
 * FeedbackAsk::eligible says the run may be asked (feedback.ask on, 45 minutes AND 500,000 tokens, the shared budget
 * open, the run's one ask unused), on a terminal, with a Pulsed Media AI key and PHP sodium. The ask is claimed for the
 * dismissed snooze before the line is printed. A number sends only the rating, the rounded length and tokens, the model
 * and the version numbers, sealed with /feedback's key, endpoint and account key; 0, Enter, 20 seconds of silence or `n`
 * send nothing, and `n` turns feedback.ask off. A failed send is reported once and never retried.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/SessionCounters.php';
require_once __DIR__ . '/FeedbackAsk.php';
require_once __DIR__ . '/FeedbackTty.php';

class FeedbackSurvey
{
    /** Seconds the line waits for an answer; silence is a dismissal, so closing Pikkumies is never held for long. */
    const TIMEOUT = 20;
    /** Seconds the send may take at exit. */
    const SEND_TIMEOUT = 5;
    /** The local copy, when privacy.perfLogging is on (tools/system/telemetry, ADR-0086). */
    const SOURCE = 'feedback-rating';
    const LINE = 'How is Pikkumies doing this session?  1 bad  2 fine  3 good  0 dismiss  n never ask';
    const DISCLOSURE = 'A number sends Pulsed Media only that number, the session\'s length and token count (rounded), the model and versions, linked to your Pulsed Media account. No conversation.';

    /** What a rating sends: integers rounded so they say how long and how big, plus the model id and the version numbers. */
    public static function payload($rating, array $counters, $now, $model, array $versions)
    {
        $v = [];
        foreach ($versions as $name => $version) { // version numbers only: each at most 64 printable characters
            if (is_string($name) && preg_match('/^[a-z]{1,16}$/D', $name) && is_scalar($version)) {
                $v[$name] = substr((string) preg_replace('/[^\x20-\x7e]/', '', (string) $version), 0, 64);
            }
        }
        return ['v' => 1, 'kind' => 'rating', 'rating' => (int) $rating,
            'minutes' => (int) round(max(0, $now - (int) $counters['since']) / 300) * 5,
            'tokens_k' => (int) round(max(0, (int) $counters['tokens']) / 100000) * 100,
            'model' => self::modelName($model), 'versions' => $v];
    }

    /**
     * The model as a name, never a path: a file path or anything naming a home directory (a local model file) is "other";
     * a provider's "family/name" is cut to its last part; anything left that is not 1-128 of [A-Za-z0-9._:@+-] is "other".
     */
    public static function modelName($model, $home = null)
    {
        $model = trim((string) $model);
        $home = rtrim((string) ($home === null ? getenv('HOME') : $home), '/');
        if ($model === '' || $model[0] === '/' || $model[0] === '~' || strpos($model, '\\') !== false
            || ($home !== '' && strpos($model, $home) !== false) || preg_match('#(^|/)\.\.?(/|$)|/home/|/root/#', $model)) {
            return 'other';
        }
        $base = substr($model, (int) strrpos('/' . $model, '/'));
        return preg_match('/^[A-Za-z0-9._:@+-]{1,128}$/D', $base) && trim($base, '.') !== '' ? $base : 'other';
    }

    /**
     * Asks once and acts on the answer. $io: ready (bool: terminal, key and sodium checked by the caller), prepare()
     * optional, called before the line is shown, returning the closure called after the read (FeedbackTty: drain what was
     * typed before, canonical mode, then the saved mode back), read(timeout) returning a line or false, write(text),
     * send(json) that throws on failure, versions() and model (string), record(fields) optional. Returns '' (not shown),
     * 'sent', 'failed', 'dismissed' or 'never'.
     */
    public static function run(array $config, $run, $now, array $io, $localDir = null)
    {
        if (empty($io['ready']) || !FeedbackAsk::eligible($config, $run, $now, $localDir)
            || !FeedbackAsk::claim($now, $run, FeedbackAsk::SNOOZE_DISMISSED_DAYS, $localDir)) {
            return '';
        }
        $counters = SessionCounters::readRun($localDir === null ? Config::localDir() : $localDir, $run);
        $restore = isset($io['prepare']) ? $io['prepare']() : null;
        try {
            $io['write']("\n" . self::LINE . "\n" . self::DISCLOSURE . "\n> ");
            $line = $io['read'](self::TIMEOUT);
        } finally {
            if (is_callable($restore)) {
                $restore();
            }
        }
        $answer = $line === false ? '' : strtolower(trim((string) $line));
        if ($answer === 'n') {
            try {
                Config::set('feedback.ask', false, $localDir === null ? null : "$localDir/config.php");
                $io['write']("Feedback questions are off.\n");
            } catch (Exception $e) {
                $io['write']('Feedback questions could not be turned off: ' . Cli::cleanString($e->getMessage()) . "; use the pikkumies-configure skill.\n");
            }
            return 'never';
        }
        if (!in_array($answer, ['1', '2', '3'], true) || !is_array($counters)) {
            $io['write']($line === false ? "\n" : '');
            return 'dismissed'; // the claim already holds the 30-day snooze
        }
        FeedbackAsk::snooze($now, FeedbackAsk::SNOOZE_ANSWERED_DAYS, $localDir);
        $p = self::payload((int) $answer, $counters, $now, isset($io['model']) ? $io['model'] : '', $io['versions']());
        if (!empty($config['privacy']['perfLogging']) && isset($io['record'])) {
            try {
                $io['record'](['rating' => $p['rating'], 'minutes' => $p['minutes'], 'tokens_k' => $p['tokens_k']]);
            } catch (Exception $e) {
                // the local copy is a convenience; the answer still goes as the person chose
            }
        }
        try {
            $io['send'](json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            $io['write']('Not sent: ' . Cli::cleanString($e->getMessage()) . ". Nothing is retried.\n");
            return 'failed';
        }
        $io['write']($p['rating'] === 1 ? "Thanks. To tell us what went wrong, type /feedback in your next session.\n" : "Thanks, sent.\n");
        return 'sent';
    }

    /**
     * bin/pikkumies' post-exit call. A no-op unless this was a session typed on a terminal (stdin and stdout), with a run id,
     * feedback.ask on and no launcher flag that turns the safety layers off or changes the config (--safe-mode and its kin,
     * which reach Hermes only as flags). --yolo is the default run mode (ADR-0073 amendment 2026-10-20), so it is no reason
     * to stay silent and is left out of that check. PIKKUMIES_DEPTH here is the depth the launcher gave Hermes, so the
     * person's own start reads 1. Never throws: nothing here may change how Pikkumies exits.
     */
    public static function atExit(array $passArgs)
    {
        try {
            $run = FeedbackAsk::runId();
            $yolo = '/^--(' . NestedAgent::spellings(['yolo' => NestedAgent::FLAGS['yolo']]) . ')$/';
            $flagArgs = array_values(array_filter(array_map('strval', $passArgs), function ($a) use ($yolo) {
                return !preg_match($yolo, $a);
            }));
            if ($run === '' || !Launch::isSession($passArgs) || !Launch::isTty(STDIN) || !Launch::isTty(STDOUT)
                || NestedAgent::argvFlag($flagArgs) !== '') {
                return;
            }
            $config = Config::load();
            if (empty($config['feedback']['ask'])) {
                return;
            }
            require_once __DIR__ . '/FeedbackSend.php';
            try {
                $key = function_exists('sodium_crypto_box_seal') ? FeedbackSend::pmKey($config) : '';
            } catch (CliError $e) {
                $key = '';
            }
            $model = RunFlags::model($passArgs);
            self::run($config, $run, time(), ['ready' => $key !== '', 'model' => $model !== '' ? $model : Provider::resolve($config)['model'],
                'prepare' => ['FeedbackTty', 'prepare'], 'read' => function ($timeout) {
                    return FeedbackTty::readLine(STDIN, $timeout);
                }, 'write' => function ($t) {
                    fwrite(STDOUT, $t);
                }, 'send' => function ($json) use ($key) {
                    return FeedbackSend::send($json, $key, dirname(__DIR__, 3) . '/config/feedback-public-key.txt', FeedbackSend::ENDPOINT, FeedbackSurvey::SEND_TIMEOUT);
                }, 'versions' => ['FeedbackSend', 'versions'], 'record' => function (array $fields) {
                    $log = dirname(__DIR__) . '/telemetry/TelemetryLog.php';
                    if (is_file($log)) { // the local store, when it is part of this Pikkumies (ADR-0086)
                        require_once $log;
                        TelemetryLog::record(FeedbackSurvey::SOURCE, $fields);
                    }
                }]);
        } catch (Throwable $x) {
            // a rating is never worth a failed exit
        }
    }
}
