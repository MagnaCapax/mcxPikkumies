<?php
/**
 * HookLlm: the one way a hook asks the model something. Same endpoint and key as the agent
 * (Provider::resolve); the model is the light one decided at generate time (local/llm.json,
 * Provider::lightModel). Hooks never call /models. Returns '' on any error, timeout or when
 * hooks.llm.disabled is set, so a hook stays silent rather than slow or wrong.
 * Callers send only what the user typed in this turn; nothing is logged here.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Provider.php';

class HookLlm
{
    /** One chat completion: the reply text, or '' on any failure. */
    public static function chat(array $messages, $timeout = 6, $maxTokens = 60)
    {
        $config = Config::load();
        if (!empty($config['hooks']['llm']['disabled'])) {
            return '';
        }
        $prov = Provider::resolve($config);
        $model = self::model($prov, $config);
        if ($prov['endpoint'] === '' || $prov['key'] === '' || $model === '') {
            return '';
        }
        $body = json_encode(['model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => 0]);
        // Same rule as Provider::models: the endpoint may be a local service of root or a service account.
        $r = Http::request('POST', $prov['endpoint'] . '/chat/completions', ['timeout' => $timeout, 'max' => 65536, 'owners' => 'system',
            'headers' => ['Authorization: Bearer ' . $prov['key'], 'Content-Type: application/json'], 'body' => $body]);
        $d = $r['code'] >= 200 && $r['code'] < 300 ? json_decode($r['body'], true) : null;
        return isset($d['choices'][0]['message']['content']) && is_string($d['choices'][0]['message']['content']) ? $d['choices'][0]['message']['content'] : '';
    }

    /**
     * hooks.model, else the light model in local/llm.json when it was decided for the current main
     * model, else the main model. The main model is the configured one, else llm.json's own `main`
     * (what Provider::model picked at generate time); never a /models call. A main model changed in
     * local/config.php since the last generate therefore never gets the old main model's light pick.
     */
    public static function model(array $prov, array $config)
    {
        if (isset($config['hooks']['model']) && is_string($config['hooks']['model']) && $config['hooks']['model'] !== '') {
            return $config['hooks']['model'];
        }
        $file = Provider::llmFile();
        $d = is_file($file) && !is_link($file) ? json_decode((string) file_get_contents($file), true) : null;
        $decidedFor = isset($d['main']) && is_string($d['main']) ? $d['main'] : '';
        $main = $prov['model'] !== '' ? $prov['model'] : $decidedFor;
        if ($main !== '' && $decidedFor === $main && isset($d['light']) && is_string($d['light']) && $d['light'] !== '') {
            return $d['light'];
        }
        return $main;
    }
}
