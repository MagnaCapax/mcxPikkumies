<?php
/**
 * credits: the AI credits left on this account's provider key, from GET {endpoint}/balance (ADR-0015 tool unit).
 * The tool reads the key so the agent never has to: the key goes only into the request's Authorization header and is
 * never printed, not even masked; key_from names where it came from. Origin: in a live test the agent, with no tool
 * for "how many credits do I have left?", searched the home for its own key and called the balance route itself.
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Config.php";
require_once "$lib/Provider.php";
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";
require_once __DIR__ . "/CreditsLine.php";

// Test seam: a test may set this before including the file, so the checkout's real local/config.php is never read.
$creditsConfig = isset($creditsConfig) ? $creditsConfig : Config::load();
// The same seam for the line's cache directory (local/state/credits/).
$creditsStateDir = isset($creditsStateDir) ? $creditsStateDir : CreditsLine::stateDir();

$creditsKeyHint = 'a key comes from ~/.virsilipasKey (the Pulsed Media AI key; also shown in the Pulsed Media client area on the page'
    . ' "Virsilipas AI key" under "AI credits") or from ~/.pikkumiesKey for your own provider. Never read or print the key itself';

$usage = <<<'U'
usage: php credits.php balance [--human] | line [--tmux] [--ascii] [--jsonl]
  balance  credits left on this account's AI key, what is reserved and running, the key's limits, the last minute's use
  line     one line: credits left, a bar of what is left of the newest grant, an estimate of the tokens it buys, today's use;
           --jsonl prints the record instead, --tmux the tmux status form. Prints nothing when credits cannot be read
U;

Cli::run($argv, [
    'balance' => function () use ($creditsConfig, $creditsKeyHint) {
        $prov = Provider::resolve($creditsConfig);
        if ($prov['key'] === '') {
            throw new CliError('no AI key found for this account; no request was made', $creditsKeyHint);
        }
        if ($prov['endpoint'] === '') {
            throw new CliError('the AI key has no endpoint; no request was made', 'the key file needs an endpoint= line, or set provider.endpoint in ~/pikkumies/local/config.php');
        }
        $host = (string) parse_url($prov['endpoint'], PHP_URL_HOST);
        $r = Provider::balanceAnswer($prov);
        // The answer's body is never quoted in an error: a server could echo the Authorization header back.
        if ($r['code'] === 404) {
            throw new CliError("the AI endpoint ($host) has no balance route, so it cannot report credits", 'only the Pulsed Media AI key reports credits here; ' . $creditsKeyHint . '. With your own provider, its own account page shows the balance');
        }
        if ($r['code'] === 401 || $r['code'] === 403) {
            throw new CliError("the AI endpoint ($host) refused the key (HTTP {$r['code']}): it may have been replaced or removed", $creditsKeyHint);
        }
        if ($r['code'] === 0) {
            throw new CliError("could not reach the AI endpoint ($host): " . $r['error'], 'try again in a minute; if it persists, the provider or this server\'s network is down');
        }
        $data = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !is_array($data) || !isset($data['balance']) || !is_int($data['balance'])) {
            throw new CliError("the AI endpoint ($host) gave no balance (HTTP {$r['code']})", 'try again in a minute');
        }
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
            'endpoint_host' => $host,
        ]];
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
], $usage, ['tmux', 'ascii']);
