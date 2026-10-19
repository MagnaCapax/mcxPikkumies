<?php
/**
 * Corpus: the documentation on disk before the first question (ADR-0039, amended 2026-10-19). Every host family's
 * sources are downloaded once per install (reused with --install: the marker docs-refreshed in the sandbox root), so a
 * question sees the same corpus whatever was asked before it, and other providers' copies are on disk for tier 3. Run 3
 * refreshed each family lazily in question order: a PMSS question saw other providers' pages only when an earlier
 * family had been asked first. Then `sources` is saved to sources.jsonl and each source with no page is printed EMPTY.
 */
class Corpus
{
    const MARKER = 'docs-refreshed';

    /** Families downloaded before the first question: every Host::TABLE family, sorted, then generic. */
    public static function families(array $hostTable)
    {
        $f = array_keys($hostTable);
        sort($f);
        $f[] = 'generic';
        return $f;
    }

    /** From `sources` records: the downloadable sources that are on and have no page on disk, by name. */
    public static function emptySources(array $records)
    {
        $out = [];
        foreach ($records as $r) {
            $on = !isset($r['status']) || $r['status'] === 'on';
            if (isset($r['name'], $r['type']) && $r['type'] !== 'link' && $on && (int) (isset($r['pages']) ? $r['pages'] : 0) === 0) {
                $out[] = $r['name'];
            }
        }
        return $out;
    }

    /** Download every family once per install (network only, no tokens); throws when the real crontab changed meanwhile. */
    public static function refresh(Sandbox $box, array $target, CrontabGuard $guard, $out)
    {
        if (is_file($box->root . '/' . self::MARKER)) {
            return;
        }
        foreach (self::families(Host::TABLE) as $family) {
            list($code) = Asker::refresh($box, Targets::overlay($box, Targets::settings($target, $family)));
            echo $code === 0 ? '' : "documentation refresh for family '$family' exited $code (see {$box->root}/refresh.out); asking anyway\n";
            if ($guard->check("refresh-$family")) {
                throw new Exception("the real crontab changed during the documentation refresh; see $out/crontab-after-refresh-$family.txt");
            }
        }
        touch($box->root . '/' . self::MARKER);
    }

    /** Save `sources` (pages per source) to $out/sources.jsonl and print each source with no page; returns their names. */
    public static function record(Sandbox $box, $out)
    {
        Asker::run($box, [PHP_BINARY, $box->root . '/checkout/pikkumies/tools/memory/pikkumies/pikkumies.php', 'sources', '--limit', '0',
            '--fields', 'name,type,status,scope,in_scope,pages,last_error'], '', "$out/sources.jsonl", $box->root . '/sources.err', 60);
        $records = array_map(function ($l) {
            return (array) json_decode($l, true);
        }, (array) @file("$out/sources.jsonl"));
        $empty = self::emptySources($records);
        foreach ($empty as $name) {
            echo "EMPTY $name: no page on disk after the refresh\n";
        }
        return $empty;
    }
}
