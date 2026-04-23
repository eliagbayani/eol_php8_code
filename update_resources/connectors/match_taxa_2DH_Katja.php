<?php
namespace php_active_record;
/* This matches any DwCA taxa extension to Dynamic Hierarchy. Uses Katja's instructions:
https://github.com/EOL/ContentImport/issues/33

clients: for neo4j trait resources
php update_resources/connectors/match_taxa_2DH.php _ '{"resource_id": "Brazilian_Flora"}'
php update_resources/connectors/match_taxa_2DH.php _ '{"resource_id": "globi_assoc"}'
php update_resources/connectors/match_taxa_2DH.php _ '{"resource_id": "WoRMS2EoL"}'

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
ini_set('memory_limit','9096M'); //8096M orig
$timestart = time_elapsed();


// $old = array("Eli", "Cha", "Isaiah", "Willie", "Winie", "Wilbel", "Susan");
// $new = array("Eli", "Cha", "Willie", "Winie", "Susan");
// $diff = array_diff($old, $new);
// print_r($diff); exit;

/*
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
$AncestryIndexVer = $param['AncestryIndexVer'];

$tmp_id = $param['resource_id']; //e.g. "Brazilian_Flora-with-hC_neo4j_1"
// $tmp_id .= "_neo4j_1"; //OBSOLETE line, "_neo4j_1" is now included in the resource_id passed.

$dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/'.$tmp_id.'.tar.gz';
$dwca_file = WEB_ROOT . "/applications/content_server/resources_3/".$tmp_id.".tar.gz"; //during dev only

// $resource_id .= "_neo4j_2"; //the DwCA with the new column eolID from DH --- OBSOLETE
$resource_id .= "_eolID"; //the DwCA with the new column eolID from DH

process_resource_url($dwca_file, $resource_id, $AncestryIndexVer, $timestart);

function process_resource_url($dwca_file, $resource_id, $AncestryIndexVer, $timestart)
{
    require_library('connectors/DwCA_Utility');
    $params['resource'] = "match_taxa_2DH";
    $params['AncestryIndexVer'] = $AncestryIndexVer;
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