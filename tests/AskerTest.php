<?php
/**
 * tests/testing/questions/lib/Asker.php: parseStream()'s "no stream-json" detection and stderrTail(), the piece that
 * tells a QA report WHY (Hermes' own stderr), with any key-shaped text masked before it reaches a report file.
 */
require_once dirname(__DIR__) . '/tests/testing/questions/lib/Asker.php';

/** Builds a fake "Authorization: Bearer <token>" line at runtime so the source never holds a literal secret shape. */
function askerFakeBearerLine()
{
    return 'curl: (22) auth failed: Authorization: ' . implode(' ', ['Bearer', 'fake' . '-token-' . '000111']);
}

/** Builds a fake vsk-/sk- prefixed key line at runtime, same reason as askerFakeBearerLine(). */
function askerFakeKeyLine($prefix)
{
    return 'provider rejected ' . $prefix . '-' . 'fakeKEY' . '000111' . ' with 401';
}

return [
    'Asker::stderrTail is empty for a missing or empty file, and keeps only the trailing ~400 bytes otherwise' => function () {
        $dir = sys_get_temp_dir() . '/pk-asker-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0700, true);
        try {
            check(Asker::stderrTail("$dir/nope.err") === '', 'a missing file must give no tail, not an error');
            file_put_contents("$dir/empty.err", '');
            check(Asker::stderrTail("$dir/empty.err") === '', 'an empty file must give no tail');
            file_put_contents("$dir/short.err", "hermes: unrecognized arguments: --usage-file\n");
            check(Asker::stderrTail("$dir/short.err") === '; stderr: hermes: unrecognized arguments: --usage-file',
                'a short file must come back whole, trimmed, with the "; stderr: " prefix: ' . Asker::stderrTail("$dir/short.err"));
            $long = str_repeat('x', 1000) . 'TAIL-MARKER-END'; // 15 bytes; with $take=20 the kept tail is 5 x's + the marker
            file_put_contents("$dir/long.err", $long);
            $tail = Asker::stderrTail("$dir/long.err", 20);
            check($tail === '; stderr: xxxxxTAIL-MARKER-END', 'only the last $take bytes must be kept, not the whole file: ' . $tail);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'Asker::stderrTail masks a Bearer token and a vsk-/sk- prefixed key before they ever reach a report' => function () {
        $dir = sys_get_temp_dir() . '/pk-asker-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0700, true);
        try {
            $bearerLine = askerFakeBearerLine();
            file_put_contents("$dir/bearer.err", $bearerLine);
            $out = Asker::stderrTail("$dir/bearer.err");
            check(strpos($out, 'Bearer [masked]') !== false, 'a Bearer token was not masked: ' . $out);
            check(strpos($out, '000111') === false, 'the raw token leaked past masking: ' . $out);
            foreach (['vsk', 'sk'] as $prefix) {
                $line = askerFakeKeyLine($prefix);
                file_put_contents("$dir/$prefix.err", $line);
                $out = Asker::stderrTail("$dir/$prefix.err");
                check(strpos($out, '[masked-key]') !== false && strpos($out, '000111') === false,
                    "a $prefix- key was not masked: $out");
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'Asker::parseStream\'s "no stream-json" error is the exact prefix Asker::ask() keys off to append the stderr tail' => function () {
        $dir = sys_get_temp_dir() . '/pk-asker-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/raw.jsonl", "not json at all\n");
            $r = Asker::parseStream("$dir/raw.jsonl");
            // Asker::ask() does: if (strpos($r['error'], 'no stream-json from Hermes') === 0) { append stderrTail(...); }
            check(strpos($r['error'], 'no stream-json from Hermes') === 0, 'parseStream must still lead with this exact text, or ask() will silently stop attaching the stderr tail: ' . $r['error']);
            file_put_contents("$dir/raw.err", 'hermes_cli: error: unrecognized arguments: --usage-file=/tmp/x');
            $withTail = $r['error'] . Asker::stderrTail("$dir/raw.err");
            check(strpos($withTail, 'no stream-json from Hermes') === 0 && strpos($withTail, 'unrecognized arguments') !== false,
                'the composed error lost either the original cause or the stderr context: ' . $withTail);
            // A genuinely empty stream ("no result record") must NOT match the prefix check, so ask() never
            // appends a stderr tail to a run that did stream, just cut off before a result line.
            file_put_contents("$dir/raw2.jsonl", json_encode(['type' => 'system', 'subtype' => 'init']) . "\n");
            $r2 = Asker::parseStream("$dir/raw2.jsonl");
            check(strpos($r2['error'], 'no stream-json from Hermes') !== 0, 'a cut-off-but-streamed run must not be treated as "no stream": ' . $r2['error']);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
