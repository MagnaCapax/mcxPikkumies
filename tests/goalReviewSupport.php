<?php
/** Shared by the goal review tests (ADR-0071): a valid ten-line answer, with some lines changed. */
if (!function_exists('grAnswers')) {
    function grAnswers(array $over = [])
    {
        $lines = ['Q1: no', 'Q2: no', 'Q3: no', 'Q4: none', 'Q5: none', 'Q6: no', 'Q7: none', 'Q8: no', 'Q9: no', 'Q10: no'];
        foreach ($over as $n => $token) {
            $lines[$n - 1] = "Q$n: $token";
        }
        return implode("\n", $lines);
    }
}
