<?php
/**
 * code-review: what a review of a change needs to start from (ADR-0042 tool unit). One read-only verb, `context`:
 * the project root, the project's own rules files (AGENTS.md and the like, CONTRIBUTING, docs/adr, development/),
 * whether the review falls back to the engineering principles shipped with Pikkumies because the project has none,
 * and the changed files with their added and removed lines and current length. The review itself is the four passes
 * in skill.md; this script only finds the material, deterministically, and never writes anything.
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";
require_once "$lib/Update.php";
require_once __DIR__ . '/CodeReviewGit.php';

// Shipped with Pikkumies: the install is a full clone, and development/ sits next to pikkumies/ (ADR-0001).
$codeReviewPrinciples = dirname(__DIR__, 4) . '/development/engineering-principles.md';

$usage = <<<'U'
usage: php code-review.php context [--base REF] [--path DIR] [--human]
  context  the project root, its own rules files and ADRs, whether the bundled engineering principles are the
           fallback, and the changed files (added and removed lines, current length, over 150 lines).
           Default change: uncommitted changes and untracked files, or the last commit when the tree is clean;
           --base REF compares REF to the working tree. --path: a directory inside the project (default: here).
U;

/** Sorted names of the .md files in $dir (scandir, not glob: a project path may hold [ or *). */
function codeReviewMarkdown($dir)
{
    $names = is_dir($dir) ? array_values(array_filter((array) @scandir($dir), function ($n) {
        return substr($n, -3) === '.md';
    })) : [];
    sort($names);
    return $names;
}

/**
 * The project's own rules files as records, relative to the root: every root Markdown file named in capitals
 * (AGENTS.md, CONTRIBUTING.md, SECURITY.md, a coding agent's own instruction file...) except the ones that hold no
 * rules, then .github/CONTRIBUTING.md and development/*.md. A pattern, not a list of agent products' file names.
 */
function codeReviewRules($root)
{
    $records = [];
    $files = [];
    foreach (codeReviewMarkdown($root) as $name) {
        if (preg_match('/^[A-Z][A-Z0-9_-]*\.md$/', $name) && !in_array($name, ['README.md', 'CHANGELOG.md', 'LICENSE.md', 'NOTICE.md', 'HISTORY.md'], true)) {
            $files[] = $name;
        }
    }
    sort($files);
    $files[] = '.github/CONTRIBUTING.md';
    foreach (codeReviewMarkdown("$root/development") as $name) {
        $files[] = "development/$name";
    }
    foreach ($files as $f) {
        if (is_file("$root/$f")) {
            $records[] = ['rules_file' => $f, 'lines' => CodeReviewGit::lineCount("$root/$f")];
        }
    }
    $adrs = codeReviewMarkdown("$root/docs/adr");
    if ($adrs) {
        rsort($adrs); // ADR files are numbered: the highest numbers are the newest decisions
        $records[] = ['adr_dir' => 'docs/adr', 'count' => count($adrs), 'newest' => array_slice($adrs, 0, 5)];
    }
    return $records;
}

Cli::run($argv, [
    'context' => function ($args, $opt) use ($codeReviewPrinciples) {
        foreach (['base', 'path'] as $k) {
            if (isset($opt[$k]) && !is_string($opt[$k])) {
                throw new CliError("--$k needs a value", $k === 'base' ? 'for example --base main' : 'for example --path ~/src/project');
            }
        }
        $root = CodeReviewGit::root(isset($opt['path']) ? $opt['path'] : getcwd());
        $rules = codeReviewRules($root);
        $changes = CodeReviewGit::changes($root, isset($opt['base']) ? $opt['base'] : null);
        $records = [[
            'root' => $root,
            'compared' => $changes['compared'],
            'fallback' => !$rules, // no rules of its own: review against the bundled principles, and say so
            'principles' => $codeReviewPrinciples, // the bundled fallback; used only when fallback is true
            'principles_found' => is_file($codeReviewPrinciples),
        ]];
        $records = array_merge($records, $rules);
        $over = 0;
        foreach ($changes['files'] as $path => $counts) {
            $lines = CodeReviewGit::lineCount("$root/$path");
            $isOver = $lines !== null && $lines > CodeReviewGit::LINES_TARGET;
            $over += $isOver ? 1 : 0;
            $records[] = ['file' => $path, 'lines_added' => $counts[0], 'lines_removed' => $counts[1], 'lines' => $lines, 'over_150' => $isOver];
        }
        return ['records' => $records, 'status' => ['changed_files' => count($changes['files']), 'files_over_150' => $over]];
    },
], $usage, [], ['base', 'path']);
