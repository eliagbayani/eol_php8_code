<?php 
namespace php_active_record;
/* Maintenance of the TB Terms file (Zenodo concept 22776577)
start Nov 10, 2026:
The master of the Terms is a Google Sheet, edited by the data managers:
    https://docs.google.com/spreadsheets/d/1D5L2ySCI23sWR_LBGc7ClOl7J5_oUtnBY7bW-9wObC4

php do_terms_mtce.php _ '{"task": "update_terms_from_googlesheet", "dry_run_YN": 1}'   //download + build + QC only: never uploads
php do_terms_mtce.php _ '{"task": "update_terms_from_googlesheet"}'                    //... + upload a Zenodo DRAFT if QC passes, for review on Zenodo
php do_terms_mtce.php _ '{"task": "update_terms_from_googlesheet", "publish": 1}'      //... + publish on Zenodo -- CANNOT be undone
    All files go in the folder CONTENT_RESOURCE_LOCAL_PATH/Terms_maintenance/ (created if it doesn't exist).
    Only the newest 5 sheet downloads (terms_googlesheet_*.tsv) and the newest 10 error reports (Terms_file_errors_*.tsv) are kept; older ones are deleted.
    - Step 1: downloads the Google Sheet as TSV -> CONTENT_RESOURCE_LOCAL_PATH/Terms_maintenance/terms_googlesheet_<date>.tsv
    - Step 2: builds CONTENT_RESOURCE_LOCAL_PATH/Terms_maintenance/terms.tsv: the 12 published columns (the sheet's working columns delete?, new term for dataset
      and notes are left out), all rows, in the published format (CRLF). An unchanged sheet gives the same file, and nothing is uploaded.
    - Step 3: quality control (QC):
        1. [uri] is unique
        2. [name] + [type] is unique. A duplicate already in Zenodo's latest file (same rows) is only a WARNING; a new one is an ERROR.
        3/4. the primary fields [name], [type], [uri] are not blank
        5. [parent uris]: several values only separated by | (no , or ; or empty values)
        6. [uri], [parent uris], [synonym of uri], [units term], [inverse of] are valid URIs (http:// or https://, no spaces or hidden characters)
        7. boolean fields, e.g. [symmetrical assoc]: TRUE, FALSE or blank
        8. [type] is one of: association, measurement, value, metadata
        9. [parent uris]: each parent is a [uri] in the Terms file, of the same [type] as the row (e.g. an association's parents are associations)
        also: all 12 columns present, every row has as many fields as the header, no leading/trailing spaces in the checked fields
    - Step 4: any ERROR -> report CONTENT_RESOURCE_LOCAL_PATH/Terms_maintenance/Terms_file_errors_YYYY-MM-DD-HH-MM.tsv
      (severity, check, sheet_row, uri, name, type, field, value, message; sheet_row as in the Google Sheet, header = row 1),
      to be emailed to the data manager. Nothing is uploaded.
    - Step 5: QC passed -> terms.tsv is uploaded to Zenodo as terms_1.0.tsv, a new version (draft unless "publish": 1).
      Then refresh our local copy: php generate_csv_new_model.php _ '{"concept_id": "22776577", "redownload_zip_file_YN": 1, "task": "download_only", "what": "Main:Terms file"}'
    - Web page (check + [Download] the report + [Upload to Zenodo], which publishes; the upload needs TERMS_MTCE_PASSWORD from config/environments/<env>.php):
        http://localhost:81/eol_php8_code/applications/terms_maintenance/main.php
    - Options: "version": "v3" (version label on Zenodo; default: the previous one is kept)
               "file": "<path>" (QC a local TSV instead of downloading the sheet, e.g. a re-check)
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
ini_set('memory_limit','8096M'); //required for GloBI
$timestart = time_elapsed();
// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode((string)@$argv[2], true); //print_r($param); exit;
if(!is_array($param) || empty($param['task'])) {
    echo "\nERROR: the parameters aren't valid JSON with a \"task\". Got: [" . @$argv[2] . "]";
    echo "\nPut the JSON in single quotes, e.g. php do_terms_mtce.php _ '{\"task\": \"update_terms_from_googlesheet\", \"dry_run_YN\": 1}'";
    echo "\nTo use a shell variable, close the quotes around it: '{\"task\": \"...\", \"file\": \"'\"\$file\"'\"}'\n";
    exit(1);
}
require_library('connectors/ZenodoTraitBankAPI');
require_library('connectors/GenerateTB_Files_Functions');
require_library('connectors/TermsMaintenanceAPI');
$func = new TermsMaintenanceAPI($param);
$func->start(); //main operation
?>