<?php
/**
 * lib/Links.php (ADR-0060): the one link parser and resolver that search, save, append, the `links` verb and the
 * tracked-markdown link test share. Each rule here is one that a second, diverging copy got wrong before.
 */
return [
    'links: targets() takes [text](target) and [text](target "title"), drops the #fragment, and skips web addresses, mailto, anchor-only links, code spans, fenced blocks and YAML front matter' => function () {
        $text = "---\nnote: [front](front.md)\n---\n# T\n\nSee [a](a.md), [b](dir/b.md#part) and [c](../c.md \"C page\").\n"
            . "Not links: [web](https://x.example/y.md) [mail](mailto:x@example.org) [top](#top) `[span](span.md)` ``[two](two.md) ` ``\n"
            . "```md\n[fenced](fenced.md)\n```\n~~~\n[tilde](tilde.md)\n~~~\nAfter: [d](d.md)\n";
        $t = Links::targets($text);
        check($t === ['a.md', 'dir/b.md', '../c.md', 'd.md'], 'targets: ' . json_encode($t));
    },
    'links: a stray backtick does not hide the links of the next paragraph, and a fenced block indented inside a list item is skipped' => function () {
        $text = "A stray ` backtick here.\n\n[x](x.md) and `[y](y.md)`\n\n- item\n\n    ```\n    [z](z.md)\n\n    [z2](z2.md)\n    ```\n- [w](w.md)\n";
        $t = Links::targets($text);
        check($t === ['x.md', 'w.md'], 'targets: ' . json_encode($t));
    },
    'links: rewrite() replaces only the target, keeping the #fragment and the title, leaves a null-mapped link and code untouched' => function () {
        $text = "[a](x.md#sec) [b](y.md \"Y\") [c](keep.md) `[d](x.md)`\n";
        $out = Links::rewrite($text, function ($t) {
            return $t === 'keep.md' ? null : "new/$t";
        });
        check($out === "[a](new/x.md#sec) [b](new/y.md \"Y\") [c](keep.md) `[d](x.md)`\n", 'rewrite: ' . $out);
    },
    'links: resolve() tries an absolute target, then relative to the file, then each base in order; an empty base is the target as written (the current directory); $accept can skip a candidate' => function () {
        $tmp = testTempDir('pk-links-resolve');
        $cwd = getcwd();
        try {
            $real = realpath($tmp);
            foreach (['from', 'base1', 'base2', 'cwd'] as $d) {
                mkdir("$real/$d", 0700);
            }
            file_put_contents("$real/from/f.md", 'x');
            file_put_contents("$real/base1/b.md", 'x');
            file_put_contents("$real/base2/b.md", 'x');
            file_put_contents("$real/cwd/c.md", 'x');
            $from = "$real/from/page.md";
            check(Links::resolve("$real/base2/b.md", $from) === "$real/base2/b.md", 'absolute');
            check(Links::resolve('f.md', $from, ["$real/base1"]) === "$real/from/f.md", 'relative to the file first');
            check(Links::resolve('b.md', $from, ["$real/base1", "$real/base2"]) === "$real/base1/b.md", 'bases in order');
            check(Links::resolve('b.md', $from) === false, 'no base given: a base-relative link must not resolve');
            check(Links::resolve('b.md', $from, ["$real/base1", "$real/base2"], function ($p) use ($real) {
                return strpos($p, "$real/base2/") === 0;
            }) === "$real/base2/b.md", '$accept skips the first base');
            chdir("$real/cwd");
            check(Links::resolve('c.md', $from, []) === false && Links::resolve('c.md', $from, ['']) === "$real/cwd/c.md", 'the current directory only through the empty base');
            check(Links::resolve('/b.md', $from, ["$real/base1"]) === "$real/base1/b.md", 'a leading slash is also tried under each base (save has always accepted it)');
        } finally {
            chdir($cwd);
            testRmTree($tmp);
        }
    },
    'links: relative() gives the path from a file\'s directory to another file: same directory, down, up and across' => function () {
        check(Links::relative('/m/lessons/a/x.md', '/m/lessons/a/y.md') === 'y.md', 'same dir');
        check(Links::relative('/m/lessons/a/x.md', '/m/reference/r.md') === '../../reference/r.md', 'across');
        check(Links::relative('/m/x.md', '/m/lessons/a/y.md') === 'lessons/a/y.md', 'down');
        check(Links::relative('/m/lessons/a/x.md', '/m/y.md') === '../../y.md', 'up');
    },
    'links: scan() gives outbound and inbound edges between markdown files and lists the targets that resolve to nothing; non-.md targets are not edges' => function () {
        $tmp = testTempDir('pk-links-scan');
        try {
            $real = realpath($tmp);
            mkdir("$real/sub", 0700);
            file_put_contents("$real/a.md", "[b](sub/b.md) [c](sub/c.md) [gone](gone.md) [file](notes.txt)\n");
            file_put_contents("$real/sub/b.md", "[a](../a.md)\n");
            file_put_contents("$real/sub/c.md", "# c\n");
            list($out, $in, $broken) = Links::scan(["$real/a.md" => [], "$real/sub/b.md" => [], "$real/sub/c.md" => []]);
            check($out === ["$real/a.md" => ["$real/sub/b.md", "$real/sub/c.md"], "$real/sub/b.md" => ["$real/a.md"]], 'out: ' . json_encode($out));
            check($in === ["$real/sub/b.md" => ["$real/a.md"], "$real/sub/c.md" => ["$real/a.md"], "$real/a.md" => ["$real/sub/b.md"]], 'in: ' . json_encode($in));
            check($broken === ["$real/a.md" => ['gone.md']], 'broken: ' . json_encode($broken));
        } finally {
            testRmTree($tmp);
        }
    },
];
