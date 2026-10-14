<?php
/**
 * MediabrowserServer: the shared verbs for Jellyfin and Emby over Mediabrowser.php. $found says where
 * and how the server runs (each unit's Locate): how (native, docker, url), url, port, listen,
 * log_dir (native installs: the log directory on disk), login_from, pmss_status, restart (plan).
 */
class MediabrowserServer implements MediaServer
{
    private $mb;
    private $found;
    private $secrets;
    private $wait;
    private $admin = null;

    public function __construct(Mediabrowser $mb, array $found, array $secrets, $wait = 90)
    {
        $this->mb = $mb;
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
        return MediabrowserInfo::stats($this->mb, $this, $this->found);
    }

    public function libraries($counts = true)
    {
        $out = [];
        foreach ($this->mb->get('/Library/VirtualFolders') as $v) {
            $r = MediabrowserRecords::library((array) $v);
            if ($counts && $r['id'] !== '') {
                $q = ['parentId' => $r['id'], 'recursive' => 'true', 'mediaTypes' => 'Video,Audio,Photo,Book', 'limit' => '0',
                    'userId' => $this->admin()];
                $c = $this->maybe('/Items', $q);
                $r['items'] = isset($c['TotalRecordCount']) ? (int) $c['TotalRecordCount'] : null;
            }
            $out[] = $r;
        }
        return $out;
    }

    public function scan(array $libraries)
    {
        $out = [];
        foreach ($libraries as $l) {
            if ($l['scanning']) {
                $out[] = ['id' => $l['id'], 'name' => $l['name'], 'result' => 'already scanning'];
                continue;
            }
            // Explicit modes (the spec defaults them to None); Default found new files on a live 12.2.0.
            // No replaceAll*: never overwrite the user's edits.
            $q = ['metadataRefreshMode' => 'Default', 'imageRefreshMode' => 'Default']
                + ($this->mb->set['recursive'] ? ['recursive' => 'true'] : []);
            $this->mb->post('/Items/' . rawurlencode($l['id']) . '/Refresh', $q);
            $out[] = ['id' => $l['id'], 'name' => $l['name'], 'result' => 'started'];
        }
        return $out;
    }

    public function sessions()
    {
        return array_values(array_filter(array_map(function ($s) {
            return MediabrowserRecords::session((array) $s);
        }, $this->mb->get('/Sessions'))));
    }

    public function endStream(array $sessions, $reason)
    {
        $mb = $this->mb;
        return MediaEndStream::each($sessions, function ($s) use ($mb, $reason) {
            $path = '/Sessions/' . rawurlencode($s['id']);
            if ($reason !== '') {
                $msg = ['Header' => 'Playback stopped', 'Text' => $reason, 'TimeoutMs' => 10000];
                // Jellyfin takes a MessageCommand body, Emby the same fields as query parameters (both specs).
                $mb->set['pascal'] ? $mb->post("$path/Message", array_map('strval', $msg))
                    : $mb->post("$path/Message", [], json_encode($msg));
            }
            $mb->post("$path/Playing/Stop");
        });
    }

    public function recent($limit)
    {
        $items = [];
        $user = $this->admin();
        foreach ($this->libraries(false) as $l) {
            $q = ['parentId' => $l['id'], 'limit' => (string) $limit, 'fields' => 'DateCreated'];
            $path = str_replace('{user}', rawurlencode($user), $this->mb->set['latest']);
            foreach ($this->mb->get($path, $path === '/Items/Latest' ? $q + ['userId' => $user] : $q) as $i) {
                $items[] = MediabrowserRecords::item((array) $i, $l['name']);
            }
        }
        usort($items, function ($a, $b) {
            return (int) $b['added_at'] - (int) $a['added_at'];
        });
        return array_slice($items, 0, $limit);
    }

    public function logs($lines)
    {
        return MediabrowserInfo::logs($this->mb, $this->found['log_dir'], $lines);
    }

    /** $found['answers'] (fn() => bool, every base path the unit knows) when given, else this base's public info. */
    public function restart()
    {
        $mb = $this->mb;
        $answers = isset($this->found['answers']) ? $this->found['answers'] : function () use ($mb) {
            return $mb->publicInfo(3) !== null;
        };
        return MediaRestart::run($this->found['restart'], $answers, $this->wait);
    }

    /** The first administrator's id, for the per-user paths (recently added). */
    private function admin()
    {
        if ($this->admin === null) {
            foreach ($this->mb->get('/Users') as $u) {
                if (!empty($u['Policy']['IsAdministrator']) && empty($u['Policy']['IsDisabled']) && !empty($u['Id'])) {
                    return $this->admin = (string) $u['Id'];
                }
            }
            throw new CliError("{$this->mb->set['name']} lists no administrator", 'the key must belong to an administrator');
        }
        return $this->admin;
    }

    /** A read that may fail without failing the verb (null); a 401 or 403 here marks nothing. */
    public function maybe($path, array $q = [])
    {
        try {
            return $this->mb->get($path, $q, true);
        } catch (CliError $e) {
            return null;
        }
    }
}
