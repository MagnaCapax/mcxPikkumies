<?php
/**
 * A fake OpenAI-compatible transcription endpoint for tests/MediaToolsTranscribeTest.php, run with
 * `php -S 127.0.0.1:<port> router.php`. Records every call's Authorization header and multipart
 * fields (never the uploaded bytes), and answers the scripted status: `cloud_codes` (a list,
 * consumed one per call) if given, else `cloud_code` (default 200). Same pattern as
 * tests/fixtures/arr/autobrr-router.php (MT_FAKE_STATE in place of ARR_FAKE_STATE).
 */
$file = getenv('MT_FAKE_STATE');
$s = json_decode((string) file_get_contents($file), true);
$codes = isset($s['cloud_codes']) && $s['cloud_codes'] ? $s['cloud_codes'] : null;
$code = $codes !== null ? array_shift($codes) : (isset($s['cloud_code']) ? (int) $s['cloud_code'] : 200);
if ($codes !== null) {
    $s['cloud_codes'] = $codes;
}
$s['calls'][] = [
    'path' => $_SERVER['REQUEST_URI'],
    'authorization' => isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '',
    'model' => isset($_POST['model']) ? $_POST['model'] : null,
    'response_format' => isset($_POST['response_format']) ? $_POST['response_format'] : null,
    'language' => isset($_POST['language']) ? $_POST['language'] : null,
    'file_name' => isset($_FILES['file']['name']) ? $_FILES['file']['name'] : null,
    'file_size' => isset($_FILES['file']['size']) ? $_FILES['file']['size'] : null,
];
file_put_contents($file, json_encode($s));
http_response_code($code);
header('Content-Type: application/json');
echo $code === 200 ? json_encode(['text' => isset($s['cloud_text']) ? $s['cloud_text'] : 'hello']) : json_encode(['error' => ['message' => 'fake error']]);
