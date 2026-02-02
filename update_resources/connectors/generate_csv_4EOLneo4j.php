<?php
namespace php_active_record;
/* Main starting point for generating CSV files for loading into Neo4j
php update_resources/connectors/generate_csv_4EOLneo4j.php _ '{"resource_id": "globi_assoc-with-hC_neo4j_2_OK"}' --- copied template

start Jan 27, 2026:
php update_resources/connectors/generate_csv_4EOLneo4j.php _ '{"resource_id": "WoRMS_TraitBank_1_0", "eol_resource_id": "worms"}'
php update_resources/connectors/generate_csv_4EOLneo4j.php _ '{"resource_id": "GloBI_TraitBank_1_0", "eol_resource_id": "globi"}'
php update_resources/connectors/generate_csv_4EOLneo4j.php _ '{"resource_id": "Wikipedia_TraitBank_1_0", "eol_resource_id": "wikipedia"}'
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
ini_set('memory_limit','8096M'); //required for GloBI
$timestart = time_elapsed();

/* hash in PHP
$str = 'This is the string to be hashed.';
echo "\nmd5: [".md5($str)."]\n";
$algos = hash_algos();
foreach($algos as $algo) {
    echo "\n$algo: [".hash($algo, $str). "]";
} exit;
*/

// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode(@$argv[2], true); //print_r($param); exit;
$resource_id = $param['resource_id'];

require_library('connectors/GenerateCSV_4EOLNeo4j');
$func = new GenerateCSV_4EOLNeo4j($param);
/* copied template
// $func->buildup_predicates(); //obsolete. Only generates based on a given file
// $func->buildup_predicates_all(); //the way to go. generates all from EOL Terms File: type 'measurement' and 'association'
*/
$func->assemble_data($resource_id);

?>