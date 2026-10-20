<?php
/**
 * MoveState: one move's files under local/state/moves/ (directory 0700): <id>.json (the plan and each torrent's
 * finished steps, rewritten through a temp file and rename), <id>.jsonl (one line per step result, appended),
 * <id>/<n>.torrent (the exported .torrent, 0600; never printed or logged). intents.jsonl one level up holds the
 * user's own words for each source removal. These files are written by this tool and by the agent's account alike:
 * they say what was planned and done, they never authorize a delete (MoveFree reads the live clients instead).
 */
class MoveState
{
    public $dir;

    /** $dir: the moves directory (local/state/moves; a test passes a temp one). */
    public function __construct($dir)
    {
        $this->dir = rtrim($dir, '/');
    }

    public static function newId()
    {
        return gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    public static function validId($id)
    {
        return is_string($id) && preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $id) === 1;
    }

    public function load($id)
    {
        if (!self::validId($id)) {
            throw new CliError("not a move id: $id", 'move ids look like 20261020-101500-a1b2c3; `list` shows them');
        }
        $d = json_decode((string) @file_get_contents("{$this->dir}/$id.json"), true);
        if (!is_array($d) || !isset($d['items'])) {
            throw new CliError("no move $id", '`list` shows the moves on this box');
        }
        return $d;
    }

    public function save(array $move)
    {
        $this->mkdir($this->dir);
        $file = "{$this->dir}/{$move['id']}.json";
        $tmp = "$file.tmp" . getmypid();
        $json = json_encode($move, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); // names that are not UTF-8 fail here, never saved altered
        if ($json === false || @file_put_contents($tmp, "$json\n") === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new CliError("cannot write $file", 'check the free space and the permissions of ~/pikkumies/local/state');
        }
        @chmod($file, 0600);
    }

    /** One line in <id>.jsonl: ts, step, torrent (id), result and any detail. */
    public function log($id, $step, $torrent, $result, array $detail = [])
    {
        $this->append("{$this->dir}/$id.jsonl", ['ts' => gmdate('c'), 'step' => $step, 'torrent' => $torrent, 'result' => $result] + $detail);
    }

    public function logLines($id)
    {
        $out = [];
        foreach (explode("\n", (string) @file_get_contents("{$this->dir}/$id.jsonl")) as $l) {
            $d = json_decode($l, true);
            if (is_array($d)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** An exclusive lock on the move, held while the returned handle lives; refused when another run holds it. */
    public function lock($id)
    {
        if (!self::validId($id)) {
            $this->load($id); // throws: not a move id
        }
        $this->mkdir($this->dir);
        $h = @fopen("{$this->dir}/$id.lock", 'c');
        if ($h === false || !flock($h, LOCK_EX | LOCK_NB)) {
            throw new CliError("move $id is running already", 'wait for it to finish; `status ' . $id . '` shows its last step');
        }
        return $h;
    }

    /** The user's words for a source removal, one line in local/state/intents.jsonl. */
    public function intent(array $rec)
    {
        $this->append(dirname($this->dir) . '/intents.jsonl', ['ts' => gmdate('c')] + $rec);
    }

    public function torrentPath($id, $n)
    {
        return "{$this->dir}/$id/$n.torrent";
    }

    public function saveTorrent($id, $n, $raw)
    {
        $this->mkdir("{$this->dir}/$id");
        $f = $this->torrentPath($id, $n);
        if (@file_put_contents($f, $raw) !== strlen($raw)) {
            throw new CliError("cannot write $f", 'check the free space of ~/pikkumies/local/state');
        }
        @chmod($f, 0600);
        return $f;
    }

    /** Every move, newest first. */
    public function all()
    {
        $out = [];
        foreach (glob("{$this->dir}/*.json") ?: [] as $f) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d) && isset($d['id'], $d['items'])) {
                $out[] = $d;
            }
        }
        usort($out, function ($a, $b) {
            return strcmp($b['id'], $a['id']);
        });
        return $out;
    }

    private function append($file, array $rec)
    {
        $this->mkdir(dirname($file));
        $line = json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new CliError("cannot append to $file", 'check the free space and the permissions of ~/pikkumies/local/state');
        }
        @chmod($file, 0600);
    }

    private function mkdir($d)
    {
        if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
            throw new CliError("cannot create $d", 'check the permissions of ~/pikkumies/local');
        }
        @chmod($d, 0700);
    }
}
