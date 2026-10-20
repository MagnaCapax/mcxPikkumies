# report

PDF reports that are easy to read and easy to check. A report is a JSON document; this unit reviews its data, renders it with one stylesheet and prints it to PDF with the server's own Chromium. The decision and its reasons are in [ADR-0091](../../../../docs/adr/0091-pdf-reports.md).

## What a report looks like

- **Cover**: the title, a one-paragraph verdict with a labelled state (Good, Attention, Critical, Note; an icon and a word, never colour alone), three to six KPI cards (value, limit, a meter, a sparkline), the report's facts (data date, generation time, number of sources, data review result) and a line on how to read it.
- **Contents** when there are more than three sections, then the sections: numbered headings, a one-sentence takeaway, prose, lists, callouts, tables with right-aligned tabular figures and declared totals, and charts.
- **Charts** are inline SVG written in PHP: line and area (time series, with a low-high band for bucketed history), columns, bars, stacked bars, donut, sparkline and bullet. Each has a title and a description in words; lines differ by dash and carry their value at the end, fills beyond the first differ by texture, and every series is named in a legend or a label.
- **Data review** page: every check, also the ones that passed, and every finding.
- **Where each number comes from**: the sources by number (the small number after each figure points here) and every figure with its value, source, date and age. A figure without a source carries a red dagger.
- Every page but the cover has a running header (title, data date) and a footer with "Page X of Y" (CSS page-margin boxes, printed by Chromium 131 and later).

## How it renders

The page is self-contained: a Content-Security-Policy allows only inline styles and `data:` images and fonts, there is no script, fonts are the system's (serif headings, sans text, mono labels), and every value is escaped. `render` prints it with `setsid unshare --map-root-user --net chromium --headless --no-sandbox ... --print-to-pdf`: a private network namespace with no network, its own process group (a timeout kills all of it) and a fresh profile under `local/state/tmp/report-XXXX`, removed afterwards. Without `unshare` (or where user namespaces are off) Chromium runs with network-off flags only. `chromium-headless-shell` is used when installed, else `chromium`. On Pulsed Media servers the PMSS updater installs Debian's Chromium where it fits (PMSS ADR-0123); without one, `render` writes the HTML and says so.

## The kit

`pikkumies/lib/report/` is plain PHP 7.3 with no Pikkumies dependency, meant to be copied as it is into other projects: `ReportKit::decode()`, `ReportKit::lint()`, `ReportKit::html()`, `ReportKit::example()`. The unit adds the templates (`ReportTemplates`, `ReportTelemetry`), the output directory (`ReportFiles`) and printing (`ReportPrint`).
