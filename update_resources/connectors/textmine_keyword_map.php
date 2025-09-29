<?php
namespace php_active_record;
include_once(dirname(__FILE__) . "/../../config/environment.php");
require_library('connectors/TextmineKeywordMapAPI');
// ini_set('memory_limit','6096M');
// $GLOBALS['ENV_DEBUG'] = true;
$timestart = time_elapsed();

$func = new TextmineKeywordMapAPI();
$params['spreadsheetID'] = '1G3vCRvoJsYijqvJXwOooKdGj-jEiBop2EOl3lUxEny0';
$params['range']         = 'river!A1:D430'; //where "A" is the starting column, "D" is the ending column, and "1" is the starting row.
// $params['expire_seconds'] = 60*60*24*1; //1 day cache
// $params['expire_seconds'] = 0; //expire now 0 or false
$func->xxx($params);

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n\n";
echo "elapsed time = " . $elapsed_time_sec/60 . " minutes \n";
echo "elapsed time = " . $elapsed_time_sec/60/60 . " hours \n";
echo "\nDone processing.\n";
?>