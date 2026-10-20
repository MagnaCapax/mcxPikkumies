<?php
/**
 * MoveRoots: where a move may write on this box. roots(): the data roots, first-level folders of the home where the
 * target client keeps data (its torrents' paths and its save_path) and ~/data, never a dot-folder, a symlink or the
 * Pikkumies tree. targetDir(): a folder inside one of them, with no dot part on the way, also after symlinks resolve.
 */
class MoveRoots
{
    /**
     * The data roots here: first-level folders of the home where the target client keeps data (its torrents' paths, its
     * save_path) and ~/data; never a dot-folder or the Pikkumies tree. A folder a move writes into must lie inside one.
     */
    public static function roots(MoveRunner $run, $client, $home)
    {
        $home = rtrim((string) $home, '/');
        $seen = ["$home/data"];
        foreach ([['list', '--fields', 'path'], ['stats']] as $args) {
            try {
                foreach ($run->pages('local', $client, $args)['records'] as $r) {
                    $seen[] = isset($r['path']) ? (string) $r['path'] : (isset($r['save_path']) ? (string) $r['save_path'] : '');
                }
            } catch (CliError $e) {
                continue; // a client that does not answer adds no root
            }
        }
        $roots = [];
        foreach ($seen as $p) {
            $first = strpos($p, "$home/") === 0 ? explode('/', substr($p, strlen($home) + 1))[0] : '';
            if ($first !== '' && $first[0] !== '.' && $first !== 'pikkumies' && is_dir("$home/$first") && !is_link("$home/$first")) {
                $roots["$home/$first"] = true;
            }
        }
        return array_keys($roots);
    }

    /** $dir as an absolute folder inside one of $roots (it need not exist yet), with no dot-folder on the way; refused otherwise. */
    public static function targetDir($dir, $home, array $roots)
    {
        $home = rtrim((string) $home, '/');
        $dir = (string) $dir;
        if (strncmp($dir, '~/', 2) === 0) {
            $dir = $home . substr($dir, 1);
        } elseif ($dir !== '' && $dir[0] !== '/') {
            $dir = $home . '/' . $dir;
        }
        $dir = rtrim($dir, '/');
        $realHome = realpath($home);
        $probe = $dir;
        while ($probe !== '' && !file_exists($probe)) {
            $probe = dirname($probe);
        }
        $real = $probe === '' ? false : realpath($probe);
        if ($home === '' || $realHome === false || $real === false || preg_match('#(^|/)\.\.?(/|$)#', $dir) || strpbrk($dir, "\0\n") !== false
            || strpos($real . '/', $realHome . '/') !== 0 || strpos($dir . '/', $home . '/') !== 0) {
            throw new CliError("nothing planned: --dir $dir is not a folder inside your home", 'give a folder under ~, e.g. --dir ~/data');
        }
        $inRoot = false;
        foreach ($roots as $r) {
            $realRoot = realpath($r); // the part that exists must resolve inside the root too: no symlink out of it
            $inRoot = $inRoot || (strpos($dir . '/', rtrim($r, '/') . '/') === 0 && $realRoot !== false && strpos($real . '/', $realRoot . '/') === 0);
        }
        if (!$inRoot || preg_match('#/\.#', substr($dir, strlen($home)) . substr($real, strlen($realHome)))) {
            throw new CliError("nothing planned: $dir is not inside a data folder here (" . ($roots ? implode(', ', $roots) : 'none found') . ')',
                'a move writes only into a torrent client\'s data folder (or ~/data: create it with mkdir ~/data), never the home itself, a dot-folder or ~/pikkumies; give --dir inside one');
        }
        return $dir;
    }
}
