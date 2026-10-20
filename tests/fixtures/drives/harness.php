<?php
/**
 * Helpers for the drives tests: a temp tree with a fake /proc and /sys, a bin directory holding only the fake programs
 * (lsblk, smartctl, badblocks, sudo, fuser, blkid) plus the real sh, flock, head, nohup and cat that the detached pass needs, and a
 * wrapper that runs drives.php with PATH and its seam set. No real disk is ever touched.
 */
const DR_REAL = ['sh', 'flock', 'head', 'nohup', 'cat', 'sleep'];

/** The default machine: a blank SATA disk, a SAS md member, a disk asleep, an NVMe system disk, a SATA disk with data. */
function drState()
{
    $disk = function ($name, $serial, $size, $rota, array $children = [], $tran = 'sata') {
        return ['name' => $name, 'serial' => $serial, 'wwn' => null, 'model' => 'TEST', 'size' => $size, 'rota' => $rota, 'type' => 'disk',
            'tran' => $tran, 'fstype' => null, 'pttype' => $children ? 'gpt' : null, 'mountpoint' => null, 'children' => $children];
    };
    return [
        'disks' => [
            $disk('sda', 'TSATA0001', 8001563222016, true),
            $disk('sdb', 'TSAS0001', 10000831348736, true, [['name' => 'sdb1', 'fstype' => 'linux_raid_member', 'mountpoint' => null]], 'sas'),
            $disk('sdc', 'TSTBY0001', 4000787030016, true),
            $disk('nvme0n1', 'TNVME0001', 1000204886016, false, [['name' => 'nvme0n1p1', 'fstype' => 'ext4', 'mountpoint' => '/']], 'nvme'),
            $disk('sdd', 'TSATA0002', 8001563222016, true, [['name' => 'sdd1', 'fstype' => 'ext4', 'mountpoint' => null]]),
        ],
        'smart' => ['/dev/sda' => 'sata-clean', '/dev/sdb' => 'sas', '/dev/sdc' => 'standby', '/dev/nvme0n1' => 'nvme', '/dev/sdd' => 'sata-pending'],
        'selftest' => [], 'override' => [], 'busy' => [],
        'blkid' => ['/dev/sdb' => 'PTTYPE=gpt', '/dev/sdb1' => 'TYPE=linux_raid_member', '/dev/nvme0n1' => 'PTTYPE=gpt', '/dev/nvme0n1p1' => 'TYPE=ext4',
            '/dev/sdd' => 'PTTYPE=gpt', '/dev/sdd1' => 'TYPE=ext4'],
        'badblocks' => ['mode' => 'clean', 'sleep' => 0],
    ];
}

function drFixture(array $state = null)
{
    $dir = sys_get_temp_dir() . '/pk-drives-' . getmypid() . '-' . mt_rand();
    $fx = ['dir' => $dir, 'bin' => "$dir/bin", 'local' => "$dir/local", 'proc' => "$dir/proc", 'sys' => "$dir/sys"];
    foreach ([$fx['bin'], $fx['local'], $fx['proc'] . '/self', $fx['sys'] . '/block'] as $d) {
        mkdir($d, 0700, true);
    }
    foreach (['fakelib.php', 'lsblk', 'smartctl', 'badblocks', 'sudo', 'fuser', 'blkid'] as $f) {
        copy(__DIR__ . "/bin/$f", $fx['bin'] . "/$f");
        chmod($fx['bin'] . "/$f", 0755);
    }
    symlink(PHP_BINARY, $fx['bin'] . '/php');
    foreach (DR_REAL as $p) {
        $real = ToolProc::which($p);
        if ($real === null) {
            skip("$p is not installed here");
        }
        symlink($real, $fx['bin'] . "/$p");
    }
    file_put_contents($fx['proc'] . '/mdstat', "Personalities : [raid1]\nmd0 : active raid1 sdb1[0] sde1[1]\n      9766436864 blocks super 1.2 [2/2] [UU]\n\nunused devices: <none>\n");
    file_put_contents($fx['proc'] . '/self/mounts', "/dev/nvme0n1p1 / ext4 rw,relatime 0 0\nproc /proc proc rw 0 0\n");
    file_put_contents($fx['proc'] . '/swaps', "Filename Type Size Used Priority\n");
    file_put_contents("$dir/state.json", json_encode($state !== null ? $state : drState(), JSON_UNESCAPED_SLASHES));
    touch("$dir/argv.log");
    return $fx;
}

/** Runs drives.php with $args; $seam adds to or replaces the default seam. Returns [exit code, records, status]. */
function drRun(array $fx, array $args, array $seam = [])
{
    $seam += ['proc' => $fx['proc'], 'sys' => $fx['sys'], 'root' => ['available' => true, 'via' => 'uid0'], 'virt' => 'none',
        'local' => $fx['local'], 'telemetry' => 'off'];
    $code = "<?php\n";
    foreach (['PATH' => $fx['bin'], 'FAKE_DIR' => $fx['dir'], 'FAKE_FIXTURES' => __DIR__, 'HOME' => $fx['dir']] as $k => $v) {
        $code .= 'putenv(' . var_export("$k=$v", true) . ");\n";
    }
    $code .= '$drivesSeam = ' . var_export($seam, true) . ";\n";
    $code .= 'require ' . var_export(dirname(__DIR__, 3) . '/pikkumies/tools/hardware/drives/drives.php', true) . ";\n";
    file_put_contents($fx['dir'] . '/run.php', $code);
    list($exit, $out) = runPhp($fx['dir'] . '/run.php', $args);
    clearstatcache();
    $lines = array_values(array_filter(explode("\n", trim($out)), 'strlen'));
    $status = $lines ? json_decode(array_pop($lines), true) : null;
    return [$exit, array_map(function ($l) {
        return json_decode($l, true);
    }, $lines), $status, $out];
}

/** The record of one serial (case-insensitive) among smart's records, or null. */
function drRecord(array $records, $serial)
{
    foreach ($records as $r) {
        if (isset($r['serial'], $r['screen']) && strcasecmp($r['serial'], $serial) === 0) {
            return $r;
        }
    }
    return null;
}

/** Waits (at most 10 s) until the drive's pass no longer holds its lock. */
function drWait(array $fx, $key)
{
    $dir = $fx['local'] . "/drives/$key";
    for ($i = 0; $i < 100 && DrivesVerify::running($dir); $i++) {
        usleep(100000);
    }
    check(!DrivesVerify::running($dir), "the pass of $key did not end");
    usleep(100000);
}

function drState2(array $fx)
{
    return json_decode((string) file_get_contents($fx['dir'] . '/state.json'), true);
}

function drCalls(array $fx, $bin)
{
    $out = [];
    foreach (array_filter(explode("\n", (string) file_get_contents($fx['dir'] . '/argv.log')), 'strlen') as $l) {
        $c = json_decode($l, true);
        if ($c['bin'] === $bin) {
            $out[] = implode(' ', $c['argv']);
        }
    }
    return $out;
}

/** The parsed fields of one fixture document. */
function drParse($name)
{
    return DrivesSmart::parse(json_decode((string) file_get_contents(__DIR__ . "/$name.json"), true));
}

function drRequireUnit()
{
    foreach (['Smart', 'Selftest', 'Rules', 'Store', 'Host', 'Pass', 'Next', 'Lesson', 'Run', 'Verify', 'Actions'] as $c) {
        require_once dirname(__DIR__, 3) . "/pikkumies/tools/hardware/drives/Drives$c.php";
    }
}
