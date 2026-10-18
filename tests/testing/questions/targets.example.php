<?php
/**
 * Example targets file for the question suite (README.md). Copy it OUTSIDE the repository, e.g. to
 * ~/.qa-targets.php (owned by you, not writable by others), and fill in your own values. Nothing here names a
 * provider or a model; your copy does, and it never goes into the repository.
 *
 * Each key file is the INI file pikkumies itself reads, mode 0600, owned by the account that runs the suite:
 *   endpoint=https://ENDPOINT/v1
 *   key=YOUR-KEY
 * Only key= is used from it; the endpoint below is the one asked.
 */
return [
    'targets' => [
        // name => settings. The name is used in the scorecard and in transcripts/<name>/.
        'target-a' => [
            'endpoint' => 'https://ENDPOINT/v1',    // OpenAI-compatible base URL
            'keyFile' => '~/.qa-keys/target-a.key', // ~/ is your real home, not the throwaway one
            'model' => 'MODEL-ID',
            'lightModel' => 'LIGHT-MODEL-ID',       // optional: the hooks' model; left out = pikkumies decides
            'family' => '',                         // optional: force a host family; '' = detected (a question's family wins)
        ],
        'target-b' => [
            'endpoint' => 'https://OTHER-ENDPOINT/v1',
            'keyFile' => '~/.qa-keys/target-b.key',
            'model' => 'OTHER-MODEL-ID',
        ],
    ],
    // Optional: grades the questions that have judge lines. Without it those lines are reported as not graded.
    'judge' => [
        'endpoint' => 'https://JUDGE-ENDPOINT/v1',
        'keyFile' => '~/.qa-keys/judge.key',
        'model' => 'JUDGE-MODEL-ID',
    ],
];
