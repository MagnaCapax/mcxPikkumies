<?php
/**
 * ArrErrorText: the app's own message from an error body: ErrorModel {message} or a list of {errorMessage}
 * (RadarrErrorPipeline.cs:33-52), and urls() for every text the app writes (errors, health, command and
 * indexer test messages). Moved out of ArrRecords to keep each file small.
 */
class ArrErrorText
{
    /** At most Cli::TEXT_MAX bytes; a body that is not JSON gives its text, cut to the same. */
    public static function from($body)
    {
        $d = json_decode((string) $body, true);
        $parts = [];
        if (is_array($d) && isset($d['message']) && is_string($d['message'])) {
            $parts[] = $d['message'];
        } elseif (is_array($d)) {
            foreach ($d as $e) {
                if (is_array($e) && isset($e['errorMessage'])) {
                    $parts[] = (isset($e['propertyName']) ? $e['propertyName'] . ': ' : '') . $e['errorMessage'];
                }
            }
        } else {
            $parts[] = substr(trim(strip_tags((string) $body)), 0, Cli::TEXT_MAX);
        }
        return self::urls(substr(implode('; ', array_filter($parts, 'strlen')), 0, Cli::TEXT_MAX));
    }

    /**
     * Every http(s) URL in app text keeps scheme://host/path; its query and fragment become `?…`. Prowlarr puts
     * the request URL in an indexer test failure (HttpIndexerBase.cs:815-818, HttpException.cs:18), and a tracker
     * key can sit in any query parameter. Cli::cleanString later removes the named key parameters, user:password@
     * and long path tokens; this removes the rest of the query, which only the app's own texts need.
     */
    public static function urls($s)
    {
        return (string) preg_replace('#(\bhttps?://[^\s?\#"\'<>]*)[?\#][^\s"\'<>]*#i', '$1?…', (string) $s);
    }
}
