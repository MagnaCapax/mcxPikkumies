<?php
/**
 * StatusTheme: the colours of both status lines (ADR-0061, 2026-10-19 section): Hermes' own status bar above the
 * input, through the skin Pikkumies writes to Hermes' skins directory, and the status line of the `pikkumies` tmux
 * session. A graphite bar, light grey text, a sky-blue accent, and green, amber, orange and red for levels. Every
 * colour is an exact xterm-256 entry: Hermes' classic CLI sends 256-colour codes on Linux whatever TERM says, so its
 * bar shows these colours exactly, as does tmux. The bar's grey has no hue: prompt_toolkit's nearest-colour rule for 16
 * colours gives it black, where Hermes' tinted #1a1a2e gives blue. NO_COLOR (no-color.org) leaves the tmux colours out; Hermes v0.22.2 ignores NO_COLOR on Linux (it
 * builds its own prompt_toolkit output, hermes_cli/cli_terminal_input.py _build_cpr_disabled_output), which only an
 * upstream change can fix.
 */
class StatusTheme
{
    /** The skin's name, `display.skin` in the user's Hermes config; `/skin default` gives Hermes' own look back. */
    const SKIN = 'pikkumies';

    /** role => [hex, xterm-256 index of the same colour]. */
    const PALETTE = [
        'bg' => ['#3a3a3a', 237],
        'text' => ['#dadada', 253],
        'strong' => ['#87d7ff', 117],
        'dim' => ['#b2b2b2', 249],
        'good' => ['#87d787', 114],
        'warn' => ['#ffd75f', 221],
        'bad' => ['#ffaf5f', 215],
        'critical' => ['#ff8787', 210],
    ];

    /** Hermes skin colour key => role. Only the status bar's keys (and the voice bar's background below the input). */
    const SKIN_KEYS = [
        'status_bar_bg' => 'bg', 'status_bar_text' => 'text', 'status_bar_strong' => 'strong', 'status_bar_dim' => 'dim',
        'status_bar_good' => 'good', 'status_bar_warn' => 'warn', 'status_bar_bad' => 'bad', 'status_bar_critical' => 'critical',
        'voice_status_bg' => 'bg',
    ];

    /** A role as tmux names it: colour<N>, which every tmux version reads. */
    public static function tmux($role)
    {
        return 'colour' . self::PALETTE[$role][1];
    }

    /** The tmux status-style of the `pikkumies` session. */
    public static function tmuxStyle()
    {
        return 'bg=' . self::tmux('bg') . ',fg=' . self::tmux('text');
    }

    /**
     * The skin file, <HERMES_HOME>/skins/pikkumies.yaml. Hermes merges a skin's colours over its default skin
     * (hermes_cli/skin_engine.py _build_skin_config), so everything but the status bar keeps Hermes' own look.
     */
    public static function skinYaml()
    {
        $y = "# Written by pikkumies at every start (ADR-0061). Choose another look with /skin; /skin default is Hermes' own.\n"
            . 'name: ' . self::SKIN . "\ndescription: \"Hermes' look with a graphite status bar (Pikkumies)\"\ncolors:\n";
        foreach (self::SKIN_KEYS as $key => $role) {
            $y .= "  $key: \"" . self::PALETTE[$role][0] . "\"\n";
        }
        return $y;
    }

    /** True when NO_COLOR is set and not empty. $env: a test seam (name => value). */
    public static function noColor(array $env = null)
    {
        $v = $env === null ? getenv('NO_COLOR') : (isset($env['NO_COLOR']) ? $env['NO_COLOR'] : false);
        return $v !== false && $v !== '';
    }
}
