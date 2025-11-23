<?php
namespace php_active_record;
/* Main starting point for annotating text
php update_resources/connectors/annotator.php _ '{"text": "the quick brown fox", "ontologies": "envo,growth"}' => Obsolete

php update_resources/connectors/annotator.php _ '{"text": "the quick brown fox", "predicates": "ALL"}'
php update_resources/connectors/annotator.php _ '{"text": "the quick brown fox", "predicates": "habitat, reproduction"}'
php                             annotator.php _ '{"text": "a whole bunch of text", "predicates": "habitat, mating system, xyz"}'


predicates: 7 as of 10Nov2025
    behavioral circadian rhythm
    developmental mode
    habitat
    life cycle habit
    mating system
    reproduction
    sexual system

Related workspaces:
- TraitAnnotator
- Environments_2_EOL_8
- WikipediaInferredTrait
- ReviseKeyWordMap


*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = false; //true; //set to true during development
// */
// $GLOBALS['ENV_DEBUG'] = false;
$timestart = time_elapsed();

/* test
$desc = "& < > ' ,";
echo "\n1. [$desc]\n";
$desc = htmlentities($desc); //caused probs. for: & < > etc. //but fixed text with ' single quote
echo "\n2. [$desc]\n";
$desc = html_entity_decode($desc);
echo "\n3. [$desc]\n";
exit("\n-end test-\n");
*/
if($GLOBALS['ENV_DEBUG']) print_r($argv);
$jenkins_or_cron = @$argv[1]; //not needed here
$params = json_decode(@$argv[2], true); // print_r($param); exit;
if($GLOBALS['ENV_DEBUG']) { echo "\n+++++start"; print_r($params); echo "\n+++++end"; }
echo "\ntext: [".$params['text']."]";
echo "\npredicates: [".$params['predicates']."]\n";
if(!@$params['text'])       { echo "\n+++++start2"; print_r($argv); exit("ERROR: Cannot parse [text] cmdline param"); echo "\n+++++end2";}
if(!@$params['predicates']) { echo "\n+++++start3"; print_r($argv); exit("ERROR: Cannot parse [predicates] cmdline param"); echo "\n+++++end3"; }

require_library('connectors/TraitAnnotatorAPI');
$func = new TraitAnnotatorAPI();
$func->annotate($params);
?>