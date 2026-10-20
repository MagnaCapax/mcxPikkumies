<?php
/**
 * mdraid recovery verbs (ADR-0095): re-add, add, replace, assemble, check and idle, each against the fixture's live
 * state: the conditions that refuse (not degraded, failed array, slot taken, kernel line, two stale, held disks, data
 * without --destroy), the order of what runs, the --examine snapshot before any write, and the read-back.
 */
require_once __DIR__ . '/mdraidSupport.php';

if (!function_exists('mdLines')) {
    /** The commands the fake saw, as space-joined lines. */
    function mdLines(MdFix $f)
    {
        return array_map(function ($a) {
            return implode(' ', $a);
        }, $f->calls);
    }

    /** A raid1 md0 of sdb+sdc with sdc dropped out (its superblock still names md0, slot 1). */
    function mdDegradedMirror()
    {
        $f = new MdFix();
        $f->disk('sdb', 'A');
        $f->disk('sdc', 'B');
        $f->addArray('md0', 'raid1', 2, ['sdb' => 0, 'sdc' => 1]);
        unset($f->arrays['md0']['members']['sdc']);
        $f->sync();
        return $f;
    }

    /** A raid5 md0 of $n disks (sdb...) with the last one dropped out. */
    function mdDegradedParity($n)
    {
        $f = new MdFix();
        $members = [];
        foreach (array_slice(['sdb', 'sdc', 'sdd', 'sde', 'sdf', 'sdg'], 0, $n) as $i => $k) {
            $f->disk($k, 'P' . $i);
            $members[$k] = $i;
        }
        $f->addArray('md0', 'raid5', $n, $members);
        end($members);
        unset($f->arrays['md0']['members'][key($members)]);
        $f->sync();
        return [$f, key($members)];
    }
}

return [
    're-add: the same disk goes back into its own vacant slot, after the snapshot, and is read back' => function () {
        $f = mdDegradedMirror();
        try {
            $r = $f->run(function () {
                return MdRecover::readd('md0', 'ata-TESTDISK_B');
            });
            check($r['status']['verified'] === true && $r['records'][1]['member'] === 'ata-TESTDISK_B', 're-add result: ' . json_encode($r));
            check(is_file($f->root . $r['records'][0]['snapshot']) && strpos(file_get_contents($f->root . $r['records'][0]['snapshot']), 'Array UUID') !== false, 'snapshot missing or empty');
            check(in_array('mdadm /dev/md0 --re-add /dev/disk/by-id/ata-TESTDISK_B', mdLines($f), true), 'no re-add run: ' . json_encode(mdLines($f)));
        } finally {
            $f->clean();
        }
    },
    're-add: refused when not degraded, on a failed array, when the slot is taken, without the superblock, without root, or held by another array' => function () {
        $f = new MdFix();
        try {
            $f->disk('sdb', 'A');
            $f->disk('sdc', 'B');
            $f->addArray('md0', 'raid1', 2, ['sdb' => 0, 'sdc' => 1]);
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::readd('md0', 'B');
                });
            }), 'not degraded') !== false, 'a full array must refuse');
        } finally {
            $f->clean();
        }
        list($f) = mdDegradedParity(3);
        try {
            unset($f->arrays['md0']['members']['sdc']); // two of three missing: failed
            $f->sync();
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::readd('md0', 'P2');
                });
            }), 'has failed') !== false, 'a failed array must refuse re-add');
        } finally {
            $f->clean();
        }
        $f = mdDegradedMirror();
        try {
            $f->disk('sdd', 'C');
            $f->disks['sdd']['examine'] = ['uuid' => $f->arrays['md0']['uuid'], 'md' => 'md0', 'level' => 'raid1', 'devices' => 2, 'role' => 0, 'events' => 50];
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::readd('md0', 'C');
                });
            }), 'not vacant') !== false, 'a disk whose slot is taken must refuse');
            $f->disk('sde', 'D');
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::readd('md0', 'D');
                });
            }), "superblock") !== false, 'a disk without the superblock must refuse');
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::readd('md0', 'B');
                });
            }, 'none'), 'needs root') !== false, 'without root it must refuse');
            $f->addArray('md127', 'raid1', 2, ['sdc' => 1], 100, ['active' => false, 'uuid' => $f->arrays['md0']['uuid']]);
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::readd('md0', 'B');
                });
            }), 'held by md127') !== false, 'a disk held by another array must refuse');
            check(!preg_grep('/--re-add/', mdLines($f)), 're-add ran after a refusal');
        } finally {
            $f->clean();
        }
    },
    're-add: the kernel line refuses parity with a bitmap and 3 or 5 data disks on an affected or unknown kernel, not on a fixed one or 4 data disks' => function () {
        foreach ([[4, '6.12.95+deb12-amd64', true], [4, '6.12.0-1-amd64', true], [6, '7.2.5', true], [4, '6.12.110', false], [4, '6.1.190', false], [4, '6.1.0-52-amd64', true], [5, '6.12.95+deb12-amd64', false]] as $case) {
            list($f, $out) = mdDegradedParity($case[0]);
            $f->release = $case[1];
            $f->procVersion = "Linux version {$case[1]}";
            try {
                $res = $f->run(function () use ($out, $f) {
                    try {
                        return MdRecover::readd('md0', $f->disks[$out]['serial']);
                    } catch (CliError $e) {
                        return $e->getMessage();
                    }
                });
                $refused = is_string($res) && strpos($res, 'kernel') !== false;
                check($refused === $case[2], json_encode($case) . ': ' . json_encode($res));
            } finally {
                $f->clean();
            }
        }
    },
    're-add: a member the kernel marks faulty is removed first, then re-added' => function () {
        $f = mdDegradedMirror();
        try {
            $f->arrays['md0']['members']['sdc'] = 1;
            $f->arrays['md0']['faulty'] = ['sdc'];
            $f->sync();
            $r = $f->run(function () {
                return MdRecover::readd('md0', 'B');
            });
            $l = mdLines($f);
            $rm = array_search('mdadm /dev/md0 --remove /dev/disk/by-id/ata-TESTDISK_B', $l, true);
            $ra = array_search('mdadm /dev/md0 --re-add /dev/disk/by-id/ata-TESTDISK_B', $l, true);
            check($rm !== false && $ra !== false && $rm < $ra && $r['status']['verified'], 'remove then re-add: ' . json_encode($l));
        } finally {
            $f->clean();
        }
    },
    'add: a blank disk joins a degraded array; data needs its serial in --destroy and is wiped first; a former member is pointed to re-add' => function () {
        $f = mdDegradedMirror();
        try {
            $f->disk('sdd', 'NEW');
            $f->disk('sde', 'USED', 8, [], 'ext4');
            $msg = $f->run(function () {
                return mdRefusal(function () {
                    MdRecover::add('md0', 'USED', []);
                });
            });
            check(strpos($msg, 'holds data') !== false && strpos($msg, 'sde:ext4') !== false, 'data without --destroy must refuse naming the signature: ' . $msg);
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::add('md0', 'USED', ['NEW']);
                });
            }), 'holds data') !== false, 'a --destroy serial of another disk does not cover this one');
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::add('md0', 'NEW', ['USED']);
                });
            }), 'not one of the selected disks') !== false, 'a --destroy serial must belong to a selected disk');
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::add('md0', 'B', []);
                });
            }), 'use re-add') !== false, 'a former member must be pointed to re-add');
            check(!$f->writes(), 'a refused add wrote: ' . json_encode($f->writes()));
            $r = $f->run(function () {
                return MdRecover::add('md0', 'USED', ['USED']);
            });
            $l = mdLines($f);
            $wipe = array_search('wipefs -a /dev/disk/by-id/ata-TESTDISK_USED', $l, true);
            $add = array_search('mdadm /dev/md0 --add /dev/disk/by-id/ata-TESTDISK_USED', $l, true);
            check($wipe !== false && $add !== false && $wipe < $add && $r['status']['verified'], 'wipe then add: ' . json_encode($l));
        } finally {
            $f->clean();
        }
        $f = mdDegradedMirror();
        try {
            $f->disk('sdd', 'SMALL', 2);
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::add('md0', 'SMALL', []);
                });
            }), 'smaller') !== false, 'a disk smaller than the members must refuse');
        } finally {
            $f->clean();
        }
    },
    'replace: a working member is replaced with --replace --with on a full array; refused on a degraded one; a faulty one is removed then the new disk added' => function () {
        $f = new MdFix();
        try {
            $f->disk('sdb', 'A');
            $f->disk('sdc', 'B');
            $f->disk('sdd', 'NEW');
            $f->addArray('md0', 'raid1', 2, ['sdb' => 0, 'sdc' => 1]);
            $f->run(function () {
                return MdRecover::replace('md0', 'B', 'NEW', []);
            });
            $l = mdLines($f);
            check(in_array('mdadm /dev/md0 --add /dev/disk/by-id/ata-TESTDISK_NEW', $l, true)
                && in_array('mdadm /dev/md0 --replace /dev/disk/by-id/ata-TESTDISK_B --with /dev/disk/by-id/ata-TESTDISK_NEW', $l, true), 'add then replace --with: ' . json_encode($l));
        } finally {
            $f->clean();
        }
        $f = mdDegradedMirror();
        try {
            $f->disk('sdd', 'NEW');
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::replace('md0', 'A', 'NEW', []);
                });
            }), 'degraded') !== false, 'replacing a working member of a degraded array must refuse');
            $f->arrays['md0']['members']['sdc'] = 1;
            $f->arrays['md0']['faulty'] = ['sdc'];
            $f->sync();
            $f->run(function () {
                return MdRecover::replace('md0', 'B', 'NEW', []);
            });
            $l = mdLines($f);
            check(in_array('mdadm /dev/md0 --remove /dev/disk/by-id/ata-TESTDISK_B', $l, true) && !preg_grep('/--replace/', $l), 'faulty: remove then add, no --replace: ' . json_encode($l));
        } finally {
            $f->clean();
        }
    },
    'assemble: read-only from the newest-Events group, one stale member left out, --run when short, two stale refused untouched' => function () {
        $f = new MdFix();
        try {
            foreach (['sdb', 'sdc', 'sdd'] as $i => $k) {
                $f->disk($k, 'S' . $i);
            }
            $f->addArray('md5', 'raid5', 3, ['sdb' => 0, 'sdc' => 1, 'sdd' => 2], ['sdb' => 70, 'sdc' => 70, 'sdd' => 60], ['active' => false]);
            $r = $f->run(function () {
                return MdRecover::assemble('md5', false);
            });
            $l = mdLines($f);
            check(in_array('mdadm --stop /dev/md5', $l, true), 'the inactive array must be stopped first: ' . json_encode($l));
            $asm = array_values(preg_grep('/^mdadm --assemble/', $l));
            check(count($asm) === 1 && strpos($asm[0], '--readonly') !== false && strpos($asm[0], '--run') !== false && strpos($asm[0], 'TESTDISK_S2') === false && strpos($asm[0], '--force') === false,
                'read-only, --run, stale member left out, no force: ' . json_encode($asm));
            check($r['records'][1]['left_out'] === ['ata-TESTDISK_S2'] && $r['records'][1]['verdict'] === 'n-1' && $r['status']['verified'] && $f->arrays['md5']['readonly'], 'assemble result: ' . json_encode($r['records'][1]));
        } finally {
            $f->clean();
        }
        $f = new MdFix();
        try {
            foreach (['sdb', 'sdc', 'sdd'] as $i => $k) {
                $f->disk($k, 'S' . $i);
            }
            $f->addArray('md5', 'raid5', 3, ['sdb' => 0, 'sdc' => 1, 'sdd' => 2], ['sdb' => 70, 'sdc' => 60, 'sdd' => 50], ['active' => false]);
            $msg = $f->run(function () {
                return mdRefusal(function () {
                    MdRecover::assemble('md5', false);
                });
            });
            check(strpos($msg, '2 members are behind') !== false && strpos($msg, 'nothing was done') !== false, 'two stale must refuse: ' . $msg);
            check(!$f->writes(), 'two stale: something was written: ' . json_encode($f->writes()));
        } finally {
            $f->clean();
        }
    },
    'assemble: --force only after a plain assemble failed and with the consistent group only; --writable only on an assembled read-only array' => function () {
        $f = new MdFix();
        try {
            foreach (['sdb', 'sdc', 'sdd'] as $i => $k) {
                $f->disk($k, 'S' . $i);
            }
            $uuid = $f->addArray('md5', 'raid5', 3, ['sdb' => 0, 'sdc' => 1, 'sdd' => 2], ['sdb' => 70, 'sdc' => 70, 'sdd' => 69]);
            unset($f->arrays['md5']); // stopped, known from mdadm.conf
            $f->sync();
            $f->conf("ARRAY /dev/md5 metadata=1.2 UUID=$uuid");
            $f->fail = ['mdadm --assemble --readonly /dev/md5' => [1, 'mdadm: cannot start dirty degraded array']];
            $f->run(function () {
                return MdRecover::assemble('md5', false);
            });
            $force = array_values(preg_grep('/--force/', mdLines($f)));
            check(count($force) === 1 && strpos($force[0], 'TESTDISK_S0') !== false && strpos($force[0], 'TESTDISK_S1') !== false && strpos($force[0], 'TESTDISK_S2') === false,
                'force retry with the consistent group only: ' . json_encode(mdLines($f)));
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::assemble('md5', false);
                });
            }), 'already running') !== false, 'a running array must not be assembled again');
            $f->run(function () {
                return MdRecover::assemble('md5', true);
            });
            check(in_array('mdadm --readwrite /dev/md5', mdLines($f), true) && !$f->arrays['md5']['readonly'], '--writable must switch to read-write');
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::assemble('md6', true);
                });
            }), 'not running') !== false, '--writable on a stopped array must refuse');
        } finally {
            $f->clean();
        }
    },
    'check and idle: check is refused on a degraded or busy array, writes check otherwise; idle writes idle' => function () {
        $f = mdDegradedMirror();
        try {
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::action('md0', 'check');
                });
            }), 'degraded') !== false, 'check on a degraded array must refuse');
        } finally {
            $f->clean();
        }
        $f = new MdFix();
        try {
            $f->disk('sdb', 'A');
            $f->disk('sdc', 'B');
            $f->addArray('md0', 'raid1', 2, ['sdb' => 0, 'sdc' => 1], 100, ['action' => 'resync', 'progress' => 3.0]);
            check(strpos($f->run(function () {
                return mdRefusal(function () {
                    MdRecover::action('md0', 'check');
                });
            }), 'busy') !== false, 'check during a resync must refuse');
            $r = $f->run(function () {
                return MdRecover::action('md0', 'idle');
            });
            check(trim(file_get_contents("$f->root/sys/block/md0/md/sync_action")) === 'idle' && $r['status']['verified'], 'idle not written');
            $r = $f->run(function () {
                return MdRecover::action('md0', 'check');
            });
            check(trim(file_get_contents("$f->root/sys/block/md0/md/sync_action")) === 'check' && $r['status']['verified'], 'check not written');
        } finally {
            $f->clean();
        }
    },
];
