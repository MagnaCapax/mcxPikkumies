<?php
/**
 * TextSearch: the one ranker for lessons and cached documentation (markdown files).
 *
 * Whole words, stopwords dropped, all terms required (a quarter may miss at 5+ terms),
 * query words in the title count double, score divided by sqrt(length), inline-link boost only for
 * files that already matched.
 * Deterministic and local: nothing leaves the machine.
 */
require_once __DIR__ . '/Links.php';

class TextSearch
{
    const STOPWORDS = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'is', 'it', 'how', 'what', 'why', 'do', 'does', 'with', 'my', 'i'];

    public static function terms($text)
    {
        preg_match_all('/[a-z0-9][a-z0-9._-]*/', strtolower((string) $text), $m);
        // "policy." at the end of a sentence is the word "policy"; inner dots stay (rtorrent.rc, 1.2.3).
        $words = array_map(function ($w) {
            return rtrim($w, '._-');
        }, $m[0]);
        return array_values(array_diff(array_unique($words), self::STOPWORDS));
    }

    public static function slug($title, $fallback = 'lesson')
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $title)), '-');
        return $slug !== '' ? $slug : $fallback;
    }

    /**
     * Memory directories (ADR-0018): lessons/<topic>/, reference/ and research/ under
     * pikkumies/memory, plus memory/ itself, where lessons saved before the hierarchy still live.
     */
    public static function lessonDirs($pikkumiesRoot)
    {
        $m = $pikkumiesRoot . '/memory';
        return array_merge([$m, "$m/reference", "$m/research"], (array) glob("$m/lessons/*", GLOB_ONLYDIR));
    }

    /** Markdown files (README.md excluded) in the given directories, path => text. */
    public static function readDirs(array $dirs)
    {
        $files = [];
        foreach ($dirs as $dir) {
            foreach ((array) glob($dir . '/*.md') as $f) {
                if (basename($f) !== 'README.md') {
                    $files[$f] = (string) file_get_contents($f);
                }
            }
        }
        return $files;
    }

    /**
     * Ranked paths, best first. $share null: all terms (a quarter may miss at 5+ terms), for a
     * query someone typed as keywords. A share such as 0.5: that fraction of the terms, for free text.
     * $bases: where a link in a file under one of them may also be relative to (the memory root, for memory files).
     */
    public static function rank(array $files, $query, $limit, $share = null, array $bases = [])
    {
        $want = self::terms($query);
        if (!$want) {
            return [];
        }
        $termSets = [];
        foreach ($files as $f => $text) {
            $termSets[$f] = array_flip(self::terms($text));
        }
        // Weight 1 per term for keyword queries. For free text, rarer words weigh more and words found
        // in over a quarter of the files are dropped, so everyday words do not decide the match.
        $weight = array_fill_keys($want, 1.0);
        if ($share !== null) {
            $n = max(1, count($files));
            foreach ($want as $t) {
                $df = 0;
                foreach ($termSets as $set) {
                    $df += isset($set[$t]) ? 1 : 0;
                }
                if ($df === 0 || ($n >= 20 && $df > $n / 4)) {
                    unset($weight[$t]);
                } else {
                    $weight[$t] = log($n / $df) + 1;
                }
            }
            if (!$weight) {
                return [];
            }
        }
        $need = $share === null ? count($want) - (count($want) >= 5 ? (int) floor(count($want) / 4) : 0) : max(1, (int) ceil(count($weight) * $share));
        $scores = [];
        foreach ($files as $f => $text) {
            $hits = 0;
            $sum = 0.0;
            foreach ($weight as $t => $w) {
                if (isset($termSets[$f][$t])) {
                    $hits++;
                    $sum += $w;
                }
            }
            if ($hits >= $need) {
                // A query word in the page's title (its first "# " line) counts double.
                $title = preg_match('/^# (.+)$/m', $text, $m) ? array_flip(self::terms($m[1])) : [];
                foreach ($weight as $t => $w) {
                    $sum += isset($title[$t]) ? $w : 0;
                }
                $scores[$f] = $sum / sqrt(max(1, str_word_count($text)));
            }
        }
        // Link boost: matched files linked from other matched files rank higher (Links, ADR-0060).
        foreach ($scores as $f => $s) {
            $own = array_filter($bases, function ($b) use ($f) {
                return strpos($f, "$b/") === 0; // a base applies only to the files under it: the memory root to memory
            });
            foreach (Links::targets($files[$f]) as $target) {
                $abs = substr($target, -3) === '.md' ? Links::resolve($target, $f, $own) : false;
                if ($abs !== false && isset($scores[$abs])) {
                    $scores[$abs] += 0.25 * $s;
                }
            }
        }
        arsort($scores);
        return array_slice(array_keys($scores), 0, $limit);
    }

    /**
     * The paragraph of $path with the most distinct search words, at most $max bytes plus an ellipsis; YAML
     * frontmatter and heading-only paragraphs are skipped. Shared by the memory search and the wiki and kb units.
     */
    public static function excerpt($path, $query, $max)
    {
        $text = preg_replace('/\A---\n.*?\n---\n/s', '', (string) @file_get_contents($path));
        $want = array_flip(self::terms($query));
        $best = '';
        $bestScore = -1;
        foreach (preg_split('/\n\s*\n/', (string) $text) as $para) {
            if (preg_match('/\A\s*#{1,6} [^\n]*\s*\z/', $para)) {
                continue; // a heading alone repeats the title; the excerpt is the text under it
            }
            $score = count(array_intersect_key(array_flip(self::terms($para)), $want));
            if ($score > $bestScore) {
                $best = $para;
                $bestScore = $score;
            }
        }
        $best = trim($best);
        return strlen($best) > $max ? substr($best, 0, $max) . '…' : $best;
    }
}
