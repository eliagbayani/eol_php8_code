<?php
namespace php_active_record;
/* Main starting point for annotating text
php update_resources/connectors/annotator.php _ '{"text": "the quick brown fox", "ontologies": "envo,growth"}'
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
$timestart = time_elapsed();

/* test
$leftmost = "é";
if(ctype_digit($leftmost)  || \IntlChar::isalpha($leftmost)  || ctype_alpha($leftmost) ) echo "\nis alpha or number\n";
else echo "\nnot alpha nor a number\n";
exit("\n-end test-\n");
*/

print_r($argv);
$jenkins_or_cron = @$argv[1]; //not needed here
$params                     = json_decode(@$argv[2], true); // print_r($param); exit;
$text = $params['text'];
print_r($params);

require_library('connectors/TraitAnnotatorAPI');
$func = new TraitAnnotatorAPI();
$func->annotate($params);
// $func->annotate($params);
// $func->annotate($params);

?>