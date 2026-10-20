<?php
/** Only the resume pointer and restart accounting persist; no command is replayed. */
class EternalRecord
{
    public static $base = null;
    public static function validId($id)
    {
        return preg_match('/^pm[1-9]$/D', (string) $id) === 1;
    }

    public static function validSession($id)
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D', $id) === 1;
    }

    public static function path($id)
    {
        if (!self::validId($id)) {
            throw new InvalidArgumentException('invalid Eternal id');
        }
        return (self::$base !== null ? self::$base : Config::localDir() . '/eternals') . '/' . $id;
    }

    public static function read($id)
    {
        $path = self::path($id);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || array_keys($data) !== ['sessionId', 'fails', 'lastStart']
            || !is_int($data['fails']) || $data['fails'] < 0 || !is_int($data['lastStart']) || $data['lastStart'] < 0) {
            return self::initial(); // reject every foreign field before it can affect a launch
        }
        if (!self::validSession($data['sessionId'])) {
            $data['sessionId'] = '';
        }
        return $data;
    }

    public static function write($id, array $data)
    {
        if (!isset($data['sessionId'], $data['fails'], $data['lastStart'])
            || ($data['sessionId'] !== '' && !self::validSession($data['sessionId']))
            || !is_int($data['fails']) || $data['fails'] < 0 || !is_int($data['lastStart']) || $data['lastStart'] < 0) {
            throw new InvalidArgumentException('invalid Eternal record');
        }
        $path = self::path($id);
        @mkdir(dirname($path), 0700, true);
        $body = json_encode(['sessionId' => $data['sessionId'], 'fails' => $data['fails'], 'lastStart' => $data['lastStart']]) . "\n";
        $tmp = @tempnam(dirname($path), '.record-');
        if ($tmp === false || @file_put_contents($tmp, $body) !== strlen($body) || !@rename($tmp, $path)) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            throw new RuntimeException('could not write Eternal record');
        }
    }

    public static function initial()
    {
        return ['sessionId' => '', 'fails' => 0, 'lastStart' => 0];
    }

    /** null means the store cannot be checked; only a proven absence falls back to fresh. */
    public static function sessionExists($id, $database = null)
    {
        if (!self::validSession($id) || !class_exists('SQLite3')) {
            return null;
        }
        $database = $database !== null ? $database : HermesConfig::home() . '/state.db';
        if (!is_file($database)) {
            return false;
        }
        try {
            $db = new SQLite3($database, SQLITE3_OPEN_READONLY);
            $db->busyTimeout(200);
            $query = $db->prepare('SELECT 1 FROM sessions WHERE id = :id LIMIT 1');
            if (!$query) {
                $db->close();
                return null;
            }
            $query->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $query->execute();
            $exists = $result ? $result->fetchArray(SQLITE3_NUM) !== false : null;
            $db->close();
            return $exists;
        } catch (Throwable $x) {
            return null;
        }
    }
}
