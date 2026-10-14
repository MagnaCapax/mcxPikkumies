<?php
/** Tests for lib/Cli.php and lib/CliHuman.php through a small throwaway tool. */
function cliFixtureTool()
{
    $dir = sys_get_temp_dir() . '/pk-cli-' . getmypid();
    @mkdir($dir, 0700, true);
    $lib = dirname(__DIR__) . '/pikkumies/lib';
    $tool = "$dir/fixture.php";
    file_put_contents($tool, '<?php
require ' . var_export("$lib/Cli.php", true) . ';
require ' . var_export("$lib/CliHuman.php", true) . ';
Cli::run($argv, [
    "list" => function ($args, $opt) {
        $out = [];
        for ($i = 0; $i < (int) (isset($opt["n"]) ? $opt["n"] : 3); $i++) {
            $out[] = ["name" => "t$i", "size" => 1048576 * ($i + 1), "progress" => 0.5];
        }
        return $out;
    },
    "nasty" => function ($args, $opt) {
        return [["name" => "a\xffb\x1b[31mred\xe2\x80\xaeevil", "password" => "hunter2", "sub" => ["token" => "x", "ok" => 1]]];
    },
    "big" => function ($args, $opt) {
        return array_fill(0, 100, ["blob" => str_repeat("x", 2000)]);
    },
    "write" => function ($args, $opt) {
        return ["records" => [["id" => isset($args[0]) ? $args[0] : ""]], "status" => ["verified" => true]];
    },
    "fail" => function ($args, $opt) {
        throw new CliError("client not running", "start it from the panel");
    },
    "leak" => function ($args, $opt) {
        return [["rpc_password" => "a", "webui_pass" => "b", "access_token" => "c", "Authorization" => "d", "x" => ["api-key" => "e"],
            "tracker" => "https://t.example/announce.php?passkey=0123456789abcdef&x=1", "t2" => "https://t.example/0123456789abcdef0123/announce",
            "url" => "http://user:pw9@host.example/x", "total_tokens" => 5, "t3" => "https://t.example/a.php?pid=0123456789abcdef9", "t4" => "https://t.example/tracker/0123456789abcdef9z/announce"]];
    },
    "boom" => function ($args, $opt) {
        throw new RuntimeException("boom secret=hunter2");
    },
    "one" => function ($args, $opt) {
        return ["name" => "single", "size" => 1];
    },
    "evil" => function ($args, $opt) {
        return ["records" => [], "status" => ["ok" => false, "password" => "pw", "note" => "x"]];
    },
], "usage: fixture.php list|nasty|big|write|fail [--human]", [], ["n"]);
');
    return $tool;
}

function cliLines($out)
{
    return array_map(function ($l) {
        return json_decode($l, true);
    }, array_values(array_filter(explode("\n", $out), 'strlen')));
}

return [
    'cli: JSON Lines records then one status line, exit 0' => function () {
        list($code, $out) = runPhp(cliFixtureTool(), ['list']);
        $l = cliLines($out);
        check($code === 0 && count($l) === 4, "expected 3 records + status: $out");
        check($l[0]['name'] === 't0' && $l[0]['size'] === 1048576, 'raw units changed');
        check($l[3] === ['ok' => true, 'count' => 3], 'bad status line: ' . json_encode($l[3]));
    },
    'cli: invalid UTF-8, escapes, bidi and secret fields are cleaned' => function () {
        list($code, $out) = runPhp(cliFixtureTool(), ['nasty']);
        $l = cliLines($out);
        check($code === 0 && is_array($l[0]), "record lost: $out");
        check(strpos($l[0]['name'], "\x1b") === false && strpos($l[0]['name'], "\u{202E}") === false, 'control or bidi survived');
        check(strpos($l[0]['name'], 'red') !== false && strpos($l[0]['name'], 'evil') !== false, 'text lost');
        check(!isset($l[0]['password']) && !isset($l[0]['sub']['token']) && $l[0]['sub']['ok'] === 1, 'secret fields not dropped');
    },
    'cli: --limit and the byte budget truncate and say so' => function () {
        list(, $out) = runPhp(cliFixtureTool(), ['list', '--n', '30']);
        $l = cliLines($out);
        $s = end($l);
        check(count($l) === 21 && $s['truncated'] === true && $s['total'] === 30, 'default limit not applied: ' . json_encode($s));
        list(, $out) = runPhp(cliFixtureTool(), ['big', '--limit', '0']);
        $l = cliLines($out);
        $s = end($l);
        check(!empty($s['truncated']) && strlen($out) < Cli::BUDGET + 4096, 'byte budget not applied');
    },
    'cli: status extras and positional args reach the status line' => function () {
        list(, $out) = runPhp(cliFixtureTool(), ['write', 'abc123']);
        $l = cliLines($out);
        check($l[0]['id'] === 'abc123' && $l[1]['verified'] === true, 'status extra missing: ' . $out);
    },
    'cli: errors give ok:false with a hint and exit 1; --human errors go to stderr' => function () {
        list($code, $out) = runPhp(cliFixtureTool(), ['fail']);
        $l = cliLines($out);
        check($code === 1 && $l[0]['ok'] === false && $l[0]['hint'] === 'start it from the panel', "bad error: $out");
        list($code, $out) = runPhp(cliFixtureTool(), ['fail', '--human']);
        check($code === 1 && trim($out) === '', 'human error leaked to stdout');
    },
    'cli: usage exits 2, --help exits 0, unknown verb exits 2' => function () {
        list($code) = runPhp(cliFixtureTool(), []);
        check($code === 2, 'no verb should exit 2');
        list($code, $out) = runPhp(cliFixtureTool(), ['--help']);
        check($code === 0 && strpos($out, 'usage:') !== false, '--help failed');
        list($code) = runPhp(cliFixtureTool(), ['nope']);
        check($code === 2, 'unknown verb should exit 2');
    },
    'cli: --human prints a table with converted units and no escapes' => function () {
        list($code, $out) = runPhp(cliFixtureTool(), ['list', '--human']);
        check($code === 0 && strpos($out, '1.0 MiB') !== false && strpos($out, '50%') !== false, "units not converted: $out");
        check(strpos($out, 'ok, 3 shown') !== false, 'no human status line');
        list(, $out) = runPhp(cliFixtureTool(), ['nasty', '--human']);
        check(strpos($out, "\x1b") === false, 'escape reached the terminal');
    },
    'cli: secret-like fields and secrets inside URLs never print' => function () {
        list($code, $out) = runPhp(cliFixtureTool(), ['leak']);
        $r = cliLines($out)[0];
        foreach (['rpc_password', 'webui_pass', 'access_token', 'Authorization'] as $k) {
            check(!isset($r[$k]), "$k printed");
        }
        check($r['x'] === [] && $r['total_tokens'] === 5, 'nested api-key printed or a harmless field dropped: ' . $out);
        check(strpos($out, '0123456789abcdef') === false && strpos($out, 'pw9') === false, 'a passkey or URL password printed: ' . $out);
    },
    'cli: a bug still ends in a status line, without a stack trace' => function () {
        list($code, $out) = runPhp(cliFixtureTool(), ['boom']);
        $l = cliLines($out);
        check($code === 1 && end($l)['ok'] === false && strpos($out, 'hunter2') === false && strpos($out, 'Stack trace') === false, 'uncaught exception: ' . $out);
    },
    'cli: one record is one record; a verb cannot fake ok or print secrets in the status' => function () {
        list(, $out) = runPhp(cliFixtureTool(), ['one']);
        $l = cliLines($out);
        check(count($l) === 2 && $l[0]['name'] === 'single', 'single record split: ' . $out);
        list($code, $out) = runPhp(cliFixtureTool(), ['evil']);
        $l = cliLines($out); $s = end($l);
        check($code === 0 && $s['ok'] === true && !isset($s['password']) && $s['note'] === 'x', 'status extra overrode ok or leaked: ' . $out);
    },
    'cli: usage errors end in a status line listing the verbs; an unknown option does not eat the verb' => function () {
        list($code, $out) = runPhp(cliFixtureTool(), ['nope']);
        $l = cliLines($out); $s = end($l);
        check($code === 2 && $s['ok'] === false && strpos($s['hint'], 'list') !== false, 'no status line on usage error: ' . $out);
        list($code, $out) = runPhp(cliFixtureTool(), ['--limit', 'list']);
        check($code === 0, 'an option swallowed the verb: ' . $out);
    },
    'cli: an unknown option (--dry-run, a typo) is a usage error and the verb never runs' => function () {
        foreach ([['write', '--dry-run'], ['write', '--with-dat'], ['write', '--frobnicate=1']] as $argv) {
            list($code, $out) = runPhp(cliFixtureTool(), $argv);
            $l = cliLines($out); $s = end($l);
            check($code === 2 && $s['ok'] === false && strpos($s['error'], 'unknown option') !== false, 'unknown option accepted: ' . implode(' ', $argv) . ' -> ' . $out);
            check(strpos($out, 'wrote') === false, 'the verb ran despite an unknown option: ' . $out);
        }
        list($code) = runPhp(cliFixtureTool(), ['list', '--limit=2', '--human', '--yes', '--fields', 'id']);
        check($code === 0, 'global options were refused');
        list($code, $out) = runPhp(cliFixtureTool(), ['list', '--yes=no']);
        check($code === 2 && strpos($out, 'unknown option') !== false, 'a value on an on/off option was accepted: ' . $out);
    },
    'cli: --offset pages through records; the status says where the next page starts' => function () {
        list($code, $out) = runPhp(cliFixtureTool(), ['list', '--n', '7', '--limit', '3']);
        $l = cliLines($out); $s = end($l);
        check($code === 0 && $s['count'] === 3 && $s['total'] === 7 && $s['next_offset'] === 3, 'first page: ' . $out);
        list($code, $out) = runPhp(cliFixtureTool(), ['list', '--n', '7', '--limit', '3', '--offset', '6']);
        $l = cliLines($out); $s = array_pop($l);
        check($code === 0 && $s['count'] === 1 && !isset($s['truncated']) && count($l) === 1, 'last page: ' . $out);
    },
    'cli: parse handles --k=v, --k v, flags and --' => function () {
        list($verb, $args, $opt) = Cli::parse(['x', 'show', '--limit=5', '--fields', 'a,b', '--human', '--', '--not-an-option']);
        check($verb === 'show' && $opt['limit'] === '5' && $opt['fields'] === 'a,b' && $opt['human'] === true, 'options misparsed');
        list(, $a2, $o2) = Cli::parse(['x', 'remove', '--with-data', 'abc', '--expect', '1GiB'], ['remove'], ['with-data']);
        check($a2 === ['abc'] && $o2['with-data'] === true && $o2['expect'] === '1GiB', 'a declared flag took the next word');
        check($args === ['--not-an-option'], 'args after -- misparsed');
    },
];
