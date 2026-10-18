<?php
/**
 * credits: the AI credits left on this account's provider key, from GET {endpoint}/balance (ADR-0015 tool unit); where they
 * went and which models there are, from GET /usage and GET /models (ADR-0050); the credits line (ADR-0040).
 * The tool reads the key so the agent never has to: the key goes only into the request's Authorization header and is
 * never printed, not even masked; key_from names where it came from. Origin: in a live test the agent, with no tool
 * for "how many credits do I have left?", searched the home for its own key and called the balance route itself.
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Config.php";
require_once "$lib/Provider.php";
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";
require_once __DIR__ . "/CreditsUsage.php";

// Test seam: a test may set this before including the file, so the checkout's real local/config.php is never read.
$creditsConfig = isset($creditsConfig) ? $creditsConfig : Config::load();
// The same seam for the line's cache directory (local/state/credits/).
$creditsStateDir = isset($creditsStateDir) ? $creditsStateDir : CreditsLine::stateDir();

$creditsKeyHint = 'a key comes from ~/.virsilipasKey (the Pulsed Media AI key; also shown in the Pulsed Media client area on the page'
    . ' "Virsilipas AI key" under "AI credits") or from ~/.pikkumiesKey for your own provider. Never read or print the key itself';

$usage = <<<'U'
usage: php credits.php balance [--human] | usage [--days N] [--human] | models [--human] | line [--tmux] [--ascii] [--jsonl]
  balance  credits left on this account's AI key, what is reserved and running, the key's limits, the last minute's use
  usage    where the credits went: the balance, totals, then per model (most credits first) and per day, for the last
           --days days (1 to 400, default 30); more rows with --offset N or --limit
  models   the models the endpoint offers, with the rates and descriptions it sends, for choosing a model
  line     one line: credits left, a bar of what is left of the newest grant, the least number of tokens it buys, today's use;
           --jsonl prints the record instead, --tmux the tmux status form. Prints nothing when credits cannot be read
U;

Cli::run($argv, [
    'balance' => function () use ($creditsConfig, $creditsKeyHint) {
        $prov = Provider::resolve($creditsConfig);
        $data = CreditsUsage::get($prov, '/balance', 'balance', $creditsKeyHint);
        $int = function ($a, $k) {
            return is_array($a) && isset($a[$k]) && is_int($a[$k]) ? $a[$k] : null;
        };
        $limits = isset($data['limits']) ? $data['limits'] : null;
        $window = isset($data['window']) ? $data['window'] : null;
        return [[
            'balance'     => $data['balance'],
            'reserved'    => $int($data, 'reserved'),
            'in_flight'   => $int($data, 'in_flight'),
            'limits'      => [
                'max_concurrent'      => $int($limits, 'max_concurrent'),
                'requests_per_minute' => $int($limits, 'requests_per_minute'),
                'credits_per_minute'  => $int($limits, 'credits_per_minute'),
            ],
            // The API calls this `window`; it is the last 60 seconds only, never a day or a month.
            'last_minute' => ['requests' => $int($window, 'requests'), 'credits' => $int($window, 'credits')],
            'key_from'    => getenv('PIKKUMIES_KEY') ? 'PIKKUMIES_KEY' : Provider::keyFile($creditsConfig['provider'])['path'],
            'endpoint_host' => (string) parse_url($prov['endpoint'], PHP_URL_HOST),
        ]];
    },
    // Where the credits went (ADR-0050): GET /usage for the last --days days, by model and by day.
    'usage' => function ($args, $opt) use ($creditsConfig, $creditsKeyHint) {
        $days = CreditsUsage::days($opt);
        $prov = Provider::resolve($creditsConfig);
        return CreditsUsage::usageRecords(CreditsUsage::get($prov, "/usage?days=$days", 'usage', $creditsKeyHint, 1048576), $days);
    },
    // The models the endpoint offers, with its own rates and descriptions, for choosing one (ADR-0050).
    'models' => function () use ($creditsConfig, $creditsKeyHint) {
        return CreditsUsage::modelRecords(CreditsUsage::get(Provider::resolve($creditsConfig), '/models', 'models', $creditsKeyHint, 1048576));
    },
    // Prints its own output and exits 0 always: the tmux status line and the start banner show nothing rather than an error.
    'line' => function ($args, $opt) use ($creditsConfig, $creditsStateDir) {
        try {
            $record = CreditsLine::record(Provider::resolve($creditsConfig), $creditsStateDir);
            if ($record !== null) {
                $utf8 = CreditsFormat::utf8Locale();
                $mode = !empty($opt['tmux']) ? 'tmux' : (!empty($opt['ascii']) || !$utf8 ? 'ascii' : 'utf8');
                echo !empty($opt['jsonl']) ? Cli::json($record) : CreditsFormat::render($record, $mode, $utf8 && empty($opt['ascii'])), "\n";
            }
        } catch (Throwable $e) {
            // silent by design (ADR-0040)
        }
        exit(0);
    },
], $usage, ['tmux', 'ascii'], ['days']);
