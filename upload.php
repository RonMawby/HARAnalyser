<?php

require_once 'harParse.php';
$oData = json_decode(file_get_contents("php://input"));
if ($oData !== null && isset($oData->log) && isset($oData->log->entries)) {
    $oHarParse = new harParse();
    echo json_encode($oHarParse->parseEntries($oData->log->entries));
    die;
}