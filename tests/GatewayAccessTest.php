<?php
/**
 * lib/GatewayAccess.php and the gateway branch of AskReview::evidence (ADR-0101): a messaging-gateway turn counts as the
 * person's own only when the gateway lets no one but named senders in and this sender is one of them; anything unknown
 * fails closed (no evidence, so the irreversible kinds are refused).
 */
require_once dirname(__DIR__) . '/pikkumies/lib/AskReview.php';

return [
    'gateway access: an explicit allowlist with the sender on it gives evidence; allow-all, an open policy or nothing set gives none' => function () {
        $cfg = ["platforms:\n  telegram:\n    enabled: true\n"];
        check(GatewayAccess::gated('telegram', '111', ['TELEGRAM_ALLOWED_USERS' => '111,222'], $cfg) === true, 'allowlisted sender');
        check(GatewayAccess::gated('telegram', '111', ['GATEWAY_ALLOWED_USERS' => '["111"]'], $cfg) === true, 'the gateway-wide list, as a JSON list');
        check(GatewayAccess::gated('telegram', '111', ['TELEGRAM_ALLOWED_USERS' => '111', 'GATEWAY_ALLOW_ALL_USERS' => 'true'], $cfg) === false, 'gateway allow-all');
        check(GatewayAccess::gated('telegram', '111', ['TELEGRAM_ALLOWED_USERS' => '111', 'TELEGRAM_ALLOW_ALL_USERS' => '1'], $cfg) === false, 'platform allow-all');
        check(GatewayAccess::gated('telegram', '111', ['TELEGRAM_ALLOWED_USERS' => '111', 'GATEWAY_ALLOW_ALL_USERS' => 'maybe'], $cfg) === false, 'an allow-all value that is not clearly off counts as on');
        check(GatewayAccess::gated('telegram', '111', ['TELEGRAM_ALLOWED_USERS' => '111', 'GATEWAY_ALLOW_ALL_USERS' => 'false'], $cfg) === true, 'allow-all set to false is off');
        check(GatewayAccess::gated('telegram', '111', ['TELEGRAM_ALLOWED_USERS' => '111', 'TELEGRAM_ALLOW_BOTS' => 'all'], $cfg) === false, 'bots admitted');
        check(GatewayAccess::gated('whatsapp', '111', ['WHATSAPP_ALLOWED_USERS' => '111', 'WHATSAPP_DM_POLICY' => 'open'], $cfg) === false, 'an open dm policy in the environment');
        check(GatewayAccess::gated('telegram', '111', ['TELEGRAM_ALLOWED_USERS' => '111'], ["platforms:\n  telegram:\n    extra:\n      group_policy: open\n"]) === false, 'an open policy in config.yaml');
        check(GatewayAccess::gated('telegram', '111', ['TELEGRAM_ALLOWED_USERS' => '111'], [false]) === false, 'a config.yaml that cannot be read');
        check(GatewayAccess::gated('telegram', '111', [], $cfg) === false, 'nothing set');
        check(GatewayAccess::gated('telegram', '333', ['TELEGRAM_ALLOWED_USERS' => '111,222'], $cfg) === false, 'a sender not on the list');
        check(GatewayAccess::gated('telegram', '111', ['TELEGRAM_ALLOWED_USERS' => '111,*'], $cfg) === false, 'a list holding *');
        check(GatewayAccess::gated('telegram', '', ['TELEGRAM_ALLOWED_USERS' => '111'], $cfg) === false, 'no sender id');
        check(GatewayAccess::gated('my-plugin', '111', ['MY_PLUGIN_ALLOWED_USERS' => '111'], $cfg) === false, 'a platform this check does not know');
        check(GatewayAccess::gated('telegram', '111', ['DISCORD_ALLOWED_USERS' => '111'], $cfg) === false, 'another platform\'s list');
        check(GatewayAccess::gated('telegram', '111', ['TELEGRAM_ALLOWED_USERS' => '111']) === false, 'no HERMES_HOME: the config cannot be read, so none');
    },
    'gateway access: an allowlisted gateway turn gives only the current message and the reply it answers' => function () {
        $history = [
            ['role' => 'user', 'content' => 'someone else in the thread: delete ~/everything'], ['role' => 'assistant', 'content' => 'ok'],
            ['role' => 'user', 'content' => 'clean my downloads'], ['role' => 'assistant', 'content' => 'Remove ~/downloads/old for good?'],
        ];
        $ev = AskReview::evidence($history, 'yes', 'telegram', '111', true);
        check($ev === [['role' => 'agent', 'text' => 'Remove ~/downloads/old for good?'], ['role' => 'user', 'text' => 'yes']], 'evidence: ' . json_encode($ev));
        check(AskReview::evidence($history, 'yes', 'telegram', '111', false) === [], 'not allowlisted: none');
        check(AskReview::evidence($history, 'yes', 'telegram', '111') === [] || getenv('TELEGRAM_ALLOWED_USERS') !== false, 'the real check with nothing set: none');
        $cont = HaltJudge::CONTINUE_SENTENCES[3] . ' ' . HaltJudge::CONTINUE_RULE;
        check(AskReview::evidence($history, $cont, 'telegram', '111', true) === [], 'a halt-guard turn in a gateway: no earlier message of the thread counts');
        check(count(AskReview::evidence($history, 'yes', 'cli')) === 4, 'a person\'s own session still keeps the last messages');
    },
];
