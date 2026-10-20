<?php
/**
 * lib/PathName.php: the last part of a path without basename(), which before PHP 8 follows the locale and in the C
 * locale drops leading multibyte characters ("ä€ö.pdf" became ".pdf" on PHP 7.3). The multibyte checks set the C
 * locale themselves, so they fail with basename() on PHP 7.3 whatever locale the runner has.
 */
require_once __DIR__ . '/fixtures/email/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/storage/rclone/RclonePlace.php';

/** Runs $fn with LC_CTYPE set to C, then puts the locale back. */
function pathNameInC($fn)
{
    $was = setlocale(LC_CTYPE, 0);
    setlocale(LC_CTYPE, 'C');
    try {
        $fn();
    } finally {
        setlocale(LC_CTYPE, $was);
    }
}

return [
    'path name: the same answer as basename() for ASCII paths, suffixes, trailing slashes, "/" and ""' => function () {
        $paths = ['', '/', '//', '.', '..', 'a', '/a', 'a/', 'a//', '//a', '/a/b/', '/a/b.jsonl', 'b.jsonl', '.jsonl', '/x/.jsonl', 'a/b.php/',
            '/usr/bin/php', 'x y?.txt', '/a/b.tar.gz', '..//'];
        foreach ($paths as $p) {
            foreach (['', '.jsonl', '.php', '.gz', 'b', '.'] as $s) {
                check(PathName::base($p, $s) === basename($p, $s), json_encode([$p, $s]) . ': ' . json_encode(PathName::base($p, $s)) . ' vs basename() ' . json_encode(basename($p, $s)));
            }
        }
    },
    'path name: a name starting with multibyte characters keeps them in the C locale' => function () {
        pathNameInC(function () {
            foreach ([['/t/ä€ö.pdf', '', 'ä€ö.pdf'], ['ä€ö', '', 'ä€ö'], ['/home/fixtureuser/äänet/', '', 'äänet'], ['/l/€.jsonl', '.jsonl', '€'], ['/l/ö', 'ö', 'ö']] as $c) {
                check(PathName::base($c[0], $c[1]) === $c[2], json_encode($c, JSON_UNESCAPED_UNICODE) . ' -> ' . json_encode(PathName::base($c[0], $c[1]), JSON_UNESCAPED_UNICODE));
            }
        });
    },
    'path name: rclone place and the mail folders keep a user-given name that starts with a multibyte character' => function () {
        $fx = emFixture();
        try {
            $h = $fx['home'];
            pathNameInC(function () use ($h) {
                $place = RclonePlace::of("$h/äänitteet.tar", false, $h);
                check($place === "$h/äänitteet.tar", 'rclone place: ' . $place);
                $root = MailPath::mailRoot("$h/€posti", $h, 'archive');
                check($root === "$h/€posti" && is_dir($root), 'mail root: ' . $root);
                $fresh = MailPath::freshDir("$h/ä.kuitit", $h); // basename() made this ".kuitit", refused as a dot-directory
                check($fresh === "$h/ä.kuitit" && is_dir($fresh), 'fresh dir: ' . $fresh);
            });
        } finally {
            emDone($fx);
        }
    },
];
