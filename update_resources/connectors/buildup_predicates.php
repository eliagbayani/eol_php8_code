<?php
namespace php_active_record;
include_once(dirname(__FILE__) . "/../../config/environment.php");
$timestart = time_elapsed();
// $GLOBALS['ENV_DEBUG'] = true;

require_library('connectors/GenerateCSV_4Neo4j');
$func = new GenerateCSV_4Neo4j();
$func->buildup_predicates()

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n\n";
echo "elapsed time = " . $elapsed_time_sec/60 . " minutes \n";
echo "\nDone processing.\n";

?>