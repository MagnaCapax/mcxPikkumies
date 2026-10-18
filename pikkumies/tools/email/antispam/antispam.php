<?php
/**
 * antispam: the user's own spam rules over their IMAP mailbox (README.md here, ADR-0053). `scan` reports, per message,
 * the provider's verdict (SPF, DKIM, DMARC, X-Spam-Flag) and the user's matching rules; `apply` moves the messages scan
 * calls spam into the Junk folder with the imap login. It never deletes. Test seams as in tools/email/imap/imap.php.
 */
require_once dirname(__DIR__) . '/lib/MailUnit.php';
require_once __DIR__ . '/AntispamRules.php';

$emailSecretDir = isset($emailSecretDir) ? $emailSecretDir : Config::localDir() . '/secrets';
$emailStateDir = isset($emailStateDir) ? $emailStateDir : Config::localDir() . '/state';
$rulesFile = rtrim($emailStateDir, '/') . '/email-rules.json';

/** [scan records, folder, uids judged spam] for the newest --limit messages of --folder. */
function antispamScan($secretDir, $rulesFile, array $opt)
{
    $imap = new MailImap(MailLogin::transport(MailLogin::need($secretDir, 'imap')));
    $folder = MailImap::text(isset($opt['folder']) && is_string($opt['folder']) ? $opt['folder'] : 'INBOX', 'folder');
    $limit = max(1, min(200, isset($opt['limit']) ? (int) $opt['limit'] : Cli::LIMIT));
    $rules = AntispamRules::load($rulesFile);
    $uids = array_reverse(array_slice($imap->search($folder, 'ALL'), -$limit));
    $meta = $uids ? $imap->meta($folder, $uids) : [];
    $records = [];
    $spam = [];
    $unseen = [];
    foreach ($uids as $uid) {
        if (!isset($meta[$uid])) {
            continue;
        }
        list($h) = MailParse::split($imap->fetch($folder, $uid, AntispamRules::HEAD, ['max' => 262144]));
        $r = ['uid' => $uid] + AntispamRules::score($h, $rules);
        if ($r['spam']) {
            $spam[] = $uid;
        }
        if (!in_array('\\Seen', $meta[$uid]['flags'], true)) {
            $unseen[] = $uid;
        }
        $records[] = $r;
    }
    if ($unseen) {
        $imap->store($folder, $unseen, '-', '\\Seen'); // a header read sets \Seen; scanning changes nothing
    }
    return [$records, $folder, $spam, $imap];
}

$usage = <<<'TXT'
usage: php antispam.php <verb> [arguments] [--human]
  rules                                               the user's spam rules
  rule-add --from A | --domain D | --subject-word W   adds a rule
  rule-remove --from A | --domain D | --subject-word W   removes a rule
  scan [--folder INBOX] [--limit N]                   the provider's verdict and matching rules, per message
  apply [--folder INBOX] [--limit N] [--to Junk]      moves what scan calls spam to the Junk folder; never deletes
TXT;

Cli::run($argv, [
    'rules' => function ($args, $opt) use ($rulesFile) {
        $records = [];
        foreach (AntispamRules::load($rulesFile) as $kind => $values) {
            foreach ($values as $v) {
                $records[] = ['kind' => str_replace('_', '-', $kind), 'value' => $v];
            }
        }
        return $records;
    },
    'rule-add' => function ($args, $opt) use ($rulesFile) {
        list($kind, $v) = AntispamRules::pick($opt);
        $rules = AntispamRules::load($rulesFile);
        $rules[$kind] = array_values(array_unique(array_merge($rules[$kind], [$v])));
        MailUnit::writeState($rulesFile, $rules);
        return ['records' => [['added' => str_replace('_', '-', $kind), 'value' => $v]], 'status' => ['verified' => in_array($v, AntispamRules::load($rulesFile)[$kind], true)]];
    },
    'rule-remove' => function ($args, $opt) use ($rulesFile) {
        list($kind, $v) = AntispamRules::pick($opt);
        $rules = AntispamRules::load($rulesFile);
        if (!in_array($v, $rules[$kind], true)) {
            throw new CliError('no such rule: ' . str_replace('_', '-', $kind) . " $v", 'see rules');
        }
        $rules[$kind] = array_values(array_diff($rules[$kind], [$v]));
        MailUnit::writeState($rulesFile, $rules);
        return ['records' => [['removed' => str_replace('_', '-', $kind), 'value' => $v]], 'status' => ['verified' => !in_array($v, AntispamRules::load($rulesFile)[$kind], true)]];
    },
    'scan' => function ($args, $opt) use ($emailSecretDir, $rulesFile) {
        list($records, , $spam) = antispamScan($emailSecretDir, $rulesFile, $opt);
        return ['records' => $records, 'status' => ['spam' => count($spam)]];
    },
    'apply' => function ($args, $opt) use ($emailSecretDir, $rulesFile) {
        $to = MailImap::text(isset($opt['to']) && is_string($opt['to']) ? $opt['to'] : 'Junk', 'folder');
        list($records, $folder, $spam, $imap) = antispamScan($emailSecretDir, $rulesFile, $opt);
        if (!in_array($to, array_column($imap->folders(), 'name'), true)) {
            throw new CliError("there is no folder $to; nothing moved", 'pass --to with the junk folder that imap folders prints');
        }
        if ($spam) {
            $imap->move($folder, $spam, $to);
        }
        $left = $spam ? $imap->meta($folder, $spam) : [];
        return ['records' => array_values(array_filter($records, function ($r) {
            return $r['spam'];
        })), 'status' => ['moved' => count($spam) - count($left), 'to' => $to, 'verified' => !$left]];
    },
], $usage, [], ['folder', 'to', 'from', 'domain', 'subject-word']);
