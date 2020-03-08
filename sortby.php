<?php

$jDataString = filter_var($_GET['data'], FILTER_SANITIZE_STRING,
        FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_BACKTICK);
require_once 'harParse.php';
$oHarParse = new harParse();
echo json_encode($oHarParse->sortData($jDataString));
die;

