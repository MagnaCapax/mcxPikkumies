<?php
/**
 * Report: what a run leaves behind (ADR-0039), in a private directory outside the repository: results.jsonl (one
 * line per question and target), transcripts/<target>/<id>.jsonl (the agent's stream) and the scorecard printed
 * at the end. Every key of every target and of the judge is masked in everything written.
 */
class Report
{
    public $dir;
    private $keys;
    private $file;
    public $rows = [];

    public function __construct($dir, array $keys, $file = 'results.jsonl')
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new Exception("could not create $dir");
        }
        chmod($dir, 0700);
        $this->dir = $dir;
        $this->file = $file;
        $this->keys = array_values(array_filter($keys, 'strlen'));
    }

    /** $text with every key replaced by its masked form (Provider::mask). */
    public function mask($text)
    {
        foreach ($this->keys as $k) {
            $text = str_replace($k, Provider::mask($k), (string) $text);
        }
        return $text;
    }

    /** Copy the agent's raw stream into transcripts/<target>/<id>.jsonl, masked. */
    public function transcript($target, $id, $rawFile)
    {
        $dir = $this->dir . '/transcripts/' . $target;
        @mkdir($dir, 0700, true);
        $this->write("$dir/$id.jsonl", $this->mask(is_file($rawFile) ? (string) file_get_contents($rawFile) : ''), false);
    }

    /** One result line. */
    public function add(array $row)
    {
        $this->rows[] = $row;
        $this->write($this->dir . '/' . $this->file, $this->mask(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n", true);
    }

    private function write($path, $text, $append)
    {
        file_put_contents($path, $text, $append ? FILE_APPEND : 0);
        chmod($path, 0600);
    }

    /** The rows of an earlier run's results.jsonl. */
    public static function read($dir)
    {
        $rows = [];
        foreach (is_file("$dir/results.jsonl") ? file("$dir/results.jsonl") : [] as $line) {
            $j = json_decode($line, true);
            if (is_array($j) && isset($j['question'], $j['target'])) {
                $rows[] = $j;
            }
        }
        return $rows;
    }

    /** One result row from Asker::ask()'s record, Judge::ask()'s result and Questions::stale()'s missing facts (any = status stale). */
    public static function row(array $q, $target, array $meta, array $checks, array $rec, array $judge, array $missing = [])
    {
        $row = ['question' => $q['id'], 'target' => $target, 'tags' => $q['tags'], 'status' => '',
            'hermes_version' => isset($meta['hermes_version']) ? $meta['hermes_version'] : '', 'pikkumies_commit' => isset($meta['pikkumies_commit']) ? $meta['pikkumies_commit'] : '',
            'checks' => $missing ? [['name' => 'stale', 'pass' => false, 'detail' => implode('; ', $missing)]] : $checks,
            'code_pass' => $missing ? 0 : count(array_filter(array_column($checks, 'pass'))), 'code_total' => $missing ? 0 : count($checks),
            'judge_yes' => count(array_filter(array_column($judge['verdicts'], 'yes'))), 'judge_total' => count($judge['verdicts']),
            'judge' => $judge['verdicts'], 'judge_error' => $judge['error'], 'tokens' => (int) $rec['tokens'] + (int) $judge['tokens'],
            'seconds' => isset($rec['seconds']) ? (int) $rec['seconds'] : 0, 'error' => (string) $rec['error']];
        $row['status'] = $missing ? 'stale' : ($row['error'] === '' && $row['code_pass'] === $row['code_total'] ? 'pass' : 'fail');
        return $row;
    }

    /** The progress line printed for a row (masked). */
    public function line(array $r)
    {
        $judge = $r['judge_total'] ? "{$r['judge_yes']}/{$r['judge_total']}" : '-';
        $note = $r['status'] === 'stale' ? $r['checks'][0]['detail'] : $r['error'] . ($r['judge_error'] !== '' ? " ({$r['judge_error']})" : '');
        return $this->mask(sprintf("%-5s %-16s %-44s code %d/%d judge %s tokens %d %ds %s\n", strtoupper($r['status']), $r['target'], $r['question'],
            $r['code_pass'], $r['code_total'], $judge, $r['tokens'], $r['seconds'], $note));
    }

    /**
     * --regrade: the code checks again from an earlier run's transcripts and today's question files, plus the stale guard when
     * $docsCache (a kept install's) is given. Writes DIR/regrade.jsonl; the judge columns are the earlier run's (no tokens).
     */
    public static function regrade($dir, array $questions, $docsCache = '')
    {
        $byId = array_column($questions, null, 'id');
        $report = new self($dir, [], 'regrade.jsonl');
        @unlink("$dir/regrade.jsonl");
        foreach (self::read($dir) as $old) {
            if (!isset($byId[$old['question']])) {
                continue;
            }
            $q = $byId[$old['question']];
            $missing = $docsCache !== '' ? Questions::stale($q, $docsCache) : [];
            $wasStale = isset($old['status']) && $old['status'] === 'stale';
            $rec = Asker::parseStream("$dir/transcripts/" . basename($old['target']) . '/' . basename($old['question']) . '.jsonl');
            $judge = ['verdicts' => isset($old['judge']) ? $old['judge'] : [], 'tokens' => 0, 'error' => isset($old['judge_error']) ? $old['judge_error'] : ''];
            $row = $wasStale && !$missing ? $old : self::row($q, $old['target'], $old, Checks::check($q, $rec), ['tokens' => $old['tokens'],
                'seconds' => $old['seconds'], 'error' => $old['error']], $judge, $missing);
            $report->add($row);
        }
        return $report->rows;
    }

    /** The scorecard per target and per target and tag; stale questions are counted apart, code and judge are separate columns. */
    public static function scorecard(array $rows)
    {
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['target'] . "\0(all)"][] = $r;
            foreach (isset($r['tags']) ? $r['tags'] : [] as $tag) {
                $groups[$r['target'] . "\0" . $tag][] = $r;
            }
        }
        ksort($groups);
        $out = sprintf("%-16s %-20s %6s %8s %9s %9s %9s %8s %6s %5s\n", 'target', 'tag', 'asked', 'all-pass', 'checks', 'judge', 'tokens', 'seconds', 'errors', 'stale');
        foreach ($groups as $key => $all) {
            list($target, $tag) = explode("\0", $key);
            $rs = array_filter($all, function ($r) {
                return $r['status'] !== 'stale';
            });
            $sum = function ($field) use ($rs) {
                return array_sum(array_map(function ($r) use ($field) {
                    return (int) $r[$field];
                }, $rs));
            };
            $count = function ($field, $value) use ($all) {
                return count(array_filter($all, function ($r) use ($field, $value) {
                    return $value === '' ? $r[$field] !== '' : $r[$field] === $value;
                }));
            };
            $judge = $sum('judge_total') ? $sum('judge_yes') . '/' . $sum('judge_total') : '-';
            $out .= sprintf("%-16s %-20s %6d %8d %9s %9s %9d %8d %6d %5d\n", $target, $tag, count($rs), $count('status', 'pass'),
                $sum('code_pass') . '/' . $sum('code_total'), $judge, $sum('tokens'), $sum('seconds'), $count('error', ''), $count('status', 'stale'));
        }
        return $out;
    }
}
