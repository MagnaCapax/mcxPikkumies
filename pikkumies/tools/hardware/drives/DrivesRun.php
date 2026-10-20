<?php
/**
 * DrivesRun: the `smart` verb. One record per disk, worst screen first: what the drive reports (DrivesSmart), its
 * array role (DrivesHost), its screen, the state of a write pass, the verdict, the grade and the next step. When a
 * pass has ended since the last call, its verdict is decided here, from the pass files and a SMART sample taken now,
 * and a `lesson` record follows for the agent to save; the tool itself never writes memory (ADR-0018).
 */
class DrivesRun
{
    const SHOWN = ['screen', 'device', 'serial', 'bytes', 'model', 'health', 'realloc', 'pending', 'uncorrectable', 'crc', 'selftest_last', 'verify', 'verdict', 'grade'];

    private $host;
    private $store;
    private $seen = [];

    public function __construct(DrivesHost $host, DrivesStore $store)
    {
        $this->host = $host;
        $this->store = $store;
    }

    public static function needRoot(DrivesHost $host)
    {
        if (empty($host->root()['available'])) {
            throw new CliError('reading drive health needs root, and root is not available to this agent',
                'check root.available with system-status; on a shared or managed server the provider watches the disks');
        }
    }

    public function smart(array $opt)
    {
        self::needRoot($this->host);
        $records = [];
        $lessons = [];
        $disks = $this->host->disks();
        foreach ($disks as $disk) {
            if (isset($opt['serial']) && strcasecmp((string) $disk['serial'], (string) $opt['serial']) !== 0) {
                continue;
            }
            list($rec, $lesson) = $this->one($disk);
            $records[] = $rec;
            if ($lesson) {
                $lessons[] = $lesson;
            }
        }
        $lessons = array_merge($lessons, $this->gone($disks, $records));
        if (isset($opt['serial']) && !$records) {
            throw new CliError('no disk with serial ' . $opt['serial'], 'run smart without --serial for the serials this machine has');
        }
        usort($records, function ($a, $b) {
            return [DrivesRules::rank($a['screen']), -max((int) $a['realloc'], (int) $a['pending'], (int) $a['uncorrectable']), (string) $a['serial']]
                <=> [DrivesRules::rank($b['screen']), -max((int) $b['realloc'], (int) $b['pending'], (int) $b['uncorrectable']), (string) $b['serial']];
        });
        if (!empty($opt['human']) && !isset($opt['fields'])) {
            $records = array_map(function ($r) {
                return array_intersect_key($r, array_flip(self::SHOWN));
            }, $records);
        }
        return ['records' => array_merge($records, $lessons), 'status' => ['note' => 'screen glyph = a screen, not a verdict; verdicts come only from a completed write pass']];
    }

    private function one(array $disk)
    {
        $j = $this->host->smart($disk['device']);
        $d = isset($j['error']) ? DrivesSmart::parse([]) : DrivesSmart::parse($j);
        foreach (['serial', 'wwn', 'model', 'bytes', 'rotational'] as $k) {
            $d[$k] = $d[$k] !== null ? $d[$k] : $disk[$k];
        }
        list($role, $roleDetail) = $this->host->role($disk);
        list($glyph, $why) = DrivesRules::screen($d);
        $rec = ['screen' => $glyph, 'device' => $disk['device'], 'serial' => $d['serial'], 'wwn' => $d['wwn'], 'bytes' => $d['bytes'],
            'model' => $d['model'], 'protocol' => $d['protocol'], 'rotational' => $d['rotational'], 'role' => $role, 'role_detail' => $roleDetail,
            'health' => $d['health'], 'poh' => $d['poh'], 'realloc' => $d['realloc'], 'pending' => $d['pending'], 'uncorrectable' => $d['uncorrectable'],
            'reported_unc' => $d['reported_unc'], 'crc' => $d['crc'], 'error_log' => $d['error_log'], 'failing_now' => $d['failing_now'],
            'selftest_last' => $d['selftest_running'] ? 'running, ' . (int) $d['selftest_remaining'] . '% left' : $d['selftest_last'],
            'screen_reason' => $why, 'verify' => null, 'verdict' => null, 'verdict_reason' => null, 'grade' => null, 'grade_reason' => null];
        if (isset($j['error'])) {
            $rec['screen_reason'] = 'no SMART data: ' . $j['error'];
        }
        if ($d['serial'] === null) {
            return [$rec + ['next' => 'this drive reports no serial: nothing is kept for it'], null];
        }
        $key = DrivesStore::key($d['serial']);
        $this->seen[$key] = true;
        if (!isset($j['error']) && !$d['standby']) {
            $this->store->saveSmart($key, $j);
            $this->store->sample($key, $d);
        }
        $pass = new DrivesPass($this->store);
        $landed = $pass->settle($key, $d);
        $state = $pass->state($key);
        $rec['verify'] = $state['verify'];
        $end = $this->store->last($key, ['verify-end']);
        $verdict = $state['running'] ? ['HOLD', 'write pass running, recoverability undetermined']
            : ($pass->open($key) ? ['HOLD', $state['verify']] : ($end ? [$end['verdict'], $end['reason']] : [null, null]));
        $standing = DrivesRules::standingFailure($d, $end && $end['verdict'] === 'RECOVERED' ? $this->store->uncPerHour($key, (int) $end['ts']) : null);
        if ($standing !== null && $verdict[0] !== 'HOLD') {
            $verdict = ['FAILED', $standing];
        }
        list($rec['verdict'], $rec['verdict_reason']) = $verdict;
        $gradeEvent = $this->store->last($key, ['grade']);
        $measured = !isset($j['error']) && !$d['standby'] && array_filter(DrivesPass::sample($d), 'is_int');
        list($rec['grade'], $rec['grade_reason']) = DrivesRules::grade($d, $rec['verdict']);
        if (!$measured && $gradeEvent && DrivesRules::letter($rec['grade'])) {
            // Nothing read now (asleep, no SMART): the counters are unknown, not zero, so the last grade stands.
            list($rec['grade'], $rec['grade_reason']) = [$gradeEvent['grade'], 'the last grade; nothing was read now (asleep or no SMART data)'];
        }
        $rec['next'] = DrivesNext::of($rec, $d, $state);
        $graded = $measured && DrivesRules::letter($rec['grade']) && (!$gradeEvent || $gradeEvent['grade'] !== $rec['grade']);
        if ($graded) {
            $this->store->event($key, ['event' => 'grade', 'grade' => $rec['grade'], 'reason' => $rec['grade_reason'], 'verdict' => $rec['verdict']]);
        }
        $lesson = $landed || $graded ? DrivesLesson::record($this->store, $key, $rec) : null;
        return [$rec, $lesson];
    }

    /** Settles a pass whose drive is no longer listed (it dropped mid-pass): INCOMPLETE, a link or cable fault. */
    private function gone(array $disks, array &$records)
    {
        $listed = array_map(function ($s) {
            return preg_replace('/[^A-Z0-9._-]/', '', strtoupper((string) $s));
        }, array_column($disks, 'serial'));
        $lessons = [];
        $pass = new DrivesPass($this->store);
        foreach ($this->store->keys() as $key) {
            if (!in_array($key, $listed, true) && !isset($this->seen[$key]) && $pass->open($key) && $pass->settle($key, null)) {
                $end = $this->store->last($key, ['verify-end']);
                $rec = ['screen' => '⚪', 'device' => null, 'serial' => $key, 'realloc' => null, 'pending' => null, 'uncorrectable' => null, 'verify' => 'the drive is not listed now', 'verdict' => $end['verdict'],
                    'verdict_reason' => $end['reason'], 'grade' => null, 'next' => 'check the cable and port, bring the drive back, then run smart and verify again'];
                $records[] = $rec;
                $lessons[] = DrivesLesson::record($this->store, $key, $rec);
            }
        }
        return $lessons;
    }
}
