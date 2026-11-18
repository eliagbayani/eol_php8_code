<?php
namespace php_active_record;
/* Legacy DwCA process: maintenance of the legacy DwCA resources on Zenodo.
Library: lib/connectors/LegacyDwCAMaintenanceAPI.php -- downloads go to [CONTENT_RESOURCE_LOCAL_PATH]/Legacy_DwCA_files/
start Nov 18, 2026:
php process_legacy_dwca.php _ '{"task": "download_file_from_zenodo", "concept_id": "13318109", "supplied_name": "AmphibiaWeb"}'
php process_legacy_dwca.php _ '{"task": "download_file_from_zenodo", "concept_id": "13318109", "supplied_name": "AmphibiaWeb", "redownload_zip_file_YN": 1}'  //ask Zenodo for its latest version now
    - Downloads the files of Zenodo's latest version of the dataset, e.g. AmphibiaWeb ->
      Legacy_DwCA_files/13318109_AmphibiaWeb/input_files/Archive.zip (not extracted).
    - "supplied_name" names the folder: [concept_id]_[supplied_name] (letters, digits, _ and - only).
      Without it, the folder is [concept_id]_[abbreviated Zenodo title], e.g. 13318109_AmphibiaWeb_Amph_text_w_trai_base_on_Pens_Anno
      If Zenodo gives a zip archive of the record instead, it is saved as Legacy_DwCA_files/[concept_id].zip.
    - Downloads only if Zenodo's latest version differs from ours (Legacy_DwCA_files/[concept_id].record_id), or files are missing.
    - Zenodo's record info is cached 1 day; "redownload_zip_file_YN": 1 gets it fresh, so a new version is never missed.
php process_legacy_dwca.php _ '{"task": "download_file_from_zenodo", "concept_id": "13318109", "supplied_name": "AmphibiaWeb", "file_type": ".tar.gz"}'
    - Same, then saves the final version in CONTENT_RESOURCE_LOCAL_PATH as [concept_id]_[supplied_name][file_type], e.g. 13318109_AmphibiaWeb.tar.gz
    - "file_type": ".tar.gz" or ".zip". Zenodo's file is converted if it is the other type (e.g. Archive.zip -> .tar.gz; files at the top of the
      archive, Mac __MACOSX/ and ._ files left out), or copied as is if it is already that type.
    - Not redone if the final version is already newer than the downloaded file.
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
ini_set('memory_limit','8096M');
$timestart = time_elapsed();
// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode((string)@$argv[2], true); //print_r($param); exit;
if(!is_array($param) || empty($param['task'])) {
    echo "\nERROR: the parameters aren't valid JSON with a \"task\". Got: [" . @$argv[2] . "]";
    echo "\nPut the JSON in single quotes, e.g. php process_legacy_dwca.php _ '{\"task\": \"...\"}'";
    echo "\nTo use a shell variable, close the quotes around it: '{\"task\": \"...\", \"file\": \"'\"\$file\"'\"}'\n";
    exit(1);
}

require_library('connectors/ZenodoTraitBankAPI');
require_library('connectors/LegacyDwCAMaintenanceAPI');
$func = new LegacyDwCAMaintenanceAPI($param);
$func->start();
?>