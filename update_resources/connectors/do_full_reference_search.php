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
      identifier, full_reference, searched_text (what was sent to CrossRef: without quotes a spreadsheet put around it, a FishBase "(Ref. N)" at the end or a stray "0" after the authors),
      text_changes (what that pre-processing changed in the row, in plain words; empty = nothing; the guide counts each kind), status, DOI, DOI_URL (the DOI as a https://doi.org/ link), DOI_source (CrossRef, DataCite or citation), review_note, parsed_* (what was read from the citation), crossref_* (the candidate), checks
      plus <same name>_guide.txt: the results and how to review them, for the Data Managers.
      Only the newest 20 reports (with their guides) are kept; older ones are deleted when a search starts.
    - status: matched (journal, volume, year and page agree) / possible (all but the page agree: look at it) / not found / skipped / error (run again)
      DOI is filled only for matched and possible; review_note says what was found, in plain words.
    - Citation styles read: TraitBank's, FishBase's, "Author AB (Year) Title. Journal Vol: pages", APA, Harvard, Chicago, MLA (Vancouver: no volume or pages),
      "A. B. Author;C. Author. Year. Title. pp. 43-50. Journal. vol. 44" and "... Journal. vol. 46. pp. 233 - 240" (Nov 19, 2026).
    - Nov 19, 2026 (from the Data Managers' DOI list): CrossRef's journal is also found at the end of a title left in front of it; a CrossRef record
      without a volume (e.g. JSTOR's "Mammalian Species") can be 'possible' when the first author agrees; and a citation with a typo in its
      journal/volume/issue/page (or the journal's old name) can be 'possible' when the year, first author and 90% of the title agree.
      A DOI written in the citation (e.g. "doi:10.1594/PANGAEA.692046") is checked in CrossRef instead of searching.
    - Author and title checks (on by default): the first author's surname and CrossRef's title words also count; they break ties, and a
      'matched' row whose authors clearly differ becomes 'possible'. A citation with no volume or pages (a book, or Vancouver style) can be
      'possible' when the first author and 90% of CrossRef's title agree. To turn them off for a run: add "author_title_checks": false, e.g.
      php do_full_reference_search.php _ '{"task": "search_DOIs_for_full_references", "file": "/path/reference.tab", "author_title_checks": false}'
      (to turn them off for good, or remove them: see AUTHOR_TITLE_CHECKS in FullReferenceSearchAPI.php)
    - DataCite, a second search (on by default, with the author and title checks): rows CrossRef can't confirm and with no volume or pages
      (datasets, reports, theses, books) are also searched in DataCite, e.g. PANGAEA and GBIF datasets; at most 'possible'.
      To turn it off for a run: add "datacite_search": false (for good, or to remove it: see DATACITE_SEARCH in FullReferenceSearchAPI.php)
    - Tests: the known cases (problems fixed before, CrossRef's own sample citations, citation styles) must still give their expected results:
      php do_full_reference_search.php _ '{"task": "run_tests"}'
      The cases are in lib/connectors/FullReferenceSearchAPI_tests.json: add one whenever a problem is fixed. Also [Run Tests] on the web page.
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
