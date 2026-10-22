<?php
class EternalCli
{
    public static function run(array $args)
    {
        $verb = isset($args[0]) ? $args[0] : 'list';
        if (!is_file(Eternal::$hostMarker !== null ? Eternal::$hostMarker : Eternal::HOST_MARKER)) {
            if ($verb === 'list' && (!isset($args[1]) || $args[1] === '--json')) {
                echo "[]\n";
                return 0;
            }
            if ($verb === 'ensure' && count($args) === 1) {
                return 0;
            }
            echo "Pikkumies windows are not enabled on this host.\n";
            return 1;
        }
        if ($verb === 'list' && (!isset($args[1]) || $args[1] === '--json')) {
            echo json_encode(self::listing(), JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        }
        if ($verb === 'ensure' && count($args) === 1) {
            Eternal::ensure();
            return 0;
        }
        if ($verb === 'hold' && count($args) === 3 && $args[1] === '--until' && ctype_digit($args[2]) && (int) $args[2] > time()) {
            $lock = Eternal::lock();
            if (!$lock) {
                return 1;
            }
            $path = Config::localDir() . '/eternals.hold';
            $tmp = $path . '.tmp-' . getmypid();
            if (@file_put_contents($tmp, json_encode(['until' => (int) $args[2]]) . "\n") === false || !@rename($tmp, $path)) {
                @unlink($tmp);
                return 1;
            }
            $stopped = Eternal::stopManaged();
            fclose($lock);
            return $stopped && Eternal::waitStopped() ? 0 : 1;
        }
        if (($verb === 'on' || $verb === 'off') && count($args) === 1) {
            $lock = Eternal::lock();
            if (!$lock) {
                return 1;
            }
            $path = Config::localDir() . '/eternals.off';
            $stopped = true;
            if ($verb === 'off') {
                if (@file_put_contents($path, "off\n") === false) {
                    return 1;
                }
                $stopped = Eternal::stopManaged();
            } else {
                @unlink($path);
            }
            fclose($lock);
            if ($verb === 'on') {
                Eternal::ensure();
            }
            return $stopped && ($verb !== 'off' || Eternal::waitStopped()) ? 0 : 1;
        }
        if ($verb === 'new' && count($args) === 1) {
            if (!Eternal::enabled()) {
                echo "Pikkumies windows are not ready on this host.\n";
                return 1;
            }
            $lock = Eternal::lock();
            if (!$lock) {
                return 1;
            }
            if (Eternal::held()) {
                echo "Pikkumies windows are held for transfer. Try again after the hold ends.\n";
                return 1;
            }
            if (Eternal::ready() !== ''
                || !Eternal::cgroupAllows(@file_get_contents('/proc/self/cgroup'), function_exists('posix_geteuid') ? posix_geteuid() : getmyuid())) {
                echo "Pikkumies windows are not ready on this host.\n";
                return 1;
            }
            $usage = Cgroup::usageAtLimit();
            if (($usage['pids_max'] !== null && $usage['pids_current'] !== null && $usage['pids_max'] - $usage['pids_current'] < 1)
                || ($usage['memory_max'] !== null && $usage['memory_current'] !== null && $usage['memory_max'] - $usage['memory_current'] < 134217728)) {
                echo "No room for another Pikkumies window.\n";
                return 1;
            }
            list($tmuxCode, $windowRows) = Eternal::tmux(['list-windows', '-t', '=pikkumies', '-F', '#W']);
            $windows = $tmuxCode === 0 ? explode("\n", trim($windowRows)) : [];
            if (Eternal::primaryMissing($tmuxCode === 0 ? $windows : null)) {
                $primary = EternalRecord::read('pm1');
                if ($primary === null) {
                    $primary = EternalRecord::initial();
                }
                $primary['lastStart'] = time();
                EternalRecord::write('pm1', $primary);
                if (!Eternal::start('pm1', $tmuxCode === 0)) {
                    echo "Could not start Primary.\n";
                    return 1;
                }
                $tmuxCode = 0;
                $windows = Eternal::withPrimary($tmuxCode === 0 ? $windows : null);
            }
            $used = [];
            foreach (range(2, 9) as $n) {
                if (file_exists(EternalRecord::path('pm' . $n))) {
                    $used[] = 'pm' . $n;
                }
            }
            $id = self::nextFreeId($used, $windows);
            if ($id === null) {
                echo "All nine Pikkumies windows are in use.\n";
                return 1;
            }
            $record = EternalRecord::initial();
            $record['lastStart'] = time();
            EternalRecord::write($id, $record);
            $started = Eternal::start($id, $tmuxCode === 0);
            fclose($lock);
            if (!$started) {
                echo "Could not start the Pikkumies window.\n";
                return 1;
            }
            echo $id . "\n";
            return 0;
        }
        if ($verb === 'quit' && count($args) === 2 && EternalRecord::validId($args[1])) {
            $lock = Eternal::lock();
            if (!$lock) {
                return 1;
            }
            $id = $args[1];
            if ($id === 'pm1') {
                $record = EternalRecord::read($id);
                if ($record !== null) {
                    $record['sessionId'] = '';
                    EternalRecord::write($id, $record);
                    @file_put_contents(Eternal::quitPath($id), "quit\n");
                }
            } else {
                @unlink(EternalRecord::path($id));
            }
            Eternal::tmux(['kill-window', '-t', '=pikkumies:' . $id]);
            return 0;
        }
        echo "usage: pikkumies eternal new | quit pmN | on | off | hold --until TIMESTAMP | ensure | list --json\n";
        return 2;
    }

    public static function listing()
    {
        $out = [];
        $ready = null;
        $why = '';
        $paused = is_file(Config::localDir() . '/eternals.off') ? 'off' : (Eternal::held() ? 'hold' : '');
        foreach (range(1, 9) as $n) {
            $id = 'pm' . $n;
            $record = EternalRecord::read($id);
            if ($record === null && $id === 'pm1' && is_file(Eternal::$hostMarker !== null ? Eternal::$hostMarker : Eternal::HOST_MARKER)) {
                $record = EternalRecord::initial();
            }
            if ($record === null) {
                continue;
            }
            $lock = EternalLock::take(EternalRecord::path($id) . '.run');
            if ($lock && $ready === null) {
                $ready = Eternal::ready();
                $why = Eternal::$why;
            }
            $hostWhy = '';
            $state = $paused !== '' || is_file(Eternal::quitPath($id)) ? 'quit' : ($lock ? ($ready ?: 'starting') : 'running');
            if ($state !== 'quit' && $lock && !Eternal::cgroupAllows(@file_get_contents('/proc/self/cgroup'), function_exists('posix_geteuid') ? posix_geteuid() : getmyuid())) {
                $state = 'not-ready';
                $hostWhy = 'host';
            }
            if ($lock) {
                fclose($lock);
            }
            // 'why' tells the browser window what to say instead of waiting forever; 'reason' already means off/hold.
            $out[] = ['id' => $id, 'state' => $state] + ($paused !== '' ? ['reason' => $paused] : [])
                + ($state === 'not-ready' ? ['why' => $hostWhy !== '' ? $hostWhy : ($why !== '' ? $why : 'unknown')] : []);
        }
        return $out;
    }

    public static function nextFreeId(array $records, array $windows)
    {
        for ($n = 2; $n <= 9; $n++) {
            $id = 'pm' . $n;
            if (!in_array($id, $records, true) && !in_array($id, $windows, true)) {
                return $id;
            }
        }
        return null;
    }
}
