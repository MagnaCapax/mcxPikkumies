<?php
/**
 * ConfigureModel: a model id is checked against the endpoint's own model list before provider.model or hooks.model is
 * written (ADR-0059). A model the endpoint does not serve would break every next start (or silently switch the hooks'
 * judges off), so the check fails closed: no endpoint, no key, no answer or no such id refuses the change. Runs in the
 * tool after Hermes' approval, never in the hook, which has seconds to answer. The rate shown is the endpoint's own
 * credits_per_million_tokens (ADR-0050), when it sends one.
 */
require_once dirname(__DIR__, 3) . '/lib/Provider.php';
require_once dirname(__DIR__) . '/credits/CreditsFormat.php';

class ConfigureModel
{
    /** The model's rates (CreditsFormat::rate) or null when the endpoint sends none; a CliError when it is not served. */
    public static function check(array $config, $key, $model)
    {
        $prov = Provider::resolve($config);
        $r = Provider::routeAnswer($prov, '/models', 10, 1048576);
        $host = (string) parse_url($prov['endpoint'], PHP_URL_HOST);
        if ($r === null) {
            throw new CliError("$key: no AI endpoint and key to check $model against; nothing was changed", 'the person sets up the AI key first');
        }
        $data = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            $why = $r['code'] === 0 ? 'could not reach' : "got no model list (HTTP {$r['code']}) from";
            throw new CliError("$key: $why the AI endpoint ($host), so $model could not be checked; nothing was changed", 'try again in a minute');
        }
        foreach ($data['data'] as $m) {
            if (is_array($m) && isset($m['id']) && $m['id'] === $model) {
                return CreditsFormat::rate($m);
            }
        }
        throw new CliError("$key: the AI endpoint ($host) does not list the model $model; nothing was changed", 'the models it offers: php ~/pikkumies/tools/system/credits/credits.php models');
    }
}
