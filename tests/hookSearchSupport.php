<?php
/** Shared by the search-first tests: a throwaway pikkumies tree, the hook and gate runners and a fake light model (see tests/HookSearchTest.php). */
if (!function_exists('hookSearchTree')) {
    /** A throwaway pikkumies tree with one documentation source, so nothing touches this checkout's local/. */
    function hookSearchTree(array $pages)
    {
        $tmp = sys_get_temp_dir() . '/pk-hook-' . getmypid() . '-' . mt_rand();
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'hooks/search/search-first', 'hooks/search/search-first-gate', 'tools/memory/pikkumies', 'config'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        @mkdir("$tmp/pikkumies/memory", 0700, true);
        @mkdir("$tmp/pikkumies/local/docs-cache/kb", 0700, true);
        // The shipped sources have no copy in this tree, so the search sees these pages alone.
        file_put_contents("$tmp/pikkumies/local/documentationSources.md", "## kb\n- type: kb-json\n- url: https://example.org/kb.json\n- trust: official\n");
        foreach ($pages as $slug => $page) {
            file_put_contents("$tmp/pikkumies/local/docs-cache/kb/$slug.md", "---\ntitle: \"x\"\nurl: \"https://example.org/$slug\"\n---\n# {$page[0]}\n\n{$page[1]}\n");
        }
        return $tmp;
    }

    function hookSearchRun($tree, array $payload)
    {
        list(, $out) = runPhp("$tree/pikkumies/hooks/search/search-first/search-first.php", [], json_encode($payload));
        $d = json_decode($out, true);
        return is_array($d) && isset($d['context']) ? $d['context'] : (trim($out) === '' ? '' : 'UNPARSED: ' . $out);
    }

    function hookSearchTurn($session, $message, $first = false)
    {
        return ['hook_event_name' => 'pre_llm_call', 'session_id' => $session, 'extra' => ['is_first_turn' => $first, 'user_message' => $message]];
    }

    function hookSearchGateBlocks($tree, $session, $tool, $cmd = null, $path = 'x')
    {
        $p = ['hook_event_name' => 'pre_tool_call', 'session_id' => $session, 'tool_name' => $tool, 'tool_input' => $cmd === null ? ['path' => $path] : ['command' => $cmd]];
        list(, $out) = runPhp("$tree/pikkumies/hooks/search/search-first-gate/search-first-gate.php", [], json_encode($p));
        $d = json_decode($out, true);
        return is_array($d) && isset($d['action']) && $d['action'] === 'block';
    }

    /**
     * A fake OpenAI-compatible endpoint (php -S on 127.0.0.1): answers /v1/chat/completions with the
     * text in <dir>/reply (or sleeps when <dir>/mode says so) and appends each request to <dir>/requests.
     * Returns [process, port].
     */
    function hookSearchFakeProvider($dir)
    {
        file_put_contents("$dir/router.php", '<?php
$d = __DIR__;
file_put_contents("$d/requests", json_encode(["auth" => isset($_SERVER["HTTP_AUTHORIZATION"]) ? $_SERVER["HTTP_AUTHORIZATION"] : "", "uri" => $_SERVER["REQUEST_URI"], "body" => json_decode(file_get_contents("php://input"), true)]) . "\n", FILE_APPEND);
if (trim((string) @file_get_contents("$d/mode")) === "sleep") { sleep(9); }
header("Content-Type: application/json");
echo json_encode(["choices" => [["message" => ["role" => "assistant", "content" => (string) @file_get_contents("$d/reply")]]]]);
');
        for ($try = 0; $try < 5; $try++) {
            // A port the OS just handed out, not a random one: with a random port another run's
            // listener could answer while our server had already exited on "address in use".
            $port = testFreePort();
            $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port router.php", [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir);
            for ($i = 0; $i < 50; $i++) {
                $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($s) {
                    fclose($s);
                    if (proc_get_status($p)['running']) {
                        return [$p, $port];
                    }
                    break; // something else answers on this port; ours exited: try another
                }
                usleep(100000);
            }
            proc_terminate($p);
        }
        throw new Exception('fake provider did not start');
    }

    function hookSearchRequests($dir)
    {
        $out = [];
        foreach (is_file("$dir/requests") ? file("$dir/requests", FILE_IGNORE_NEW_LINES) : [] as $l) {
            $out[] = json_decode($l, true);
        }
        return $out;
    }
}
