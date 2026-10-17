<?php
/**
 * The AI key is never the agent's to read (ADR-0004): commands on ~/.virsilipasKey, ~/.pikkumiesKey, the Hermes
 * .env or a vsk- key are blocked; the balance comes from `credits balance`. Origin: in a live test the agent
 * grepped the home for its own key to answer "how many credits do I have left?".
 * guardKindTable() is defined in HookGuardContainersTest.php; this file loads it when run alone.
 */
if (!function_exists('guardKindTable')) {
    require __DIR__ . '/HookGuardContainersTest.php';
}

return [
    'key guard: reading, listing or searching for the AI key is blocked; doctor, credits and unrelated .env files run' => function () {
        guardKindTable([
            ['cat ~/.virsilipasKey', 'block', 'AI key'],
            ['ls -la ~/.pikkumiesKey', 'block', 'AI key'],
            ["grep -rIl 'vsk-' ~/", 'block', 'AI key'],
            ['V=$(grep -oE x /home/u/.mcxPikkumies/pikkumies/local/hermes/.env); curl -H "Authorization: Bearer $V" https://e/v1/balance', 'block', 'AI key'],
            ['cat "${HERMES_HOME}/.env"', 'block', 'AI key'],
            ['pikkumies doctor', 'none'],
            ['php ~/pikkumies/tools/system/credits/credits.php balance', 'none'],
            ['cat ~/myapp/.env', 'none'],
        ]);
        guardKindTable([['import os; print(open(os.path.expanduser("~/.virsilipasKey")).read())', 'block', 'AI key']], 'execute_code');
    },
];
