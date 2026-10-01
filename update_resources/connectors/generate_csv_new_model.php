<?php
namespace php_active_record;
/* Main starting point for generating CSV files for loading into Neo4j

start Oct 1, 2026:
php generate_csv_new_model.php _ '{"concept_id": "23067562" , "redownload_zip_file_YN": 1}'         -> Biochemistry and Natural Products
php generate_csv_new_model.php _ '{"task": "generate_Zenodo_TraitBank_datasets_inCSV"}'

php generate_csv_4EOLneo4j.php _ '{"resource_id": "GloBI_TraitBank_1_0",     "eol_resource_id": "R20"}'     -> 20 globi   
php generate_csv_4EOLneo4j.php _ '{"resource_id": "Wikipedia_TraitBank_1_0", "eol_resource_id": "R512"}'    -> 512 wikipedia
-> generates CSV files for import to Neo4j

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
    $func->generate_Zenodo_TraitBank_datasets_inCSV();    
    exit("\nGenerated TraitBank datasets in TSV file.\n");
}

$param['eol_resource_id'] = $param['concept_id'];
$func = new GenerateCSV_NewModel($param);
$func->assemble_data($concept_id);
?>