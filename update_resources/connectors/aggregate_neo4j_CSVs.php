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
$param = array();
require_library('connectors/CSV_Aggregator_For_Neo4j');
$func = new CSV_Aggregator_For_Neo4j($param);
$func->start();
?>