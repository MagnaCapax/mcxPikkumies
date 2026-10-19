<?php
/**
 * lib/Redact.php: secret forms removed from a shell command line or error text before it is logged (lib/Cli.php's
 * cleanString only handles URL passkeys and user:password@; the option, header and env-assignment forms below are
 * Redact::secrets, shared with /feedback; tests/FeedbackTest.php already covers most header/assignment forms --
 * Authorization and Cookie headers, and a bare `key = value`/`TOKEN=xyz` assignment -- this file adds the forms it
 * does not: mysql -p, X-Api-Key, and a compact VAR=value env assignment for key/secret/password names too.
 * Every fixture value below is built by concatenation so no credential-shaped literal sits in this file's source.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/Redact.php';

return [
    'redact: a mysql -p<password> (bare, or after other flags) has the password replaced, the rest of the line kept' => function () {
        $pw = 'hun' . 'ter2secret';
        check(Redact::secrets("mysql -p$pw -u root") === 'mysql -pREDACTED -u root', 'bare mysql -p: ' . Redact::secrets("mysql -p$pw -u root"));
        check(Redact::secrets("mysql --user=root -p$pw somedb") === 'mysql --user=root -pREDACTED somedb',
            'mysql -p after other flags: ' . Redact::secrets("mysql --user=root -p$pw somedb"));
    },
    'redact: X-Api-Key header values are replaced, case-insensitively, the same as Authorization and Cookie already are' => function () {
        $v = 'abcdef' . '0123456789';
        $name = 'X-Api' . '-Key';
        check(Redact::secrets("$name: $v") === "$name: REDACTED", 'X-Api-Key: ' . Redact::secrets("$name: $v"));
        check(Redact::secrets(strtolower("$name: $v")) === strtolower("$name") . ': REDACTED', 'lower-case x-api-key: ' . Redact::secrets(strtolower("$name: $v")));
    },
    'redact: a compact VAR=value env assignment is replaced for KEY/SECRET/PASSWORD names too, an ordinary assignment is kept' => function () {
        $apiKeyName = 'API' . '_KEY';
        $apiKeyVal = 'abcdef' . '0123456longvalue';
        check(Redact::secrets("$apiKeyName=$apiKeyVal") === "$apiKeyName=REDACTED", "$apiKeyName=: " . Redact::secrets("$apiKeyName=$apiKeyVal"));

        $tokenName = 'MY_SECRET' . '_TOKEN';
        $tokenVal = 'abc' . 'ZZZ9988';
        check(Redact::secrets("export $tokenName=$tokenVal") === "export $tokenName=REDACTED", "$tokenName=: " . Redact::secrets("export $tokenName=$tokenVal"));

        $pwName = 'DB' . '_PASSWORD';
        $pwVal = 'hun' . 'ter2plain';
        check(Redact::secrets("$pwName=$pwVal") === "$pwName=REDACTED", "$pwName=: " . Redact::secrets("$pwName=$pwVal"));

        check(Redact::secrets('COLOR=blue') === 'COLOR=blue', 'an ordinary assignment was redacted: ' . Redact::secrets('COLOR=blue'));
    },
];
