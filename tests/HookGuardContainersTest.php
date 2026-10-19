<?php
/**
 * The delete guard on container commands (ADR-0020 amendment of 2026-10-17, ADR-0034): Docker-compatible
 * deletes, exposure and daemon stops ask the user with a message that names the kind; registry passwords
 * are blocked; everyday reads, `run --rm`, loopback publishes and the unit's previews stay silent.
 * A hand-written table: each row is [command, expected action, expected kind].
 */
if (!function_exists('guardKindAnswer')) {
    /** The guard's decoded answer for a terminal command (or execute_code code), or null for no answer. */
    function guardKindAnswer($cmd, $tool = 'terminal')
    {
        $field = $tool === 'terminal' ? 'command' : 'code';
        list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php', [],
            json_encode(['tool_name' => $tool, 'tool_input' => [$field => $cmd]]));
        check($code === 0, "guard exited $code for $cmd");
        $d = json_decode($out, true);
        check(is_array($d) || trim($out) === '', "unreadable answer for $cmd: $out");
        return is_array($d) ? $d : null;
    }

    /**
     * Check rows of [command, action, kind]: action none|approve|block; an approve kind names the end of the
     * question (delete, exposure, stop, load) and its rule_key prefix; a block kind is a text in the message.
     */
    function guardKindTable(array $rows, $tool = 'terminal')
    {
        $ends = ['delete' => 'which cannot be undone', 'exposure' => 'reachable beyond this account',
            'stop' => 'stops every container this account runs', 'load' => "your share now, not the server's ceiling"];
        foreach ($rows as $row) {
            list($cmd, $action, $kind) = array_pad($row, 3, '');
            $d = guardKindAnswer($cmd, $tool);
            $got = $d === null ? 'none' : $d['action'];
            check($got === $action, "$cmd: expected $action, got $got" . ($d ? ' (' . $d['message'] . ')' : ''));
            if ($action === 'approve') {
                $id = substr(sha1($cmd), 0, 16);
                $prefix = $kind === 'load' ? 'pikkumies-load:' : 'pikkumies-delete:';
                check(strpos($d['message'], $ends[$kind]) !== false, "$cmd: not asked as $kind: " . $d['message']);
                check($d['rule_key'] === $prefix . $id && strpos($d['message'], "[$id]: $cmd") !== false, "$cmd: rule_key or command missing: " . json_encode($d));
            } elseif ($action === 'block' && $kind !== '') {
                check(strpos($d['message'], $kind) !== false, "$cmd: wrong block reason: " . $d['message']);
            }
        }
    }
}

return [
    'containers guard: image, container, volume and network deletes ask as deletes, also behind global options and sudo' => function () {
        guardKindTable([
            ['docker rmi nginx:alpine', 'approve', 'delete'],
            ['podman rmi 3f2a', 'approve', 'delete'],
            ['nerdctl rmi web:old', 'approve', 'delete'],
            ['docker --context default rmi nginx', 'approve', 'delete'],
            ['sudo docker image prune -a', 'approve', 'delete'],
            ['docker container prune -f', 'approve', 'delete'],
            ['docker volume prune', 'approve', 'delete'],
            ['docker network prune', 'approve', 'delete'],
            ['docker system prune -af --volumes', 'approve', 'delete'],
            ['docker builder prune', 'approve', 'delete'],
            ['podman system reset', 'approve', 'delete'],
            ['docker container rm web-1', 'approve', 'delete'],
            ['docker image remove nginx:alpine', 'approve', 'delete'],
            ['docker volume rm jellyfin-config', 'approve', 'delete'],
            ['docker network rm media', 'approve', 'delete'],
            ['docker rm web-1', 'approve', 'delete'],
        ]);
    },
    'containers guard: compose down asks only with -v, --volumes or --rmi; engine API deletes over the socket ask' => function () {
        guardKindTable([
            ['docker compose down -v', 'approve', 'delete'],
            ['docker compose -f ~/docker/media/compose.yaml down --rmi all', 'approve', 'delete'],
            ['docker-compose down --volumes', 'approve', 'delete'],
            ['docker compose down', 'none'],
            ['docker compose -p media up -d', 'none'],
            ['curl --unix-socket /run/user/1000/docker.sock -X DELETE http://localhost/containers/web-1', 'approve', 'delete'],
            ['curl --unix-socket /run/user/1000/docker.sock -X POST http://localhost/images/prune', 'approve', 'delete'],
            ['curl --unix-socket /run/user/1000/docker.sock http://localhost/containers/json', 'none'],
        ]);
    },
    'containers guard: a publish without a loopback address asks as exposure; 127.0.0.1: and [::1]: stay silent' => function () {
        guardKindTable([
            ['docker run -d -p 8080:80 nginx:alpine', 'approve', 'exposure'],
            ['docker run -d -p8080:80 nginx:alpine', 'approve', 'exposure'],
            ['docker run -d --publish=8080:80 nginx:alpine', 'approve', 'exposure'],
            ['docker run -d -p 0.0.0.0:8080:80 nginx:alpine', 'approve', 'exposure'],
            ['docker run -d -p 80 nginx:alpine', 'approve', 'exposure'],
            ['docker run -d -p 127.0.0.1:8081:81 -p 8080:80 nginx:alpine', 'approve', 'exposure'],
            ['podman create -p 9000:9000 minio', 'approve', 'exposure'],
            ['docker run -d -P nginx:alpine', 'approve', 'exposure'],
            ['docker run -d --publish-all nginx:alpine', 'approve', 'exposure'],
            ['docker run -d -p 127.0.0.1:8080:80 nginx:alpine', 'none'],
            ['docker run -d -p "127.0.0.1:8080:80" nginx:alpine', 'none'],
            ['docker run -d -p [::1]:8080:80 nginx:alpine', 'none'],
        ]);
    },
    'containers guard: host network, privileges, devices and sensitive mounts ask as exposure; ordinary mounts do not' => function () {
        guardKindTable([
            ['docker run --network host nginx', 'approve', 'exposure'],
            ['docker run --net=host nginx', 'approve', 'exposure'],
            ['docker run --privileged alpine', 'approve', 'exposure'],
            ['docker run --cap-add NET_ADMIN alpine', 'approve', 'exposure'],
            ['docker run --device /dev/fuse alpine', 'approve', 'exposure'],
            ['docker run -v /run/user/1000/docker.sock:/var/run/docker.sock alpine', 'approve', 'exposure'],
            ['docker run -v ~/.ssh:/root/.ssh:ro alpine', 'approve', 'exposure'],
            ['docker run -v ~/pikkumies/local:/x alpine', 'approve', 'exposure'],
            ['docker run -v ~:/data alpine', 'approve', 'exposure'],
            ['docker run -v "$HOME":/data alpine', 'approve', 'exposure'],
            ['docker run -v /home/me/:/data alpine', 'approve', 'exposure'],
            ['docker run --mount type=bind,source=/home/me,target=/h alpine', 'approve', 'exposure'],
            ['docker run -v ~/docker/web:/usr/share/nginx/html:ro nginx', 'none'],
            ['docker run -v /home/me/docker/db:/var/lib/mysql mariadb:11', 'none'],
            ['docker run --volumes-from web alpine', 'none'],
        ]);
    },
    'containers guard: the unit asks on --public and --accept-shared-exposure, not on a lone launch-plan or a preview' => function () {
        $d = 'php ~/pikkumies/tools/containers/docker/docker.php';
        guardKindTable([
            ["$d launch --image nginx:alpine --name web --count 3 --port 80 --accept-shared-exposure", 'approve', 'exposure'],
            ["$d launch --image nginx:alpine --name web --port 80 --public", 'approve', 'exposure'],
            ["$d launch-plan --image nginx:alpine --name web --port 80 --public", 'none'],
            ["$d launch-plan --port 80; $d launch --port 80 --public", 'approve', 'exposure'],
            ["$d images --prune-plan", 'none'],
            ["$d prune --expect 4", 'approve', 'delete'],
            ["$d remove web-4 web-5 --expect 2", 'approve', 'delete'],
        ]);
    },
    'containers guard: killing or restarting the daemon asks as stopping every container; starting it does not' => function () {
        guardKindTable([
            ['pkill -x -u 1000 rootlesskit', 'approve', 'stop'],
            ['killall dockerd', 'approve', 'stop'],
            ['kill $(pgrep -u 1000 containerd)', 'approve', 'stop'],
            ['systemctl --user restart docker.service', 'approve', 'stop'],
            ['systemctl --user start docker.service', 'none'],
            ['systemctl --user status docker.service', 'none'],
        ]);
    },
    'containers guard: registry passwords are blocked: config.json reads and a login password on the command line' => function () {
        guardKindTable([
            ['cat ~/.docker/config.json', 'block', 'registry passwords'],
            ['cd ~/.docker && cat config.json', 'block', 'registry passwords'],
            ['docker login -u me -p hunter2 registry.example', 'block', 'registry password on the command line'],
            ['podman login --password=hunter2 quay.example', 'block', 'registry password on the command line'],
            ['docker login --password-stdin -u me registry.example', 'none'],
            ['docker login registry.example', 'none'],
        ]);
    },
    'containers guard: everyday reads, run --rm and names containing rm stay silent' => function () {
        guardKindTable([
            ['docker ps -a', 'none'],
            ['docker images', 'none'],
            ['docker system df', 'none'],
            ['docker run --rm alpine echo hi', 'none'],
            ['docker run --name farm-rm alpine true', 'none'],
            ['docker logs rmtest --tail 50', 'none'],
            ['docker stop web-1', 'none'],
        ]);
    },
    'containers guard: execute_code list forms are read like the shell form' => function () {
        guardKindTable([
            ['subprocess.run(["docker", "rmi", "nginx"])', 'approve', 'delete'],
            ['subprocess.run(["docker", "run", "-d", "-p", "8080:80", "nginx"])', 'approve', 'exposure'],
            ['subprocess.run(["docker", "run", "-d", "-p", "127.0.0.1:8080:80", "nginx"])', 'none'],
        ], 'execute_code');
    },
];
