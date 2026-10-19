<?php
/**
 * POST <UrlBase>/login of the fake (Sonarr AuthenticationController.Login): form fields username, password, rememberMe.
 * A match redirects to the UrlBase with a session cookie; a miss to login?returnUrl=&loginFailed=true. Counted in
 * `logins`, so a test can check one attempt per call.
 */
parse_str((string) file_get_contents('php://input'), $form);
$s['logins'] = (isset($s['logins']) ? $s['logins'] : 0) + 1;
$user = isset($form['username']) ? (string) $form['username'] : '';
$given = isset($form['password']) ? (string) $form['password'] : '';
$match = $s['userPassword'] !== '' && strcasecmp($user, $s['userName']) === 0 && hash_equals($s['userPassword'], $given);
$save();
http_response_code(302);
header('Location: ' . $s['base'] . ($match ? '/' : '/login?returnUrl=&loginFailed=true'));
if ($match) {
    header('Set-Cookie: SonarrAuth=fake; path=' . $s['base']);
}
exit;
