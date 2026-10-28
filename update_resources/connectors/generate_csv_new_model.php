<?php
namespace php_active_record;
/* Main starting point for generating CSV files for loading into Neo4j
======================================================================
start Oct 1, 2026:
php generate_csv_new_model.php _ '{"concept_id": "23067562" , "redownload_zip_file_YN": 1}'         -> Biochemistry and Natural Products
php generate_csv_new_model.php _ '{"task": "generate_Zenodo_TraitBank_datasets_inCSV"}'

php generate_csv_new_model.php _ '{"task": "refresh_all_TraitBank_datasets"}'
    - Refreshes our local copies of all TraitBank community datasets on Zenodo (except Terms, References, DH). A dataset is downloaded
      only if our copy is older than Zenodo's latest version, or missing. Prints a summary: up to date / refreshed / failed.
    Options: "include_Terms_YN": 1, "include_References_YN": 1 (our master references.tsv is kept), "dry_run_YN": 1 (report only, no downloads)
php generate_csv_new_model.php _ '{"task": "refresh_all_TraitBank_datasets", "include_Terms_YN": 1, "include_References_YN": 1}'
php generate_csv_new_model.php _ '{"task": "refresh_all_TraitBank_datasets", "dry_run_YN": 1}'
php generate_csv_new_model.php _ '{"task": "refresh_all_TraitBank_datasets", "only": "Terms"}'
php generate_csv_new_model.php _ '{"task": "refresh_all_TraitBank_datasets", "only": "References"}'
php generate_csv_new_model.php _ '{"task": "refresh_all_TraitBank_datasets", "only": "Terms,References"}'
    - "only": refresh only the named datasets: Terms, References, DH, or concept_ids e.g. "23066982"; several separated by commas.
      Same check as above: downloaded only if Zenodo has a newer version (always checked fresh, no 1-day cache).

php generate_csv_new_model.php _ '{"concept_id": "22776577", "redownload_zip_file_YN": 0, "task": "download_only", "what": "Main:Terms file"}'
php generate_csv_new_model.php _ '{"concept_id": "22776838", "redownload_zip_file_YN": 0, "task": "download_only", "what": "Main:References"}'
======================================================================
php generate_csv_4EOLneo4j.php _ '{"resource_id": "GloBI_TraitBank_1_0",     "eol_resource_id": "R20"}'     -> 20 globi   
php generate_csv_4EOLneo4j.php _ '{"resource_id": "Wikipedia_TraitBank_1_0", "eol_resource_id": "R512"}'    -> 512 wikipedia
-> generates CSV files for import to Neo4j
======================================================================
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
ini_set('memory_limit','8096M'); //required for GloBI
$timestart = time_elapsed();

/* hash in PHP
$str = 'This is the string to be hashed.';
echo "\nmd5: [".md5($str)."]\n";
$algos = hash_algos();
foreach($algos as $algo) {
    echo "\n$algo: [".hash($algo, $str). "]";
} exit;
*/

// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode(@$argv[2], true); //print_r($param); exit;
$concept_id = @$param['concept_id'];

require_library('connectors/ZenodoTraitBankAPI');
require_library('connectors/GenerateCSV_NewModel');

if(@$param['task'] == 'generate_Zenodo_TraitBank_datasets_inCSV') {
    $func = new ZenodoTraitBankAPI();
    $func->generate_Zenodo_TraitBank_datasets_inCSV(); //single report: https://github.com/eliagbayani/EOL-connector-data-files/blob/master/EOL/TraitBank_datasets.csv
    exit("\nGenerated TraitBank datasets in TSV file.\n");
}
if(@$param['task'] == 'refresh_all_TraitBank_datasets') {
    $func = new ZenodoTraitBankAPI();
    $func->param = $param;
    $func->refresh_all_TraitBank_datasets();
    exit("\nDone refreshing TraitBank datasets.\n");
}

$param['eol_resource_id'] = $param['concept_id'];
$func = new GenerateCSV_NewModel($param);
$func->assemble_data($concept_id);
?>