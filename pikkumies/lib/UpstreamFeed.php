<?php
/**
 * UpstreamFeed: the github.com Atom feeds of an upstream project (ADR-0092): commits.atom, releases.atom and tags.atom.
 * They spend none of the shared API allowance and carry no rate headers (measured 2026-10-20). Entries are cached per
 * user for 6 hours (UpstreamCache) and an expired copy is served, marked stale, when github.com does not answer.
 */
class UpstreamFeed
{
    /** Entries of a github.com Atom feed (no API allowance), cached: ['entries' => [...], 'stale' => bool], or null. */
    public static function feed($path)
    {
        $url = Upstream::WEB . '/' . ltrim($path, '/');
        $dir = Upstream::cacheDir();
        $now = Upstream::now();
        $e = UpstreamCache::get($dir, "GET $url", $now);
        if ($e && $e['fresh']) {
            return ['entries' => $e['data'], 'stale' => false, 'cached' => true];
        }
        $r = Upstream::http($url, ['Accept: application/atom+xml']);
        if ($r['code'] === 200 && strpos($r['body'], '<feed') !== false) {
            $entries = self::atom($r['body']);
            UpstreamCache::put($dir, "GET $url", $entries, Upstream::TTL['feed'], $now);
            return ['entries' => $entries, 'stale' => false, 'cached' => false];
        }
        return $e ? ['entries' => $e['data'], 'stale' => true, 'cached' => true] : null;
    }

    /** The latest 10 releases from releases.atom, or tags from tags.atom when there are none: [records, feed, stale]. */
    public static function releases($repo)
    {
        foreach (['releases.atom' => 'release', 'tags.atom' => 'tag'] as $feed => $kind) {
            $f = self::feed("$repo/$feed");
            if ($f !== null && $f['entries']) {
                return [array_map(function ($e) use ($kind) {
                    return [$kind => $e['title'], 'date' => $e['updated'], 'url' => $e['url']] + ($kind === 'release' ? ['excerpt' => $e['excerpt']] : []);
                }, array_slice($f['entries'], 0, 10)), $feed, $f['stale']];
            }
        }
        throw new CliError("no releases or tags could be read for $repo", 'check owner/name; a project may publish neither');
    }

    /** [title, updated, url, excerpt] of each <entry>, read with patterns (no XML extension needed). */
    public static function atom($xml)
    {
        $out = [];
        preg_match_all('#<entry>(.*?)</entry>#s', (string) $xml, $m);
        foreach ($m[1] as $e) {
            $get = function ($re) use ($e) {
                return preg_match($re, $e, $x) ? trim(html_entity_decode($x[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            };
            $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($get('#<content[^>]*>(.*?)</content>#s'))));
            $out[] = ['title' => Upstream::cut($get('#<title>(.*?)</title>#s'), 200), 'updated' => $get('#<updated>(.*?)</updated>#'),
                'url' => $get('#<link[^>]*href="([^"]+)"#'), 'excerpt' => Upstream::cut(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 400)];
        }
        return $out;
    }

}
