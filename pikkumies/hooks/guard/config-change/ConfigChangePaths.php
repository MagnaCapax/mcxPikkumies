<?php
/** Command words and file paths as the config-change hook reads them (ADR-0059): pure functions, no state. */

/**
 * A file-tool path as written (~/ expanded, slashes folded) and its real path: for a file not there yet, the real path of
 * its nearest existing directory with the rest appended, so a symlinked directory cannot hide a guarded file.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

function configChangePathForms($path)
{
    $home = rtrim((string) getenv('HOME'), '/');
    if (substr($path, 0, 2) === '~/' && $home !== '') {
        $path = $home . substr($path, 1);
    }
    $forms = [preg_replace('#/(\./)*/*#', '/', $path)];
    $rest = '';
    for ($at = $path; $at !== '' && $at !== '.' && $at !== '/'; $at = dirname($at)) {
        if (($real = realpath($at)) !== false) {
            $forms[] = $real . $rest;
            break;
        }
        $rest = '/' . PathName::base($at) . $rest;
    }
    return $forms;
}
/** A plain command's words, or null when it has anything a shell expands or joins (; & | < > $ ` * ? and the like). */
function configChangeWords($command)
{
    $words = [];
    $word = null;
    for ($i = 0, $n = strlen($command); $i < $n; $i++) {
        $c = $command[$i];
        if ($c === ' ' || $c === "\t") {
            if ($word !== null) {
                $words[] = $word;
                $word = null;
            }
        } elseif ($c === "'" || $c === '"') {
            $end = strpos($command, $c, $i + 1);
            $quoted = $end === false ? false : substr($command, $i + 1, $end - $i - 1);
            if ($quoted === false || ($c === '"' && strpbrk($quoted, '$`\\!') !== false)) {
                return null;
            }
            $word .= $quoted;
            $i = $end;
        } elseif (preg_match('#[A-Za-z0-9._,:/@+=%~-]|[\x80-\xff]#', $c) === 1) {
            $word .= $c;
        } else {
            return null;
        }
    }
    if ($word !== null) {
        $words[] = $word;
    }
    return $words;
}

/** The real path a command word names (~/ is the shell's HOME), or false; a relative path depends on a directory the hook cannot see. */
function configChangePath($word)
{
    $home = rtrim((string) getenv('HOME'), '/');
    if (substr($word, 0, 2) === '~/' && $home !== '') {
        $word = $home . substr($word, 1);
    }
    return $word !== '' && $word[0] === '/' ? realpath($word) : false;
}
