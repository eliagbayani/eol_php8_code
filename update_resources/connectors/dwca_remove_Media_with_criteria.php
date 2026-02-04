<?php
namespace php_active_record;
/* To remove some Media records with criteria and main code is in: DwCA_RemoveMediaWithCriteria.php
First client: AntWeb (resource ID 24)
php dwca_remove_Media_with_criteria.php _ '{"source_dwca": "AntWeb_ENV_3", "resource_id":"AntWeb_ENV_4", "resource":"remove_Media_with_criteria"}'
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
$timestart = time_elapsed();
$GLOBALS['ENV_DEBUG'] = true;
require_library('connectors/DwCA_Utility');
// ini_set('memory_limit','9096M');

// print_r($argv);
$params['jenkins_or_cron']  = @$argv[1]; //not needed here
$params                     = json_decode(@$argv[2], true);
$source_dwca = @$params['source_dwca'];
$resource_id = @$params['resource_id']; 

$dwca = CONTENT_RESOURCE_LOCAL_PATH.$source_dwca.'.tar.gz';

$func = new DwCA_Utility($resource_id, $dwca, $params);
$preferred_rowtypes = array();
$excluded_rowtypes = array('http://eol.org/schema/media/document');
$func->convert_archive($preferred_rowtypes, $excluded_rowtypes);
Functions::finalize_dwca_resource($resource_id, true, false, $timestart); //3rd param true means delete folder

/* copied template
$ret = run_utility($resource_id); //check for orphan records in MoF
*/
recursive_rmdir(CONTENT_RESOURCE_LOCAL_PATH.$resource_id."/"); //we can now delete folder after run_utility() - DWCADiagnoseAPI
/* copied template
function run_utility($resource_id)
{
    require_library('connectors/DWCADiagnoseAPI');
    $func = new DWCADiagnoseAPI();
    $undefined_parents = $func->check_if_all_parents_have_entries($resource_id, true, false, false, 'parentMeasurementID', 'measurement_or_fact_specific.tab');
    echo "\nTotal undefined parents MoF:" . count($undefined_parents)."\n";
} */
?>