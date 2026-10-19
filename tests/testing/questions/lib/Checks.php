<?php
/**
 * Checks: grades what the agent did (its tool calls) and said (its final text) against a question (ADR-0039), and the
 * offline self-test that proves every check type on the records in samples/.
 */
require_once __DIR__ . '/Questions.php';
require_once __DIR__ . '/Judge.php';

class Checks
{
    /** What a calls/first_call/not_calls pattern is matched against: the tool name, a space, the input as JSON. */
    public static function callText(array $call)
    {
        $input = isset($call['input']) ? $call['input'] : new stdClass();
        return $call['name'] . ' ' . json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The code checks (judge lines excluded) of $q against a record -> list of ['name', 'pass', 'detail']. */
    public static function check(array $q, array $record)
    {
        $calls = array_map([__CLASS__, 'callText'], isset($record['tools']) ? $record['tools'] : []);
        $text = isset($record['text']) ? (string) $record['text'] : '';
        $any = function ($pattern) use ($calls) {
            foreach ($calls as $c) {
                if (preg_match(Questions::regex($pattern), $c)) {
                    return $c;
                }
            }
            return null;
        };
        $out = [];
        $add = function ($name, $pass, $detail) use (&$out) {
            $out[] = ['name' => $name, 'pass' => (bool) $pass, 'detail' => $pass ? '' : substr($detail, 0, 300)];
        };
        foreach (Questions::values($q, 'calls') as $p) {
            $add("calls: $p", $any($p) !== null, count($calls) . ' tool calls, none matched');
        }
        foreach (Questions::values($q, 'first_call') as $p) {
            $first = null;
            foreach (isset($record['tools']) ? $record['tools'] : [] as $t) {
                if (!in_array($t['name'], Questions::readOnly(), true)) {
                    $first = self::callText($t);
                    break;
                }
            }
            $add("first_call: $p", $first !== null && preg_match(Questions::regex($p), $first), $first === null ? 'no acting tool call' : "first acting call: $first");
        }
        foreach (Questions::values($q, 'not_calls') as $p) {
            $hit = $any($p);
            $add("not_calls: $p", $hit === null, "matched: $hit");
        }
        // Every tool call counts, read-only ones too: each is a model round, which is what the budget is about.
        foreach (Questions::values($q, 'max_calls') as $n) {
            $add("max_calls: $n", count($calls) <= (int) $n, count($calls) . ' tool calls: ' . implode(' | ', $calls));
        }
        foreach (Questions::values($q, 'contains') as $s) {
            $add("contains: $s", Questions::has($text, $s), 'not in the final text');
        }
        foreach (Questions::values($q, 'contains_any') as $s) {
            $found = array_filter(array_map('trim', explode(' | ', $s)), function ($alt) use ($text) {
                return $alt !== '' && Questions::has($text, $alt);
            });
            $add("contains_any: $s", (bool) $found, 'none of the alternatives is in the final text');
        }
        foreach (Questions::values($q, 'not_contains') as $s) {
            $add("not_contains: $s", !Questions::has($text, $s), 'found in the final text');
        }
        return $out;
    }

    /**
     * Lint every question file and grade every sample in $samplesDir: each sample names the check its bad record
     * must fail; its good record must pass every check. Offline. Returns a list of problems ([] = all good).
     */
    public static function selfTest($questionsDir, $samplesDir, $fixturesDir)
    {
        $problems = in_array('read_file', Questions::readOnly(), true) ? [] : ['could not read SEARCH_FIRST_READ_ONLY from ' . Questions::GATE];
        foreach (Questions::load($questionsDir) as $q) {
            foreach (Questions::lint($q, $fixturesDir) as $p) {
                $problems[] = 'questions/' . $q['id'] . '.md: ' . $p;
            }
        }
        $samples = glob(rtrim($samplesDir, '/') . '/*.json') ?: [];
        if (!$samples) {
            $problems[] = 'no samples in ' . $samplesDir;
        }
        foreach ($samples as $file) {
            $s = json_decode((string) file_get_contents($file), true);
            $name = 'samples/' . basename($file) . ': ';
            if (!is_array($s) || !isset($s['fails'], $s['bad'], $s['good']) || (!isset($s['question']) && $s['fails'] !== 'stale')) {
                $problems[] = $name . 'needs fails, question, bad and good';
                continue;
            }
            if ($s['fails'] === 'stale') { // bad and good are questions; docs names a fake docs-cache under samples/
                $meta = function ($m) {
                    $q = ['meta' => array_map(function ($v) {
                        return (array) $v;
                    }, $m)];
                    return $q + ['tags' => array_map('trim', explode(',', implode(',', isset($q['meta']['tags']) ? $q['meta']['tags'] : [])))];
                };
                $cache = rtrim($samplesDir, '/') . '/' . (isset($s['docs']) ? basename($s['docs']) : 'docs-cache');
                $problems = array_merge($problems, Questions::stale($meta($s['bad']), $cache) ? [] : [$name . 'the bad question was not stale'],
                    Questions::stale($meta($s['good']), $cache) ? [$name . 'the good question was stale'] : []);
                continue;
            }
            $q = ['meta' => array_map(function ($v) {
                return (array) $v;
            }, $s['question'])];
            if (isset($q['meta']['judge'])) {
                $grade = function ($reply) use ($q, $s) {
                    return Judge::grade($q['meta']['judge'], (string) $s['answer'], (string) $reply);
                };
                $bad = $grade($s['bad']);
                $good = $grade($s['good']);
                $badFails = (bool) array_filter($bad, function ($v) {
                    return !$v['yes'];
                });
                $goodPasses = !array_filter($good, function ($v) {
                    return !$v['yes'];
                });
            } else {
                $badFails = (bool) array_filter(self::check($q, $s['bad']), function ($c) use ($s) {
                    return !$c['pass'] && strpos($c['name'], $s['fails'] . ': ') === 0;
                });
                $goodPasses = !array_filter(self::check($q, $s['good']), function ($c) {
                    return !$c['pass'];
                });
            }
            if (!$badFails) {
                $problems[] = $name . 'the bad record did not fail ' . $s['fails'];
            }
            if (!$goodPasses) {
                $problems[] = $name . 'the good record did not pass';
            }
        }
        return $problems;
    }
}
