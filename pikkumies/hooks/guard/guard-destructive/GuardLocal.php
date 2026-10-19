<?php
/**
 * The local-hooks rule of the guard (ADR-0054), loaded by guard-destructive.php. A write that can land in local/hooks/
 * (code that runs on tool calls from the next start), local/tools/ (notes for the agent's instructions) or the record
 * of what changed there (local/state/local-changes.json) asks the user first; rule_key pikkumies-local:<sha1-16>.
 * write_file and patch show the path and the start of the text, a command is shown whole.
 *
 * A command is asked when it writes (redirect, cp, mv, tar, ln, mkdir, sed -i, open( ...) and either names such a path,
 * names pikkumies/local itself as a target (mv x ~/pikkumies/local/, tar -C ~/pikkumies/local), puts a glob or brace
 * right after pikkumies/ or pikkumies/local/ (~/pikkumies/local/h*s, {hooks,}), cds into pikkumies/local or below, or
 * cds into pikkumies/ and names local/hooks, local/tools or local itself relative to it, or cds into a directory below
 * pikkumies/ and names ../local (any number of ../). Every path is also read with /./ and seg/../ folded away
 * (~/pikkumies/hooks/../local/hooks). Paths are matched from pikkumies/, so /usr/local/tools is not asked; write_file
 * and patch also take a relative local/hooks or local/tools.
 * Out of its sight: a relative path after a cd it did not see (an earlier call, or a directory reached through a variable
 * or a glob), a glob or variable before pikkumies/, a path built in a variable or in code, and a program that writes
 * without a write word above (a script that copies files itself). A prompt against mistakes and naive steering, not a
 * stop on self-modification: the agent runs as this account.
 */

/** The guard's answer for a write to local hooks or notes, or null for no opinion. $texts: the call's forms (HookPayload). */
function guardLocal($kind, $tool, array $input, $command, array $texts)
{
    // Also read every form with seg/.. folded away: ~/pikkumies/hooks/../local/hooks is ~/pikkumies/local/hooks.
    $texts = array_values(array_unique(array_merge($texts, array_map('guardLocalFold', $texts))));
    $end = '(?=$|[\s"\';&|)<>])';
    $path = '#pikkumies/+(local/+(hooks|tools)(?![A-Za-z0-9_.-])|local/*' . $end . '|(local/+)?[^/\s"\';&|)<>]*[*?\[{])|\blocal-changes\.json\b#';
    $shown = 'where ~/pikkumies/local/hooks and local/tools are: local hooks run on tool calls from the next start and local notes reach the agent\'s instructions; check it is what you asked for';
    if ($kind === 'file') {
        // patch also takes a V4A patch whose paths are inside the patch text, not in path. A relative path is read as
        // Hermes reads it, from where Hermes runs, which may be ~/pikkumies: local/hooks/... is asked too.
        $patch = isset($input['patch']) ? (string) $input['patch'] : '';
        $relFile = '#(^|[\s:])(\./)?local/+(hooks|tools)(?![A-Za-z0-9_.-])#';
        $all = implode(' ', $texts) . ' ' . $patch . ' ' . guardLocalFold($patch);
        if (!in_array($tool, ['write_file', 'patch'], true) || (!preg_match($path, $all) && !preg_match($relFile, $all))) {
            return null;
        }
        $text = isset($input['content']) ? $input['content'] : (isset($input['new_string']) ? $input['new_string'] : $patch);
        $text = (string) preg_replace('/\s+/', ' ', (string) $text);
        $head = strlen($text) > 600 ? (function_exists('mb_strcut') ? mb_strcut($text, 0, 600, 'UTF-8') : substr($text, 0, 600)) . ' [...]' : $text;
        $id = substr(sha1((string) json_encode($input, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)), 0, 16);
        $where = isset($input['path']) ? (string) $input['path'] : 'the files in the patch';
        $approveMessage = "Pikkumies: $tool writes $where, $shown. Text (" . strlen($text) . " characters, the first 600 shown) [$id]: $head";
        if (!HookUnits::approveShown($approveMessage)) {
            return ['action' => 'block', 'message' => "Pikkumies safety: a write that can reach local hooks or notes, and the question is too long to show the user in full. Not run. Write less at once."];
        }
        return ['action' => 'approve', 'rule_key' => 'pikkumies-local:' . $id, 'message' => $approveMessage, 'kind' => 'local'];
    }
    $write = '#(?<![0-9&])>(?!&)|\b(cp|mv|install|ln|tee|touch|mkdir|rsync|tar|unzip|cpio|patch|curl|wget|dd|truncate|chmod|chown|git)\b'
        . '|\b(sed|perl)\b[^;&|\n]*\s-[a-zA-Z]*i|\b(open|write_text|write_bytes|file_put_contents|fopen|copy|rename|symlink|makedirs|mkdir|move)\s*\(#';
    // A cd into pikkumies/local or below makes every write in the command a candidate; a cd into pikkumies/ itself
    // only together with a relative local/... path of the same kinds as above.
    $cd = '(^|[\s;&|(`])(cd|pushd)\s+["\']?[^\s;&|"\']*pikkumies';
    $cdLocal = '#' . $cd . '/+local(/[^\s;&|"\']*)?["\']?' . $end . '#';
    $cdRoot = '#' . $cd . '/*["\']?' . $end . '#';
    $cdSub = '#' . $cd . '/+[^\s;&|"\']+["\']?' . $end . '#'; // pikkumies/hooks, pikkumies/tools/x ...
    $up = '(\.\./+)+';
    $relative = '#(^|[\s"\'=:])(\./)?(local/+(hooks|tools)(?![A-Za-z0-9_.-])|local/*' . $end . '|local/+[^/\s"\';&|)<>]*[*?\[{])#';
    $hit = false;
    foreach ($texts as $t) {
        $hit = $hit || (preg_match($write, $t) && (preg_match($path, $t) || preg_match($cdLocal, $t) || (preg_match($cdRoot, $t) && preg_match($relative, $t))
            || (preg_match($cdSub, $t) && preg_match(str_replace('(\./)?', $up, $relative), $t))));
    }
    if (!$hit) {
        return null;
    }
    $id = substr(sha1($command), 0, 16);
    $approveMessage = "Pikkumies: a write $shown. Command [$id]: $command";
    if (!HookUnits::approveShown($approveMessage)) {
        return ['action' => 'block', 'message' => 'Pikkumies safety: a write that can reach local hooks or notes, and the command is too long to show the user in full. Not run. Split it into smaller commands.'];
    }
    return ['action' => 'approve', 'rule_key' => 'pikkumies-local:' . $id, 'message' => $approveMessage, 'kind' => 'local'];
}

/** $s with /./ and seg/../ folded away, so a path that steps out and back in reads as the path it reaches. */
function guardLocalFold($s)
{
    for ($i = 0; $i < 16; $i++) {
        $next = (string) preg_replace(['#/\.(?=/)#', '#(^|/|[\s"\'=:])(?!\.\.(/|$))[^/\s"\';&|)<>]+/\.\.(?=/|$|[\s"\';&|)<>])/?#'], ['', '$1'], $s);
        if ($next === $s) {
            return $s;
        }
        $s = $next;
    }
    return $s;
}
