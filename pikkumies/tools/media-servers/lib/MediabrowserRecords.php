<?php
/**
 * MediabrowserRecords: Jellyfin and Emby objects as the category's records (README.md of this
 * category). Field names follow the Jellyfin 12.2.0 schemas SessionInfoDto, TranscodingInfo,
 * PlayerStateInfo, VirtualFolderInfo and BaseItemDto, and the Emby 4.1.1.0 Session.SessionInfo
 * and TranscodingInfo (Emby reports hardware use per decoder and encoder instead of one type).
 */
class MediabrowserRecords
{
    public static function library(array $v)
    {
        $progress = isset($v['RefreshProgress']) && is_numeric($v['RefreshProgress']) ? round($v['RefreshProgress'] / 100, 3) : null;
        $status = isset($v['RefreshStatus']) ? (string) $v['RefreshStatus'] : '';
        return ['id' => (string) (isset($v['ItemId']) ? $v['ItemId'] : ''), 'name' => (string) (isset($v['Name']) ? $v['Name'] : ''),
            'type' => isset($v['CollectionType']) ? (string) $v['CollectionType'] : 'mixed', 'paths' => isset($v['Locations']) ? array_values((array) $v['Locations']) : [],
            'items' => null, 'scanning' => $progress !== null || !in_array($status, ['', 'Idle'], true), 'progress' => $progress];
    }

    /** A session with something playing, or null (idle clients and this tool's own session are not streams). */
    public static function session(array $s)
    {
        if (empty($s['NowPlayingItem']) || !is_array($s['NowPlayingItem'])) {
            return null;
        }
        $item = $s['NowPlayingItem'];
        $play = isset($s['PlayState']) && is_array($s['PlayState']) ? $s['PlayState'] : [];
        $tc = isset($s['TranscodingInfo']) && is_array($s['TranscodingInfo']) ? $s['TranscodingInfo'] : null;
        $method = isset($play['PlayMethod']) ? (string) $play['PlayMethod'] : '';
        $run = isset($item['RunTimeTicks']) ? (float) $item['RunTimeTicks'] : 0;
        $addr = isset($s['RemoteEndPoint']) ? (string) $s['RemoteEndPoint'] : '';
        return [
            'id' => (string) (isset($s['Id']) ? $s['Id'] : ''),
            'user' => (string) (isset($s['UserName']) ? $s['UserName'] : ''),
            'client' => (string) (isset($s['Client']) ? $s['Client'] : ''),
            'device' => (string) (isset($s['DeviceName']) ? $s['DeviceName'] : ''),
            'title' => self::title($item),
            'progress' => $run > 0 && isset($play['PositionTicks']) ? round($play['PositionTicks'] / $run, 3) : null,
            'paused' => !empty($play['IsPaused']),
            'play' => $method === 'Transcode' || ($method === '' && $tc !== null) ? 'transcode' : 'direct',
            'transcode_reasons' => $tc !== null && isset($tc['TranscodeReasons']) ? array_values((array) $tc['TranscodeReasons']) : [],
            'hardware' => self::hardware($tc),
            'bitrate' => $tc !== null && isset($tc['Bitrate']) ? (int) $tc['Bitrate'] : null,
            'remote' => $addr === '' ? null : self::remote($addr),
            'address' => $addr,
        ];
    }

    /** A recently added item; $library: the library's name. */
    public static function item(array $i, $library)
    {
        $when = isset($i['DateCreated']) ? strtotime((string) $i['DateCreated']) : false;
        return ['title' => self::title($i), 'type' => (string) (isset($i['Type']) ? $i['Type'] : ''), 'library' => (string) $library, 'added_at' => $when === false ? null : $when];
    }

    public static function title(array $i)
    {
        $name = (string) (isset($i['Name']) ? $i['Name'] : '');
        return !empty($i['SeriesName']) ? $i['SeriesName'] . ' - ' . $name : $name;
    }

    /** Whether the transcode uses hardware, as the server reports it (null: no transcode, or not said). */
    private static function hardware($tc)
    {
        if ($tc === null) {
            return null;
        }
        if (isset($tc['VideoDecoderIsHardware']) || isset($tc['VideoEncoderIsHardware'])) {
            return !empty($tc['VideoDecoderIsHardware']) || !empty($tc['VideoEncoderIsHardware']); // Emby
        }
        return isset($tc['HardwareAccelerationType']) ? strtolower((string) $tc['HardwareAccelerationType']) !== 'none' : null; // Jellyfin
    }

    /**
     * True when the address the server saw is a public one. Behind a local reverse proxy (PMSS
     * /public-<user>/ paths) the server may only see the proxy's 127.0.0.1, so false is not proof.
     */
    private static function remote($addr)
    {
        $ip = filter_var($addr, FILTER_VALIDATE_IP) ? $addr : preg_replace('/^\[([^\]]+)\](:\d+)?$|^([\d.]+):\d+$/', '$1$3', $addr);
        $ip = preg_replace('/^::ffff:/i', '', (string) $ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
