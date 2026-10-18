<?php
/**
 * TranscribeCloud: one call to an OpenAI-compatible `<endpoint>/audio/transcriptions` route
 * (ADR-0045), through pikkumies/lib/Http.php's multipart support. Never retries itself: every
 * non-success outcome is handed back with a reason, and TranscribeRun decides whether to fall back
 * to the local engine.
 */
class TranscribeCloud
{
    const TIMEOUT = 120; // one segment or a short file; TranscribeRun splits anything larger

    /**
     * POSTs $file (already the speech-16k conversion, or one ~150 s segment of it) with fields
     * model, file, response_format=json and, when given, language. Returns ['ok' => bool, 'text' =>
     * string, 'authError' => bool, 'reason' => string (set when !ok)].
     */
    public static function transcribe($endpoint, $model, $key, $file, $language = null)
    {
        $url = rtrim($endpoint, '/') . '/audio/transcriptions';
        $fields = ['model' => $model, 'file' => new CURLFile($file), 'response_format' => 'json'];
        if ($language !== null && $language !== '') {
            $fields['language'] = $language;
        }
        $r = Http::request('POST', $url, ['fields' => $fields, 'headers' => ['Authorization: Bearer ' . $key], 'timeout' => self::TIMEOUT]);
        if ($r['code'] === 401 || $r['code'] === 403) {
            return ['ok' => false, 'text' => '', 'authError' => true, 'reason' => 'the stored key was refused; run login again'];
        }
        if ($r['code'] !== 200) {
            $why = $r['code'] === 0 ? $r['error'] : 'the endpoint answered HTTP ' . $r['code'];
            return ['ok' => false, 'text' => '', 'authError' => false, 'reason' => $why];
        }
        $data = json_decode($r['body'], true);
        if (!is_array($data) || !isset($data['text']) || !is_string($data['text'])) {
            return ['ok' => false, 'text' => '', 'authError' => false, 'reason' => 'the endpoint answered 200 with no text field'];
        }
        return ['ok' => true, 'text' => $data['text'], 'authError' => false, 'reason' => ''];
    }
}
