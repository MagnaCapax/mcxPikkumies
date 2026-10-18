<?php
/**
 * TranscribeSecret: the cloud transcription endpoint, model and key, typed once by the user at a
 * terminal (ADR-0019, ADR-0045), the same pattern as tools/arr/autobrr/AutobrrSecret.php: never in
 * the chat, never on a command line. Stored as one SecretFile JSON at local/secrets/transcription
 * (directory 0700, file 0600). There is no endpoint/model/key setting in local/config.php: the
 * agent can change config, but the guard keeps it away from local/secrets, so binding endpoint and
 * key together in the secret stops a redirected key reaching someone else's endpoint.
 */
class TranscribeSecret
{
    private $file;
    private $uid;

    /** $uid: the owner the file must have (a test seam; this account's uid by default). */
    public function __construct($dir, $uid = null)
    {
        $this->file = rtrim($dir, '/') . '/transcription';
        $this->uid = $uid;
    }

    public function path()
    {
        return $this->file;
    }

    /** ['endpoint', 'model', 'key'], or null when nothing is stored, or any field is missing/not a string. */
    public function load()
    {
        $raw = SecretFile::read($this->file, 'the user runs `php ' . dirname(__FILE__) . '/transcribe.php login` in a terminal', $this->uid);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || !isset($d['endpoint'], $d['model'], $d['key']) || !is_string($d['endpoint'])
            || !is_string($d['model']) || !is_string($d['key'])) {
            return null;
        }
        return ['endpoint' => $d['endpoint'], 'model' => $d['model'], 'key' => $d['key']];
    }

    public function save($endpoint, $model, $key)
    {
        SecretFile::write($this->file, ['endpoint' => $endpoint, 'model' => $model, 'key' => $key], 'transcription endpoint, model and key');
    }

    /**
     * Prompts for endpoint (https required unless it is this account's own local listener, checked by
     * Http::foreignLocalListener before anything is stored), model name (required, no default) and
     * the API key (hidden). Throws when any required value is missing, or the endpoint is refused.
     */
    public function promptAndSave($prompt = ['SecretFile', 'prompt'])
    {
        $endpoint = trim((string) call_user_func($prompt, 'endpoint (OpenAI-compatible base URL, e.g. https://host/v1): ',
            'login reads these from a terminal, and stdin is not one', 'the user runs `php ' . dirname(__FILE__) . '/transcribe.php login` in their own terminal',
            false));
        if ($endpoint === '') {
            throw new CliError('no endpoint typed; nothing stored', 'run login again');
        }
        if (strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== 'https' && !self::looksLocal($endpoint)) {
            // Only the scheme is checked here; whether a local listener is actually this account's own is
            // Http::request's job every time it is called (pikkumies/lib/Http.php, ADR-0019) — not re-checked here.
            throw new CliError("https is required unless the endpoint is this account's own local listener; nothing stored",
                'use an https:// endpoint, or your own 127.0.0.1 listener');
        }
        $model = trim((string) call_user_func($prompt, 'model name (required): ',
            'login reads these from a terminal, and stdin is not one', 'the user runs `php ' . dirname(__FILE__) . '/transcribe.php login` in their own terminal',
            false));
        if ($model === '') {
            throw new CliError('no model name typed; nothing stored', 'run login again');
        }
        $key = call_user_func($prompt, 'API key (hidden): ', 'login reads these from a terminal, and stdin is not one',
            'the user runs `php ' . dirname(__FILE__) . '/transcribe.php login` in their own terminal', true);
        if ($key === '') {
            throw new CliError('no API key typed; nothing stored', 'run login again');
        }
        $this->save($endpoint, $model, $key);
        return ['stored' => true, 'endpoint' => $endpoint, 'model' => $model];
    }

    /**
     * A textual loopback check only, on the exact host: `localhost`, `::1`, or a literal IPv4 address in
     * 127.0.0.0/8. A name that merely starts or contains these (127.0.0.1.example.org,
     * localhost.example.org) is a remote host and needs https. Ownership is Http's own job.
     */
    private static function looksLocal($url)
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        return $host === 'localhost' || $host === '::1'
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && strpos($host, '127.') === 0);
    }
}
