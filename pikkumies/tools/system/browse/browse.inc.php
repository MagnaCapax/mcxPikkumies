<?php
/** The browse unit's classes, for browse.php and for bin/pikkumies (doctor, and Hermes' environment at a start). */
foreach (['BrowsePaths', 'BrowseBinary', 'BrowseArgv', 'BrowseIsolation', 'BrowseProc', 'BrowseState', 'BrowseCheck', 'BrowseSupervisor', 'BrowseCli'] as $browseClass) {
    require_once __DIR__ . "/$browseClass.php";
}
