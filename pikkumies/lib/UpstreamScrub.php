<?php
/**
 * UpstreamScrub: what is taken out of text before it goes to a public upstream project (ADR-0092): an issue search query
 * or an issue draft. Redact::secrets() removes passwords and tokens; this removes who and where the user is, which it does
 * not: the home path, any /home/<name>/, the login name and uid, this machine's host name, Pulsed Media's and other
 * providers' host names, IP addresses, ports, e-mail addresses, tracker, feed and keyed URLs, private tracker host:port,
 * torrent info hashes, release names, and torrent, media and file names in paths (customer data). Deterministic patterns only: no list catches every personal detail, so the user reads
 * the whole draft before anything is published (ADR-0083 says the same of names and addresses).
 */
class UpstreamScrub
{
    const MEDIA = 'torrent|mkv|mp4|m4v|avi|mov|wmv|webm|ts|mpg|mpeg|mp3|m4a|flac|ogg|opus|wav|aac|iso|img|rar|zip|7z|tar|gz|nfo|srt|sub|ass|idx|epub|mobi|azw3|pdf|cbr|cbz|jpg|jpeg|png|gif';
    const DATA_DIRS = 'data|downloads?|torrents?|media|watch|session|completed|incoming|movies|tv|music|books';
    const RELEASE = '/(?:\b[Ss]\d{1,2}[Ee]\d{1,3}\b|\b(?:480|576|720|1080|2160)[pi]\b|\b[xh]\.?26[45]\b|\bWEB-?(?:DL|Rip)\b|\bBlu-?Ray\b|\bHDTV\b|\bREMUX\b)/i';

    /** Who and where this account is: home, login, uid, host names. $over: test values. */
    public static function context(array $over = [])
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $pw = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
        $host = (string) gethostname();
        $fqdn = $host !== '' ? (string) @gethostbyaddr((string) @gethostbyname($host)) : '';
        $hosts = [];
        foreach (Host::TABLE as $h) {
            $hosts = array_merge($hosts, $h['suffixes']);
        }
        return $over + [
            'home' => rtrim((string) getenv('HOME'), '/'),
            'login' => is_array($pw) ? (string) $pw['name'] : (string) get_current_user(),
            'uid' => (int) $uid,
            'hosts' => array_values(array_filter(array_unique([$host, $fqdn]), function ($h) {
                return $h !== '' && !filter_var($h, FILTER_VALIDATE_IP) && $h !== 'localhost';
            })),
            'domains' => array_values(array_unique(array_merge(['pulsedmedia.com'], $hosts))),
        ];
    }

    /** [scrubbed text, replaced: [what => count]]. Line by line, so a hit never joins two lines. */
    public static function scrub($text, array $ctx)
    {
        $counts = [];
        $out = [];
        foreach (explode("\n", (string) $text) as $line) {
            $out[] = self::line($line, $ctx, $counts);
        }
        return [implode("\n", $out), $counts];
    }

    private static function line($s, array $ctx, array &$counts)
    {
        $rules = [];
        // A tracker, feed or download URL, or any URL carrying a key (16+ letters and digits): the whole URL, its host included.
        $rules['tracker, feed or keyed URL'] = ['#\b[a-z][a-z0-9+.-]*://[^\s"\'<>]+#i', function ($m) {
            return preg_match('/announce|tracker|scrape|passkey|torrent|rss|download|(?<![A-Za-z0-9])(?=[A-Za-z0-9]*\d)[A-Za-z0-9]{16,}/i', $m[0]) ? 'URL' : $m[0];
        }];
        $rules['e-mail address'] = ['/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', 'EMAIL'];
        if ($ctx['home'] !== '' && $ctx['home'] !== '/') {
            $rules['home path'] = ['#' . preg_quote($ctx['home'], '#') . '(?=/|\b|$)#', '~'];
        }
        $rules['home directory name'] = ['#/home\d*/(?!USER\b)[^/\s"\'<>:]+#', '/home/USER'];
        // Names may hold spaces: both rules take too much rather than too little (the rest of the line after a data folder).
        $rules['file or media name in a path'] = ['#(?<=/)[^/"\'<>]+?\.(?:' . self::MEDIA . ')\b#i', 'FILE'];
        $rules['name under a data folder'] = ['#(/(?:' . self::DATA_DIRS . ')/)[^"\'<>]+#i', '$1NAME'];
        $alt = function (array $names) {
            usort($names, function ($a, $b) {
                return strlen($b) - strlen($a); // the FQDN before the short name
            });
            return implode('|', array_map(function ($d) {
                return preg_quote($d, '/');
            }, $names));
        };
        if ($ctx['hosts']) {
            $rules['this machine\'s host name'] = ['/(?<![A-Za-z0-9.-])(?:' . $alt($ctx['hosts']) . ')(?![A-Za-z0-9-])/i', 'HOST'];
        }
        $domains = $alt($ctx['domains']);
        $rules['provider host name'] = ['/(?<![A-Za-z0-9-])(?:[A-Za-z0-9-]+\.)*(?:' . $domains . ')(?![A-Za-z0-9-])/i', 'HOST'];
        $rules['host and port'] = ['/(?<![A-Za-z0-9.-])(?:[A-Za-z0-9-]+\.)+[A-Za-z][A-Za-z0-9-]*:\d{2,5}(?!\d)/', 'HOST:PORT']; // a private tracker's host
        $rules['torrent info hash'] = ['/(?<![0-9A-Za-z])(?:[0-9A-Fa-f]{64}|[0-9A-Fa-f]{40})(?![0-9A-Za-z])/', 'HASH'];
        $rules['IPv4 address'] = ['/(?<![0-9.])(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}(?![0-9.])/', 'IP'];
        $rules['IPv6 address'] = ['/(?<![0-9A-Za-z:])(?=[0-9A-Fa-f:]*[A-Fa-f]|[0-9A-Fa-f:]*::|(?:[0-9A-Fa-f]{1,4}:){4})(?:[0-9A-Fa-f]{0,4}:){2,7}[0-9A-Fa-f]{0,4}(?![0-9A-Za-z:])/', 'IP'];
        $rules['port'] = ['/\b(IP|HOST|localhost|127\.0\.0\.1|\[::1\]):\d{2,5}\b|\b(port[ =:]+)\d{2,5}\b/i', function ($m) {
            return isset($m[2]) && $m[2] !== '' ? $m[2] . 'PORT' : $m[1] . ':PORT';
        }];
        if (strlen($ctx['login']) >= 2) { // any case, and next to _ or - (alice_rtorrent, Alice)
            $rules['login name'] = ['/(?<![A-Za-z0-9])' . preg_quote($ctx['login'], '/') . '(?![A-Za-z0-9])/i', 'USER'];
        }
        if ($ctx['uid'] >= 1000) {
            $rules['uid'] = ['/(?<![0-9.:])' . $ctx['uid'] . '(?![0-9.:])/', 'UID'];
        }
        foreach ($rules as $what => $rule) {
            $n = 0;
            if ($rule[1] instanceof Closure) { // never is_callable: a replacement such as HASH names a PHP function
                $before = $s;
                $s = (string) preg_replace_callback($rule[0], $rule[1], $s);
                $n = $s === $before ? 0 : 1;
            } else {
                $s = (string) preg_replace($rule[0], $rule[1], $s, -1, $n);
            }
            if ($n > 0) {
                $counts[$what] = (isset($counts[$what]) ? $counts[$what] : 0) + $n;
            }
        }
        $n = 0; // a release name outside a path: a word with a release marker and two or more . or _ separators
        $s = (string) preg_replace_callback('#[^\s/"\'<>]+#', function ($m) use (&$n) {
            return preg_match(self::RELEASE, $m[0]) && preg_match_all('/[._]/', $m[0]) >= 2 && ++$n ? 'NAME' : $m[0];
        }, $s);
        if ($n > 0) {
            $counts['release name'] = (isset($counts['release name']) ? $counts['release name'] : 0) + $n;
        }
        if (preg_match(self::RELEASE, $s) && preg_match_all('#(?:~|/home/USER)(?:/[^\s"\'<>]+)#', $s, $paths)) {
            foreach ($paths[0] as $p) { // a release name in a path: keep only the first folder
                $s = str_replace($p, preg_replace('#^((?:~|/home/USER)/[^/]+).*$#', '$1/NAME', $p), $s);
            }
            $counts['release name in a path'] = (isset($counts['release name in a path']) ? $counts['release name in a path'] : 0) + count($paths[0]);
        }
        return $s;
    }

    /**
     * Search words for GitHub. The text goes through Redact::secrets and scrub() first; then every word that could
     * identify the user, their files or their trackers is dropped (review 2026-10-20): paths, URLs, placeholders,
     * redacted secrets, numbers of 4+ digits, tokens of 16+ characters with a digit and long hex (torrent info hashes),
     * release and media names, host:port, host names (a dotted word with letters, except a file name such as php.ini),
     * search qualifiers (repo:, user:, which could widen the search; Class::method is kept) and AND/OR/NOT. A phrase in
     * closed quotes stays a phrase of its kept words; an unclosed quote is an ordinary word. At most 256 characters.
     */
    public static function query($terms, array $ctx)
    {
        list($s) = self::scrub(Redact::secrets(str_replace(["\r", "\n"], ' ', (string) $terms)), $ctx);
        preg_match_all('/"[^"]*"|\S+/', $s, $m);
        $keep = [];
        foreach ($m[0] as $t) {
            $phrase = strlen($t) >= 2 && $t[0] === '"' && substr($t, -1) === '"';
            $words = array_values(array_filter(array_map(function ($w) {
                return trim(str_replace('"', '', $w), ",;:()[]{}<>'`");
            }, preg_split('/\s+/', $phrase ? substr($t, 1, -1) : $t)), function ($w) {
                return $w !== '' && self::wordKept($w);
            }));
            if ($words) {
                $keep[] = $phrase && count($words) > 1 ? '"' . implode(' ', $words) . '"' : implode(' ', $words);
            }
        }
        $q = '';
        foreach ($keep as $w) {
            if (strlen($q) + strlen($w) + 1 > 256) {
                break;
            }
            $q = ltrim("$q $w");
        }
        return $q;
    }

    /** Whether one search word may go to GitHub (see query()). */
    private static function wordKept($w)
    {
        $file = '/^[A-Za-z0-9_-]+\.(?:php|ini|c|h|cc|cpp|js|ts|py|rb|go|rs|java|conf|cfg|rc|log|json|ya?ml|xml|toml|sh|so|service|socket|timer|sock|pid|lock|md|txt|html?|css|sql|db)$/i';
        return !(preg_match('#[/~@\\\\]|REDACTED|\b(?:USER|HOST|IP|UID|PORT|EMAIL|URL|FILE|NAME|HASH)\b|^\W*\d{4,}\W*$|^-?[A-Za-z_-]+:(?!:)\S|:\d{2,5}$#', $w)
            || in_array($w, ['AND', 'OR', 'NOT'], true)
            || (preg_match('/[A-Za-z0-9_-]{16,}/', $w) && preg_match('/\d/', $w)) || preg_match('/[0-9a-f]{32,}/i', $w)
            || preg_match(self::RELEASE, $w) || preg_match('/\.(?:' . self::MEDIA . ')$/i', $w)
            || (preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)+$/', $w) && preg_match('/[A-Za-z]/', $w) && !preg_match($file, $w)));
    }
}
