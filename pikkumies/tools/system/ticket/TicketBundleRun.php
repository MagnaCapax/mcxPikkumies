<?php
/**
 * TicketBundleRun (ADR-0100): the `ticket bundle` verb. Checks the diagnosis (and, for --by agent, the ten-round review
 * and the searches the session ran), collects attachment 01 at the right scope, builds 02 and 03 and the message, writes
 * the three files to a private folder under the ticket state, and saves the draft through TicketDraft::create, which
 * redacts every text file and checks the endpoint's limits like any ticket. Nothing leaves the machine.
 */
require_once __DIR__ . '/TicketSearches.php';
require_once __DIR__ . '/TicketBundle.php';
require_once __DIR__ . '/TicketAuto.php';

class TicketBundleRun
{
    /** $ctx: config, fileConfig, stateDir, service, run, profile, root, transcript, metricsDir, now, lib. @return array the draft record */
    public static function run(array $opt, array $ctx)
    {
        foreach (['Update', 'HermesConfig', 'HermesInstall', 'Detect', 'Quota', 'MetricsLog', 'DraftStore'] as $c) {
            require_once $ctx['lib'] . "/$c.php";
        }
        require_once dirname(__DIR__) . '/feedback/FeedbackDraft.php';
        require_once dirname(__DIR__) . '/feedback/FeedbackSend.php';
        $by = isset($opt['by']) ? (string) $opt['by'] : 'agent';
        if (!in_array($by, ['agent', 'user'], true)) {
            throw new CliError('--by takes agent or user', 'agent: you decided to ask for help; user: the user asked for a ticket');
        }
        $read = function ($name, $max) use ($opt) {
            $f = isset($opt[$name]) && is_string($opt[$name]) ? $opt[$name] : '';
            if ($f === '' || !is_file($f) || is_link($f) || filesize($f) > $max) {
                throw new CliError("--$name must name a plain file of at most $max bytes", 'write it with your file tool first');
            }
            return (string) file_get_contents($f);
        };
        $subject = TicketBundle::subject(isset($opt['subject']) && is_string($opt['subject']) ? $opt['subject'] : '');
        $diagnosis = $read('diagnosis-file', 200000);
        $sections = TicketBundle::sections($diagnosis);
        $roadblocks = TicketNeeds::parse(isset($sections['roadblocks']) ? $sections['roadblocks'] : '');
        $missing = array_values(array_filter($by === 'agent' ? TicketBundle::SECTIONS : ['problem'], function ($s) use ($sections) {
            return !isset($sections[$s]) || $sections[$s] === '';
        }));
        if ($missing || ($by === 'agent' && !$roadblocks)) {
            throw new CliError('the diagnosis file needs these "## " sections with text: ' . implode(', ', $missing ?: ['roadblocks (as "- <kind>: <why>" bullets)']),
                'sections: Problem, Error, Impact, Tried, Roadblocks (see the skill); a ticket the user asked for needs only Problem');
        }
        $review = $by === 'agent' || isset($opt['review-file']) ? $read('review-file', 100000) : null;
        if ($by === 'agent' && !TicketBundle::reviewComplete($review)) {
            throw new CliError('the review file needs ten numbered rounds, 1. to 10., one line each with its result', 'doctrine/SOP/ask-vainamoinen-for-help.md lists the ten angles');
        }
        $md = null;
        $why = 'the user left the conversation out';
        if (empty($opt['no-conversation'])) {
            try {
                $md = call_user_func($ctx['transcript']);
            } catch (CliError $e) {
                $why = $e->getMessage();
            }
        }
        $searches = $md !== null ? TicketSearches::fromExport($md) : TicketSearches::fromMetrics($ctx['metricsDir'], $ctx['now']);
        $source = $md !== null ? 'the tool calls in this session\'s transcript' : 'the memory search metrics of the last 24 hours (no transcript)';
        if ($by === 'agent') {
            TicketSearches::check($searches, TicketSearches::required(Config::dotted($ctx['config'], 'web.search') === true, is_dir(dirname(__DIR__, 2) . '/development/upstream')));
        }
        $auto = $by === 'agent' && TicketAuto::on($ctx['fileConfig']);
        $profile = call_user_func($ctx['profile']);
        $real = TicketCollect::privilege(call_user_func($ctx['root']));
        // A ticket nobody reads before it goes, and any ticket from a Pulsed Media server (other customers' processes and logs
        // there), carries this account's own processes and files only, whatever the privilege.
        $skip = $auto ? 'automatic ticket' : (ConfigureTiers::onPmss() ? 'Pulsed Media server' : '');
        $priv = $skip !== '' ? 'none' : $real;
        $euid = TicketAuto::euid();
        // As root, HOME may have come from the calling account (sudo -E): the home directory is root's own, from passwd.
        $pw = $euid === 0 && function_exists('posix_getpwuid') ? posix_getpwuid(0) : false;
        $home = is_array($pw) && isset($pw['dir']) ? (string) $pw['dir'] : (string) getenv('HOME');
        $uid = isset($profile['uid']) ? (int) $profile['uid'] : $euid;
        $facts = TicketCollect::facts($profile, $real, TicketCollect::addresses(isset($profile['identity']['primaryIp']) ? $profile['identity']['primaryIp'] : Detect::primaryIp()),
            Quota::fields($home), FeedbackSend::versions());
        if ($skip !== '' && $real !== 'none') {
            $facts['Privilege'] .= " (system-wide reads skipped: $skip)";
        }
        // As root, "this account's processes" are the system's: an automatic or Pulsed Media bundle then carries none at all.
        $procs = $skip !== '' && $euid === 0 ? ['not collected', 'not collected: Pikkumies runs as root, and this ticket carries no system-wide reads (' . $skip . ')', 0]
            : TicketCollect::processes($priv, $uid, $ctx['run']);
        $named = isset($opt['log']) && is_string($opt['log']) ? array_values(array_filter(array_map('trim', explode(',', $opt['log'])), 'strlen')) : [];
        $logs = TicketCollect::logs($named, $home, $uid, $priv, $profile, Config::localDir(), $ctx['run']);
        list($transcript, $older) = TicketBundle::transcriptFile($md, $why, $ctx['now']);
        $n = TicketBundle::NAMES;
        $files = [$n[0] => TicketBundle::systemFile($facts, $procs, $logs, $ctx['now']),
            $n[1] => TicketBundle::diagnosisFile($diagnosis, $review, TicketSearches::markdown($searches, $source), $ctx['now']), $n[2] => $transcript];
        if ($auto) {
            // Nobody reads this one before it goes: long key-like runs go too, in every file (the search log in 02 quotes
            // tool output, and the agent may quote a file in its diagnosis).
            foreach ($n as $name) {
                $files[$name] = TicketBundle::scrubTokens($files[$name]);
            }
        }
        $box = [$facts['Hostname'], 'IP ' . $facts['IP addresses'], 'service user ' . $facts['Service user'], 'PMSS ' . $facts['Pulsed Media server (PMSS)'],
            'privilege ' . $real . ($skip !== '' && $real !== 'none' ? ' (not used)' : '')];
        $counts = TicketSearches::counts($searches);
        $message = TicketBundle::message($by, $box, $sections, $roadblocks, $counts, [
            $n[0] => 'system facts, the process list (' . $procs[0] . '), ' . count($logs) . ' logs',
            $n[1] => 'the full diagnosis' . ($review !== null ? ', the 10-round review' : '') . ', every search with its keywords and matches',
            $n[2] => $md === null ? 'no transcript (' . $why . ')' : 'the conversation with its tool calls' . ($older ? ' (its newest part)' : '')], $auto);
        $bundle = ['process_scope' => $procs[0], 'privilege' => $real, 'scrubbed' => $auto, 'signature' => TicketBundle::signature($subject),
            'venues' => $counts, 'needs' => array_values(array_unique(array_column($roadblocks, 0))), 'review_rounds' => $review === null ? 0 : 10,
            'transcript' => $md === null ? 'none' : ($older ? 'newest part' : 'whole'), 'logs' => count($logs)];
        $rec = self::save($subject, $message, $files, $opt, $ctx, $by, $bundle);
        $p = TicketDraft::load($rec['id'], $ctx['stateDir'])[1];
        return $rec + ['by' => $by, 'process_scope' => $procs[0], 'venues' => $counts, 'needs' => $bundle['needs'], 'scrubbed' => $auto,
            'goes_out' => TicketAuto::goesAlone($p, $ctx['fileConfig']) ? 'by itself' : 'after the user says yes'];
    }

    /** The three files written to a fresh private folder, saved through TicketDraft::create (which redacts them), then removed. */
    private static function save($subject, $message, array $files, array $opt, array $ctx, $by, array $bundle)
    {
        $base = TicketDraft::dir($ctx['stateDir']);
        if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) {
            throw new CliError("could not create $base", 'check the disk and the permissions of ~/pikkumies/local/state');
        }
        foreach (glob("$base/bundle-*", GLOB_ONLYDIR) ?: [] as $old) {
            if (!is_link($old) && filemtime($old) < $ctx['now'] - 600) { // left by a killed bundle
                array_map('unlink', glob("$old/*") ?: []);
                @rmdir($old);
            }
        }
        $dir = "$base/bundle-" . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700)) {
            throw new CliError('could not make a private folder for the bundle', 'check the free disk space');
        }
        try {
            $paths = [];
            foreach ($files as $name => $text) {
                if (@file_put_contents("$dir/$name", $text) !== strlen($text) || !@chmod("$dir/$name", 0600)) {
                    throw new CliError('could not write the bundle files', 'check the free disk space');
                }
                $paths[] = "$dir/$name";
            }
            return TicketDraft::create($subject, $message, isset($opt['email']) ? (string) $opt['email'] : null,
                isset($opt['priority']) ? (string) $opt['priority'] : 'Medium', $ctx['service'], $paths, $ctx['now'], $ctx['stateDir'], $by, $bundle);
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
        }
    }
}
