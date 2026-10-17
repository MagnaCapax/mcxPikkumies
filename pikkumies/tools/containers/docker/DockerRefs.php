<?php
/**
 * DockerRefs: container references resolved in PHP before any change (ADR-0034). A reference is an exact
 * container name or a unique prefix of at least 4 hex digits of the full ID; every one is resolved against the live
 * list first, and one unknown, ambiguous or malformed reference fails the whole call, so nothing changes. Docker is
 * then always given the full 64-hex ID, never the user's text. Names and images are checked against a grammar, and
 * an image never starts with "-" (it would be read as an option). readBack() is the one state read every changing
 * verb reports.
 */
class DockerRefs
{
    const NAME_RE = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}\\z/';
    const IMAGE_RE = '#^[a-zA-Z0-9][a-zA-Z0-9._/:@+-]{0,254}\\z#';
    const ID_RE = '/^[0-9a-f]{64}$/';
    const PREFIX_MIN = 4;

    public static function validName($name)
    {
        return is_string($name) && preg_match(self::NAME_RE, $name) === 1;
    }

    public static function validImage($image)
    {
        return is_string($image) && preg_match(self::IMAGE_RE, $image) === 1;
    }

    /**
     * Full IDs for $refs, in order, from $containers (records with `id`, the full 64-hex ID, and `name`, without the
     * leading "/"). $changing: the error starts "nothing changed:" instead of "not found:". A reference that is
     * both a name and an ID prefix resolves to the name (docker does the same). Duplicates collapse to one.
     */
    public static function resolve(array $refs, array $containers, $changing = false)
    {
        $lead = $changing ? 'nothing changed: ' : 'not found: ';
        if (!$refs) {
            throw new CliError(rtrim($lead, ': ') . ': name at least one container', 'container names and IDs come from `list`');
        }
        $out = [];
        foreach ($refs as $ref) {
            $ref = (string) $ref;
            if (!self::validName($ref)) {
                throw new CliError($lead . "'" . substr($ref, 0, 80) . "' is not a container name or ID", 'names use letters, digits, _ . - and start with a letter or digit; copy one from `list`');
            }
            $hit = null;
            foreach ($containers as $c) {
                if (isset($c['name']) && ltrim((string) $c['name'], '/') === $ref) {
                    $hit = (string) $c['id'];
                    break;
                }
            }
            if ($hit === null && strlen($ref) >= self::PREFIX_MIN && ctype_xdigit($ref)) {
                $ids = [];
                foreach ($containers as $c) {
                    if (isset($c['id']) && strncmp((string) $c['id'], strtolower($ref), strlen($ref)) === 0) {
                        $ids[] = (string) $c['id'];
                    }
                }
                if (count($ids) > 1) {
                    throw new CliError($lead . "'$ref' matches " . count($ids) . ' containers', 'give more of the ID, or the name');
                }
                $hit = $ids ? $ids[0] : null;
            }
            if ($hit === null || !preg_match(self::ID_RE, $hit)) {
                throw new CliError($lead . "no container '$ref'", 'names and IDs come from `list --all`; a prefix needs at least ' . self::PREFIX_MIN . ' hex digits');
            }
            $out[$hit] = $hit;
        }
        return array_values($out);
    }

    /**
     * The state a changing verb reports, from one `inspect --format '{{json .}}'` object: id, name, state, running,
     * exit_code, started_at, finished_at, restart_count, error_text, oom. oom is "unknown" unless the caller says
     * per-container limits are enforced here (on cgroup v1 or a cgroupfs rootless daemon docker cannot tell).
     */
    public static function readBack(array $inspect, $limitsEnforced = false)
    {
        $s = isset($inspect['State']) && is_array($inspect['State']) ? $inspect['State'] : [];
        $get = function ($k) use ($s) {
            return array_key_exists($k, $s) ? $s[$k] : null;
        };
        return [
            'id' => isset($inspect['Id']) ? (string) $inspect['Id'] : null,
            'name' => isset($inspect['Name']) ? ltrim((string) $inspect['Name'], '/') : null,
            'state' => $get('Status'),
            'running' => $get('Running'),
            'exit_code' => $get('ExitCode'),
            'started_at' => self::time($get('StartedAt')),
            'finished_at' => self::time($get('FinishedAt')),
            'restart_count' => isset($inspect['RestartCount']) ? (int) $inspect['RestartCount'] : null,
            'error_text' => $get('Error') === '' ? null : $get('Error'),
            'oom' => $limitsEnforced && is_bool($get('OOMKilled')) ? $get('OOMKilled') : 'unknown',
        ];
    }

    /** Docker's zero time (0001-01-01...) means never: null. */
    private static function time($t)
    {
        return !is_string($t) || $t === '' || strncmp($t, '0001-01-01', 10) === 0 ? null : $t;
    }
}
