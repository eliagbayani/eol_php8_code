<?php
namespace php_active_record;
/* Finds the DOI of references that have only a full_reference text (no DOI or other identifier), using CrossRef's REST API
   (the same free-text matching as https://apps.crossref.org/SimpleTextQuery). Library: lib/connectors/FullReferenceSearchAPI.php
start Nov 14, 2026:
php do_full_reference_search.php _ '{"task": "search_DOIs_for_full_references", "file": "/path/reference.tab", "limit": 200}'   //test: the first 200 rows
php do_full_reference_search.php _ '{"task": "search_DOIs_for_full_references", "file": "/path/reference.tab"}'                 //all rows
php do_full_reference_search.php _ '{"task": "search_DOIs_for_full_references", "file": "/path/reference.tab", "start": 1001}'   //from data row 1001 on
    - "file": a TSV whose header has 'identifier' and 'full_reference' (other columns are ignored). Path as the container sees it.
    - Report: Reference_maintenance/<file name>_DOIs_<YYYYmmdd_HHMMSS>.tsv, written row by row:
      identifier, full_reference, status, DOI, review_note, parsed_* (what was read from the citation), crossref_* (the candidate), checks
      plus <same name>_guide.txt: the results and how to review them, for the Data Managers.
      Only the newest 20 reports (with their guides) are kept; older ones are deleted when a search starts.
    - status: matched (journal, volume, year and page agree) / possible (all but the page agree: look at it) / not found / skipped / error (run again)
      DOI is filled only for matched and possible; review_note says what was found, in plain words.
    - Changes nothing else. CrossRef answers are cached (never expire), so a re-run or a resumed run is instant for rows already searched.
    - CROSSREF_MAILTO in config/environments/<env>.php (not in GitHub): our contact email, sent with every call as CrossRef asks ("polite pool").
    - Polite: one call at a time, a 1-second pause after each (about 3 seconds per new reference). On HTTP 429 (too many requests) or
      503 (busy) it waits (CrossRef's Retry-After, else 1, 2, 4, 8 minutes); after 5 in a row it stops cleanly and prints the "start" to resume with.
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
ini_set('memory_limit','2048M');
$timestart = time_elapsed();
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode((string)@$argv[2], true);
if(!is_array($param) || empty($param['task'])) {
    echo "\nERROR: the parameters aren't valid JSON with a \"task\". Got: [" . @$argv[2] . "]";
    echo "\nPut the JSON in single quotes, e.g. php do_full_reference_search.php _ '{\"task\": \"search_DOIs_for_full_references\", \"file\": \"...\"}'\n";
    exit(1);
}

require_library('connectors/ZenodoTraitBankAPI');
require_library('connectors/GenerateTB_Files_Functions');
require_library('connectors/FullReferenceSearchAPI');
$func = new FullReferenceSearchAPI($param);
$func->start();
?>
