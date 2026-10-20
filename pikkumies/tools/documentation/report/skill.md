---
name: report
description: "PDF reports for people: render a report JSON into a styled PDF (cover with verdict and KPI cards, charts, tables, a data review page and where every number comes from), or build one from this account's data: account health, services, a move. Raportti, PDF."
---

# Report

Use it when the user wants something to read, keep or pass on: a status report, a summary of a move, an overview of their boxes. For a quick answer in the chat, answer in the chat instead.

1. **Start from data, never from typing.** For this account use `templates account-health` (or `services-overview`, `move-report`): it writes a report JSON under `~/pikkumies/local/reports/` from the live tools and the recorded history. For anything else, run `example` and copy its shape. Every number is a figure with `source` and `as_of`; a number inside a sentence is written `{fig:<id>}` and declared once under `figures`.
2. **Lint before you render.** `lint <report.json>` runs the data review: numbers without a source, stale or future dates, totals that do not add up, shares over 100%, one label with two values, empty sections, typed numbers in the text, sentences over 25 words, charts too flat to show their differences. Fix every error; fix the warnings you can.
3. **Render.** `render <report.json>`; add `--strict` when the report goes to someone else, so a report with errors is refused. `--strict` blocks errors only: warnings still render, so read them too. The status line gives the PDF path, `pages`, the review summary and `verified`.
4. **Tell the user** the PDF path and the review line ("clean", or what is left). If the status has a `hint` (no Chromium here, an old Chromium without page numbers), pass it on. Never claim the PDF exists when `verified` is not true.
