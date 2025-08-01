<?php
namespace php_active_record;
/* This matches any DwCA taxa extension to Dynamic Hierarchy. Uses Katja's instructions:
https://github.com/EOL/ContentImport/issues/33

clients: for neo4j trait resources
php update_resources/connectors/match_taxa_2DH.php _ '{"resource_id": "Brazilian_Flora"}'
php update_resources/connectors/match_taxa_2DH.php _ '{"resource_id": "globi_assoc"}'
php update_resources/connectors/match_taxa_2DH.php _ '{"resource_id": "WoRMS2EoL"}'

These ff. workspaces work together:
- DHConnLib_8.code-workspace
- GNParserAPI_8.code-workspace
- DwCA_MatchTaxa2DH.code-workspace
- generate_higherClassification_8.code-workspace
==================================================================== generate tar.gz
tar -czf protisten_v2_Eli.tar.gz protisten_v2_Eli/
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
$tmp_id = $param['resource_id'];
$tmp_id .= "_neo4j_1";

$dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/'.$tmp_id.'.tar.gz';
$dwca_file = WEB_ROOT . "/applications/content_server/resources_3/".$tmp_id.".tar.gz"; //during dev only

$resource_id .= "_neo4j_2"; //the DwCA with the new column eolID from DH

process_resource_url($dwca_file, $resource_id, $timestart);

function process_resource_url($dwca_file, $resource_id, $timestart)
{
    require_library('connectors/DwCA_Utility');
    $params['resource'] = "match_taxa_2DH";
    $func = new DwCA_Utility($resource_id, $dwca_file, $params);

    $preferred_rowtypes = array("http://rs.gbif.org/terms/1.0/vernacularname", "http://eol.org/schema/reference/reference", 
        "http://rs.tdwg.org/dwc/terms/occurrence", "http://rs.tdwg.org/dwc/terms/measurementorfact", "http://eol.org/schema/association",    
        "http://eol.org/schema/agent/agent", "http://eol.org/schema/media/document");
    $preferred_rowtypes[] = "http://rs.gbif.org/terms/1.0/reference"; //just in case used by some DwCA
    $excluded_rowtypes = array('http://rs.tdwg.org/dwc/terms/taxon');

    /* This will be processed in DwCA_MatchTaxa2DH.php which will be called from DwCA_Utility.php */
    $func->convert_archive($preferred_rowtypes, $excluded_rowtypes);
    Functions::finalize_dwca_resource($resource_id, false, true, $timestart);
}
?>