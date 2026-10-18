<?php
/**
 * NestedAgent: an agent starting another agent with its safety layers off (ADR-0020 amendment 2026-10-18).
 *
 * The launcher exports PIKKUMIES_DEPTH (the depth it was started at, plus one) to Hermes, so every process an agent
 * starts sees 1 or more and the user's own shell sees none. Three uses, all safety prompts and not security
 * (AGENTS.md): the agent can change the variable, and a launcher named through a variable or a script is out of sight.
 * - guard-destructive blocks a command that starts `pikkumies run`, hermes or hermes_cli with a flag that skips
 *   approvals (--yolo; a top-level -z/--oneshot, which turns yolo on by itself), drops the safety hooks (--safe-mode),
 *   the rules or the hook consent, or picks another Hermes home (--profile, -p, `profile use`); a command that sets the
 *   variables doing the same or changes HERMES_HOME or PIKKUMIES_DEPTH; `config set approvals.…`; and the /yolo and
 *   /approvals off|smart slash commands typed into a session.
 * - In an agent at depth 2 or more the guard blocks what it would otherwise ask: whoever answers there is an agent.
 * - `pikkumies run` refuses the same flags and commands when it is started from inside an agent (depth 1 or more).
 * The user's own `pikkumies run --yolo` in their own shell is untouched (ADR-0020: their choice).
 */
class NestedAgent
{
    /** Hermes flag => the shortest prefix its parser accepts (argparse abbreviations, hermes_cli/_parser.py), anywhere. */
    const FLAGS = ['yolo' => 1, 'safe-mode' => 2, 'ignore-rules' => 8, 'ignore-user-config' => 8, 'accept-hooks' => 1];
    /** Hermes' top-level flags that take a value (_parser.py top_level_value_flag_sets; -p/--profile, main.py _scan_profile_flag). */
    const VALUE_LONG = ['oneshot', 'model', 'provider', 'reasoning', 'toolsets', 'resume', 'skills', 'usage-file', 'in', 'profile', 'continue'];
    const VALUE_SHORT = 'zmtrspc';
    /** The program itself, not a directory on the way to it; plain `pikkumies` words are a prompt (Launch::route), only `run` passes flags. */
    const LAUNCHER = '(?<![\w.-])(?:pikkumies[\s"\',\[\]]+run|hermes(?:_cli(?:\.\w+)*)?)(?![\w\/-])';
    const WHY_START = 'starts another agent with approvals, the safety hooks or its rules switched off, or with another config; that is the user\'s decision';
    /** Environment variables that switch approvals, hooks, rules or hook consent off when set. */
    const SWITCHES = ['HERMES_YOLO_MODE', 'HERMES_SAFE_MODE', 'HERMES_IGNORE_RULES', 'HERMES_IGNORE_USER_CONFIG', 'HERMES_ACCEPT_HOOKS'];
    /** Variables whose change or removal loses the Pikkumies config and hooks, or the depth. */
    const HOMES = ['HERMES_HOME', 'PIKKUMIES_DEPTH'];
    const MAX_DEPTH = 99;

    /** The depth this process was started at: 0 when unset; a value that is not a number counts as 1 (inside an agent). */
    public static function depth($raw = null)
    {
        $raw = trim((string) ($raw === null ? getenv('PIKKUMIES_DEPTH') : $raw));
        if ($raw === '') {
            return 0;
        }
        return ctype_digit($raw) ? min((int) substr($raw, 0, 3), self::MAX_DEPTH) : 1;
    }

    /** What the launcher exports to the Hermes it starts. */
    public static function childDepth($raw = null)
    {
        return min(self::depth($raw) + 1, self::MAX_DEPTH);
    }

    /**
     * True in an agent started by another agent (depth 2 or more): whoever answers its approval panel is that agent, through
     * a terminal it started with pty and process_manage submit, or nobody at all (yolo, approvals.mode off).
     */
    public static function unattended($depth = null)
    {
        return self::depth($depth) >= 2;
    }

    /** Every accepted spelling of the long flags in $flags, for a regex alternation. */
    public static function spellings(array $flags)
    {
        $all = [];
        foreach ($flags as $flag => $min) {
            for ($n = strlen($flag); $n >= $min; $n--) {
                $all[] = substr($flag, 0, $n);
            }
        }
        return implode('|', $all);
    }

    /** The guard's block patterns, [regex => why]; guardCommand() reads the Hermes command lines word by word. */
    public static function guardPatterns()
    {
        $l = self::LAUNCHER;
        $switch = implode('|', array_merge(self::SWITCHES, self::HOMES));
        $home = implode('|', self::HOMES);
        // A shell assignment only where the shell reads one: at the start of a command, or after env, export and the like.
        $at = '(?:^|[;&|(`\n{]|\$\()\s*(?:(?:sudo|env|export|declare|typeset|local|readonly|nohup|command|exec)(?:\s+-[^\s=]+)*\s+)*(?:[A-Za-z_]\w*=\S*\s+)*';
        $change = 'changes the settings that switch an agent\'s approvals, safety hooks or rules off, or where its config is; that is the user\'s decision';
        return [
            // The flags no other program uses, anywhere after a launcher (also behind an alias or a quote with ; in it).
            "/$l.*[\\s\"',\\[(](--(" . self::spellings(self::FLAGS) . "|profile)(?=[=\\s\"',\\])]|$))/s" => self::WHY_START,
            "/^(?=.*\\benv\\s+(-\\S+\\s+)*(-i|--ignore-environment)(?=\\s))(?=.*$l)/s" => self::WHY_START,
            "/$at($switch)=|[\\[(,]\\s*[\"']($switch)=|[\"']($switch)[\"']\\s*(\\]\\s*=(?!=)|:)|[(,]\\s*($switch)\\s*=(?!=)|\\b(putenv|setdefault|setenv)\\(\\s*[\"']($switch)\\b"
                . "|\\b(unset|export\\s+-n)\\s+(-\\S+\\s+)*($home)\\b|(-u\\s*|--unset[=\\s]+)($home)\\b|\\b(pop|unsetenv)\\(\\s*[\"']($home)\\b|\\bdel\\s+os\\.environ\\[\\s*[\"']($home)\\b/m" => $change,
            // Hermes' config writer and the dotted YAML spelling of an approvals mode (a sed or printf edit of config.yaml is
            // out of sight); /yolo (this session, re-armed on resume) and /approvals off|smart (approvals.mode, the whole profile).
            '/\bconfig\s+set\s+["\']?approvals\.|\bapprovals\.([a-z_]*_)?mode["\']?\s*(=(?!=)|:)|(?:^|[\s"\'=])\/(yolo|approvals\s+(off|smart))(?![\w\/.-])/m' => $change,
        ];
    }

    /** True when a Hermes command line in one of $texts (one command: up to ; & | or a line break) has a word argvFlag() refuses. */
    public static function guardCommand(array $texts)
    {
        foreach ($texts as $text) {
            foreach (preg_split('/[;&|\n]+/', (string) $text) as $part) {
                preg_match_all('/' . self::LAUNCHER . '/', $part, $hits, PREG_OFFSET_CAPTURE);
                foreach ($hits[0] as $hit) {
                    $words = preg_split('/[\s"\',\[\]()]+/', substr($part, $hit[1] + strlen($hit[0])), -1, PREG_SPLIT_NO_EMPTY);
                    if (self::argvFlag($words) !== '') {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * The first word (or Hermes command) of a Hermes argv that is refused inside an agent, or ''. Read the way Hermes' parser
     * reads it: -z anywhere (chat has no -z), a short cluster that reaches z (-wz, -zgo), --oneshot before the subcommand only
     * (chat --oneshot only answers and exits), skipping the values of the top-level flags that take one (-m chat is a model).
     */
    public static function argvFlag(array $args)
    {
        $args = array_values(array_map('strval', $args));
        $any = '/^--(' . self::spellings(self::FLAGS) . ')(=|$)|^--profile(=|$)|^-p$/';
        $positional = [];
        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $w = $args[$i];
            $hasValue = $i + 1 < $n && ($args[$i + 1] === '' || $args[$i + 1][0] !== '-');
            if (preg_match($any, $w)) {
                return $w;
            }
            if ($w === '--') {
                break;
            }
            if (strncmp($w, '--', 2) === 0) {
                $name = explode('=', substr($w, 2), 2);
                if ($positional === [] && $name[0] !== '' && strpos('oneshot', $name[0]) === 0) {
                    return $w;
                }
                $i += count($name) === 1 && $hasValue && self::takesValue($name[0]) ? 1 : 0;
                continue;
            }
            if (strlen($w) > 1 && $w[0] === '-') {
                for ($k = 1; $k < strlen($w); $k++) {
                    if ($w[$k] === 'z') {
                        return $w;
                    }
                    if (strpos(self::VALUE_SHORT, $w[$k]) !== false) {
                        $i += $k === strlen($w) - 1 && $hasValue ? 1 : 0; // a value attached (-mchat) or the next word
                        break;
                    }
                }
                continue;
            }
            $positional[] = $w;
        }
        $p = array_pad($positional, 3, '');
        if ($p[0] === 'profile' && $p[1] === 'use') {
            return 'profile use';
        }
        return $p[0] === 'config' && $p[1] === 'set' && strpos($p[2], 'approvals.') === 0 ? "config set $p[2]" : '';
    }

    /** True when $name is an unambiguous-or-not prefix of a top-level flag that takes a value: skipping too much only blocks more. */
    private static function takesValue($name)
    {
        foreach (self::VALUE_LONG as $flag) {
            if (strpos($flag, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Why `pikkumies run` refuses $args, or '' when it may start: only inside an agent, only for the flags. */
    public static function refusal(array $args, $raw = null)
    {
        $flag = self::argvFlag($args);
        if ($flag === '' || self::depth($raw) < 1) {
            return '';
        }
        return "Not starting: this pikkumies was started from inside an agent (PIKKUMIES_DEPTH is set), and $flag would run that agent"
            . ' with approvals, the safety hooks or its rules off, or with another config. Show the user the command; if you are'
            . ' the user typing in your own terminal, run `unset PIKKUMIES_DEPTH` first (ADR-0020).';
    }
}
