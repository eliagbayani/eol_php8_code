<?php
namespace php_active_record;
exit("\nThis was never used. Left unfinished but has a good start and intension.\n");
/* this is based from the template: investigate_dwca.php
this is used to investigate a DwCA, and its extensions. 
php investigate_dwca_WikipediaInferredTraits.php _ '{"resource_id": "wikipedia_en_traits_tmp4"}'
php investigate_dwca_WikipediaInferredTraits.php _ '{"resource_id": "wikipedia_en_traits_ForReview_v4"}'
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
$timestart = time_elapsed();
// ini_set('memory_limit','7096M'); //required

// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode(@$argv[2], true);
$resource_id = $param['resource_id'];
print_r($param);

run_utility($resource_id);

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n\n";
echo "\n elapsed time = " . $elapsed_time_sec/60 . " minutes";
echo "\n elapsed time = " . $elapsed_time_sec/60/60 . " hours";
echo "\n Done processing.\n";

function run_utility($resource_id)
{
    // /* utility ==========================
    require_library('connectors/DWCADiagnoseAPI');
    $func = new DWCADiagnoseAPI();
    
    // /* works OK - but not needed for this resource: wikipedia_en_traits_tmp4
    // $func->investigate_extension($resource_id, 'association.tab');
    // $func->investigate_extension($resource_id, 'occurrence.tab');
    // */
    
    /* check Associations integrity: works OK - but not needed for this resource: wikipedia_en_traits_tmp4
    $ret = $func->check_if_source_and_taxon_in_associations_exist($resource_id, false, 'occurrence_specific.tab');
    echo "\nundefined source occurrence [$resource_id]:" . count(@$ret['undefined source occurrence'])."\n";
    echo "\nundefined target occurrence [$resource_id]:" . count(@$ret['undefined target occurrence'])."\n";
    */
    
    // /* all valid tests: OK
    $undefined_parents = $func->check_if_all_parents_have_entries($resource_id, true); //2nd param true means output will write to text file
    echo "\nTotal undefined parents:" . count($undefined_parents)."\n"; unset($undefined_parents);

    $without = $func->get_all_taxa_without_parent($resource_id, true); //true means output will write to text file
    echo "\nTotal taxa without parents:" . count($without)."\n"; unset($without);

    $undefined_parents = $func->check_if_all_parents_have_entries($resource_id, true, false, false, 'parentMeasurementID', 'measurement_or_fact_specific.tab');
    echo "\nTotal undefined parents MoF:" . count($undefined_parents)."\n";
    // */

    $undefined = $func->check_if_all_occurrences_have_entries($resource_id, true); //true means output will write to text file
    if($undefined) echo "\nERROR: There is undefined taxonID(s) in OCCURRENCE.tab: ".count($undefined)."\n";
    else           echo "\nOK: All taxonID(s) in OCCURRENCE.tab have TAXON entries.\n";
    // ===================================== */
}
?>