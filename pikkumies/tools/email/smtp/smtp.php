<?php
/**
 * smtp: sends mail as the user through their own relay, TLS only (README.md here, ADR-0053, R15). `send` only writes a
 * draft; `send-draft <sha>` sends it, and the guard first shows the user what the draft holds and asks. `send-self` and
 * `test` go only to the user's own addresses, the allowlist stored with the login, which only `login` changes. From is
 * always the login's address. Test seams as in tools/email/imap/imap.php.
 */
require_once dirname(__DIR__) . '/lib/MailUnit.php';
require_once __DIR__ . '/SmtpDraft.php';
require_once __DIR__ . '/SmtpMime.php';

$emailSecretDir = isset($emailSecretDir) ? $emailSecretDir : Config::localDir() . '/secrets';
$emailStateDir = isset($emailStateDir) ? $emailStateDir : Config::localDir() . '/state';
$emailPrompt = isset($emailPrompt) ? $emailPrompt : ['SecretFile', 'prompt'];
$outbox = rtrim($emailStateDir, '/') . '/email-outbox';
// --attach may be given more than once; Cli keeps only the last value, so the list is read from argv here.
$attach = [];
foreach ($argv as $i => $a) {
    if ($a === '--attach' && isset($argv[$i + 1])) {
        $attach[] = $argv[$i + 1];
    } elseif (strpos($a, '--attach=') === 0) {
        $attach[] = substr($a, 9);
    }
}

/** Sends a checked draft with the stored login; From must still be the login's address. */
function smtpSend(array $login, array $d, $home)
{
    if (strtolower($d['from']) !== strtolower($login['from'])) {
        throw new CliError('the draft is from ' . $d['from'] . ' but the login now sends as ' . $login['from'] . '; nothing sent', 'write the draft again with send');
    }
    $mime = SmtpMime::build($d, $home);
    MailLogin::transport($login)->request('/', ['upload' => $mime, 'from' => $d['from'], 'rcpt' => $d['to']]);
    return ['sent' => true, 'from' => $d['from'], 'to' => $d['to'], 'subject' => $d['subject'], 'bytes' => strlen($mime)];
}

/** A word outside an option is usually a subject that was not quoted: refused rather than dropped. */
function smtpNoStray(array $args)
{
    if ($args) {
        throw new CliError('unexpected words: ' . implode(' ', $args) . '; nothing written', "quote the subject in single quotes, e.g. --subject 'Hello there'");
    }
}

function smtpLogin($dir)
{
    $login = MailLogin::need($dir, 'smtp');
    if (!isset($login['from'], $login['allowlist']) || !is_array($login['allowlist']) || !$login['allowlist']) {
        throw new CliError('the smtp login has no From address or allowlist', 'the user runs `smtp login` again in their own terminal');
    }
    return $login;
}

$usage = <<<'TXT'
usage: php smtp.php <verb> [arguments] [--human]
  login                                       the user types relay, port, user, password, From and own addresses at a terminal
  status                                      the stored login: host, port, user, From and own addresses, never the password
  send --to A[,B] --subject S --body-file F [--attach F]...   writes a draft and prints its id; sends nothing
  send-draft <id>                             sends that draft (the user is shown it and asked)
  send-self --subject S --body-file F [--attach F]...   sends to the user's own first address, no question
  test                                        sends a short test message to the user's own first address
TXT;

Cli::run($argv, [
    'login' => function ($args, $opt) use ($emailSecretDir, $emailPrompt) {
        $fields = MailLogin::prompt('smtp', $emailPrompt);
        $ask = function ($label) use ($emailPrompt) {
            return trim((string) call_user_func($emailPrompt, $label, 'login reads from a terminal, and stdin is not one', 'the user runs smtp login in their own terminal', false));
        };
        $from = $ask('From address [' . $fields['user'] . ']: ');
        $fields['from'] = SmtpDraft::address($from === '' ? $fields['user'] : $from);
        $own = $ask('your own addresses that send-self and test may write to, comma separated [' . $fields['from'] . ']: ');
        $fields['allowlist'] = SmtpDraft::addresses($own === '' ? $fields['from'] : $own);
        $saved = MailLogin::verifyAndSave($emailSecretDir, 'smtp', $fields, function (MailTransport $t) {
            $t->request('/', ['custom' => 'NOOP', 'nobody' => true]);
        });
        return $saved + ['from' => $fields['from'], 'allowlist' => $fields['allowlist']];
    },
    'status' => function ($args, $opt) use ($emailSecretDir) {
        $login = MailLogin::load($emailSecretDir, 'smtp');
        return MailLogin::status($login) + ($login !== null ? ['from' => isset($login['from']) ? $login['from'] : null,
            'allowlist' => isset($login['allowlist']) ? $login['allowlist'] : []] : []);
    },
    'send' => function ($args, $opt) use ($emailSecretDir, $outbox, $attach) {
        smtpNoStray($args);
        $login = smtpLogin($emailSecretDir);
        foreach (['to', 'subject', 'body-file'] as $k) {
            if (!isset($opt[$k]) || !is_string($opt[$k])) {
                throw new CliError("send needs --$k; nothing written", 'e.g. send --to a@example.org --subject Hello --body-file /home/you/letter.txt');
            }
        }
        $spec = SmtpDraft::spec($login['from'], $opt['to'], $opt['subject'], $opt['body-file'], $attach, HomePath::home());
        $sha = SmtpDraft::write($outbox, $spec);
        return ['draft' => $sha, 'from' => $spec['from'], 'to' => $spec['to'], 'subject' => $spec['subject'],
            'attachments' => array_map(function ($a) {
                return ['path' => $a['path'], 'bytes' => $a['bytes']];
            }, $spec['attachments']), 'body_bytes' => strlen($spec['body']), 'next' => 'php ' . __FILE__ . " send-draft $sha"];
    },
    'send-draft' => function ($args, $opt) use ($emailSecretDir, $outbox) {
        $home = HomePath::home();
        $sha = isset($args[0]) ? $args[0] : '';
        $d = SmtpDraft::load($outbox, $sha, $home);
        $sent = smtpSend(smtpLogin($emailSecretDir), $d, $home);
        @mkdir("$outbox/sent", 0700);
        @rename("$outbox/$sha.json", "$outbox/sent/$sha.json"); // kept as the record of what was sent
        return $sent + ['draft' => $sha];
    },
    'send-self' => function ($args, $opt) use ($emailSecretDir, $attach) {
        smtpNoStray($args);
        if (isset($opt['to'])) {
            throw new CliError('send-self takes no --to: it sends only to your own first address; nothing sent', 'use send for other recipients');
        }
        $login = smtpLogin($emailSecretDir);
        foreach (['subject', 'body-file'] as $k) {
            if (!isset($opt[$k]) || !is_string($opt[$k])) {
                throw new CliError("send-self needs --$k; nothing sent", 'e.g. send-self --subject Report --body-file /home/you/report.txt');
            }
        }
        $home = HomePath::home();
        return smtpSend($login, SmtpDraft::spec($login['from'], [$login['allowlist'][0]], $opt['subject'], $opt['body-file'], $attach, $home), $home);
    },
    'test' => function ($args, $opt) use ($emailSecretDir) {
        $login = smtpLogin($emailSecretDir);
        $d = ['from' => $login['from'], 'to' => [SmtpDraft::address($login['allowlist'][0])], 'subject' => 'Pikkumies test message',
            'body' => "This is a test message from Pikkumies. Sending works.\n", 'attachments' => []];
        return smtpSend($login, $d, HomePath::home());
    },
], $usage, [], ['to', 'subject', 'body-file', 'attach']);
