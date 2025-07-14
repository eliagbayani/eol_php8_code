<?php
namespace php_active_record;
/* 
TraitBank 1.0
https://github.com/EOL/ContentImport/issues/32
Step 1:
Parse DwCA and assemble data from Taxa and MoF
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
$timestart = time_elapsed();
$resource_id = 'parsing_dwca'; //doesn't generate a DwCA

if(Functions::is_production()) $dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/xxx.tar.gz';
else                           $dwca_file = WEB_ROOT . '/applications/content_server/resources_3/globi_assoc_2025_05_17.tar.gz';

process_resource_url($dwca_file, $resource_id, $timestart);

function process_resource_url($dwca_file, $resource_id, $timestart)
{
    require_library('connectors/DwCA_Utility');
    $params['resource'] = "neo4j_prep";
    $func = new DwCA_Utility($resource_id, $dwca_file, $params);
    $preferred_rowtypes = array('');
    $excluded_rowtypes = array('http://rs.tdwg.org/dwc/terms/taxon', 'http://rs.tdwg.org/dwc/terms/occurrence', 'http://rs.tdwg.org/dwc/terms/measurementorfact');
    /* All 3 files will be processed in DwCA_Rem_Taxa_Adjust_MoF_API.php which will be called from DwCA_Utility.php */
    $func->convert_archive($preferred_rowtypes, $excluded_rowtypes);
    Functions::finalize_dwca_resource($resource_id, false, true, $timestart);
}
?>