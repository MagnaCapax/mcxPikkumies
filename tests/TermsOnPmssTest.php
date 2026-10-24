<?php
/**
 * On a Pulsed Media (PMSS) server the terms question is not asked: the customer accepted the same terms (Launch::TERMS_URL)
 * when signing up (operator 2026-10-24, ADR-0032 amendment). Elsewhere the first interactive session still asks.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/ConfigureTiers.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/ConfigureSettings.php';

return [
    'terms: accepted on a PMSS server without asking, still asked elsewhere, and an accepted flag counts everywhere' => function () {
        $fresh = Config::defaults();
        $accepted = $fresh;
        $accepted['terms']['accepted'] = true;
        try {
            ConfigureTiers::$pmssOverride = false;
            check(Config::termsAccepted($fresh) === false, 'off PMSS a fresh config must still need the terms');
            check(Config::termsAccepted($accepted) === true, 'off PMSS an accepted flag counts');
            ConfigureTiers::$pmssOverride = true;
            check(Config::termsAccepted($fresh) === true, 'on PMSS a fresh config needs no terms question');
            check(Config::termsAccepted($accepted) === true, 'on PMSS an accepted flag still counts');
            check($fresh['terms']['accepted'] === false, 'the flag itself is never written for the person');
        } finally {
            ConfigureTiers::$pmssOverride = null;
        }
    },
    'terms: the review does not tell a PMSS customer that the next session will ask' => function () {
        $config = Config::defaults();
        try {
            ConfigureTiers::$pmssOverride = true;
            $rows = array_column(ConfigureSettings::review($config), null, 'key');
            check(!isset($rows['terms.accepted']['warning']), 'on PMSS the terms must not warn: ' . json_encode($rows['terms.accepted']));
            ConfigureTiers::$pmssOverride = false;
            $rows = array_column(ConfigureSettings::review($config), null, 'key');
            check(isset($rows['terms.accepted']['warning']), 'off PMSS an unaccepted terms flag must still warn');
        } finally {
            ConfigureTiers::$pmssOverride = null;
        }
    },
    'terms: the first-session prompt and the panel ask both decide through Config::termsAccepted' => function () {
        $root = dirname(__DIR__) . '/pikkumies';
        $bin = (string) file_get_contents($root . '/bin/pikkumies');
        $ask = (string) file_get_contents($root . '/lib/EternalAsk.php');
        check(strpos($bin, 'if (!Config::termsAccepted($firstConfig)) {') !== false, 'the first-session prompt must use Config::termsAccepted');
        check(strpos($ask, '!Config::termsAccepted(Config::load())') !== false, 'eternal ask must use Config::termsAccepted');
        check(strpos($bin . $ask, "['terms']['accepted']") === false, 'no second, raw terms check may remain in the prompt or ask path');
    },
];
