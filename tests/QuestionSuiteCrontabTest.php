<?php
/**
 * tests/testing/questions/lib/Sandbox.php's login-shell PATH fix (ADR-0039 RCA 2026-10-18): Hermes' terminal tool
 * snapshots a login bash, which re-reads /etc/profile and can lose the fake crontab's place on PATH that
 * Targets::env set up, so a question's terminal call could reach the account's real crontab. Sandbox::create() and
 * ::reuse() write a $home/.bash_profile that wins that race; this also covers reset() restoring the fake crontab's
 * saved tab (the smaller gap in the same RCA: a question's edit to it must not carry into the next question).
 */
require_once dirname(__DIR__) . '/tests/testing/questions/lib/Sandbox.php';
require_once dirname(__DIR__) . '/tests/testing/questions/lib/Asker.php';

/**
 * What `command -v crontab` resolves to inside a real login bash with HOME=$home and a stock PATH (as a bare
 * /etc/profile would leave it): this host's own /etc/profile runs, exactly reproducing the race Hermes hits.
 */
function crontabLoginResolve($home)
{
    exec('env -i HOME=' . escapeshellarg($home) . ' PATH=/usr/bin:/bin bash -l -c ' . escapeshellarg('command -v crontab') . ' 2>&1', $o, $c);
    return $c === 0 && $o ? trim($o[0]) : '';
}

/** Skip, rather than fail, when this host cannot even run Sandbox::create() (it shells out to `git ls-files`). */
function questionsSandboxOrSkip($repo)
{
    exec('command -v git', $o, $c);
    if ($c !== 0) {
        skip('no git on PATH here; Sandbox::create() needs it to copy the tracked files, so it is not this fix under test');
    }
    return Sandbox::create($repo);
}

return [
    'questions: Sandbox::create() writes a .bash_profile that wins a login shell\'s PATH race, so crontab resolves to the fake' => function () {
        $box = questionsSandboxOrSkip(dirname(__DIR__));
        try {
            $profile = (string) file_get_contents($box->home . '/.bash_profile');
            check($profile !== '', 'create() must write home/.bash_profile');
            check(strpos($profile, '. ~/.profile') !== false, 'it must chain-source ~/.profile, or a real one\'s own PATH would be lost: ' . $profile);
            check(strpos($profile, $box->home . '/.qa-bin:$PATH') !== false, 'it must put .qa-bin first on PATH: ' . $profile);
            $want = $box->home . '/.qa-bin/crontab';
            check(crontabLoginResolve($box->home) === $want, "a login shell did not resolve crontab to the fake ($want): " . crontabLoginResolve($box->home));
        } finally {
            $box->remove();
        }
    },
    'questions: without .bash_profile a login shell resolves crontab to the real one (proves the fix is load-bearing)' => function () {
        exec('command -v crontab', $o, $c);
        if ($c !== 0 || !$o) {
            skip('no real crontab binary on this host (a bare container image, typically); there is nothing for a reset login PATH to wrongly resolve to, so this environment cannot demonstrate the exploit the fix closes');
        }
        $box = questionsSandboxOrSkip(dirname(__DIR__));
        try {
            unlink($box->home . '/.bash_profile');
            $resolved = crontabLoginResolve($box->home);
            check($resolved !== '' && $resolved !== $box->home . '/.qa-bin/crontab',
                'removing .bash_profile should break the login-shell PATH race, but crontab still resolved to the fake: ' . $resolved);
        } finally {
            $box->remove();
        }
    },
    'questions: Sandbox::reuse() creates .bash_profile when an earlier kept sandbox is missing it' => function () {
        $box = questionsSandboxOrSkip(dirname(__DIR__));
        try {
            unlink($box->home . '/.bash_profile');
            check(!is_file($box->home . '/.bash_profile'), 'setup must remove the file before asking reuse() to recreate it');
            $reused = Sandbox::reuse($box->root);
            check(is_file($reused->home . '/.bash_profile'), 'reuse() must create a missing .bash_profile, not assume an older sandbox already has one');
        } finally {
            $box->remove();
        }
    },
    'questions: Sandbox::reuse() leaves an existing .bash_profile alone' => function () {
        $box = questionsSandboxOrSkip(dirname(__DIR__));
        try {
            file_put_contents($box->home . '/.bash_profile', 'custom');
            Sandbox::reuse($box->root);
            check(file_get_contents($box->home . '/.bash_profile') === 'custom', 'reuse() must not overwrite an existing .bash_profile');
        } finally {
            $box->remove();
        }
    },
    'questions: Sandbox::reset() restores the fake crontab\'s tab, so one question\'s edit does not carry into the next' => function () {
        $box = questionsSandboxOrSkip(dirname(__DIR__));
        try {
            $tab = $box->home . '/.qa-bin/tab';
            // The tab already exists at snapshot time (as it would after install schedules the auto-update): a
            // question's edit to its CONTENT is not a new file, so the generic trace sweep alone would miss it.
            file_put_contents($tab, "0 3 * * * /original/line\n");
            $box->snapshot();
            file_put_contents($tab, "0 3 * * * /original/line\n* * * * * /injected/by-a-question\n");
            $box->reset();
            check(file_get_contents($tab) === "0 3 * * * /original/line\n", 'a question\'s edit to the tab carried over: ' . file_get_contents($tab));
            unlink($tab);
            $box->reset();
            check(file_get_contents($tab) === "0 3 * * * /original/line\n", 'a question deleting the tab must also be undone');
        } finally {
            $box->remove();
        }
    },
    'questions: Sandbox::reset() removes a tab a question created when none existed at snapshot time' => function () {
        $box = questionsSandboxOrSkip(dirname(__DIR__));
        try {
            $tab = $box->home . '/.qa-bin/tab';
            check(!is_file($tab), 'a fresh sandbox must start with no tab (the crontab fake was never called)');
            $box->snapshot();
            file_put_contents($tab, "* * * * * /added/by-a-question\n");
            $box->reset();
            check(!is_file($tab), 'a tab a question created, with none in the snapshot, must not survive reset()');
        } finally {
            $box->remove();
        }
    },
];
