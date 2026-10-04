<?php
/**
 * Provider: endpoint + key resolution. The key file is INI ("endpoint=..." and "key=...").
 * Precedence: env PIKKUMIES_ENDPOINT / PIKKUMIES_KEY, then local config, then the key file.
 * The key is never printed; use mask().
 */
class Provider
{
    public static function resolve(array $config)
    {
        $p = $config['provider'];
        $file = self::readKeyFile($p['keyFile']);
        $endpoint = getenv('PIKKUMIES_ENDPOINT') ?: ($p['endpoint'] !== '' ? $p['endpoint'] : $file['endpoint']);
        $key = getenv('PIKKUMIES_KEY') ?: $file['key'];
        return ['endpoint' => rtrim((string) $endpoint, '/'), 'key' => (string) $key, 'model' => (string) $p['model']];
    }

    public static function readKeyFile($path)
    {
        $empty = ['endpoint' => '', 'key' => ''];
        if ($path === '' || !is_file($path) || is_link($path) || !is_readable($path)) {
            return $empty;
        }
        $ini = @parse_ini_file($path, false, INI_SCANNER_RAW);
        if (!is_array($ini)) {
            return $empty;
        }
        return [
            'endpoint' => isset($ini['endpoint']) ? trim($ini['endpoint']) : '',
            'key'      => isset($ini['key']) ? trim($ini['key']) : '',
        ];
    }

    public static function mask($key)
    {
        $len = strlen($key);
        return $len === 0 ? '(none)' : substr($key, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($key, -4);
    }

    /** GET {endpoint}/models as a reachability + key check. Returns [ok, httpCode]. */
    public static function check(array $prov)
    {
        if ($prov['endpoint'] === '' || $prov['key'] === '' || !function_exists('curl_init')) {
            return [false, 0];
        }
        $ch = curl_init($prov['endpoint'] . '/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $prov['key']],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code >= 200 && $code < 300, $code];
    }
}
