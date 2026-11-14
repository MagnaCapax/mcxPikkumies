<?php
/**
 * Update::attended() and the post-update call that uses it (ADR-0013 addendum 2026-11-14).
 * A person-started update (PMSS installer, panel setup button) sets PIKKUMIES_ATTENDED=1 and gets the attended build;
 * the daily cron line sets nothing and must keep the unattended build (file reserve, idle priority).
 */
return [
    'update: attended() is false unless PIKKUMIES_ATTENDED is exactly 1' => function () {
        foreach ([null, '', '0', 'true', 'yes', ' 1'] as $value) {
            $got = testWithEnv(['PIKKUMIES_ATTENDED' => $value], function () {
                return Update::attended();
            });
            check($got === false, 'PIKKUMIES_ATTENDED=' . var_export($value, true) . ' must read as unattended');
        }
        check(testWithEnv(['PIKKUMIES_ATTENDED' => '1'], function () {
            return Update::attended();
        }) === true, 'PIKKUMIES_ATTENDED=1 must read as attended');
    },
    'update: post-update builds unattended unless attended; install and run stay attended' => function () {
        $bin = file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        $post = substr($bin, strpos($bin, "case 'post-update':"));
        $post = substr($post, 0, strpos($post, 'break;'));
        check(strpos($post, 'HermesInstall::ensure(!Update::attended(),') !== false, 'post-update must pass !Update::attended()');
        check(strpos($post, 'HermesInstall::ensure(true,') === false, 'post-update must not hard-code unattended');
        check(substr_count($bin, 'HermesInstall::ensure(false,') === 2, 'install and run keep the attended build');
        // The cron line must never declare itself attended.
        check(strpos(Update::cronLine(1061), 'PIKKUMIES_ATTENDED') === false, 'the daily cron line stays unattended');
    },
];
