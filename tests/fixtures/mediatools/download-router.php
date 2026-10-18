<?php
/**
 * A plain static file server for tests/MediaToolsTranscribeInstallTest.php, run with
 * `php -S 127.0.0.1:<port> download-router.php`. Serves whatever file sits at MT_DL_DIR + the
 * request path; everything else is 404. Real `curl` fetches from this loopback fixture in the
 * install-local tests — the real network is never reached.
 */
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = rtrim((string) getenv('MT_DL_DIR'), '/') . $path;
if ($path === '' || strpos($path, '..') !== false || !is_file($file)) {
    http_response_code(404);
    echo 'not found';
    exit;
}
header('Content-Type: application/octet-stream');
readfile($file);
