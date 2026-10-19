<?php
/**
 * Settings routes of the fake (included first by routes.php with $s, $rel, $method, $body, $send in scope), shaped on
 * Sonarr's HostConfigController and ConfigController (PUT config/<section>/<id> answers 202 with the id):
 * - PUT config/host/1 checks what the app checks: PasswordConfirmation must match unless the password is "********",
 *   forms needs a user name and a password, "script" updates need a script path, the address must be an IP or *.
 *   A body that changes the API key or carries a redacted value is a violation: the tool must send back what it read.
 * - failHostPut: the app fails with a message that echoes the password it got (the tool must hide it); lastPut keeps
 *   the password of the last PUT so a test can look for it in the output.
 * - ignoreHostPut: the app answers 202 but keeps nothing (the login check after it must fail).
 * - a stored change also writes updateMechanism into config.xml (configPath), as the app saves its config file.
 */
$sections = ['ui', 'downloadclient', 'naming', 'indexer', 'mediamanagement'];
if ($method === 'GET' && $rel === 'config/host') {
    $send(200, $s['host']);
}
if ($method === 'GET' && $rel === 'config/mediamanagement') {
    $send(200, ['id' => 1, 'recycleBin' => $s['recycleBin'], 'recycleBinCleanupDays' => 7] + $s['cfg']['mediamanagement']);
}
if ($method === 'GET' && preg_match('#^config/([a-z]+)$#', $rel, $m) && in_array($m[1], $sections, true)) {
    $send(200, $s['cfg'][$m[1]]);
}
if ($method === 'PUT' && $rel === 'config/host/1') {
    $b = (array) $body;
    if (json_encode($b) !== false && strpos(json_encode($b), 'REDACTED') !== false || (isset($b['apiKey']) && $b['apiKey'] !== $s['key'])) {
        $s['violations'][] = 'config/host PUT changed the API key or sent a redacted value';
    }
    $s['lastPut'] = isset($b['password']) ? (string) $b['password'] : '';
    if (!empty($s['failHostPut'])) {
        $send(500, ['message' => 'Database is locked while saving ' . (isset($b['password']) ? $b['password'] : '')]);
    }
    $pw = isset($b['password']) ? (string) $b['password'] : '';
    $errors = [];
    if ($pw !== '********' && $pw !== (isset($b['passwordConfirmation']) ? $b['passwordConfirmation'] : null)) {
        $errors[] = ['propertyName' => 'PasswordConfirmation', 'errorMessage' => 'Must match Password', 'attemptedValue' => $pw];
    }
    if (strtolower((string) $b['authenticationMethod']) === 'forms' && ((string) $b['username'] === '' || $pw === '')) {
        $errors[] = ['propertyName' => 'Password', 'errorMessage' => "'Password' must not be empty."];
    }
    if (strtolower((string) $b['updateMechanism']) === 'script' && (string) $b['updateScriptPath'] === '') {
        $errors[] = ['propertyName' => 'UpdateScriptPath', 'errorMessage' => 'Invalid Path'];
    }
    if ($b['bindAddress'] !== '*' && !filter_var($b['bindAddress'], FILTER_VALIDATE_IP)) {
        $errors[] = ['propertyName' => 'BindAddress', 'errorMessage' => 'Enter a valid IP address'];
    }
    if ($errors) {
        $send(400, $errors);
    }
    if (!empty($s['ignoreHostPut'])) {
        $send(202, 1);
    }
    if ($pw !== '********' && (string) $b['username'] !== '') { // UserService.Upsert: a new password is stored
        $s['userPassword'] = $pw;
    }
    $s['userName'] = strtolower((string) $b['username']);
    $s['host'] = array_merge($b, ['password' => $s['userPassword'] !== '' ? '********' : '', 'passwordConfirmation' => '']);
    $xml = isset($s['configPath']) && is_file($s['configPath']) ? (string) file_get_contents($s['configPath']) : '';
    if ($xml !== '') { // ConfigFileProvider.SaveConfigDictionary: the app writes the value it got into config.xml (BuiltIn, Script)
        $tag = '<UpdateMechanism>' . ucfirst((string) $b['updateMechanism']) . '</UpdateMechanism>';
        $xml = strpos($xml, '<UpdateMechanism>') !== false ? preg_replace('#<UpdateMechanism>[^<]*</UpdateMechanism>#', $tag, $xml)
            : str_replace('</Config>', "  $tag\n</Config>", $xml);
        file_put_contents($s['configPath'], $xml);
    }
    $send(202, 1);
}
if ($method === 'PUT' && preg_match('#^config/([a-z]+)/1$#', $rel, $m) && in_array($m[1], $sections, true)) {
    $b = (array) $body;
    if ($m[1] === 'mediamanagement') {
        $s['recycleBin'] = (string) $b['recycleBin'];
        unset($b['recycleBin'], $b['recycleBinCleanupDays'], $b['id']);
    }
    $s['cfg'][$m[1]] = $b;
    $send(202, 1);
}
