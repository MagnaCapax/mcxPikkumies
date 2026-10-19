<?php
/**
 * lib/SshLists.php (ADR-0068): the person's SSH allow and deny lists. A listed host matches by its exact name or an
 * explicit * / ? pattern, never as a substring; the deny list wins; a connection is allowed only when all its names are.
 */
return [
    'ssh lists: exact names and patterns match whole names only, never a substring, a user or a port' => function () {
        $c = ['ssh' => ['allow' => ['nas', '*.home.example', 'h?.lan', '10.0.0.*'], 'deny' => []]];
        foreach (['nas', 'a.home.example', 'a.b.home.example', 'h1.lan', '10.0.0.7'] as $n) {
            check(SshLists::decide($c, [$n]) === 'allow', "$n must be allowed");
        }
        foreach (['nas.evil.com', 'evilnas', 'home.example', 'a.home.example.evil', 'h12.lan', '10.0.0.7.evil', 'xnas'] as $n) {
            check(SshLists::decide($c, [$n]) === 'unknown', "$n must not match by substring");
        }
    },
    'ssh lists: the deny list wins over the allow list, * alone is every host, and every name must be allowed' => function () {
        $c = ['ssh' => ['allow' => ['*'], 'deny' => ['evil.example']]];
        check(SshLists::decide($c, ['evil.example']) === 'deny', 'deny wins over allow *');
        check(SshLists::decide($c, ['nas', 'evil.example']) === 'deny', 'a denied hop denies the connection');
        check(SshLists::decide(['ssh' => ['allow' => ['nas'], 'deny' => []]], ['nas', 'hop']) === 'unknown', 'an unlisted hop makes it unknown');
        check(SshLists::decide(['ssh' => ['allow' => [], 'deny' => ['*']]], ['nas']) === 'deny', 'deny * refuses all');
        check(SshLists::decide([], ['nas']) === 'unknown' && SshLists::decide(['ssh' => ['allow' => ['*']]], []) === 'unknown', 'no lists, no names');
        check(SshLists::decide(['ssh' => ['deny' => 'evil.example']], ['evil.example']) === 'deny', 'a hand-written string is a one-entry list');
    },
    'ssh lists: entries and names are normalised the same way (case, trailing dot, IPv6, mapped IPv4)' => function () {
        $c = ['ssh' => ['allow' => [], 'deny' => ['NAS.Example.', '2001:DB8:0:0::1', '::ffff:10.1.1.1']]];
        foreach (['nas.example', '2001:db8::1', '10.1.1.1'] as $n) {
            check(SshLists::decide($c, [SshLists::host($n)]) === 'deny', "$n must match its listed spelling");
        }
        check(SshLists::host('NAS.example.') === 'nas.example' && SshLists::host('[::1]') === '::1' && SshLists::host('::ffff:192.0.2.4') === '192.0.2.4', 'normalise');
        foreach (['3232235777', '127.1', '0300.0250.1.1', '0x7f.1', 'fe80::1%eth0', 'a b', "na\u{202e}s", 'nas;id', ''] as $bad) {
            check(SshLists::host($bad) === null, "$bad must not be readable as a host");
        }
        check(SshLists::blockUnknown(['ssh' => ['blockUnknown' => true]]) && !SshLists::blockUnknown(Config::defaults()), 'blockUnknown');
    },
];
