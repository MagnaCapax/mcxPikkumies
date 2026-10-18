<?php
/**
 * UpdateChannel: the release channel of `pikkumies update` and of a first install (ADR-0057).
 * A release is a date tag on origin: YYYY-MM-DD, or YYYY-MM-DD.N (N 1-99) for a second release the
 * same day. Moving to it is a fast-forward only; a checkout already ahead of the newest release stays
 * where it is. The one other move, `checkout -B main <tag>`, happens only on a first install, on a
 * fresh clone with nothing of the user's in it.
 */
class UpdateChannel
{
    const RELEASE_RE = '/^(\d{4}-\d{2}-\d{2})(?:\.([1-9][0-9]?))?$/';

    /**
     * The newest release tag in `git ls-remote --tags` output, '' when there is none: by date, then N.
     * Peeled (^{}) lines, other tags and tags dated after tomorrow (UTC, from $now) never count, so a
     * mistyped future date cannot hold every install back.
     */
    public static function latestRelease($lsRemote, $now = null)
    {
        $limit = gmdate('Y-m-d', ($now === null ? time() : $now) + 86400);
        $best = '';
        foreach (preg_split('/\r?\n/', (string) $lsRemote) as $line) {
            if (preg_match('#^[0-9a-f]{40,64}\s+refs/tags/(\S+)$#', trim($line), $m) && self::isRelease($m[1])
                && strcmp(substr($m[1], 0, 10), $limit) <= 0 && ($best === '' || strcmp(self::sortKey($m[1]), self::sortKey($best)) > 0)) {
                $best = $m[1];
            }
        }
        return $best;
    }

    /** A tag name that is a release: YYYY-MM-DD with a real date, optionally .1 to .99. */
    public static function isRelease($tag)
    {
        return is_string($tag) && preg_match(self::RELEASE_RE, $tag, $m) === 1
            && checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4));
    }

    /** A release as a string that sorts by date, then N (none = 0). */
    private static function sortKey($tag)
    {
        preg_match(self::RELEASE_RE, $tag, $m);
        return $m[1] . sprintf('.%02d', isset($m[2]) ? (int) $m[2] : 0);
    }

    /** The newest release on origin: the tag, '' when none is published, null when origin could not be read ($out holds git's words). */
    public static function newest(&$out = null)
    {
        $out = Update::git(['ls-remote', '--tags', 'origin'], $code);
        return $code === 0 ? self::latestRelease(implode("\n", $out)) : null;
    }

    /**
     * Fetch release $tag (a plain fetch, never --depth: see Update::run) into refs/tags/$tag. A local tag
     * of that name pointing elsewhere is never replaced (git refuses to clobber it). Returns the commit, or
     * null with git's words in $out.
     */
    public static function fetch($tag, &$out = null)
    {
        $out = Update::git(['fetch', 'origin', "refs/tags/$tag:refs/tags/$tag"], $code);
        if ($code !== 0) {
            return null;
        }
        $sha = Update::git(['rev-parse', '--verify', '--quiet', "refs/tags/$tag^{commit}"], $code);
        return $code === 0 && $sha ? trim($sha[0]) : null;
    }

    /**
     * Where HEAD stands against commit $sha: same, behind (a fast-forward reaches it), ahead (it is
     * already in HEAD's history), diverged, or unknown. A shallow clone cannot see past its boundary,
     * so an older release would look diverged: the history is completed once (it is small) and asked
     * again; unknown when that fetch fails.
     */
    public static function relation($sha)
    {
        $head = trim(implode('', Update::git(['rev-parse', 'HEAD'])));
        if ($head === $sha) {
            return 'same';
        }
        for ($try = 0; $try < 2; $try++) {
            Update::git(['merge-base', '--is-ancestor', 'HEAD', $sha], $code);
            if ($code === 0) {
                return 'behind';
            }
            Update::git(['merge-base', '--is-ancestor', $sha, 'HEAD'], $code);
            if ($code === 0) {
                return 'ahead';
            }
            if ($try === 0 && trim(implode('', Update::git(['rev-parse', '--is-shallow-repository']))) === 'true') {
                Update::git(['fetch', '--unshallow', 'origin'], $code);
                if ($code !== 0) {
                    return 'unknown';
                }
            }
        }
        return 'diverged';
    }

    /** `pikkumies update` on the release channel, once a release $tag exists. Returns [ok, message]. */
    public static function toRelease($tag, $before)
    {
        $sha = self::fetch($tag, $out);
        if ($sha === null) {
            return [false, "fetch of release $tag failed:\n" . implode("\n", $out)];
        }
        switch (self::relation($sha)) {
            case 'same':
                return [true, "already at the latest release $tag ($before)"];
            case 'ahead':
                return [true, "ahead of the latest release $tag; staying here until a newer release ($before)"];
            case 'diverged':
                return [false, "cannot fast-forward to release $tag (history diverged); nothing changed"];
            case 'unknown':
                return [false, "could not compare with release $tag (fetching the full history failed); nothing changed"];
        }
        $merge = Update::git(['merge', '--ff-only', $sha], $code);
        if ($code !== 0) {
            return [false, "cannot fast-forward to release $tag; nothing changed:\n" . implode("\n", $merge)];
        }
        return [true, "updated $before -> " . self::shortHead() . " (release $tag)"];
    }

    /**
     * First install on the release channel: move a fresh clone to the newest release with
     * `checkout -B main <tag>`. Fresh means: no $localDir yet, no tracked edits, and HEAD's reflog holds
     * only the clone itself, so a checkout that was ever updated or moved (local/ deleted and installed
     * again) is never moved, backwards least of all. Returns [moved, message]; message '' when there is
     * nothing to say.
     */
    public static function firstInstallMove($channel, $localDir)
    {
        if ($channel !== 'release' || file_exists($localDir)) {
            return [false, ''];
        }
        $log = Update::git(['reflog', '-n', '2', '--format=%gs', 'HEAD'], $code);
        if ($code !== 0 || count($log) !== 1 || strpos($log[0], 'clone:') !== 0) {
            return [false, ''];
        }
        $dirty = Update::git(['status', '--porcelain', '--untracked-files=no'], $code);
        if ($code !== 0 || $dirty) {
            return [false, $code !== 0 ? '' : 'not moved to the latest release: tracked files were changed'];
        }
        $tag = self::newest($out);
        if ($tag === null || $tag === '') {
            return [false, $tag === null ? 'not moved to the latest release: could not list the releases' : ''];
        }
        $sha = self::fetch($tag, $out);
        if ($sha === null) {
            return [false, "not moved to release $tag: fetch failed"];
        }
        if (trim(implode('', Update::git(['rev-parse', 'HEAD']))) === $sha) {
            return [false, ''];
        }
        $before = self::shortHead();
        $co = Update::git(['checkout', '-q', '-B', 'main', $sha], $code);
        return $code === 0 ? [true, "moved $before -> " . self::shortHead() . " (release $tag)"]
            : [false, "not moved to release $tag: " . implode(' ', $co)];
    }

    /** For doctor and update: the channel and where the checkout is, read from the checkout only (never fetched). */
    public static function describe($channel)
    {
        $tags = array_values(array_filter(Update::git(['tag', '--points-at', 'HEAD']), [__CLASS__, 'isRelease']));
        usort($tags, function ($a, $b) {
            return strcmp(self::sortKey($b), self::sortKey($a));
        });
        $at = $tags ? 'release ' . $tags[0] . ' (' . self::shortHead() . ')' : 'commit ' . self::shortHead();
        $name = Config::validChannel($channel) ? $channel : json_encode($channel) . ' (not valid, so update refuses it: use release or git/<branch>)';
        return "channel $name, at $at";
    }

    private static function shortHead()
    {
        return trim(implode('', Update::git(['rev-parse', '--short', 'HEAD'])));
    }
}
