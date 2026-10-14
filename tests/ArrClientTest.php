<?php
/** ArrClient against the fake API (tests/fixtures/arr/): answers it cannot read whole, empty answers, no XML parser. */
require_once __DIR__ . '/fixtures/arr/harness.php';

return [
    'arr client: an answer over the size cap or an empty GET answer is an error, never an empty list; a 6 MiB library lists' => function () {
        $fx = arrFixture('radarr');
        try {
            arrSet($fx, ['mode' => 'huge']); // health answers 6 MiB, over Http::MAX
            list($code, $l, $out) = arrRun($fx, ['health']);
            $last = $l[count($l) - 1];
            check($code === 1 && $last['ok'] === false && strpos($last['error'], 'larger than 5242880 bytes') !== false,
                'over the cap: ' . $out);
            arrSet($fx, ['mode' => 'empty']); // GET movie answers 200 with no body
            list($code, $l, $out) = arrRun($fx, ['list']);
            $last = $l[count($l) - 1];
            check($code === 1 && $last['ok'] === false && strpos($last['error'], 'not with the app\'s API') !== false, 'empty 200: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['stats']);
            check($code === 1 && strpos($out, '"ok":false') !== false, 'stats counted an empty answer as 0 items: ' . $out);
            arrSet($fx, ['mode' => 'bigList']); // GET movie answers 6 MiB: a large library
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 0 && count($l) === 3 && $l[0]['id'] === 1 && $l[1]['id'] === 2 && $l[2]['count'] === 2,
                'a 6 MiB library: ' . substr($out, 0, 400));
        } finally {
            arrDone($fx);
        }
    },
    'arr: no XML parser anywhere in tools/arr; config.xml is read with a regex only' => function () {
        $dir = dirname(__DIR__) . '/pikkumies/tools/arr';
        $n = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (substr((string) $f, -4) !== '.php') {
                continue;
            }
            $n++;
            check(!preg_match('/simplexml|DOMDocument|xml_parse|XMLReader|libxml/i', (string) file_get_contents((string) $f)),
                "an XML parser in $f");
        }
        check($n >= 10, "only $n PHP files found under $dir");
    },
];
