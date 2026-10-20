<?php
/**
 * TransmissionSecret: the RPC credential, typed once by the user at a terminal (ADR-0019), for the
 * case where `rpc-authentication-required` is true (PMSS's own managed socket always has it off; this
 * is for a self-built daemon or another provider). The username comes from settings.json's
 * `rpc-username` (not secret); only the secret value is kept, in `local/secrets/transmission` (dir
 * 0700, file 0600, lib/SecretFile.php, which also refuses a file another account owns), mirroring
 * QbitSecret.
 */
class TransmissionSecret
{
    private $file;
    private $uid;

    /** $uid: the owner the file must have (a test seam; this account's uid by default). */
    public function __construct($dir, $uid = null)
    {
        $this->file = rtrim($dir, '/') . '/transmission';
        $this->uid = $uid;
    }

    /** ['secret' => string, 'rejected' => bool], or null when nothing has been saved yet. */
    public function load()
    {
        $raw = SecretFile::read($this->file, 'the user runs `php ' . self::script() . ' login` in a terminal', $this->uid);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || !isset($d['secret']) || !is_string($d['secret']) || $d['secret'] === '') {
            return null;
        }
        return ['secret' => $d['secret'], 'rejected' => !empty($d['rejected'])];
    }

    public function save($secret, $rejected = false)
    {
        SecretFile::write($this->file, ['secret' => $secret, 'rejected' => (bool) $rejected], 'Transmission RPC credential');
    }

    /** Never try this credential again until the user runs `login`. */
    public function reject()
    {
        $s = $this->load();
        if ($s !== null) {
            $this->save($s['secret'], true);
        }
    }

    /**
     * The usable credential, or a CliError that tells the user what to run -- NEVER a suggestion to turn
     * authentication off: that would let any co-tenant on a shared TCP endpoint drive the daemon (and
     * this RPC can run scripts) as this account.
     */
    public function usable()
    {
        $s = $this->load();
        if ($s === null) {
            throw new CliError('Transmission requires RPC authentication and nothing has been stored for this tool yet',
                'the user runs `php ' . self::script() . ' login` in a terminal, or this account switches to the PMSS-managed unix socket, which has authentication off by design');
        }
        if ($s['rejected']) {
            throw new CliError('Transmission rejected the stored RPC credential; not trying again',
                'the user runs `php ' . self::script() . ' login` in a terminal with the current rpc-password setting');
        }
        return $s['secret'];
    }

    /** Reads a credential from the terminal without echo; refuses when stdin is not a terminal. */
    public static function prompt($label)
    {
        return SecretFile::prompt($label, 'login reads the credential from a terminal, and stdin is not one',
            'the user runs `php ' . self::script() . ' login` in their own terminal (never type it on the command line or in chat)');
    }

    private static function script()
    {
        return dirname(__FILE__) . '/transmission.php';
    }
}
