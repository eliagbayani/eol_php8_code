<?php
namespace php_active_record;
/* With the assigned EOLid in taxon.tab using Katja's instructions:
https://github.com/EOL/ContentImport/issues/33
This lib. will now use EOLid for: taxon->taxonID
                                  vernacular->taxonID  
                                  media->taxonID
                                  occurrence->taxonID

clients: for neo4j trait resources
php update_resources/connectors/use_EOLid_as_taxonID.php _ '{"resource_id": "globi_assoc"}'
php update_resources/connectors/xxx.php _ '{"resource_id": "Brazilian_Flora"}'

php use_EOLid_as_taxonID.php _ '{"resource_id": "WoRMS-with-hC"}' # gen neo4j_3


These ff. workspaces work together:
- generate_higherClassification_8.code-workspace
- DHConnLib_8.code-workspace
- GNParserAPI_8.code-workspace
- DwCA_MatchTaxa2DH.code-workspace
- UseEOLidInTaxon.code-workspace
- GenerateCSV_4Neo4j.code-workspace
==================================================================== generate tar.gz
tar -czf folder.tar.gz folder/
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
$tmp_id .= "_neo4j_2"; //reads this file

$dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/'.$tmp_id.'.tar.gz';
$dwca_file = WEB_ROOT . "/applications/content_server/resources_3/".$tmp_id.".tar.gz";  //during dev only
$dwca_file = CONTENT_RESOURCE_LOCAL_PATH . "/".$tmp_id.".tar.gz";                       //maybe the way to go

$resource_id .= "_neo4j_3"; //the DwCA using EOLid in taxonID of taxon.tab

process_resource_url($dwca_file, $resource_id, $timestart);

function process_resource_url($dwca_file, $resource_id, $timestart)
{
    require_library('connectors/DwCA_Utility');
    $params['resource'] = "use_EOLid_as_taxonID";
    $func = new DwCA_Utility($resource_id, $dwca_file, $params);

    $preferred_rowtypes = array("http://eol.org/schema/reference/reference", "http://eol.org/schema/agent/agent");
    $preferred_rowtypes[] = "http://rs.gbif.org/terms/1.0/reference"; //just in case used by some DwCA
    $excluded_rowtypes = array('http://rs.tdwg.org/dwc/terms/taxon');

    /* These (if exists) will be processed in DwCA_MatchTaxa2DH.php which will be called from DwCA_Utility.php 
        http://rs.gbif.org/terms/1.0/vernacularname
        http://eol.org/schema/media/document
        http://rs.tdwg.org/dwc/terms/measurementorfact
        http://eol.org/schema/association
        and occurrence tab
    */
    $func->convert_archive($preferred_rowtypes, $excluded_rowtypes);
    Functions::finalize_dwca_resource($resource_id, false, true, $timestart);
}
?>