<?php
/**
 * HermesTelemetry: Hermes' shared-metrics answer, recorded as "No thanks" where Hermes reads it (ADR-0072).
 *
 * Hermes reads the answer from the user's own config.yaml only, never through the managed scope
 * (hermes_cli/observability/shared_metrics_consent.py consent_state over read_raw_config; relay_shared_metrics.py
 * _raw_config: "managed overlays cannot opt a profile in or out"). The question counts as answered when
 * telemetry.shared_metrics.enabled or .send is in that file, and an "off" answer counts only with the current
 * offer_version; otherwise the dashboard shows its "Send to Nous / Local only / No thanks" banner and an interactive
 * chat asks once. So the seed carries the answer Hermes itself writes for "No thanks" (enabled false, send false,
 * offer_version), and every start puts it back when it is gone. Only send stays pinned in the managed scope: Hermes
 * then refuses `config set` on it, and the bulk save that drops pinned keys from the user's file can drop send
 * without unsettling the answer, which rests on enabled.
 */
class HermesTelemetry
{
    /** Hermes v0.22.2 OFFER_VERSION, used when the installed source cannot be read. */
    const DEFAULT_OFFER_VERSION = 2;
    const SOURCE = 'hermes_cli/observability/shared_metrics_consent.py';

    /** The offer version the installed Hermes asks with; a later Hermes that bumps it would ask again. */
    public static function offerVersion($tree = null)
    {
        $tree = $tree !== null ? $tree : HermesInstall::sourceDir();
        $src = $tree === '' ? '' : (string) @file_get_contents($tree . '/' . self::SOURCE);
        return preg_match('/^OFFER_VERSION = ([1-9][0-9]{0,3})\s*$/m', $src, $m) ? (int) $m[1] : self::DEFAULT_OFFER_VERSION;
    }

    /** The user's config.yaml as seeded: Hermes' own "No thanks" (set_answer writes these three). */
    public static function seedYaml($version)
    {
        return "telemetry:\n  shared_metrics:\n    enabled: false\n    send: false\n    offer_version: " . (int) $version . "\n";
    }

    /** The managed config.yaml: send only (see the class comment for why enabled is not pinned). */
    public static function managedYaml()
    {
        return "telemetry:\n  shared_metrics:\n    send: false\n";
    }

    /**
     * True when the user's file holds the settled "off" answer: enabled false and the current offer_version, as block
     * YAML (what Hermes' writer and the seed produce). Anything else, including YAML written another way, reads false
     * and gets repaired through Hermes, which writes block YAML; so a misread costs one idempotent edit, never a banner.
     */
    public static function isOff($cfg, $version)
    {
        $leaves = self::leaves((string) @file_get_contents($cfg));
        return isset($leaves['enabled'], $leaves['offer_version']) && $leaves['enabled'] === 'false' && $leaves['offer_version'] === (string) (int) $version;
    }

    /** telemetry.shared_metrics leaf => raw scalar text, from block YAML (2-space indents, as Hermes writes it). */
    public static function leaves($yaml)
    {
        $out = [];
        $at = 0; // 0 outside, 1 in telemetry:, 2 in its shared_metrics:
        foreach (preg_split('/\r?\n/', $yaml) as $line) {
            if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            if ($indent === 0) {
                $at = rtrim($line) === 'telemetry:' ? 1 : 0;
            } elseif ($at >= 1 && $indent === 2) {
                $at = rtrim($line) === '  shared_metrics:' ? 2 : 1;
            } elseif ($at === 2 && $indent === 4 && preg_match('/^    ([a-z_]+):\s*([^#\s]+)\s*(#.*)?$/', $line, $m)) {
                $out[$m[1]] = $m[2];
            }
        }
        return $out;
    }

    /**
     * Put "No thanks" back through Hermes' own `config set` (backed up and logged by HermesUserConfig::setValue): only
     * the leaves that differ. send is pinned, so Hermes refuses to set it; with enabled false it sends nothing anyway
     * (shared_metrics_send_config.py resolve_send_config). True when every needed edit went through.
     */
    public static function repair($home, $version)
    {
        $leaves = self::leaves((string) @file_get_contents("$home/config.yaml"));
        $ok = true;
        if (!isset($leaves['enabled']) || $leaves['enabled'] !== 'false') {
            $ok = HermesUserConfig::setValue($home, 'telemetry.shared_metrics.enabled', 'false', 'repair-telemetry-answer', 'enabled false') && $ok;
        }
        if (!isset($leaves['offer_version']) || $leaves['offer_version'] !== (string) (int) $version) {
            $ok = HermesUserConfig::setValue($home, 'telemetry.shared_metrics.offer_version', (string) (int) $version, 'repair-telemetry-answer', 'offer_version ' . (int) $version) && $ok;
        }
        return $ok;
    }
}
