<?php
namespace php_active_record;
/* This will update the local Textmining Strings TSV file.
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


echo "\nUpdating local Textmining Strings...";
require_library('connectors/TextmineKeywordMapAnnotator');
$func = new TextmineKeywordMapAnnotator();
$func->refresh_local_textmining_strings();
?>