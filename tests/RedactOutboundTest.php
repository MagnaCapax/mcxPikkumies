<?php
/**
 * ADR-0117: Redact::outbound is what leaves the machine. It removes a value assigned to a secret-like name inside
 * JSON-escaped tool-call arguments (one-line, pretty-printed, doubly encoded, the first assignment of a string; #24) and
 * a value left after a REDACTED marker, keeps the escaping and the ordinary words, and repeats to the same text.
 * Redact::leftover checks the exact bytes again: a draft saved before ADR-0117 is refused at load, by name, never by
 * value (#25). Fixture values are built by concatenation so no credential-shaped literal sits in this file.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackDraft.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/ticket/TicketDraft.php';
require_once dirname(__DIR__) . '/pikkumies/hooks/guard/publish-check/PublishScan.php';

if (!function_exists('outboundScript')) {
    /** A three-assignment script with oracle values, the shape #24 reported. @return array [script, values] */
    function outboundScript()
    {
        $v = ['AAAA' . 'k3y1', 'SSSS' . 's3c2', 'CCCC' . 'c0n3'];
        $s = 'APPLICATION' . "_KEY = \"$v[0]\"\nAPPLICATION" . "_SECRET = \"$v[1]\"\nCONSUMER" . "_KEY = \"$v[2]\"\nSERVICE_NAME = \"keepme\"\n";
        return [$s, $v];
    }

    function outboundHas($text, array $values)
    {
        foreach ($values as $v) {
            if (strpos($text, $v) !== false) {
                return $v;
            }
        }
        return '';
    }
}

return [
    'outbound: every value of the script is removed in one-line, pretty-printed and doubly encoded JSON, SERVICE_NAME stays' => function () {
        list($s, $v) = outboundScript();
        $carriers = ['one-line' => json_encode(['content' => $s]), 'pretty' => json_encode(['args' => ['content' => $s]], JSON_PRETTY_PRINT),
            'double' => json_encode(['x' => json_encode(['content' => $s])]), 'plain' => $s];
        foreach ($carriers as $name => $c) {
            $o = Redact::outbound($c);
            check(outboundHas($o, $v) === '', "$name: a value survived");
            check(strpos($o, 'keepme') !== false, "$name: SERVICE_NAME's value was removed");
            check(Redact::outbound($o) === $o, "$name: a second pass changed the text");
            check(Redact::leftover($o) === null, "$name: leftover found something after the pass");
            if ($name !== 'plain') {
                check(json_decode($name === 'double' ? (string) json_decode($o, true)['x'] : $o, true) !== null, "$name: the JSON no longer decodes");
            }
        }
        // The command log and the local stores keep secrets() alone: the one-line JSON script is unchanged there.
        check(Redact::secrets($carriers['one-line']) === $carriers['one-line'], 'secrets() changed: local redaction must stay as it was');
    },
    'outbound: a lower-case name right after an escaped newline or tab is caught, and named without the escape letter' => function () {
        $v = ['low' . 'pw1x', 'tok' . '3n9y'];
        $c = json_encode(['c' => "x\npass" . "word = \"$v[0]\"\n\t" . "token: '$v[1]'\n"]);
        $names = [];
        check(outboundHas(Redact::outbound($c, $names), $v) === '', 'a value after \\n or \\t survived');
        check($names === ['pass' . 'word', 'token'], 'names: ' . json_encode($names));
        check(Redact::leftover($c) !== null && Redact::leftover($c)[0] === 'pass' . 'word', 'leftover: ' . json_encode(Redact::leftover($c)));
    },
    'outbound: a value after a REDACTED marker is removed, plain and escaped' => function () {
        $hex = '0123456789ab' . 'cdef0123456789abcdef';
        foreach (['APPLICATION' . "_KEY=REDACTED = \"$hex\"", 'APPLICATION' . "_KEY=REDACTED = \\\"$hex\\\"", "TOKEN: REDACTED = '$hex'"] as $line) {
            check(strpos(Redact::outbound($line), $hex) === false, 'tail survived: ' . str_replace($hex, '<hex>', Redact::outbound($line)));
            check(Redact::leftover($line) !== null, 'leftover missed the tail');
        }
    },
    'outbound: ordinary text, code and placeholders stay (the false-positive classes measured on real conversations)' => function () {
        foreach (['PWD=/home/fixtureuser/files', 'pass = 0;', '{"key":"menu.file"}', '$apiKey = getenv("X");', 'token: "string"',
            'password = "$PASS"', 'SERVICE_NAME = "x"', 'monkey="banana" keyboard="fi"', 'the password is required here', 'GITHUB' . '_TOKEN_ENV=REDACTED ===='] as $t) {
            check(Redact::outbound($t) === Redact::secrets($t), 'outbound changed an ordinary line: ' . Redact::outbound($t));
            check(Redact::leftover(Redact::secrets($t)) === null, 'leftover fired on: ' . $t);
        }
    },
    'outbound: the names removed are reported, never the values' => function () {
        list($s, $v) = outboundScript();
        $names = [];
        Redact::outbound(json_encode(['content' => $s]), $names);
        check(array_values(array_unique($names)) === ['APPLICATION' . '_KEY', 'APPLICATION' . '_SECRET', 'CONSUMER' . '_KEY'], 'names: ' . json_encode($names));
    },
    'feedback: a draft with a script in the conversation is saved without the values, with the count and names' => function () {
        list($s, $v) = outboundScript();
        $dir = testTempDir('pk-outbound-fb');
        $rec = FeedbackDraft::create('it broke', "## tool call\n" . json_encode(['name' => 'write_file', 'args' => ['content' => $s]]), ['x'], 1700000000, $dir, ['s1', 't']);
        $saved = file_get_contents($rec['path']);
        check(outboundHas($saved, $v) === '', 'a value reached the saved draft');
        check($rec['removed'] === 3 && in_array('CONSUMER' . '_KEY', $rec['removed_names'], true), 'removed: ' . json_encode([$rec['removed'], $rec['removed_names']]));
        list($json) = FeedbackDraft::load($rec['id'], $dir);
        check($json === $saved, 'load did not return the saved bytes');
    },
    'feedback: a draft saved before ADR-0117 with a value in it is refused at load, naming the variable, not the value' => function () {
        list($s, $v) = outboundScript();
        $dir = testTempDir('pk-outbound-old');
        $p = ['v' => 1, 'created_at' => 1700000000, 'session_id' => 's1', 'session_title' => 't', 'text' => 'x',
            'transcript' => json_encode(['content' => $s]), 'transcript_older_part_left_out' => false, 'versions' => ['x']];
        list($id) = DraftStore::save($dir, json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $e = null;
        try {
            FeedbackDraft::load($id, $dir);
        } catch (CliError $x) {
            $e = $x;
        }
        check($e !== null, 'an old draft holding a value was loaded for sending');
        check($e === null || (strpos($e->getMessage(), 'APPLICATION' . '_KEY') !== false && outboundHas($e->getMessage() . $e->hint, $v) === ''),
            'refusal: ' . ($e === null ? '' : $e->getMessage()));
    },
    'ticket: a text attachment holding a JSON-escaped value is refused at load; the same draft through create is clean' => function () {
        list($s, $v) = outboundScript();
        $dir = testTempDir('pk-outbound-tk');
        $p = ['v' => 1, 'created_at' => 1700000000, 'email' => 'a@example.org', 'subject' => 's', 'message' => 'm', 'priority' => 'Low',
            'service' => '', 'attachments' => [['name' => 'log.txt', 'data' => base64_encode(json_encode(['content' => $s]))]], 'by' => 'user', 'bundle' => null];
        list($id) = DraftStore::save($dir, json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $refused = false;
        try {
            TicketDraft::load($id, $dir);
        } catch (CliError $x) {
            $refused = strpos($x->getMessage(), 'ticket draft') !== false;
        }
        check($refused, 'an attachment holding a value was loaded for sending');
        $f = $dir . '/log.txt';
        file_put_contents($f, json_encode(['content' => $s]));
        check(outboundHas(base64_decode(TicketDraft::attachment($f)['data']), $v) === '', 'TicketDraft::attachment kept a value');
    },
    'publish check: a value inside a JSON-escaped string is a hit, shown masked' => function () {
        list($s, $v) = outboundScript();
        $m = PublishScan::masked(json_encode(['content' => $s]));
        check($m !== null && outboundHas($m, $v) === '', 'publish check: ' . var_export($m, true));
        check(PublishScan::masked('SERVICE_NAME = "keepme"') === null, 'publish check fired on an ordinary line');
    },
];
