<?php
/**
 * Lessons: the save/append side of the memory unit (ADR-0018). Lessons live in
 * pikkumies/memory/lessons/<topic>/YYYYMMDD-slug.md with frontmatter date, topic and sources.
 * A new lesson must link to at least one related memory file (or say why none is related), so the
 * lessons form a web that search can follow. Memory files are only ever appended to; the links in the new text are
 * written relative to the file, so they work in any markdown viewer (ADR-0060).
 * With privacy.perfLogging on, each save is also counted in the memory metrics (ADR-0041).
 * --tool links a memory to the files it is about (frontmatter `tools`, real paths); the memory-check hook shows it when a
 * tool call uses one of them (ADR-0081).
 * --service links a memory to the services-list nicks it is about (frontmatter `service`), and --tags (or, with
 * --service and no --tags, the named services' own current tags) record its `categories` (ADR-0104); `services
 * show <nick>` reads both back (ServicesList::memories).
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';
require_once dirname(__DIR__, 3) . '/lib/Services.php';
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/SessionCounters.php';

class Lessons
{
    const EXCERPT = 1500; // bytes of the best paragraph returned with a search hit
    const SOP_HEADINGS = ['When to use', 'Steps', 'Checks', 'What did not work']; // a --kind sop body needs each (ADR-0106)
    // The bar for offering to share an SOP with Pulsed Media (ADR-0106, 2026-10-22). Operator, 2026-10-22: "it needs to be
    // genuinely difficult, like 3+ outside sources minimum and 100+ turns, multiple failures, etc."
    const SHARE_OUTSIDE = 3;   // distinct web addresses among the SOP's sources and text
    const SHARE_REQUESTS = 100; // model requests of this run (SessionCounters reqs, delegated agents included)
    const SHARE_FAILED = 2;    // list items under "## What did not work"

    public static function root()
    {
        return DocSources::root() . '/memory';
    }

    /** The lesson text: --body-file, or stdin when it is not a terminal. */
    public static function body(array $opt)
    {
        if (isset($opt['body-file']) && is_string($opt['body-file'])) {
            $body = (string) @file_get_contents($opt['body-file']);
        } else {
            $body = (function_exists('posix_isatty') && posix_isatty(STDIN)) ? '' : (string) stream_get_contents(STDIN);
        }
        if (trim($body) === '') {
            throw new CliError('the lesson text is empty', 'pass --body-file <file>, or pipe the text on stdin');
        }
        return rtrim($body) . "\n";
    }

    public static function save(array $opt, $body)
    {
        $topic = isset($opt['topic']) ? (string) $opt['topic'] : '';
        $title = isset($opt['title']) ? trim((string) $opt['title']) : '';
        $sources = isset($opt['sources']) ? array_values(array_filter(array_map('trim', explode(';', (string) $opt['sources'])), 'strlen')) : [];
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $topic) || $title === '' || !$sources) {
            throw new CliError('save needs --topic, --title and --sources', 'e.g. --topic rtorrent --title "rtorrent stops on a full disk" --sources "df -h output; rtorrent log"');
        }
        // ADR-0018 hierarchy: lessons/<topic>/ for solved problems, reference/ for facts, research/ for longer findings.
        // An SOP (ADR-0106) is the full process of a hard task, kept as its own file beside the task's lessons.
        $kind = isset($opt['kind']) && is_string($opt['kind']) ? $opt['kind'] : 'lesson';
        $searchTitle = $title; // related memories are found by the words of the title, never by an added "SOP: "
        if (!in_array($kind, ['lesson', 'reference', 'research', 'sop'], true)) {
            throw new CliError("unknown --kind $kind", 'kinds: lesson (default), reference, research, sop');
        }
        if ($kind === 'sop') {
            $missing = [];
            foreach (self::SOP_HEADINGS as $h) {
                if (!preg_match('/^##[ \t]+' . preg_quote($h, '/') . '[ \t]*$/mi', $body)) {
                    $missing[] = "## $h";
                }
            }
            if ($missing) {
                throw new CliError('an SOP needs the headings: ' . implode(', ', $missing),
                    'write it so the next agent can follow it for the same kind of request: ## When to use (the requests it answers, in the user\'s words), ## Steps (numbered, each with the command), ## Checks (how each step is known to have worked), ## What did not work');
            }
            if (stripos($title, 'SOP: ') !== 0) {
                $title = "SOP: $title";
            }
        }
        $dir = self::root() . ($kind === 'lesson' || $kind === 'sop' ? "/lessons/$topic" : "/$kind");
        $tools = self::toolLinks(isset($opt['tool']) ? (string) $opt['tool'] : '');
        foreach (['service', 'tags'] as $k) {
            if (isset($opt[$k]) && !is_string($opt[$k])) { // a bare --service/--tags (no value) parses to true; --$k=1 must never silently link nick/tag "1"
                throw new CliError("--$k needs a value", 'e.g. --' . $k . ($k === 'service' ? ' pm1,pm2' : ' seedbox,backup'));
            }
        }
        list($services, $categories) = self::serviceLinks(isset($opt['service']) ? (string) $opt['service'] : '', isset($opt['tags']) ? (string) $opt['tags'] : '');
        $related = [];
        list($hits) = DocSources::search($searchTitle, 5, true, 0.5); // free text: half of the title's words
        foreach ($hits as $h) {
            if ($h[3] === 'lessons' || ($kind === 'research' && $h[3] === 'research')) { // research notes are labelled research (DocSources)
                $related[] = $h[0];
            }
        }
        // Links are written relative to the new file's directory under the real root; that directory may not exist yet.
        $real = realpath(self::root());
        list($body, $links) = self::relink($body, ($real !== false ? $real . substr($dir, strlen(self::root())) : $dir) . '/new.md');
        $why = isset($opt['no-related']) && is_string($opt['no-related']) ? trim($opt['no-related']) : '';
        if (!$links && $why === '') {
            throw new CliError('a lesson must link to at least one related memory file',
                'link them in the text, e.g. [what it is about](<path>)' . ($related ? ', from: ' . implode(', ', $related) : '') . '; or pass --no-related "<why none is related>"');
        }
        $slug = substr(TextSearch::slug($title), 0, 100);
        $path = "$dir/" . gmdate('Ymd') . "-$slug.md";
        if (file_exists($path)) {
            throw new CliError("a lesson with this title already exists today: $path", 'add to it with: append --path ' . $path);
        }
        $duplicate = null;
        foreach ($related as $p) {
            if (substr(PathName::base($p, '.md'), 9) === $slug) {
                $duplicate = $p;
            }
        }
        $text = "---\ndate: " . gmdate('Y-m-d') . "\ntopic: $topic" . ($kind === 'sop' ? "\nkind: sop" : '') . "\nsources: " . json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ($tools ? "\ntools: " . json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '')
            . ($services ? "\nservice: " . json_encode($services, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '')
            . ($categories ? "\ncategories: " . json_encode($categories, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '')
            . "\n---\n# $title\n\n" . $body;
        if ((!@mkdir($dir, 0700, true) && !is_dir($dir)) || @file_put_contents($path, $text) !== strlen($text)) {
            throw new CliError("could not write $path", 'is the disk full?');
        }
        @chmod(self::root(), 0700); // git leaves the tracked memory/ directory 0755; lessons are private
        DocSources::appendMetric('saves', ['ts' => time()]); // the time only: stats pairs it with the searches before it
        $status = ['verified' => is_file($path) && filesize($path) === strlen($text)];
        if ($duplicate !== null) {
            $status['warning'] = "a lesson with the same title exists: $duplicate (append to it next time)";
        }
        if ($why !== '') {
            $status['no_related'] = $why;
        }
        if ($kind === 'sop') {
            $status['share'] = self::share($sources, $body, self::runRequests());
        }
        return ['records' => [['path' => $path, 'links' => $links, 'related' => $related] + ($tools ? ['tools' => $tools] : [])
            + ($services ? ['service' => $services] : []) + ($categories ? ['categories' => $categories] : [])], 'status' => $status];
    }

    /**
     * One line for an SOP's save result: whether the task met the bar for offering to share it with Pulsed Media
     * (SHARE_*). Outside sources are the distinct web addresses in the sources and the text (a fragment and trailing
     * punctuation dropped); failed approaches are the list items under "## What did not work"; $requests is this run's
     * model request count, or null when it is not counted here. "met" needs all three; "unknown" when only the request
     * count is missing and the other two hold; else "not met". Every count is the agent's own text except the requests:
     * the line prompts a suggestion the user decides on, it grants nothing (ADR-0106).
     */
    public static function share(array $sources, $body, $requests)
    {
        preg_match_all('#https?://[^\s<>"\'`]+#i', implode("\n", $sources) . "\n" . $body, $m);
        $urls = [];
        foreach ($m[0] as $u) {
            $u = rtrim(preg_replace('/#.*$/s', '', $u), '.,;:!?)]}*/');
            $urls[strtolower($u)] = true;
        }
        $failed = 0;
        if (preg_match('/^##[ \t]+What did not work[ \t]*$(.*?)(?=^##[ \t]|\z)/msi', $body, $s)) {
            $failed = preg_match_all('/^[ \t]*(?:[-*+]|\d+[.)])[ \t]+\S/m', $s[1]);
        }
        $outside = count($urls);
        $rest = $outside >= self::SHARE_OUTSIDE && $failed >= self::SHARE_FAILED;
        $verdict = !$rest ? 'not met' : ($requests === null ? 'unknown' : ($requests >= self::SHARE_REQUESTS ? 'met' : 'not met'));
        return "$verdict (outside sources $outside of " . self::SHARE_OUTSIDE . ', model requests '
            . ($requests === null ? 'not counted here' : $requests) . ' of ' . self::SHARE_REQUESTS . ", failed approaches $failed of " . self::SHARE_FAILED . ')';
    }

    /** This run's model requests (PIKKUMIES_RUN's run file, ADR-0096), or null without a run or a readable count. */
    private static function runRequests()
    {
        $run = (string) getenv('PIKKUMIES_RUN');
        if (SessionCounters::runFile('', $run) === null) {
            return null;
        }
        $c = SessionCounters::readRun(Config::localDir(), $run);
        return $c !== null && isset($c['reqs']) && is_int($c['reqs']) ? $c['reqs'] : null;
    }

    /**
     * [service nicks, categories] from --service (';' or ',' separated nicks, format-checked only: a nick need not
     * exist in the services list, ADR-0104) and --tags (','-separated, format-checked against the same rule a
     * service's own tag uses). Categories are the union of the given tags and the current tags of every named
     * service that still exists in Services::all() — a missing or unreadable services file leaves that union empty,
     * so --tags alone still works.
     */
    public static function serviceLinks($serviceArg, $tagsArg)
    {
        $services = [];
        foreach (array_filter(array_map('trim', preg_split('/[;,]/', (string) $serviceArg)), 'strlen') as $n) {
            $services[Services::nick($n)] = true; // format only; existence is not required
        }
        // array_map('strval', ...): an all-digit nick/tag (e.g. "42") is a valid array key, but PHP stores it as the
        // integer 42, not the string "42" — json_encode would then write service: [42], and ServicesList's strict
        // in_array('42', [42], true) would never match it back.
        $services = array_map('strval', array_keys($services));
        $categories = [];
        if ($services) {
            $all = Services::all();
            foreach ($services as $n) {
                foreach (isset($all[$n]['tags']) ? (array) $all[$n]['tags'] : [] as $t) {
                    if (is_string($t) && preg_match(Services::NICK_RE, $t)) { // a hand-edited services.jsonl may hold a non-string or malformed tag
                        $categories[$t] = true;
                    }
                }
            }
        }
        foreach (array_filter(array_map('trim', explode(',', strtolower((string) $tagsArg))), 'strlen') as $t) {
            if (!preg_match(Services::NICK_RE, $t)) {
                throw new CliError('not a tag: ' . json_encode($t), 'lowercase words such as seedbox, backup, webhosting, dedicated, workstation, nas, laptop, media, other');
            }
            $categories[$t] = true;
        }
        return [$services, array_map('strval', array_keys($categories))];
    }

    /** The real paths of the files --tool names (';'-separated); each must be an existing file. ADR-0081. */
    public static function toolLinks($arg)
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $arg)), 'strlen') as $p) {
            $real = realpath($p);
            if ($real === false || !is_file($real)) {
                throw new CliError("--tool names no file: $p", 'give the path of an existing file, such as a tool unit\'s script or a config file; several separated by ;');
            }
            $out[] = $real;
        }
        return array_values(array_unique($out));
    }

    public static function append($path, $body)
    {
        $real = realpath($path);
        $root = realpath(self::root());
        // README.md is tracked by git: changing it would stop every later `pikkumies update`.
        if ($real === false || $root === false || strpos($real, $root . '/') !== 0 || substr($real, -3) !== '.md' || !is_file($real) || PathName::base($real) === 'README.md') {
            throw new CliError('append works only on an existing memory file', 'pass --path <a path printed by search or save>');
        }
        $before = filesize($real);
        list($body) = self::relink($body, $real);
        $add = "\n## Update " . gmdate('Y-m-d') . "\n\n" . $body;
        if (@file_put_contents($real, $add, FILE_APPEND | LOCK_EX) !== strlen($add)) {
            throw new CliError("could not append to $real", 'is the disk full?');
        }
        clearstatcache(true, $real);
        return ['records' => [['path' => $real]], 'status' => ['verified' => filesize($real) === $before + strlen($add)]];
    }

    /**
     * [$body with each link to a memory file written relative to $fromFile, the real paths it links to]. A link
     * counts when it names an existing .md file in memory other than README.md: absolute, relative to $fromFile,
     * relative to the memory root, or as written (relative to the current directory), the forms save has
     * always accepted (ADR-0060). Other links, and links in code or front matter, stay as they are.
     */
    public static function relink($body, $fromFile)
    {
        $root = realpath(self::root());
        $accept = function ($real) use ($root) {
            return $root !== false && strpos($real, $root . '/') === 0 && PathName::base($real) !== 'README.md';
        };
        $links = [];
        // The body follows the frontmatter and title in the file, so a --- block at its start is text, not front
        // matter: a leading newline keeps Links from skipping it, and is taken off again below.
        $body = Links::rewrite("\n" . $body, function ($target) use ($fromFile, $accept, &$links) {
            $real = substr($target, -3) === '.md' ? Links::resolve($target, $fromFile, [self::root(), ''], $accept) : false;
            if ($real === false) {
                return null;
            }
            $links[] = $real;
            return Links::relative($fromFile, $real);
        });
        return [(string) substr($body, 1), array_values(array_unique(array_reverse($links)))]; // rewrite walks the text from the end
    }

    /** The paragraph of $path that contains the most search words, at most EXCERPT bytes (TextSearch::excerpt). */
    public static function excerpt($path, $query)
    {
        return TextSearch::excerpt($path, $query, self::EXCERPT);
    }
}
