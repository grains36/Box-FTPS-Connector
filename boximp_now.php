<?php
// No-auth entry point. Triggered by the cron in BoxFTPSConnectorModule::importmethod()
// via a scheduled curl request. Do not add authentication here - REDCap's cron has no
// logged-in session. For manual/interactive testing, use boximp_manual.php instead,
// which requires a normal authenticated REDCap session.

$_GET['location'] = "this";
require_once __DIR__ . "/boxftpsconnector.php";

$module = new \BoxFTPSConnector\BoxImportScript\BoxFTPSConnector();
$pid    = $_GET['pid'] ?? null;

$module->runImportJob($pid);
