<?php
/**
 * ToolList: how much of a program's listing the storage and backup units read so Cli::emit can page it. A listing reads
 * at most CAP entries: --offset pages within them, --limit 0 reads all CAP.
 */
class ToolList
{
    const CAP = 10000;

    /** Entries a listing reads so Cli::emit pages it: offset + limit + 1 (the extra one marks `truncated`), CAP for --limit 0. */
    public static function want(array $opt)
    {
        $limit = isset($opt['limit']) ? max(0, (int) $opt['limit']) : Cli::LIMIT;
        $offset = isset($opt['offset']) ? max(0, (int) $opt['offset']) : 0;
        return $limit > 0 ? min(self::CAP, $offset + $limit + 1) : self::CAP;
    }

    /** Status of a listing that read $count of at most $want entries: listed_all = it read to the end. */
    public static function listed($count, $want)
    {
        return ['listed_all' => $count < $want]
            + ($count >= self::CAP ? ['note' => 'read the first ' . self::CAP . ' entries only; list a narrower path for the rest'] : []);
    }
}
