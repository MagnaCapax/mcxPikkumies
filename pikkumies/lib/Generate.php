<?php
/**
 * Generate: write the Hermes skills and tools/INDEX.md from the tool units (ADR-0015), and
 * hooks/INDEX.md from the hook units (ADR-0030).
 *
 * Runs on install, update and every `pikkumies run`. Under a lock, each file is written only when
 * its content changed, through a temporary file and a rename, so a Hermes session that is running
 * never reads half a file. Skills of removed or disabled units are deleted; nothing outside the
 * generated directory is touched. A unit with problems is skipped and reported; the rest still load.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Units.php';
require_once __DIR__ . '/HookUnits.php';

class Generate
{
    /** Categories the user switched off: tools.disabled, plus server-admin when root.disabled. */
    public static function disabledCategories(array $config)
    {
        $off = isset($config['tools']['disabled']) ? (array) $config['tools']['disabled'] : [];
        if (!empty($config['root']['disabled'])) {
            $off[] = 'server-admin';
        }
        return array_values(array_unique($off));
    }

    /** Returns ['skills' => n, 'written' => n, 'removed' => n, 'problems' => [unit => [..]], 'error' => '']. */
    public static function run(array $disabled, $toolsDir = null, $outDir = null, $indexFile = null)
    {
        $toolsDir = $toolsDir ?: Units::toolsDir();
        $outDir = $outDir ?: Units::generatedDir();
        $indexFile = $indexFile ?: $toolsDir . '/INDEX.md';
        $result = ['skills' => 0, 'written' => 0, 'removed' => 0, 'problems' => [], 'error' => ''];
        if (!@mkdir($outDir, 0700, true) && !is_dir($outDir)) {
            $result['error'] = "cannot create $outDir";
            return $result;
        }
        $lock = fopen(dirname($outDir) . '/.generate.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            $result['error'] = 'cannot take the generate lock';
            return $result;
        }
        $want = [];
        $listed = [];
        foreach (Units::all($toolsDir) as $u) {
            if (in_array($u['category'], $disabled, true)) {
                continue;
            }
            if ($u['errors']) {
                $result['problems']["{$u['category']}/{$u['name']}"] = $u['errors'];
                continue;
            }
            $want["{$u['category']}/{$u['name']}/SKILL.md"] = Units::renderSkill($u);
            $listed[] = $u;
        }
        foreach ($want as $rel => $content) {
            $w = self::writeIfChanged("$outDir/$rel", $content);
            if ($w === null) {
                $result['error'] = "cannot write $outDir/$rel (disk full?)";
                break;
            }
            $result['written'] += $w ? 1 : 0;
        }
        $result['skills'] = count($want);
        foreach (self::generatedFiles($outDir) as $rel) {
            if (!isset($want[$rel]) && @unlink("$outDir/$rel")) {
                $result['removed']++;
                @rmdir(dirname("$outDir/$rel"));
                @rmdir(dirname(dirname("$outDir/$rel")));
            }
        }
        if ($result['error'] === '' && self::writeIfChanged($indexFile, Units::renderIndex($listed)) === null) {
            $result['error'] = "cannot write $indexFile";
        }
        // hooks/ beside tools/; a tools dir without one (a test tree) gets no hook index.
        $hooksDir = dirname($toolsDir) . '/hooks';
        if ($result['error'] === '' && is_dir($hooksDir)) {
            $hooks = [];
            foreach (HookUnits::all($hooksDir) as $h) {
                if ($h['errors']) {
                    $result['problems']["hooks/{$h['category']}/{$h['name']}"] = $h['errors'];
                } else {
                    $hooks[] = $h;
                }
            }
            if (self::writeIfChanged("$hooksDir/INDEX.md", HookUnits::renderIndex($hooks)) === null) {
                $result['error'] = "cannot write $hooksDir/INDEX.md";
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        return $result;
    }

    /** true = written, false = already up to date, null = failed (the old file stays). */
    public static function writeIfChanged($path, $content)
    {
        if (is_file($path) && file_get_contents($path) === $content) {
            return false;
        }
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true)) {
            return null;
        }
        $tmp = dirname($path) . '/.tmp-' . basename($path) . '-' . getmypid();
        if (@file_put_contents($tmp, $content) !== strlen($content) || !@rename($tmp, $path)) {
            @unlink($tmp);
            return null;
        }
        return true;
    }

    /** SKILL.md files (and stale temporaries) below the generated dir, as relative paths. */
    private static function generatedFiles($outDir)
    {
        $out = [];
        foreach (array_merge((array) glob("$outDir/*/*/SKILL.md"), (array) glob("$outDir/*/*/.tmp-*")) as $f) {
            $out[] = substr($f, strlen($outDir) + 1);
        }
        return $out;
    }
}
