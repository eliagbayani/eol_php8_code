<?php
namespace php_active_record;
/* This can be a template to update a resource's MoF.tab file 

First client: Brazilian_Flora.tar.gz
php update_resources/connectors/revise_textmine_keyword_map.php _ '{"resource_id": "Brazilian_Flora"}'

This workspace: ReviseKeyWordMap.code-workspace
is similar to the ones below:

These ff. workspaces work together:
- generate_higherClassification_8.code-workspace
- DHConnLib_8.code-workspace
- GNParserAPI_8.code-workspace
- DwCA_MatchTaxa2DH.code-workspace
- UseEOLidInTaxon.code-workspace
- GenerateCSV_4Neo4j.code-workspace
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


/* test only
$dwca_file = Functions::get_resource_url_path($resource_id);
echo "\n[$dwca_file]\n";

$dwca_file = CONTENT_RESOURCE_LOCAL_PATH . $resource_id . ".tar.gz";
echo "\n[$dwca_file]\n";

exit("\n-end-\n");
*/

// $dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/' . $resource_id . '.tar.gz';

// $dwca_file = Functions::get_resource_url_path($resource_id); //works OK also
// http://host.docker.internal:81/eol_php8_code/applications/content_server/resources_3/wikipedia_en_traits.tar.gz


$dwca_file = CONTENT_RESOURCE_LOCAL_PATH . $resource_id . ".tar.gz"; //works OK
// [/var/www/html/eol_php8_code/applications/content_server/resources_3/Brazilian_Flora.tar.gz]

/* copied template
if ($resource_id == "WoRMS2EoL") $dwca_file = LOCAL_HOST . "/cp/WORMS/WoRMS2EoL.zip";
*/

$resource_id .= "_revised"; //generates this file; latest implementation
process_resource_url($dwca_file, $resource_id, $timestart);

function process_resource_url($dwca_file, $resource_id, $timestart)
{
    require_library('connectors/DwCA_Utility');
    $params['resource'] = "revise_keyword_map";
    $func = new DwCA_Utility($resource_id, $dwca_file, $params);

    $preferred_rowtypes = array("http://rs.tdwg.org/dwc/terms/taxon", "http://rs.gbif.org/terms/1.0/vernacularname", "http://eol.org/schema/reference/reference", 
        "http://eol.org/schema/association", "http://eol.org/schema/agent/agent", "http://eol.org/schema/media/document");
    $preferred_rowtypes[] = "http://rs.gbif.org/terms/1.0/reference"; //just in case used by some DwCA
    $excluded_rowtypes = array("http://rs.tdwg.org/dwc/terms/occurrence", "http://rs.tdwg.org/dwc/terms/measurementorfact");

    /* dev only
    $preferred_rowtypes[] = "http://rs.tdwg.org/dwc/terms/occurrence";
    $preferred_rowtypes[] = "http://rs.tdwg.org/dwc/terms/measurementorfact";
    $excluded_rowtypes = array();
    */

    /* This will be processed in DwCA_ReviseKeywordMap.php which will be called from DwCA_Utility.php */
    $func->convert_archive($preferred_rowtypes, $excluded_rowtypes);
    Functions::finalize_dwca_resource($resource_id, false, true, $timestart);
}