<?php
/**
 * CronAuth (ADR-0101): what the person asked for when a scheduled job was made, so the job's runs get the same ask
 * review as the person's own session instead of a refusal for every delete.
 * - record(): hooks/guard/guard-destructive, on a cronjob_manage create or update that carries a prompt, in a session
 *   with the person's words (the halt snapshot's asked entries), asks the ask review whether the person asked for this
 *   scheduled job (R1 yes, R3 no). Only then it keeps {sha256 of the prompt: the prompt, those entries, the time} in
 *   local/state/ask/jobs.json (dir 0700, file 0600, temp name + rename under a lock, at most MAX entries, newest kept).
 *   It never blocks the call.
 * - lookup(): hooks/halt/halt-snapshot, in a run whose platform is cron (Hermes v0.22.2 cron/scheduler.py: platform
 *   "cron", agent.run_conversation(prompt)), finds a recorded prompt inside the run's user message where Hermes puts
 *   it (cron/scheduler_prompt.py _build_job_prompt: after its fixed hint or a data block, each ending in a blank line;
 *   followed by nothing or a "## Run Context" block) and returns the entries recorded with it as the run's evidence.
 * The record is the person's words, never the prompt: the review still judges each action against what they asked.
 * guard-destructive blocks every call that names local/state/ask. A safety record at the same trust level as the halt
 * snapshot, not a security boundary (AGENTS.md): the agent's user owns the file.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/AskReview.php';

class CronAuth
{
    const MAX = 200;            // entries kept, newest first
    const PROMPT_MAX = 20000;   // characters; a longer prompt is not recorded, so its runs get no evidence
    const REVIEW_SECONDS = 6;   // within guard-destructive's 10 s timeout
    const QUESTION = 'Pikkumies: creates or changes a scheduled job that runs by itself whenever it is due, with this prompt: ';

    /** Test seam: a callable($tool, $questions, $evidence, $timeout) returning parsed R answers, instead of the model. */
    public static $reviewer = null;

    /** Test seam: another record file. */
    public static $path = null;

    public static function file()
    {
        return self::$path !== null ? self::$path : Config::localDir() . '/state/ask/jobs.json';
    }

    /**
     * Record the person's words for this job prompt when the review finds they asked for it. Returns a reason code for
     * the hook log: 'recorded', 'not-covered', 'no-answer', 'no-evidence', 'no-prompt', 'too-long' or 'write-failed'.
     */
    public static function record($prompt, array $evidence)
    {
        $prompt = is_string($prompt) ? trim($prompt) : '';
        if ($prompt === '') {
            return 'no-prompt';
        }
        if (strlen($prompt) > self::PROMPT_MAX) {
            return 'too-long';
        }
        if (!$evidence) {
            return 'no-evidence';
        }
        $q = [self::QUESTION . $prompt];
        $answers = self::$reviewer !== null ? call_user_func(self::$reviewer, 'cronjob_manage', $q, $evidence, self::REVIEW_SECONDS)
            : AskReview::ask('cronjob_manage', $q, $evidence, self::REVIEW_SECONDS);
        if (!is_array($answers) || !isset($answers['R1'], $answers['R3'])) {
            return 'no-answer';
        }
        if ($answers['R1'] !== 'yes' || $answers['R3'] !== 'no') {
            return 'not-covered';
        }
        $entry = ['prompt' => $prompt, 'asked' => array_values($evidence), 'ts' => time()];
        return self::update(function (array $jobs) use ($prompt, $entry) {
            $jobs[hash('sha256', $prompt)] = $entry;
            uasort($jobs, function ($a, $b) {
                return (isset($b['ts']) ? (int) $b['ts'] : 0) - (isset($a['ts']) ? (int) $a['ts'] : 0);
            });
            return array_slice($jobs, 0, self::MAX, true);
        }) ? 'recorded' : 'write-failed';
    }

    /** The asked entries recorded for the job prompt found in this cron run's user message, or [] (newest record wins). */
    public static function lookup($userMessage)
    {
        $msg = (string) $userMessage;
        $data = json_decode((string) @file_get_contents(self::file()), true);
        if ($msg === '' || !is_array($data)) {
            return [];
        }
        $best = null;
        foreach ($data as $e) {
            if (!is_array($e) || !isset($e['prompt'], $e['asked'], $e['ts']) || !is_string($e['prompt']) || !is_array($e['asked'])) {
                continue;
            }
            if (self::placed($msg, $e['prompt']) && ($best === null || (int) $e['ts'] > (int) $best['ts'])) {
                $best = $e;
            }
        }
        return $best === null ? [] : array_values($best['asked']);
    }

    /** True when $prompt sits in $msg where Hermes puts a job's prompt: after a blank line (or at the start), before the end or a Run Context block. */
    public static function placed($msg, $prompt)
    {
        $prompt = trim((string) $prompt);
        if ($prompt === '') {
            return false;
        }
        for ($pos = strpos($msg, $prompt); $pos !== false; $pos = strpos($msg, $prompt, $pos + 1)) {
            $before = rtrim(substr($msg, 0, $pos), " \t");
            $after = ltrim((string) substr($msg, $pos + strlen($prompt)), " \t\r\n");
            if (($before === '' || substr($before, -2) === "\n\n") && ($after === '' || strpos($after, '## Run Context') === 0)) {
                return true;
            }
        }
        return false;
    }

    /** Read-modify-write of the record under a lock; true when written (dir 0700, file 0600, temp name + rename). */
    private static function update(callable $change)
    {
        $file = self::file();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return false;
        }
        $old = umask(0077);
        $lock = @fopen($file . '.lock', 'c');
        umask($old);
        if (!$lock || !flock($lock, LOCK_EX)) {
            return false;
        }
        try {
            $jobs = json_decode((string) @file_get_contents($file), true);
            $body = json_encode($change(is_array($jobs) ? $jobs : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($body)) {
                return false;
            }
            $tmp = $file . '.tmp-' . getmypid();
            $old = umask(0077);
            $ok = @file_put_contents($tmp, $body) === strlen($body) && @chmod($tmp, 0600) && @rename($tmp, $file);
            umask($old);
            if (!$ok) {
                @unlink($tmp);
            }
            return $ok;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
