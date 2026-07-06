<?php
namespace php_active_record;
/* This will update the local Textmining Strings TSV file.

php update_local_textmining_strings.php _ '{"google_sheet": "mapped_strings"}'
php update_local_textmining_strings.php _ '{"google_sheet": "AncestryIndex_new"}'
php update_local_textmining_strings.php _ '{"google_sheet": "AncestryIndex_compatibleAncestors"}'


Related workspaces:
- TraitAnnotator
- Environments_2_EOL_8
- WikipediaInferredTrait
- ReviseKeyWordMap
- UpdateLocalTextminingStrings
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
// $GLOBALS['ENV_DEBUG'] = false;
$timestart = time_elapsed();

// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode(@$argv[2], true);
$google_sheet = $param['google_sheet']; //e.g. 'mapped_strings'


// /* worksheet: [mapped strings] : https://docs.google.com/spreadsheets/d/1sK-rGa1l1jQ7-ui5BXI3-44NVHS00E-ErsGyaGVficA/edit?gid=0#gid=0
$p['google_sheet'] = $google_sheet;
$p['spreadsheetID'] = '1sK-rGa1l1jQ7-ui5BXI3-44NVHS00E-ErsGyaGVficA';
$p['expire_seconds'] = 60*60*24*1; //1 day cache is ideal OK
$p['range'] = 'mapped strings!A1:E1400'; //where "A" is the starting column, "E" is the ending column, and "1" is the starting row.
$p['fields'] = array('string', 'value', 'value uri', 'predicate', 'predicate uri');
$params['mapped_strings'] = $p;
// */

// /* worksheet: [new] : https://docs.google.com/spreadsheets/d/1hImI6u9XXScSxKt7T6hYKoq1tAxj43znrusJA8XMNQc/edit?gid=1648385244#gid=1648385244
$p['google_sheet'] = $google_sheet;
$p['spreadsheetID'] = '1hImI6u9XXScSxKt7T6hYKoq1tAxj43znrusJA8XMNQc';
$p['expire_seconds'] = 60*60*24*1; //1 day cache is ideal OK
$p['range'] = 'new!A1:B5200'; //where "A" is the starting column, "B" is the ending column, and "1" is the starting row.
$p['fields'] = array('Index', 'higherClassification');
$params['AncestryIndex_new'] = $p;
// */

// /* worksheet: [new] : https://docs.google.com/spreadsheets/d/1hImI6u9XXScSxKt7T6hYKoq1tAxj43znrusJA8XMNQc/edit?gid=1648385244#gid=1648385244
$p['google_sheet'] = $google_sheet;
$p['spreadsheetID'] = '1hImI6u9XXScSxKt7T6hYKoq1tAxj43znrusJA8XMNQc';
$p['expire_seconds'] = 60*60*24*1; //1 day cache is ideal OK
$p['range'] = 'new!A1:B55'; //where "A" is the starting column, "B" is the ending column, and "1" is the starting row.
$p['fields'] = array('Index_1', 'Index_2');
$params['AncestryIndex_compatibleAncestors'] = $p;
// */

require_library('connectors/TextmineKeywordMapAnnotator');
$func = new TextmineKeywordMapAnnotator($google_sheet);
$func->refresh_local_file_using_GoogleSheet($params[$google_sheet]);
?>