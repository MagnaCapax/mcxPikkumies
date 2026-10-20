<?php
/**
 * ReportKit: the entry point of the report kit. Plain PHP 7.3 with no dependency outside this directory, so the
 * directory can be copied into another repository as it is (record the source commit where you copy it).
 *
 *   $doc  = ReportKit::decode(file_get_contents('report.json'));   // throws InvalidArgumentException on a bad shape
 *   $lint = ReportKit::lint($doc);                                   // the data review: errors, warnings, checks
 *   $html = ReportKit::html($doc, $lint);                            // one self-contained page; print it with Chromium
 *
 * Printing is left to the caller: `chromium --headless --no-pdf-header-footer --print-to-pdf=<out> file://<html>`
 * honours the page size, margins and running header/footer the stylesheet sets.
 */
require_once __DIR__ . '/ReportFormat.php';
require_once __DIR__ . '/ReportModel.php';
require_once __DIR__ . '/ReportModelTable.php';
require_once __DIR__ . '/ReportProvenance.php';
require_once __DIR__ . '/ReportLint.php';
require_once __DIR__ . '/ReportSvg.php';
require_once __DIR__ . '/ReportSvgBars.php';
require_once __DIR__ . '/ReportCss.php';
require_once __DIR__ . '/ReportHtmlBlocks.php';
require_once __DIR__ . '/ReportHtmlBack.php';
require_once __DIR__ . '/ReportHtml.php';
require_once __DIR__ . '/ReportExample.php';

class ReportKit
{
    const MAX_BYTES = 8388608;

    /** The normalized document from report JSON text. */
    public static function decode($json, $now = null)
    {
        if (strlen((string) $json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('the report JSON is larger than 8 MiB');
        }
        $in = json_decode((string) $json, true);
        if (!is_array($in)) {
            throw new InvalidArgumentException('not a JSON object: ' . (json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'a report is an object with a title'));
        }
        return ReportModel::normalize($in, $now);
    }

    public static function lint(array $doc)
    {
        return ReportLint::run($doc);
    }

    public static function html(array $doc, array $lint = null)
    {
        return ReportHtml::render($doc, $lint !== null ? $lint : ReportLint::run($doc));
    }

    /** A complete example document (sample data, dated around $now) using every block and chart kind. */
    public static function example($now = null)
    {
        return ReportExample::build($now === null ? time() : (int) $now);
    }
}
