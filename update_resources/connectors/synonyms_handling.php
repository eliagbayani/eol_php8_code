<?php
namespace php_active_record;
/* this script will update EOL DwCA and implement synonym handling suggested here: DATA-1822

php update_resources/connectors/synonyms_handling.php _ itis_2019-08-28
                         php5.6 synonyms_handling.php jenkins itis_2019-08-28

php update_resources/connectors/synonyms_handling.php _ itis_2020-07-28
                         php5.6 synonyms_handling.php jenkins itis_2020-07-28

php update_resources/connectors/synonyms_handling.php _ itis_2022-02-28_all_nodes
                         php5.6 synonyms_handling.php jenkins itis_2022-02-28_all_nodes

php update_resources/connectors/synonyms_handling.php _ 368_final
php5.6 synonyms_handling.php jenkins 368_final
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
$timestart = time_elapsed();
// $GLOBALS['ENV_DEBUG'] = true;

// print_r($argv);
$params['jenkins_or_cron']  = @$argv[1];
$params['resource_id']      = @$argv[2];
print_r($params); //exit;

if($resource_id = @$params['resource_id']) {}
else exit("\nERROR: No resource_id.\n");

if(Functions::is_production()) {
    $info[$resource_id] = array('dwca_file' => "https://editors.eol.org/eol_php_code/applications/content_server/resources/".$resource_id.".tar.gz");
    // -> above line is default, should go first
    $info['itis_2019-08-28'] = array('dwca_file' => 'https://editors.eol.org/eol_php_code/applications/content_server/resources/itis_2019-08-28.tar.gz');
    $info['itis_2020-07-28'] = array('dwca_file' => 'https://editors.eol.org/eol_php_code/applications/content_server/resources/itis_2020-07-28.tar.gz');
    $info['itis_2020-12-01'] = array('dwca_file' => 'https://editors.eol.org/eol_php_code/applications/content_server/resources/itis_2020-12-01.tar.gz');
    $info['368_final'] = array('dwca_file' => 'https://editors.eol.org/eol_php_code/applications/content_server/resources/368_removed_aves.tar.gz');
}
else {
    $info[$resource_id] = array('dwca_file' => WEB_ROOT."/applications/content_server/resources_3/".$resource_id.".tar.gz");
    // -> above line is default, should go first
    $info['itis_2019-08-28'] = array('dwca_file' => WEB_ROOT.'/applications/content_server/resources_3/itis_2019-08-28.tar.gz');
    $info['itis_2020-07-28'] = array('dwca_file' => WEB_ROOT.'/applications/content_server/resources_3/itis_2020-07-28.tar.gz');
    $info['itis_2020-12-01'] = array('dwca_file' => WEB_ROOT.'/applications/content_server/resources_3/itis_2020-12-01.tar.gz');
    $info['368_final'] = array('dwca_file' => WEB_ROOT.'/applications/content_server/resources_3/368_removed_aves.tar.gz');
}

// /* customize here:
$info['itis_2019-08-28']['preferred_rowtypes']           = array('http://rs.gbif.org/terms/1.0/vernacularname');
$info['itis_2020-07-28']['preferred_rowtypes']           = array('http://rs.gbif.org/terms/1.0/vernacularname');
$info['itis_2020-12-01']['preferred_rowtypes']           = array('http://rs.gbif.org/terms/1.0/vernacularname');
$info['itis_2022-02-28_all_nodes']['preferred_rowtypes'] = array('http://rs.gbif.org/terms/1.0/vernacularname');
$info['368_final']['preferred_rowtypes'] = array('http://rs.tdwg.org/dwc/terms/occurrence', 'http://rs.gbif.org/terms/1.0/vernacularname');
// */

if(!@$info[$resource_id]) exit("\nERROR: resource_id not initialized.\n");
$dwca_file          = $info[$resource_id]['dwca_file'];
$preferred_rowtypes = $info[$resource_id]['preferred_rowtypes'];
process_resource_url($dwca_file, $resource_id, $preferred_rowtypes);

// /* newly refactored routine
require_library('connectors/DWCADiagnoseAPI');
$func = new DWCADiagnoseAPI();
$func->run_diagnostics($resource_id);
// */

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n\n";
echo "elapsed time = " . $elapsed_time_sec/60 . " minutes \n";
echo "elapsed time = " . $elapsed_time_sec/60/60 . " hours \n";
echo "\nDone processing.\n";

function process_resource_url($dwca_file, $resource_id, $preferred_rowtypes)
{
    require_library('connectors/DwCA_Utility');
    $func = new DwCA_Utility($resource_id, $dwca_file);

    /* Orig in meta.xml has capital letters. Just a note reminder. */

    /* This 1 will be processed in SynonymsHandlingAPI.php which will be called from DwCA_Utility.php
    http://rs.tdwg.org/dwc/terms/Taxon
    Please also check preferred_rowtypes above for each resource.
    */
    $func->convert_archive($preferred_rowtypes);
    Functions::finalize_dwca_resource($resource_id);
    
    /* Customized part ----------------------------------------
    echo "\n---Deleting leftover files---\n";
    if($resource_id == '368_final') {
        $files = array('368.tar.gz', '368_removed_aves.tar.gz');
        foreach($files as $file) {
            $path = CONTENT_RESOURCE_LOCAL_PATH.$file;
            if(file_exists($path)) {
                if(unlink($path)) echo "\nLeftover file deleted [$path]\n";
                else              echo "\nFile cannot be deleted, investigate: [$path]\n";
            }
            else echo "\nFile does not exist anymore: [$path]\n";
        }
    }
    ----------------------------------------------------------- */
    
}
?>