<?php
/**
 * PathName: the last part of a path, byte for byte, in place of basename(). Before PHP 8, basename() follows the
 * locale and in the C locale drops leading multibyte characters ("ä€ö.pdf" became ".pdf" on PHP 7.3); PHP 8 made it
 * locale-independent, which is what this does on every version. Same rules otherwise: trailing slashes are ignored,
 * "/" and "" give "", and $suffix is cut only when the name ends with it and is longer than it.
 */
class PathName
{
    public static function base($path, $suffix = '')
    {
        $name = substr((string) strrchr('/' . rtrim((string) $path, '/'), '/'), 1);
        $n = strlen((string) $suffix);
        return $n > 0 && $n < strlen($name) && substr($name, -$n) === (string) $suffix ? substr($name, 0, -$n) : $name;
    }
}
