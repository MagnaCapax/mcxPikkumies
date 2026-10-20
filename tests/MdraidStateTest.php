<?php
/**
 * mdraid status and the parsers under it (ADR-0095): /proc/mdstat lines, mdadm --examine, mdadm.conf, and the N-1
 * verdict on clean, degraded, recovering, failed, two-stale and inactive arrays. Fixtures only; no real disk.
 */
require_once __DIR__ . '/mdraidSupport.php';

if (!function_exists('mdStatusOf')) {
    /** The status record of $md. */
    function mdStatusOf(array $records, $md)
    {
        foreach ($records as $r) {
            if (isset($r['array']) && $r['array'] === $md) {
                return $r;
            }
        }
        throw new Exception("no status record for $md");
    }
}

return [
    'mdraid state: mdstat parses active, auto-read-only, degraded, recovering and inactive arrays' => function () {
        $text = "Personalities : [raid1] [raid5]\n"
            . "md0 : active raid5 sdd[3] sdc[1] sdb[0]\n      1953260544 blocks super 1.2 level 5, 512k chunk, algorithm 2 [3/3] [UUU]\n      bitmap: 0/8 pages [0KB], 65536KB chunk\n\n"
            . "md1 : active (auto-read-only) raid1 sdf[1] sde[0]\n      976630464 blocks super 1.2 [2/2] [UU]\n\n"
            . "md2 : active raid5 sdh[1] sdg[0] sdi[3](F)\n      100 blocks super 1.2 level 5, 512k chunk, algorithm 2 [3/2] [UU_]\n\n"
            . "md3 : active raid1 sdk[2] sdj[0]\n      100 blocks super 1.2 [2/1] [U_]\n      [=>...................]  recovery =  7.5% (1/10) finish=10.0min speed=100K/sec\n\n"
            . "md127 : inactive sdl[1](S)\n      100 blocks super 1.2\n\nunused devices: <none>\n";
        $a = MdState::parseMdstat($text);
        check(array_keys($a) === ['md0', 'md1', 'md2', 'md3', 'md127'], 'arrays: ' . implode(',', array_keys($a)));
        check($a['md0']['level'] === 'raid5' && $a['md0']['map'] === 'UUU' && count($a['md0']['members']) === 3, 'md0 parsed wrong: ' . json_encode($a['md0']));
        check($a['md1']['readonly'] && $a['md1']['level'] === 'raid1', 'auto-read-only lost: ' . json_encode($a['md1']));
        check($a['md2']['map'] === 'UU_' && $a['md2']['members'][2]['flags'] === 'F', 'faulty member lost: ' . json_encode($a['md2']['members']));
        check($a['md3']['action'] === 'recovery' && $a['md3']['progress'] === 7.5, 'recovery progress lost: ' . json_encode($a['md3']));
        check(!$a['md127']['active'] && $a['md127']['level'] === '' && $a['md127']['members'][0]['kname'] === 'sdl', 'inactive array wrong: ' . json_encode($a['md127']));
    },
    'mdraid state: the N-1 verdict is consistent, n-1, or stop for two behind, too few, a doubled role' => function () {
        $m = function ($events, $role, $spare = false) {
            return ['events' => $events, 'role' => $role, 'spare' => $spare, 'updated' => 'T'];
        };
        check(MdState::verdict(['a' => $m(9, 0), 'b' => $m(9, 1), 'c' => $m(9, 2)], 'raid5', 3)['verdict'] === 'consistent', 'all equal must be consistent');
        $v = MdState::verdict(['a' => $m(9, 0), 'b' => $m(9, 1), 'c' => $m(4, 2)], 'raid5', 3);
        check($v['verdict'] === 'n-1' && $v['stale'] === ['c'] && $v['group'] === ['a', 'b'], 'one behind must be n-1 naming c: ' . json_encode($v));
        check(MdState::verdict(['a' => $m(9, 0), 'b' => $m(5, 1), 'c' => $m(4, 2)], 'raid5', 3)['verdict'] === 'stop', 'two behind must stop');
        check(MdState::verdict(['a' => $m(9, 0), 'b' => $m(9, 1), 'c' => $m(9, 2), 'd' => $m(8, 3), 'e' => $m(7, 4)], 'raid6', 5)['verdict'] === 'stop', 'two behind stops on RAID6 too');
        check(MdState::verdict(['a' => $m(9, 0)], 'raid5', 3)['verdict'] === 'stop', 'one of three cannot run a RAID5');
        check(MdState::verdict(['a' => $m(9, 0), 'b' => $m(9, 0)], 'raid1', 2)['verdict'] === 'stop', 'two members with one role must stop');
        check(MdState::verdict(['a' => $m(9, 0), 'b' => $m(9, 1), 's' => $m(3, null, true)], 'raid1', 2)['verdict'] === 'consistent', 'a spare is not a stale member');
        check(MdState::verdict(['a' => $m(9, 0)], 'raid1', 2)['verdict'] === 'consistent', 'one mirror of two runs (degraded but consistent)');
    },
    'mdraid state: mdadm.conf lines and MAILADDR parse; examine reads uuid, events, role and spare' => function () {
        $c = MdState::parseConf("# comment\nMAILADDR ops@example.org\nARRAY /dev/md0 metadata=1.2 name=host:0 UUID=AAAA0000:bbbb1111:cccc2222:dddd3333\nARRAY /dev/md/1 UUID=eeee:ffff:0000:1111 # tail\n");
        check($c['mailaddr'] === 'ops@example.org' && count($c['arrays']) === 2, 'conf parse: ' . json_encode($c));
        check($c['arrays'][0]['uuid'] === 'aaaa0000:bbbb1111:cccc2222:dddd3333' && $c['arrays'][0]['name'] === 'host:0' && $c['arrays'][1]['device'] === '/dev/md/1', 'conf fields: ' . json_encode($c['arrays']));
        $e = MdState::examine("     Array UUID : 1:2:3:4\n           Name : host:0  (local to host host)\n     Raid Level : raid5\n   Raid Devices : 4\n         Events : 8512\n    Device Role : Active device 2\n");
        check($e['uuid'] === '1:2:3:4' && $e['name'] === 'host:0' && $e['events'] === 8512 && $e['role'] === 2 && $e['devices'] === 4 && !$e['spare'], 'examine: ' . json_encode($e));
        check(MdState::examine("    Device Role : spare\n         Events : 3\n")['spare'], 'a spare role must read as spare');
        check(MdState::examine('')['uuid'] === '' && MdState::examine('')['events'] === -1, 'no superblock: empty uuid, events -1');
    },
    'mdraid state: status reports clean, degraded, recovering, failed, two-stale and inactive arrays with the right verdicts' => function () {
        $f = new MdFix();
        try {
            foreach (['sdb', 'sdc', 'sdd', 'sde', 'sdf', 'sdg', 'sdh', 'sdi', 'sdj', 'sdk', 'sdl', 'sdm', 'sdn', 'sdo'] as $i => $k) {
                $f->disk($k, 'SER' . $i);
            }
            $f->addArray('md0', 'raid5', 3, ['sdb' => 0, 'sdc' => 1, 'sdd' => 2]);                                   // clean
            $f->addArray('md1', 'raid1', 2, ['sde' => 0]);                                                          // degraded
            $f->addArray('md2', 'raid1', 2, ['sdf' => 0, 'sdg' => 1], 100, ['action' => 'recovery', 'progress' => 12.5]); // recovering
            $f->addArray('md3', 'raid5', 3, ['sdh' => 0], 100);                                                     // failed: two missing
            $f->addArray('md4', 'raid5', 3, ['sdi' => 0, 'sdj' => 1, 'sdk' => 2], ['sdi' => 50, 'sdj' => 40, 'sdk' => 30], ['active' => false]); // two stale
            $f->addArray('md5', 'raid5', 3, ['sdl' => 0, 'sdm' => 1, 'sdn' => 2], ['sdl' => 70, 'sdm' => 70, 'sdn' => 69], ['active' => false]); // inactive, n-1
            $f->conf('ARRAY /dev/md0 metadata=1.2 name=host:0 UUID=' . $f->arrays['md0']['uuid']);
            $rec = $f->run(function () {
                return MdVerbs::status([]);
            });
            $md0 = mdStatusOf($rec, 'md0');
            check($md0['verdict']['verdict'] === 'consistent' && $md0['degraded'] === 0 && $md0['mdadm_conf'] === 'listed', 'md0: ' . json_encode($md0));
            check($md0['members'][0]['disk'] === 'ata-TESTDISK_SER0' && $md0['members'][0]['serial'] === 'SER0', 'members by by-id and serial: ' . json_encode($md0['members'][0]));
            $md1 = mdStatusOf($rec, 'md1');
            check($md1['degraded'] === 1 && $md1['map'] === 'U_' && strpos($md1['mdadm_conf'], 'missing') === 0, 'md1 degraded: ' . json_encode($md1));
            $md2 = mdStatusOf($rec, 'md2');
            check($md2['sync_action'] === 'recovery' && $md2['progress_pct'] === 12.5, 'md2 recovering: ' . json_encode($md2));
            $md3 = mdStatusOf($rec, 'md3');
            check($md3['degraded'] === 2 && $md3['verdict']['verdict'] === 'stop', 'md3 failed must stop: ' . json_encode($md3));
            $md4 = mdStatusOf($rec, 'md4');
            check(!$md4['active'] && $md4['verdict']['verdict'] === 'stop' && count($md4['verdict']['stale']) === 2, 'md4 two stale must stop: ' . json_encode($md4));
            $md5 = mdStatusOf($rec, 'md5');
            check($md5['verdict']['verdict'] === 'n-1' && $md5['verdict']['stale'] === ['ata-TESTDISK_SER12'], 'md5 inactive n-1 names the stale member: ' . json_encode($md5));
            $mon = end($rec);
            check(!empty($mon['monitoring']) && $mon['mailaddr'] === 'root' && $mon['mail_agent'] === false && $mon['note'] !== null, 'monitoring record: ' . json_encode($mon));
            check(!$f->writes(), 'status ran a write: ' . json_encode($f->writes()));
        } finally {
            $f->clean();
        }
    },
    'mdraid state: status without root still reads mdstat and sysfs, and says what it could not compare' => function () {
        $f = new MdFix();
        try {
            $f->disk('sdb', 'A');
            $f->disk('sdc', 'B');
            $f->addArray('md0', 'raid1', 2, ['sdb' => 0, 'sdc' => 1]);
            $rec = $f->run(function () {
                return MdVerbs::status(['array' => 'md0', 'profile' => 'big-file']);
            }, 'none');
            $r = mdStatusOf($rec, 'md0');
            check($r['level'] === 'raid1' && is_string($r['verdict']) && strpos($r['verdict'], 'as root') !== false, 'non-root status: ' . json_encode($r));
            check($r['tunables']['profile_wants']['read_ahead_kb'] === 4096 && $r['tunables']['profile_wants']['stripe_cache_size'] === null, 'profile comparison: ' . json_encode($r['tunables']));
            check(!preg_grep('/--examine/', array_map('implode', array_fill(0, count($f->calls), ' '), $f->calls)), 'no superblock read without root');
        } finally {
            $f->clean();
        }
    },
    'mdraid state: the tool refuses a disk named sdX and an array name that is not mdN' => function () {
        $f = new MdFix();
        try {
            $f->disk('sdb', 'A');
            $msg = $f->run(function () {
                return mdRefusal(function () {
                    MdDisks::resolve('/dev/sdb', MdDisks::whole(), MdDisks::serials());
                });
            });
            check(strpos($msg, 'kernel name') !== false, 'sdX must be refused as a kernel name: ' . $msg);
            check(strpos(mdRefusal(function () {
                MdState::name('/dev/sdb');
            }), 'mdN') !== false, 'a non-md array name must be refused');
            $k = $f->run(function () {
                return [MdDisks::resolve('ata-TESTDISK_A', MdDisks::whole(), MdDisks::serials()), MdDisks::resolve('A', MdDisks::whole(), MdDisks::serials())];
            });
            check($k === ['sdb', 'sdb'], 'by-id name and serial must both resolve: ' . json_encode($k));
            $f->disk('sdc', 'TWIN');
            $f->disk('sdd', 'TWIN2');
            $f->disks['sdd']['serial'] = 'TWIN';
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdDisks::resolve('TWIN', MdDisks::whole(), MdDisks::serials());
                });
            }), 'belongs to 2 disks') !== false, 'a serial two disks share must be refused');
        } finally {
            $f->clean();
        }
    },
];
