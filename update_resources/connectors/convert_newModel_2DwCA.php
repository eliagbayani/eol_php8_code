<?php 
namespace php_active_record;
/* Main starting point for generating CSV files for loading into Neo4j

start Oct 1, 2026:
php generate_csv_new_model.php _ '{"concept_id": "23067562" , "redownload_zip_file_YN": 1}'         -> Biochemistry and Natural Products

*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
ini_set('memory_limit','8096M'); //required for GloBI
$timestart = time_elapsed();

// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode(@$argv[2], true); //print_r($param); exit;
$concept_id = @$param['concept_id'];

require_library('connectors/ZenodoTraitBankAPI');
require_library('connectors/ConvertNewModel_2DwCA');

$param['eol_resource_id'] = $param['concept_id'];
$func = new ConvertNewModel_2DwCA($param);
$func->convert($concept_id);
?>