<?php
namespace php_active_record;
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //true; //set to true during development
// */
// $GLOBALS['ENV_DEBUG'] = false;
$timestart = time_elapsed();

require_library('connectors/TraitAnnotatorAPI');
$func = new TraitAnnotatorAPI();
$func->validate_term_uris();
?>