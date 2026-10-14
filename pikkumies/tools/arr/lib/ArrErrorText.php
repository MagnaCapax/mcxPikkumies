<?php
/**
 * ArrErrorText: the app's own message from an error body: ErrorModel {message} or a list of {errorMessage}
 * (RadarrErrorPipeline.cs:33-52). Moved out of ArrRecords to keep each file small.
 */
class ArrErrorText
{
    /** At most 600 characters; a body that is not JSON gives its first 300 characters of text. */
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
            $parts[] = substr(trim(strip_tags((string) $body)), 0, 300);
        }
        return substr(implode('; ', array_filter($parts, 'strlen')), 0, 600);
    }
}
