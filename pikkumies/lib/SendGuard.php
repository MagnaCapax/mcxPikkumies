<?php
/**
 * SendGuard: the check shared by the hook units that hold a send to Pulsed Media until the user's yes (pre_tool_call,
 * ADR-0030): guard/feedback-send (ADR-0056) and guard/ticket-send (ADR-0089). The terminal command exactly
 * `php <this Pikkumies' tool> send <id>`, alone, the path absolute (or ~/) and canonical, gets an approve whose message
 * the unit builds from the draft file itself; the orchestrator's ask policy never passes it on to Hermes: the call is
 * blocked until the review finds the user's own yes in the chat, and blocked outright where no yes can be checked
 * (ADR-0073). Under --yolo, the default run mode, the same review decides. At depth 2 or more (an agent started by an
 * agent) it is blocked here: that agent's "user" messages are agent-written, so no request can be checked. One exception, for /ticket only (ADR-0100): a plain send that the unit's
 * $alone check accepts (a ticket the agent bundled, with ticket.auto on) runs without a question, before that block.
 * Blocked: a path to the tool followed by `send`, the sender's class names
 * with the word send, and a path to the tool with shell expansion and the word send, in a terminal command, code,
 * process input, a file written or patched, or a scheduled job, and whatever else the unit names in $also. A safety
 * prompt that catches the agent's own and casual sends, not a security boundary: an agent steered by text it reads can
 * still run the sender some other way, as it could run curl (AGENTS.md). Fails closed: an unreadable payload or a regex
 * engine error is a block, never an allow.
 */
class SendGuard
{
    /** Hermes tool => the input fields that run or schedule a command; cronjob_manage is read whole. */
    const FIELDS = ['terminal' => ['command'], 'execute_code' => ['code'], 'process_manage' => ['data'], 'write_file' => ['content'],
        'patch' => ['new_string', 'patch']];

    /**
     * The hook's whole run: reads the payload on stdin, prints one answer (or nothing for no opinion) and exits 0.
     * $word: the unit's name in its paths and class names ('feedback'); $script: the tool under the pikkumies directory;
     * $question: callable($id) -> the approve message, or null when it cannot be shown in full; throws CliError for a
     * draft that is missing, changed or already sent. Loaded only for a send, so every other call costs nothing.
     * $also: regex => why, more text the unit blocks in the same places (guard/feedback-send: the exit rating, ADR-0097).
     * $alone: optional callable($id) -> true when this plain send goes out without a question (an automatic ticket the
     * person's setting allows, ADR-0100); it throws CliError to block. Only /ticket passes one.
     */
    public static function main($word, $script, callable $question, array $also = [], callable $alone = null)
    {
        $d = self::decide((string) stream_get_contents(STDIN), $word, $script, $question, $also, $alone);
        if ($d !== null) {
            echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }
        exit(0);
    }

    /** The answer to one payload (see main), or null for no opinion. */
    public static function decide($stdin, $word, $script, callable $question, array $also = [], callable $alone = null)
    {
        $block = function ($why) {
            return ['action' => 'block', 'message' => "Pikkumies safety: $why Not run."];
        };
        $payload = json_decode($stdin, true);
        if (!is_array($payload)) {
            return $block('the tool call could not be read to check it.');
        }
        $tool = isset($payload['tool_name']) ? (string) $payload['tool_name'] : '';
        $input = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
        if ($tool === 'cronjob_manage') {
            $text = (string) json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } elseif (isset(self::FIELDS[$tool])) {
            $parts = [];
            foreach (self::FIELDS[$tool] as $k) {
                $parts[] = isset($input[$k]) && is_scalar($input[$k]) ? (string) $input[$k] : '';
            }
            $text = implode("\n", $parts);
        } else {
            return null; // a tool that runs nothing: no opinion
        }
        $bare = str_replace(['"', "'", '\\'], '', $text);
        if (stripos($bare, $word) === false) {
            return null; // not about this unit: no opinion
        }
        $hay = $text . "\n" . $bare;
        $error = false;
        $hit = function ($re) use ($hay, &$error) {
            $r = preg_match($re, $hay);
            $error = $error || $r === false;
            return $r === 1;
        };
        $w = preg_quote($word, '/');
        // A path to the tool or its directory: a token with the unit's name and a slash or .php in it.
        $ref = '(?<!\S)(?=\S*' . $w . ')(?=\S*(?:\/|\.php))';
        $sendWord = '(?<![A-Za-z0-9_-])send(?![A-Za-z0-9_-])';
        $own = realpath(dirname(__DIR__) . '/' . $script);
        $shown = $own === false ? "<path>/$script" : $own;
        $plain = "`php $shown send <id>`";
        if ($tool !== 'terminal' || preg_match('/^\s*php[^\S\n]+(\S+)[^\S\n]+send[^\S\n]+([0-9a-f]{32})\s*$/D', $text, $m) !== 1) {
            $verb = $hit('/' . $ref . '\S+\s+[\[("\',]*(?:' . $sendWord . '|[^\s]*[$`])/i'); // the word after the tool path
            $classes = $hit('/' . $w . '(?:send|draft)/i') && $hit('/' . $sendWord . '/i');
            $expansion = $hit('/' . $ref . '/i') && $hit('/[*?\[$`]/') && $hit('/' . $sendWord . '/i');
            $named = null;
            foreach ($also as $re => $why) {
                if ($hit($re) && $named === null) {
                    $named = $why;
                }
            }
            if ($error) {
                return $block('a command too long or complex to check.');
            }
            if ($named !== null) {
                return $block($named);
            }
            if ($verb || $classes || $expansion) {
                return $block("a $word send runs only as the plain command $plain in the terminal, on its own. A $word path next to shell "
                    . 'characters ($ * ? [ `) and the word send is blocked too; for a draft whose text has them, write the text to a file and use --text-file.');
            }
            return null; // a draft or list, or a mention that runs no send: they never leave the machine
        }
        // ~/ is what the shell expands with HOME. The path must then be exactly this tool's canonical path: absolute, no
        // symlink, no `..`, so what the user approves is what runs whatever the terminal's directory.
        $path = $m[1];
        $home = rtrim((string) getenv('HOME'), '/');
        if (substr($path, 0, 2) === '~/' && $home !== '') {
            $path = $home . substr($path, 1);
        }
        if ($own === false || $path !== $own) {
            return $block("run the $word send on its own, exactly $plain, with nothing before or after it.");
        }
        $id = $m[2];
        $yourself = "Do not reach the same effect another way; finish the rest and list this send as skipped, with this reason, in the final answer, without the command.";
        require_once __DIR__ . '/Config.php';
        require_once __DIR__ . '/Cli.php';
        require_once __DIR__ . '/HookUnits.php';
        // A send the person's own setting lets go without a question (ADR-0100): it runs, also unattended; the tool applies its caps.
        if ($alone !== null) {
            try {
                if ($alone($id) === true) {
                    return null;
                }
            } catch (CliError $e) {
                return $block($e->getMessage() . '; ' . $e->hint . '.');
            }
        }
        // An agent started by an agent: its "user" messages are written by that agent, so the person's own request cannot be
        // checked here and the send is not run. --yolo (the default run mode) is no reason to block: the review decides.
        require_once __DIR__ . '/NestedAgent.php';
        if (NestedAgent::unattended()) {
            return $block("this agent was started by another agent, so the person's own request for this $word cannot be checked here. It was not sent; finish the rest and say in one line that it was skipped.");
        }
        try {
            $message = $question($id);
        } catch (CliError $e) {
            return $block($e->getMessage() . '; ' . $e->hint . '.');
        }
        // What goes and where first, within what the panel is sure to show (HookUnits::approveShown, ADR-0020 2026-10-19).
        if (!is_string($message) || !HookUnits::approveShown($message)) {
            return $block("the question for this send is too long to show in full. $yourself");
        }
        return ['action' => 'approve', 'rule_key' => 'pikkumies-send:' . $id, 'message' => $message];
    }

    /** 812 bytes, 48.2 kB, 1.5 MB: decimal, as the senders' limits are written. */
    public static function size($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes < 1000) {
            return "$bytes bytes";
        }
        return $bytes < 1000000 ? round($bytes / 1000, 1) . ' kB' : round($bytes / 1000000, 1) . ' MB';
    }

    /**
     * $text as one line with its quotes escaped and cut with … to at most $left characters, so text a model wrote cannot
     * add lines or close its quote in a question; null when fewer than $min characters are left for it.
     */
    public static function excerpt($text, $left, $min = 20)
    {
        if ($left < $min) {
            return null;
        }
        $text = str_replace('"', '\\"', (string) preg_replace('/\s+/u', ' ', Cli::cleanString((string) $text)));
        if (HookUnits::charCount($text) > $left) {
            preg_match('/^.{0,' . max(0, $left - 1) . '}/su', $text, $m);
            $text = rtrim(isset($m[0]) ? $m[0] : '', '\\') . '…';
        }
        return $text;
    }
}
