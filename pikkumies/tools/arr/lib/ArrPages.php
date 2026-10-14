<?php
/**
 * ArrPages: reads a paged list (history, queue, wanted) so Cli::emit can apply --limit, --offset and its 16 KiB
 * budget the way it does for every tool: --limit 0 means "as many as fit", never one record. A call reads the first
 * offset + limit + 1 records, one more than it may print, so Cli marks `truncated` with `next_offset` when the app
 * has more; with --limit 0 it reads CAP. Pages are PAGE records, the most one request asks for. When CAP stops the
 * reading while the app has more, the status says so in `note`.
 */
class ArrPages
{
    const PAGE = 200;  // records per request
    const CAP = 1000;  // records per call: far more than 16 KiB of output holds; --offset reads further, up to CAP
    const SERVARR = ['page', 'pageSize', 'records', 'totalRecords'];

    /** How many records this call reads: offset + limit + 1, or CAP for --limit 0. */
    public static function need(array $opt)
    {
        $limit = isset($opt['limit']) ? max(0, (int) $opt['limit']) : Cli::LIMIT;
        $offset = isset($opt['offset']) ? max(0, (int) $opt['offset']) : 0;
        return $limit > 0 ? min(self::CAP, $offset + $limit + 1) : self::CAP;
    }

    /**
     * One list of the app: $k = [offset param, size param, rows field, total field]. Servarr pages by number (SERVARR);
     * Bazarr (start, length -> data, total) and autobrr (offset, limit -> data, count) by record offset. Stops at a
     * short page, at the app's total or at need(). Returns [rows, status, the app's total or 0, the app's total or null]:
     * the last one goes to Cli as status `total`, so a truncated call reports the app's count, not the records read.
     */
    public static function get(ArrClient $c, $path, array $k, array $query, array $opt)
    {
        $need = self::need($opt);
        $size = min(self::PAGE, $need);
        $rows = [];
        do {
            $at = $k[0] === 'page' ? intdiv(count($rows), $size) + 1 : count($rows); // a multiple of $size here
            $p = $c->call('GET', $path, [$k[0] => $at, $k[1] => $size] + $query);
            $total = isset($p[$k[3]]) ? (int) $p[$k[3]] : null;
            $got = array_values(array_filter(isset($p[$k[2]]) && is_array($p[$k[2]]) ? $p[$k[2]] : [], 'is_array'));
            $rows = array_merge($rows, $got);
        } while (count($got) === $size && count($rows) < $need && ($total === null || count($rows) < $total));
        $rows = array_slice($rows, 0, $need);
        $more = count($rows) >= self::CAP && ($total === null || $total > count($rows));
        return [$rows, $more ? ['note' => 'read the first ' . self::CAP . ' of ' . ($total === null ? 'more' : $total)
            . ' records; the rest: --since where the verb has it, or the app\'s own UI'] : [], (int) $total, $total];
    }
}
