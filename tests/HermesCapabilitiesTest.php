<?php
/**
 * lib/HermesConfig.php: capabilities()'s PMSS/Host-line interaction beyond what
 * HermesConfigRenderTest.php and CoreTest.php already cover (D151). Every existing capabilities()
 * test either sets `pmss` with no `family` key at all (HermesConfigRenderTest.php's "pmss without
 * a family key..." case and every CoreTest.php capabilities() call), which exercises the "Host:
 * generic" fallback branch, or sets `family: pmss` only inside DoctrineTest.php's soulProfiles()
 * fixtures, whose own tests check tool-doctrine and budget content, never the capability block's
 * Host-line wording itself. The one real-world case — a PMSS host, where the "PMSS seedbox
 * platform: yes" line already says which host this is, so a redundant "Host: pmss" line is
 * deliberately suppressed — had no test naming that suppression directly.
 */
return [
    'hermes capabilities: a PMSS host (pmss true, family pmss) shows the PMSS line and suppresses the redundant Host: line; a non-PMSS host with family pmss still shows both' => function () {
        $onPmss = HermesConfig::capabilities(['pmss' => true, 'family' => 'pmss', 'root' => ['available' => true, 'via' => 'sudo']], Config::defaults());
        check(strpos($onPmss, '- PMSS seedbox platform: yes') !== false, 'PMSS line missing: ' . $onPmss);
        check(strpos($onPmss, '- Host:') === false, 'a PMSS host must not also print a redundant Host: line: ' . $onPmss);

        // Not actually possible from Detect.php in practice (pmss=false implies family!=pmss), but
        // capabilities() takes the profile array as given: this proves the suppression is keyed on
        // BOTH pmss=true AND family==pmss together, not on family==pmss alone.
        $notPmss = HermesConfig::capabilities(['pmss' => false, 'family' => 'pmss', 'root' => ['available' => true, 'via' => 'sudo']], Config::defaults());
        check(strpos($notPmss, '- PMSS seedbox platform: yes') === false, 'pmss=false must never print the PMSS platform line: ' . $notPmss);
        check(strpos($notPmss, '- Host: pmss') !== false, 'pmss=false must still show the Host: line even when family happens to say pmss: ' . $notPmss);
    },
];
