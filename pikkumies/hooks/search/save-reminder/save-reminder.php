<?php
/**
 * Hook unit (pre_llm_call, run by hooks/orchestrator.php, ADR-0030). The save reminder of ADR-0041:
 * when the newest memory search found no lesson (tier 1 had no hit), no memory file was saved or
 * appended after it, and it is under MemoryMetrics::SAVE_WINDOW old, the next turn gets one line
 * with the exact save command. Once per search: save-reminder.last holds the search time it
 * reminded about. Reads last-search.json and file times only: no model call, no network.
 * searchFirst.disabled turns it off, as it does the search-first units. Fails open; a safety
 * prompt for the agent's habits, not a security boundary (AGENTS.md).
 */
require_once dirname(__DIR__, 3) . '/lib/DocSources.php';
require_once dirname(__DIR__, 3) . '/tools/memory/pikkumies/MemoryMetrics.php';

stream_get_contents(STDIN); // the turn payload is not needed; read it so the writer never blocks
$config = Config::load();
if (!empty($config['searchFirst']['disabled'])) {
    exit(0);
}
$miss = MemoryMetrics::recentMiss();
if ($miss === null) {
    exit(0);
}
$time = (int) $miss['time'];
$marker = DocSources::searchStateDir() . '/save-reminder.last';
if ((int) @file_get_contents($marker) === $time || MemoryMetrics::newestMemoryChange() >= $time) {
    exit(0); // already reminded about this search, or something was saved after it
}
if (@file_put_contents($marker, $time . "\n") === false) {
    exit(0); // without the marker it would repeat every turn
}
echo json_encode(['context' => 'The last search found no lesson; if that task is solved, save what you learned (or what did not settle it): '
    . MemoryMetrics::saveCommand()], JSON_UNESCAPED_SLASHES) . "\n";
