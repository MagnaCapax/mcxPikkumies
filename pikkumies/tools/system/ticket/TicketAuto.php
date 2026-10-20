<?php
/**
 * TicketAuto (ADR-0100): when a ticket the agent bundled by itself may go out without the user's yes, and the checks
 * around it. ticket.auto unset is on on a Pulsed Media (PMSS) server and off elsewhere (the operator's decision of
 * 2026-10-20); read from local/config.php, never the overlay. The guard lets such a ticket through only when its draft
 * is exactly what `ticket bundle` makes (checkFiles) and carries this account's processes and files only; the send
 * verb then holds one lock over the cap check, the request and its auto.jsonl line, at most AUTO_PER_DAY attempts in
 * 24 hours and never the same subject twice in 24 hours. A ticket the user asked for always waits for their yes.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/ConfigureTiers.php';
require_once dirname(__DIR__, 3) . '/lib/Redact.php';
require_once __DIR__ . '/TicketBundle.php';

class TicketAuto
{
    const AUTO_PER_DAY = 2;
    const SCOPE = 'this account only';

    /** ticket.auto as it applies here: set, or by default on on a Pulsed Media server and off elsewhere. */
    public static function on(array $config)
    {
        $v = Config::dotted($config, 'ticket.auto');
        return $v === null ? ConfigureTiers::onPmss() : $v === true;
    }

    /** The line an automatic ticket's message carries; one bundled while ticket.auto was off does not (it says "approved"). */
    const UNSEEN = 'The account holder has not seen this ticket.';

    /** Test seam: the effective uid instead of posix_geteuid(). */
    public static $euidOverride = null;

    public static function euid()
    {
        return self::$euidOverride !== null ? (int) self::$euidOverride : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
    }

    /** True when the draft claims to be a bundle: it has a bundle record, or carries files under the bundle's names. */
    public static function looksLikeBundle(array $p)
    {
        return $p['bundle'] !== null || array_column($p['attachments'], 'name') === TicketBundle::NAMES;
    }

    /**
     * True when the draft $p goes out without a question. All of: ticket.auto is on now; Pikkumies is not running as root
     * (a root process holds every account's data, and its bundle record is as writable as any other); the draft was
     * bundled by the agent with ticket.auto already on (scrubbed, its message saying the holder has not seen it, so a
     * bundle made for the user's eyes never goes out unasked once auto is turned on); collected without root privilege
     * and for this account only; and its files pass checkFiles with the automatic-ticket pass. The bundle record is the
     * agent's own writing (ADR-0100): these are checks against mistakes, not proof.
     */
    public static function goesAlone(array $p, array $config)
    {
        if ($p['by'] !== 'agent' || !is_array($p['bundle']) || !self::on($config) || self::euid() === 0) {
            return false;
        }
        $b = $p['bundle'];
        if (empty($b['scrubbed']) || strpos($p['message'], "\n" . self::UNSEEN . "\n") === false
            || !isset($b['privilege'], $b['process_scope']) || $b['privilege'] === 'root' || $b['process_scope'] !== self::SCOPE) {
            return false;
        }
        self::checkFiles($p, true);
        return true;
    }

    /**
     * A bundle draft must hold what `ticket bundle` makes, whoever wrote the file (the agent can write local/state):
     * a bundle record, exactly the three attachments, each within 1 MiB, every text already redacted (redacting again
     * changes nothing), and, on the automatic path ($auto) or when the bundle says it was scrubbed, all three files
     * already through the automatic-ticket pass. Refuses with "was not made by bundle".
     */
    public static function checkFiles(array $p, $auto = false)
    {
        $why = '';
        $names = array_map(function ($a) {
            return is_array($a) && isset($a['name']) ? $a['name'] : '';
        }, $p['attachments']);
        if (!is_array($p['bundle']) || $names !== TicketBundle::NAMES) {
            $why = 'it does not hold exactly the three bundle attachments and a bundle record';
        } elseif (strlen($p['message']) > TicketBundle::MESSAGE_MAX || Redact::secrets($p['message']) !== $p['message']) {
            $why = 'its message is longer than a bundle message or holds text that redaction would remove';
        }
        $scrub = $auto || (is_array($p['bundle']) && !empty($p['bundle']['scrubbed']));
        foreach ($why === '' ? $p['attachments'] : [] as $a) {
            $text = base64_decode((string) $a['data'], true);
            if (!is_string($text) || strlen($text) > TicketBundle::FILE_MAX) {
                $why = "{$a['name']} is not readable or over 1 MiB";
            } elseif (Redact::secrets($text) !== $text) {
                $why = "{$a['name']} holds text that redaction would remove";
            } elseif ($scrub && TicketBundle::scrubTokens($text) !== $text) {
                $why = "{$a['name']} holds a long token that the automatic-ticket pass would remove";
            }
            if ($why !== '') {
                break;
            }
        }
        if ($why !== '') {
            throw new CliError("this ticket draft was not made by bundle: $why",
                'bundle it again with the ticket tool; it is never sent otherwise (files of your own: give them other names than the bundle\'s)');
        }
    }

    /**
     * Refuses over the caps: AUTO_PER_DAY attempts, or the same subject sent. An attempt is its last line in auto.jsonl
     * (one per attempt id): it counts when it was sent, may have been (no answer, 429, 5xx), or never finished (-2, the
     * line written before posting with no line after it: a crash or a kill mid-send). -1 (refused before posting) does not.
     */
    public static function checkCaps(array $records, $signature)
    {
        $last = [];
        foreach ($records as $i => $r) {
            $last[isset($r['attempt']) && is_string($r['attempt']) ? $r['attempt'] : "line$i"] = $r;
        }
        $counted = array_filter($last, function ($r) {
            $s = isset($r['status']) ? (int) $r['status'] : 0;
            return $s === 201 || $s === 0 || $s === -2 || $s === 429 || $s >= 500;
        });
        if (count($counted) >= self::AUTO_PER_DAY) {
            throw new CliError('Pikkumies already sent or tried ' . self::AUTO_PER_DAY . ' tickets by itself in the last 24 hours',
                'the draft is kept; to send it now, turn ticket.auto off and send it with the user\'s yes, or wait 24 hours');
        }
        foreach ($last as $r) {
            if (isset($r['status'], $r['signature']) && (int) $r['status'] === 201 && $r['signature'] === $signature) {
                throw new CliError('a ticket with this subject went out by itself in the last 24 hours', 'the draft is kept; add to that ticket instead, or tell the user');
            }
        }
    }
    /** Runs $fn holding <dir>/.auto.lock, so two automatic sends cannot both pass the caps. */
    public static function locked($dir, callable $fn)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new CliError("could not create $dir", 'check the disk and the permissions of ~/pikkumies/local/state');
        }
        $h = @fopen("$dir/.auto.lock", 'c');
        if ($h === false || !flock($h, LOCK_EX)) {
            throw new CliError('could not lock the ticket state', 'check the permissions of ~/pikkumies/local/state/ticket');
        }
        try {
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /**
     * One line in auto.jsonl: an automatic attempt's id, attempt id, time, status (-2 before posting, then the HTTP status,
     * 0 for no answer, -1 refused before posting), ticket number and subject signature; never the text.
     */
    public static function record($dir, array $rec)
    {
        $line = json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $ok = @file_put_contents("$dir/auto.jsonl", $line, FILE_APPEND | LOCK_EX) === strlen($line);
        @chmod("$dir/auto.jsonl", 0600);
        return $ok;
    }

    /** The automatic attempts since $since. */
    public static function records($dir, $since)
    {
        $out = [];
        foreach (@file("$dir/auto.jsonl", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $r = json_decode($l, true);
            if (is_array($r) && isset($r['ts']) && (int) $r['ts'] >= $since) {
                $out[] = $r;
            }
        }
        return $out;
    }
}
