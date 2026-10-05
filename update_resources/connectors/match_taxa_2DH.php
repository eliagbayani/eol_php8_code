<?php
namespace php_active_record;
/* This matches any DwCA taxa extension to Dynamic Hierarchy. Uses Katja's instructions:
https://github.com/EOL/ContentImport/issues/33

clients: for neo4j trait resources
php match_taxa_2DH.php _ '{"resource_id": "Brazilian_Flora"                             ,"resource_type": "legacy_dwca"}'
php match_taxa_2DH.php _ '{"resource_id": "globi_assoc"                                 ,"resource_type": "legacy_dwca"}'
php match_taxa_2DH.php _ '{"resource_id": "WoRMS2EoL"                                   ,"resource_type": "legacy_dwca"}'
php match_taxa_2DH.php _ '{"resource_id": "23067562_Bioc_and_Natu_Prod-with-hC_neo4j_1" ,"resource_type": "TB_dwca"}'


These ff. workspaces work together:
- generate_higherClassification_8.code-workspace
- DHConnLib_8.code-workspace
- GNParserAPI_8.code-workspace
- DwCA_MatchTaxa2DH.code-workspace
- UseEOLidInTaxon.code-workspace
- GenerateCSV_4Neo4j.code-workspace
==================================================================== generate tar.gz
tar -czf protisten_v2_Eli.tar.gz protisten_v2_Eli/
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
ini_set('memory_limit','13096M'); //8096M orig | TreatmentBank needs 10096M 12096M | GBIF data coverage needs 11096M | GloBI needs 13096M
$timestart = time_elapsed();

/* file() converts rows into an array
$old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/TreatmentBank_old.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/TreatmentBank_new.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/AntWeb_old.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/AntWeb_new.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/Brazilian_Flora_old.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/Brazilian_Flora_new.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/WoRMS_old.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/WoRMS_new.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/wikipedia_en_traits_old.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/wikipedia_en_traits_new.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/globi_assoc_old.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/globi_assoc_new.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$diff = array_diff($old, $new);
sort($diff);
print_r($diff); 
echo "\nold: [".count($old)."]";
echo "\nnew: [".count($new)."]";
echo "\ndiff: [".count($diff)."]";
exit("\n -globi_assoc- \n");
*/

// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode(@$argv[2], true); // print_r($param); exit;
$resource_id = $param['resource_id'];
echo "\nRunning resource_id: [$resource_id]\n";

if(!isset($param['resource_type'])) exit("\nERROR: resource_type not set.\n");

$source_id = $resource_id; //e.g. "Brazilian_Flora-with-hC_neo4j_1" -> source file
$dwca_file = DOC_ROOT . "/applications/content_server/resources/".$source_id.".tar.gz";
$resource_id .= "_eolID"; //the DwCA with the new column eolID from DH -> target file
$param['resource_id'] = $resource_id;

process_resource_url($dwca_file, $param, $timestart);

function process_resource_url($dwca_file, $param, $timestart)
{
    $resource_id = $param['resource_id'];
    require_library('connectors/DwCA_Utility');
    $params['resource'] = "match_taxa_2DH";
    $params['resource_type'] = $param['resource_type'];
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