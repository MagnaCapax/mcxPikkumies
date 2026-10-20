<?php
/**
 * TicketPrompt (ADR-0089): the question before a ticket is sent. Where it goes and from which account email first,
 * then the service, priority, the files with their sizes, the file holding all of it, and last the subject and the start
 * of the message on one line with quotes escaped, cut to fit. The whole question passes HookUnits::approveShown(); when
 * even a short text does not fit (a long email or many long file names), message() returns null and the hook blocks.
 */
require_once dirname(__DIR__, 3) . '/lib/HookUnits.php';
require_once dirname(__DIR__, 3) . '/lib/SendGuard.php';

class TicketPrompt
{
    /** @param array $s TicketDraft::summary() of the draft; $home the user's home, shown as ~ in the path. */
    public static function message(array $s, $path, $home)
    {
        $files = [];
        foreach ($s['attachments'] as $a) {
            $files[] = $a['name'] . ' (' . SendGuard::size($a['bytes']) . ')';
        }
        $home = rtrim((string) $home, '/');
        if ($home !== '' && strpos($path, "$home/") === 0) {
            $path = '~' . substr($path, strlen($home));
        }
        $fixed = "Open a Pulsed Media support ticket at pulsedmedia.com as {$s['email']}, with your AI key? Service "
            . ($s['service'] === '' ? 'none' : $s['service']) . ", priority {$s['priority']}, "
            . ($files ? 'files: ' . implode(', ', $files) : 'no files') . ". All in $path. Text: \"";
        $text = SendGuard::excerpt($s['subject'] . ' | ' . $s['message_start'], HookUnits::APPROVE_SHOWN - HookUnits::charCount($fixed) - 1);
        if ($text === null) {
            return null;
        }
        $message = $fixed . $text . '"';
        return HookUnits::approveShown($message) ? $message : null;
    }
}
