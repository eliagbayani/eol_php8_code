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

// /*
// $old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/TreatmentBank_old.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
// $new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/TreatmentBank_new.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// $old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/AntWeb_old.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
// $new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/AntWeb_new.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// $old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/Brazilian_Flora_old.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
// $new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/Brazilian_Flora_new.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/WoRMS_old_v3.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/WoRMS_new_v4.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$att = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/WoRMS_new_attempts_v4.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); //attempts


// $old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/wikipedia_en_traits_old_v3.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
// $new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/wikipedia_en_traits_new_v3.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
// $att = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/wikipedia_en_traits_new_attempts_v3.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); //attempts

// $old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/globi_assoc_old.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
// $new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/globi_assoc_new.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// /* ------------------------
$old = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/Cannot_be_matched_at_all_old.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$new = file(CONTENT_RESOURCE_LOCAL_PATH.'/for_Katja/Cannot_be_matched_at_all_new_v2.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
compare_CANNOT_BE_MATCHED_AT_ALL($old, $new); exit("\n-end report-\n");
// ------------------------ */


$old = array_map('trim', $old);
$new = array_map('trim', $new);
// /*
$att = array_map('trim', $att);
// */
$diff = array_diff($old, $new);

sort($diff);
print_r($diff); 
echo "\nold: [".count($old)."]";
echo "\nnew: [".count($new)."]";
echo "\ndiff: [".count($diff)."]";

// /*
echo "\nattempts: [".count($att)."]";
$intersect = array_intersect($att, $diff);
sort($intersect);
print_r($intersect); 
echo "\nintersect: [".count($intersect)."]";
// */

exit("\n -Wikipedia- \n");
// */


// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode(@$argv[2], true); // print_r($param); exit;
$resource_id = $param['resource_id'];
$AncestryIndexVer = $param['AncestryIndexVer'];

$tmp_id = $param['resource_id']; //e.g. "Brazilian_Flora-with-hC_neo4j_1"
// $tmp_id .= "_neo4j_1"; //OBSOLETE line, "_neo4j_1" is now included in the resource_id passed.

$dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/'.$tmp_id.'.tar.gz';
$dwca_file = WEB_ROOT . "/applications/content_server/resources/".$tmp_id.".tar.gz"; //during dev only

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
function compare_CANNOT_BE_MATCHED_AT_ALL($old, $new)
{
    // taxonID	furtherInformationURL	referenceID	acceptedNameUsageID	parentNameUsageID	
    // scientificName	namePublishedIn	
    // higherClassification	
    // kingdom	phylum	class	order	family	genus	taxonRank	taxonomicStatus	
    // taxonRemarks	
    // canonicalName	EOLid
    $old2 = array();
    foreach($old as $row) {
        $arr = explode("\t", $row); //print_r($arr); //exit;
        /*Array(
            [0] => taxonID
            [1] => furtherInformationURL
            [2] => referenceID
            [3] => acceptedNameUsageID
            [4] => parentNameUsageID
            [5] => scientificName
            [6] => namePublishedIn
            [7] => higherClassification
            [8] => kingdom
            [9] => phylum
            [10] => class
            [11] => order
            [12] => family
            [13] => genus
            [14] => taxonRank
            [15] => taxonomicStatus
            [16] => taxonRemarks
            [17] => canonicalName
            [18] => EOLid
        )*/
        unset($arr[7]); //print_r($arr); exit;
        unset($arr[16]); //print_r($arr); exit;
        $row = implode("\t", $arr);
        $old2[$row] = '';
    }
    unset($old);

    $new2 = array();
    foreach($new as $row) {
        $arr = explode("\t", $row); //print_r($arr); exit;
        $info[$arr[0]] = array('hC' => $arr[7], 'tR' => $arr[16]);
        unset($arr[7]);
        unset($arr[16]);
        $row = implode("\t", $arr);
        $new2[$row] = '';
    }
    unset($new);

    $diff = array();
    foreach(array_keys($new2) as $n) {
        if(!isset($old2[$n])) {
            $arr = explode("\t", $n); //print_r($arr); exit;
            $taxonID = $arr[0];
            $n .= "\t".$info[$taxonID]['hC'];
            $n .= "\t".$info[$taxonID]['tR'];
            $diff[] = $n;
            // exit("\n[$n]\n");
        }
    }

    sort($diff);
    echo "\nold: [".count($old2)."]";
    echo "\nnew: [".count($new2)."]";
    echo "\ndiff: [".count($diff)."]";
    print_r($diff); 

}
?>