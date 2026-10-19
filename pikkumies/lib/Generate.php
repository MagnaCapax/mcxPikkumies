<?php
/**
 * Generate: write the Hermes skills and tools/INDEX.md from the tool units (ADR-0015), and
 * hooks/INDEX.md from the hook units (ADR-0030); reports the user's local hooks and notes what changed (ADR-0054); adds the
 * user's local notes for a tool to its skill (ADR-0076).
 *
 * Runs on install, update and every `pikkumies run`. Under a lock, each file is written only when
 * its content changed, through a temporary file and a rename, so a Hermes session that is running
 * never reads half a file. Skills of removed or disabled units are deleted, and so is the skill of a unit the user
 * hid (skills.disabled: the tool stays in the index, Hermes just has no skill by that name, ADR-0007, ADR-0062);
 * nothing outside the generated directory is touched. A unit with problems is skipped and reported; the rest still load.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Units.php';
require_once __DIR__ . '/HookUnits.php';
require_once __DIR__ . '/LocalHooks.php';
require_once __DIR__ . '/LocalNotes.php';
require_once __DIR__ . '/NestedAgent.php';
require_once __DIR__ . '/Host.php';

class Generate
{
    /** Test seam: a docker CLI presence other than this machine's real PATH and ~/bin. */
    public static $dockerPresentOverride = null;

    /**
     * Categories left out: what the user switched off (tools.disabled, plus server-admin when root.disabled) and
     * what this machine cannot use (unavailableCategories). The one list for skills, the tool index and the SOUL.md
     * tool doctrine. Each entry is a category path and also covers the categories below it (Units::isDisabled, ADR-0055).
     */
    public static function disabledCategories(array $config)
    {
        $off = isset($config['tools']['disabled']) ? (array) $config['tools']['disabled'] : [];
        if (!empty($config['root']['disabled'])) {
            $off[] = 'server-admin';
        }
        return array_values(array_unique(array_merge($off, self::unavailableCategories())));
    }

    /**
     * Categories whose program is missing here, recomputed on every run, never written to the config:
     * containers when no `docker` CLI is on PATH or in ~/bin (ADR-0034). Only the CLI decides, never the
     * daemon's health: a daemon that is down is when the containers tools are needed.
     */
    public static function unavailableCategories()
    {
        $docker = self::$dockerPresentOverride !== null ? (bool) self::$dockerPresentOverride : Host::markerPresent('docker');
        return $docker ? [] : ['containers'];
    }

    /**
     * Returns ['skills' => n, 'written' => n, 'removed' => n, 'problems' => [unit => [..]], 'error' => '', 'localNotice' => text]:
     * localNotice is what changed in the user's local hooks and notes (ADR-0054, ADR-0076), for the caller to print; '' when nothing did.
     * $hiddenSkills: shipped skill names (skills.disabled) that get no SKILL.md.
     */
    public static function run(array $disabled, $toolsDir = null, $outDir = null, $indexFile = null, array $hiddenSkills = [])
    {
        $toolsDir = $toolsDir ?: Units::toolsDir();
        $outDir = $outDir ?: Units::generatedDir();
        $indexFile = $indexFile ?: $toolsDir . '/INDEX.md';
        $result = ['skills' => 0, 'written' => 0, 'removed' => 0, 'problems' => [], 'error' => '', 'localNotice' => ''];
        if (!@mkdir($outDir, 0700, true) && !is_dir($outDir)) {
            $result['error'] = "cannot create $outDir";
            return $result;
        }
        $lock = @fopen(dirname($outDir) . '/.generate.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            $result['error'] = 'cannot take the generate lock';
            return $result;
        }
        $want = [];
        $listed = [];
        $root = dirname($toolsDir);
        // A start by an agent (PIKKUMIES_DEPTH, ADR-0020) records nothing: nobody it could tell is a user. So it shows only
        // the local notes recorded at the last start by the user; a start by the user records and shows them all.
        $record = NestedAgent::depth() === 0;
        $notes = LocalNotes::scan($root, $toolsDir);
        $unitNotes = LocalNotes::visible($notes, $record ? null : LocalHooks::record($root), $disabled, $toolsDir)['units'];
        foreach (Units::all($toolsDir) as $u) {
            if (Units::isDisabled($u['category'], $disabled)) {
                continue;
            }
            if ($u['errors']) {
                $result['problems']["{$u['category']}/{$u['name']}"] = $u['errors'];
                continue;
            }
            if (!in_array($u['name'], $hiddenSkills, true)) {
                $path = ltrim("{$u['category']}/{$u['name']}", '/');
                $want["{$u['category']}/{$u['name']}/SKILL.md"] = Units::renderSkill($u, isset($unitNotes[$path]) ? $unitNotes[$path] : '');
            }
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
                for ($d = dirname("$outDir/$rel"); $d !== $outDir && strpos($d, "$outDir/") === 0 && @rmdir($d); $d = dirname($d)) {
                    // empty directories go, up to the generated dir itself
                }
            }
        }
        if ($result['error'] === '' && self::writeIfChanged($indexFile, Units::renderIndex($listed)) === null) {
            $result['error'] = "cannot write $indexFile";
        }
        // hooks/ beside tools/; a tools dir without one (a test tree) gets no hook index.
        $hooksDir = $root . '/hooks';
        if ($result['error'] === '' && (is_dir($hooksDir) || $notes !== ['notes' => [], 'problems' => [], 'off' => ''])) {
            $result = self::local($root, $hooksDir, $notes, $record, $result);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        return $result;
    }

    /** hooks/INDEX.md, then the user's local hooks and notes: what is skipped, and the one local-changes notice (ADR-0054, ADR-0076). */
    private static function local($root, $hooksDir, array $notes, $record, array $result)
    {
        if (is_dir($hooksDir)) {
            $hooks = [];
            foreach (HookUnits::all($hooksDir) as $h) {
                if ($h['errors']) {
                    $result['problems']["hooks/{$h['category']}/{$h['name']}"] = $h['errors'];
                } else {
                    $hooks[] = $h;
                }
            }
            if (self::writeIfChanged("$hooksDir/INDEX.md", HookUnits::renderIndex($hooks, Config::load())) === null) { // the intervals as set (ADR-0067)
                $result['error'] = "cannot write $hooksDir/INDEX.md";
            }
        }
        // The user's local hooks (ADR-0054) and notes (ADR-0076): skipped ones are reported here, and what changed since
        // the last start becomes the one local-changes notice in SOUL.md.
        $local = LocalHooks::scan($root);
        foreach (array_merge($local['problems'], $notes['problems']) as $id => $why) {
            $result['problems'][$id] = [$why];
        }
        $result['localNotice'] = LocalHooks::noteChanges($root, $local, $record, $notes);
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

    /** SKILL.md files (and stale temporaries) at any depth below the generated dir, as relative paths. */
    private static function generatedFiles($outDir, $rel = '')
    {
        $out = [];
        $entries = @scandir($rel === '' ? $outDir : "$outDir/$rel");
        if ($entries === false) {
            // Not there yet, or unreadable: nothing to list here (a write into it reports its own error).
            return [];
        }
        foreach ($entries as $entry) {
            $r = $rel === '' ? $entry : "$rel/$entry";
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir("$outDir/$r")) {
                if ($entry[0] !== '.' && !is_link("$outDir/$r") && substr_count($r, '/') < Tree::MAX_DEPTH) {
                    $out = array_merge($out, self::generatedFiles($outDir, $r));
                }
            } elseif ($entry === 'SKILL.md' || strpos($entry, '.tmp-') === 0) {
                $out[] = $r;
            }
        }
        return $out;
    }
}
