<?php
namespace php_active_record;
/*
Processes a DwCA file, preferably an EOL DwCA file.
For non-EOL DwCA file, the result archive will only consist of extensions and fields that are understood by the EOL DwCA.
*Another similar library is DWCA_Utility_cmd.php. This one will process a DwCA taxa extension (taxon.tab/txt/tsv). And this one is run as command-line in terminal.

$ php dwca_utility.php jenkins '{"resource_id": "704"}'                                 //with jenkins (in eol-archive). Just plain conversion to EOL DwCA
$ php dwca_utility.php jenkins '{"resource_id": "704", "task": "gen_hC_using_pID"}'     //with jenkins (in eol-archive), and with higherClassification
$ php dwca_utility.php _       '{"resource_id": "704", "task": "gen_hC_using_pID"}'         

These ff. workspaces work together:
- DHConnLib_8.code-workspace
- GNParserAPI_8.code-workspace
- DwCA_MatchTaxa2DH.code-workspace
- generate_higherClassification_8.code-workspace
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
require_library('connectors/DwCA_Utility');
$timestart = time_elapsed();
// ini_set("memory_limit","4000M");
// $GLOBALS['ENV_DEBUG'] = true;
//===========================================================================================new - start -- handles cmdline params
// print_r($argv);
$params                     = json_decode(@$argv[2], true); // print_r($param); exit;
$params['jenkins_or_cron']  = @$argv[1]; //not needed here
print_r($params);
$resource_id = $params['resource_id'];

if($resource_id == 704) $dwca_file = "https://opendata.eol.org/dataset/7a17dc15-cb08-4e41-b901-6af5fd89bcd7/resource/3c56c4e4-3be7-463b-b958-22fbc560cf0d/download/pantheria.zip";
else { // the rest goes here
    $dwca_file = CONTENT_RESOURCE_LOCAL_PATH.$resource_id.".tar.gz";
    /* can be deleted
    if(is_dir(CONTENT_RESOURCE_LOCAL_PATH.$resource_id)) {}
    elseif(file_exists(CONTENT_RESOURCE_LOCAL_PATH.$resource_id.".tar.gz")) {} //e.g. those dwca generated from spreadsheet_2_dwca.php
    else exit("\nProgram will terminate. Invalid resource_id [$resource_id].\n\n");
    */
}
if($params['task'] == "gen_hC_using_pID") $resource_id .= "-with-higherClassification";
//===========================================================================================new - end
echo "\n[$resource_id] [$dwca_file] [".$params['task']."]\n";

// /* //main operation
$func = new DwCA_Utility($resource_id, $dwca_file);
if($params['task'] == "gen_hC_using_pID") $func->convert_archive_by_adding_higherClassification();
else                                      $func->convert_archive(); //this is same as above; just doesn't generate higherClassification
Functions::finalize_dwca_resource($resource_id, false, true);
unset($func);
// */

/* //utility - useful when generating higherClassification
// $dwca_file = WEB_ROOT."/applications/content_server/resources/dwca-phasmida-v10-with-higherClassification.tar.gz"; //debug -> if you want to supply a diff. dwca
$func = new DwCA_Utility(NULL, $dwca_file);
$func->count_records_in_dwca();
unset($func);
*/

/* utility - useful when generating higherClassification
require_library('connectors/DWCADiagnoseAPI');
$func = new DWCADiagnoseAPI();
$undefined_parents = $func->check_if_all_parents_have_entries($resource_id, false); //true means output will write to text file
echo "\nTotal undefined parents:" . count($undefined_parents);
*/

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n\n";
echo "\n elapsed time = " . $elapsed_time_sec/60 . " minutes";
echo "\n elapsed time = " . $elapsed_time_sec/60/60 . " hours";
echo "\n Done processing.\n";
?>