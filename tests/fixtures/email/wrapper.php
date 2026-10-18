<?php
/**
 * Runs one email unit with its test seams set from env vars (test code only, never shipped): EM_UNIT (imap, pop, smtp,
 * antispam), EM_SECRET_DIR, EM_STATE_DIR, EM_TYPED (login's prompt answers, \x1f-joined; unset means no terminal),
 * EM_RULES (a JSON file: a list of fake answers: {match: regex on "custom|url", body, errno, error, once}), EM_LOG (each request
 * as one JSON line), EM_QUOTA (raw `quota -s` output for Quota::$live). Nothing here reaches the network.
 */
$emailSecretDir = (string) getenv('EM_SECRET_DIR');
$emailStateDir = (string) getenv('EM_STATE_DIR');
if (getenv('EM_TYPED') !== false) {
    $answers = explode("\x1f", (string) getenv('EM_TYPED'));
    $emailPrompt = function () use (&$answers) {
        return $answers ? array_shift($answers) : '';
    };
}
require_once dirname(__DIR__, 3) . '/pikkumies/tools/email/lib/MailUnit.php';
Quota::$live = function () {
    return getenv('EM_QUOTA') !== false ? (string) getenv('EM_QUOTA') : null;
};
$emRules = json_decode((string) @file_get_contents((string) getenv('EM_RULES')), true) ?: [];
MailTransport::$fake = function (array $req) use (&$emRules) {
    $log = ['url' => $req['url'], 'custom' => isset($req['custom']) ? $req['custom'] : null, 'nobody' => !empty($req['nobody'])];
    if (isset($req['upload'])) {
        $log += ['from' => $req['from'], 'rcpt' => $req['rcpt'], 'upload' => $req['upload']];
    }
    file_put_contents((string) getenv('EM_LOG'), json_encode($log) . "\n", FILE_APPEND);
    $key = $log['custom'] . '|' . $req['url'];
    foreach ($emRules as $i => $r) {
        if (preg_match($r['match'], $key)) {
            if (!empty($r['once'])) {
                unset($emRules[$i]);
            }
            return ['body' => isset($r['body']) ? $r['body'] : '', 'errno' => isset($r['errno']) ? $r['errno'] : 0,
                'error' => isset($r['error']) ? $r['error'] : ''];
        }
    }
    return ['body' => ''];
};
$unit = (string) getenv('EM_UNIT');
require dirname(__DIR__, 3) . "/pikkumies/tools/email/$unit/$unit.php";
