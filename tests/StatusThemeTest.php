<?php
/**
 * The look and place of the status lines (ADR-0061, 2026-10-19 section): the colours in lib/StatusTheme.php, the
 * Hermes skin Pikkumies writes and selects once, the `pikkumies tmux` status line pinned to the bottom and coloured on
 * its own session, and NO_COLOR leaving the tmux colours out. Uses the fake hermes of HermesUserConfigTest.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/credits/CreditsFormat.php';
if (!function_exists('userCfgWorld')) {
    require __DIR__ . '/HermesUserConfigTest.php';
}

/** xterm-256 colour $i (16-255) as prompt_toolkit and tmux take it: the 6x6x6 cube, then the grey ramp. */
function themeXterm($i)
{
    if ($i >= 232) {
        $g = 8 + ($i - 232) * 10;
        return [$g, $g, $g];
    }
    $v = [0, 0x5F, 0x87, 0xAF, 0xD7, 0xFF];
    $n = $i - 16;
    return [$v[intdiv($n, 36) % 6], $v[intdiv($n, 6) % 6], $v[$n % 6]];
}

/** WCAG 2 contrast ratio of two '#rrggbb' colours. */
function themeContrast($a, $b)
{
    $lum = function ($hex) {
        $l = 0;
        foreach ([0.2126, 0.7152, 0.0722] as $i => $w) {
            $c = hexdec(substr($hex, 1 + 2 * $i, 2)) / 255;
            $l += $w * ($c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4));
        }
        return $l;
    };
    list($x, $y) = [$lum($a), $lum($b)];
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/** A user config.yaml of an install that already ran, before the skin: migration marker, skills dir, telemetry answer. */
function themeInstalled(array $w, $extra = '')
{
    @mkdir($w['local'] . '/state', 0700);
    HermesConfig::put(HermesUserConfig::marker($w['local']), "{}\n");
    HermesConfig::put(HermesUserConfig::browseMarker($w['local']), "{}\n"); // the browser step already ran (ADR-0077)
    file_put_contents($w['home'] . '/config.yaml', HermesConfig::seedHeader() . "skills:\n  external_dirs:\n    - " . HermesConfig::q(Units::generatedDir()) . "\n"
        . HermesTelemetry::seedYaml(2) . $extra);
}

/** A credits record at $pct percent left (CreditsFormat::render input). */
function creditsThemeRecord($pct)
{
    return ['balance' => 100, 'pot' => 200, 'pct_left' => $pct, 'tokens_min' => null, 'model' => 'm', 'today_credits' => 0, 'approx' => ['pot' => false]];
}

return [
    'status theme: every colour is the xterm-256 entry tmux is given, so a 256-colour terminal shows what a 24-bit one does' => function () {
        foreach (StatusTheme::PALETTE as $role => $c) {
            list($r, $g, $b) = themeXterm($c[1]);
            check($c[1] >= 16 && $c[0] === sprintf('#%02x%02x%02x', $r, $g, $b), "$role: {$c[0]} is not xterm colour {$c[1]}");
            check(StatusTheme::tmux($role) === 'colour' . $c[1], "$role in tmux");
        }
        list($r, $g, $b) = themeXterm(StatusTheme::PALETTE['bg'][1]);
        check($r === $g && $g === $b, 'the bar is a grey with no hue: by prompt_toolkit\'s 16-colour rule a tinted near-black such as #1a1a2e is blue');
        check(StatusTheme::tmuxStyle() === 'bg=colour237,fg=colour253', StatusTheme::tmuxStyle());
    },
    'status theme: readable on the bar, and no colour that Hermes darkens on a light terminal' => function () {
        $bg = StatusTheme::PALETTE['bg'][0];
        // AA (4.5) for every colour, AAA (7) for the text read all the time.
        $min = ['text' => 7, 'strong' => 7, 'dim' => 4.5, 'good' => 4.5, 'warn' => 4.5, 'bad' => 4.5, 'critical' => 4.5];
        foreach ($min as $role => $ratio) {
            $got = themeContrast(StatusTheme::PALETTE[$role][0], $bg);
            check($got >= $ratio, sprintf('%s on the bar: %.2f, want %.1f', $role, $got, $ratio));
        }
        foreach (['#000000', '#1e1e1e', '#282828', '#282a36', '#282c34'] as $term) { // black, and common dark theme backgrounds
            check(themeContrast($bg, $term) >= 1.2, "the bar must show as a band on a $term terminal: " . round(themeContrast($bg, $term), 2));
        }
        // Hermes v0.22.2 hermes_cli/cli_render.py _LIGHT_MODE_REMAP: every skin colour read goes through it on a light
        // terminal, the status bar's too, while the bar keeps its own background; a key listed there would go dark on dark.
        $remap = ['#fff8dc', '#ffd700', '#ffbf00', '#b8860b', '#daa520', '#f1e6cf', '#c9d1d9', '#eaf7ff', '#f5f5f5', '#fff0d4', '#cd7f32', '#ffefb5'];
        foreach (StatusTheme::PALETTE as $role => $c) {
            check(!in_array(strtolower($c[0]), $remap, true), "$role {$c[0]} is remapped by Hermes on light terminals");
        }
    },
    'status theme: the skin file names the skin and gives every status bar key its colour' => function () {
        $y = StatusTheme::skinYaml();
        check(preg_match('/^name: pikkumies$/m', $y) === 1 && strpos($y, "\ncolors:\n") !== false, $y);
        foreach (['status_bar_bg' => '#3a3a3a', 'status_bar_text' => '#dadada', 'status_bar_strong' => '#87d7ff', 'status_bar_dim' => '#b2b2b2',
            'status_bar_good' => '#87d787', 'status_bar_warn' => '#ffd75f', 'status_bar_bad' => '#ffaf5f', 'status_bar_critical' => '#ff8787', 'voice_status_bg' => '#3a3a3a'] as $k => $hex) {
            check(strpos($y, "  $k: \"$hex\"\n") !== false, "$k: $y");
        }
        check(strpos(strtolower($y), 'ffd700') === false && strpos($y, 'banner_') === false, 'only the status bar changes');
    },
    'status theme: the first start writes the skin and seeds display.skin; the managed file leaves the skin to the user' => function () {
        $w = userCfgWorld();
        try {
            userCfgIn($w, '', '', function () use ($w) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                check((string) @file_get_contents($w['home'] . '/skins/pikkumies.yaml') === StatusTheme::skinYaml(), 'the skin file');
                check(strpos((string) file_get_contents($w['home'] . '/config.yaml'), "display:\n  skin: \"pikkumies\"\n") !== false, 'the seed selects it');
                check(strpos((string) file_get_contents($w['local'] . '/hermes-managed/config.yaml'), 'display:') === false, 'a pin would refuse /skin');
                check(is_file(HermesUserConfig::skinMarker($w['local'])) && !is_file($w['log']), 'done once, no Hermes process');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'status theme: an install from before gets display.skin once through config set; a skin the user chose stays; without Hermes it waits' => function () {
        $w = userCfgWorld();
        try {
            themeInstalled($w);
            userCfgIn($w, '', '', function () use ($w) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                check(!is_file($w['log']) && !is_file(HermesUserConfig::skinMarker($w['local'])), 'Hermes not built: wait, no marker');
            });
            userCfgIn($w, $w['bin'], '', function () use ($w) {
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                check((string) file_get_contents($w['log']) === 'config set display.skin pikkumies | managed=' . $w['local'] . "/hermes-managed\n", 'log: ' . file_get_contents($w['log']));
                $lines = userCfgLogLines($w);
                check(json_decode((string) end($lines), true)['action'] === 'skin-pikkumies', 'logged: ' . end($lines));
                HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                check(substr_count((string) file_get_contents($w['log']), 'display.skin') === 1, 'once only: a later /skin default stays');
            });
        } finally {
            testRmTree($w['t']);
        }
        foreach (["display:\n  skin: ares\n", "display: {skin: default}\n"] as $chosen) {
            $w = userCfgWorld();
            try {
                themeInstalled($w, $chosen);
                userCfgIn($w, $w['bin'], '', function () use ($w, $chosen) {
                    HermesConfig::writeConfig($w['home'], userCfgProv(), Config::defaults());
                    check(!is_file($w['log']) && is_file(HermesUserConfig::skinMarker($w['local'])), "the user's skin stays: $chosen");
                });
            } finally {
                testRmTree($w['t']);
            }
        }
    },
    'status theme: the tmux plan pins the status line to the bottom and colours it on the pikkumies session only; NO_COLOR leaves the colours out' => function () {
        $plan = Launch::tmuxPlan('2', null, false, "'p'", "'c'", "'p' status-line");
        check(in_array(['tmux', 'set-option', '-t', '=pikkumies:', 'status-position', 'bottom'], $plan, true), 'bottom: ' . json_encode($plan));
        check(in_array(['tmux', 'set-option', '-t', '=pikkumies:', 'status-style', 'bg=colour237,fg=colour253'], $plan, true), 'style: ' . json_encode($plan));
        foreach ($plan as $step) {
            check(!in_array('-g', $step, true), 'never global: ' . json_encode($step));
        }
        $plain = Launch::tmuxPlan('2', null, false, "'p'", "'c'", "'p' status-line", false);
        check(in_array(['tmux', 'set-option', '-t', '=pikkumies:', 'status-position', 'bottom'], $plain, true) && strpos(json_encode($plain), 'status-style') === false, 'no colour: ' . json_encode($plain));
        check(count(Launch::tmuxPlan('1', ['pm1'], true, "'p'")) === 2, 'no status command: the plan leaves the status line alone');
        check(StatusTheme::noColor(['NO_COLOR' => '1']) && !StatusTheme::noColor(['NO_COLOR' => '']) && !StatusTheme::noColor([]), 'NO_COLOR counts when set and not empty');
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(strpos($bin, "\$creditsCmd = (\$noColor ? 'NO_COLOR=1 ' : '') . escapeshellarg(PHP_BINARY)") !== false, 'bin hands NO_COLOR to the credits line tmux runs');
        check(strpos($bin, "Launch::tmuxPlan(\$n, \$windows, \$insideTmux, \$self, \$creditsCmd, \$self . ' status-line', !\$noColor)") !== false, 'bin passes the colour choice');
    },
    'status theme: the credits bar in tmux takes the level colours over the theme bar, and none under NO_COLOR' => function () {
        testWithEnv(['NO_COLOR' => null], function () {
            foreach (['25.1' => 'colour114', '25' => 'colour221', '10' => 'colour221', '9.9' => 'colour210'] as $pct => $colour) {
                $line = CreditsFormat::render(creditsThemeRecord((float) $pct), 'tmux', false);
                check(strpos($line, "100 credits #[fg=$colour][") === 0 && strpos($line, '#[default]') !== false && strpos($line, 'bg=') === false, "$pct%: $line");
            }
        });
        testWithEnv(['NO_COLOR' => '1'], function () {
            $line = CreditsFormat::render(creditsThemeRecord(50.0), 'tmux', false);
            check($line === '100 credits [#####-----] 50% left | today 0', 'NO_COLOR: ' . $line);
        });
    },
];
