<?php
/** wiki: search and list this host's wiki sources, downloaded (ADR-0037). The verbs live in ../lib/DocsUnit.php. */
require_once dirname(__DIR__) . '/lib/DocsUnit.php';
DocsUnit::run($argv, 'wiki', 'wiki.php');
