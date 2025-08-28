<?php
namespace php_active_record;
/* Clients for DWCA_Measurements_ReassignValues() and purpose of this API is in: DWCA_Measurements_ReassignValuesAPI.php
php update_resources/connectors/dwca_MoF_reassign_values.php _ '{"resource_id":"natdb_meta_recoded", "resource":"MoF_reassign_values"}'
php update_resources/connectors/dwca_MoF_reassign_values.php _ '{"resource_id":"TreatmentBank_adjustment_03", "resource":"MoF_reassign_values"}'
php                             dwca_MoF_reassign_values.php _ '{"resource_id":"polytraits_01", "resource":"MoF_reassign_values"}'
php                             dwca_MoF_reassign_values.php _ '{"resource_id":"726_meta_recoded", "resource":"MoF_reassign_values"}'
php                             dwca_MoF_reassign_values.php _ '{"resource_id":"726_meta_recoded_01", "resource":"MoF_reassign_values"}'
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
$timestart = time_elapsed();
// echo "\n".date("Y_m_d_H_i_s")."\n"; exit;
$GLOBALS['ENV_DEBUG'] = true;
require_library('connectors/DwCA_Utility');
// ini_set('memory_limit','9096M'); //required

// print_r($argv);
$params['jenkins_or_cron']  = @$argv[1]; //not needed here
$params                     = json_decode(@$argv[2], true);
$resource_id = @$params['resource_id']; 

// if(Functions::is_production())  $dwca = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/'.$resource_id.'.tar.gz';
// else                            $dwca = 'http://localhost/eol_php_code/applications/content_server/resources_3/'.$resource_id.'.tar.gz'; //orig
// else                            $dwca = WEB_ROOT . '/applications/content_server/resources_3/'.$resource_id.'.tar.gz'; // PHP 8.2 compatible; OK also
$dwca = CONTENT_RESOURCE_LOCAL_PATH.$resource_id.'.tar.gz'; //seems a better sol'n.

// /* ---------- CUSTOMIZE HERE: ----------
if($resource_id == "natdb_meta_recoded")              $resource_id = "natdb_temp_1"; //this will be moved to natdb_final.tar.gz in Jenkins script.
elseif($resource_id == "TreatmentBank_adjustment_03") $resource_id = "TreatmentBank_adjustment_04"; //this will be moved to TreatmentBank_final.tar.gz in Jenkins script.
elseif($resource_id == "polytraits_01")               $resource_id = "polytraits_new";
elseif($resource_id == "726_meta_recoded")            $resource_id = "726_meta_recoded_01";
elseif($resource_id == "726_meta_recoded_01")         $resource_id = "726_meta_recoded_02";
else exit("\nresource ID*** not yet initialized [$resource_id]\n");
// ---------------------------------------- */

$func = new DwCA_Utility($resource_id, $dwca, $params);
$preferred_rowtypes = array();
$excluded_rowtypes = array('http://rs.tdwg.org/dwc/terms/measurementorfact');
$func->convert_archive($preferred_rowtypes, $excluded_rowtypes);
Functions::finalize_dwca_resource($resource_id, true, false, $timestart); //3rd param true means delete folder

$ret = run_utility($resource_id); //check for orphan records in MoF
recursive_rmdir(CONTENT_RESOURCE_LOCAL_PATH.$resource_id."/"); //we can now delete folder after run_utility() - DWCADiagnoseAPI

function run_utility($resource_id)
{
    // /* utility ==========================
    require_library('connectors/DWCADiagnoseAPI');
    $func = new DWCADiagnoseAPI();
    $undefined_parents = $func->check_if_all_parents_have_entries($resource_id, true, false, false, 'parentMeasurementID', 'measurement_or_fact_specific.tab');
    echo "\nTotal undefined parents MoF:" . count($undefined_parents)."\n";
    // ===================================== */
}
?>