<?php
namespace php_active_record;
/* This connector is a template for those partners who host movie (.mov) files.
Script should be able to:
- download .mov file
- convert to .mp4
- use editors.eol.org path in accessURI in DwCA

First client of this template is: 170_final.php

*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
// $GLOBALS['ENV_DEBUG'] = true;
// ini_set('memory_limit','8096M');
$timestart = time_elapsed();

/* Start 1st part -- download the .mov and convert to .mp4 locally
$resource_id = '170';
require_library('connectors/MovieFilesAPI');
$dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/170.tar.gz';
$func = new MovieFilesAPI(false, $resource_id, $dwca_file);
$func->download_mov_convert_2_mp4();
unset($func);
*/

// /* Start 2nd part -- create the DwCA 170_final.tar.gz
$resource_id = '170_final';
$dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/170.tar.gz';
process_resource_url($dwca_file, $resource_id);
// */

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n\n";
echo "elapsed time = " . $elapsed_time_sec/60 . " minutes \n";
echo "elapsed time = " . $elapsed_time_sec/60/60 . " hours \n";
echo "\nDone processing.\n";

function process_resource_url($dwca_file, $resource_id)
{
    require_library('connectors/DwCA_Utility');
    $func = new DwCA_Utility($resource_id, $dwca_file);

    /* Orig in meta.xml has capital letters. Just a note reminder. */

    $preferred_rowtypes = array('http://rs.tdwg.org/dwc/terms/taxon');
    /* This 1 will be processed in MovieFilesAPI.php which will be called from DwCA_Utility.php
    http://eol.org/schema/media/Document
    */
    $func->convert_archive($preferred_rowtypes);
    Functions::finalize_dwca_resource($resource_id, false, true);
}
?>