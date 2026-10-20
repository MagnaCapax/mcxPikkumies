<?php
/** Shared by the goal review tests (ADR-0096): a valid 13-line answer, with some values changed (null drops a line). */
if (!function_exists('grAnswers')) {
    function grAnswers(array $over = [])
    {
        $v = ['GOAL' => 'move the torrents to the new disk', 'CURRENT' => 'R1', 'CLEAR' => '900', 'ONTASK' => '900', 'MEMORY' => '200', 'OUTSIDE' => '100',
            'OPEN' => 'no', 'WAITING' => 'no', 'TOOLS' => 'none', 'PAGES' => 'none', 'KIND' => 'none', 'GUESSED' => 'no', 'CHAT' => 'no'];
        $lines = [];
        foreach ($over + $v as $name => $value) {
            if ($value !== null) {
                $lines[] = "$name: $value";
            }
        }
        return implode("\n", $lines);
    }

    /** A spool record. */
    function grRec($seq, $kind, $text, array $more = [])
    {
        return ['seq' => $seq, 'ts' => gmdate('Y-m-d\TH:i:s\Z', 1700000000 + $seq), 'kind' => $kind] + ($kind === 'tool' ? [] : ['text' => $text]) + $more;
    }
}
