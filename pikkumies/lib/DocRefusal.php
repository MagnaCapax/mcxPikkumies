<?php
/**
 * DocRefusal: a documentation site that refuses us is left alone for a while (ADR-0031, amended 2026-10-19).
 *
 * A `site` source whose server answers 403 (Forbidden) or 429 (Too Many Requests), for robots.txt, the page
 * list or a page, is paused: no request goes to it until the pause ends, in the background fill and in an
 * explicit refresh alike. The pause is the server's Retry-After when it gives one in seconds, else 7 days,
 * and never more than 30 days. It is kept in the source's meta.json (`pausedUntil`) and shown by the memory
 * unit's `sources` verb. Every account fetches its own copy, so on a shared server several accounts share one
 * IP; each one's Crawl-delay does not add up to a polite rate, and a refusal is the site saying so.
 */
require_once __DIR__ . '/DocSources.php';

/** Thrown by DocSite::fetch for a refusing answer; the code is the HTTP status. */
class DocSiteRefused extends Exception
{
    /** @var string the Retry-After header as the server sent it, '' when none */
    public $retryAfter = '';
}

class DocRefusal
{
    const CODES = [403, 429];
    const PAUSE = 604800;      // seconds (7 days) when the server gives no usable Retry-After
    const MAX_PAUSE = 2592000; // seconds (30 days): a longer Retry-After is cut to this

    public static function refuses($code)
    {
        return in_array((int) $code, self::CODES, true);
    }

    /** Seconds to pause: Retry-After when it is a whole number of seconds above 0, else PAUSE; at most MAX_PAUSE. */
    public static function seconds($retryAfter)
    {
        $s = trim((string) $retryAfter);
        return ctype_digit($s) && (int) $s > 0 ? min((int) $s, self::MAX_PAUSE) : self::PAUSE;
    }

    /** $meta with the pause recorded after $e; the error says which address refused and until when. */
    public static function pause(array $meta, DocSiteRefused $e, $url, $now)
    {
        $meta['pausedUntil'] = $now + self::seconds($e->retryAfter);
        $meta['error'] = $url . ': refused (HTTP ' . $e->getCode() . '), paused until ' . gmdate('Y-m-d H:i', $meta['pausedUntil']) . ' UTC';
        $meta['errorAt'] = $now;
        return $meta;
    }

    /** True while the source's meta says it is paused. */
    public static function paused(array $meta, $now)
    {
        return isset($meta['pausedUntil']) && (int) $meta['pausedUntil'] > $now;
    }
}
