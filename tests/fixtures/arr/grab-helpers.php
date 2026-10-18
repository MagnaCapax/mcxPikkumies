<?php
/**
 * Shared helpers for the `grab`/`releases` tests (docs/adr/0046): ArrGrabTest.php,
 * ArrGrabPathBTest.php, ArrLibraryReleasesTest.php. A static test seam ($detect/$build) cannot
 * cross the proc_open() boundary arrRun() uses, so path-B cases build a real ArrClient in-process
 * against the fixture's own fake server and call ArrGrab::grab() directly, with a fake TorrentClient
 * behind the seam: never a real rTorrent/qBittorrent/Deluge, never this install's own
 * local/secrets/qbittorrent.
 */
require_once __DIR__ . '/harness.php';

if (!class_exists('ArrGrabFakeClient')) {
    /** Records every add() call; never answers for real. */
    class ArrGrabFakeClient implements TorrentClient
    {
        public $added = [];
        public $failResult = null;

        public function torrents()
        {
            return [];
        }

        public function details($native)
        {
            return [];
        }

        public function files($native)
        {
            return [];
        }

        public function filesOf(array $records)
        {
            return [];
        }

        public function add(array $items, array $opt)
        {
            $this->added[] = ['items' => $items, 'opt' => $opt];
            return [['id' => 'fake', 'name' => 'fake', 'result' => $this->failResult !== null ? $this->failResult : 'added']];
        }

        public function remove(array $natives, $withData)
        {
            return [];
        }

        public function pause(array $natives)
        {
            return [];
        }

        public function resume(array $natives)
        {
            return [];
        }

        public function stats()
        {
            return [];
        }

        public function traffic()
        {
            return [];
        }

        public function restart()
        {
            return [];
        }

        public function recheck(array $natives)
        {
            return [];
        }

        public function reannounce(array $natives)
        {
            return [];
        }

        public function setLimits($down, $up)
        {
            return [];
        }

        public function trackers($native)
        {
            return [];
        }
    }
}

if (!function_exists('arrGrabClientFor')) {
    /** A real ArrClient against $fx's fake server (in-process; config.xml is the one arrFixture() wrote). */
    function arrGrabClientFor(array $fx)
    {
        $app = ArrApps::get($fx['app']);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $locate = function () use ($app, $fx, $uid) {
            return ArrLocate::find($app, $fx['home'], 'pmss', $uid);
        };
        return new ArrClient($locate(), $locate, $app['api'], 5);
    }

    /** [home, uid] for ArrGrab::grab()'s $account argument. */
    function arrGrabAccount(array $fx)
    {
        return [$fx['home'], function_exists('posix_geteuid') ? posix_geteuid() : getmyuid()];
    }

    /** Resets every in-process test seam this file touches, so one test can never leak into the next. */
    function arrGrabResetSeams()
    {
        ArrGrabClient::$detect = null;
        ArrGrabClient::$build = null;
        ArrReleases::$file = null;
    }
}
