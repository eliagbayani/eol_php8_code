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
    ksort($filenames); //important
    // print_r($filenames);
    foreach($filenames as $fname => $destination) {
        echo "\n[$fname] [$destination]";
    }
}
exit("\n-end test-\n");
*/

// /* Working OK ?
$arr['worms_CSVs']['nodes']['file_3.csv'] = 1;
$arr['worms_CSVs']['nodes']['file_1.csv'] = 2;
$arr['worms_CSVs']['nodes']['file_2.csv'] = 3;
$arr['worms_CSVs']['edges']['FILE_6.csv'] = 4;
$arr['worms_CSVs']['edges']['FILE_4.csv'] = 5;
$arr['worms_CSVs']['edges']['FILE_5.csv'] = 6;

$arr['globi_CSVs']['nodes']['file_9.csv'] = 7;
$arr['globi_CSVs']['nodes']['file_7.csv'] = 8;
$arr['globi_CSVs']['nodes']['file_8.csv'] = 9;
$arr['globi_CSVs']['edges']['FILE_12.csv'] = 10;
$arr['globi_CSVs']['edges']['FILE_10.csv'] = 11;
$arr['globi_CSVs']['edges']['FILE_11.csv'] = 12;

$r = array_keys($arr);
$r = array_unique($r); //make unique
$r = array_values($r); //reindex key

foreach($r as $resource_name) { echo "\n-----Resource: [$resource_name]\n";
    $a = $arr[$resource_name];
    foreach($a as $path => $filenames) {
        ksort($filenames); //important
        echo "\n-----\npath: $path\n";
        foreach($filenames as $fname => $total) {
            echo "\n[$fname] [total = $total]";
        }
    }
}

// $save_path = $this->path['stats'].'/'.$resource_name.'.tsv';
// $WRITE = Functions::file_open($save_path, 'a');
// if(!file_exists($save_path)) {
//     array_unshift($filenames, "Date"); //add an element on the start of an array
//     fwrite($WRITE, implode("\t", $filenames)."\n");
// }
// fclose($WRITE);        





exit("\n-end test-\n");
// */

$param = array();
require_library('connectors/AggregateCSV_4Neo4j');
$func = new AggregateCSV_4Neo4j($param);
$func->start();
?>