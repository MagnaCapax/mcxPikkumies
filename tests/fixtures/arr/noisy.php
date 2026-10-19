<?php
// A program that fills stderr past a pipe buffer (64 KiB) before it writes stdout, for tests/ArrShellTest.php; it echoes
// what it got on stdin, so the test sees stdin arrive too.
fwrite(STDERR, str_repeat('e', 200000));
echo 'out:' . trim((string) fgets(STDIN));
fwrite(STDERR, 'tail');
