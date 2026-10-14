<?php
/*
 * A fake `docker` CLI for the media tests (harness.php writes it to bin/docker with a shebang). It reads
 * docker.json next to the socket DOCKER_HOST names and records each call there. Output shapes follow a
 * real docker 26.1.5 on 2026-10-14: `ps --format '{{.ID}}\t{{.Image}}\t{{.Names}}\t{{.Ports}}'` gives
 * tab-separated fields with ports like "1900/udp, 127.0.0.1:18097->8096/tcp"; `inspect --format
 * '{{json .Mounts}}'` gives [{"Type","Source","Destination",...}].
 */
$sock = preg_replace('#^unix://#', '', (string) getenv('DOCKER_HOST'));
$file = dirname($sock) . '/docker.json';
$s = json_decode((string) @file_get_contents($file), true);
if (!is_array($s) || !file_exists($sock)) {
    fwrite(STDERR, "Cannot connect to the Docker daemon at unix://$sock\n");
    exit(1);
}
$args = array_slice($argv, 1);
$s['calls'][] = $args;
file_put_contents($file, json_encode($s));
$cmd = isset($args[0]) ? $args[0] : '';
if ($cmd === 'ps') {
    foreach ($s['containers'] as $c) {
        echo $c['id'], "\t", $c['image'], "\t", $c['name'], "\t", $c['ports'], "\n";
    }
    exit(0);
}
if ($cmd === 'inspect') {
    foreach ($s['containers'] as $c) {
        if ($c['id'] === end($args)) {
            echo json_encode(isset($c['mounts']) ? $c['mounts'] : []), "\n";
            exit(0);
        }
    }
    fwrite(STDERR, "Error: No such object\n");
    exit(1);
}
if ($cmd === 'restart') {
    echo end($args), "\n";
    exit(0);
}
fwrite(STDERR, "fake docker: unsupported command $cmd\n");
exit(1);
