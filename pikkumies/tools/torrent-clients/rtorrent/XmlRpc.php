<?php
/**
 * RtorrentXmlRpc: the XML-RPC encoder and decoder for rTorrent (int, i8, boolean, double, string,
 * base64, array, struct). Shapes follow the XML-RPC spec (xmlrpc.com/spec) as rTorrent 0.9.8 reads
 * them (rpc/xmlrpc.cc; base64 accepted at xmlrpc.cc:292) and PMSS's own client
 * (PMSS scripts/lib/rtorrent/scgi/xmlrpc.php:28-120). A <fault> becomes a CliError.
 */
class RtorrentBase64
{
    public $data;

    public function __construct($data)
    {
        $this->data = (string) $data;
    }
}

class RtorrentXmlRpc
{
    public static function request($method, array $params)
    {
        $xml = '';
        foreach ($params as $p) {
            $xml .= '<param>' . self::value($p) . '</param>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>' . self::text($method)
            . '</methodName><params>' . $xml . '</params></methodCall>';
    }

    public static function value($v)
    {
        if ($v instanceof RtorrentBase64) {
            return '<value><base64>' . base64_encode($v->data) . '</base64></value>';
        }
        if (is_bool($v)) {
            return '<value><boolean>' . ($v ? '1' : '0') . '</boolean></value>';
        }
        if (is_int($v)) {
            $type = ($v > 2147483647 || $v < -2147483648) ? 'i8' : 'i4';
            return "<value><$type>$v</$type></value>";
        }
        if (is_float($v)) {
            return '<value><double>' . $v . '</double></value>';
        }
        if (is_array($v)) {
            if ($v && array_keys($v) !== range(0, count($v) - 1)) {
                $m = '';
                foreach ($v as $k => $x) {
                    $m .= '<member><name>' . self::text((string) $k) . '</name>' . self::value($x) . '</member>';
                }
                return '<value><struct>' . $m . '</struct></value>';
            }
            return '<value><array><data>' . implode('', array_map([__CLASS__, 'value'], $v)) . '</data></array></value>';
        }
        return '<value><string>' . self::text((string) $v) . '</string></value>';
    }

    /** XML text; characters XML 1.0 cannot carry are refused, never sent mangled. */
    private static function text($s)
    {
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $s)) {
            throw new CliError('a value contains control characters XML-RPC cannot carry', 'check the argument for stray characters');
        }
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** The first value of a methodResponse; a fault throws CliError naming the method. */
    public static function response($xml, $method)
    {
        if (!preg_match('//u', $xml)) {
            $xml = (string) json_decode(json_encode($xml, JSON_INVALID_UTF8_SUBSTITUTE)); // torrent names are not always UTF-8
        }
        if (stripos($xml, '<!DOCTYPE') !== false || !function_exists('simplexml_load_string')) {
            throw new CliError("rtorrent answered $method with something that is not an XML-RPC reply", 'the PHP SimpleXML extension is needed; check that the socket belongs to rtorrent');
        }
        $doc = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_PARSEHUGE);
        if ($doc === false) {
            throw new CliError("rtorrent answered $method with XML that does not parse", 'try again; if it repeats, restart rtorrent');
        }
        if (isset($doc->fault->value)) {
            $f = self::decode($doc->fault->value);
            $why = is_array($f) && isset($f['faultString']) ? $f['faultString'] : 'unknown fault';
            throw new CliError("rtorrent refused $method: $why", 'check the id with `list`; the method may not exist in this rtorrent version');
        }
        if (!isset($doc->params->param->value)) {
            throw new CliError("rtorrent answered $method without a value", 'try again; if it repeats, restart rtorrent');
        }
        return self::decode($doc->params->param->value);
    }

    public static function decode(SimpleXMLElement $node)
    {
        $children = $node->children();
        if (count($children) === 0) {
            return (string) $node; // a value with no type is a string (spec)
        }
        $c = $children[0];
        switch ($c->getName()) {
            case 'i4':
            case 'i8':
            case 'int':
                return (int) (string) $c;
            case 'boolean':
                return trim((string) $c) === '1';
            case 'double':
                return (float) (string) $c;
            case 'base64':
                return (string) base64_decode((string) $c);
            case 'nil':
                return null;
            case 'array':
                $out = [];
                foreach ($c->data->value as $v) {
                    $out[] = self::decode($v);
                }
                return $out;
            case 'struct':
                $out = [];
                foreach ($c->member as $m) {
                    $out[(string) $m->name] = self::decode($m->value);
                }
                return $out;
        }
        return (string) $c; // string, dateTime.iso8601
    }
}
