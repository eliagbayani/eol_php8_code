<?php
namespace php_active_record;
/*
execution time: 14.97 hours when HTTP request is NOT YET cached (as of Jul 4, 2019)
execution time: 3 hours when HTTP request is already cached
Connector processes a CSV file exported from the IUCN portal (www.iucnredlist.org). 
The exported CSV file is requested and is generated by the portal a couple of days afterwards.
The completion is confirmed via email to the person who requested it.

To be harvestd quarterly: https://jira.eol.org/browse/WEB-5427
#==== 8 PM, 25th of the month, quarterly (Feb, May, Aug, Nov) => IUCN Structured Data
00 20 25 2,5,8,11 * /usr/bin/php /opt/eol_php_code/update_resources/connectors/737.php > /dev/null

            taxon   measurementorfact   occurrence
2014 05 27  73,465  533,549
2014 08 14  76,022  554,047
2016 08 25  81,703  597,586             243,525
2017 10 05  80,823  591,236             240,800

737	Friday 2018-03-02 04:19:52 PM	{"measurement_or_fact.tab":591236,"occurrence.tab":240800,"taxon.tab":80823}
737	Wednesday 2018-03-07 11:16:22 AM{"measurement_or_fact.tab":591236,"occurrence.tab":240800,"taxon.tab":80823}
737	Wednesday 2018-03-07 07:04:07 PM{"measurement_or_fact.tab":591236,"occurrence.tab":240800,"taxon.tab":80823} all-hash measurementID
737	Thursday 2018-03-08 08:10:12 PM	{"measurement_or_fact.tab":591236,"occurrence.tab":240800,"taxon.tab":80823}
737	Wednesday 2019-07-03 11:19:27 PM{"measurement_or_fact.tab":591411,"occurrence.tab":240851,"taxon.tab":80818} latest for quite sometime, still looks consistent
737	Wednesday 2019-07-10 01:56:00 PM{"measurement_or_fact.tab":591411,"occurrence.tab":240851,"taxon.tab":80818} updated: six metadata now as child records
737	Tuesday 2019-07-30 11:56:36 PM	{"measurement_or_fact.tab":591411,  "occurrence.tab":240851, "taxon.tab":80818} fixed sciname string with subpopulation, add locality
737	Wed 2021-05-26 10:04:54 AM	    {"measurement_or_fact.tab":1005463, "occurrence.tab":359364, "taxon.tab":118716, "time_elapsed":false}
stable run:
737	Sun 2021-05-30 09:02:22 AM	    {"measurement_or_fact.tab":921035,  "occurrence.tab":359364, "taxon.tab":118716, "time_elapsed":false}
back to orig strings, not URIs for /Assessors, /Reviewers
737	Wed 2021-06-02 02:46:44 AM	    {"measurement_or_fact.tab":921174, "occurrence.tab":359364, "taxon.tab":118716, "time_elapsed":false}
737	Mon 2021-06-07 11:06:32 AM	    {"measurement_or_fact.tab":921181, "occurrence.tab":359367, "taxon.tab":118716, "time_elapsed":false}
start below rank set to 'species'
737	Tue 2022-01-04 10:02:08 AM	    {"measurement_or_fact.tab":990810, "occurrence.tab":385458, "taxon.tab":127110, "time_elapsed":false}
737	Tue 2022-01-04 09:33:24 PM	    {"measurement_or_fact.tab":990810, "occurrence.tab":385458, "taxon.tab":127110, "time_elapsed":false}
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
/*
$GLOBALS['ENV_DEBUG'] = false;
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING); //report all errors except notice and warning
*/
require_library('connectors/ContributorsMapAPI');
require_library('connectors/IUCNRedlistDataConnector');

/*
$final2 = array();
$part = 'Matusin, Dg Ku Rozianah';
$arr2 = explode(" and ", $part);
foreach($arr2 as $item) $final2[$item] = '';
print_r(array_keys($final2));
exit("\n-end-\n");
*/

/*
$str = "eli is and the & best , right";
$parts = preg_split("/(, | and | and | & )/",$str);
print_r($parts);
exit("\n-end-\n");
*/

/* test only
$str = "1234 6";
$len = strlen($str);
$char = substr($str,$len-2,1);
echo "\n[$char]\n";
exit("\n-end-\n");
*/

$timestart = time_elapsed();
$resource_id = 737;
// if(!Functions::can_this_connector_run($resource_id)) return; //obsolete

// /* NOTE: like 211.php a manual step is needed to update partner source file (export-74550.csv.zip)
$func = new IUCNRedlistDataConnector($resource_id);
$func->generate_IUCN_data();
Functions::finalize_dwca_resource($resource_id, false, true); //3rd param orig value is true
// */

// Functions::set_resource_status_to_harvest_requested($resource_id); //obsolete

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n\n";
echo "\n elapsed time = " . $elapsed_time_sec . " seconds";
echo "\n elapsed time = " . $elapsed_time_sec/60 . " minutes";
echo "\n elapsed time = " . $elapsed_time_sec/60/60 . " hours";
echo "\n Done processing.\n";
?>