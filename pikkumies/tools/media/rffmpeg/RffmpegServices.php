<?php
/**
 * RffmpegServices: this unit's seam onto the shared Services list (pikkumies/lib/Services.php, owner
 * A, docs/adr/0083 -- SPEC.md "Shared contract" section). That file has not landed yet at the time
 * this unit was written, so every call goes through an injectable closure: a bare `require_once` of
 * a file that does not exist would fatal every verb of this tool, not just the ones that need a
 * remote GPU host (plan without --gpu, install, status and a hostless remove must all keep working).
 * Production code lazy-loads the real Services class on first use and throws a CliError naming the
 * gap when it is still missing; tests inject their own closures instead (SPEC.md ownership note for
 * B/E/G/H: "write tests with a fake Services (inject a runner callable)").
 */
class RffmpegServices
{
    private $seams;

    public function __construct(array $seams = [])
    {
        $this->seams = $seams + [
            'get'       => [$this, 'realGet'],
            'run'       => [$this, 'realRun'],
            'sshArgv'   => [$this, 'realSshArgv'],
            'secretDir' => [$this, 'realSecretDir'],
        ];
    }

    public function get($nick)
    {
        return call_user_func($this->seams['get'], $nick);
    }

    /** ['rc', 'stdout', 'stderr']. $remoteArgv here is always one shell command string (the RemoteSetup.php heredoc pattern). */
    public function run($nick, array $remoteArgv, $stdin = null, $timeout = 120)
    {
        return call_user_func($this->seams['run'], $nick, $remoteArgv, $stdin, $timeout);
    }

    public function sshArgv($nick, array $remoteArgv, array $opt = [])
    {
        return call_user_func($this->seams['sshArgv'], $nick, $remoteArgv, $opt);
    }

    public function secretDir($nick)
    {
        return call_user_func($this->seams['secretDir'], $nick);
    }

    /**
     * The GPU service record, refused unless its transport is shell (the forced-command serve
     * whitelist has no ffmpeg verb, SPEC.md "Remote serve") and the caller passed --allow-shell: a
     * second, command-level confirmation on top of the service's own transport field, because a real
     * shell key can run anything on that host, unlike the restricted serve forced command.
     */
    public function shellService($nick, array $opt)
    {
        $svc = $this->get($nick);
        if (!is_array($svc)) {
            throw new CliError("no service named $nick", 'check the nick with the services list (tools/seedbox/services)');
        }
        $transport = isset($svc['transport']) ? $svc['transport'] : '';
        if ($transport !== 'shell') {
            throw new CliError("service $nick has transport '$transport', not 'shell'; the forced-command serve on this service cannot run ffmpeg",
                're-add this service with a shell-capable key (transport shell), then pass --allow-shell here');
        }
        if (empty($opt['allow-shell'])) {
            throw new CliError("service $nick needs a real remote shell to run ffmpeg; nothing was done",
                'confirm it with --allow-shell -- a shell key can run anything on that host, unlike the restricted serve forced command');
        }
        return $svc;
    }

    private function loaded()
    {
        if (class_exists('Services')) {
            return true;
        }
        $f = dirname(__DIR__, 3) . '/lib/Services.php';
        if (is_file($f)) {
            require_once $f;
        }
        return class_exists('Services');
    }

    private function need()
    {
        if (!$this->loaded()) {
            throw new CliError('the Services list (pikkumies/lib/Services.php) is not installed on this Pikkumies yet',
                'update Pikkumies, or ask the operator; rffmpeg needs it to reach a GPU host');
        }
    }

    public function realGet($nick)
    {
        $this->need();
        return Services::get($nick);
    }

    public function realRun($nick, array $remoteArgv, $stdin, $timeout)
    {
        $this->need();
        return Services::run($nick, $remoteArgv, $stdin, $timeout);
    }

    public function realSshArgv($nick, array $remoteArgv, array $opt)
    {
        $this->need();
        return Services::sshArgv($nick, $remoteArgv, $opt);
    }

    public function realSecretDir($nick)
    {
        $this->need();
        return Services::secretDir($nick);
    }
}
