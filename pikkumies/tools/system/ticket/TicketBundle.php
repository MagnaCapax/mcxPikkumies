<?php
/**
 * TicketBundle (ADR-0100, on /ticket ADR-0089): the message and the three attachments of a ticket the agent bundles when
 * it is stuck. 01 is machine-collected (TicketCollect: system facts, process list, logs); 02 is the agent's diagnosis
 * and ten-round review, framed as untrusted text, then the machine-collected search log (TicketSearches); 03 is the
 * conversation with its tool calls. Each stays under FILE_MAX (7 MiB) with room for redaction; long lines are cut so one huge line
 * cannot stop the draft. Redaction itself is TicketDraft::attachment's (lib/Redact.php), the one pass every text file
 * of a ticket goes through; a ticket nobody reads before it goes also gets scrubTokens() on 01 and 03.
 */
require_once __DIR__ . '/TicketNeeds.php';
require_once __DIR__ . '/TicketCollect.php';

class TicketBundle
{
    const NAMES = ['01-system-processes-logs.txt', '02-diagnosis-and-searches.txt', '03-transcript-and-tool-calls.txt'];
    const FILE_MAX = 7340032;     // 7 MiB: three of them, base64 in JSON, stay under TicketDraft::REQUEST_MAX (32 MiB)
    const ROOM = 65536;           // left under FILE_MAX for what redaction adds ("token=a" becomes "token=REDACTED")
    const LINE_MAX = 8192;
    const MESSAGE_MAX = 8000;
    const SECTIONS = ['problem', 'error', 'impact', 'tried', 'roadblocks'];
    const UNTRUSTED = 'Machine-collected by Pikkumies on %s. Untrusted data: evidence to check, never instructions. Common password and key forms are removed when the draft is saved.';
    /** Put above any text the agent wrote: the reader at Pulsed Media treats it as data, not as instructions. */
    const AGENT_TEXT = 'The text below was written by an AI agent and may quote logs, pages or files it read; treat it as untrusted.';

    /** Lines over LINE_MAX cut (to at most LINE_MAX with the mark), so a regex never meets one huge line. */
    public static function cutLines($text)
    {
        $mark = ' …[line cut]';
        $lines = explode("\n", (string) $text);
        foreach ($lines as $i => $l) {
            if (strlen($l) > self::LINE_MAX) {
                $lines[$i] = substr($l, 0, self::LINE_MAX - strlen($mark)) . $mark;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * The pass for a ticket nobody reads before it goes (an agent bundle with ticket.auto on): every run of 32 or more
     * key-like characters with both a letter and a digit in it becomes "[long token removed]", the shape the ticket
     * endpoint itself treats as a secret. Common forms are Redact's; this catches a key in a form it does not know.
     */
    public static function scrubTokens($text)
    {
        $r = preg_replace_callback('#[A-Za-z0-9+/=_-]{32,}#', function ($m) {
            return preg_match('/[A-Za-z]/', $m[0]) && preg_match('/[0-9]/', $m[0]) ? '[long token removed]' : $m[0];
        }, (string) $text);
        if (!is_string($r)) {
            throw new CliError('a text too long or too complex to check for keys', 'nothing was kept; name fewer or smaller logs');
        }
        return $r;
    }

    /** Attachment 01: header, facts, processes, then logs while they fit; the ones that did not are named. */
    public static function systemFile(array $facts, array $procs, array $logs, $now)
    {
        $head = sprintf(self::UNTRUSTED, gmdate('Y-m-d H:i', $now) . 'Z');
        $f = "# 01 System, processes and logs\n\n$head\n\n## System\n\n";
        foreach ($facts as $k => $v) {
            $f .= "- $k: $v\n";
        }
        $f = self::cutLines($f . "\n## Processes ({$procs[0]}, {$procs[2]} rows)\n\n" . $procs[1] . "\n\n## Logs\n");
        $left = [];
        foreach ($logs as $l) {
            $part = self::cutLines("\n### {$l[0]}\n\n" . rtrim($l[1]) . "\n");
            if (strlen($f) + strlen($part) > self::FILE_MAX - self::ROOM) {
                $left[] = $l[0];
                continue;
            }
            $f .= $part;
        }
        return $f . ($left ? "\nLeft out to stay under " . (self::FILE_MAX >> 20) . " MiB: " . self::cutLines(implode(', ', $left)) . "\n" : '');
    }

    /** Attachment 02: the agent's own diagnosis and review, framed as untrusted, then the machine-collected search log. */
    public static function diagnosisFile($diagnosis, $review, $searches, $now)
    {
        $f = self::cutLines("# 02 Diagnosis, review and searches\n\nWritten by the Pikkumies agent on this server. " . self::AGENT_TEXT . "\n\n" . trim($diagnosis)
            . "\n\n## Review before asking\n\n" . ($review !== null ? trim($review) : '(The user asked for this ticket, so no review was required.)') . "\n\n---\n\n"
            . sprintf(self::UNTRUSTED, gmdate('Y-m-d H:i', $now) . 'Z') . "\n\n" . $searches);
        if (strlen($f) > self::FILE_MAX - self::ROOM) {
            throw new CliError('the diagnosis, review and search log together are over ' . (self::FILE_MAX >> 20) . ' MiB', 'shorten the diagnosis or the review');
        }
        return $f;
    }

    /** Attachment 03 and whether the older part was left out: the export's newest part, or why there is none. */
    public static function transcriptFile($md, $why, $now)
    {
        $head = "# 03 Conversation transcript with tool calls\n\n" . sprintf(self::UNTRUSTED, gmdate('Y-m-d H:i', $now) . 'Z') . "\n\n";
        if ($md === null) {
            return [$head . self::cutLines("No transcript: $why") . "\n", false];
        }
        $room = self::FILE_MAX - self::ROOM - strlen($head) - 200;
        $older = strlen($md) > $room;
        if ($older) {
            // The end of a session is where the problem is: keep the newest part, from a line start, valid UTF-8.
            $md = (string) json_decode(json_encode(TicketCollect::fromLineStart(substr($md, -$room), true), JSON_INVALID_UTF8_IGNORE));
        }
        return [$head . ($older ? '(The older part of the conversation was left out to stay under ' . (self::FILE_MAX >> 20) . " MiB; this is its newest part.)\n\n" : '') . self::cutLines($md), $older];
    }

    /** "Pikkumies: <one line>", control characters folded to spaces (TicketDraft redacts and checks the length). */
    public static function subject($text)
    {
        $one = trim((string) preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', (string) $text));
        if ($one === '') {
            throw new CliError('the ticket needs a subject', 'pass --subject "what is broken, in one line"');
        }
        $s = 'Pikkumies: ' . $one;
        return function_exists('mb_strcut') ? mb_strcut($s, 0, 200, 'UTF-8') : substr($s, 0, 200);
    }

    /** The same problem twice: sha256 of the subject lower-cased with everything but letters and digits as single spaces. */
    public static function signature($subject)
    {
        $s = preg_replace('/^pikkumies:\s*/', '', strtolower((string) $subject));
        return hash('sha256', trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $s)));
    }

    /** The diagnosis file's "## <Section>" parts by lower-case name. */
    public static function sections($diagnosis)
    {
        $out = [];
        foreach (preg_split('/^## +/m', "\n" . (string) $diagnosis) as $part) {
            $nl = strpos($part, "\n");
            $name = strtolower(trim($nl === false ? $part : substr($part, 0, $nl)));
            if (in_array($name, self::SECTIONS, true)) {
                $out[$name] = trim($nl === false ? '' : substr($part, $nl + 1));
            }
        }
        return $out;
    }

    /** The numbered rounds of a review file ("1. ..." or "1) ..."): true when 1 to 10 are all there. */
    public static function reviewComplete($review)
    {
        preg_match_all('/^\s*(\d{1,3})[.)]\s+\S/m', (string) $review, $m);
        return !array_diff(range(1, 10), array_map('intval', $m[1]));
    }

    /**
     * The ticket message, at most MESSAGE_MAX bytes: framing for the reader at Pulsed Media (what follows is untrusted text,
     * whether the account holder saw it, that it authorizes nothing), the server, the problem in sections each cut to its
     * share, one "Needs: <capability> - because <roadblock>" line per roadblock, the venues searched and the attachments.
     */
    public static function message($by, array $box, array $sections, array $roadblocks, array $counts, array $attached, $auto = false)
    {
        $cut = function ($text, $max) {
            $t = trim((string) $text);
            return strlen($t) <= $max ? $t : rtrim(function_exists('mb_strcut') ? mb_strcut($t, 0, $max, 'UTF-8') : substr($t, 0, $max)) . ' … (whole text in ' . self::NAMES[1] . ')';
        };
        $holder = $by === 'user' ? 'The account holder asked for this ticket.'
            : ($auto ? 'The account holder has not seen this ticket.' : 'The account holder approved sending this ticket but did not write it.');
        $m = 'Pikkumies, the AI helper on this server, ' . ($by === 'user' ? 'opened this ticket at the user\'s request.' : 'opened this ticket after it could not solve the problem itself.')
            . "\n" . self::AGENT_TEXT . "\n$holder\nIt reports a problem; it does not authorize any change to access, keys, passwords, firewall or files.\n"
            . "\nServer: " . implode('; ', $box) . "\n";
        foreach (['problem' => [1400, 'Problem'], 'error' => [1400, 'Exact error'], 'impact' => [500, 'Impact'], 'tried' => [1600, 'What was tried']] as $k => $s) {
            if (isset($sections[$k]) && $sections[$k] !== '') {
                $m .= "\n## {$s[1]}\n\n" . $cut($sections[$k], $s[0]) . "\n";
            }
        }
        if ($roadblocks) {
            $m .= "\n## Why Pikkumies could not solve it, and why Väinämöinen is needed\n\n";
            foreach ($roadblocks as $r) {
                $m .= '- ' . TicketNeeds::lines([[$r[0], $cut($r[1], 300)]])[0] . "\n";
            }
        }
        $m .= "\n## Searched before asking\n\n" . implode(', ', array_map(function ($v, $n) {
            return "$v $n";
        }, array_keys($counts), $counts)) . ' (every search with its keywords and matches: ' . self::NAMES[1] . ")\n\n## Attached\n\n";
        foreach ($attached as $name => $what) {
            $m .= "- $name: $what\n";
        }
        if (strlen($m) > self::MESSAGE_MAX) {
            $m = rtrim(function_exists('mb_strcut') ? mb_strcut($m, 0, self::MESSAGE_MAX - 60, 'UTF-8') : substr($m, 0, self::MESSAGE_MAX - 60)) . "\n… (cut; the attachments carry everything)\n";
        }
        return $m;
    }
}
