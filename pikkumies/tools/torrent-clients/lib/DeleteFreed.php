<?php
/**
 * DeleteFreed: the disk space a delete frees, counted once per inode (DeletePlan keeps the inodes).
 * Deleting a path frees nothing while another hard link keeps the inode, so an inode counts only when
 * every hard link of it is a planned path: two hard-linked torrents in one batch free their size once.
 */
class DeleteFreed
{
    /** Records $path of torrent $id under the inode $key; a symlink to the inode is not one of its links. */
    public static function add(array &$inodes, $key, $id, $path)
    {
        $st = strpos($key, 'inode:') === 0 ? @stat($path) : false;
        if (!$st) {
            return; // not on disk: frees nothing
        }
        $i = isset($inodes[$key]) ? $inodes[$key]
            : ['torrents' => [], 'paths' => [], 'links' => (int) $st['nlink'], 'size' => (int) $st['size']];
        if (!in_array($id, $i['torrents'], true)) {
            $i['torrents'][] = $id;
        }
        if (!is_link($path) && !in_array($path, $i['paths'], true)) {
            $i['paths'][] = $path;
        }
        $inodes[$key] = $i;
    }

    /**
     * Bytes freed per torrent id (the first torrent holding the inode): an inode frees its size when its
     * planned paths are all its hard links, each is $gone(path), and every torrent holding it is in $deleted.
     */
    public static function byTorrent(array $inodes, array $deleted, callable $gone)
    {
        $out = [];
        foreach ($inodes as $i) {
            $all = count($i['paths']) >= $i['links'] && count(array_filter($i['paths'], $gone)) === count($i['paths']);
            if ($all && !array_diff($i['torrents'], $deleted)) {
                $first = $i['torrents'][0];
                $out[$first] = (isset($out[$first]) ? $out[$first] : 0) + $i['size'];
            }
        }
        return $out;
    }

    /** True when a planned file on disk keeps its space through a hard link outside the plan. */
    public static function linkedElsewhere(array $inodes)
    {
        foreach ($inodes as $i) {
            if (count($i['paths']) < $i['links']) {
                return true;
            }
        }
        return false;
    }
}
