<?php
/**
 * upstream: known issues, recent commits and releases of an upstream project on GitHub, and a local issue draft
 * (ADR-0092). search-issues sends the scrubbed words of the problem to GitHub and runs only while the person's
 * web.search switch is on (ADR-0082); issue, commits and releases carry only a public project name. Nothing is ever
 * posted: draft writes a local file and prints the link and the command the user publishes with.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'Host', 'Http', 'Redact', 'ToolProc', 'WebSearch', 'UpstreamScrub', 'UpstreamCache', 'UpstreamLimit', 'Upstream', 'UpstreamFeed'] as $c) {
    require_once "$lib/$c.php";
}
require_once __DIR__ . '/UpstreamGit.php';
require_once __DIR__ . '/UpstreamDraft.php';

$usage = <<<'U'
usage: php upstream.php <verb> <owner/repo> [arguments] [--human]
  search-issues <owner/repo> <words...> [--state open|closed|all] [--limit N]
                known issues matching the problem (scrubbed words go to GitHub; needs `pikkumies websearch on`; N at most 10)
  issue <owner/repo> <number> [--comments]    one issue or pull request, with up to 10 comments
  commits <owner/repo> [--grep WORDS] [--since 90d]   recent commits from a local treeless clone, or the commits feed
  releases <owner/repo>                       the latest releases, or tags when the project makes no releases
  draft <owner/repo> --title T --body-file F  a scrubbed issue draft in local/upstream/drafts/ with a prefilled link
                and the gh command; sends nothing
U;

/** The repository argument, validated, or a usage refusal. */
function upstreamRepo(array $args)
{
    if (!isset($args[0])) {
        throw new CliError('no repository given', 'give owner/name, for example MagnaCapax/PMSS');
    }
    return Upstream::repo($args[0]);
}

Cli::run($argv, [
    'search-issues' => function ($args, $opt) {
        $repo = upstreamRepo($args);
        $state = isset($opt['state']) ? (string) $opt['state'] : 'all';
        $n = isset($opt['limit']) ? (int) $opt['limit'] : 5;
        if (!in_array($state, ['open', 'closed', 'all'], true) || $n < 1 || $n > 10) {
            throw new CliError('--state is open, closed or all, and --limit is 1 to 10', 'for example --state open --limit 5');
        }
        return Upstream::searchIssues($repo, implode(' ', array_slice($args, 1)), $state, $n, Config::fileConfig(), UpstreamScrub::context()); // the person's own file, never a one-run overlay
    },
    'issue' => function ($args, $opt) {
        $repo = upstreamRepo($args);
        if (!isset($args[1]) || !preg_match('/^[1-9][0-9]{0,8}$/', $args[1])) {
            throw new CliError('no issue number given', 'give the number, for example: issue MagnaCapax/PMSS 123');
        }
        return Upstream::issue($repo, (int) $args[1], !empty($opt['comments']));
    },
    'commits' => function ($args, $opt) {
        $repo = upstreamRepo($args);
        $since = isset($opt['since']) ? (string) $opt['since'] : UpstreamGit::DAYS . 'd';
        if (!preg_match('/^([1-9][0-9]?)d$/', $since, $m) || (int) $m[1] > UpstreamGit::DAYS) {
            throw new CliError('--since is a number of days up to ' . UpstreamGit::DAYS . ', written like 30d', 'the local clone holds ' . UpstreamGit::DAYS . ' days');
        }
        $grep = isset($opt['grep']) && $opt['grep'] !== true ? (string) $opt['grep'] : '';
        $r = UpstreamGit::commits($repo, $grep, (int) $m[1]);
        return ['records' => $r['records'], 'status' => ['repo' => $repo, 'source' => $r['source'], 'stale' => $r['stale'], 'data' => Upstream::UNTRUSTED]];
    },
    'releases' => function ($args, $opt) {
        $repo = upstreamRepo($args);
        list($records, $feed, $stale) = UpstreamFeed::releases($repo);
        return ['records' => $records, 'status' => ['repo' => $repo, 'source' => $feed, 'stale' => $stale, 'data' => Upstream::UNTRUSTED]];
    },
    'draft' => function ($args, $opt) {
        $repo = upstreamRepo($args);
        return UpstreamDraft::make($repo, isset($opt['title']) && $opt['title'] !== true ? $opt['title'] : '',
            isset($opt['body-file']) && $opt['body-file'] !== true ? $opt['body-file'] : '', UpstreamScrub::context());
    },
], $usage, ['comments'], ['state', 'grep', 'since', 'title', 'body-file']);
