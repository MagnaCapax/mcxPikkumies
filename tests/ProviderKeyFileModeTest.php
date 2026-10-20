<?php
/**
 * The key file is trusted like a stored secret (security review 2026-10-17, F1 CWE-732 and F3): a file others can
 * read is set to 0600 and used, a file another account owns is no key, and a short key is never shown in part.
 */
return [
    'provider key file: a 0644 key file is set to 0600 and used; another owner reads as no key' => function () {
        $tmp = testTempDir('pk-keymode');
        file_put_contents("$tmp/key", "endpoint=https://e/v1\nkey=vsk-abcdef0123456789\n");
        chmod("$tmp/key", 0644);
        $r = Provider::readKeyFile("$tmp/key");
        clearstatcache(true, "$tmp/key");
        check($r['key'] === 'vsk-abcdef0123456789', 'a loose key file must still be used');
        check((fileperms("$tmp/key") & 0777) === 0600, sprintf('mode must be 0600, is %04o', fileperms("$tmp/key") & 0777));
        $me = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        check(Provider::readKeyFile("$tmp/key", $me + 1)['key'] === '', 'a key file of another uid must not be trusted');
        testRmTree($tmp);
    },
    'provider key file: every key is shown as stars only, whatever its length (ADR-0101)' => function () {
        check(Provider::mask('short123') === '********', 'short key: ' . Provider::mask('short123'));
        check(Provider::mask('vsk-abcdef01234') === '********', '15 chars: ' . Provider::mask('vsk-abcdef01234'));
        check(Provider::mask('vsk-abcdef0123456789') === '********', 'long key: ' . Provider::mask('vsk-abcdef0123456789'));
        check(Provider::mask('') === '(none)', 'empty');
    },
    'provider key file: doctor never prints a user:password@ part of the endpoint' => function () {
        $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(strpos($src, "out('endpoint: ' . (\$prov['endpoint'] ?: '(not set)'))") === false, 'raw endpoint print is back');
        check(preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]*@#i', '$1', 'https://u:p@e.example/v1') === 'https://e.example/v1', 'the strip pattern');
    },
];
