<?php
// Authenticated manual-trigger entry point. NOT listed in config.json's
// no-auth-pages / no-csrf-pages, so REDCap requires a normal logged-in
// session with access to this project before this file runs at all.
// Runs the exact same import job as the cron (see boxftpsconnector.php's
// runImportJob()) so it's a faithful way to test without waiting on cron
// or using the no-auth trigger.

require_once __DIR__ . "/boxftpsconnector.php";

$module = new \BoxFTPSConnector\BoxImportScript\BoxFTPSConnector();
$pid    = $_GET['pid'] ?? null;

$module->runImportJob($pid);
