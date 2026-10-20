<?php
/**
 * A support ticket draft (ADR-0089): exactly what will be posted, saved once under local/state/ticket/<id>.json through
 * lib/DraftStore.php (mode 0400, named by its own sha256, never sent twice). Subject, message and text attachments go
 * through lib/Redact.php first. Attachments are files the user named, read when the draft is made, so a file changed
 * after the user saw the draft cannot go out under that yes. The account email is remembered in local/state/ticket/email.
 */
require_once dirname(__DIR__, 3) . '/lib/DraftStore.php';

class TicketDraft
{
    const SUBJECT_MAX = 200;      // characters (the endpoint's limits, docs/SUPPORT-TICKETS.md of the credit service)
    const MESSAGE_MAX = 20000;    // characters
    const FILES_MAX = 3;
    const FILE_MAX = 1048576;     // bytes, each file as sent
    const REQUEST_MAX = 4718592;  // bytes, the whole request (4.5 MiB)
    const PRIORITIES = ['Low', 'Medium', 'High'];
    /** Extension => the bytes the file must start with ('' for text: no NUL byte, secrets removed). */
    const TYPES = ['txt' => '', 'log' => '', 'png' => "\x89PNG\r\n\x1a\n", 'jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'pdf' => '%PDF-'];
    const FIELDS = ['v' => 'integer', 'created_at' => 'integer', 'email' => 'string', 'subject' => 'string', 'message' => 'string',
        'priority' => 'string', 'service' => 'string', 'attachments' => 'array'];

    public static function dir($stateDir = null)
    {
        return $stateDir !== null ? $stateDir : Config::localDir() . '/state/ticket';
    }

    public static function path($id, $stateDir = null, $suffix = '.json')
    {
        return DraftStore::path(self::dir($stateDir), $id, 'ticket', $suffix);
    }

    /**
     * Builds the request and saves it. $files: paths the user named. $email: given now, or null for the remembered one;
     * a given one is remembered once the draft is saved. @return array the record the draft verb prints
     */
    public static function create($subject, $message, $email, $priority, $service, array $files, $now, $stateDir = null)
    {
        $email = $email === null ? self::rememberedEmail($stateDir) : trim((string) $email);
        // A plain address only: a quoted local part could carry quotes and words into the question before sending.
        if (strlen($email) > 254 || !preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/D', $email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CliError('the account email is not an email address', 'ask the user for the email of their Pulsed Media account and pass --email');
        }
        $subject = trim((string) preg_replace('/\s+/u', ' ', Redact::secrets((string) $subject)));
        $message = Redact::secrets(trim((string) $message));
        $p = ['v' => 1, 'created_at' => (int) $now, 'email' => $email, 'subject' => self::text($subject, self::SUBJECT_MAX, 'subject'),
            'message' => self::text($message, self::MESSAGE_MAX, 'message'), 'priority' => self::priority($priority),
            'service' => (string) $service, 'attachments' => []];
        if ($p['service'] !== '' && !preg_match('/^([1-9][0-9]{0,9}|[a-z][a-z0-9_-]{0,31})$/D', $p['service'])) {
            throw new CliError('a service is its number or its username (lowercase letters, digits, _ -), as Pulsed Media accepts it', 'pass --service <name> or --no-service');
        }
        // The endpoint also refuses a message over 40000 bytes once HTML-encoded (its ticket field); Redact already drops control characters.
        if (strlen(htmlspecialchars($p['message'], ENT_QUOTES)) > 40000) {
            throw new CliError('the message is too long once its quotes and < > & signs are encoded (about 40 kB)', 'shorten it, or attach a log file');
        }
        if (count($files) > self::FILES_MAX) {
            throw new CliError('at most ' . self::FILES_MAX . ' files can be attached', 'pick the ones that show the problem');
        }
        foreach ($files as $f) {
            $p['attachments'][] = self::attachment((string) $f);
        }
        $json = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen(self::body($p)) > self::REQUEST_MAX) {
            throw new CliError('the ticket is larger than the 4.5 MiB Pulsed Media takes', 'attach fewer or smaller files');
        }
        list($id, $path) = DraftStore::save(self::dir($stateDir), $json);
        self::rememberEmail($email, $stateDir);
        return ['id' => $id] + self::summary($p, strlen(self::body($p))) + ['path' => $path];
    }

    /** The request body: what the endpoint reads, nothing else (an empty service and no files are left out). */
    public static function body(array $p)
    {
        $b = ['email' => $p['email'], 'subject' => $p['subject'], 'message' => $p['message'], 'priority' => $p['priority']];
        $b += ($p['service'] !== '' ? ['service' => $p['service']] : []) + ($p['attachments'] ? ['attachments' => $p['attachments']] : []);
        return json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array [json, decoded payload] of a draft not yet sent whose content still matches its id. */
    public static function load($id, $stateDir = null)
    {
        list($json, $p) = DraftStore::load(self::dir($stateDir), $id, self::FIELDS, 'ticket');
        foreach ($p['attachments'] as $a) {
            if (!is_array($a) || array_keys($a) !== ['name', 'data'] || !is_string($a['name']) || !is_string($a['data'])) {
                throw new CliError("draft $id is not a ticket draft", 'draft it again');
            }
        }
        return [$json, $p];
    }

    /** What the user sees before saying yes: where it goes, from which email, subject, message start, service, files. */
    public static function summary(array $p, $bytes)
    {
        $start = function_exists('mb_strcut') ? mb_strcut($p['message'], 0, 400, 'UTF-8') : substr($p['message'], 0, 400);
        $files = [];
        foreach ($p['attachments'] as $a) {
            $files[] = ['name' => $a['name'], 'bytes' => strlen((string) base64_decode($a['data'], true))];
        }
        return ['to' => 'Pulsed Media support (pulsedmedia.com)', 'email' => $p['email'], 'subject' => $p['subject'],
            'message_start' => $start . (strlen($start) < strlen($p['message']) ? '…' : ''), 'message_chars' => self::chars($p['message']),
            'priority' => $p['priority'], 'service' => $p['service'], 'attachments' => $files, 'bytes' => (int) $bytes];
    }

    /** @return array the drafts kept here, newest first. */
    public static function all($stateDir = null)
    {
        return DraftStore::all(self::dir($stateDir), function ($id, $p, $f, $sent) {
            return ['id' => $id, 'made' => isset($p['created_at']) ? gmdate('Y-m-d H:i', (int) $p['created_at']) . 'Z' : '',
                'subject' => isset($p['subject']) ? (string) $p['subject'] : '', 'files' => isset($p['attachments']) ? count((array) $p['attachments']) : 0,
                'sent' => $sent];
        });
    }

    /**
     * One file the user named: a regular file of this account (never a symlink), of an allowed type that starts as that
     * type does, at most FILE_MAX bytes; a text file has its common password and key forms removed. Its name is cut to
     * letters, digits, . _ - and space.
     */
    public static function attachment($path)
    {
        clearstatcache(true, $path);
        $st = @lstat($path);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || (int) $st['uid'] !== $uid) {
            throw new CliError("$path is not a file of this account (a symlink, a folder, another account's or missing)",
                'attach only files the user named; copy another account\'s file into the home first');
        }
        // The part after the last slash, not basename(): before PHP 8 basename() follows the locale and drops leading
        // multibyte characters in the C locale ("ä€ö.pdf" became ".pdf" on PHP 7.3).
        $base = substr((string) strrchr('/' . $path, '/'), 1);
        $name = trim(ltrim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base), ' .'));
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw new CliError("$path: only " . implode(', ', array_keys(self::TYPES)) . ' files can be attached', 'save it as one of those, or leave it out');
        }
        $name = strlen($name) > 100 ? substr($name, 0, 99 - strlen($ext)) . ".$ext" : $name;
        $data = $st['size'] > self::FILE_MAX ? false : @file_get_contents($path, false, null, 0, self::FILE_MAX + 1);
        if ($data !== false && self::TYPES[$ext] === '' && strpos($data, "\0") === false) {
            $data = Redact::secrets($data);
        } elseif ($data !== false && (self::TYPES[$ext] === '' || strncmp($data, self::TYPES[$ext], strlen(self::TYPES[$ext])) !== 0)) {
            throw new CliError("$path is not a $ext file inside", 'attach the file as what it is');
        }
        if ($data === false || strlen($data) > self::FILE_MAX) {
            throw new CliError("$path is larger than 1 MiB or could not be read", 'attach a smaller part of it, such as the end of a log');
        }
        return ['name' => $name, 'data' => base64_encode($data)];
    }

    /** The remembered account email, or a refusal telling the agent to ask the user once. */
    public static function rememberedEmail($stateDir = null)
    {
        $f = self::dir($stateDir) . '/email';
        $email = is_file($f) && !is_link($f) ? trim((string) file_get_contents($f, false, null, 0, 1024)) : '';
        if ($email === '') {
            throw new CliError('no account email yet: the ticket needs the email of the Pulsed Media account (the owner, or a user who may open tickets)',
                'ask the user for it once and draft again with --email <address>; it is remembered for the next ticket');
        }
        return $email;
    }

    private static function rememberEmail($email, $stateDir)
    {
        $f = self::dir($stateDir) . '/email';
        if (is_file($f) && !is_link($f) && trim((string) file_get_contents($f)) === $email) {
            return;
        }
        $tmp = "$f.tmp" . getmypid();
        if (@file_put_contents($tmp, "$email\n") === false || !@chmod($tmp, 0600) || !@rename($tmp, $f)) {
            @unlink($tmp);
            throw new CliError('the draft is saved, but the email could not be remembered', 'pass --email again next time');
        }
    }

    private static function text($s, $max, $what)
    {
        $n = self::chars($s);
        if ($n === 0 || $n > $max) {
            throw new CliError("the $what must be 1 to $max characters of text (it is $n)", $what === 'subject' ? 'one short line' : 'shorten it, or attach a log file');
        }
        return $s;
    }

    /** Unicode characters; -1 for text that is not valid UTF-8 (refused). */
    private static function chars($s)
    {
        $n = preg_match_all('/./su', (string) $s);
        return $n === false ? -1 : $n;
    }

    private static function priority($p)
    {
        $p = ucfirst(strtolower(trim((string) $p)));
        if (!in_array($p, self::PRIORITIES, true)) {
            throw new CliError('priority is Low, Medium or High', 'leave it out for Medium');
        }
        return $p;
    }
}
