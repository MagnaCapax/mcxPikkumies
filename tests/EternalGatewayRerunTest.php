<?php
/** Eternal resident reruns Hermes after a gateway that was still starting (ADR-0116); seams only, no gateway or tmux. */
function eternalRerunSeams($state, $held, $booting, $wait, array &$calls)
{
    return [
        'state' => function ($home) use ($state, &$calls) { $calls[] = 'state'; return $state; },
        'held' => function ($lock) use ($held, &$calls) { $calls[] = 'held'; return $held; },
        'booting' => function ($home) use ($booting, &$calls) { $calls[] = 'booting'; return $booting; },
        'wait' => function ($home, $say) use ($wait, &$calls) { $calls[] = 'wait'; return $wait; },
    ];
}

return [
    'eternal gateway rerun: a failed exit while the gateway is starting waits and runs again' => function () {
        $calls = [];
        $say = function ($line) {};
        check(Eternal::rerunAfterGateway(1, 0, 0, '/h', $say, eternalRerunSeams('starting', true, false, 'ready', $calls)) === true, 'starting gateway, ready after wait: rerun');
        check(in_array('wait', $calls, true), 'waited on GatewayWait');
        $calls = [];
        check(Eternal::rerunAfterGateway(1, 0, 0, '/h', $say, eternalRerunSeams('', false, true, 'ready', $calls)) === true, 'no answer yet but a gateway run process booting: rerun');
        $calls = [];
        check(Eternal::rerunAfterGateway(1, 0, 2, '/h', $say, eternalRerunSeams('', true, false, 'ready', $calls)) === true, 'no answer, gateway lock held: rerun while reruns remain');
    },
    'eternal gateway rerun: no rerun when the gateway was ready, gone, or the run was quit, signalled or out of reruns' => function () {
        $say = function ($line) {};
        $calls = [];
        check(Eternal::rerunAfterGateway(1, 0, 0, '/h', $say, eternalRerunSeams('running', true, true, 'ready', $calls)) === false, 'gateway already served: the exit was something else');
        check(!in_array('wait', $calls, true), 'no wait when the gateway was not starting');
        $calls = [];
        check(Eternal::rerunAfterGateway(1, 0, 0, '/h', $say, eternalRerunSeams('', false, false, 'ready', $calls)) === false, 'nothing starting: the window ends as before');
        $calls = [];
        check(Eternal::rerunAfterGateway(1, 0, 0, '/h', $say, eternalRerunSeams('starting', true, false, 'gone', $calls)) === false, 'boot ended without a gateway: no rerun');
        $calls = [];
        check(Eternal::rerunAfterGateway(0, 0, 0, '/h', $say, eternalRerunSeams('starting', true, false, 'ready', $calls)) === false && $calls === [], 'exit 0 is left to afterExit');
        check(Eternal::rerunAfterGateway(1, 15, 0, '/h', $say, eternalRerunSeams('starting', true, false, 'ready', $calls)) === false && $calls === [], 'a signalled run is not rerun');
        check(Eternal::rerunAfterGateway(1, 0, Eternal::GATEWAY_RERUNS, '/h', $say, eternalRerunSeams('starting', true, false, 'ready', $calls)) === false && $calls === [], 'reruns are capped');
        check(Eternal::rerunAfterGateway(1, 0, 0, '', $say, eternalRerunSeams('starting', true, false, 'ready', $calls)) === false && $calls === [], 'no HERMES_HOME: no rerun');
    },
    'eternal gateway rerun: the resident loops on rerunAfterGateway only for an Eternal and before afterExit' => function () {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        $loop = strpos($bin, "} while (\$eternalId !== '' && Eternal::rerunAfterGateway(");
        $after = strpos($bin, 'Eternal::afterExit($eternalId, $code, $eternalStarted, $eternalSignalled);' . "\n", (int) $loop);
        check($loop !== false && $after !== false && $loop < $after, 'loop precedes the afterExit that records the final exit');
    },
];
