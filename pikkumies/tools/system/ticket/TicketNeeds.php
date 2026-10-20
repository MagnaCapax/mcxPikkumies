<?php
/**
 * TicketNeeds (ADR-0100, on /ticket ADR-0089): why a ticket needs Väinämöinen, Pulsed Media's own support agent. Each roadblock kind in the
 * diagnosis maps to one fixed, public-safe line about what Väinämöinen can do that Pikkumies, running as this account,
 * cannot. The lines say what, never how: no internal tool, host or path, and no promise of a time.
 */
class TicketNeeds
{
    /** kind => what Väinämöinen has that this account does not. The ask-vainamoinen-for-help SOP carries the same list. */
    const CAPABILITY = [
        'root' => 'root on the server itself, not just this one account',
        'console' => 'the server\'s management console, which reaches it even when the operating system will not boot, and can power-cycle it',
        'fleet' => 'a view of the other servers and of past incidents across all customers, not only this box',
        'billing' => 'changes to the account and billing record itself, made with safety checks',
        'platform' => 'updates of the seedbox platform itself and moves of a whole account between servers',
        'code' => 'fixes in Pulsed Media\'s own product code, not just in this account',
        'memory' => 'Pulsed Media\'s accumulated experience of every past ticket and incident',
    ];

    /** The kinds, for an error message. */
    public static function kinds()
    {
        return implode(', ', array_keys(self::CAPABILITY));
    }

    /**
     * The roadblock bullets of the diagnosis ("- kind: why"), as [[kind, why], ...]. A bullet whose kind is not known
     * is refused with the list, so a ticket never claims a capability that is not on it.
     */
    public static function parse($section)
    {
        $out = [];
        foreach (preg_split('/\R/', (string) $section) as $line) {
            if (!preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
                continue;
            }
            if (!preg_match('/^([a-z]+)\s*:\s*(\S.*)$/', trim($m[1]), $b) || !isset(self::CAPABILITY[$b[1]])) {
                throw new CliError('a roadblock bullet needs a known kind first: "- <kind>: <why you could not do it>" (' . trim($m[1]) . ')',
                    'kinds: ' . self::kinds() . '; put anything else under Tried');
            }
            $out[] = [$b[1], trim($b[2])];
        }
        return $out;
    }

    /** "Needs: <capability> - because <roadblock>", one line per roadblock. */
    public static function lines(array $roadblocks)
    {
        $out = [];
        foreach ($roadblocks as $r) {
            $out[] = 'Needs: ' . self::CAPABILITY[$r[0]] . ' - because ' . $r[1];
        }
        return $out;
    }
}
