<?php
/**
 * MediaEndStream: `end-stream` for every media unit (README.md of this category): each id gets its own result, a
 * failed stop does not hide the streams already ended, and the call ends ok:false when any stop was not sent.
 */
class MediaEndStream
{
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

    /** The records when every stop was sent; else the call ends ok:false with each id's result. */
    public static function finish(array $done)
    {
        $bad = array_filter($done, function ($r) {
            return $r['result'] !== 'stop sent';
        });
        if (!$bad) {
            return $done;
        }
        $each = array_map(function ($r) {
            $ended = 'ended' . ($r['verified'] ? '' : ' (still listed)');
            return "{$r['id']}: " . ($r['result'] === 'stop sent' ? $ended : $r['result']);
        }, $done);
        throw new CliError('not every stream ended: ' . implode(' | ', $each),
            '`sessions` shows what still plays; tell the user exactly this');
    }
}
