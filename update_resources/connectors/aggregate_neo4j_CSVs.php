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
$this_path_stats = CONTENT_RESOURCE_LOCAL_PATH . 'neo4j_stats';
if(!is_dir($this_path_stats)) mkdir($this_path_stats);

$arr['worms_CSVs']['nodes']['file_3.csv'] = 10;
$arr['worms_CSVs']['nodes']['file_1.csv'] = 20;
$arr['worms_CSVs']['nodes']['file_2.csv'] = 30;
$arr['worms_CSVs']['edges']['FILE_6.csv'] = 40;
$arr['worms_CSVs']['edges']['FILE_4.csv'] = 50;
$arr['worms_CSVs']['edges']['FILE_5.csv'] = 60;

$arr['globi_CSVs']['nodes']['file_9.csv'] = 70;
$arr['globi_CSVs']['nodes']['file_7.csv'] = 80;
$arr['globi_CSVs']['nodes']['file_8.csv'] = 90;
$arr['globi_CSVs']['edges']['FILE_12.csv'] = 100;
$arr['globi_CSVs']['edges']['FILE_10.csv'] = 110;
$arr['globi_CSVs']['edges']['FILE_11.csv'] = 120;

$r = array_keys($arr);
$r = array_unique($r); //make unique
$r = array_values($r); //reindex key

foreach($r as $resource_name) { echo "\n-----Resource: [$resource_name]";
    $save_path = $this_path_stats.'/'.$resource_name.'.tsv';
    $a = $arr[$resource_name];
    $values = array(); $headers = array();
    $headers[] = 'Date';
    $values[] = date('Y-m-d H:i:s A');
    foreach($a as $path => $filenames) {
        ksort($filenames); //important
        echo "\npath: $path";
        foreach($filenames as $fname => $total) {
            echo "\n[$fname] [total = $total]";
            $headers[] = $fname;
            $values[] = $total;
        }
    }

    // if(!file_exists($save_path)) {
        $WRITE = Functions::file_open($save_path, 'a');
        array_unshift($filenames, "Date"); //add an element on the start of an array
        fwrite($WRITE, implode("\t", $headers)."\n");
    // }
    $WRITE = Functions::file_open($save_path, 'a');
    fwrite($WRITE, implode("\t", $values)."\n");
    fclose($WRITE);        

}





// $save_path = $this_path_stats.'/'.$resource_name.'.tsv';
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