<?php
/**
 * Provider: endpoint + key resolution. Key files are INI ("endpoint=..." and "key=...").
 * Precedence: env PIKKUMIES_ENDPOINT / PIKKUMIES_KEY, then local config, then ~/.pikkumiesKey, then
 * ~/.virsilipasKey (the file the platform writes for its own proxy, ADR-0004 amendment); the first key file
 * holding a key is the one used, for its endpoint too.
 * The key is never printed; use mask().
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Http.php';

class Provider
{
    public static function resolve(array $config)
    {
        $p = $config['provider'];
        $file = self::keyFile($p);
        $endpoint = getenv('PIKKUMIES_ENDPOINT') ?: ($p['endpoint'] !== '' ? $p['endpoint'] : $file['endpoint']);
        $key = getenv('PIKKUMIES_KEY') ?: $file['key'];
        return ['endpoint' => rtrim((string) $endpoint, '/'), 'key' => (string) $key, 'model' => (string) $p['model']];
    }

    /** The first key file in $p (keyFile, then virsilipasKeyFile) holding a key: ['endpoint', 'key', 'path'], all '' when none. */
    public static function keyFile(array $p)
    {
        foreach (['keyFile', 'virsilipasKeyFile'] as $name) {
            $path = isset($p[$name]) ? (string) $p[$name] : '';
            $file = self::readKeyFile($path);
            if ($file['key'] !== '') {
                return $file + ['path' => $path];
            }
        }
        return ['endpoint' => '', 'key' => '', 'path' => ''];
    }

    /**
     * A key file is trusted like SecretFile::read trusts a secret: a regular file (never a symlink) owned by this
     * account. One others could read (mode & 077, e.g. written under umask 022) is set back to 0600 with a note on
     * stderr, then used; a chmod that fails, or another owner, reads as no key. $uid: a test seam.
     */
    public static function readKeyFile($path, $uid = null)
    {
        $empty = ['endpoint' => '', 'key' => ''];
        if ($path === '' || !is_file($path) || is_link($path) || !is_readable($path)) {
            return $empty;
        }
        clearstatcache(true, $path);
        $st = @lstat($path);
        $uid = $uid !== null ? (int) $uid : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        if ($st === false || (int) $st['uid'] !== $uid) {
            return $empty;
        }
        if ($st['mode'] & 077) {
            if (!@chmod($path, 0600)) {
                return $empty;
            }
            fwrite(STDERR, sprintf("note: %s was mode %04o (others could read the key); set to 0600\n", $path, $st['mode'] & 0777));
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
        // A key under 16 characters is shown as stars only: showing 8 of it would show most of it.
        return $len === 0 ? '(none)' : ($len < 16 ? str_repeat('*', 8) : substr($key, 0, 4) . str_repeat('*', $len - 8) . substr($key, -4));
    }

    /** GET {endpoint}/models as a reachability + key check. Returns [ok, httpCode]. */
    public static function check(array $prov)
    {
        list($code) = self::models($prov);
        return [$code >= 200 && $code < 300, $code];
    }

    /**
     * The model to use: the configured one, else the first model the endpoint lists.
     * Hermes needs an explicit model; an OpenAI-compatible endpoint names its models in /models.
     * $ids receives the listed ids when /models was asked, so a caller can reuse that one request.
     */
    public static function model(array $prov, &$ids = null)
    {
        if ($prov['model'] !== '') {
            return $prov['model'];
        }
        list(, $ids) = self::models($prov);
        return $ids ? $ids[0] : '';
    }

    /**
     * Credits left on the key, from GET {endpoint}/balance (the platform proxy's route; its `balance` is already net of
     * running requests). null when the endpoint has no such route or answers anything else, so other providers just
     * show no line.
     */
    public static function balance(array $prov)
    {
        $r = self::balanceAnswer($prov);
        if ($r === null) {
            return null;
        }
        $data = json_decode($r['body'], true);
        return $r['code'] === 200 && isset($data['balance']) && is_int($data['balance']) ? $data['balance'] : null;
    }

    /** The raw Http::request answer of GET {endpoint}/balance (the credits tool reads every field); null, without a request, when the endpoint or key is missing. */
    public static function balanceAnswer(array $prov)
    {
        return self::routeAnswer($prov, '/balance');
    }

    /**
     * The raw Http::request answer of GET {endpoint}{$route} with the key; null, without a request, when the endpoint
     * or key is missing. For short read-only calls with their own timeout (the credits line uses 2 seconds).
     */
    public static function routeAnswer(array $prov, $route, $timeout = 10, $max = 65536)
    {
        if ($prov['endpoint'] === '' || $prov['key'] === '') {
            return null;
        }
        return Http::request('GET', $prov['endpoint'] . $route, ['timeout' => (int) $timeout, 'owners' => 'system', 'max' => (int) $max, 'headers' => ['Authorization: Bearer ' . $prov['key']]]);
    }

    /** GET {endpoint}/models. Returns [httpCode, model ids]. */
    public static function models(array $prov)
    {
        if ($prov['endpoint'] === '' || $prov['key'] === '') {
            return [0, []];
        }
        // The provider endpoint may be a local service run by another account (root or a service account, never another customer).
        $r = Http::request('GET', $prov['endpoint'] . '/models', ['timeout' => 10, 'owners' => 'system', 'headers' => ['Authorization: Bearer ' . $prov['key']]]);
        $code = $r['code'];
        $ids = [];
        $data = json_decode($r['body'], true);
        if ($code >= 200 && $code < 300 && isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $m) {
                if (isset($m['id']) && is_string($m['id']) && $m['id'] !== '') {
                    $ids[] = $m['id'];
                }
            }
        }
        return [$code, $ids];
    }

    /** Generic words that mark a smaller, cheaper variant in a model id; never a vendor or product name (AGENTS.md). */
    const LIGHT_MARKERS = ['mini', 'small', 'lite', 'light', 'nano', 'tiny', 'fast', 'flash', 'instant', 'air'];

    /** A marker word between separators, or a size tag of 30b or less ("-7b", "8b"). */
    public static function isLight($id)
    {
        $id = strtolower((string) $id);
        if (preg_match('/(^|[^a-z])(' . implode('|', self::LIGHT_MARKERS) . ')($|[^a-z])/', $id)) {
            return true;
        }
        preg_match_all('/(?<![a-z0-9.])(\d+(?:\.\d+)?)b(?![a-z0-9])/', $id, $m);
        foreach ($m[1] as $size) {
            if ((float) $size <= 30) {
                return true;
            }
        }
        return false;
    }

    /**
     * The model hooks use: hooks.model if set; else the agent's model if it is already a light one;
     * else from /models the first light id named "<main>-..."; else the first light id with the same
     * vendor prefix (text before the first "/"); else the agent's model. $prov['model'] is the agent's
     * resolved model; $ids the /models list when the caller already has it (null: asked here, once).
     */
    public static function lightModel(array $prov, array $config, $ids = null)
    {
        if (isset($config['hooks']['model']) && is_string($config['hooks']['model']) && $config['hooks']['model'] !== '') {
            return $config['hooks']['model'];
        }
        $main = (string) $prov['model'];
        if ($main === '' || self::isLight($main)) {
            return $main;
        }
        if ($ids === null) {
            list(, $ids) = self::models($prov);
        }
        $slash = strpos($main, '/');
        foreach ([$main . '-', $slash === false ? null : substr($main, 0, $slash + 1)] as $prefix) {
            foreach ($prefix === null ? [] : (array) $ids as $id) {
                if (strpos($id, $prefix) === 0 && self::isLight($id)) {
                    return $id;
                }
            }
        }
        return $main;
    }

    public static function llmFile()
    {
        return Config::localDir() . '/llm.json';
    }

    /**
     * Decide the light model and write local/llm.json {main, light, decidedAt} (0600, temp + rename).
     * Called while generating; a decision under a day old for the same model is kept, so a configured
     * model costs at most one /models request a day. Failures are silent: hooks then use the main model.
     */
    public static function writeLight(array $prov, array $config, $ids = null, $file = null)
    {
        $file = $file ?: self::llmFile();
        $old = is_file($file) && !is_link($file) ? json_decode((string) file_get_contents($file), true) : null;
        $override = isset($config['hooks']['model']) ? (string) $config['hooks']['model'] : '';
        if ($ids === null && $override === '' && isset($old['main'], $old['light'], $old['decidedAt']) && $old['main'] === $prov['model']
            && is_int($old['decidedAt']) && $old['decidedAt'] > time() - 86400) {
            return $old['light'];
        }
        $light = self::lightModel($prov, $config, $ids);
        $tmp = $file . '.tmp-' . getmypid();
        $body = json_encode(['main' => $prov['model'], 'light' => $light, 'decidedAt' => time()]) . "\n";
        if (!(@file_put_contents($tmp, $body) === strlen($body) && @chmod($tmp, 0600) && @rename($tmp, $file))) {
            @unlink($tmp);
        }
        return $light;
    }
}
