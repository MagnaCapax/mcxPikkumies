<?php
/**
 * UpstreamDraft: an issue for an upstream project, drafted locally and never sent (ADR-0092). The title and the body file
 * go through UpstreamScrub (who and where the user is) and Redact::secrets (passwords, keys, tokens); the result is
 * written to local/upstream/drafts/<id>.md (0600) and printed in full with what was replaced, a prefilled
 * github.com/<repo>/issues/new link of at most 6000 encoded characters (longer ones failed, measured 2026-10-20), and
 * the exact `gh issue create --body-file` command for a user already logged in to gh. Publishing is the user's own act.
 */
class UpstreamDraft
{
    const URL_MAX = 6000;
    const BODY_MAX = 8000;
    const TITLE_MAX = 256;

    public static function make($repo, $title, $bodyFile, array $ctx)
    {
        $title = trim(str_replace(["\r", "\n"], ' ', (string) $title));
        if ($title === '' || strlen($title) > self::TITLE_MAX) {
            throw new CliError('the title must be 1 to ' . self::TITLE_MAX . ' characters', 'one line: what fails, in the project\'s own words');
        }
        if (!is_string($bodyFile) || $bodyFile === '' || !is_file($bodyFile) || !is_readable($bodyFile) || filesize($bodyFile) > 65536) {
            throw new CliError('--body-file must name a readable file of at most 64 KiB', 'write the report to a file first, then give its path');
        }
        list($t, $tHits) = UpstreamScrub::scrub($title, $ctx);
        list($b, $bHits) = UpstreamScrub::scrub(str_replace("\r\n", "\n", (string) file_get_contents($bodyFile)), $ctx);
        $t2 = Redact::secrets($t);
        $b2 = rtrim(Redact::secrets($b)) . "\n";
        $replaced = $tHits;
        foreach ($bHits as $what => $n) {
            $replaced[$what] = (isset($replaced[$what]) ? $replaced[$what] : 0) + $n;
        }
        $secrets = substr_count($t2 . $b2, 'REDACTED') - substr_count($t . $b, 'REDACTED');
        if ($secrets > 0) {
            $replaced['secrets (passwords, keys, tokens)'] = $secrets; // a key ending in "token" would be dropped as a secret field by Cli
        }
        if (strlen($b2) > self::BODY_MAX) {
            throw new CliError('the report is ' . strlen($b2) . ' bytes after scrubbing; the most a draft holds is ' . self::BODY_MAX,
                'keep what fails, the steps, the versions and a minimal log excerpt');
        }
        $file = Upstream::localDir() . '/upstream/drafts/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.md';
        if (!UpstreamCache::writeFile($file, $b2)) {
            throw new CliError('could not write the draft', 'check that ' . Config::shown(dirname($file)) . ' is writable');
        }
        list($url, $complete) = self::url($repo, $t2, $b2);
        return ['records' => [
            ['draft' => $file, 'repo' => $repo, 'title' => $t2, 'text' => $b2, 'replaced' => $replaced,
                'public' => 'a public, permanent issue under the user\'s own GitHub name; show the user this whole text and the replaced list first'],
            ['open' => $url, 'complete' => $complete, 'how' => $complete
                ? 'default: the user opens this link in their own logged-in browser, reviews it and submits it there'
                : 'the link holds the start of the text only: the user pastes the rest from the draft file before submitting'],
            ['gh' => 'gh issue create -R ' . $repo . ' --title ' . escapeshellarg($t2) . ' --body-file ' . escapeshellarg($file),
                'how' => 'only when `gh auth status` shows the user is already logged in, and only after the user\'s own yes; never gh api, curl, --web or --editor'],
        ], 'status' => ['sent' => false]];
    }

    /** [prefilled new-issue URL, whether the whole body fits]: the body is cut, whole lines first, until the URL fits. */
    public static function url($repo, $title, $body)
    {
        $base = Upstream::WEB . "/$repo/issues/new?title=" . rawurlencode($title) . '&body=';
        if (strlen($base . rawurlencode($body)) <= self::URL_MAX) {
            return [$base . rawurlencode($body), true];
        }
        $room = self::URL_MAX - strlen($base);
        $lines = explode("\n", $body);
        while (count($lines) > 1 && strlen(rawurlencode(implode("\n", $lines))) > $room) {
            array_pop($lines);
        }
        $text = implode("\n", $lines);
        if (strlen(rawurlencode($text)) > $room) { // one long line left: the most whole characters that fit
            $lo = 0;
            $hi = strlen($text);
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi + 1, 2);
                preg_match('/^.{0,' . $mid . '}/su', $text, $m);
                if (strlen(rawurlencode($m[0])) <= $room) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }
            preg_match('/^.{0,' . $lo . '}/su', $text, $m);
            $text = $m[0];
        }
        return [$base . rawurlencode($text), false];
    }
}
