<?php
/**
 * CatalogFeed: the plans in Pulsed Media's public product feed, reduced to what a question needs (ADR-0111).
 * Fetched once per run: stock changes by the minute, so there is no cache and no older copy to fall back on.
 */
class CatalogFeed
{
    const URL = 'https://pulsedmedia.com/data/v1/products.json';
    const ORDER = 'https://pulsedmedia.com/clients/cart.php?a=add&pid=';

    /** The feed from an Http::request answer; a failure refuses, so no price is ever given from nothing. */
    public static function parse(array $answer)
    {
        $doc = $answer['code'] === 200 ? json_decode($answer['body'], true) : null;
        if (!is_array($doc) || !isset($doc['products']) || !is_array($doc['products'])) {
            $why = $answer['code'] === 200 ? 'not a product feed' : ($answer['error'] !== '' ? $answer['error'] : 'HTTP ' . $answer['code']);
            throw new CliError("the plan catalogue could not be read ($why): give no price, stock or spec", 'the plans are listed at https://pulsedmedia.com/');
        }
        return $doc;
    }

    /** All the words in the name or description, the exact name first, then names holding the phrase; nothing is dropped. */
    public static function find(array $products, array $words)
    {
        $phrase = implode(' ', $words);
        $tiers = [[], [], []];
        foreach ($products as $p) {
            $text = $p['name'] . ' ' . self::text($p);
            foreach ($words as $word) {
                if (stripos($text, $word) === false) {
                    continue 2;
                }
            }
            $tier = strcasecmp($p['name'], $phrase) === 0 ? 0 : (stripos($p['name'], $phrase) !== false ? 1 : 2);
            $tiers[$tier][] = self::record($p);
        }
        return array_merge($tiers[0], $tiers[1], $tiers[2]);
    }

    /** One plan. Most plans have no specs block: their own description says it, so no figure is ever made up. */
    public static function record(array $p)
    {
        $r = ['id' => $p['id'], 'name' => $p['name'], 'billing_cycles' => isset($p['billing_cycles']) ? $p['billing_cycles'] : [], 'available' => !empty($p['available'])];
        if (!empty($p['stock_managed']) && isset($p['stock']) && $p['stock'] >= 0) {
            $r['stock'] = $p['stock']; // below zero is the store's internal marker, not a count
        }
        $specs = isset($p['specs']) && is_array($p['specs']) ? array_intersect_key($p['specs'], array_flip(['ram_mib', 'disk_gib', 'traffic_gib_month'])) : [];
        $r += $specs ?: ['description' => self::text($p)];
        $r['order_url'] = self::ORDER . $p['id'];
        return $r;
    }

    /** The short list: id, name, monthly price where there is one, on sale or not. */
    public static function rows(array $products, $availableOnly)
    {
        $rows = [];
        foreach ($products as $p) {
            if ($availableOnly && empty($p['available'])) {
                continue;
            }
            $row = ['id' => $p['id'], 'name' => $p['name']];
            if (isset($p['billing_cycles']['monthly'])) {
                $row['monthly'] = $p['billing_cycles']['monthly'];
            }
            $rows[] = $row + ['available' => !empty($p['available'])];
        }
        return $rows;
    }

    /** What every answer carries: how old the feed is, and that prices exclude VAT. */
    public static function status(array $doc, $now)
    {
        $at = isset($doc['generated_at']) ? strtotime((string) $doc['generated_at']) : false;
        return ['generated_at' => isset($doc['generated_at']) ? $doc['generated_at'] : null,
            'age_minutes' => $at === false ? null : (int) floor(($now - $at) / 60), 'prices_exclude_vat' => true];
    }

    private static function text(array $p)
    {
        $html = isset($p['description']) ? (string) $p['description'] : '';
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)))));
    }
}
