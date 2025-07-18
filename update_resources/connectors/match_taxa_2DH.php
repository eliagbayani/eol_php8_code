<?php
namespace php_active_record;
/* This matches any DwCA taxa extension to Dynamic Hierarchy. Uses Katja's instructions:
https://github.com/EOL/ContentImport/issues/33

clients: for neo4j trait resources
php update_resources/connectors/run_gnparser_dwca.php _ '{"resource_id": "Brazilian_Flora"}'
php update_resources/connectors/run_gnparser_dwca.php _ '{"resource_id": "globi_assoc"}'
php update_resources/connectors/run_gnparser_dwca.php _ '{"resource_id": "WoRMS2EoL"}'

====================================================================
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
// ini_set('memory_limit','7096M');
$timestart = time_elapsed();

// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode(@$argv[2], true); // print_r($param); exit;
$resource_id = $param['resource_id'];

$dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/'.$resource_id.'.tar.gz';
$dwca_file = WEB_ROOT . "/applications/content_server/resources_3/".$resource_id.".tar.gz"; //during dev only

$resource_id .= "_neo4j_1"; //latest implementation

process_resource_url($dwca_file, $resource_id, $timestart);

function process_resource_url($dwca_file, $resource_id, $timestart)
{
    require_library('connectors/DwCA_Utility');
    $params['resource'] = "match_taxa_2DH";
    $func = new DwCA_Utility($resource_id, $dwca_file, $params);

    $preferred_rowtypes = false;
    $excluded_rowtypes = array('http://rs.tdwg.org/dwc/terms/taxon');

    /* This will be processed in DwCA_MatchTaxa2DH.php which will be called from DwCA_Utility.php */
    $func->convert_archive($preferred_rowtypes, $excluded_rowtypes);
    Functions::finalize_dwca_resource($resource_id, false, true, $timestart);
}
?>