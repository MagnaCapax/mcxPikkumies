<?php
/** config show/set and repair of the Servarr units against the fake (tests/fixtures/arr/config-routes.php). */
require_once __DIR__ . '/fixtures/arr/harness.php';

return [
    'arr config: show hides the key, the stored password and every secret; set changes one field and reads it back' => function () {
        $fx = arrFixture('sonarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['config', 'show', 'host', '--limit', '0']);
            $by = [];
            foreach (array_slice($l, 0, -1) as $r) {
                $by[$r['field']] = $r['value'];
            }
            check($code === 0 && $by['apiKey'] === 'hidden' && $by['password'] === 'hidden' && $by['proxyPassword'] === ''
                && $by['bindAddress'] === '127.0.0.1' && $by['username'] === 'exampleuser', 'show host: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['config', 'set', 'ui', 'firstDayOfWeek', '1']);
            check($code === 0 && $l[0]['old'] === 0 && $l[0]['value'] === 1 && arrState($fx)['cfg']['ui']['firstDayOfWeek'] === 1
                && !isset($l[0]['update_mechanism']) && strpos(file_get_contents($fx['config']), 'UpdateMechanism') === false, 'set ui: ' . $out);
            arrFakeInstaller($fx);
            list($code, $l, $out) = arrRun($fx, ['config', 'set', 'host', 'logLevel', 'debug']);
            check($code === 0 && arrState($fx)['host']['logLevel'] === 'debug' && $l[0]['update_mechanism'] === 'Script' && $l[0]['restart']['answered'] === true
                && strpos(file_get_contents($fx['config']), '<UpdateMechanism>Script</UpdateMechanism>') !== false
                && arrState($fx)['host']['apiKey'] === ARR_KEY && arrState($fx)['userPassword'] === ARR_LOGIN, 'set host: ' . $out);
            list($code, , $out) = arrRun($fx, ['config', 'set', 'mediamanagement', 'deleteEmptyFolders', 'true']);
            check($code === 0 && arrState($fx)['cfg']['mediamanagement']['deleteEmptyFolders'] === true, 'set mediamanagement: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr config: the login, the 127.0.0.1 address, the key, the user, permissions, unknown fields and wrong types are refused unchanged' => function () {
        $fx = arrFixture('radarr');
        try {
            foreach ([['host', 'bindAddress', '0.0.0.0', 'stays 127.0.0.1'], ['host', 'authenticationMethod', 'none', 'stays forms'],
                ['host', 'authenticationRequired', 'disabledForLocalAddresses', 'stays enabled'], ['host', 'apiKey', 'abc', 'not set with this verb'],
                ['host', 'password', 'abc', 'set-login'], ['host', 'username', 'someone', 'set-login'], ['host', 'urlBase', '/x', 'repair'],
                ['mediamanagement', 'chmodFolder', '777', 'not set with this verb'], ['ui', 'noSuchField', '1', 'has no setting'],
                ['ui', 'firstDayOfWeek', 'monday', 'whole number'], ['ui', 'enableColorImpairedMode', 'yes', 'true or false'],
                ['nosuch', 'x', '1', 'no settings section']] as $t) {
                list($code, , $out) = arrRun($fx, ['config', 'set', $t[0], $t[1], $t[2]]);
                check($code === 1 && strpos($out, $t[3]) !== false, implode(' ', $t) . ': ' . $out);
            }
            check(!arrCalls($fx, 'PUT', 'config/host/1') && !arrCalls($fx, 'PUT', 'config/ui/1') && !arrCalls($fx, 'PUT', 'config/mediamanagement/1'),
                'a refused set reached the app');
            arrFakeInstaller($fx);
            list($code, , $out) = arrRun($fx, ['config', 'set', 'host', 'authenticationMethod', 'Forms']);
            check($code === 0, 'setting the login to what it must stay is allowed: ' . $out);
        } finally {
            arrDone($fx);
        }
        $fx = arrFixture('prowlarr');
        try {
            list($code, , $out) = arrRun($fx, ['config', 'show', 'naming']);
            check($code === 1 && strpos($out, 'sections: host, ui, downloadclient') !== false, 'prowlarr has no library sections: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr repair: PMSS-safe address, login and UrlBase through the API, then the restart; no user means set-login, nothing changed' => function () {
        $fx = arrFixture('sonarr', ['base' => ArrAuth::proxyPath('sonarr')]);
        try {
            list($code, $l, $out) = arrRun($fx, ['repair']);
            check($code === 0 && $l[0]['changed'] === [] && !arrCalls($fx, 'PUT', 'config/host/1'), 'nothing to repair: ' . $out);
            $host = arrState($fx)['host'];
            arrSet($fx, ['host' => array_merge($host, ['bindAddress' => '*', 'authenticationRequired' => 'disabledForLocalAddresses', 'urlBase' => '/wrong'])]);
            arrFakeInstaller($fx);
            list($code, $l, $out) = arrRun($fx, ['repair']);
            $h = arrState($fx)['host'];
            check($code === 0 && $l[0]['changed'] === ['bindAddress', 'urlBase', 'authenticationRequired']
                && $h['bindAddress'] === '127.0.0.1' && $h['urlBase'] === ArrAuth::proxyPath('sonarr') && $h['authenticationRequired'] === 'enabled'
                && $l[0]['update_mechanism'] === 'Script' && $l[0]['restart']['answered'] === true
                && substr_count((string) file_get_contents($fx['dir'] . '/installer.log'), '--start-app') === 1, 'repair: ' . $out);
            check(arrState($fx)['userPassword'] === ARR_LOGIN, 'repair changed the password');
            arrSet($fx, ['host' => array_merge($h, ['authenticationMethod' => 'none', 'username' => '', 'password' => '']), 'userPassword' => '']);
            $puts = count(arrCalls($fx, 'PUT', 'config/host/1'));
            list($code, , $out) = arrRun($fx, ['repair']);
            check($code === 1 && strpos($out, 'set-login') !== false && count(arrCalls($fx, 'PUT', 'config/host/1')) === $puts, 'no user: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr repair: an empty config.xml is copied aside, never rewritten, and the hint names the installer rerun' => function () {
        $fx = arrFixture('radarr', ['config' => "<Config>\n  <Port>1"]);
        try {
            list($code, , $out) = arrRun($fx, ['repair']);
            $copies = glob($fx['config'] . '.pikkumies-*');
            check($code === 1 && strpos($out, 'copied to ~/.config/radarr/config.xml.pikkumies-') !== false && strpos($out, 'install-media-stack.sh') !== false
                && count($copies) === 1 && file_get_contents($copies[0]) === "<Config>\n  <Port>1"
                && file_get_contents($fx['config']) === "<Config>\n  <Port>1" && (fileperms($copies[0]) & 0777) === 0600, 'invalid: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr updates: config.xml goes back to Script only for the installer\'s apps; an app elsewhere keeps what it saved' => function () {
        $fx = arrFixture('radarr', ['where' => 'servarr']);
        try {
            list($code, $l, $out) = arrRun($fx, ['config', 'set', 'host', 'logLevel', 'debug'], ['PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/not-pmss']);
            check($code === 0 && !isset($l[0]['update_mechanism'])
                && strpos(file_get_contents($fx['config']), '<UpdateMechanism>BuiltIn</UpdateMechanism>') !== false, 'not installer-managed: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr updates: converge keeps every other byte and the mode, writes nothing when it already reads Script, refuses a link' => function () {
        $dir = testTempDir('pk-arr-upd');
        try {
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            $f = "$dir/config.xml";
            file_put_contents($f, "<Config>\n  <Port>8989</Port>\n  <UpdateMechanism>BuiltIn</UpdateMechanism>\n</Config>\n");
            chmod($f, 0640);
            check(ArrUpdates::converge($f, $uid) === true, 'BuiltIn was not rewritten');
            clearstatcache();
            check(file_get_contents($f) === "<Config>\n  <Port>8989</Port>\n  <UpdateMechanism>Script</UpdateMechanism>\n</Config>\n"
                && (fileperms($f) & 0777) === 0640, 'converge: ' . file_get_contents($f) . sprintf(' mode %o', fileperms($f) & 0777));
            $ino = fileinode($f);
            check(ArrUpdates::converge($f, $uid) === false && fileinode($f) === $ino, 'already Script, but the file was written');
            file_put_contents("$dir/bare.xml", "<Config>\n  <Port>1</Port>\n</Config>\n");
            ArrUpdates::converge("$dir/bare.xml", $uid);
            check(file_get_contents("$dir/bare.xml") === "<Config>\n  <Port>1</Port>\n  <UpdateMechanism>Script</UpdateMechanism>\n</Config>\n", 'tag not added as the installer adds it');
            file_put_contents("$dir/target.xml", "<Config>\n  <UpdateMechanism>BuiltIn</UpdateMechanism>\n</Config>\n");
            symlink("$dir/target.xml", "$dir/link.xml");
            $err = '';
            try {
                ArrUpdates::converge("$dir/link.xml", $uid);
            } catch (CliError $e) {
                $err = $e->getMessage();
            }
            check(strpos($err, 'a link') !== false && strpos(file_get_contents("$dir/target.xml"), 'BuiltIn') !== false && is_link("$dir/link.xml"), 'link: ' . $err);
            $err = '';
            try {
                ArrUpdates::converge($f, $uid + 1);
            } catch (CliError $e) {
                $err = $e->getMessage();
            }
            check(strpos($err, "not this account's own") !== false, 'another owner: ' . $err);
        } finally {
            testRmTree($dir);
        }
    },
    'arr updates: a restart that fails after Script is written back ends ok:false, says the app may still update itself and names restart' => function () {
        $fx = arrFixture('sonarr'); // no installer: the PMSS restart refuses
        try {
            list($code, $l, $out) = arrRun($fx, ['config', 'set', 'host', 'logLevel', 'debug']);
            check($code === 1 && strpos($out, 'host.logLevel was set, but Sonarr may still update itself') !== false
                && strpos($out, 'the restart that applies it failed') !== false && strpos($out, 'run `restart`') !== false
                && arrState($fx)['host']['logLevel'] === 'debug' && strpos(file_get_contents($fx['config']), '<UpdateMechanism>Script</UpdateMechanism>') !== false,
                'restart failed: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
];