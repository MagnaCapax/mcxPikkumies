<?php
/**
 * ReportExample: a complete report document with sample data, dated relative to the time it is made, that uses every
 * block type and chart kind. It is the living reference for the JSON shape (copy it, then replace the data), and the
 * figures are labelled as sample data so the output can never pass for a real account's report.
 */
class ReportExample
{
    const GIB = 1073741824;

    public static function build($now)
    {
        $day = 86400;
        $iso = function ($t) {
            return gmdate('Y-m-d\TH:i:s\Z', $t);
        };
        $at = $iso($now - 600);
        $src = 'Sample data (example report)';
        $fig = function ($v, $unit, $label, $extra = []) use ($src, $at) {
            return ['value' => $v, 'unit' => $unit, 'source' => $src, 'as_of' => $at, 'label' => $label] + $extra;
        };
        $used = [];
        $upload = [];
        $base = 704.9 * self::GIB;
        for ($i = 29; $i >= 0; $i--) {
            $t = (int) (floor(($now - $i * $day) / $day) * $day);
            $used[] = [$iso($t), (int) ($base + (29 - $i) * 3.9 * self::GIB + ($i % 5) * 2 * self::GIB)];
        }
        $daily = [212, 340, 298, 405, 512, 466, 390, 610, 575, 488, 530, 702, 655, 590];
        $cats = [];
        foreach ($daily as $k => $v) {
            $cats[] = gmdate('j M', $now - (13 - $k) * $day);
            $upload[] = $v;
        }
        return [
            'title' => 'Seedbox account health',
            'subtitle' => 'An example of the report kit: every block and chart kind, filled with **sample data**. Copy it, keep the shape, replace the data.',
            'eyebrow' => 'Example report · sample data', 'as_of' => $at, 'stale_days' => 7, 'author' => 'Report kit',
            'footer' => 'Example report · sample data', 'disclaimer' => 'All figures in this example are invented for illustration.',
            'meta' => ['Scope' => 'One account'],
            'verdict' => ['tone' => 'warn', 'headline' => 'Disk is at {fig:diskPct}; everything else is healthy.',
                'text' => 'At the current growth of {fig:growth} a day the quota fills in about {fig:daysLeft}. Upload and memory are well inside their limits.'],
            'figures' => [
                'diskUsed' => $fig(818 * self::GIB, 'bytes', 'Disk used'),
                'diskQuota' => $fig(1024 * self::GIB, 'bytes', 'Disk quota'),
                'diskPct' => $fig(79.9, '%', 'Disk used (share of quota)', ['decimals' => 1]),
                'growth' => $fig(3.9 * self::GIB, 'bytes', 'Disk growth per day'),
                'daysLeft' => $fig(53, 'days', 'Days until the quota is full'),
                'upTotal' => $fig(7273, 'GiB', 'Upload, last 14 days', ['sum_of' => ['upRt', 'upQb']]),
                'upRt' => $fig(5821, 'GiB', 'Upload by rTorrent, last 14 days'),
                'upQb' => $fig(1452, 'GiB', 'Upload by qBittorrent, last 14 days'),
            ],
            'kpis' => [
                ['label' => 'Disk used', 'value' => 'fig:diskUsed', 'of' => 'fig:diskQuota', 'tone' => 'warn', 'spark' => array_column($used, 1), 'note' => 'Full in about {fig:daysLeft} at this rate'],
                ['label' => 'Upload, 30 days', 'value' => $fig(14.2, 'TiB', 'Upload, 30 days'), 'of' => $fig(50, 'TiB', 'Upload limit, 30 days'), 'tone' => 'good', 'spark' => $upload],
                ['label' => 'Memory', 'value' => $fig(1.9, 'GiB', 'Memory used'), 'of' => $fig(4, 'GiB', 'Memory limit'), 'tone' => 'good'],
                ['label' => 'Torrents seeding', 'value' => $fig(412, '', 'Torrents seeding'), 'note' => 'Most of the loaded torrents'],
                ['label' => 'OOM kills, 30 days', 'value' => $fig(0, '', 'OOM kills, 30 days'), 'tone' => 'good'],
                ['label' => 'rTorrent uptime', 'value' => $fig(18, 'days', 'rTorrent uptime')],
            ],
            'sections' => [
                ['title' => 'Storage', 'lead' => 'The disk grows steadily and reaches the quota in about {fig:daysLeft}.', 'blocks' => [
                    ['type' => 'chart', 'kind' => 'area', 'title' => 'Disk used, last 30 days', 'unit' => 'bytes', 'source' => $src, 'as_of' => $at,
                        'series' => [['label' => 'Used', 'points' => $used]], 'caption' => 'Daily reading of the quota tool.'],
                    ['type' => 'chart', 'kind' => 'bullet', 'title' => 'Quota', 'source' => $src, 'as_of' => $at, 'unit' => 'bytes',
                        'value' => 'fig:diskUsed', 'max' => 'fig:diskQuota', 'ranges' => [75, 90]],
                    ['type' => 'table', 'caption' => 'Largest folders', 'source' => $src, 'as_of' => $at, 'totals' => true,
                        'columns' => ['Folder', ['label' => 'Size', 'unit' => 'bytes'], ['label' => 'Files'], 'State'],
                        'rows' => [['downloads/tv', 402 * self::GIB, 3120, ['text' => 'Seeding', 'tone' => 'good']],
                            ['downloads/movies', 291 * self::GIB, 214, ['text' => 'Seeding', 'tone' => 'good']],
                            ['downloads/incomplete', 74 * self::GIB, 96, ['text' => 'Downloading', 'tone' => 'info']],
                            ['backups', 51 * self::GIB, 12, ['text' => 'Old', 'tone' => 'warn']],
                            ['**Total**', 818 * self::GIB, 3442, '']]],
                ]],
                ['title' => 'Traffic', 'lead' => 'Upload over the last two weeks: {fig:upTotal}, most of it from rTorrent.', 'blocks' => [
                    ['type' => 'chart', 'kind' => 'bar', 'title' => 'Upload per day, last 14 days', 'unit' => 'GiB', 'source' => $src, 'as_of' => $at,
                        'categories' => $cats, 'series' => [['label' => 'Upload', 'values' => $upload]]],
                    ['type' => 'chart', 'kind' => 'donut', 'title' => 'Upload by client, last 14 days', 'unit' => 'GiB', 'source' => $src, 'as_of' => $at,
                        'slices' => [['label' => 'rTorrent', 'value' => 5821], ['label' => 'qBittorrent', 'value' => 1452]], 'total' => 'fig:upTotal'],
                ]],
                ['title' => 'Torrents', 'lead' => 'Nearly every loaded torrent is seeding.', 'blocks' => [
                    ['type' => 'chart', 'kind' => 'stacked', 'title' => 'Torrents by state', 'source' => $src, 'as_of' => $at, 'table' => true,
                        'categories' => ['rTorrent', 'qBittorrent'],
                        'series' => [['label' => 'Seeding', 'values' => [338, 74]], ['label' => 'Downloading', 'values' => [6, 3]], ['label' => 'Stopped', 'values' => [8, 2]]]],
                    ['type' => 'chart', 'kind' => 'hbar', 'title' => 'Size by label', 'unit' => 'GiB', 'source' => $src, 'as_of' => $at,
                        'bars' => [['label' => 'tv', 'value' => 402], ['label' => 'movies', 'value' => 291], ['label' => 'music', 'value' => 38], ['label' => 'linux-isos', 'value' => 12]]],
                    ['type' => 'chart', 'kind' => 'line', 'title' => 'Upload and download rate, last 30 days', 'unit' => 'MB/s', 'source' => $src, 'as_of' => $at,
                        'series' => [['label' => 'Up', 'points' => self::rate($now, 30, 9.0)], ['label' => 'Down', 'points' => self::rate($now, 30, 2.5)]]],
                ]],
                ['title' => 'What to do next', 'lead' => 'One thing needs attention this month.', 'blocks' => [
                    ['type' => 'callout', 'tone' => 'warn', 'title' => 'Free space before the quota is reached', 'text' => 'Remove torrents you no longer seed, or move old backups off the box. The quota fills in about {fig:daysLeft}.'],
                    ['type' => 'list', 'ordered' => true, 'items' => ['Review the **backups** folder; it has not changed in months.', 'Check the largest finished torrents that no longer upload.', 'Run this report again next week to see the trend.']],
                    ['type' => 'prose', 'text' => "Everything else is healthy: memory has room, nothing was killed for memory, and upload stays well inside the limit.\n\nThe Data review page at the back lists the checks this report passed."],
                ]],
            ],
        ];
    }

    /** A deterministic daily rate series around $level (sample data). */
    private static function rate($now, $days, $level)
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $t = (int) (floor(($now - $i * 86400) / 86400) * 86400);
            $out[] = [gmdate('Y-m-d\TH:i:s\Z', $t), round($level * (1 + 0.25 * sin($i / 2.3) + 0.1 * cos($i * 1.7)), 2)];
        }
        return $out;
    }
}
