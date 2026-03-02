<?php
namespace php_active_record;
/*
http://content.eol.org/resources/533
https://eol-jira.bibalex.org/browse/DATA-1767   WoRMS text objects- attribution data?
https://eol-jira.bibalex.org/browse/DATA-1827   updated WoRMS resource- traits have been added
https://eol-jira.bibalex.org/browse/DATA-1870   textmined habitat for additional resources
https://eol-jira.bibalex.org/browse/TRAM-520    Fresh copy of WoRMS DwCA

WORMS archive
Now partner provides/hosts a DWC-A file. Connector also converts Distribution text into structured data.
                            
Based on new Dec 2019: http://www.marinespecies.org/export/eol/WoRMS2EoL.zip.
'http://rs.tdwg.org/dwc/terms/measurementType' == 'Feedingtype' does not exist anymore
So this means there is no more association data from WoRMS.
26	Friday 2019-12-06 07:34:50 AM	{"agent.tab":1615,"measurement_or_fact_specific.tab":3406168,"media_resource.tab":85783,"occurrence_specific.tab":2068198,"reference.tab":616890,"taxon.tab":335022,"vernacular_name.tab":79161,"time_elapsed":{"sec":2669.63,"min":44.49,"hr":0.74}}
26	Friday 2020-06-12 01:22:50 AM	{"agent.tab":1664, "measurement_or_fact_specific.tab":3417987, "media_resource.tab":87379, "occurrence_specific.tab":2121662, "reference.tab":648535, "taxon.tab":355711, "vernacular_name.tab":80309, "time_elapsed":{"sec":3890.43, "min":64.84, "hr":1.08}}
26	Wed 2021-05-12 12:20:44 PM	{"agent.tab":1771, "measurement_or_fact_specific.tab":3402028, "media_resource.tab":92507, "occurrence_specific.tab":2214437, "reference.tab":689291, "taxon.tab":373164, "vernacular_name.tab":85152, "time_elapsed":false}

=========================================================================================
In Jenkins: run one connector after the other:
#OK 1
#OK 2
=========================================================================================
/Volumes/Crucial_2TB/eol_php_code_tmp2/eol-archive/WoRMS/docs/connector_info.txt
/Volumes/OWC_Express/other_files/My_Docker_content/webroot/eol_php8_code/update_resources/connectors/files/Wikipedia Inferred/Stats_WoRMS.numbers
/Volumes/OWC_Express/other_files/My_Docker_content/webroot/eol_php8_code/update_resources/connectors/files/Wikipedia Inferred/Stats.numbers (other trait resources)
=========================================================================================
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
// $GLOBALS['ENV_DEBUG'] = false; //true;


/* e.g. php 26.php jenkins taxonomy */
$cmdline_params['jenkins_or_cron']  = @$argv[1]; //irrelevant here
$cmdline_params['what']             = @$argv[2]; //useful here

require_library('connectors/ContributorsMapAPI');
require_library('connectors/WormsArchiveAPI2026');
require_library('connectors/RemoveHTMLTagsAPI');

$timestart = time_elapsed();
ini_set('memory_limit','10096M'); //required. From 7096M

$resource_id = "26";
$cmdline_params['what'] = "media_objects";

// /* //main operation
$func = new WormsArchiveAPI2026($resource_id);
$func->start(); 
Functions::finalize_dwca_resource($resource_id, false, true, $timestart); //3rd param should be false so it doesn't remove the /26/ folder which will be used below when diagnosing...
// */

/* main operation - continued
run_utility($resource_id);
recursive_rmdir(CONTENT_RESOURCE_LOCAL_PATH."26/"); //we can now delete folder after run_utility() - DWCADiagnoseAPI
*/

// ==============================================================================================================================
// /* NEW Feb 11, 2020: start auto-remove children of 26_undefined_parentMeasurementIDs.txt in MoF ------------------------------
if(@filesize(CONTENT_RESOURCE_LOCAL_PATH.'26_undefined_parentMeasurementIDs.txt')) {
    echo "\nThere are: undefinedparentMeasurementIDs\n";
    $resource_id = "26";
    $dwca_file = CONTENT_RESOURCE_LOCAL_PATH . "/".$resource_id.".tar.gz";

    require_library('connectors/DwCA_Utility');
    $func = new DwCA_Utility($resource_id, $dwca_file);

    $preferred_rowtypes = array("http://rs.tdwg.org/dwc/terms/taxon", "http://eol.org/schema/media/document", 
                        "http://eol.org/schema/reference/reference", "http://eol.org/schema/agent/agent", "http://rs.gbif.org/terms/1.0/vernacularname");
    // These 2 will be processed in WoRMS_post_process.php which will be called from DwCA_Utility.php
    // http://rs.tdwg.org/dwc/terms/measurementorfact
    // http://rs.tdwg.org/dwc/terms/occurrence

    $func->convert_archive($preferred_rowtypes);
    Functions::finalize_dwca_resource($resource_id);

    run_utility($resource_id);
    recursive_rmdir(CONTENT_RESOURCE_LOCAL_PATH."26/"); //we can now delete folder after run_utility() - DWCADiagnoseAPI
}
// ------------------------------------------------------------------------------------------------------------------------------ */
// ==============================================================================================================================

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

    $undefined_parents = $func->check_if_all_parents_have_entries($resource_id, true); //2nd param true means output will write to text file
    echo "\nTotal undefined parents:" . count($undefined_parents)."\n"; unset($undefined_parents);

    $without = $func->get_all_taxa_without_parent($resource_id, true); //true means output will write to text file
    echo "\nTotal taxa without parents:" . count($without)."\n"; unset($without);

    $undefined_parents = $func->check_if_all_parents_have_entries($resource_id, true, false, false, 'parentMeasurementID', 'measurement_or_fact_specific.tab');
    echo "\nTotal undefined parents MoF:" . count($undefined_parents)."\n";
    // ===================================== */
}
?>