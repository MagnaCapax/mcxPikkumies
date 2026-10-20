<?php
/**
 * TranscribeInstall: the `install-local` verb (ADR-0045). Builds whisper.cpp v1.9.5 from its
 * pinned release source tarball and downloads one pinned ggml model, verifying every download's
 * sha256 before it is used; a mismatch deletes the download and stops (nothing is ever used
 * unverified). Downloads a pinned Kitware cmake release only when no usable `cmake` is already on
 * PATH. Idempotent: a step whose result already exists is skipped. Never runs on its own (AGENTS.md
 * "updates fast-forward only"; this is the one verb that builds anything, and only when the user
 * asks for it).
 */
class TranscribeInstall
{
    const BUILD_SECONDS = 7200; // 2 h ceiling on the whisper.cpp build itself

    public static function run(array $args, array $opt, array $pins = null, $localDir = null)
    {
        $model = isset($opt['model']) && $opt['model'] === 'small' ? 'small' : 'base';
        $pins = $pins !== null ? $pins : self::defaultPins();
        self::preflight($model, $pins);
        $root = TranscribeLocal::root($localDir);
        @mkdir($root, 0700, true);
        $steps = [];

        $srcDir = "$root/src";
        if (!is_file("$srcDir/CMakeLists.txt")) {
            self::fetchAndExtract($pins['whisper']['url'], $pins['whisper']['sha256'], "$root/whisper-source", $pins['whisper']['dirname'], $srcDir);
            $steps[] = 'source';
        }

        $cmakeBin = ToolProc::which('cmake');
        if ($cmakeBin === null || !self::cmakeAtLeast3($cmakeBin)) {
            $downloaded = "$root/cmake/{$pins['cmake']['dirname']}/bin/cmake";
            if (!is_file($downloaded)) {
                self::fetchCmake($pins['cmake'], "$root/cmake");
                $steps[] = 'cmake';
            }
            $cmakeBin = $downloaded;
        }

        $cli = TranscribeLocal::binaryPath($localDir);
        $buildDir = "$root/build";
        if (!is_file($cli)) {
            self::build($cmakeBin, $srcDir, $buildDir);
            $steps[] = 'build';
        }

        $modelFile = TranscribeLocal::modelPath($model, $localDir);
        if (!is_file($modelFile)) {
            @mkdir(dirname($modelFile), 0700, true);
            self::fetchFile($pins['models'][$model]['url'], $pins['models'][$model]['sha256'], $modelFile);
            $steps[] = 'model';
        }
        return ['model' => $model, 'installed' => is_file($cli) && is_file($modelFile), 'steps_run' => $steps];
    }

    public static function defaultPins()
    {
        return ['whisper' => ['url' => TranscribePins::WHISPER_URL, 'sha256' => TranscribePins::WHISPER_SHA256,
            'dirname' => TranscribePins::WHISPER_DIRNAME],
            'cmake' => ['url' => TranscribePins::CMAKE_URL, 'sha256' => TranscribePins::CMAKE_SHA256, 'dirname' => TranscribePins::CMAKE_DIRNAME],
            'models' => TranscribePins::MODELS];
    }

    private static function preflight($model, array $pins)
    {
        foreach (['gcc', 'g++', 'make'] as $need) {
            if (ToolProc::which($need) === null) {
                throw new CliError("$need is not installed on this machine; nothing was built", 'the provider installs a C/C++ build toolchain: report it in your answer');
            }
        }
        $home = HomePath::home();
        $free = Quota::fields($home)['quota_free_bytes'];
        $need = TranscribePins::roomNeeded($model);
        if ($free !== null && $free < $need) {
            throw new CliError("not enough free quota to build the local engine: need about $need bytes, $free free; nothing was built",
                'free space first (storage stats), or stay with the cloud endpoint');
        }
        $mem = self::memAvailableBytes();
        $needMem = TranscribePins::memNeeded($model);
        if ($mem !== null && $mem < $needMem) {
            throw new CliError("not enough free memory to build the local engine (" . $model . " needs about $needMem bytes, $mem available);"
                . ' nothing was built', 'try --model=base; more memory is the provider\'s to give: report it in your answer');
        }
    }

    private static function memAvailableBytes()
    {
        $raw = @file_get_contents('/proc/meminfo');
        if (!is_string($raw) || !preg_match('/^MemAvailable:\s*(\d+)\s*kB/m', $raw, $m)) {
            return null;
        }
        return ((int) $m[1]) * 1024;
    }

    private static function cmakeAtLeast3($bin)
    {
        $r = ToolProc::run([$bin, '--version']);
        return $r['code'] === 0 && preg_match('/version\s+(\d+)\./i', $r['out'], $m) === 1 && (int) $m[1] >= 3;
    }

    private static function fetchCmake(array $pin, $destDir)
    {
        $arch = php_uname('m');
        if (!in_array($arch, ['x86_64', 'amd64'], true)) {
            throw new CliError("no cmake is on PATH and this machine's architecture ($arch) is not x86_64; nothing was downloaded",
                'the provider installs cmake 3 or newer: report it in your answer');
        }
        self::fetchAndExtract($pin['url'], $pin['sha256'], $destDir, $pin['dirname'], "$destDir/{$pin['dirname']}");
    }

    /** Builds whisper.cpp with cmake into $buildDir; `-j` is min(4, max(1, nproc/2)). */
    private static function build($cmakeBin, $srcDir, $buildDir)
    {
        $jobs = TranscribeLocal::threads();
        $configure = ToolProc::run(ToolProc::gentle([$cmakeBin, '-S', $srcDir, '-B', $buildDir, '-DCMAKE_BUILD_TYPE=Release'], self::BUILD_SECONDS));
        if ($configure['code'] !== 0) {
            throw new CliError('cmake configure failed' . ($configure['err'] !== '' ? ': ' . ToolText::tail($configure['err']) : ''),
                'check the build toolchain (gcc, g++, make)');
        }
        $build = ToolProc::run(ToolProc::gentle([$cmakeBin, '--build', $buildDir, '-j', (string) $jobs, '--config', 'Release'], self::BUILD_SECONDS));
        if ($build['code'] !== 0 || !is_file("$buildDir/bin/whisper-cli")) {
            throw new CliError('the whisper.cpp build failed' . ($build['err'] !== '' ? ': ' . ToolText::tail($build['err']) : ''),
                'check the build toolchain, and that enough memory and disk were free');
        }
    }

    /**
     * Downloads $url, verifies sha256, extracts (tar), and moves the one top-level directory to $target
     * (cmake extracts straight to its $target, so there is nothing to move). A leftover of an
     * interrupted run is deleted first; every $workDir is a direct child of the whisper root.
     */
    private static function fetchAndExtract($url, $sha256, $workDir, $topDirName, $target)
    {
        @mkdir($workDir, 0700, true);
        $archive = "$workDir/download.tar.gz";
        self::fetchFile($url, $sha256, $archive);
        $extracted = "$workDir/$topDirName";
        if (is_dir($extracted)) {
            self::removeTree($extracted, dirname($workDir));
        }
        $r = ToolProc::run(['tar', '-xzf', $archive, '-C', $workDir]);
        if ($r['code'] !== 0 || !is_dir($extracted)) {
            @unlink($archive);
            throw new CliError("could not extract $url", 'the archive may be corrupt; try install-local again');
        }
        @unlink($archive);
        if ($extracted === $target) {
            return;
        }
        if (is_dir($target)) {
            self::removeTree($target, dirname($workDir));
        }
        @mkdir(dirname($target), 0700, true);
        if (!@rename($extracted, $target)) {
            throw new CliError("could not move the extracted files into place at $target", 'check that pikkumies/local is writable');
        }
    }

    /** Downloads $url to $dest with curl, verifies sha256 BEFORE returning; on a mismatch deletes it and stops. */
    private static function fetchFile($url, $sha256, $dest)
    {
        $curl = ToolProc::need('curl', 'curl is required to download the pinned files');
        $tmp = $dest . '.part';
        $r = ToolProc::run([$curl, '-fsSL', '-o', $tmp, $url]);
        if ($r['code'] !== 0 || !is_file($tmp)) {
            @unlink($tmp);
            throw new CliError("could not download $url", 'check network access and try install-local again');
        }
        $got = (string) @hash_file('sha256', $tmp);
        if (!hash_equals(strtolower($sha256), strtolower($got))) {
            @unlink($tmp);
            throw new CliError("downloaded file does not match its pinned sha256 (got $got, pinned $sha256); deleted, nothing was used",
                'try install-local again; if this repeats, the pin may be stale');
        }
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new CliError("could not store the downloaded file at $dest", 'check that pikkumies/local is writable');
        }
    }

    /**
     * Deletes $path and everything in it, without following links (a link is removed itself). Refuses
     * anything not strictly inside $root (this tool's own build directory under pikkumies/local/), or a
     * path with a `..` segment, so a wrong argument can never reach outside it.
     */
    private static function removeTree($path, $root)
    {
        $root = rtrim($root, '/') . '/';
        if (strpos($path, $root) !== 0 || $path === $root || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw new CliError("refusing to delete $path: it is not inside $root", 'this is a bug; report it');
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (is_dir($path) ? (array) @scandir($path) : [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree("$path/$name", $root);
            }
        }
        @rmdir($path);
    }
}
