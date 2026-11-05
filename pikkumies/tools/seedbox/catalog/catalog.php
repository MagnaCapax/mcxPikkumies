<?php
/** catalog: Pulsed Media's plans, prices, stock and specs from the public feed (ADR-0111). Reads only; orders nothing. */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Cli.php";
require_once "$lib/Http.php";
require_once __DIR__ . '/CatalogFeed.php';

$usage = <<<'U'
usage: php catalog.php find <words> [--human] | list [--available] [--human]
  find  plans with all the words in the name or description: prices per billing cycle, stock, specs, order link
  list  every plan in short; --available only those on sale
U;

$catalogRead = function () {
    return CatalogFeed::parse(Http::request('GET', CatalogFeed::URL, ['agent' => 'pikkumies-catalog']));
};

Cli::run($argv, [
    'find' => function ($args) use ($catalogRead) {
        if (!$args) {
            throw new CliError('find needs at least one word', 'for example: find M10G M');
        }
        $doc = $catalogRead();
        return ['records' => CatalogFeed::find($doc['products'], $args), 'status' => CatalogFeed::status($doc, time())];
    },
    'list' => function ($args, $opt) use ($catalogRead) {
        $doc = $catalogRead();
        return ['records' => CatalogFeed::rows($doc['products'], !empty($opt['available'])), 'status' => CatalogFeed::status($doc, time())];
    },
], $usage, ['available']);
