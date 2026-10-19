<?php
/**
 * Web search is opt-in (ADR-0082, keeping ADR-0021's decision): off by default, so no backend is named and nothing the
 * agent searches for leaves the machine; the person's `pikkumies websearch on` pins ddgs in the managed Hermes config,
 * `off` takes it out; the agent can never turn it on and is told the one command that does.
 */

if (!function_exists('userCfgWorld')) {
    require __DIR__ . '/HermesUserConfigTest.php'; // userCfgWorld() and friends, when this file runs alone
}

/** Render both Hermes config layers for $config. */
function webSearchLayers(array $config)
{
    $hooks = ['pre_tool_call' => ['command' => 'x', 'failClosed' => true, 'timeout' => 5]];
    $prov = ['endpoint' => 'e', 'model' => 'm', 'key' => 'k'];
    $web = !empty($config['web']['search']);
    return [HermesConfig::renderYaml($prov, ['/a'], $hooks, false, '', false, $web), HermesConfig::renderYaml($prov, [], $hooks, false, '', true, $web)];
}

return [
    'web search: off by default; neither Hermes config layer names a backend, the keyless fallback stays off' => function () {
        check(Config::defaults()['web']['search'] === false, 'web.search must default to off');
        foreach (webSearchLayers(Config::defaults()) as $y) {
            check(strpos($y, 'search_backend') === false && strpos($y, "\n  backend:") === false, 'no backend may be named while off: ' . $y);
            check(strpos($y, "web:\n  keyless_fallback: false\n  keyless_rescue: false\n") !== false, 'ADR-0021 pins stay: ' . $y);
        }
    },
    'web search: `on` writes web.search and the managed layer pins ddgs; `off` takes it out; status follows' => function () {
        $dir = testTempDir('pk-websearch');
        $file = "$dir/config.php";
        try {
            testWithEnv(['PIKKUMIES_DEPTH' => null], function () use ($file) {
                list($ok, $what) = WebSearch::turn(true, $file);
                check($ok && strpos($what, 'DuckDuckGo') !== false, 'on: ' . $what);
                $config = Config::merge(Config::defaults(), require $file);
                check($config['web']['search'] === true, 'on writes web.search true');
                list($seed, $pins) = webSearchLayers($config);
                check(strpos($pins, "web:\n  keyless_fallback: false\n  keyless_rescue: false\n  search_backend: \"ddgs\"\n") !== false, 'on pins ddgs: ' . $pins);
                check(strpos($seed, 'search_backend') === false, 'the seed never names a backend, so off can take it out: ' . $seed);
                check(strpos(WebSearch::status($config), 'web search: on') === 0, WebSearch::status($config));
                list($ok, $what) = WebSearch::turn(false, $file);
                $config = Config::merge(Config::defaults(), require $file);
                check($ok && $config['web']['search'] === false, 'off: ' . $what);
                foreach (webSearchLayers($config) as $y) {
                    check(strpos($y, 'search_backend') === false, 'off removes the backend: ' . $y);
                }
                check(strpos(WebSearch::status($config), 'web search: off') === 0 && strpos(WebSearch::status($config), WebSearch::COMMAND) !== false, WebSearch::status($config));
            });
        } finally {
            testRmTree($dir);
        }
    },
    'web search: the agent cannot turn it on (Config::set refuses it, the verb refuses it from inside an agent); off is free' => function () {
        $dir = testTempDir('pk-websearch-agent');
        $file = "$dir/config.php";
        try {
            $refused = false;
            try {
                Config::set('web.search', true, $file);
            } catch (InvalidArgumentException $x) {
                $refused = strpos($x->getMessage(), "person's") !== false;
            }
            check($refused && !is_file($file), 'Config::set must refuse turning web search on, for every caller');
            check(ConfigureTiers::classify('web.search', false, true) === ConfigureTiers::PERSON && ConfigureTiers::classify('web.search', true, false) === ConfigureTiers::FREE, 'tiers');
            testWithEnv(['PIKKUMIES_DEPTH' => '1'], function () use ($file) {
                list($ok, $what) = WebSearch::turn(true, $file);
                check(!$ok && !is_file($file) && strpos($what, 'not from inside an agent') !== false, 'inside an agent: ' . $what);
            });
            Config::set('web.search', false, $file);
            check((require $file)['web']['search'] === false, 'off is the agent\'s to make');
        } finally {
            testRmTree($dir);
        }
    },
    'web search: SOUL.md tells the agent it is off and names the one command, never to turn it on itself; on says where queries go' => function () {
        $profile = ['root' => ['available' => false], 'pmss' => false, 'family' => 'generic'];
        $off = HermesConfig::capabilities($profile, Config::defaults());
        check(strpos($off, '- Web search: off') !== false && strpos($off, '`' . WebSearch::COMMAND . '`') !== false && strpos($off, 'never turn it on yourself') !== false, $off);
        $on = Config::defaults();
        $on['web']['search'] = true;
        check(strpos(HermesConfig::capabilities($profile, $on), '- Web search: on; each web_search sends its query to DuckDuckGo') !== false, 'on');
        $skill = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/research/deep-research/skill.md');
        check(strpos($skill, '`' . WebSearch::COMMAND . '`') !== false, 'the research skill names the command');
    },
    'web search: routing; `websearch on|off|status` is the verb, other words are a prompt' => function () {
        foreach (['on', 'off', 'status'] as $verb) {
            check(Launch::route(['pikkumies', 'websearch', $verb]) === ['websearch', [$verb]], $verb);
        }
        check(Launch::route(['pikkumies', 'websearch', 'cats']) === ['run', ['chat', '--query=websearch cats']], 'a prompt');
    },
    'web search: writeConfig with it on pins ddgs in the managed file only; the user\'s config.yaml and the browser step (ADR-0077) are untouched' => function () {
        $w = userCfgWorld();
        try {
            userCfgIn($w, '', '', function () use ($w) {
                $config = Config::defaults();
                $config['web']['search'] = true;
                HermesConfig::writeConfig($w['home'], userCfgProv(), $config);
                check(strpos((string) file_get_contents($w['local'] . '/hermes-managed/config.yaml'), "search_backend: \"ddgs\"\n") !== false, 'managed pin');
                check(strpos((string) file_get_contents($w['home'] . '/config.yaml'), 'search_backend') === false, 'the user\'s file never gets it');
                check(is_file(HermesUserConfig::browseMarker($w['local'])) && !is_file($w['log']), 'the browser step still completes on its own, no Hermes process');
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                check(strpos((string) file_get_contents($w['local'] . '/hermes-managed/config.yaml'), 'search_backend') === false, 'off: gone at the next start');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
];
