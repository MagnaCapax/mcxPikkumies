<?php
/**
 * The feedback send's question (ADR-0056, amendment 2026-10-19): it opens with where it goes and what goes (the whole
 * conversation transcript, its newest 1.5 MB, or none, and system information), then size, file and the start of the
 * text, and the whole question passes HookUnits::approveShown(); one that cannot fit is a block. With approvals off
 * (HERMES_YOLO_MODE) or at depth 2 or more the plain send is blocked and the user is given the command to run.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackDraft.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackPrompt.php';

if (!function_exists('feedbackPromptSummary')) {
    function feedbackPromptSummary($conversation, $older, $text = 'The qBittorrent tool times out', $bytes = 48213)
    {
        return ['conversation' => $conversation, 'older_part_left_out' => $older, 'bytes' => $bytes, 'text_start' => $text,
            'session_id' => 's-1', 'session_title' => 'Magnets', 'text_bytes' => strlen($text), 'conversation_bytes' => 10];
    }

    /** A tree with lib, config, the feedback unit and the send guard, and one draft; returns [tree, id, the guard's answer to $env]. */
    function feedbackPromptHook(array $env)
    {
        $tree = realpath(testTempDir('pk-fbprompt'));
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'config', 'tools/system/feedback', 'hooks/guard/feedback-send'] as $d) {
            @mkdir("$tree/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tree/pikkumies/$d/" . basename($f));
            }
        }
        $id = FeedbackDraft::create('it times out', 'conversation text', [], 1792300000, "$tree/pikkumies/local/state/feedback", ['s-1', 'Magnets'])['id'];
        $cmd = "php $tree/pikkumies/tools/system/feedback/feedback.php send $id";
        list(, $out) = testWithEnv($env + ['HOME' => $tree, 'HERMES_YOLO_MODE' => null, 'PIKKUMIES_DEPTH' => null], function () use ($tree, $cmd) {
            return runPhp("$tree/pikkumies/hooks/guard/feedback-send/feedback-send.php", [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
        });
        testRmTree($tree);
        return [$tree, $id, json_decode($out, true)];
    }
}

$id = str_repeat('0123456789abcdef', 2);
return [
    'feedback prompt: opens with destination and what goes, whole transcript, newest 1.5 MB or none, then size, file and text' => function () use ($id) {
        $path = "/home/u/pikkumies/local/state/feedback/$id.json";
        $full = FeedbackPrompt::message(feedbackPromptSummary(true, false), $path, '/home/u/');
        check(strpos($full, 'Send to Pulsed Media at pikkumies.mcx.fi? Your text, full conversation transcript and system information (model, provider, folder, versions)') === 0
            && strpos($full, "48.2 kB, all in ~/pikkumies/local/state/feedback/$id.json") !== false
            && substr($full, -38) === 'Text: "The qBittorrent tool times out"', $full);
        $cut = FeedbackPrompt::message(feedbackPromptSummary(true, true), $path, '/home/u');
        check(strpos($cut, 'the newest 1.5 MB of the conversation transcript and system information') !== false && strpos($cut, 'full conversation') === false, $cut);
        $none = FeedbackPrompt::message(feedbackPromptSummary(false, false), "/opt/pk/local/state/feedback/$id.json", '/home/u');
        check(strpos($none, 'no conversation') !== false && strpos($none, 'transcript') === false && strpos($none, "/opt/pk/local/state/feedback/$id.json") !== false, $none);
    },
    'feedback prompt: the whole question stays under what the panel is sure to show, one line, its text start cut to fit' => function () use ($id) {
        $text = str_repeat("Les “quotes” \"and\" \x1b[31mred\n lines ", 20);
        foreach ([[true, false], [true, true], [false, false]] as $c) {
            foreach (["/home/u/pikkumies/local/state/feedback/$id.json", "/opt/pikkumies-tree/local/state/feedback/$id.json"] as $path) {
                $m = FeedbackPrompt::message(feedbackPromptSummary($c[0], $c[1], $text, 1499999), $path, '/home/u');
                check(is_string($m) && HookUnits::approveShown($m) && HookUnits::charCount($m) > HookUnits::APPROVE_SHOWN - 3, "not filled to the cap or too long: " . HookUnits::charCount((string) $m) . " $m");
                check(strpos($m, "\n") === false && strpos($m, "\x1b") === false && substr($m, -4) === '…"' && strpos($m, '1.5 MB, all in') !== false, $m);
                check(preg_match('/Text: "(.*)"$/u', $m, $t) === 1 && HookUnits::charCount($t[1]) >= FeedbackPrompt::TEXT_MIN, 'text start too short: ' . $m);
            }
        }
        check(FeedbackPrompt::message(feedbackPromptSummary(true, true), '/' . str_repeat('d', 200) . "/$id.json", '/home/u') === null, 'a question that cannot fit was not refused');
        check(FeedbackPrompt::size(812) === '812 bytes' && FeedbackPrompt::size(48213) === '48.2 kB' && FeedbackPrompt::size(1499999) === '1.5 MB', 'size words');
    },
    'feedback prompt: the guard asks with that question, and blocks the send with approvals off or at depth 2, giving the command' => function () {
        list($tree, $id, $d) = feedbackPromptHook([]);
        check(is_array($d) && $d['action'] === 'approve' && $d['rule_key'] === "pikkumies-send:$id"
            && strpos($d['message'], 'Send to Pulsed Media at pikkumies.mcx.fi?') === 0 && HookUnits::approveShown($d["message"]), json_encode($d));
        foreach ([['HERMES_YOLO_MODE' => '1'], ['HERMES_YOLO_MODE' => 'true'], ['HERMES_YOLO_MODE' => 'On'], ['PIKKUMIES_DEPTH' => '2']] as $env) {
            list($tree, $id, $d) = feedbackPromptHook($env);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'nobody would be asked') !== false
                && strpos($d['message'], "php $tree/pikkumies/tools/system/feedback/feedback.php send $id") !== false, json_encode($env) . ' -> ' . json_encode($d));
        }
        list(, , $d) = feedbackPromptHook(['HERMES_YOLO_MODE' => '0', 'PIKKUMIES_DEPTH' => '1']);
        check(is_array($d) && $d['action'] === 'approve', "the user's own session (depth 1, yolo '0') was not asked: " . json_encode($d));
    },
];
