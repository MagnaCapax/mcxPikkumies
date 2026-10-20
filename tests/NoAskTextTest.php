<?php
/**
 * ADR-0101: the agent-facing text under pikkumies/ (doctrine, skills, USAGE files, READMEs, hook descriptions) never
 * tells the agent to ask the user first, to wait for their yes, or to hand them a command to run. The phrasings below
 * are the ones that were removed; each line that may still hold one is listed with its reason (a description of a
 * blocked tool, a person-reserved item worded as a report, a quoted antipattern cue).
 */
if (!function_exists('noAskFiles')) {
    /** Every agent-facing markdown file under pikkumies/, relative to the repository root. */
    function noAskFiles()
    {
        $root = dirname(__DIR__);
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/pikkumies", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = (string) $f;
            if (substr($path, -3) === '.md' && strpos($path, '/local/') === false && strpos($path, '/hermes/') === false) {
                $out[] = substr($path, strlen($root) + 1);
            }
        }
        sort($out);
        return $out;
    }
}

const NO_ASK_PHRASES = '/after (they|the user) says? yes|after the user\'s yes|run it themselves|(?<!never )(?<!not )\bask the user\b|until you approve|asks you first|answered "?always|until (they|the user) says? yes|the user\'s (own |explicit )?yes|on their yes|asks the user (first|once|before)|tell the user to run it/i';

// file => [phrase found on that line, why it stays]
const NO_ASK_KEEP = [
    'pikkumies/hooks/tools/clarify/decide-yourself/README.md' => ['ask the user', 'describes Hermes\' clarify tool, which this hook blocks'],
];

return [
    'no-ask text: no agent-facing file asks the user first, waits for a yes or hands the user a command (ADR-0101)' => function () {
        $root = dirname(__DIR__);
        $bad = [];
        foreach (noAskFiles() as $rel) {
            foreach (file("$root/$rel", FILE_IGNORE_NEW_LINES) as $n => $line) {
                if (!preg_match(NO_ASK_PHRASES, $line, $m)) {
                    continue;
                }
                if (isset(NO_ASK_KEEP[$rel]) && stripos($m[0], NO_ASK_KEEP[$rel][0]) !== false) {
                    continue;
                }
                $bad[] = "$rel:" . ($n + 1) . ' "' . $m[0] . '"';
            }
        }
        check($bad === [], count($bad) . " line(s) ask the user or hand a step over:\n" . implode("\n", $bad));
    },
    'no-ask text: every kept line still exists and carries its reason' => function () {
        $root = dirname(__DIR__);
        foreach (NO_ASK_KEEP as $rel => $keep) {
            $text = (string) @file_get_contents("$root/$rel");
            check($text !== '' && stripos($text, $keep[0]) !== false && $keep[1] !== '', "$rel: the kept phrase \"{$keep[0]}\" is gone; drop it from NO_ASK_KEEP");
        }
    },
];
