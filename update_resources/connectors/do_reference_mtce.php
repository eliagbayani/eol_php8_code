<?php 
namespace php_active_record;
/* Maintenance of the TB References: 
start Oct 23, 2026:
php do_reference_mtce.php _ '{"concept_id": "23067562" , "task": ""}'
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
process_resource_url($param, $timestart);
function process_resource_url($param, $timestart)
{

    $resource_id = $param['concept_id'];
    $concept_id = $param['concept_id'];

    require_library('connectors/ZenodoTraitBankAPI');
    $func = new ZenodoTraitBankAPI();
    $title = $func->get_zenodo_title_using_conceptID($concept_id);
    $folder = $func->get_dataset_folder_name($concept_id, $title);

    $param['resource_id'] = $resource_id;
    // $path_to_archive_directory = CONTENT_RESOURCE_LOCAL_PATH . '/' . $folder . '_working/';
    // $archive_builder = new \eol_schema\ContentArchiveBuilder(array("directory_path" => $path_to_archive_directory));

    require_library('connectors/ReferenceMaintenanceAPI');
    $func = new ReferenceMaintenanceAPI($param);
    $func->convert_2DwCA($param['concept_id']); //main operation
    echo "\nend: [$resource_id]\n";
    Functions::finalize_dwca_resource($folder, false, true, $timestart);
}
?>