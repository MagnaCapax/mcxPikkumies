<?php
/**
 * /ticket's outside work (ADR-0089): posts one draft's request to Pulsed Media's ticket endpoint with the Pulsed Media AI
 * key (Provider::pulsedMediaKey: only ~/.virsilipasKey, only a vsk- key) as the Bearer token, over HTTPS, and turns the
 * answer into a ticket number or a plain refusal. The key is never printed. Nothing retries by itself.
 */
class TicketSend
{
    const ENDPOINT = 'https://pulsedmedia.com/remote/ticket.php';

    /** The Pulsed Media AI key, or a refusal: a ticket is never opened with another provider's key. */
    public static function key(array $config)
    {
        $key = Provider::pulsedMediaKey($config);
        if ($key === '') {
            throw new CliError('a ticket is opened with the Pulsed Media AI key (~/.virsilipasKey), and there is none here',
                'your draft is kept; it is sent only with a Pulsed Media key, never with another provider\'s key');
        }
        return $key;
    }

    /**
     * Posts $body (TicketDraft::body) and returns ['ticket' => number, '' when a 201 carried none]. Any other answer throws
     * with what happened; the hint says whether sending the same draft again can help. A 401 never says whether the key
     * or the email failed: the server answers the same either way. $code receives the HTTP status (0: no answer; -1: not
     * sent at all), which the automatic-ticket caps count (ADR-0100).
     */
    public static function send($body, $key, $endpoint = self::ENDPOINT, &$code = null)
    {
        $code = -1;
        $u = parse_url($endpoint);
        $local = isset($u['host']) && in_array($u['host'], ['127.0.0.1', '::1', '[::1]', 'localhost'], true);
        if (!isset($u['scheme']) || ($u['scheme'] !== 'https' && !($u['scheme'] === 'http' && $local))) {
            throw new CliError('the ticket address is not https, so the key would go out readable', 'run pikkumies update; your draft is kept');
        }
        $r = Http::request('POST', $endpoint, ['body' => $body, 'timeout' => 60, 'max' => 65536,
            'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json']]);
        $code = (int) $r['code'];
        $answer = json_decode($r['body'], true);
        if ($r['code'] === 201) {
            $t = is_array($answer) && isset($answer['ticket']) && is_scalar($answer['ticket']) ? (string) $answer['ticket'] : '';
            return ['ticket' => preg_match('/^[A-Za-z0-9-]{1,32}$/D', $t) ? $t : ''];
        }
        $said = is_array($answer) && isset($answer['error']) && is_string($answer['error']) ? substr(Cli::cleanString($answer['error']), 0, 300) : '';
        $nothing = 'nothing was opened; your draft is kept';
        $again = "$nothing; send the same draft again later with the same send command";
        $unsure = 'your draft is kept; the ticket may or may not have been opened: if the account got a new-ticket email, do not send it again, otherwise send it again later';
        $map = [
            400 => ['Pulsed Media refused the ticket as malformed' . ($said !== '' ? ": $said" : ''), "$nothing, but sending it again will not help: draft it again with that fixed"],
            401 => ['the key or email was not accepted', "$nothing. The answer is the same whatever did not match; check with the user that the email is their Pulsed Media account's (the owner's, or a user's who may open tickets) and that ~/.virsilipasKey holds their current AI key"],
            413 => ['the ticket is too large for Pulsed Media (HTTP 413)', "$nothing; draft it again with fewer or smaller files"],
            429 => ['too many tickets or attempts just now (HTTP 429)', $again],
            503 => ['the ticket service is unavailable just now (HTTP 503)', $again],
        ];
        if (isset($map[$r['code']])) {
            throw new CliError($map[$r['code']][0], $map[$r['code']][1]);
        }
        if ($r['code'] >= 400 && $r['code'] < 500) {
            throw new CliError("Pulsed Media did not take the request (HTTP {$r['code']})" . ($said !== '' ? ": $said" : ''), "$nothing, but sending it again will not help; tell the user what it answered");
        }
        throw new CliError($r['code'] === 0 ? 'could not reach Pulsed Media (' . Cli::cleanString($r['error']) . ')' : "Pulsed Media answered HTTP {$r['code']}", $unsure);
    }
}
