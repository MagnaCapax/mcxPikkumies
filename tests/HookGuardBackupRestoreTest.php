<?php
/**
 * The delete guard's backup-tool rules (ADR-0020 amendment 2026-11-17, issue #20): restic key remove / repair snapshots
 * --forget / restore, borg check --repair / extract, kopia and duplicacy restores are asked only where the program is
 * run (command position), not where a note, an echo or a search only names it, and never for the unit's own restore,
 * which refuses an existing non-empty target itself. guardKindTable() is defined in HookGuardContainersTest.php; this
 * file loads it when run alone.
 */
if (!function_exists('guardKindTable')) {
    require __DIR__ . '/HookGuardContainersTest.php';
}

return [
    'backup guard: a raw restic, borg, kopia or duplicacy restore or repair is asked as a delete, at the start and after |, ;, &&, ||, sudo, env, a -c string or a list' => function () {
        $rows = [];
        foreach ([
            'restic key remove abc', 'restic repair snapshots --forget', 'restic -r r restore latest --target ~',
            'restic restore latest --target ~/data', 'borg check --repair ~/repo', 'borg extract ~/repo::a',
            'kopia snapshot restore k1 ~/x', 'kopia restore k1 ~/x', 'duplicacy restore -r 3 -overwrite',
        ] as $cmd) {
            foreach ([$cmd, "echo go | $cmd", "echo go; $cmd", "cd ~ && $cmd", "false || $cmd", "echo go\n$cmd", "sudo $cmd", "sudo -n $cmd", "env X=1 $cmd",
                "RESTIC_PASSWORD=x $cmd", "nice -n 5 $cmd", "timeout 60 $cmd", "/usr/bin/$cmd", "bash -c \"$cmd\"", "sh -c '$cmd'", "x=\$($cmd)"] as $form) {
                $rows[] = [$form, 'approve', 'delete'];
            }
        }
        $rows[] = ['import subprocess; subprocess.run(["restic", "restore", "latest", "--target", "/h"])', 'approve', 'delete'];
        $rows[] = ["import os; os.system('borg extract ~/repo::a')", 'approve', 'delete'];
        $rows[] = ['RESTIC_PASSWORD="a b" RESTIC_REPOSITORY=\'/r s\' restic restore latest --target ~/x', 'approve', 'delete'];
        guardKindTable($rows);
        guardKindTable([['import subprocess; subprocess.run(["restic", "key", "remove", "abc"])', 'approve', 'delete']], 'execute_code');
    },
    'backup guard: text that only mentions restic, borg or kopia and restore is not a delete (a note, an echo, a heredoc, a search, a commit message)' => function () {
        guardKindTable([
            ['php ~/pikkumies/tools/memory/memory.php append notes.md "Backed up with restic, then a restore into a new directory brought the file back"', 'none'],
            ['echo "restic was used; the restore went into a new directory"', 'none'],
            ["cat >> ~/notes.md <<'EOF'\nBacked up with restic and checked it. To get a file back use the unit: restic restore goes into a new directory.\nEOF", 'none'],
            ['grep -rn restic ~/notes | grep restore', 'none'],
            ['git commit -m "docs: restic restore into a new directory, borg extract and kopia snapshot restore too"', 'none'],
            ['echo "note: restic key remove and restic repair snapshots --forget are refused"', 'none'],
            ['echo "borg extract and borg check --repair need care, and so does kopia snapshot restore"', 'none'],
            ['pikkumies memory save "restic and kopia: a restore goes to a new folder, while duplicacy restore -overwrite does not"', 'none'],
            ['man restic restore', 'none'],
        ]);
    },
    'backup guard: the unit\'s own restore into a new directory is not asked, alone or in a chain' => function () {
        $r = 'php ~/pikkumies/tools/backup/restic/restic.php restore 4f1c2a9b --target ~/restore-1117';
        $b = 'php ~/pikkumies/tools/backup/borg/borg.php restore myhost-2026-10-01T03:00:00 --target ~/restore-1117 --include /home/fixtureuser/data';
        guardKindTable([
            [$r, 'none'],
            ["$r --include /home/fixtureuser/data/photos", 'none'],
            ["cd ~ && $r", 'none'],
            ["echo go; $r | tail -3", 'none'],
            ['pikkumies restic restore 4f1c2a9b --target ~/restore-1117', 'none'],
            ['pikkumies backup restic restore 4f1c2a9b --target ~/restore-1117', 'none'],
            ['~/pikkumies/tools/backup/restic/restic.php restore 4f1c2a9b --target ~/restore-1117', 'none'],
            [$b, 'none'],
            ['pikkumies borg restore myhost-2026-10-01T03:00:00 --target ~/restore-1117', 'none'],
        ]);
    },
];
