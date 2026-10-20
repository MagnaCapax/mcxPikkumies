<?php
/**
 * /feedback's outside work (ADR-0056): the current conversation from Hermes' own export, sealed sending to Pulsed Media.
 * Only the Pulsed Media AI key (~/.virsilipasKey, a vsk- key) is ever sent, never a key for the user's own provider.
 */
class FeedbackSend
{
    const ENDPOINT = 'https://pikkumies.mcx.fi/v1/feedback';

    /**
     * The current session, exported by `hermes sessions export --format md --redact --lineage logical` into a fresh
     * directory of its own (mode 0700, inside the user's home, never a shared /tmp), read once, deleted, and
     * returned as exported: FeedbackDraft::create cuts it to size and runs Redact::secrets on it (Hermes' redaction misses
     * bare vsk- keys). The session comes from HERMES_SESSION_ID, which Hermes sets for every command it runs; a command
     * can set it too, so the draft and the message the guard checks show which session is attached.
     */
    public static function transcript($stateDir = null)
    {
        $sid = (string) getenv('HERMES_SESSION_ID');
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $sid)) {
            throw new CliError('no current Pikkumies session to attach', 'run /feedback from a Pikkumies chat, or add --no-conversation');
        }
        $bin = HermesInstall::bin();
        if ($bin === '') {
            throw new CliError('Hermes is not installed here, so the conversation cannot be exported', 'add --no-conversation');
        }
        $dir = FeedbackDraft::dir($stateDir) . '/export-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true)) {
            throw new CliError('could not make a private export directory', 'check the free disk space');
        }
        try {
            $r = ToolProc::run([$bin, 'sessions', 'export', $dir, '--format', 'md', '--session-id', $sid, '--redact', '--lineage', 'logical'],
                HermesConfig::env());
            $md = glob("$dir/*.md") ?: [];
            if ($r['code'] !== 0 || count($md) !== 1) {
                throw new CliError('Hermes did not export this conversation', 'add --no-conversation to send the text alone');
            }
            return (string) file_get_contents($md[0]); // redacted by FeedbackDraft::create after it is cut to size
        } finally {
            foreach (glob("$dir/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /** The session title from the export's frontmatter (`title: "..."`), one line of at most 120 bytes, secrets removed. */
    public static function title($md)
    {
        if (!preg_match('/^title: (".*")$/m', substr((string) $md, 0, 4096), $m) || !is_string($t = json_decode($m[1]))) {
            return '';
        }
        $t = Redact::secrets(trim((string) preg_replace('/\s+/u', ' ', $t)));
        return function_exists('mb_strcut') ? mb_strcut($t, 0, 120, 'UTF-8') : substr($t, 0, 120);
    }

    /** Export directories a killed draft left behind (finally never ran): removed once they are 10 minutes old. */
    public static function sweepExports($stateDir = null, $now = null)
    {
        foreach (glob(FeedbackDraft::dir($stateDir) . '/export-*', GLOB_ONLYDIR) ?: [] as $d) {
            if (!is_link($d) && filemtime($d) < ($now === null ? time() : $now) - 600) {
                foreach (array_merge(glob("$d/*") ?: [], glob("$d/.[!.]*") ?: []) as $f) {
                    @unlink($f);
                }
                @rmdir($d);
            }
        }
    }

    /** Version numbers only: Pikkumies' commit, the Hermes tag, and the PMSS version when this is a Pulsed Media box. */
    public static function versions()
    {
        $v = ['pikkumies' => trim(implode('', Update::git(['rev-parse', '--short', 'HEAD'], $code)))];
        if ($code !== 0) {
            $v['pikkumies'] = '';
        }
        $src = HermesInstall::sourceDir();
        $v['hermes'] = $src !== '' && is_file("$src/pyproject.toml") && preg_match('/^version\s*=\s*"([^"]+)"/m', (string) file_get_contents("$src/pyproject.toml"), $m)
            ? $m[1] : '';
        $pmss = @file_get_contents('/etc/seedbox/config/version');
        $v['pmss'] = $pmss === false ? '' : substr(trim($pmss), 0, 64);
        return $v;
    }

    /** The Pulsed Media AI key (Provider::pulsedMediaKey: only from ~/.virsilipasKey, and only a vsk- key). */
    public static function pmKey(array $config)
    {
        $key = Provider::pulsedMediaKey($config);
        if ($key === '') {
            throw new CliError('feedback goes to Pulsed Media and needs the Pulsed Media AI key (~/.virsilipasKey)',
                'your draft is kept; it is sent only with a Pulsed Media key, never with another provider\'s key');
        }
        return $key;
    }

    /**
     * Seals the payload to Pulsed Media's public key and posts it, within $timeout seconds (the exit rating gives it 5, so
     * closing Pikkumies never hangs). @return array the server's receipt (id, received_at)
     */
    public static function send($json, $key, $publicKeyFile, $endpoint = self::ENDPOINT, $timeout = 60)
    {
        if (!function_exists('sodium_crypto_box_seal')) {
            throw new CliError('this PHP has no sodium extension, which feedback needs to lock the message',
                'your draft is kept; install the PHP sodium extension and send it again');
        }
        $lines = preg_grep('/^[A-Za-z0-9+\/]{43}=$/', array_map('trim', (array) @file($publicKeyFile)));
        $pub = $lines ? base64_decode((string) reset($lines), true) : false;
        if (!is_string($pub) || strlen($pub) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new CliError('the Pulsed Media feedback key in this Pikkumies is missing or damaged', 'run pikkumies update; your draft is kept');
        }
        $sealed = sodium_crypto_box_seal($json, $pub);
        $r = Http::request('POST', $endpoint, ['body' => $sealed, 'timeout' => (int) $timeout, 'max' => 65536,
            'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/octet-stream']]);
        $answer = json_decode($r['body'], true);
        if ($r['code'] === 201 && is_array($answer) && isset($answer['id']) && is_string($answer['id'])) {
            return ['received_id' => $answer['id'], 'received_at' => isset($answer['received_at']) ? (int) $answer['received_at'] : 0];
        }
        $msg = is_array($answer) && isset($answer['message']) && is_string($answer['message']) ? Cli::cleanString($answer['message']) : '';
        $what = $r['code'] === 0 ? 'could not reach Pulsed Media (' . $r['error'] . ')' : "Pulsed Media did not take it (HTTP {$r['code']})";
        $final = $r['code'] >= 400 && $r['code'] < 500 && $r['code'] !== 429;
        throw new CliError($what . ($msg !== '' ? ": $msg" : ''), $final
            ? 'your draft is kept, but sending it again will not help; tell the user what Pulsed Media answered'
            : 'your draft is kept; send it again later with the same send command');
    }
}
