<?php
/**
 * lib/MailParse.php, lib/MailPath.php and lib/Maildir.php (ADR-0053, R15 CWE-22 and CWE-400): headers and names decoded
 * first, attachment names cut to one plain file name, writes only into a new directory with fopen 'x', reads for sending
 * fenced, and a capped Maildir delivery that leaves no partial file. The key marker is built from parts.
 */
require_once __DIR__ . '/fixtures/email/harness.php';

return [
    'email parse: RFC 2047 headers, RFC 2231 names, nested parts, HTML stripped of scripts, text capped and flagged' => function () {
        $msg = emMessage('=?ISO-8859-1?Q?caf=E9?= =?UTF-8?B?IGfDpHN0?=', str_repeat('a', 9000), [
            ["Content-Type: application/pdf\r\nContent-Disposition: attachment; filename*=UTF-8''..%2F..%2F.ssh%2Fauthorized_keys\r\nContent-Transfer-Encoding: base64", base64_encode('KEY')],
            ["Content-Type: application/octet-stream; name=\"=?UTF-8?B?Li4vZXZpbC5waHA=?=\"\r\nContent-Transfer-Encoding: base64", base64_encode('<?php')],
            ["Content-Type: text/plain; name*0*=UTF-8''r%C3%A9; name*1=sum.txt", 'cv'],
        ]);
        $p = MailParse::parse($msg, 8000, true);
        check(MailParse::header($p['headers'], 'subject') === 'café gäst', 'subject: ' . MailParse::header($p['headers'], 'subject'));
        check(strlen($p['text']) === 8000 && $p['text_truncated'] === true, 'text not capped at 8000');
        $names = array_column($p['attachments'], 'name');
        check($names === ['../../.ssh/authorized_keys', '../evil.php', 'résum.txt'], 'names not decoded: ' . json_encode($names));
        check($p['attachments'][0]['data'] === 'KEY' && $p['attachments'][1]['data'] === '<?php', 'attachment bytes not decoded');
        $h = MailParse::htmlText('<style>x{}</style><p>Hi <b>there</b></p><script>alert(1)</script><!-- secret -->&amp;');
        check($h === "Hi there\n&", 'html text: ' . json_encode($h));
        $deep = str_repeat("Content-Type: message/rfc822\r\n\r\n", 50) . "Content-Type: text/plain\r\n\r\nbottom";
        check(is_array(MailParse::parse($deep)), 'deep nesting must stop, not loop');
    },
    'email parse: HTML with a wrong or missing charset still gives its text; nesting stops at DEPTH levels' => function () {
        foreach (["Content-Type: text/html\r\n\r\n<p>caf\xE9 au lait</p><p>ok</p>", "Content-Type: text/html; charset=UTF-8\r\n\r\n<p>caf\xE9\xA0au lait</p>"] as $raw) {
            $t = MailParse::parse($raw)['text'];
            check(strpos($t, 'caf') === 0 && strpos($t, 'au lait') !== false && preg_match('//u', $t), 'html text lost: ' . json_encode(bin2hex($t)));
        }
        $nest = function ($levels) {
            return str_repeat("Content-Type: message/rfc822\r\n\r\n", $levels) . "Content-Type: text/plain\r\n\r\nbottom";
        };
        check(MailParse::parse($nest(MailParse::DEPTH))['text'] === 'bottom', 'a message within the depth cap was not read');
        check(MailParse::parse($nest(MailParse::DEPTH + 1))['text'] === '', 'nesting past DEPTH was read');
    },
    'email path: attachment names are decoded, cut to one plain name, stripped of dots, dashes and controls, capped at 200 bytes' => function () {
        $cases = ['../../.ssh/authorized_keys' => 'authorized_keys', '..\\..\\x.php' => 'x.php', '.bashrc' => 'bashrc', '-rf' => 'rf',
            '' => 'attachment-3', '..' => 'attachment-3', '.' => 'attachment-3', "a\0b\x01c.txt" => 'abc.txt', 'naïve résumé.pdf' => 'naïve résumé.pdf',
            'a/b<c>.txt' => 'b_c_.txt', 'C:\\temp\\run.bat' => 'run.bat', "evil\xff.txt" => 'evil_.txt', '...hidden' => 'hidden'];
        foreach ($cases as $in => $want) {
            $got = MailPath::safeName($in, 3);
            check($got === $want, json_encode($in) . " -> $got, wanted $want");
        }
        $long = MailPath::safeName(str_repeat('ä', 200) . '.pdf', 1);
        check(strlen($long) <= 200 && substr($long, -4) === '.pdf' && preg_match('//u', $long), 'long name not capped with its extension: ' . strlen($long));
    },
    'email path: fetch writes only into a new directory inside the home, never an existing one, a dot-directory, ~/www, ~/bin or outside' => function () {
        $fx = emFixture();
        try {
            $h = $fx['home'];
            foreach (['empty', '.ssh', 'www', 'bin', 'docs'] as $d) {
                mkdir("$h/$d");
            }
            file_put_contents("$h/docs/a", 'x');
            foreach (["$h/empty", "$h/docs", "$h/.ssh/new", "$h/www/new", "$h/bin/new", "$h/docs/.hidden", $fx['dir'] . '/outside', "$h/../x", 'relative/dir', $h] as $bad) {
                $refused = false;
                try {
                    MailPath::freshDir($bad, $h);
                } catch (CliError $e) {
                    $refused = true;
                }
                check($refused, "freshDir accepted $bad");
            }
            $ok = MailPath::freshDir("$h/docs/invoice", $h);
            check($ok === "$h/docs/invoice" && is_dir($ok) && (fileperms($ok) & 0777) === 0700, 'a new directory was not created 0700');
        } finally {
            emDone($fx);
        }
    },
    'email path: fopen x never replaces a file or follows a planted symlink; collisions get -1, -2' => function () {
        $fx = emFixture();
        try {
            $d = $fx['home'] . '/att';
            mkdir($d);
            $a = MailPath::writeNew($d, 'a.txt', 'one');
            $b = MailPath::writeNew($d, 'a.txt', 'two');
            check(basename($a) === 'a.txt' && basename($b) === 'a-1.txt' && file_get_contents($a) === 'one', 'collision handling wrong');
            check((fileperms($b) & 0777) === 0600, 'attachment not 0600');
            file_put_contents($fx['dir'] . '/target', 'keep');
            symlink($fx['dir'] . '/target', "$d/b.txt");
            $c = MailPath::writeNew($d, 'b.txt', 'evil');
            check(basename($c) === 'b-1.txt' && file_get_contents($fx['dir'] . '/target') === 'keep', 'a planted symlink was followed');
        } finally {
            emDone($fx);
        }
    },
    'email path: files to send must be regular files in the home, outside dot-directories, secrets and anything key-shaped' => function () {
        $fx = emFixture();
        try {
            $h = $fx['home'];
            mkdir("$h/.ssh");
            mkdir("$h/docs");
            file_put_contents("$h/.ssh/id_ed25519", 'x');
            file_put_contents("$h/docs/id_rsa", 'x');
            file_put_contents("$h/docs/server.pem", 'x');
            file_put_contents("$h/docs/notes.txt", '-----BEGIN OPENSSH ' . 'PRIVATE' . " KEY-----\nabc\n");
            file_put_contents("$h/docs/letter.txt", 'hello');
            symlink("$h/.ssh/id_ed25519", "$h/docs/link.txt");
            foreach (["$h/.ssh/id_ed25519", "$h/docs/id_rsa", "$h/docs/server.pem", "$h/docs/notes.txt", "$h/docs/link.txt", "$h/docs", $fx['dir'] . '/secrets', 'docs/letter.txt'] as $bad) {
                $refused = false;
                try {
                    MailPath::readable($bad, $h, 'the file');
                } catch (CliError $e) {
                    $refused = true;
                }
                check($refused, "readable accepted $bad");
            }
            check(MailPath::readable("$h/docs/letter.txt", $h, 'the file') === "$h/docs/letter.txt", 'a plain file was refused');
        } finally {
            emDone($fx);
        }
    },
    'email maildir: a capped delivery leaves no partial file; a second delivery of the same name is a no-op' => function () {
        $fx = emFixture();
        try {
            $d = Maildir::ensure($fx['home'] . '/mail');
            $t = new MailTransport('imaps', 'mail.example.org', 993, 'me', 'pw');
            MailTransport::$fake = function () {
                return ['body' => str_repeat('y', 70000)];
            };
            $failed = false;
            try {
                Maildir::deliver($d, 'U1.V9.pikkumies', function ($sink) use ($t) {
                    return $t->request('/', ['max' => 65536, 'sink' => $sink]);
                });
            } catch (CliError $e) {
                $failed = true;
            }
            check($failed && !glob("$d/tmp/*") && !glob("$d/new/*"), 'a capped message left a file behind');
            $n = Maildir::deliver($d, 'U2.V9.pikkumies', function ($sink) use ($t) {
                return $t->request('/', ['max' => 100000, 'sink' => $sink]);
            });
            check($n === 70000 && filesize("$d/new/U2.V9.pikkumies") === 70000, 'delivery wrong');
            check(Maildir::deliver($d, 'U2.V9.pikkumies', function () {
                throw new Exception('must not be fetched again');
            }) === 0, 'a delivered message was fetched again');
        } finally {
            MailTransport::$fake = null;
            emDone($fx);
        }
    },
];
