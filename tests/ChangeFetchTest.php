<?php
/** lib/ChangeVerbs.php (ADR-0067): the fetch tools read unless they save, upload or send data. */
return [
    'change verbs: a fetch reads unless it saves a file, uploads, or sends data with a method other than GET or HEAD' => function () {
        $rows = [
            ['curl', ['-o', 'f', 'https://example.org/x'], 'write'], ['curl', ['-X', 'POST', 'u'], 'write'], ['curl', ['-sSd', 'a=1', 'u'], 'write'],
            ['curl', ['--data-binary', '@f', 'u'], 'write'], ['curl', ['-T', 'f', 'u'], 'write'], ['curl', ['--request=PUT', 'u'], 'write'],
            ['curl', ['-s', 'https://example.org/x'], 'read'], ['curl', ['-XGET', 'u'], 'read'], ['curl', ['-I', 'u'], 'read'], ['curl', ['--request', 'HEAD', 'u'], 'read'],
            ['wget', ['https://example.org/f'], 'write'], ['wget', ['-O', 'out', 'u'], 'write'], ['wget', ['--spider', 'u'], 'read'],
            ['wget', ['-q', '-O', '-', 'u'], 'read'], ['wget', ['-qO-', 'u'], 'read'], ['wget', ['--output-document=-', 'u'], 'read'],
        ];
        foreach ($rows as $r) {
            $got = ChangeVerbs::verdict($r[0], $r[1]);
            check($got === $r[2], $r[0] . ' ' . implode(' ', $r[1]) . ": $got, want {$r[2]}");
        }
    },
];
