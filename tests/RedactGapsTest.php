<?php
/**
 * lib/Redact.php, the forms a security review found it missed (ADR-0100 review, probe p.php): an indented or quoted PEM
 * body, camelCase JSON and config names, long options and headers ending in -secret/-pass/-token/-key, crypt(3) hashes,
 * and the private lines of a PuTTY key. One test per form; each also checks that its everyday look-alike is kept, since
 * Redact is shared with /feedback and the command log. Fake values only, assembled here.
 */
if (!function_exists('redactGapCheck')) {
    /** Every Or4cle value is gone from the redacted text, and every $keep string is still there. */
    function redactGapCheck($text, array $keep = [])
    {
        $out = Redact::secrets($text);
        check(!preg_match('/Or4cle|OR4CLE|or4cle/', $out), 'not removed: ' . json_encode($text) . ' -> ' . json_encode($out));
        foreach ($keep as $k) {
            check(strpos($out, $k) !== false, "kept text lost: $k -> " . json_encode($out));
        }
        return $out;
    }
}

return [
    'redact gaps (a): a PEM private key indented or quoted (>) loses its body like a plain one; the BEGIN and END lines stay' => function () {
        $begin = '-----BEGIN OPENSSH PRIVATE ' . 'KEY-----';
        $end = str_replace('BEGIN', 'END', $begin);
        $out = redactGapCheck("    $begin\n    b3BlbnNzaC1Or4cle1AAAAABG5vbmUAAAAE\n    QyNTUxOQOr4cle2xxxxxxxx\n    $end\nafter", ['after']);
        check(strpos($out, "    $end") !== false, $out);
        redactGapCheck("> $begin\n> MIIEowOr4cle3AAKCAQEAxxxx\n> $end\n> thanks", ['> thanks']);
        redactGapCheck("$begin\nMIIEowOr4cle4AAKCAQEAxxxx\n$end");
    },
    'redact gaps (b): camelCase names ending in Key, Token, Secret, Password or Passwd are redacted; monkey and keyboard are not' => function () {
        redactGapCheck('{"accessToken": "Or4cle1abc", "clientSecret": "Or4cle2", "refreshToken":"Or4cle3"}');
        redactGapCheck('PrivateKey = Or4cle4+abc/def=');
        redactGapCheck("dbPassword: Or4cle5\n", []);
        $prose = "monkey=banana keyboard=fi compass=north\nThe Turnkey solution";
        check(Redact::secrets($prose) === $prose, 'ordinary text changed: ' . Redact::secrets($prose));
    },
    'redact gaps (c): a long option ending in -secret, -pass, -password, -token or -key loses its value, with = or a space' => function () {
        redactGapCheck('rclone mount gdrive: /x --drive-client-secret Or4cle1-abcdefghijkl --webdav-pass Or4cle2hunter', ['rclone mount gdrive: /x']);
        redactGapCheck('aria2c --rpc-secret Or4cle3secret && aria2c --rpc-secret=Or4cle4', ['aria2c']);
        $plain = 'rsync --partial --progress src/ dst/';
        check(Redact::secrets($plain) === $plain, 'an ordinary option line changed');
    },
    'redact gaps (d): a header whose name ends in -token or -key loses its value' => function () {
        redactGapCheck("curl -H 'X-Auth-Token: Or4cle1abc' https://x.example", ['https://x.example']);
        redactGapCheck('X-Upload-Key: Or4cle2');
        $plain = 'Content-Type: text/plain';
        check(Redact::secrets($plain) === $plain, 'an ordinary header changed');
    },
    'redact gaps (e): a crypt(3) password hash ($1$, $2b$, $5$, $6$, $y$) loses everything after its prefix' => function () {
        foreach (['$6$saltOr4c$', '$5$roundsOr4cle$', '$2b$12$Or4cle', '$y$j9T$Or4cle', '$1$Or4cle12$'] as $p) {
            $out = redactGapCheck("root:{$p}abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGH:19000:0:99999:7:::", ['root:', ':19000:']);
            check(strpos($out, 'REDACTED') !== false, $out);
        }
        $plain = 'price is $6 or $10$ today';
        check(Redact::secrets($plain) === $plain, 'ordinary dollar text changed: ' . Redact::secrets($plain));
    },
    'redact gaps (f): a PuTTY key\'s Private-Lines block is left out, its public lines and the text after it stay' => function () {
        $ppk = "PuTTY-User-Key-File-3: ssh-ed25519\nPublic-Lines: 2\nAAAAC3NzaC1lZDI1NTE5AAAAIPublicOne\nAAAAPublicTwo\nPrivate-Lines: 1\nAAAAIOr4cle1xxxxxxxxxxxxxxxx\nPrivate-MAC: abc";
        $out = redactGapCheck($ppk, ['AAAAPublicTwo', 'Private-Lines: REDACTED', 'Private-MAC']);
        check(substr_count($out, "\n") === 5, 'one private line must go, no more: ' . json_encode($out));
        $big = Redact::secrets("Private-Lines: 9999\n" . str_repeat("x\n", 300) . 'tail');
        check(substr($big, -4) === 'tail', 'a huge Private-Lines count swallowed the rest of the text');
    },
];
