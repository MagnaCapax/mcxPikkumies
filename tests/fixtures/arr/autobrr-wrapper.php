<?php
/**
 * Runs tools/arr/autobrr/autobrr.php with its two test seams set (this file is test code, never shipped as a tool):
 * the secrets folder from ARR_TEST_SECRETS, and, when ARR_TEST_TYPED is set, what the user "types" at login.
 */
$autobrrSecretDir = (string) getenv('ARR_TEST_SECRETS');
if (getenv('ARR_TEST_TYPED') !== false) {
    $autobrrPrompt = function () {
        return (string) getenv('ARR_TEST_TYPED');
    };
}
require dirname(__DIR__, 3) . '/pikkumies/tools/arr/autobrr/autobrr.php';
