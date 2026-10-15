<?php
/** Provider::lightModel and the local/llm.json writer. Synthetic model ids only (AGENTS.md: provider-agnostic). */
$noEndpoint = ['endpoint' => '', 'key' => '', 'model' => '']; // models() makes no request without an endpoint

return [
    'light model: generic marker words and size tags, between separators only' => function () {
        foreach (['acme/model-9-mini', 'acme/model-9-fast', 'acme/model_small', 'acme/model-7b', 'acme/model-1.5b-chat', 'acme/model-30b-a3b', 'model-9:lite'] as $id) {
            check(Provider::isLight($id), "$id should be light");
        }
        foreach (['acme/model-9', 'acme/model-70b', 'acme/repair-1', 'acme/minimal-9', 'acme/airborne', 'acme/model-9-instruct', 'acme/8x22b'] as $id) {
            check(!Provider::isLight($id), "$id should not be light");
        }
    },
    'light model: override, main already light, main-variant, vendor prefix, none' => function () use ($noEndpoint) {
        $table = [
            // [main, /models ids, hooks.model, expected]
            ['acme/model-9-mini', ['acme/model-9', 'acme/model-9-nano'], '', 'acme/model-9-mini'],
            ['acme/model-9', ['acme/model-9', 'acme/other-small', 'acme/model-9-instruct', 'acme/model-9-mini'], '', 'acme/model-9-mini'],
            ['acme/model-9', ['zeta/tool-mini', 'acme/model-9', 'acme/other-small'], '', 'acme/other-small'],
            ['acme/model-9', ['acme/model-9', 'zeta/tool-mini', 'acme/model-9-instruct'], '', 'acme/model-9'],
            ['model-9', ['model-9', 'other-mini'], '', 'model-9'],
            ['model-9', ['model-9', 'model-9-flash'], '', 'model-9-flash'],
            ['acme/model-9', ['acme/model-9-mini'], 'acme/custom-1', 'acme/custom-1'],
            ['acme/model-9', [], '', 'acme/model-9'],
        ];
        foreach ($table as $row) {
            list($main, $ids, $override, $want) = $row;
            $got = Provider::lightModel(['model' => $main] + $noEndpoint, ['hooks' => ['model' => $override]], $ids);
            check($got === $want, "$main / $override: got $got, want $want");
        }
    },
    'light model: llm.json is written 0600 with main, light and decidedAt; a fresh decision is kept without asking again' => function () use ($noEndpoint) {
        $dir = sys_get_temp_dir() . '/pk-llm-' . getmypid() . '-' . mt_rand();
        @mkdir($dir, 0700);
        $file = "$dir/llm.json";
        $prov = ['model' => 'acme/model-9'] + $noEndpoint;
        check(Provider::writeLight($prov, [], ['acme/model-9', 'acme/model-9-mini'], $file) === 'acme/model-9-mini', 'wrong light model');
        $d = json_decode((string) file_get_contents($file), true);
        check($d['main'] === 'acme/model-9' && $d['light'] === 'acme/model-9-mini' && is_int($d['decidedAt']), 'llm.json content: ' . json_encode($d));
        check((fileperms($file) & 0777) === 0600, 'llm.json is not 0600');
        check(Provider::writeLight($prov, [], null, $file) === 'acme/model-9-mini', 'fresh decision not kept');
        check(Provider::writeLight($prov, ['hooks' => ['model' => 'acme/custom-1']], null, $file) === 'acme/custom-1', 'override ignored');
        check(Provider::writeLight(['model' => 'acme/model-10'] + $noEndpoint, [], null, $file) === 'acme/model-10', 'a changed main model must be decided again');
        check(!glob("$dir/*.tmp-*"), 'temporary file left behind');
        exec('rm -rf ' . escapeshellarg($dir));
    },
];
