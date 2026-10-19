<?php
/**
 * FeedbackPrompt (ADR-0056, amendment 2026-10-19): the question Hermes shows before a feedback send. It opens with
 * where it goes and what goes (the conversation transcript, whole or its newest 1.5 MB, or none, and the system
 * information), then the size, the file holding all of it and the start of the text: what the privacy policy says the
 * question shows. The whole question passes HookUnits::approveShown(), the characters sure to be seen on 24x80: the
 * text start gets what is left, and when even a short one does not fit, message() returns null and the hook blocks.
 */
require_once dirname(__DIR__, 3) . '/lib/HookUnits.php';

class FeedbackPrompt
{
    /** The fewest characters of the text worth showing; with fewer left, the question is not asked (a block instead). */
    const TEXT_MIN = 20;

    /** @param array $s FeedbackDraft::summary() of the draft; $home the user's home, shown as ~ in the path. */
    public static function message(array $s, $path, $home)
    {
        $system = 'system information (model, provider, folder, versions)';
        if (!$s['conversation']) {
            $what = 'Your text and system information (versions), no conversation';
        } elseif ($s['older_part_left_out']) {
            $what = "Your text, the newest 1.5 MB of the conversation transcript and $system";
        } else {
            $what = "Your text, full conversation transcript and $system";
        }
        $home = rtrim((string) $home, '/');
        if ($home !== '' && strpos($path, "$home/") === 0) {
            $path = '~' . substr($path, strlen($home));
        }
        $fixed = "Send to Pulsed Media at pikkumies.mcx.fi? $what, locked for Pulsed Media only, linked to your account. "
            . self::size($s['bytes']) . ", all in $path. Text: \"";
        $left = HookUnits::APPROVE_SHOWN - HookUnits::charCount($fixed) - 1; // the closing quote
        $text = str_replace('"', '\\"', (string) preg_replace('/\s+/u', ' ', Cli::cleanString((string) $s['text_start'])));
        if (HookUnits::charCount($text) > $left) {
            preg_match('/^.{0,' . max(0, $left - 1) . '}/su', $text, $m);
            $text = rtrim(isset($m[0]) ? $m[0] : '', '\\') . '…';
        }
        if ($left < self::TEXT_MIN) {
            return null;
        }
        $message = $fixed . $text . '"';
        return HookUnits::approveShown($message) ? $message : null;
    }

    /** 812 bytes, 48.2 kB, 1.5 MB (decimal, as the 1,500,000-byte limit is written). */
    public static function size($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes < 1000) {
            return "$bytes bytes";
        }
        return $bytes < 1000000 ? round($bytes / 1000, 1) . ' kB' : round($bytes / 1000000, 1) . ' MB';
    }
}
