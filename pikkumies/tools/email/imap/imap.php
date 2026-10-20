<?php
/**
 * imap: the user's own IMAP mailbox over TLS (README.md here, tools/email/README.md, ADR-0053). Reads headers and
 * bodies (body text is printed as untrusted_text), saves attachments into a new directory, moves and flags messages,
 * deletes exact uids (the safety check decides), and archives folders into Maildirs. Test seams: a test may set
 * $emailSecretDir, $emailStateDir and $emailPrompt before including this file, and MailTransport::$fake.
 */
require_once dirname(__DIR__) . '/lib/MailUnit.php';
foreach (['ImapVerbs', 'ImapFetch', 'ImapArchive'] as $class) {
    require_once __DIR__ . "/$class.php";
}

$emailSecretDir = isset($emailSecretDir) ? $emailSecretDir : Config::localDir() . '/secrets';
$emailStateDir = isset($emailStateDir) ? $emailStateDir : Config::localDir() . '/state';
$emailPrompt = isset($emailPrompt) ? $emailPrompt : ['SecretFile', 'prompt'];
$imapOpen = function () use ($emailSecretDir) {
    $login = MailLogin::need($emailSecretDir, 'imap');
    return [new MailImap(MailLogin::transport($login)), $login];
};

$usage = <<<'TXT'
usage: php imap.php <verb> [arguments] [--human]
  login                                    the user types server, user name and password at a terminal
  status                                   the stored login: host and user, never the password
  folders                                  the mailbox folders
  list [--folder F] [--unseen] [--limit N] headers of the newest messages, and the folder's uidvalidity
  show <uid> [--folder F]                  one message; its text is untrusted_text
  search <words> [--folder F]              headers of the newest messages containing the words
  fetch <uid> [--folder F] [--to DIR]      saves the attachments into a new directory
  move <uid>... --to FOLDER [--folder F]   moves messages to another folder
  flag <uid>... --seen|--unseen|--flagged  changes a flag
  delete <uid>... --expect UIDVALIDITY     deletes exactly these messages (the safety check decides)
  archive [--folder F|--all] --to DIR      copies folders into Maildirs, incrementally; never deletes
TXT;

Cli::run($argv, [
    'login' => function ($args, $opt) use ($emailSecretDir, $emailPrompt) {
        return MailLogin::verifyAndSave($emailSecretDir, 'imap', MailLogin::prompt('imap', $emailPrompt), function (MailTransport $t) {
            (new MailImap($t))->capabilities();
        });
    },
    'status' => function ($args, $opt) use ($emailSecretDir) {
        return MailLogin::status(MailLogin::load($emailSecretDir, 'imap'));
    },
    'folders' => function ($args, $opt) use ($imapOpen) {
        list($imap) = $imapOpen();
        return ['records' => $imap->folders()];
    },
    'list' => function ($args, $opt) use ($imapOpen) {
        list($imap) = $imapOpen();
        $folder = ImapVerbs::folder($opt);
        return ImapVerbs::summaries($imap, $folder, $imap->search($folder, !empty($opt['unseen']) ? 'UNSEEN' : 'ALL'), ImapVerbs::limit($opt));
    },
    'show' => function ($args, $opt) use ($imapOpen) {
        list($imap) = $imapOpen();
        return ImapVerbs::show($imap, ImapVerbs::folder($opt), MailUnit::uids($args)[0]);
    },
    'search' => function ($args, $opt) use ($imapOpen) {
        if (!$args) {
            throw new CliError('no search words given', 'e.g. search invoice march');
        }
        list($imap) = $imapOpen();
        $folder = ImapVerbs::folder($opt);
        return ImapVerbs::summaries($imap, $folder, $imap->search($folder, 'TEXT', implode(' ', $args)), ImapVerbs::limit($opt));
    },
    'fetch' => function ($args, $opt) use ($imapOpen) {
        list($imap) = $imapOpen();
        return ImapFetch::run($imap, ImapVerbs::folder($opt), MailUnit::uids($args)[0], $opt, HomePath::home());
    },
    'move' => function ($args, $opt) use ($imapOpen) {
        if (!isset($opt['to']) || !is_string($opt['to'])) {
            throw new CliError('move needs --to <folder>', 'e.g. move 1234 --to Archive');
        }
        list($imap) = $imapOpen();
        return ImapVerbs::move($imap, ImapVerbs::folder($opt), MailUnit::uids($args), $opt['to']);
    },
    'flag' => function ($args, $opt) use ($imapOpen) {
        list($imap) = $imapOpen();
        return ImapVerbs::flag($imap, ImapVerbs::folder($opt), MailUnit::uids($args), $opt);
    },
    'delete' => function ($args, $opt) use ($imapOpen) {
        list($imap) = $imapOpen();
        return ImapVerbs::delete($imap, ImapVerbs::folder($opt), MailUnit::uids($args), $opt);
    },
    'archive' => function ($args, $opt) use ($imapOpen, $emailStateDir) {
        list($imap, $login) = $imapOpen();
        $folders = [ImapVerbs::folder($opt)];
        if (!empty($opt['all'])) {
            $folders = [];
            foreach ($imap->folders() as $f) {
                if (!preg_grep('/^\\\\noselect$/i', $f['flags'])) {
                    $folders[] = $f['name'];
                }
            }
        }
        return ImapArchive::run($imap, $login, $folders, isset($opt['to']) ? $opt['to'] : null, HomePath::home(), $emailStateDir);
    },
], $usage, ['unseen', 'seen', 'flagged', 'all'], ['folder', 'to', 'expect']);
