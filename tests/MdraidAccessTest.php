<?php
/**
 * How mdraid reaches root (ADR-0095, ADR-0004, operator directive 2026-10-20): Detect::rootAccess decides; via uid0 the
 * programs run directly and files are written in PHP, via sudo every program runs as `sudo -n <program>`, files are
 * written by `sudo -n cp` of a private temp file and disk samples are read with `sudo -n dd`, and via none a privileged
 * verb is refused with the reason and runs nothing. The same create and tune are run under each branch.
 */
require_once __DIR__ . '/mdraidSupport.php';

if (!function_exists('mdAccessBuild')) {
    /** A fixture with two blank disks and one that holds data, a create of RAID1 on D0,D1 and a tune; returns [fixture, create, tune] (results or refusal text). */
    function mdAccessBuild($access)
    {
        $f = new MdFix();
        $f->disk('sdb', 'D0', 8, [5 * MdFix::GIB => 'old data']);
        $f->disk('sdc', 'D1');
        $f->disk('sdd', 'D2');
        $res = $f->run(function () {
            $out = [];
            foreach ([function () {
                return MdBuild::create(['level' => '1', 'disks' => 'D0,D1', 'profile' => 'typical', 'destroy' => 'D0']);
            }, function () {
                return MdBuild::tune(['profile' => 'big-file', 'array' => 'md0']);
            }] as $fn) {
                try {
                    $out[] = $fn();
                } catch (CliError $e) {
                    $out[] = $e->getMessage();
                }
            }
            return $out;
        }, $access);
        return [$f, $res[0], $res[1]];
    }

    function mdAccessLines(MdFix $f)
    {
        return array_map(function ($a) {
            return implode(' ', $a);
        }, $f->calls);
    }
}

return [
    'mdraid access: uid0 runs every program directly and writes in PHP, never through sudo' => function () {
        list($f, $create, $tune) = mdAccessBuild('uid0');
        try {
            check(is_array($create) && $create['status']['verified'] && is_array($tune) && $tune['status']['verified'], 'uid0 build: ' . json_encode([$create, $tune]));
            $l = mdAccessLines($f);
            check(!preg_grep('/^sudo /', $l), 'uid0 must never use sudo: ' . json_encode(preg_grep('/^sudo /', $l)));
            check(!preg_grep('/^(cp|dd|cat|mkdir) /', $l), 'uid0 reads and writes in PHP, not with cp/dd: ' . json_encode(preg_grep('/^(cp|dd|cat|mkdir) /', $l)));
            check(strpos(file_get_contents("$f->root/etc/mdadm/mdadm.conf"), 'ARRAY /dev/md0') !== false, 'conf line not written');
        } finally {
            $f->clean();
        }
    },
    'mdraid access: sudo runs every program as sudo -n, writes by sudo -n cp of a private temp file, samples with sudo -n dd' => function () {
        $before = count((array) glob(sys_get_temp_dir() . '/pkmdw-*'));
        list($f, $create, $tune) = mdAccessBuild('sudo');
        try {
            check(is_array($create) && $create['status']['verified'] && is_array($tune) && $tune['status']['verified'], 'sudo build: ' . json_encode([$create, $tune]));
            $l = mdAccessLines($f);
            $direct = array_values(array_filter($l, function ($x) {
                return strpos($x, 'sudo -n ') !== 0 && strpos($x, 'uname ') !== 0;
            }));
            check(!$direct, 'via sudo every program but uname must run as sudo -n: ' . json_encode($direct));
            check((bool) preg_grep('#^sudo -n mdadm --create /dev/md0 #', $l) && (bool) preg_grep('#^sudo -n mkfs\.ext4 #', $l), 'create and mkfs through sudo: ' . json_encode($l));
            check((bool) preg_grep('#^sudo -n dd if=\S+/dev/disk/by-id/ata-TESTDISK_D0 bs=65536 count=1 skip=[0-9]+ iflag=skip_bytes status=none$#', $l), 'samples through sudo -n dd: ' . json_encode(preg_grep('/ dd /', $l)));
            check((bool) preg_grep('#^sudo -n cp --preserve=mode /\S+ \S+/var/lib/pikkumies-mdraid/actions\.log$#', $l), 'the action log is copied with its 0600 mode: ' . json_encode(preg_grep('/actions/', $l)));
            check((bool) preg_grep('#^sudo -n cp /\S+ \S+/etc/mdadm/mdadm\.conf$#', $l) && (bool) preg_grep('#^sudo -n cp /\S+ \S+/sys/block/md0/queue/read_ahead_kb$#', $l)
                && (bool) preg_grep('#^sudo -n cp /\S+ \S+/etc/systemd/system/pikkumies-mdraid-tune\.service$#', $l), 'writes through sudo -n cp: ' . json_encode(preg_grep('/ cp /', $l)));
            check((bool) preg_grep('#^sudo -n mkdir -p -m 0700 \S+/var/lib/pikkumies-mdraid$#', $l), 'the record directory is made 0700 through sudo: ' . json_encode(preg_grep('/mkdir/', $l)));
            $conf = file_get_contents("$f->root/etc/mdadm/mdadm.conf");
            check(substr_count($conf, 'ARRAY /dev/md0') === 1 && strpos($conf, 'MAILADDR root') !== false, 'the append through cp kept the old lines: ' . $conf);
            check(trim(file_get_contents("$f->root/sys/block/md0/queue/read_ahead_kb")) === '4096', 'tune value not written through sudo');
            check(count((array) glob(sys_get_temp_dir() . '/pkmdw-*')) === $before, 'a temp file of the sudo write path was left behind');
            $st = $f->run(function () {
                return MdVerbs::status(['array' => 'md0']);
            }, 'sudo');
            check(isset($st[0]['verdict']['verdict']) && (bool) preg_grep('#^sudo -n mdadm --examine #', mdAccessLines($f)), 'status reads superblocks through sudo: ' . json_encode($st[0]));
        } finally {
            $f->clean();
        }
    },
    'mdraid access: none refuses every privileged verb with the reason and runs nothing; status still works' => function () {
        list($f, $create, $tune) = mdAccessBuild('none');
        try {
            foreach ([$create, $tune] as $r) {
                check(is_string($r) && strpos($r, 'root is not available here') !== false && strpos($r, 'passwordless sudo is not available') !== false, 'refusal with the reason: ' . json_encode($r));
            }
            check(!$f->calls, 'via none nothing may run: ' . json_encode(mdAccessLines($f)));
            foreach (['disks' => [], 're-add' => ['md0', 'D0'], 'add' => ['md0', 'D0'], 'assemble' => ['md0'], 'check' => ['md0'], 'idle' => ['md0']] as $verb => $args) {
                $msg = $f->run(function () use ($verb, $args) {
                    $t = MdVerbs::table();
                    try {
                        $t[$verb]($args, []);
                        return 'ran';
                    } catch (CliError $e) {
                        return $e->getMessage();
                    }
                }, 'none');
                check(strpos($msg, 'root is not available here') !== false, "$verb via none: $msg");
            }
            $f->addArray('md0', 'raid1', 2, ['sdb' => 0, 'sdc' => 1]);
            $st = $f->run(function () {
                return MdVerbs::status([]);
            }, 'none');
            $rootRec = array_values(array_filter($st, function ($r) {
                return isset($r['root']);
            }));
            check($rootRec && $rootRec[0]['root'] === 'none' && !preg_grep('/^(sudo |mdadm --examine)/', mdAccessLines($f)), 'status without root: ' . json_encode($rootRec));
        } finally {
            $f->clean();
        }
    },
    'mdraid access: with no test seam the answer is Detect::rootAccess of this process, and MdRun has no sudo probe of its own' => function () {
        MdRun::$access = null;
        $d = Detect::rootAccess(function_exists('posix_geteuid') ? posix_geteuid() : -1);
        check(MdRun::access() === ($d['available'] ? $d['via'] : 'none'), 'MdRun::access disagrees with Detect::rootAccess: ' . MdRun::access() . ' vs ' . json_encode($d));
        $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/server-admin/mdraid/MdRun.php');
        check(strpos($src, 'Detect::rootAccess(') !== false && strpos($src, 'sudo -n -k -l\'') === false && !preg_match('/\bexec\(|shell_exec|posix_geteuid\(\) === 0 \?/', $src), 'MdRun must ask Detect, not probe sudo or uid itself');
        foreach (glob(dirname(__DIR__) . '/pikkumies/tools/server-admin/mdraid/*.md') as $doc) {
            $text = (string) file_get_contents($doc);
            check(!preg_match('#\bas:? `?sudo php|\bsudo php [~</]#', $text) && strpos($text, 'never `sudo php`') !== false, basename($doc) . ' must say never sudo php, and never tell the agent to run it');
        }
    },
];
