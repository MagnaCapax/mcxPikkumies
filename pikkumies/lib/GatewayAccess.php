<?php
/**
 * GatewayAccess (ADR-0101): whether a messaging-gateway turn may count as the person's own for the ask review's evidence
 * (AskReview::evidence). It counts only when Hermes' gateway lets nobody but named senders in, read the way the gateway
 * reads it (Hermes v0.22.2 gateway/authz_mixin.py _principal_authorized, gateway/pairing.py _PLATFORM_ALLOWLIST_ENV,
 * gateway/run.py _OWN_POLICY_OPEN_ENV, gateway/run_startup.py _start_check_access_policy), and fails closed:
 * - the platform is one of Hermes' built-in messaging platforms below (a plugin platform: no);
 * - neither GATEWAY_ALLOW_ALL_USERS nor the platform's <P>_ALLOW_ALL_USERS is set to anything but an off value, the
 *   platform does not admit bots (<P>_ALLOW_BOTS), and no dm_policy or group_policy is "open" (the platform's env, or
 *   anywhere in the user's or the managed config.yaml; a config file that exists but cannot be read counts as open);
 * - an explicit list is set for the platform (<P>_ALLOWED_USERS or GATEWAY_ALLOWED_USERS), it holds no "*", and the
 *   sender of this turn (pre_llm_call extra.sender_id) is on it exactly. A sender let in another way (pairing, a group
 *   chat id, a role, an alias) gives no evidence.
 * Anything unknown means no evidence, so the irreversible kinds are refused there. The variables are read from this
 * hook's own environment, which the gateway passes to its shell hooks in a single-profile run (agent/shell_hooks.py,
 * build_subprocess_env); under profile multiplexing the gateway scrubs them, and the turn gives no evidence.
 * A safety record of who wrote, at the same trust level as the halt snapshot, not a security boundary (AGENTS.md).
 */
class GatewayAccess
{
    /** platform value => [its allowlist variable, its allow-all variable]: gateway/pairing.py, authz_mixin.py _ALLOW_ALL_ENV. */
    const PLATFORMS = [
        'telegram' => ['TELEGRAM_ALLOWED_USERS', 'TELEGRAM_ALLOW_ALL_USERS'], 'discord' => ['DISCORD_ALLOWED_USERS', 'DISCORD_ALLOW_ALL_USERS'],
        'whatsapp' => ['WHATSAPP_ALLOWED_USERS', 'WHATSAPP_ALLOW_ALL_USERS'], 'whatsapp_cloud' => ['WHATSAPP_CLOUD_ALLOWED_USERS', 'WHATSAPP_CLOUD_ALLOW_ALL_USERS'],
        'slack' => ['SLACK_ALLOWED_USERS', 'SLACK_ALLOW_ALL_USERS'], 'signal' => ['SIGNAL_ALLOWED_USERS', 'SIGNAL_ALLOW_ALL_USERS'],
        'email' => ['EMAIL_ALLOWED_USERS', 'EMAIL_ALLOW_ALL_USERS'], 'sms' => ['SMS_ALLOWED_USERS', 'SMS_ALLOW_ALL_USERS'],
        'mattermost' => ['MATTERMOST_ALLOWED_USERS', 'MATTERMOST_ALLOW_ALL_USERS'], 'matrix' => ['MATRIX_ALLOWED_USERS', 'MATRIX_ALLOW_ALL_USERS'],
        'dingtalk' => ['DINGTALK_ALLOWED_USERS', 'DINGTALK_ALLOW_ALL_USERS'], 'feishu' => ['FEISHU_ALLOWED_USERS', 'FEISHU_ALLOW_ALL_USERS'],
        'wecom' => ['WECOM_ALLOWED_USERS', 'WECOM_ALLOW_ALL_USERS'], 'wecom_callback' => ['WECOM_CALLBACK_ALLOWED_USERS', 'WECOM_CALLBACK_ALLOW_ALL_USERS'],
        'weixin' => ['WEIXIN_ALLOWED_USERS', 'WEIXIN_ALLOW_ALL_USERS'], 'bluebubbles' => ['BLUEBUBBLES_ALLOWED_USERS', 'BLUEBUBBLES_ALLOW_ALL_USERS'],
        'qqbot' => ['QQ_ALLOWED_USERS', 'QQ_ALLOW_ALL_USERS'], 'yuanbao' => ['YUANBAO_ALLOWED_USERS', 'YUANBAO_ALLOW_ALL_USERS'],
    ];
    /** Platforms whose bots skip the person allowlist (authz_mixin.py _ALLOW_BOTS_ENV). */
    const BOTS = ['discord' => 'DISCORD_ALLOW_BOTS', 'feishu' => 'FEISHU_ALLOW_BOTS', 'telegram' => 'TELEGRAM_ALLOW_BOTS', 'slack' => 'SLACK_ALLOW_BOTS'];
    /** Platforms with their own dm/group policy variables (gateway/run.py _OWN_POLICY_OPEN_ENV). */
    const POLICY = ['wecom' => 'WECOM', 'weixin' => 'WEIXIN', 'yuanbao' => 'YUANBAO', 'whatsapp' => 'WHATSAPP'];
    const OFF = ['', '0', 'false', 'no', 'off'];

    /**
     * True when this turn's sender is on an explicit allowlist and the gateway lets no one else write on $platform.
     * $env: name => value (null: this process's environment). $configs: the config.yaml texts, false for a file that
     * exists but cannot be read (null: the user's and the managed config.yaml beside HERMES_HOME).
     */
    public static function gated($platform, $sender, array $env = null, array $configs = null)
    {
        $platform = strtolower(trim((string) $platform));
        $sender = trim((string) $sender);
        if (!isset(self::PLATFORMS[$platform]) || $sender === '') {
            return false;
        }
        $get = function ($name) use ($env) {
            $v = $env === null ? getenv($name) : (isset($env[$name]) ? $env[$name] : false);
            return $v === false ? '' : trim((string) $v);
        };
        list($listVar, $allVar) = self::PLATFORMS[$platform];
        foreach (array_filter(['GATEWAY_ALLOW_ALL_USERS', $allVar, isset(self::BOTS[$platform]) ? self::BOTS[$platform] : '']) as $name) {
            if (!in_array(strtolower($get($name)), self::OFF, true)) {
                return false; // anyone (or any bot) may write: the "user" may be anyone
            }
        }
        if (isset(self::POLICY[$platform])) {
            foreach (['_DM_POLICY', '_GROUP_POLICY'] as $suffix) {
                if (strtolower($get(self::POLICY[$platform] . $suffix)) === 'open') {
                    return false;
                }
            }
        }
        foreach ($configs === null ? self::configTexts($get('HERMES_HOME'), $get('HERMES_MANAGED_DIR')) : $configs as $text) {
            if ($text === false || preg_match('/(dm_policy|group_policy)["\']?\s*:\s*["\']?open\b/i', (string) $text) !== 0) {
                return false; // unreadable, a regex engine error, or an open policy
            }
        }
        $allowed = [];
        foreach ([$listVar, 'GATEWAY_ALLOWED_USERS'] as $name) {
            $allowed = array_merge($allowed, self::parseList($get($name)));
        }
        return $allowed && !in_array('*', $allowed, true) && in_array($sender, $allowed, true);
    }

    /** An allowlist as Hermes reads it (authz_mixin.py _coerce_allow_set): a JSON list literal or comma-separated. */
    public static function parseList($raw)
    {
        $raw = trim((string) $raw);
        $list = substr($raw, 0, 1) === '[' ? json_decode($raw, true) : null;
        $parts = is_array($list) ? $list : explode(',', $raw);
        $out = [];
        foreach ($parts as $p) {
            $p = is_scalar($p) ? trim((string) $p) : '';
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** The two config.yaml files the gateway reads its platform settings from; an unknown home is one unreadable file. */
    private static function configTexts($home, $managed)
    {
        if ($home === '') {
            return [false];
        }
        $out = [];
        foreach (array_filter([$home, $managed]) as $dir) {
            $file = rtrim($dir, '/') . '/config.yaml';
            if (file_exists($file)) {
                $text = @file_get_contents($file);
                $out[] = is_string($text) ? $text : false;
            }
        }
        return $out;
    }
}
