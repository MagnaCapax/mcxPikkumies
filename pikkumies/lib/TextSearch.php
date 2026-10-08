<?php
/**
 * TextSearch: the one ranker for lessons and cached documentation (markdown files).
 *
 * Whole words, stopwords dropped, all terms required (a quarter may miss at 5+ terms),
 * query words in the title count double, score divided by sqrt(length), inline-link boost only for
 * files that already matched.
 * Deterministic and local: nothing leaves the machine.
 */
class TextSearch
{
    const STOPWORDS = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'is', 'it', 'how', 'what', 'why', 'do', 'does', 'with', 'my', 'i'];

    public static function terms($text)
    {
        preg_match_all('/[a-z0-9][a-z0-9._-]*/', strtolower((string) $text), $m);
        return array_values(array_diff(array_unique($m[0]), self::STOPWORDS));
    }

    public static function slug($title, $fallback = 'lesson')
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $title)), '-');
        return $slug !== '' ? $slug : $fallback;
    }

    /** Lesson directories: the user's own (pikkumies/memory) plus read-only lessons shipped by packs. */
    public static function lessonDirs($pikkumiesRoot)
    {
        $dirs = [$pikkumiesRoot . '/memory'];
        foreach ((array) glob(dirname($pikkumiesRoot) . '/packs/*/memory') as $d) {
            $dirs[] = $d;
        }
        return $dirs;
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
     */
    public static function rank(array $files, $query, $limit, $share = null)
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
        // Link boost: matched files linked from other matched files rank higher.
        foreach ($scores as $f => $s) {
            if (preg_match_all('/\]\(([^)]+\.md)\)/', $files[$f], $m)) {
                foreach ($m[1] as $target) {
                    $abs = realpath(dirname($f) . '/' . $target);
                    if ($abs !== false && isset($scores[$abs])) {
                        $scores[$abs] += 0.25 * $s;
                    }
                }
            }
        }
        arsort($scores);
        return array_slice(array_keys($scores), 0, $limit);
    }
}
