<?php
/**
 * PlexServer: the shared verbs for Plex over PlexClient.php. $found: how (docker, url), url, port,
 * listen, data_dir (the Plex data directory on disk, '' when unknown), login_from, restart (plan).
 * Records come from PlexRecords.php (README.md "Plex calls", [UNVERIFIED-spec]).
 */
class PlexServer implements MediaServer
{
    private $plex;
    private $found;
    private $secrets;
    private $wait;

    public function __construct(PlexClient $plex, array $found, array $secrets, $wait = 90)
    {
        $this->plex = $plex;
        $this->found = $found;
        $this->secrets = $secrets;
        $this->wait = $wait;
    }

    public function secrets()
    {
        return $this->secrets;
    }

    public function stats()
    {
        $id = $this->plex->get('/identity');
        $root = $this->plex->get('/');
        $streams = $this->sessions();
        $hw = [];
        try {
            foreach ($this->rows('/:/prefs', 'Setting', true) as $s) { // optional: a refusal here marks nothing
                if (isset($s['id'], $s['value']) && stripos((string) $s['id'], 'hardware') !== false) {
                    $hw[(string) $s['id']] = $s['value']; // pref ids are read from the server, not assumed
                }
            }
        } catch (CliError $e) {
            $hw = null;
        }
        $f = $this->found;
        $version = isset($id['version']) ? (string) $id['version'] : (isset($root['version']) ? (string) $root['version'] : '');
        $name = isset($root['friendlyName']) ? (string) $root['friendlyName'] : '';
        return ['server' => 'Plex', 'version' => $version, 'server_name' => $name,
            'found' => $f['how'], 'url' => $f['url'], 'listen' => $f['listen'], 'libraries' => count($this->libraries(false)),
            'streams' => count($streams), 'transcodes' => count(array_filter($streams, function ($s) {
                return $s['play'] === 'transcode';
            })),
            'hardware_acceleration' => $hw ?: null, 'update_available' => null, 'login_from' => $f['login_from']];
    }

    public function libraries($counts = true)
    {
        $out = [];
        foreach ($this->rows('/library/sections/all', 'Directory') as $d) {
            $id = (string) (isset($d['key']) ? $d['key'] : '');
            $items = null;
            if ($counts && ctype_digit($id)) {
                $c = $this->plex->get("/library/sections/$id/all", ['X-Plex-Container-Start' => '0', 'X-Plex-Container-Size' => '0']);
                $items = isset($c['totalSize']) ? (int) $c['totalSize'] : null;
            }
            $out[] = PlexRecords::library($d, $items);
        }
        return $out;
    }

    public function scan(array $libraries)
    {
        $out = [];
        foreach ($libraries as $l) {
            if ($l['scanning'] || !ctype_digit($l['id'])) {
                $out[] = ['id' => $l['id'], 'name' => $l['name'], 'result' => $l['scanning'] ? 'already scanning' : 'not a section id'];
                continue;
            }
            // refreshSectionPost (community spec 1.1.1); never `force`: it re-reads everything (rule 6), never `path`.
            $this->plex->post("/library/sections/{$l['id']}/refresh");
            $out[] = ['id' => $l['id'], 'name' => $l['name'], 'result' => 'started'];
        }
        return $out;
    }

    public function sessions()
    {
        return array_map(['PlexRecords', 'session'], $this->rows('/status/sessions', 'Metadata'));
    }

    public function endStream(array $sessions, $reason)
    {
        $plex = $this->plex;
        return MediaEndStream::each($sessions, function ($s) use ($plex, $reason) {
            $plex->post('/status/sessions/terminate', ['sessionId' => $s['id']] + ($reason !== '' ? ['reason' => $reason] : []), true);
        });
    }

    public function recent($limit)
    {
        $c = $this->plex->get('/library/recentlyAdded', ['X-Plex-Container-Start' => '0', 'X-Plex-Container-Size' => (string) $limit]);
        return array_slice(array_map(['PlexRecords', 'item'], isset($c['Metadata']) ? (array) $c['Metadata'] : []), 0, $limit);
    }

    /** Plex has no log-reading API; its logs are files in Logs/ inside its data directory (README.md). */
    public function logs($lines)
    {
        $dir = $this->found['data_dir'];
        $files = $dir !== '' ? (array) glob("$dir/Logs/*.log") : [];
        if (!$files) {
            throw new CliError('no Plex log files found' . ($dir !== '' ? " in $dir/Logs" : ''),
                'the logs are in the Logs folder of the Plex data directory; the Plex dashboard can download them too');
        }
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        return ['file' => $files[0], 'lines' => MediaLocal::tail($files[0], $lines)];
    }

    public function restart()
    {
        $plex = $this->plex;
        return MediaRestart::run($this->found['restart'], function () use ($plex) {
            return $plex->answers();
        }, $this->wait);
    }

    /** The list under $key in a MediaContainer ([] when absent). */
    private function rows($path, $key, $optional = false)
    {
        $c = $this->plex->get($path, [], $optional);
        return isset($c[$key]) && is_array($c[$key]) ? $c[$key] : [];
    }
}
