<?php 
namespace php_active_record;
/* Maintenance of the TB References: 
start Oct 23, 2026:
php do_reference_mtce.php _ '{"task": "prepare_undefined_referenceIDs_list"}'               //steps 1 & 2
php do_reference_mtce.php _ '{"task": "update_References_from_UndefinedRefIDs_files"}'      //steps 3 & 4
php do_reference_mtce.php _ '{"task": "refresh_unmappable_identifiers_usingMaster"}'        //step 5
php do_reference_mtce.php _ '{"task": "refresh_unmappable_identifiers_usingItself"}'        //step 5.1
php do_reference_mtce.php _ '{"task": "refresh_unmappable_identifiers_usingDatasets"}'     //step 5.2
php do_reference_mtce.php _ '{"task": "utility_report_duplicate_identifiers_from_master"}'  //Utility : step 6
php do_reference_mtce.php _ '{"task": "merge_zenodo_references"}'                          //merge Zenodo's new copy of references.tsv into our local master
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

require_library('connectors/ZenodoTraitBankAPI');
require_library('connectors/GenerateTB_Files_Functions');    
require_library('connectors/ReferenceMaintenanceAPI');
$func = new ReferenceMaintenanceAPI($param);
$func->start(); //main operation - update_References_from_UndefinedRefIDs_files
?>