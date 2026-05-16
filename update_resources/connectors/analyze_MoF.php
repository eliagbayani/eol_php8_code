<?php
namespace php_active_record;
/* This analyzes the MoF extension
php update_resources/connectors/analyze_MoF.php _ '{"resource_id": "fishbase_final"}' //fishbase_final.tar.gz
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
ini_set('memory_limit','8096M');
$timestart = time_elapsed();

// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode(@$argv[2], true); // print_r($param); exit;
$resource_id = $param['resource_id'];

$tmp_id = $param['resource_id']; //e.g. "fishbase_final"
$dwca_file = WEB_ROOT . "/applications/content_server/resources_3/".$tmp_id.".tar.gz";
$resource_id .= "_analyzed"; //the DwCA with MoF extension analyzed

process_resource_url($dwca_file, $resource_id, $timestart);

function process_resource_url($dwca_file, $resource_id, $timestart)
{
    require_library('connectors/DwCA_Utility');
    $params['resource'] = "analyze_MoF";
    $func = new DwCA_Utility($resource_id, $dwca_file, $params);
    $preferred_rowtypes = array();
    $excluded_rowtypes = array('http://rs.tdwg.org/dwc/terms/taxon');
    /* This will be processed in AnalyzeMoF_API.php which will be called from DwCA_Utility.php */
    $func->convert_archive($preferred_rowtypes, $excluded_rowtypes);
    /* copied template, not needed here
    Functions::finalize_dwca_resource($resource_id, false, true, $timestart);
    */

    recursive_rmdir(CONTENT_RESOURCE_LOCAL_PATH . '/'.$resource_id.'_working');
    unlink(CONTENT_RESOURCE_LOCAL_PATH . '/'.$resource_id.'_working.tar.gz');
}
?>