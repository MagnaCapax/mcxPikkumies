# report

Reports are written to `~/pikkumies/local/reports/` (0700, files 0600); `--out` takes a file name there, never a path. Nothing leaves the machine: the page loads nothing, and Chromium runs without network ([ADR-0091](../../../../docs/adr/0091-pdf-reports.md)).

| verb | arguments | changes | what it does |
|---|---|---|---|
| render | `<report.json> [--out NAME.pdf] [--strict] [--html-only] [--browser PATH] [--timeout S]` | yes | Runs the data review, writes `NAME.html` and prints it to `NAME.pdf` with the server's Chromium (`chromium-headless-shell` or `chromium` on PATH, or `--browser`, an absolute path). One record per review finding; the status line has `pdf`, `html`, `pages`, `verified`, `review`, `errors`, `warnings`, `isolation`. `--strict` refuses to render while the review has errors. Without Chromium (or with `--html-only`) only the HTML is written, with a `hint`. `--timeout` (default 90 s) stops the whole browser process group. |
| lint | `<report.json>` | no | The data review alone: one record per finding (`finding` error or warn, `check`, `where`, `message`), and the counts. |
| templates | `[account-health\|services-overview\|move-report] [--out NAME.json] [--days N] [--move ID]` | yes | Without a name: which templates have data here. With a name: builds a report JSON from this account's own data and writes it (account-health: `system-status account` and the telemetry history of the last `--days`, default 30; services-overview: the services list; move-report: the move `--move ID`, else the newest), then prints its review. |
| example | `[--out NAME.json]` | yes | Writes a complete example report JSON (sample data, every block and chart kind) to start from. |

```sh
R=~/pikkumies/tools/documentation/report/report.php
php $R templates account-health          # writes reports/account-health-<time>.json from live figures
php $R lint ~/pikkumies/local/reports/account-health-20261020-101500.json
php $R render ~/pikkumies/local/reports/account-health-20261020-101500.json --strict
php $R example --out mine.json            # the shape to copy
```

## The report JSON

```json
{
  "title": "Account health", "subtitle": "...", "eyebrow": "...", "as_of": "2026-10-20T10:00:00Z", "stale_days": 7,
  "footer": "...", "author": "...", "disclaimer": "...", "meta": {"Scope": "One account"},
  "verdict": {"tone": "good|warn|crit|info", "headline": "Disk is at {fig:diskPct}.", "text": "..."},
  "figures": {"diskPct": {"value": 79.9, "unit": "%", "source": "system-status account", "as_of": "2026-10-20T09:58:00Z", "label": "Disk used (share)"}},
  "kpis": [{"label": "Disk used", "value": "fig:diskUsed", "of": "fig:diskQuota", "tone": "warn", "spark": [1, 2, 3], "note": "..."}],
  "sections": [{"title": "Storage", "lead": "One-sentence takeaway.", "page_break": false, "blocks": [
    {"type": "prose", "text": "Paragraphs; **bold**, `code`, {fig:id}."},
    {"type": "list", "items": ["..."], "ordered": false, "title": "..."},
    {"type": "callout", "tone": "warn", "title": "...", "text": "..."},
    {"type": "kpis", "items": [...]},
    {"type": "table", "caption": "...", "source": "...", "as_of": "...", "totals": true, "note": "...",
     "columns": ["Folder", {"label": "Size", "unit": "bytes"}], "rows": [["tv", 431000000000], ["Total", 431000000000]]},
    {"type": "chart", "kind": "line|area|bar|hbar|stacked|donut|sparkline|bullet", "title": "...", "unit": "bytes", "source": "...", "as_of": "...", "...": "data, below"}
  ]}]
}
```

- A **figure** is `{value, unit, source, as_of, label, decimals, change, sum_of}`, a bare number (no source: the review flags it), or `"fig:<id>"`. Unit `bytes` is shown in binary units (GiB); `%` is a share (0-100) unless `"change": true`. `sum_of: [ids]` declares a total the review adds up.
- **Table** cells: text, a number or figure (the column's unit, the table's source and date), or `{"text": "Seeding", "tone": "good"}` for a labelled chip. `"totals": true`: the last row is the declared total of the rows above.
- **Limits**: at most 2000 rows per table, 2000 points per series, 12 series and 500 categories per chart, 64 KiB per text field, 8 MiB of JSON and 20 MiB of HTML to print. Larger input is refused with "summarise first".
- **What the review covers**: every figure (KPIs, table cells, chart data, `figures`) for source, date, sums, shares and conflicts; typed numbers in the title, subtitle, meta values, verdict, KPI notes, section titles and leads, prose, callouts, lists, table captions, notes and text cells, and chart captions. Chart titles and KPI labels are names and are not scanned. `--strict` refuses only **errors** (unsourced, unknown figure, not a number, sums, shares, future dates, conflicts, empty sections); warnings (stale, undated, typed numbers, long sentences, flat charts) still render and are listed on the Review page, so "clean" means no errors and no warnings.
- **Chart** data: `line`/`area` `series: [{label, points: [[time, value]], band: [[time, low, high]]}]`; `bar`/`hbar`/`stacked` `categories` and `series: [{label, values}]` (or `bars: [{label, value}]`), `"table": true` adds the numbers below; `donut` `slices: [{label, value}]` and an optional `total`; `sparkline` `values`; `bullet` `value`, `max`, `target`, `ranges` (percent of max, default 70 and 90).
