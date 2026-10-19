<?php
/**
 * The email rules of the guard (ADR-0053, R15), loaded by guard-destructive.php. A safety prompt, like the rest of the
 * guard: the OS is the boundary. Order:
 * - Any email `login` is blocked: the user types logins in their own terminal (a terminal the agent opens proves no human).
 * - An smtp command with shell expansion or chaining ($ ` * ? [ { ~ ; | & < > \ or a line break outside single quotes)
 *   is blocked, so the command the user approves is the command that runs.
 * - `send-draft <sha>` asks the user, showing what the draft file holds: From, every recipient, Subject, each
 *   attachment's path and size, and as much of the body as fits the approval panel, up to 1 KiB, stating the real
 *   excerpt length and the body's total size (ADR-0020 2026-10-19 amendment: the panel shows at most
 *   HookUnits::APPROVE_SHOWN characters, so a fixed 1 KiB excerpt refused most real drafts); rule_key
 *   pikkumies-mail:<sha>. If even From/To/Subject/Attachments alone, with no body shown, does not fit, the send is
 *   refused instead of half-shown. The unit re-hashes the draft before sending, so it sends exactly what was shown.
 *   A missing or changed draft is blocked.
 * - smtp `test` asks too (it sends real mail, to the user's own first address).
 * Test seam: PIKKUMIES_MAIL_OUTBOX names the outbox directory (default local/state/email-outbox).
 */

/** The guard's answer for an email command, or null for no opinion. $texts: the command's forms (raw, unquoted, folded). */
function guardMail($command, array $texts)
{
    $hit = function ($re) use ($texts) {
        foreach ($texts as $t) {
            if (preg_match($re, $t)) {
                return true;
            }
        }
        return false;
    };
    $word = '(?=[\s"\',\])]|$)';
    $email = $hit('#tools/email/|\b(imap|pop|smtp|antispam)\.php\b#');
    if ($email && $hit('/(^|[\s"\',\[(])login' . $word . '/')) {
        return ['action' => 'block', 'message' => 'Pikkumies safety: email logins are typed by the user in their own terminal, never run by the agent. Not run. Tell the user the command to run.'];
    }
    if (!$hit('#\bsmtp\.php\b|tools/email/smtp\b|(^|[\s"\',\[(])send-(draft|self)' . $word . '#')) {
        return null;
    }
    $outside = preg_replace("/'[^']*'/", '', $command);
    if (preg_match('/[$`*?\[{~;|&<>\\\\\r\n\']/', (string) $outside)) {
        return ['action' => 'block', 'message' => 'Pikkumies safety: an smtp command with shell expansion, quoting tricks or chaining ($ ` * ? [ { ~ ; | & < > \\). Not run. Run it alone with the full script path and plain arguments; put text with such characters in single quotes or in the body file.'];
    }
    $id = substr(sha1($command), 0, 16);
    if ($hit('#smtp\.php\s+(--\S+\s+)*test' . $word . '#')) {
        $approveMessage = "Pikkumies: sends a test email to your own first address from the smtp login, which cannot be recalled. Command [$id]: $command";
        if (!HookUnits::approveShown($approveMessage)) {
            return ['action' => 'block', 'message' => 'Pikkumies safety: the smtp test command is too long to show the user in full. Not run.'];
        }
        return ['action' => 'approve', 'rule_key' => 'pikkumies-mail:' . $id, 'message' => $approveMessage];
    }
    if (!$hit('/send-draft' . $word . '/')) {
        return null; // send writes a draft only; send-self goes only to the user's own addresses
    }
    $sha = '';
    foreach ($texts as $t) {
        if ($sha === '' && preg_match('/send-draft\s+([0-9a-f]{64})' . $word . '/', $t, $m)) {
            $sha = $m[1];
        }
    }
    $dir = (string) getenv('PIKKUMIES_MAIL_OUTBOX') !== '' ? (string) getenv('PIKKUMIES_MAIL_OUTBOX') : dirname(__DIR__, 3) . '/local/state/email-outbox';
    $file = "$dir/$sha.json";
    $json = $sha !== '' && is_file($file) && !is_link($file) ? (string) @file_get_contents($file, false, null, 0, 2097152) : '';
    $d = $json !== '' && hash_equals($sha, hash('sha256', $json)) ? json_decode($json, true) : null;
    if (!is_array($d) || !isset($d['from'], $d['to'], $d['subject'], $d['body']) || !is_array($d['to'])) {
        return ['action' => 'block', 'message' => 'Pikkumies safety: send-draft names no readable, unchanged draft (64 hex characters from send). Not run. Write the draft again with send.'];
    }
    $clean = function ($s) {
        $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', ' ', (string) $s);
        return (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', (string) $s);
    };
    $files = [];
    foreach (isset($d['attachments']) && is_array($d['attachments']) ? $d['attachments'] : [] as $a) {
        $files[] = $clean(isset($a['path']) ? $a['path'] : '?') . ' (' . (isset($a['bytes']) ? (int) $a['bytes'] : 0) . ' bytes)';
    }
    $head = 'Pikkumies: sends an email, which cannot be recalled. From: ' . $clean($d['from']) . '. To (' . count($d['to']) . '): '
        . $clean(implode(', ', $d['to'])) . '. Subject: ' . $clean($d['subject']) . '. Attachments: ' . ($files ? implode('; ', $files) : 'none') . '.';
    // No [id] label here (unlike every other guard rule's "Command [id]: ..."): the id those use is a hash of the
    // command for the rule_key log, shown because it is otherwise invisible; send-draft's id IS the sha the command
    // already names in full ("send-draft <sha>"), so repeating 16 hex of it a second time only cost characters the
    // tight budget below cannot spare.
    $tail = "\n$command";
    // From/To/Subject/Attachments and the command itself (never shortened) are the floor: if even this does not
    // fit, no amount of trimming the body helps, and the user cannot see who or what the mail goes to.
    $bodyBytes = strlen($d['body']);
    $headOnly = $head . " Body: $bodyBytes bytes, not shown." . $tail; // the user always learns there is a body
    if (!HookUnits::approveShown($headOnly)) {
        return ['action' => 'block', 'message' => 'Pikkumies safety: the draft is too long to show the user in full, even without the body. Not run. Send to fewer recipients, or attach fewer files.'];
    }
    // The excerpt is cut on a byte count that mb_strcut rounds down to a whole character (never a split one), so
    // the only thing that changes with a smaller cut is how much of the body shows, never a broken character.
    $excerptFor = function ($cut) use ($d, $clean, $bodyBytes, $head, $tail) {
        $text = $cut > 0 ? $clean($cut >= $bodyBytes ? $d['body'] : (function_exists('mb_strcut') ? mb_strcut($d['body'], 0, $cut, 'UTF-8') : substr($d['body'], 0, $cut))) : '';
        $shown = HookUnits::charCount($text);
        return $head . " Body ($bodyBytes bytes), first $shown characters:\n$text" . $tail;
    };
    // The excerpt (and what it costs once shown) only grows as the cut grows, so the largest cut that still fits is
    // found by halving the search, same as any other find-the-boundary search over a monotonic condition.
    $lo = 0;
    $hi = min(1024, $bodyBytes);
    $best = null; // null: not even a 0-character excerpt's own label fit; $headOnly (body size, no text) stands
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        $candidate = $excerptFor($mid);
        if (HookUnits::approveShown($candidate)) {
            $best = $candidate;
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }
    return ['action' => 'approve', 'rule_key' => 'pikkumies-mail:' . $sha, 'message' => $best !== null ? $best : $headOnly];
}
