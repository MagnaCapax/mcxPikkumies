<?php
/**
 * MemoryLinks: the memory unit's `links` verb and the broken-link count on the `pikkumies doctor` memory line
 * (ADR-0060). The link graph is read from the files each time it is asked for: no index file, no cron.
 * It covers the memory files and Pikkumies' own tracked markdown (git ls-files), or the memory alone where git
 * cannot list them. A memory link may also be relative to the memory root (save has always accepted that);
 * a link in tracked markdown only relative to its own file, as GitHub and other viewers read it.
 */
require_once __DIR__ . '/MemoryMetrics.php';

class MemoryLinks
{
    const REPAIR = 'memory files are only appended to: repair a broken link in one with append, giving the corrected link';

    /** Real path => link bases: the memory files with the memory root, then the tracked markdown with none. */
    public static function corpus($withTracked = true)
    {
        $files = [];
        foreach (MemoryMetrics::memoryFiles() as $f) {
            $files[(string) realpath($f)] = [DocSources::root() . '/memory'];
        }
        $repo = dirname(DocSources::root());
        $out = [];
        $code = 1;
        if ($withTracked) {
            // As Update::git: a hook or fsmonitor written into .git/config must not run.
            exec('git -c core.fsmonitor=false -c core.hooksPath=/dev/null -C ' . escapeshellarg($repo) . " ls-files -z -- '*.md' 2>/dev/null", $out, $code);
        }
        foreach ($code === 0 ? explode("\0", implode("\n", $out)) : [] as $rel) {
            $real = $rel !== '' ? realpath("$repo/$rel") : false;
            if ($real !== false && !isset($files[$real])) {
                $files[$real] = [];
            }
        }
        return $files;
    }

    /** One record: what $path links to, what links to it, and its links that point at nothing. */
    public static function links($path)
    {
        $files = self::corpus();
        $real = realpath((string) $path);
        if ($real === false || !isset($files[$real])) {
            throw new CliError('links works on a markdown file in memory or in Pikkumies\' own documentation',
                'pass a path printed by search or save, or --broken for every broken link');
        }
        list($out, $in, $broken) = Links::scan($files);
        $list = function (array $map) use ($real) {
            return isset($map[$real]) ? array_values(array_unique($map[$real])) : [];
        };
        return ['records' => [['path' => $real, 'links_to' => $list($out), 'linked_from' => $list($in), 'broken' => $list($broken)]],
            'status' => ['files_scanned' => count($files)]];
    }

    /** One record per link that points at nothing: the file and the target as written. */
    public static function broken()
    {
        $files = self::corpus();
        list(, , $broken) = Links::scan($files);
        $records = [];
        foreach ($broken as $path => $targets) {
            foreach ($targets as $target) {
                $records[] = ['path' => $path, 'target' => $target];
            }
        }
        return ['records' => $records, 'status' => ['files_scanned' => count($files), 'broken' => count($records), 'note' => self::REPAIR]];
    }

    /** Broken links in the memory files alone, for the doctor line. */
    public static function brokenCount()
    {
        list(, , $broken) = Links::scan(self::corpus(false));
        return array_sum(array_map('count', $broken));
    }
}
