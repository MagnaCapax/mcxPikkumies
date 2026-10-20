<?php
/**
 * report: stylish, easy-to-read PDF reports from a report JSON (ADR-0091). The document model, the data review (lint),
 * the stylesheet and the charts are the plain-PHP kit in pikkumies/lib/report/; this unit adds the account's own
 * templates, the output directory (local/reports/) and printing with the server's Chromium (ReportPrint).
 */
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/CliHuman.php';
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/ToolProc.php';
require_once dirname(__DIR__, 3) . '/lib/report/ReportKit.php';
require_once __DIR__ . '/ReportFiles.php';
require_once __DIR__ . '/ReportPrint.php';
require_once __DIR__ . '/ReportTemplates.php';

// Test seams: a test may set these before including the file. Nothing is read from the environment.
$reportLocalDir = isset($reportLocalDir) ? $reportLocalDir : Config::localDir();
$reportNow = isset($reportNow) ? $reportNow : time();
$reportAccount = isset($reportAccount) ? $reportAccount : function () {
    $r = ToolProc::run([PHP_BINARY, dirname(__DIR__, 2) . '/system/system-status/system-status.php', 'account']);
    foreach (explode("\n", $r['out']) as $line) {
        $rec = json_decode($line, true);
        if (is_array($rec) && !isset($rec['ok'])) {
            return $rec;
        }
    }
    return null;
};

$reportUsage = <<<'TXT'
php report.php render <report.json> [--out NAME.pdf] [--strict] [--html-only] [--browser /path/to/chromium] [--timeout S]
php report.php lint <report.json>
php report.php templates [account-health|services-overview|move-report] [--out NAME.json] [--days N] [--move ID]
php report.php example [--out NAME.json]
Reports are written to ~/pikkumies/local/reports/.
TXT;

/** Lint findings as records, plus the status fields every verb that reads a report shares. */
$reportReview = function (array $lint) {
    $records = array_map(function ($i) {
        return ['finding' => $i['level'], 'check' => $i['code'], 'where' => $i['where'], 'message' => $i['message']];
    }, $lint['items']);
    return [$records, ['review' => ReportLint::summary($lint), 'errors' => $lint['errors'], 'warnings' => $lint['warnings']]];
};

$reportDecode = function ($text) use ($reportNow) {
    try {
        return ReportKit::decode($text, $reportNow);
    } catch (InvalidArgumentException $e) {
        throw new CliError('the report JSON has the wrong shape: ' . $e->getMessage(), 'compare it with the file `example` writes (the shape is in USAGE.md)');
    }
};

Cli::run($argv, [
    'render' => function ($args, $opt) use ($reportLocalDir, $reportNow, $reportReview, $reportDecode) {
        $doc = $reportDecode(ReportFiles::read(isset($args[0]) ? $args[0] : null));
        $lint = ReportKit::lint($doc);
        list($records, $status) = $reportReview($lint);
        if (!empty($opt['strict']) && $lint['errors'] > 0) {
            throw new CliError("--strict: the data review found {$lint['errors']} error(s); nothing was rendered", 'run lint to list them, fix the report JSON, then render again');
        }
        $dir = ReportFiles::dir($reportLocalDir);
        $pdf = ReportFiles::target($dir, isset($opt['out']) ? $opt['out'] : null, $doc['title'], 'pdf', $reportNow);
        $html = substr($pdf, 0, -4) . '.html';
        $page = ReportKit::html($doc, $lint);
        ReportFiles::write($html, $page);
        if (ReportFiles::printable($page) !== '') {
            throw new CliError(ReportFiles::printable($page) . '; nothing was printed', 'the HTML is at ' . Config::shown($html) . '; shorten the report JSON and render again');
        }
        unset($page);
        $status += ['html' => Config::shown($html), 'pdf' => null, 'verified' => false];
        $browser = !empty($opt['html-only']) ? null : ReportPrint::browser(isset($opt['browser']) && $opt['browser'] !== true ? (string) $opt['browser'] : null);
        if ($browser === null) {
            return ['records' => $records, 'status' => $status + ['hint' => !empty($opt['html-only']) ? 'HTML only, as asked; render without --html-only for the PDF'
                : 'no Chromium on this server (chromium, chromium-headless-shell): the HTML is complete and prints to PDF from any browser (Print, Save as PDF)']];
        }
        $timeout = isset($opt['timeout']) && ctype_digit((string) $opt['timeout']) ? max(10, min(600, (int) $opt['timeout'])) : 90;
        $r = ReportPrint::pdf($browser, $html, $pdf, "$reportLocalDir/state/tmp", $timeout);
        if (!$r['ok']) {
            throw new CliError($r['error'] . ($r['stderr'] !== '' ? ' (' . $r['stderr'] . ')' : '') . '; the HTML is at ' . Config::shown($html),
                'open the HTML in a browser and print it to PDF, or try --timeout 300');
        }
        @chmod($pdf, 0600);
        $status = array_merge($status, ['pdf' => Config::shown($pdf), 'verified' => true, 'pages' => $r['pages'], 'browser' => $browser,
            'isolation' => $r['mode'] === 'netns' ? 'private network namespace' : 'network-off flags (unshare unavailable)']);
        if ($r['major'] > 0 && $r['major'] < ReportPrint::MARGIN_BOXES) {
            $status['hint'] = "Chromium {$r['major']} prints no running header or page numbers (needs " . ReportPrint::MARGIN_BOXES . '+); the rest is complete';
        }
        return ['records' => $records, 'status' => $status];
    },
    'lint' => function ($args) use ($reportReview, $reportDecode) {
        $lint = ReportKit::lint($reportDecode(ReportFiles::read(isset($args[0]) ? $args[0] : null)));
        list($records, $status) = $reportReview($lint);
        $status['checks'] = count($lint['checks']);
        return ['records' => $records, 'status' => $status + ['next' => $lint['errors'] ? 'fix the errors, then render' : 'render <report.json>']];
    },
    'templates' => function ($args, $opt) use ($reportLocalDir, $reportNow, $reportAccount, $reportReview) {
        if (!$args) {
            return ReportTemplates::available($reportLocalDir);
        }
        $name = $args[0];
        $days = isset($opt['days']) && ctype_digit((string) $opt['days']) ? max(1, min(366, (int) $opt['days'])) : 30;
        if ($name === 'account-health') {
            $in = ReportTemplates::accountHealth($reportLocalDir, call_user_func($reportAccount), $days, $reportNow);
        } elseif ($name === 'services-overview') {
            $file = "$reportLocalDir/state/services.jsonl";
            if (!is_file($file)) {
                throw new CliError('no services list at ' . Config::shown($file), 'add a service with the services tool first');
            }
            $list = array_values(array_filter(array_map(function ($l) {
                return json_decode($l, true);
            }, explode("\n", (string) file_get_contents($file))), 'is_array'));
            $in = ReportTemplates::servicesOverview($list, $file, $reportNow);
        } elseif ($name === 'move-report') {
            $moves = ReportTemplates::moves($reportLocalDir);
            $id = isset($opt['move']) && $opt['move'] !== true ? (string) $opt['move'] : (isset($moves[0]) ? $moves[0] : '');
            $in = ReportTemplates::moveReport($reportLocalDir, $id, $reportNow);
        } else {
            throw new CliError("unknown template: $name", 'templates: ' . implode(', ', array_keys(ReportTemplates::NAMES)));
        }
        $path = ReportFiles::target(ReportFiles::dir($reportLocalDir), isset($opt['out']) ? $opt['out'] : null, $name, 'json', $reportNow);
        ReportFiles::write($path, json_encode($in, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        list($records, $status) = $reportReview(ReportKit::lint(ReportModel::normalize($in, $reportNow)));
        return ['records' => $records, 'status' => $status + ['written' => Config::shown($path), 'verified' => is_file($path), 'next' => 'render ' . Config::shown($path)]];
    },
    'example' => function ($args, $opt) use ($reportLocalDir, $reportNow) {
        $path = ReportFiles::target(ReportFiles::dir($reportLocalDir), isset($opt['out']) ? $opt['out'] : null, 'example', 'json', $reportNow);
        ReportFiles::write($path, json_encode(ReportKit::example($reportNow), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        return ['records' => [], 'status' => ['written' => Config::shown($path), 'verified' => is_file($path), 'next' => 'render ' . Config::shown($path)]];
    },
], $reportUsage, ['strict', 'html-only'], ['out', 'browser', 'timeout', 'days', 'move']);
