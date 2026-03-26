<?php
namespace php_active_record;
/* 
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
// ini_set('memory_limit','8096M');
$timestart = time_elapsed();

// print_r($argv);
// $params['jenkins_or_cron'] = @$argv[1]; //not needed here
// $param                     = json_decode(@$argv[2], true); //print_r($param); exit;
// $resource_id = $param['resource_id'];


/* Working OK
$arr['combined_CSVs']['edges']['file_1.csv'] = 'destination 1';
$arr['combined_CSVs']['edges']['file_2.csv'] = 'destination 2';
$arr['combined_CSVs']['edges']['file_3.csv'] = 'destination 3';
$arr['combined_CSVs']['nodes']['file_4.csv'] = 'destination 4';
$arr['combined_CSVs']['nodes']['file_5.csv'] = 'destination 5';
$arr['combined_CSVs']['nodes']['file_6.csv'] = 'destination 6';
$a = $arr['combined_CSVs'];
foreach($a as $path => $filenames) {
    echo "\n-----\npath: $path\n";
    print_r($filenames);
    foreach($filenames as $fname => $destination) {
        echo "\n[$fname] [$destination]";
    }
}
exit("\n-end test-\n");
*/


$param = array();
require_library('connectors/AggregateCSV_4Neo4j');
$func = new AggregateCSV_4Neo4j($param);
$func->start();
?>