<?php
/** tools/seedbox/plans/catalog (ADR-0111): a stand-in feed through CatalogFeed; the verbs contract is checked with every unit. */
require_once dirname(__DIR__) . '/pikkumies/lib/Cli.php';
require_once dirname(__DIR__) . '/pikkumies/tools/seedbox/plans/catalog/CatalogFeed.php';

function catalogFeed()
{
    return ['generated_at' => '2026-11-05T10:00:00+00:00', 'products' => [
        ['id' => 1, 'name' => 'M10G Storage 16TB', 'description' => 'Storage: 16TB<br />RAID5 &amp; 10G', 'stock' => -3, 'stock_managed' => true, 'available' => true, 'billing_cycles' => ['monthly' => 26.99]],
        ['id' => 2, 'name' => 'M10G M', 'description' => '4TB plan', 'stock' => 30, 'stock_managed' => true, 'available' => true, 'billing_cycles' => ['monthly' => 12.99, 'annually' => 148.09], 'specs' => ['ram_mib' => 4000, 'disk_gib' => 3640, 'traffic_gib_month' => 20000, 'cpu' => 'Shared']],
        ['id' => 3, 'name' => 'M10G M Old', 'description' => '', 'stock' => null, 'stock_managed' => false, 'available' => false, 'billing_cycles' => ['annually' => 99.0]],
    ]];
}

return [
    'catalog: the exact name comes first, with every cycle, stock, specs and the order link' => function () {
        $r = CatalogFeed::find(catalogFeed()['products'], ['M10G', 'M']);
        check($r[0]['name'] === 'M10G M' && count($r) === 3, 'order: ' . json_encode($r));
        check($r[0]['billing_cycles']['annually'] === 148.09 && $r[0]['stock'] === 30 && $r[0]['disk_gib'] === 3640, json_encode($r[0]));
        check($r[0]['order_url'] === 'https://pulsedmedia.com/clients/cart.php?a=add&pid=2' && !isset($r[0]['description']) && !isset($r[0]['cpu']), json_encode($r[0]));
    },
    'catalog: a plan with no specs shows its own description without tags, and invents no figure' => function () {
        $r = CatalogFeed::find(catalogFeed()['products'], ['16TB'])[0];
        check($r['description'] === 'Storage: 16TB RAID5 & 10G' && !isset($r['disk_gib']), json_encode($r));
    },
    'catalog: words match the description too, and a word missing anywhere drops the plan' => function () {
        check(count(CatalogFeed::find(catalogFeed()['products'], ['raid5'])) === 1, 'description match');
        check(CatalogFeed::find(catalogFeed()['products'], ['M10G', 'nosuchword']) === [], 'all words must match');
    },
    'catalog: a stock marker below zero and an uncounted stock are not shown as a count' => function () {
        $p = catalogFeed()['products'];
        check(!isset(CatalogFeed::record($p[0])['stock']) && !isset(CatalogFeed::record($p[2])['stock']), 'no stock field');
    },
    'catalog: list --available keeps plans with no specs and drops those not on sale' => function () {
        $rows = CatalogFeed::rows(catalogFeed()['products'], true);
        check(array_column($rows, 'id') === [1, 2] && $rows[0]['monthly'] === 26.99, json_encode($rows));
        $all = CatalogFeed::rows(catalogFeed()['products'], false);
        check(count($all) === 3 && !isset($all[2]['monthly']) && $all[2]['available'] === false, 'a plan with no monthly price stays listed: ' . json_encode($all));
    },
    'catalog: the status carries the feed age and that prices exclude VAT' => function () {
        $s = CatalogFeed::status(catalogFeed(), strtotime('2026-11-05T10:30:30+00:00'));
        check($s['age_minutes'] === 30 && $s['prices_exclude_vat'] === true && $s['generated_at'] === '2026-11-05T10:00:00+00:00', json_encode($s));
        check(CatalogFeed::status(['products' => []], 0)['age_minutes'] === null, 'no date, no age');
    },
    'catalog: a failed fetch, an error status and a non-feed answer refuse, with no data' => function () {
        foreach ([['code' => 0, 'body' => '', 'error' => 'timed out'], ['code' => 503, 'body' => '{}', 'error' => ''], ['code' => 200, 'body' => '<html>', 'error' => ''], ['code' => 200, 'body' => '{"a":1}', 'error' => '']] as $answer) {
            try {
                CatalogFeed::parse($answer);
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'give no price') !== false, $e->getMessage());
                continue;
            }
            throw new Exception('must refuse: ' . json_encode($answer));
        }
        check(count(CatalogFeed::parse(['code' => 200, 'body' => json_encode(catalogFeed()), 'error' => ''])['products']) === 3, 'a good feed parses');
    },
];
