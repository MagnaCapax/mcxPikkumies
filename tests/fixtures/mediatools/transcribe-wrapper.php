<?php
/**
 * Runs tools/media/transcribe/transcribe.php with its test seams set from env vars (test code
 * only, never shipped): MT_SECRET_DIR, MT_LOCAL_DIR, MT_PINS (JSON, optional: real pins when
 * unset), MT_TYPED (login's three prompt answers, \x1f-joined; unset means no terminal, so
 * login's own not-a-tty refusal fires). Same pattern as tests/fixtures/arr/autobrr-wrapper.php.
 */
$transcribeSecretDir = (string) getenv('MT_SECRET_DIR');
$transcribeLocalDir = getenv('MT_LOCAL_DIR') !== false ? getenv('MT_LOCAL_DIR') : null;
$pinsRaw = getenv('MT_PINS');
$transcribePins = $pinsRaw !== false && $pinsRaw !== '' ? json_decode($pinsRaw, true) : null;
if (getenv('MT_TYPED') !== false) {
    $answers = explode("\x1f", (string) getenv('MT_TYPED'));
    $transcribePrompt = function () use (&$answers) {
        return $answers ? array_shift($answers) : '';
    };
}
require dirname(__DIR__, 3) . '/pikkumies/tools/media/transcribe/transcribe.php';
