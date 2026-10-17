<?php
/** kb: search and list this host's kb sources, downloaded (ADR-0037). The verbs live in ../lib/DocsUnit.php. */
require_once dirname(__DIR__) . '/lib/DocsUnit.php';
DocsUnit::run($argv, 'kb', 'kb.php');
