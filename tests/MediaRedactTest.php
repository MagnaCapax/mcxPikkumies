<?php
/** MediaLocal::redact on address forms in media-server logs; the expected lines are literal strings, not the production regex. */
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/lib/MediaServers.php';

return [
    'media logs: every IPv4/IPv6 form is replaced (with :port, in a URL, bracketed, mapped); --addresses keeps them' => function () {
        $cases = [
            'Request: [203.0.113.7:51234 (WAN)] GET /library/sections' => 'Request: [ADDRESS:51234 (WAN)] GET /library/sections',
            '[INF] GET http://198.51.100.9:8096/Sessions' => '[INF] GET http://ADDRESS:8096/Sessions',
            'Authentication request for "bob" has succeeded. IP 192.0.2.44' => 'Authentication request for "bob" has succeeded. IP ADDRESS',
            'remote [2001:db8::5]:4433 connected' => 'remote [ADDRESS]:4433 connected',
            'login user@203.0.113.7' => 'login user@ADDRESS',
            '203.0.113.7:51234' => 'ADDRESS:51234',
            'http://198.51.100.9:8096/x' => 'http://ADDRESS:8096/x',
            '[2001:db8::1]:8920' => '[ADDRESS]:8920',
            'from ::ffff:203.0.113.7 ok' => 'from ADDRESS ok',
            'mapped ::ffff:203.0.113.7:51234' => 'mapped ADDRESS:51234',
            'Client:203.0.113.7' => 'Client:ADDRESS',
            'IP:2001:db8::5.' => 'IP:ADDRESS.',
            'peer fe80::1:51234 left' => 'peer ADDRESS:51234 left',
            'list 10.0.0.1,10.0.0.2' => 'list ADDRESS,ADDRESS',
        ];
        list($got) = MediaLocal::redact(array_keys($cases), [], false);
        check($got === array_values($cases), 'redacted: ' . json_encode(array_combine(array_keys($cases), $got)));
        list($kept) = MediaLocal::redact(array_keys($cases), [], true);
        check($kept === array_keys($cases), '--addresses changed a line: ' . json_encode($kept));
    },
    'media logs: times, versions and MAC-like runs that are not IP addresses stay as they are' => function () {
        $same = ['version 10.10.1', 'at 12:30:45', 'build 1.2.3.4.5', '2026-10-14T07:48:29.123Z', 'mac aa:bb:cc:dd:ee:ff',
            'Failed to add::x'];
        list($got) = MediaLocal::redact($same, [], false);
        check($got === $same, 'changed: ' . json_encode($got));
    },
];
