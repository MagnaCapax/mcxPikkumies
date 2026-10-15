<?php
/**
 * DocSite: the `site` documentation type (ADR-0027). `url` is a sitemap.xml, a sitemap index, or an
 * HTML index page whose links are the pages. Pages are downloaded a few at a time and kept one
 * markdown file each in local/docs-cache/<name>/; meta.json remembers when each was fetched, so every
 * run fetches missing pages first, then the oldest ones past the refresh period, within its budget.
 *
 * Only pages on the same host as `url`, under its path prefix, and allowed by that host's robots.txt
 * are fetched. A failed run keeps every page already downloaded. Fetched text is data, not instructions.
 */
require_once __DIR__ . '/DocSources.php';

class DocSite
{
    const MAX_SITE_PAGES = 500;            // pages kept per source; the oldest go first beyond it
    const MAX_CACHE_BYTES = 209715200;     // 200 MB for the whole docs cache; past it no new pages
    const MAX_SUBSITEMAPS = 20;            // sub-sitemaps followed from one sitemap index
    const MAX_LINKS = 5000;                // URLs read from one sitemap or page
    const MAX_DELAY = 60;                  // seconds; a larger Crawl-delay is cut to this

    /** @var callable|null test seam: fn(seconds) instead of sleeping */
    public static $sleep = null;

    /**
     * Fetch up to $budget requests' worth of the source. $background: the daily fill (at least one
     * second between page requests, longer when robots.txt sets a Crawl-delay). Otherwise an explicit
     * foreground refresh, which never sleeps: with a Crawl-delay it fetches one page and leaves the
     * rest to the fill. Returns [ok, message].
     */
    public static function refresh(array $src, $budget, $background = false)
    {
        $root = DocSources::cacheRoot();
        $dir = $root . '/' . $src['name'];
        @mkdir($dir, 0700, true);
        $lock = @fopen($root . '/' . $src['name'] . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return [false, 'skipped: another download of this source is running'];
        }
        try {
            return self::run($src, $dir, (int) $budget, $background);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function run(array $src, $dir, $budget, $background)
    {
        $now = time();
        $period = DocSources::refreshSeconds(isset($src['refresh']) ? $src['refresh'] : '1d');
        $meta = DocSources::meta($src['name']);
        $pages = isset($meta['pages']) && is_array($meta['pages']) ? $meta['pages'] : [];
        $found = isset($meta['found']) && is_array($meta['found']) ? $meta['found'] : [];
        $host = (string) parse_url($src['url'], PHP_URL_HOST);
        $prefix = self::prefix($src);
        $maxDepth = isset($src['depth']) && (int) $src['depth'] === 2 ? 2 : 1;
        $used = 0;

        // The page list and robots.txt, again once per refresh period.
        if (!isset($meta['list']) || !isset($meta['listAt']) || $now - (int) $meta['listAt'] > $period) {
            $robots = self::robots($host, $used);
            if ($robots === null) {
                // robots.txt did not answer (5xx or no response): its rules are unknown, so nothing is fetched this run.
                $meta['error'] = 'robots.txt not reachable; trying again on the next run';
                $meta['errorAt'] = $now;
                self::saveMeta($dir, $meta);
                return [false, 'skipped: ' . $meta['error']];
            }
            $meta['robots'] = $robots;
            try {
                $meta['list'] = self::listUrls($src, $host, $prefix, $used);
            } catch (Exception $e) {
                $meta['error'] = 'page list: ' . $e->getMessage();
                $meta['errorAt'] = $now;
                self::saveMeta($dir, $meta);
                return [false, $meta['error']];
            }
            $meta['listAt'] = $now;
        }
        $robots = isset($meta['robots']) ? $meta['robots'] : ['rules' => [], 'delay' => 0];
        $delay = min(self::MAX_DELAY, (int) $robots['delay']);
        $onePage = !$background && $delay > 0; // a foreground run never sleeps for a Crawl-delay
        if ($background) {
            $delay = max(1, $delay);
        }

        // Every known URL with its depth, the list first, capped; then what needs fetching.
        $known = [];
        foreach ($meta['list'] as $u => $d) {
            $known[$u] = (int) $d;
        }
        foreach ($found as $u => $d) {
            $known += [$u => (int) $d];
        }
        $known = array_slice($known, 0, self::MAX_SITE_PAGES, true);
        $missing = [];
        $stale = [];
        foreach ($known as $u => $d) {
            if (!self::allowed($u, $robots['rules'])) {
                continue;
            }
            if (!isset($pages[$u])) {
                $missing[] = $u;
            } elseif ($now - (int) $pages[$u]['t'] > $period) {
                $stale[$u] = (int) $pages[$u]['t'];
            }
        }
        asort($stale);
        $todo = array_merge($missing, array_keys($stale));

        $cacheBytes = self::dirBytes(DocSources::cacheRoot());
        $fetched = 0;
        $failed = 0;
        $full = false;
        foreach ($todo as $i => $u) {
            if ($used >= $budget || ($onePage && $fetched + $failed > 0)) {
                break;
            }
            if (!isset($pages[$u]) && $cacheBytes >= self::MAX_CACHE_BYTES) {
                $full = true;
                continue;
            }
            if ($background && $fetched + $failed > 0) {
                self::pause($delay);
            }
            $used++;
            // The file name comes from the URL alone, before the fetch: a page that failed once still gets one.
            $slug = isset($pages[$u]['slug']) && $pages[$u]['slug'] !== '' ? $pages[$u]['slug'] : self::slugFor($u, $pages);
            try {
                list($html, $final) = self::fetch($u, $host);
                $doc = self::htmlToText($html);
                $page = DocSources::renderPage($src, ['title' => self::title($doc, $u, $pages), 'url' => $final, 'text' => $doc['text'], 'updated' => gmdate('c', $now)]);
                $old = is_file("$dir/$slug.md") ? filesize("$dir/$slug.md") : 0;
                if (!self::writeFile("$dir/$slug.md", $page)) {
                    throw new Exception('could not write the copy (disk full?)');
                }
                $cacheBytes += strlen($page) - $old;
                $pages[$u] = ['slug' => $slug, 't' => $now, 'rt' => $doc['title']];
                $fetched++;
                if ($known[$u] < $maxDepth) {
                    foreach (self::links($html, $final, $host, $prefix) as $l) {
                        if (!isset($known[$l]) && !isset($found[$l]) && count($found) < self::MAX_SITE_PAGES) {
                            $found[$l] = $known[$u] + 1;
                        }
                    }
                }
            } catch (Exception $e) {
                // Not retried before the refresh period; a page fetched earlier keeps its copy.
                $pages[$u] = (isset($pages[$u]) ? $pages[$u] : []) + ['t' => 0];
                $pages[$u]['slug'] = $slug;
                $pages[$u]['t'] = $now;
                $pages[$u]['fail'] = $e->getMessage();
                $meta['error'] = $u . ': ' . $e->getMessage();
                $meta['errorAt'] = $now;
                $failed++;
            }
        }
        $pages = self::evict($dir, $pages);
        $count = 0;
        foreach ($pages as $p) {
            $count += $p['slug'] !== '' && is_file($dir . '/' . $p['slug'] . '.md') ? 1 : 0;
        }
        $meta['type'] = 'site';
        $meta['url'] = $src['url'];
        $meta['fetched'] = $now;
        $meta['count'] = $count;
        $meta['pages'] = $pages;
        $meta['found'] = $found;
        if ($failed === 0) {
            unset($meta['error'], $meta['errorAt']); // a clean run clears the last error
        }
        if (!self::saveMeta($dir, $meta)) {
            return [false, 'could not write the copy (disk full?)'];
        }
        $msg = "$fetched pages fetched, $count kept" . ($failed ? ", $failed failed" : '') . ($full ? ', docs cache full (' . (self::MAX_CACHE_BYTES >> 20) . ' MB): no new pages' : '')
            . ($onePage && count($todo) > 1 ? ", Crawl-delay {$robots['delay']} s: the rest is fetched by the background fill" : '');
        return [true, $msg];
    }

    /** `prefix:` when set, else the url's directory for an index page; sitemaps default to "/". */
    public static function prefix(array $src)
    {
        if (isset($src['prefix']) && $src['prefix'] !== '') {
            return $src['prefix'];
        }
        $path = (string) parse_url($src['url'], PHP_URL_PATH);
        return preg_match('/\.xml$/i', $path) ? '/' : substr($path, 0, strrpos($path, '/') + 1);
    }

    /** url => depth: sitemap <loc>s (an index's sub-sitemaps on the same host), or the index page and its links. */
    public static function listUrls(array $src, $host, $prefix, &$used)
    {
        $used++;
        list($body, $final) = self::fetch($src['url'], $host);
        $list = [];
        if (preg_match('/<(urlset|sitemapindex)\b/i', substr($body, 0, 4096), $m)) {
            $locs = self::locs($body);
            if (strtolower($m[1]) === 'sitemapindex') {
                $sub = [];
                foreach (array_slice($locs, 0, self::MAX_SUBSITEMAPS) as $s) {
                    if (($s = self::normalize($s, $host)) === '') {
                        continue;
                    }
                    $used++;
                    try {
                        list($b) = self::fetch($s, $host);
                    } catch (Exception $e) {
                        continue; // one broken sub-sitemap does not hide the others
                    }
                    if (!preg_match('/<sitemapindex\b/i', substr($b, 0, 4096))) { // one level only
                        $sub = array_merge($sub, self::locs($b));
                    }
                }
                $locs = $sub;
            }
            foreach ($locs as $l) {
                if (($l = self::normalize($l, $host)) !== '' && self::underPrefix($l, $prefix)) {
                    $list[$l] = 1;
                }
            }
        } else {
            $list[self::normalize($final, $host)] = 0;
            foreach (self::links($body, $final, $host, $prefix) as $l) {
                $list += [$l => 1];
            }
        }
        unset($list['']);
        if (!$list) {
            throw new Exception('no pages found at ' . $src['url']);
        }
        return array_slice($list, 0, self::MAX_SITE_PAGES, true);
    }

    /** <loc> values, bounded (no XML parser: no entities or external references are ever resolved). */
    public static function locs($xml)
    {
        preg_match_all('#<loc>\s*([^<\s]{1,2048})\s*</loc>#i', (string) $xml, $m);
        return array_map(function ($s) {
            return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }, array_slice($m[1], 0, self::MAX_LINKS));
    }

    /**
     * Page links of an HTML page: same host, under the prefix, no fragment or query, deduplicated.
     * A path segment starting with "_" is a wiki action (GitHub's /_new, /_history, /_compare, /_edit),
     * not a page; only /_pages, the page list itself, is kept.
     */
    public static function links($html, $base, $host, $prefix)
    {
        preg_match_all('#<a\s[^>]{0,2048}?href\s*=\s*(["\'])([^"\']{1,2048})\1#i', (string) $html, $m);
        $out = [];
        foreach (array_slice($m[2], 0, self::MAX_LINKS) as $href) {
            $u = self::normalize(self::resolve($base, html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $host);
            if ($u !== '' && self::underPrefix($u, $prefix) && !preg_match('#/_(?!pages(?:/|$))#', (string) parse_url($u, PHP_URL_PATH))) {
                $out[$u] = true;
            }
        }
        return array_keys($out);
    }

    /** An absolute URL for $href on page $base, or '' for mailto:, javascript: and other schemes. */
    public static function resolve($base, $href)
    {
        if ($href === '' || $href[0] === '#') {
            return '';
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return '';
        }
        $b = parse_url($base);
        if (!isset($b['host'])) {
            return '';
        }
        if (substr($href, 0, 2) === '//') {
            return 'https:' . $href;
        }
        $path = isset($b['path']) ? $b['path'] : '/';
        if ($href[0] !== '/') {
            $href = substr($path, 0, strrpos($path, '/') + 1) . $href;
        }
        return 'https://' . $b['host'] . $href;
    }

    /**
     * The URL as the cache keys it, or '' when it is not fetched: same host only (http upgraded to
     * https), query and fragment dropped, dot segments resolved, never a /clients/ page.
     */
    public static function normalize($url, $host)
    {
        $url = preg_replace('/[#?].*$/s', '', trim((string) $url));
        if (!preg_match('#^https?://([^/\s]+)(/[^\s]*)?$#i', $url, $m) || strtolower($m[1]) !== strtolower($host)) {
            return '';
        }
        $segs = [''];
        foreach (array_slice(explode('/', isset($m[2]) ? $m[2] : '/'), 1) as $s) {
            if ($s === '..') {
                if (count($segs) > 1) {
                    array_pop($segs);
                }
            } elseif ($s !== '.') {
                $segs[] = $s;
            }
        }
        $out = 'https://' . strtolower($host) . (count($segs) > 1 ? implode('/', $segs) : '/');
        return stripos($out, '/clients/') === false ? $out : '';
    }

    /** Under the prefix, and not an account page (login, register, cart): those are never documentation. */
    public static function underPrefix($url, $prefix)
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return strpos($path, $prefix) === 0 && !preg_match('#/(log-?in|log-?out|sign-?in|sign-?up|register|cart|checkout)([/.-]|$)#i', $path);
    }

    /**
     * robots.txt rules for User-agent * or pikkumies-docs (that group wins when both exist), and
     * Crawl-delay. A 4xx (no robots.txt) allows everything; null when it gave a 5xx or no response,
     * because then the rules are unknown and nothing may be fetched.
     */
    public static function robots($host, &$used)
    {
        $used++;
        try {
            list($text) = self::fetch("https://$host/robots.txt", $host);
        } catch (Exception $e) {
            $code = $e->getCode();
            return $code === 0 || $code >= 500 ? null : ['rules' => [], 'delay' => 0];
        }
        return self::parseRobots($text);
    }

    public static function parseRobots($text)
    {
        $groups = ['*' => null, 'pikkumies-docs' => null];
        $agents = [];
        $inRules = false;
        foreach (array_slice(preg_split('/\r?\n/', substr((string) $text, 0, 524288)), 0, 20000) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if (!preg_match('/^([a-z-]+)\s*:\s*(.*)$/i', $line, $m)) {
                continue;
            }
            $key = strtolower($m[1]);
            $val = trim($m[2]);
            if ($key === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($val);
                continue;
            }
            $inRules = true;
            foreach ($agents as $a) {
                if (!array_key_exists($a, $groups)) {
                    continue;
                }
                if ($groups[$a] === null) {
                    $groups[$a] = ['rules' => [], 'delay' => 0];
                }
                if (($key === 'allow' || $key === 'disallow') && $val !== '') {
                    $groups[$a]['rules'][] = [$key === 'allow', $val];
                } elseif ($key === 'crawl-delay' && is_numeric($val)) {
                    $groups[$a]['delay'] = (int) ceil((float) $val);
                }
            }
        }
        $g = $groups['pikkumies-docs'] !== null ? $groups['pikkumies-docs'] : $groups['*'];
        return $g !== null ? $g : ['rules' => [], 'delay' => 0];
    }

    /** The longest matching rule decides (`*` and a trailing `$` understood); a tie or no match allows. */
    public static function allowed($url, array $rules)
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $best = -1;
        $allow = true;
        foreach ($rules as $r) {
            list($isAllow, $pattern) = $r;
            $re = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')) . '#';
            if (preg_match($re, $path) && (strlen($pattern) > $best || (strlen($pattern) === $best && $isAllow))) {
                $best = strlen($pattern);
                $allow = $isAllow;
            }
        }
        return $allow;
    }

    /**
     * [body, final url]. One redirect is followed when it only adds or drops a trailing slash (a page
     * moved to its directory address); anything else that is not 200 is an error whose code is the
     * HTTP status (0: no response).
     */
    public static function fetch($url, $host)
    {
        for ($hop = 0; $hop < 2; $hop++) {
            if (strpos($url, 'https://') !== 0 || stripos($url, '/clients/') !== false) {
                throw new Exception('refusing to fetch ' . $url);
            }
            if (DocSources::$fetcher !== null) {
                $r = call_user_func(DocSources::$fetcher, $url);
                $r = is_array($r) ? $r + ['code' => 200, 'body' => '', 'location' => ''] : ['code' => 200, 'body' => (string) $r, 'location' => ''];
            } else {
                $h = Http::request('GET', $url, ['timeout' => 30, 'agent' => 'pikkumies-docs', 'max' => DocSources::MAX_RESPONSE]);
                $r = ['code' => $h['code'], 'body' => $h['body'], 'location' => isset($h['headers']['location'][0]) ? $h['headers']['location'][0] : '', 'error' => $h['error']];
            }
            if ($r['code'] === 200) {
                return [(string) $r['body'], $url];
            }
            $next = in_array($r['code'], [301, 302, 303, 307, 308], true) ? self::normalize(self::resolve($url, $r['location']), $host) : '';
            if ($hop > 0 || $next === '' || rtrim($next, '/') !== rtrim($url, '/')) {
                throw new Exception(trim('HTTP ' . $r['code'] . ' ' . (isset($r['error']) ? $r['error'] : '')), (int) $r['code']);
            }
            $url = $next;
        }
        throw new Exception('too many redirects');
    }

    /** ['title' => <title>, 'h1' => the first <h1>, 'text' => ...]: the main part of the page without scripts, menus and footers. */
    public static function htmlToText($html)
    {
        $html = (string) $html;
        $plain = function ($s) {
            return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        };
        $title = preg_match('#<title[^>]*>([^<]{1,500})</title>#i', $html, $m) ? $plain($m[1]) : '';
        $whole = $html;
        foreach (['main', 'article'] as $tag) {
            $a = stripos($html, "<$tag");
            $b = strripos($html, "</$tag>");
            if ($a !== false && $b !== false && $b > $a) {
                $html = substr($html, $a, $b - $a);
                break;
            }
        }
        $h1 = '';
        if (preg_match('#<h1[^>]*>(.{1,500}?)</h1>#is', $html, $m) || preg_match('#<h1[^>]*>(.{1,500}?)</h1>#is', $whole, $m)) {
            $h1 = (string) preg_replace('/^(?:\s|#|\xC2\xB6|\xC2\xA7)+|(?:\s|#|\xC2\xB6|\xC2\xA7)+$/', '', $plain($m[1])); // anchor marks such as a pilcrow
        }
        $clean = preg_replace('#<(script|style|noscript|nav|header|footer|aside|svg|form)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $html = $clean !== null ? $clean : preg_replace('#<(script|style)\b#i', '<x', $html);
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6]|/pre|/section|/table|/ul|/ol|/dt|/dd|/blockquote)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/(?:[ \t]|\xC2\xA0)+/', ' ', $text);
        $text = preg_replace('/ *\n[ \n]*/', "\n", (string) $text);
        $text = trim(preg_replace("/\n{3,}/", "\n\n", (string) $text));
        return ['title' => self::cleanTitle($title), 'h1' => self::cleanTitle($h1), 'text' => $text];
    }

    /** One line of at most 120 characters: control characters, U+0080-U+009F and the U+2028/2029 line breaks become spaces. */
    public static function cleanTitle($s)
    {
        $s = (string) $s;
        if (!preg_match('//u', $s)) {
            $s = preg_replace('/[\x80-\xFF]/', '?', $s); // not UTF-8: keep the ASCII
        }
        $s = trim(preg_replace('/[\x{0}-\x{1F}\x{7F}-\x{9F}\x{2028}\x{2029}\s]+/u', ' ', $s));
        return preg_match('/^.{0,120}/us', $s, $m) ? rtrim($m[0]) : substr($s, 0, 120);
    }

    /** The page's <title>, or "<h1> - <title>" when another page of the source has the same <title> (a site-wide one). */
    public static function title(array $doc, $url, array $pages)
    {
        $shared = false;
        foreach ($pages as $u => $p) {
            $shared = $shared || ($u !== $url && isset($p['rt']) && $p['rt'] === $doc['title']);
        }
        if ($doc['title'] === '') {
            return self::cleanTitle($doc['h1'] !== '' ? $doc['h1'] : $url);
        }
        return self::cleanTitle($shared && $doc['h1'] !== '' && stripos($doc['title'], $doc['h1']) === false ? $doc['h1'] . ' - ' . $doc['title'] : $doc['title']);
    }

    /** A file name from the URL path, at most 100 characters, unique within the source, never empty. */
    public static function slugFor($url, array $pages)
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $slug = TextSearch::slug(preg_replace('/\.html?$/i', '', $path), 'index');
        if ($slug === '') {
            $slug = 'page-' . substr(sha1($url), 0, 8);
        }
        $taken = [];
        foreach ($pages as $p) {
            $taken[isset($p['slug']) ? $p['slug'] : ''] = true;
        }
        if (strlen($slug) > 100 || isset($taken[$slug])) {
            $slug = substr($slug, 0, 91) . '-' . substr(sha1($url), 0, 8);
        }
        return $slug;
    }

    /** Beyond MAX_SITE_PAGES, the oldest pages are removed first. */
    private static function evict($dir, array $pages)
    {
        if (count($pages) <= self::MAX_SITE_PAGES) {
            return $pages;
        }
        uasort($pages, function ($a, $b) {
            return $a['t'] - $b['t'];
        });
        foreach (array_slice($pages, 0, count($pages) - self::MAX_SITE_PAGES, true) as $u => $p) {
            if ($p['slug'] !== '') {
                @unlink("$dir/{$p['slug']}.md");
            }
            unset($pages[$u]);
        }
        return $pages;
    }

    private static function writeFile($file, $data)
    {
        $tmp = dirname($file) . '/.tmp-' . basename($file) . '-' . getmypid();
        if (@file_put_contents($tmp, $data) !== strlen($data) || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    private static function saveMeta($dir, array $meta)
    {
        return self::writeFile("$dir/meta.json", json_encode($meta, JSON_UNESCAPED_SLASHES) . "\n");
    }

    public static function dirBytes($dir)
    {
        $n = 0;
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $n += $f->isFile() ? $f->getSize() : 0;
            }
        }
        return $n;
    }

    private static function pause($seconds)
    {
        if (self::$sleep !== null) {
            call_user_func(self::$sleep, $seconds);
        } else {
            sleep($seconds);
        }
    }
}
