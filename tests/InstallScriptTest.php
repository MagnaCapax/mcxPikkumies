<?php
/**
 * install.sh (ADR-0022): the curl/wget bootstrap only clones/updates the repository and execs
 * `pikkumies install`; every PATH/shell-profile edit belongs to lib/PathInstall.php alone (D144).
 * A static text check, not an execution: install.sh needs network (git clone) to run for real, and
 * this is a source-shape contract, the same style LaunchTest.php/LaunchRoutingTest.php already use
 * for bin/pikkumies's own dispatch.
 */
return [
    'install.sh: never edits a shell rc file itself; only clones/updates and execs pikkumies install' => function () {
        $sh = (string) file_get_contents(dirname(__DIR__) . '/install.sh');
        check($sh !== '', 'install.sh could not be read');
        foreach (['.bashrc', '.zshrc', '.profile', '.bash_profile'] as $rc) {
            check(strpos($sh, $rc) === false, "install.sh must never mention $rc itself; only PathInstall::ensureFile() may edit it: $sh");
        }
        check(strpos($sh, 'export PATH') === false, 'install.sh must never export PATH by hand: ' . $sh);
        check(strpos($sh, '>>') === false, 'install.sh must never append to a file by hand (the >> shell operator): ' . $sh);
        check(strpos($sh, 'bin/pikkumies" install') !== false, 'install.sh must hand off to `pikkumies install`, which alone runs PathInstall::install(): ' . $sh);
    },
];
