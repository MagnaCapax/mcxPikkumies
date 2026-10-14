<?php
/** Helpers for tests/ArrAutobrr*Test.php: the autobrr classes, arrRun with the test secrets folder, a stored key. */
require_once __DIR__ . '/phase2.php';
foreach (['AutobrrSecret', 'AutobrrConfig', 'AutobrrVerbs', 'AutobrrRun'] as $c) {
    require_once dirname(__DIR__, 3) . "/pikkumies/tools/arr/autobrr/$c.php";
}

/** arrRun with the test secrets folder; $typed: what the user types at `login` (null: no terminal). */
function abRun(array $fx, array $args, $typed = null, array $env = [])
{
    $env += ['ARR_TEST_SECRETS' => $fx['dir'] . '/secrets'];
    return arrRun($fx, $args, $typed === null ? $env : $env + ['ARR_TEST_TYPED' => $typed]);
}

function abLogin(array $fx)
{
    (new AutobrrSecret($fx['dir'] . '/secrets'))->save(ARR_KEY);
}
