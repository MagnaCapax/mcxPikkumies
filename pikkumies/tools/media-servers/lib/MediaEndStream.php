<?php
/**
 * MediaEndStream: `end-stream` for every media unit (README.md of this category): each id gets its own result, a
 * failed stop does not hide the streams already ended, and the call ends ok:false when any stop was not sent or a
 * session still plays after it (the same rule as the other changing verbs: a change the read-back does not show is
 * not reported as done).
 */
class MediaEndStream
{
    /** Read-backs of the playing list: up to READS, READ_GAP_US apart, while a sent stop has not taken effect yet. */
    const READS = 5;
    const READ_GAP_US = 400000;

    /** One record per session: $stop($session) sends its stop; after a refused credential nothing more is sent. */
    public static function each(array $sessions, callable $stop)
    {
        $out = [];
        $refused = false;
        foreach ($sessions as $s) {
            $r = ['id' => $s['id'], 'user' => $s['user'], 'title' => $s['title'], 'result' => 'not sent: the credential was refused'];
            try {
                if (!$refused) {
                    $stop($s);
                    $r['result'] = 'stop sent';
                }
            } catch (MediaAuthRejected $e) {
                $refused = true;
                $r['result'] = 'failed: ' . $e->getMessage();
            } catch (CliError $e) {
                $r['result'] = 'failed: ' . $e->getMessage();
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * `verified` on each record: its session is gone from $playing() (the live `sessions` list, which holds only
     * sessions with something playing). A client takes a moment to obey a stop, so the list is read again a few times
     * while a sent stop still shows.
     */
    public static function readBack(array $done, callable $playing)
    {
        for ($i = 1; ; $i++) {
            $left = array_column($playing(), 'id');
            $waiting = false;
            foreach ($done as $k => $r) {
                $done[$k]['verified'] = !in_array($r['id'], $left, true);
                $waiting = $waiting || ($r['result'] === 'stop sent' && !$done[$k]['verified']);
            }
            if (!$waiting || $i >= self::READS) {
                return $done;
            }
            usleep(self::READ_GAP_US);
        }
    }

    /** The records when every stream ended (stop sent and gone from the list); else the call ends ok:false with each id's result. */
    public static function finish(array $done)
    {
        $bad = array_filter($done, function ($r) {
            return $r['result'] !== 'stop sent' || empty($r['verified']);
        });
        if (!$bad) {
            return $done;
        }
        $each = array_map(function ($r) {
            $sent = empty($r['verified']) ? 'stop sent, but it still plays' : 'ended';
            return "{$r['id']}: " . ($r['result'] === 'stop sent' ? $sent : $r['result']);
        }, $done);
        throw new CliError('not every stream ended: ' . implode(' | ', $each),
            '`sessions` shows what still plays (a client may ignore the stop command); tell the user exactly this');
    }
}
