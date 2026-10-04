<?php 
namespace php_active_record;
/* Convert Zenodo TraitBank datasets to EOL DwCA
start Oct 2, 2026:
php convert_new_model_2DwCA.php _ '{"concept_id": "23067562" , "redownload_zip_file_YN": 0}'    -> Biochemistry and Natural Products
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
    // require_library('connectors/DwCA_Utility');
    // $params['resource'] = "convert_model_2DwCA";
    // $func = new DwCA_Utility($resource_id, $dwca_file, $params);
    // $preferred_rowtypes = array();
    // $excluded_rowtypes = array();
    // /* This will be processed in ConvertNewModel_2DwCA.php.php which will be called from DwCA_Utility.php */
    // $func->convert_archive($preferred_rowtypes, $excluded_rowtypes);

    $resource_id = $param['concept_id'];
    $concept_id = $param['concept_id'];

    require_library('connectors/ZenodoTraitBankAPI');
    $func = new ZenodoTraitBankAPI();
    $title = $func->get_zenodo_title_using_conceptID($concept_id);
    $folder = $func->get_dataset_folder_name($concept_id, $title);

    // $resource_id .= "_2dwca";
    $param['resource_id'] = $resource_id;
    $path_to_archive_directory = CONTENT_RESOURCE_LOCAL_PATH . '/' . $folder . '_working/';
    $archive_builder = new \eol_schema\ContentArchiveBuilder(array("directory_path" => $path_to_archive_directory));

    require_library('connectors/ConvertNewModel_2DwCA');
    $func = new ConvertNewModel_2DwCA($archive_builder, $param);
    $func->convert_2DwCA($param['concept_id']);

    echo "\nend: [$resource_id]\n";
    Functions::finalize_dwca_resource($folder, false, true, $timestart);
}
?>