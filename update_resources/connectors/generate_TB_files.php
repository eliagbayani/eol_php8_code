<?php
namespace php_active_record;
/* Generates the TraitBank input files.  Also, this analyzes the MoF extension
php generate_TB_files.php _ '{"resource_id": "TreatmentBank_TraitBank_1_0", "resource_type": ""}' # we may NOT need this for legacy DwCA
php generate_TB_files.php _ '{"resource_id": "22943003_Palm_TraitBank_1_0"}' # only for TB datasets
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
// ini_set('memory_limit','10096M');
$timestart = time_elapsed();

// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode(@$argv[2], true); // print_r($param); exit;
$resource_id = $param['resource_id'];

$tmp_id = $param['resource_id'];
$dwca_file = DOC_ROOT . "/applications/content_server/resources/".$tmp_id.".tar.gz";

process_resource_url($dwca_file, $param, $timestart);

function process_resource_url($dwca_file, $param, $timestart)
{
    $resource_id = $param['resource_id'];
    require_library('connectors/DwCA_Utility');
    $params['resource'] = "generate_TB_files";
    $params['resource_type'] = @$param['resource_type'];
    $func = new DwCA_Utility($resource_id, $dwca_file, $params);
    $preferred_rowtypes = array();
    $excluded_rowtypes = array();
    /* This will be processed in GenerateTB_FilesAPI.php which will be called from DwCA_Utility.php */
    $func->convert_archive($preferred_rowtypes, $excluded_rowtypes);

    unlink(CONTENT_RESOURCE_LOCAL_PATH.$resource_id.'_working.tar.gz'); //e.g. 22943003_Palm_TraitBank_working.tar.gz
    recursive_rmdir(CONTENT_RESOURCE_LOCAL_PATH.$resource_id.'_working'); //e.g. /22943003_Palm_TraitBank_working/
}
?>