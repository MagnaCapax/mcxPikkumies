<?php
/**
 * MediaDocker: a media server the user runs in their own rootless Docker (PMSS docs/docker-help.md:14-17:
 * DOCKER_HOST=unix:///run/user/<uid>/docker.sock). The socket is the one the *ARR units use (Host::dockerSocket):
 * a unix:// DOCKER_HOST, else that PMSS path, and only when this user owns it, so every container it lists is the
 * user's own; tcp:// and a root or another account's daemon are never used. A container is matched by its image name
 * and the port it publishes for the server's own port, never by a recipe's container name.
 */
class MediaDocker
{
    public $sock;
    private $uid;
    private $timeout;

    public function __construct($uid, $sock = null, $timeout = 30)
    {
        $this->uid = (int) $uid;
        $this->sock = $sock !== null ? $sock : Host::dockerSocket($this->uid); // '': none of the user's own
        $this->timeout = $timeout;
    }

    /** True when the user's own rootless Docker socket is there (a root daemon is never used). */
    public function available()
    {
        return Host::ownsSocket($this->sock, $this->uid);
    }

    /**
     * The one running container whose image matches $imageRe and that publishes $containerPort:
     * ['id', 'name', 'image', 'host_ip', 'host_port'], or null. Two candidates are an error (say which).
     */
    public function find($imageRe, $containerPort)
    {
        if (!$this->available()) {
            return null;
        }
        list($code, $out) = $this->docker(['ps', '--format', '{{.ID}}\t{{.Image}}\t{{.Names}}\t{{.Ports}}']);
        if ($code !== 0) {
            return null;
        }
        $found = [];
        foreach (explode("\n", trim($out)) as $line) {
            $f = explode("\t", $line);
            if (count($f) < 4 || !preg_match($imageRe, $f[1])) {
                continue;
            }
            foreach (array_map('trim', explode(',', $f[3])) as $p) {
                // 127.0.0.1:8096->8096/tcp, 0.0.0.0:32400->32400/tcp, :::32400->32400/tcp, [::]:8097->8096/tcp
                if (preg_match('#^(.*):(\d+)->(\d+)/tcp$#', $p, $m) && (int) $m[3] === (int) $containerPort) {
                    $found[$f[0]] = ['id' => $f[0], 'name' => $f[2], 'image' => $f[1], 'host_ip' => trim($m[1], '[]') ?: '0.0.0.0', 'host_port' => (int) $m[2]];
                    break;
                }
            }
        }
        if (count($found) > 1) {
            throw new CliError('more than one matching container: ' . implode(', ', array_column($found, 'name')), 'say which one with --url http://127.0.0.1:<its port>');
        }
        return $found ? reset($found) : null;
    }

    /** The host directory mounted at $dest in the container ('' when none). */
    public function mountSource($id, $dest)
    {
        list($code, $out) = $this->docker(['inspect', '--format', '{{json .Mounts}}', $id]);
        foreach ($code === 0 ? (array) json_decode(trim($out), true) : [] as $m) {
            if (is_array($m) && isset($m['Destination'], $m['Source']) && rtrim($m['Destination'], '/') === $dest) {
                return (string) $m['Source'];
            }
        }
        return '';
    }

    /** `docker restart <id>` on the user's own daemon. Returns [ok, output]. */
    public function restart($id)
    {
        list($code, $out) = $this->docker(['restart', $id], 120);
        return [$code === 0, trim($out)];
    }

    private function docker(array $args, $timeout = null)
    {
        return MediaExec::run(array_merge(['docker'], $args), ['DOCKER_HOST' => 'unix://' . $this->sock, 'HOME' => (string) getenv('HOME')], $timeout ?: $this->timeout);
    }
}
