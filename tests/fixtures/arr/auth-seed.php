<?php
/**
 * The login side of the fake Servarr app (config-routes.php, router.php): config/host as the app returns it (Sonarr
 * HostConfigController.GetHostConfig: the stored password shown as "********", the API key in clear), the stored user,
 * and the other settings sections. A login is required (forms, enabled) unless a test changes it.
 */
function arrAuthState($app, $base, $port)
{
    return [
        'host' => ['id' => 1, 'bindAddress' => '127.0.0.1', 'port' => $port, 'sslPort' => 9898, 'enableSsl' => false,
            'authenticationMethod' => 'forms', 'authenticationRequired' => 'enabled', 'username' => 'exampleuser',
            'password' => '********', 'passwordConfirmation' => '', 'apiKey' => ARR_KEY, 'urlBase' => $base,
            'instanceName' => ucfirst($app), 'logLevel' => 'info', 'analyticsEnabled' => false, 'branch' => 'main',
            'updateMechanism' => 'script', 'updateScriptPath' => '', 'updateAutomatically' => false,
            'proxyEnabled' => false, 'proxyPassword' => '', 'backupRetention' => 28],
        'userName' => 'exampleuser', 'userPassword' => ARR_LOGIN, 'logins' => 0, 'lastPut' => '',
        'cfg' => ['ui' => ['id' => 1, 'firstDayOfWeek' => 0, 'theme' => 'auto', 'enableColorImpairedMode' => false],
            'downloadclient' => ['id' => 1, 'enableCompletedDownloadHandling' => true, 'autoRedownloadFailed' => true],
            'naming' => ['id' => 1, 'renameEpisodes' => false, 'standardEpisodeFormat' => '{Series Title} - {episode:00}'],
            'indexer' => ['id' => 1, 'minimumAge' => 0, 'retention' => 0, 'rssSyncInterval' => 60],
            'mediamanagement' => ['setPermissionsLinux' => false, 'chmodFolder' => '755', 'deleteEmptyFolders' => false]],
    ];
}

/** A login is required on every address: forms (or basic/external) and enabled. */
function arrFakeAuthOn(array $s)
{
    return isset($s['host']) && in_array(strtolower($s['host']['authenticationMethod']), ['forms', 'basic', 'external'], true)
        && strtolower($s['host']['authenticationRequired']) === 'enabled';
}

/** A PMSS installer for restart (ArrRestart): --stop-app ends the tmux session, --start-app counts a start and makes it. */
function arrFakeInstaller(array $fx)
{
    file_put_contents($fx['home'] . '/install-media-stack.sh', "#!/bin/bash\necho \"\$*\" >> \"\$HOME/../installer.log\"\n"
        . "case \"\$1\" in --stop-app=*) rm -f \"\$HOME/../tmux-up\" ;;\n"
        . "  --start-app=*) echo x >> \"\$HOME/../restarts\"; touch \"\$HOME/../tmux-up\" ;; esac\n");
    file_put_contents($fx['home'] . '/.bashrc.custom', "alias app='true'\n");
}
