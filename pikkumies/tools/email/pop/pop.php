<?php
/**
 * pop: the user's own POP3 mailbox over TLS (port 995; README.md here, ADR-0053). Lists messages, downloads new ones
 * into a Maildir (by UIDL, so each message once; never deletes on the server), and deletes exact messages after
 * checking their UIDL (the guard asks the user). Test seams as in tools/email/imap/imap.php.
 */
require_once dirname(__DIR__) . '/lib/MailUnit.php';

$emailSecretDir = isset($emailSecretDir) ? $emailSecretDir : Config::localDir() . '/secrets';
$emailStateDir = isset($emailStateDir) ? $emailStateDir : Config::localDir() . '/state';
$emailPrompt = isset($emailPrompt) ? $emailPrompt : ['SecretFile', 'prompt'];
const POP_MAX = 52428800;

/** [n => ['size' => int, 'uidl' => string|null]] from LIST and UIDL. */
function popMessages(MailTransport $t)
{
    $out = [];
    foreach (preg_split('/\r?\n/', $t->request('/', ['max' => 8388608])) as $line) {
        if (preg_match('/^(\d+) (\d+)/', $line, $m)) {
            $out[(int) $m[1]] = ['size' => (int) $m[2], 'uidl' => null];
        }
    }
    foreach (preg_split('/\r?\n/', $t->request('/', ['custom' => 'UIDL', 'max' => 8388608])) as $line) {
        if (preg_match('/^(\d+) ([\x21-\x7E]{1,70})$/', $line, $m) && isset($out[(int) $m[1]])) {
            $out[(int) $m[1]]['uidl'] = $m[2];
        }
    }
    return $out;
}

function popNumber($n)
{
    if (!preg_match('/^[1-9][0-9]{0,8}\z/', (string) $n)) {
        throw new CliError('not a message number: ' . Cli::cleanString((string) $n), 'use the n that list printed');
    }
    return (int) $n;
}

$usage = <<<'TXT'
usage: php pop.php <verb> [arguments] [--human]
  login                            the user types server, user name and password at a terminal
  status                           the stored login: host and user, never the password
  list                             message numbers, sizes and UIDLs
  fetch --to MAILDIR [--limit N]   downloads new messages into a Maildir; never deletes on the server
  delete <n>... --expect UIDL,...  deletes exactly these messages (the user is asked)
TXT;

Cli::run($argv, [
    'login' => function ($args, $opt) use ($emailSecretDir, $emailPrompt) {
        return MailLogin::verifyAndSave($emailSecretDir, 'pop', MailLogin::prompt('pop', $emailPrompt), function (MailTransport $t) {
            $t->request('/', ['custom' => 'NOOP', 'nobody' => true]);
        });
    },
    'status' => function ($args, $opt) use ($emailSecretDir) {
        return MailLogin::status(MailLogin::load($emailSecretDir, 'pop'));
    },
    'list' => function ($args, $opt) use ($emailSecretDir) {
        $records = [];
        foreach (popMessages(MailLogin::transport(MailLogin::need($emailSecretDir, 'pop'))) as $n => $m) {
            $records[] = ['n' => $n, 'size_bytes' => $m['size'], 'uidl' => $m['uidl']];
        }
        return $records;
    },
    'fetch' => function ($args, $opt) use ($emailSecretDir, $emailStateDir) {
        $login = MailLogin::need($emailSecretDir, 'pop');
        $home = HomePath::home();
        $to = isset($opt['to']) && is_string($opt['to']) && $opt['to'] !== '' && $opt['to'][0] === '/' ? rtrim($opt['to'], '/') : null;
        $parent = $to !== null ? realpath(dirname($to)) : false;
        if ($parent === false || is_link($to)) {
            throw new CliError('fetch needs --to <full path of a Maildir folder> whose parent exists', 'e.g. --to ' . $home . '/mail-pop');
        }
        $dir = Maildir::ensure(MailPath::fence($parent . '/' . basename($to), $home, 'the Maildir'));
        $t = MailLogin::transport($login);
        $stateFile = rtrim($emailStateDir, '/') . '/email-pop/' . sha1($login['host'] . "\0" . $login['user']) . '.json';
        $state = MailUnit::readState($stateFile);
        $seen = isset($state['uidl']) && is_array($state['uidl']) ? array_flip($state['uidl']) : [];
        $limit = max(1, min(1000, isset($opt['limit']) ? (int) $opt['limit'] : Cli::LIMIT));
        $q = Quota::fields($home);
        $files = Quota::files($home);
        $got = ['fetched' => 0, 'bytes' => 0, 'skipped_too_large' => [], 'left' => 0];
        $stopped = null;
        foreach (popMessages($t) as $n => $m) {
            if ($m['uidl'] === null || isset($seen[$m['uidl']])) {
                continue;
            }
            if ($got['fetched'] >= $limit || $stopped !== null) {
                $got['left']++;
                continue;
            }
            if ($m['size'] > POP_MAX) {
                $got['skipped_too_large'][] = $n;
                continue;
            }
            $room = Maildir::roomProblem($q, $files, $got['bytes'], $got['fetched'], $m['size'], HermesInstall::UNATTENDED_RESERVE, @disk_total_space($home));
            if ($room !== '') {
                $stopped = "$room; fetch keeps 10% free and stopped";
                $got['left']++;
                continue;
            }
            $got['bytes'] += Maildir::deliver($dir, 'P' . substr(sha1($m['uidl']), 0, 20) . '.pikkumies', function ($sink) use ($t, $n) {
                return $t->request('/' . $n, ['max' => POP_MAX, 'sink' => $sink]);
            });
            $got['fetched']++;
            $seen[$m['uidl']] = true;
            MailUnit::writeState($stateFile, ['uidl' => array_keys($seen)]);
        }
        MailUnit::log($emailStateDir, ['verb' => 'pop fetch', 'messages' => $got['fetched'], 'bytes' => $got['bytes'], 'stopped' => $stopped !== null]);
        return ['records' => [['dir' => $dir] + $got], 'status' => array_filter(['stopped' => $stopped])];
    },
    'delete' => function ($args, $opt) use ($emailSecretDir) {
        $nums = array_map('popNumber', $args);
        $expect = isset($opt['expect']) && is_string($opt['expect']) ? explode(',', $opt['expect']) : [];
        if (!$nums || count($expect) !== count($nums)) {
            throw new CliError('give message numbers and --expect with their UIDLs from list, in the same order; nothing deleted', 'e.g. delete 3 4 --expect AbC1,DeF2');
        }
        $login = MailLogin::need($emailSecretDir, 'pop');
        $t = MailLogin::transport($login);
        $msgs = popMessages($t);
        foreach ($nums as $i => $n) {
            if (!isset($msgs[$n]) || $msgs[$n]['uidl'] !== $expect[$i]) {
                throw new CliError("message $n is not the one list showed (UIDL differs or it is gone); nothing deleted", 'run list again');
            }
        }
        foreach ($nums as $n) {
            $t->request('/' . $n, ['custom' => 'DELE', 'nobody' => true]);
        }
        $t->close(); // QUIT makes the deletes final
        $left = array_intersect($expect, array_column(popMessages(MailLogin::transport($login)), 'uidl'));
        return ['records' => [['deleted' => count($nums) - count($left)]], 'status' => ['verified' => !$left]];
    },
], $usage, [], ['to', 'expect']);
