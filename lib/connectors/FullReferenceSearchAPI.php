<?php
namespace php_active_record;
/* Finds the DOI of a reference from its full_reference text alone (no DOI or other identifier), using CrossRef.
   CrossRef's web form https://apps.crossref.org/SimpleTextQuery does this for pasted references, but has no API;
   its REST API does the same free-text matching: https://api.crossref.org/works?query.bibliographic=<full reference>
   Driver: update_resources/connectors/do_full_reference_search.php
   start Nov 14, 2026

   CrossRef always returns candidates, even wrong ones, so the score alone isn't trusted: each candidate is checked against
   the year, journal, volume, issue and page read from the full_reference, e.g. "1998. Brittonia,50(1): 59,1998." -> 10.2307/2807718.
   Status of each row:
     matched   : journal, volume and year (or the year next to it) agree, and the page cited is in the article's page range
     possible  : journal, volume and year agree, but the page can't be confirmed (e.g. CrossRef has only the first page) -> look at it
     not found : no candidate agrees; the closest candidate is still described, for review (its DOI is NOT put in the DOI column)
     skipped   : empty, or too short to search
     error     : CrossRef could not be reached; a re-run tries it again
   Every row gets a review_note in plain words, for the Data Managers' final check.
   Changes nothing else: it only writes a report TSV and a guide (_guide.txt) next to it.
----------------------------------------------------
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class FullReferenceSearchAPI extends GenerateTB_Files_Functions
{
    const OUTPUT_COLUMNS = array('identifier', 'full_reference', 'searched_text', 'text_changes', 'status', 'DOI', 'DOI_URL', 'DOI_source', 'review_note',
                                 'parsed_author', 'parsed_year', 'parsed_journal', 'parsed_volume', 'parsed_issue', 'parsed_page',
                                 'crossref_DOI', 'crossref_score', 'crossref_type', 'crossref_year', 'crossref_journal',
                                 'crossref_volume', 'crossref_issue', 'crossref_page', 'crossref_title', 'crossref_authors', 'checks');
    const MIN_LENGTH = 10; // shorter full_reference values aren't searched
    const PROGRESS_EVERY = 20; // a progress line ("N rows done ...") every this many rows; the web page reads it
    const PAUSE_SECONDS = 1;    // the pause after every CrossRef call (calls are one at a time); cached answers need no call and no pause
    const THROTTLE_LIMIT = 5;   // after this many "429 too many requests" / "503 busy" answers in a row (with waits of 1, 2, 4, 8 minutes), the search stops
    const KEEP_REPORTS = 20;  // only the newest 20 reports (each with its _guide.txt) are kept in Reference_maintenance/; older ones are deleted
    const REPORT_RE = '/^[A-Za-z0-9._-]+_DOIs_(\d{8}_\d{6})\.tsv$/'; // e.g. reference_DOIs_20261114_013451.tsv; the time it was made sorts them
    // CrossRef record types that are a whole issue/volume/journal or a part of an article, not an article: never the answer
    /* The author and title checks (added Nov 17, 2026), all in one place so they can be turned off, or removed, if the Data Managers say so:
         - turn off for one run: "author_title_checks": false in the parameters
         - turn off for good: set this constant to false
         - remove: delete this constant, author_title_check(), crossref_authors(), the block marked AUTHOR/TITLE in find_DOI(), and the
           AUTHOR/TITLE lines in review_note(), agreed_text() and write_guide(); the parsed_author and crossref_authors columns go too.
       What they do: the first author's surname read from the citation is compared with CrossRef's authors, and CrossRef's title words with the citation.
         - they break ties between candidates whose numbers agree equally
         - a 'matched' candidate whose authors clearly differ becomes 'possible', with a note
         - only when BOTH disagree (the authors differ and under 30% of CrossRef's title is in the citation) is a candidate 'not found':
           one alone is never enough, as CrossRef's author and title data for old works is often incomplete or garbled */
    const AUTHOR_TITLE_CHECKS = true;
    /* DATACITE: the second search, in DataCite (https://api.datacite.org), for rows CrossRef can't confirm and where no volume or pages could be read:
       datasets (PANGAEA, GBIF, Zenodo), reports, theses, books. DataCite registers DOIs that CrossRef doesn't have, e.g. 10.1594/PANGAEA.692046.
       Added Nov 18, 2026; all in one place so it can be turned off or removed:
         - turn off for one run: "datacite_search": false in the parameters; for good: set this constant to false
         - remove: delete this constant, datacite_route(), search_datacite(), datacite_work(), the DATACITE lines in find_DOI(), doi_given(),
           __construct(), run_tests() and write_guide()
       It needs the author and title checks (they confirm a DataCite record), so it's off when they are off. A DataCite DOI is at most 'possible'. */
    const DATACITE_SEARCH = true;
    const MAX_AUTHOR_LOOKUPS = 3; // per row: CrossRef's search answer has no authors, so each candidate that passes the checks costs one call (cached)
    const NOT_AN_ARTICLE = array('journal-issue', 'journal-volume', 'journal', 'component', 'proceedings', 'book-series', 'book-set', 'report-series');
    function __construct($param) {
        $this->param = $param; print_r($param);
        $this->author_title_checks = array_key_exists('author_title_checks', (array) $param) ? (bool) $param['author_title_checks'] : self::AUTHOR_TITLE_CHECKS;
        $this->datacite_search = $this->author_title_checks && (array_key_exists('datacite_search', (array) $param) ? (bool) $param['datacite_search'] : self::DATACITE_SEARCH); // DATACITE
        $this->work_dir = CONTENT_RESOURCE_LOCAL_PATH . "Reference_maintenance/";
        if(!is_dir($this->work_dir)) @mkdir($this->work_dir, 0777, true);
        // CrossRef's "polite pool" (twice the rate limit) needs an email: optional constant CROSSREF_MAILTO in config/environments/<env>.php (not in GitHub).
        // It goes in the User-Agent, not the URL, so the cache doesn't depend on it.
        $user_agent = 'EOL-connector/1.0' . (defined('CROSSREF_MAILTO') && CROSSREF_MAILTO ? ' (mailto:' . CROSSREF_MAILTO . ')' : '');
        $this->crossref_works_url = "https://api.crossref.org/works"; // tests only may point it elsewhere
        // the cache (read with lookup_with_cache, written by call_crossref); the calls themselves are made by call_crossref(), which sees HTTP 429/503
        $this->crossref_options = array('resource_id' => 'crossref', 'expire_seconds' => false, // never expires: the same full_reference is never asked twice
                                        'timeout' => 60,
                                        'user_agent' => $user_agent,
                                        'validation_regex' => '"status":"ok"', // a failed call isn't kept as a valid cache
                                        'name' => 'CrossRef');
        // DATACITE: DataCite's answers, cached the same way in their own folder; DataCite also asks for a User-Agent with a mailto
        $this->datacite_url = "https://api.datacite.org/dois";
        $this->datacite_options = array_merge($this->crossref_options, array('resource_id' => 'datacite', 'validation_regex' => '"data":', 'name' => 'DataCite'));
    }
    function start()
    {   // Runs the task; also returns its result.
        $task = $this->param['task'] ?? '';
        if($task == 'run_tests') return $this->run_tests(); // no lock: it writes no report; the page doesn't start it while a search runs
        // one run at a time: a second run waits here until the first one is done. Released when this function returns.
        $lock = @fopen($this->work_dir . ".full_reference_search.lock", 'c');
        if($lock) { @chmod($this->work_dir . ".full_reference_search.lock", 0666); flock($lock, LOCK_EX); }
        $result = null;
        if($task == 'search_DOIs_for_full_references') $result = $this->search_DOIs_for_full_references();
        else echo "\nERROR: Unknown task [$task]. Available: search_DOIs_for_full_references, run_tests\n";
        if($lock) { flock($lock, LOCK_UN); fclose($lock); }
        return $result;
    }
    private function search_DOIs_for_full_references()
    {   /* $this->param:
            'file'  => required, TSV whose header has 'identifier' and 'full_reference', e.g. reference.tab
            'limit' => optional, search only this many rows (e.g. 200 for a test)
            'start' => optional, begin at this data row (1 = the first row after the header)
        Writes <file name>_DOIs_<YYYYmmdd_HHMMSS>.tsv in Reference_maintenance/, row by row (a stopped run keeps what it did),
        and <same>_guide.txt explaining it for the Data Managers. Returns array('file' => output path, 'counts' => status => n), or false. */
        $file = (string) ($this->param['file'] ?? '');
        if(!is_file($file)) { echo "\nERROR: No file [$file]. Give one, e.g. \"file\": \"" . CONTENT_RESOURCE_LOCAL_PATH . "reference.tab\"\n"; return false; }
        $limit = (int) ($this->param['limit'] ?? 0);
        $start = max(1, (int) ($this->param['start'] ?? 1));
        if(!($in = fopen($file, 'r'))) { echo "\nERROR: Cannot read [$file]\n"; return false; }
        $header = explode("\t", rtrim(preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets($in)), "\r\n"));
        $col_id = array_search('identifier', $header); $col_ref = array_search('full_reference', $header);
        if($col_id === false || $col_ref === false) { fclose($in); echo "\nERROR: The header must have 'identifier' and 'full_reference'. Got: [" . implode(', ', $header) . "]\n"; return false; }

        $out_file = $this->work_dir . pathinfo($file, PATHINFO_FILENAME) . "_DOIs_" . date("Ymd_His") . ".tsv";
        $out = fopen($out_file, 'w');
        fwrite($out, implode("\t", self::OUTPUT_COLUMNS) . "\n");
        echo "\nInput: [$file]\nOutput: [$out_file]\nRows: from " . $start . ($limit ? ", at most $limit" : ', to the end') . "\n";
        $this->keep_latest_reports(); // here, not at the end, so stopped searches are counted too; the new report is one of the 20
        $counts = array('matched' => 0, 'possible' => 0, 'not found' => 0, 'skipped' => 0, 'error' => 0);
        $changed = array_fill_keys(array_keys(self::TEXT_CHANGES), 0); // rows changed by each pre-processing rule, for the guide
        $time = time(); $row_no = 0; $done = 0;
        while(($line = fgets($in)) !== false) {
            $line = rtrim($line, "\r\n");
            if(trim($line) === '') continue;
            $row_no++;
            if($row_no < $start) continue;
            if($limit && $done >= $limit) break;
            $f = explode("\t", $line);
            $identifier = (string) ($f[$col_id] ?? ''); $full_reference = (string) ($f[$col_ref] ?? ''); // as in the input file; find_DOI() cleans it
            $r = $this->find_DOI($full_reference);
            if($r['status'] == 'stop') { $stopped_at = $row_no; break; }
            $counts[$r['status']]++; $done++;
            foreach($r['change_codes'] as $code) $changed[$code]++;
            $row = array_merge(array('identifier' => $identifier, 'full_reference' => $full_reference), $r);
            fwrite($out, implode("\t", array_map(fn($c) => str_replace(array("\t", "\r", "\n"), ' ', (string) ($row[$c] ?? '')), self::OUTPUT_COLUMNS)) . "\n");
            if($done % self::PROGRESS_EVERY == 0) { fflush($out); echo "  $done rows done (row $row_no): " . self::counts_str($counts) . "\n"; }
        }
        fclose($in); fclose($out);
        if(isset($stopped_at)) { // CrossRef kept answering 429/503: stop politely; the report keeps the rows done so far
            $guide = $this->write_guide($out_file, $file, $start, $stopped_at - 1, $done, $counts, $changed);
            echo "\nERROR: CrossRef kept asking us to slow down (HTTP 429/503, " . self::THROTTLE_LIMIT . " times in a row), so the search stopped at row $stopped_at"
               . " after $done rows (" . self::counts_str($counts) . ").\nReport (the rows done so far): [$out_file]\nGuide for the Data Managers: [$guide]\n"
               . "To go on later, run again with \"start\": $stopped_at (and the same file).\n";
            return array('file' => $out_file, 'guide' => $guide, 'counts' => $counts, 'stopped_at' => $stopped_at);
        }
        $guide = $this->write_guide($out_file, $file, $start, $row_no, $done, $counts, $changed);
        echo "\nDone: $done rows in " . gmdate("H:i:s", time() - $time) . " -- " . self::counts_str($counts) . "\nReport: [$out_file]\nGuide for the Data Managers: [$guide]\n";
        return array('file' => $out_file, 'guide' => $guide, 'counts' => $counts);
    }
    function run_tests()
    {   /* The known cases in FullReferenceSearchAPI_tests.json (problems fixed before, CrossRef's own sample citations): each must still give the
           expected result, so a change that breaks a known fact shows at once. Writes no report; saves the results in
           Reference_maintenance/full_reference_search_tests_last.json (the web page shows them) and returns them. Uses the same cache as a search. */
        $file = dirname(__FILE__) . "/FullReferenceSearchAPI_tests.json";
        $d = @json_decode((string) @file_get_contents($file), true);
        if(!is_array($d) || empty($d['cases'])) { echo "\nERROR: No test cases in [$file]\n"; return false; }
        $res = array('when' => date("Y-m-d H:i:s"), 'author_title_checks' => $this->author_title_checks, 'datacite_search' => $this->datacite_search, 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'cases' => array());
        foreach($d['cases'] as $t) {
            $e = $t['expect']; $r = array('id' => $t['id'], 'source' => $t['source'], 'full_reference' => $t['full_reference'], 'why' => $t['why'], 'problems' => array());
            $off = array('author_title_checks' => !$this->author_title_checks ? 'author and title checks are off' : '',
                         'datacite_search' => !$this->datacite_search ? 'the DataCite search is off' : ''); // DATACITE
            if(($why_off = $off[$e['requires'] ?? ''] ?? '') !== '') { $r['result'] = 'skipped'; $r['got'] = $why_off; $res['skipped']++; $res['cases'][] = $r; continue; }
            $got = $this->find_DOI($t['full_reference']);
            if($got['status'] == 'stop') { echo "\nCrossRef keeps asking us to slow down: the tests stopped. Try again later.\n"; $res['stopped'] = true; break; }
            foreach($e as $k => $want) {
                if($k == 'requires') continue;
                if(str_ends_with($k, '_contains')) {
                    $col = substr($k, 0, -9); $col = $col == 'note' ? 'review_note' : $col;
                    if(!str_contains((string) ($got[$col] ?? ''), $want)) $r['problems'][] = "$col should contain \"$want\"";
                }
                elseif(strcasecmp((string) ($got[$k] ?? ''), (string) $want) !== 0) $r['problems'][] = "$k: expected \"$want\", got \"" . ($got[$k] ?? '') . "\"";
            }
            $r['result'] = $r['problems'] ? 'FAIL' : 'PASS'; $res[$r['problems'] ? 'failed' : 'passed']++;
            $r['got'] = $got['status'] . ($got['DOI'] !== '' ? " " . $got['DOI'] : '');
            $r['expected'] = $e['status'] . (!empty($e['DOI']) ? " " . $e['DOI'] : '');
            $r['review_note'] = $got['review_note'];
            $res['cases'][] = $r;
            echo str_pad($r['result'], 5) . " " . $t['id'] . ($r['problems'] ? "\n      " . implode("\n      ", $r['problems']) : '') . "\n";
        }
        file_put_contents($this->work_dir . "full_reference_search_tests_last.json", json_encode($res));
        echo "\nTests: {$res['passed']} passed, {$res['failed']} failed, {$res['skipped']} skipped (" . count($d['cases']) . " cases).\n";
        return $res;
    }
    private function keep_latest_reports()
    {   // deletes all but the newest KEEP_REPORTS reports (by the time in their name), each with its _guide.txt
        $reports = array();
        foreach(scandir($this->work_dir) ?: array() as $f) if(preg_match(self::REPORT_RE, $f, $m) && is_file($this->work_dir . $f)) $reports[$f] = $m[1];
        asort($reports, SORT_STRING); // oldest first
        $old = array_slice(array_keys($reports), 0, max(0, count($reports) - self::KEEP_REPORTS));
        $n = 0;
        foreach($old as $f) {
            if(unlink($this->work_dir . $f)) $n++;
            $guide = preg_replace('/\.tsv$/', '_guide.txt', $f);
            if(is_file($this->work_dir . $guide)) unlink($this->work_dir . $guide);
        }
        if($n) echo "Removed $n old report(s) like [" . $old[0] . "], with their guides: only the newest " . self::KEEP_REPORTS . " are kept.\n";
    }
    private static function counts_str($counts)
    {   return implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($counts), $counts));
    }
    function find_DOI($full_reference)
    {   // Searches CrossRef for one full_reference and checks its candidates. Returns the OUTPUT_COLUMNS from 'searched_text' on.
        $full_reference = self::clean_full_reference($full_reference, $changes); // what is searched and read; the report keeps the original full_reference too
        $ref = $this->parse_full_reference($full_reference);
        $row = array_fill_keys(array_slice(self::OUTPUT_COLUMNS, 2), '');
        $row['searched_text'] = $full_reference;
        $row['text_changes'] = implode('; ', $changes);   // every change made before the search, in plain words; empty = none
        $row['change_codes'] = array_keys($changes);      // not a column: counted for the guide
        foreach(array('author', 'year', 'journal', 'volume', 'issue') as $k) $row["parsed_$k"] = $ref[$k];
        $row['parsed_page'] = $ref['page'] . ($ref['page_end'] !== '' ? '-' . $ref['page_end'] : '');
        if(mb_strlen($full_reference) < self::MIN_LENGTH) return array_merge($row, array('status' => 'skipped', 'review_note' => 'Too short to search.'));
        if(($doi = self::doi_in_text($full_reference)) !== '') return $this->doi_given($row, $doi, $full_reference); // the citation gives its own DOI: no search
        $items = $this->search_crossref($full_reference, $ref['year']);
        if($items === 'stop') return array('status' => 'stop', 'change_codes' => array()); // CrossRef keeps asking us to slow down: the search stops before this row
        if($items === false) return array_merge($row, array('status' => 'error', 'review_note' => 'CrossRef could not be reached. Run again: this row will be searched again.'));
        if(!$items) {
            $row = array_merge($row, array('status' => 'not found', 'review_note' => $this->note_no_candidates($ref)));
            return $this->datacite_route($row, $ref, $full_reference); // DATACITE: a second search, when no volume or pages were read
        }

        // the best candidate: matched > possible > not found; then the most checks that agree; then CrossRef's order (best score first)
        $rank = array('matched' => 2, 'possible' => 1, 'not found' => 0); $best = null; $lookups = 0;
        foreach($items as $i => $item) {
            $c = $this->check_candidate($ref, $item);
            $author_score = 1; $title_share = 0; $item['_authors'] = '';
            if($this->author_title_checks) { // AUTHOR/TITLE: only for candidates that pass the other checks; they never reject one
                $title_share = (float) self::title_share($full_reference, (string) @$item['title'][0]);
                if($c['status'] == 'not found' && $c['fails'] === array('no volume') && $ref['author'] !== '') {
                    /* No volume or pages in the citation -- a book, or a style without them (e.g. Vancouver "Journal. 1917 May") -- and nothing else differs:
                       'possible' (never 'matched') when the first author agrees (or nearly), at least 90% of CrossRef's title is in the citation (all of a title of up to 9 words), and,
                       when the candidate is in a journal, the journal is named in the citation. Books like "Kofoid & Swezy 1921" have BHL DOIs in CrossRef.
                       Not 80%: "Allobates zaparo" (IUCN) then got "Allobates alessandroi: IUCN SSC Amphibian Specialist Group" (86%), and Micha et al. another Azolla paper (83%). */
                    $share = self::title_share($full_reference, (string) @$item['title'][0]);
                    $jshare = self::title_share($full_reference, (string) @$item['container-title'][0], 1);
                    if($share !== null && $share >= 0.9 && ($jshare === null || $jshare >= 0.7)) {
                        $authors = $lookups < self::MAX_AUTHOR_LOOKUPS ? $this->crossref_authors((string) @$item['DOI'], $lookups) : null;
                        if($authors === 'stop') return array('status' => 'stop', 'change_codes' => array());
                        $a = $this->author_title_check($ref['author'], $authors, $full_reference, (string) @$item['title'][0]);
                        if($a['score'] >= 2) {
                            $c['status'] = 'possible'; $c['via'] = 'author and title'; $c['why'] = '';
                            $c['checks'] = array_merge(array_values(array_diff($c['checks'], $jshare !== null ? array('volume=unknown', 'journal=unknown') : array('volume=unknown'))), $jshare !== null ? array('journal=in the citation') : array(), $a['checks']);
                            $c['agree'] += 2 + ($jshare !== null ? 1 : 0); $author_score = $a['score']; $item['_authors'] = is_array($authors) ? implode('; ', $authors) : '';
                        }
                    }
                }
                elseif($c['status'] != 'not found') {
                    $authors = ($ref['author'] !== '' && $lookups < self::MAX_AUTHOR_LOOKUPS) ? $this->crossref_authors((string) @$item['DOI'], $lookups) : null;
                    if($authors === 'stop') return array('status' => 'stop', 'change_codes' => array());
                    $a = $this->author_title_check($ref['author'], $authors, $full_reference, (string) @$item['title'][0]);
                    $c['checks'] = array_merge($c['checks'], $a['checks']);
                    $author_score = $a['score']; $item['_authors'] = is_array($authors) ? implode('; ', $authors) : '';
                    if($a['differs'] && $c['status'] == 'matched') $c['status'] = 'possible'; // the numbers agree but the authors don't: a person checks it
                    // both disagree -- the authors differ AND under 30% of CrossRef's title is in the citation -- so it's another work: not found.
                    // e.g. "King, R.P. Growth performance of Nigerian fish stocks. Naga 20(2):31-35" vs a paper on chickens by Ibe, vol. 20 p. 25-31.
                    // One alone is never enough: CrossRef's names can be garbled (given names as surnames), and many citations have no title.
                    $share = self::title_share($full_reference, (string) @$item['title'][0]);
                    if($a['differs'] && $share !== null && $share < 0.3) { $c['status'] = 'not found'; $c['why'] = 'author and title'; }
                }
            }
            $key = array($rank[$c['status']], $c['agree'], $author_score, $title_share, -$i);
            if(!$best || $key > $best['key']) $best = array('key' => $key, 'c' => $c, 'item' => $item, 'i' => $i);
        }
        $c = $best['c']; $item = $best['item'];
        $c['also'] = $c['status'] == 'not found' ? array() : $this->same_pages_elsewhere($item, $items, $best['i']); // the same work registered twice?
        $clean = fn($s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $row = array_merge($row, array('status' => $c['status'], 'DOI' => $c['status'] == 'not found' ? '' : (string) @$item['DOI'],
                     'crossref_DOI' => (string) @$item['DOI'], 'crossref_score' => round((float) @$item['score'], 1), 'crossref_type' => (string) @$item['type'],
                     'crossref_year' => self::item_year($item), 'crossref_journal' => $clean(@$item['container-title'][0]),
                     'crossref_volume' => $clean(@$item['volume']), 'crossref_issue' => $clean(@$item['issue']), 'crossref_page' => $clean(@$item['page']),
                     'crossref_title' => $clean(@$item['title'][0]), 'crossref_authors' => $item['_authors'], 'checks' => implode(' ', $c['checks'])));
        $row['DOI_URL'] = self::doi_url($row['DOI']);
        $row['DOI_source'] = $row['DOI'] !== '' ? 'CrossRef' : '';
        $row['review_note'] = $this->review_note($ref, $row, $c);
        if($row['status'] == 'not found') return $this->datacite_route($row, $ref, $full_reference); // DATACITE: a second search, when no volume or pages were read
        return $row;
    }
    private function datacite_route($row, $ref, $citation)
    {   /* DATACITE: for a row CrossRef couldn't confirm, with no volume or pages read (a dataset, report, thesis or book) and a first author read:
           DataCite's top 5 candidates, each checked:
             - at least 90% of its title words are in the citation (PANGAEA has series of near-twins: "... at Station JUNE-9", "... in winter 1995")
             - the first author agrees, or nearly
             - the year is within 1 of the cited one, when both are known; a cited year inside its title doesn't count ("... Aegean Sea in winter 1995", 2008)
             - never a GBIF "Occurrence Download" (a user's download, not the dataset)
           When several pass with the same title and first author (e.g. a PANGAEA dataset and its GBIF copy, 10.15468/...), the one that isn't a GBIF
           copy is given and the note names the other. At most 'possible'. Returns the row: unchanged when nothing passes. */
        if(!$this->datacite_search || $ref['volume'] !== '' || $ref['author'] === '') return $row;
        $items = $this->search_datacite($citation);
        if($items === 'stop') return array('status' => 'stop', 'change_codes' => array());
        if(!is_array($items) || !$items) return $row; // DataCite couldn't be reached, or has nothing: the CrossRef result stands
        $ok = array();
        foreach($items as $d) {
            $at = (array) @$d['attributes']; $title = trim((string) @$at['titles'][0]['title']); $doi = (string) ($at['doi'] ?? @$d['id']);
            if($doi === '' || $title === '' || preg_match('/^Occurrence Download/i', $title)) continue;
            $share = self::title_share($citation, $title);
            if($share === null || $share < 0.9) continue;
            $names = array();
            foreach((array) @$at['creators'] as $cr) { $n = trim((string) ($cr['familyName'] ?? '')); if($n === '') $n = trim(explode(',', (string) ($cr['name'] ?? ''))[0]); if($n !== '') $names[] = $n; }
            $a = $this->author_title_check($ref['author'], $names, $citation, $title);
            if($a['score'] < 2) continue;
            $year = (string) ($at['publicationYear'] ?? ''); $ycheck = 'year=unknown';
            if($ref['year'] !== '' && $year !== '') {
                $diff = abs((int) $ref['year'] - (int) $year);
                if($diff <= 1) $ycheck = $diff ? 'year=next to it' : 'year=OK';
                elseif(str_contains($title, $ref['year'])) $ycheck = "year=$year (the cited {$ref['year']} is in its title)";
                else continue;
            }
            $publisher = is_array(@$at['publisher']) ? (string) @$at['publisher']['name'] : (string) @$at['publisher'];
            $ok[] = array('doi' => $doi, 'title' => $title, 'names' => $names, 'year' => $year, 'publisher' => $publisher,
                          'type' => (string) (@$at['types']['resourceTypeGeneral'] ?: @$at['types']['resourceType']), 'checks' => array_merge(array($ycheck), $a['checks']));
        }
        if(!$ok) return $row;
        usort($ok, fn($x, $y) => (int) str_starts_with($x['doi'], '10.15468/') - (int) str_starts_with($y['doi'], '10.15468/')); // a GBIF copy last (stable: DataCite's order otherwise)
        $b = $ok[0]; $norm = fn($t) => implode(' ', self::words($t));
        $also = array();
        foreach(array_slice($ok, 1) as $o) if($norm($o['title']) === $norm($b['title'])) $also[] = $o['doi'] . (str_starts_with($o['doi'], '10.15468/') ? ' (a GBIF copy)' : '');
        $what = $b['type'] !== '' ? $b['type'] : 'record';
        $agree = $this->agreed_text($b['checks']);
        $note = "Not in CrossRef; found in DataCite: \"" . mb_substr($b['title'], 0, 140) . "\" ($what" . ($b['publisher'] !== '' ? ", " . $b['publisher'] : '') . ($b['year'] !== '' ? ", " . $b['year'] : '') . ")"
              . ($b['names'] ? " by " . implode('; ', array_slice($b['names'], 0, 4)) . (count($b['names']) > 4 ? ' et al.' : '') : '') . ". $agree."
              . (str_contains(implode(' ', $b['checks']), 'is in its title') ? " The cited year ({$ref['year']}) is in its title; DataCite's year is {$b['year']}." : '')
              . ($also ? " DataCite also has " . implode(' and ', $also) . " with the same title." : '')
              . " Check that this is the " . strtolower($what) . " cited.";
        return array_merge($row, array('status' => 'possible', 'DOI' => $b['doi'], 'DOI_URL' => self::doi_url($b['doi']), 'DOI_source' => 'DataCite',
            'checks' => trim($row['checks'] . ' | DataCite: ' . implode(' ', $b['checks'])), 'review_note' => $note));
    }
    private function search_datacite($text)
    {   /* DATACITE: DataCite's top 5 records for a citation (https://api.datacite.org/dois?query=...&sort=relevance), politely and cached like CrossRef's answers.
           Its query has a syntax ("-" = NOT, ":" = a field, quotes, brackets ...) and joins words with AND, so a citation word that isn't in the record
           (a co-author, a publisher) would find nothing: the words (3+ characters, at most 40, without a DOI or link) are joined with OR and
           ranked by relevance, e.g. "Gotsis OR Skretas OR Pagou OR ... OR Aegean OR winter OR 1995". Returns the list, false (couldn't be read) or 'stop'. */
        $t = preg_replace('~(?:https?://\S+|\bdoi\s*[:.]?\s*10\.\S+)~i', ' ', $text);
        preg_match_all('/[\p{L}\p{N}]{3,}/u', $t, $w);
        $words = array_slice(array_values(array_unique(array_filter($w[0], fn($x) => !in_array(strtoupper($x), array('AND', 'NOT'))))), 0, 40);
        if(!$words) return array();
        $url = $this->datacite_url . "?" . http_build_query(array('query' => implode(' OR ', $words), 'sort' => 'relevance', 'page[size]' => 5,
                                                                  'fields[dois]' => 'doi,titles,creators,publicationYear,types,publisher'));
        if(Functions::url_already_cached($url, $this->datacite_options)) $json = Functions::lookup_with_cache($url, $this->datacite_options);
        else { $json = $this->call_crossref($url, $this->datacite_options); if($json === 'stop') return 'stop'; if($json === false || $json === 'missing') return false; }
        $d = @json_decode((string) $json, true);
        return is_array($d['data'] ?? null) ? $d['data'] : false;
    }
    private function datacite_work($doi)
    {   // DATACITE: DataCite's record of one DOI (https://api.datacite.org/dois/<DOI>): its 'attributes', 'missing' (DataCite doesn't have it), null (couldn't be read) or 'stop'
        $url = $this->datacite_url . "/" . substr(self::doi_url($doi), strlen("https://doi.org/"));
        if(Functions::url_already_cached($url, $this->datacite_options)) $json = Functions::lookup_with_cache($url, $this->datacite_options);
        else { $json = $this->call_crossref($url, $this->datacite_options); if($json === 'stop' || $json === 'missing') return $json; if($json === false) return null; }
        $d = @json_decode((string) $json, true);
        return is_array($d['data']['attributes'] ?? null) ? $d['data']['attributes'] : null;
    }
    private function doi_given($row, $doi, $citation)
    {   /* The citation gives its own DOI, e.g. "... doi:10.1594/PANGAEA.692046" or "DOI: 10.1080/09670262.2014.982202,2014.": CrossRef's record of it is read
           (one call, cached) instead of a search. A DOI can be mistyped or cut short in a citation, so:
             - CrossRef has it and its title is in the citation (or can't be compared)  -> matched
             - CrossRef has it but its title isn't in the citation (under 50%)          -> possible: maybe a mistyped DOI
             - CrossRef doesn't have it                                                 -> possible: registered elsewhere (e.g. DataCite, for PANGAEA datasets), or mistyped */
        $work = $this->crossref_work($doi);
        if($work === 'stop') return array('status' => 'stop', 'change_codes' => array());
        if($work === null) return array_merge($row, array('status' => 'error', 'review_note' => 'The citation gives the DOI ' . $doi . ', but CrossRef could not be reached to check it. Run again: this row will be checked again.'));
        $row = array_merge($row, array('DOI' => $doi, 'DOI_URL' => self::doi_url($doi), 'DOI_source' => 'citation', 'crossref_DOI' => $doi));
        if($work === 'missing' && $this->datacite_search) { // DATACITE: is it a DataCite DOI? (e.g. PANGAEA datasets)
            $dc = $this->datacite_work($doi);
            if($dc === 'stop') return array('status' => 'stop', 'change_codes' => array());
            if(is_array($dc)) {
                $title = trim((string) @$dc['titles'][0]['title']); $share = self::title_share($citation, $title);
                $t = $share === null ? 'title=unknown' : 'title=' . ($share >= 0.7 ? 'OK' : ($share >= 0.3 ? 'partly' : 'not in citation')) . ' (' . round(100 * $share) . '%)';
                $desc = "\"" . mb_substr($title, 0, 140) . "\"" . (!empty($dc['publicationYear']) ? " (" . $dc['publicationYear'] . ")" : '');
                if($share !== null && $share < 0.5) return array_merge($row, array('status' => 'possible', 'checks' => "doi=in the citation, not in CrossRef, in DataCite $t",
                    'review_note' => "The citation itself gives this DOI; it is registered with DataCite (not CrossRef), but its title isn't in the citation: $desc. The DOI may be mistyped. Open the link to check."));
                return array_merge($row, array('status' => 'matched', 'checks' => "doi=in the citation, not in CrossRef, in DataCite $t",
                    'review_note' => "The citation itself gives this DOI, registered with DataCite (not CrossRef): $desc. Accept it."));
            }
        }
        if($work === 'missing') return array_merge($row, array('status' => 'possible', 'checks' => 'doi=in the citation, not in CrossRef' . ($this->datacite_search ? ' or DataCite' : ''),
            'review_note' => "The citation itself gives this DOI, but " . ($this->datacite_search ? "neither CrossRef nor DataCite has it: it may be registered elsewhere, or it may be mistyped." : "CrossRef doesn't have it: it may be registered elsewhere (e.g. with DataCite, as PANGAEA datasets are), or it may be mistyped.") . " Open the link: accept it if it opens the work cited."));
        $clean = fn($x) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $x), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $title = $clean(@$work['title'][0]); $share = self::title_share($citation, $title);
        $row = array_merge($row, array('crossref_type' => (string) @$work['type'], 'crossref_year' => self::item_year($work), 'crossref_journal' => $clean(@$work['container-title'][0]),
            'crossref_volume' => $clean(@$work['volume']), 'crossref_issue' => $clean(@$work['issue']), 'crossref_page' => $clean(@$work['page']), 'crossref_title' => $title,
            'crossref_authors' => implode('; ', array_filter(array_map(fn($a) => trim((string) ($a['family'] ?? $a['name'] ?? '')), (array) @$work['author'])))));
        $t = $share === null ? 'title=unknown' : 'title=' . ($share >= 0.7 ? 'OK' : ($share >= 0.3 ? 'partly' : 'not in citation')) . ' (' . round(100 * $share) . '%)';
        if($share !== null && $share < 0.5) return array_merge($row, array('status' => 'possible', 'checks' => "doi=in the citation, in CrossRef $t",
            'review_note' => "The citation itself gives this DOI, and CrossRef has it, but its title isn't in the citation: \"" . mb_substr($title, 0, 120) . "\". The DOI may be mistyped. Open the link to check."));
        return array_merge($row, array('status' => 'matched', 'checks' => "doi=in the citation, in CrossRef $t",
            'review_note' => "The citation itself gives this DOI, and CrossRef has it: \"" . mb_substr($title, 0, 120) . "\"" . ($row['crossref_year'] !== '' ? " (" . $row['crossref_year'] . ")" : '') . ". Accept it."));
    }
    private function crossref_work($doi)
    {   // CrossRef's record of one DOI (https://api.crossref.org/works/<DOI>, politely, cached): its 'message', 'missing' (404: CrossRef doesn't have it), null (couldn't be read) or 'stop'
        $url = $this->crossref_works_url . "/" . substr(self::doi_url($doi), strlen("https://doi.org/"));
        if(Functions::url_already_cached($url, $this->crossref_options)) $json = Functions::lookup_with_cache($url, $this->crossref_options);
        else { $json = $this->call_crossref($url); if($json === 'stop' || $json === 'missing') return $json; if($json === false) return null; }
        $d = @json_decode((string) $json, true);
        return ($d['status'] ?? '') === 'ok' ? (array) $d['message'] : null;
    }
    private function crossref_authors($doi, &$lookups)
    {   /* AUTHOR/TITLE: the authors' surnames of one CrossRef record (CrossRef's search answer doesn't include them): https://api.crossref.org/works/<DOI>,
           politely and cached like the search. Returns a list (empty: CrossRef lists none), null (couldn't be read) or 'stop' (CrossRef asks us to slow down). */
        if($doi === '') return null;
        $url = $this->crossref_works_url . "/" . substr(self::doi_url($doi), strlen("https://doi.org/"));
        if(Functions::url_already_cached($url, $this->crossref_options)) $json = Functions::lookup_with_cache($url, $this->crossref_options);
        else { $lookups++; $json = $this->call_crossref($url); if($json === 'stop') return 'stop'; if($json === false || $json === 'missing') return null; }
        $d = @json_decode((string) $json, true);
        if(($d['status'] ?? '') !== 'ok') return null;
        $names = array();
        foreach((array) @$d['message']['author'] as $a) { $n = trim((string) ($a['family'] ?? $a['name'] ?? '')); if($n !== '') $names[] = $n; }
        return $names;
    }
    private function author_title_check($surname, $authors, $citation, $title)
    {   /* AUTHOR/TITLE: the checks to add, a score for ranking candidates (3 = the author agrees, 2 = nearly, 1 = can't tell, 0 = differs), and whether it differs.
           The surname may be any of CrossRef's authors; accents and capitals don't count; up to 2 letters off counts as nearly (OCR slips, e.g. "Boulesger"). */
        $r = array('checks' => array(), 'score' => 1, 'differs' => false);
        $norm = function($x) { $x = mb_strtolower((string) $x); $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $x); return preg_replace('/[^a-z]/', '', $t !== false ? $t : $x); };
        $sn = $norm($surname);
        if($sn === '')                        $r['checks'][] = 'author=unknown (none read from the citation)';
        elseif($authors === null)             $r['checks'][] = "author=unknown (CrossRef's authors couldn't be read)";
        elseif(!$authors)                     $r['checks'][] = 'author=unknown (CrossRef lists no authors)';
        else {
            $near = '';
            foreach($authors as $a) {
                $an = $norm($a); if($an === '') continue;
                if($an === $sn || (strlen($an) >= 4 && str_ends_with($sn, $an)) || (strlen($sn) >= 4 && str_ends_with($an, $sn))) { $r['checks'][] = 'author=OK'; $r['score'] = 3; $near = null; break; }
                if(min(strlen($an), strlen($sn)) >= 5 && levenshtein($an, $sn) <= 2) $near = $a;
            }
            if($near) { $r['checks'][] = "author=nearly ($near)"; $r['score'] = 2; }
            elseif($near === '') { $r['checks'][] = 'author=differs'; $r['score'] = 0; $r['differs'] = true; }
        }
        $share = self::title_share($citation, $title);
        if($share === null) $r['checks'][] = 'title=unknown';
        else $r['checks'][] = 'title=' . ($share >= 0.7 ? 'OK' : ($share >= 0.3 ? 'partly' : 'not in citation')) . ' (' . round(100 * $share) . '%)';
        return $r;
    }
    private static function title_share($citation, $title, $min_words = 3)
    {   // AUTHOR/TITLE: the share of CrossRef's title words (3+ letters) that are in the citation; null for a title of fewer than $min_words such words
        $tw = self::words(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if(!$tw || count($tw) < $min_words) return null;
        $cw = array_flip(self::words($citation));
        return count(array_filter($tw, fn($w) => isset($cw[$w]))) / count($tw);
    }
    private function same_pages_elsewhere($best, $items, $best_i)
    {   // other candidates with the same journal, volume and first page as the one chosen, and another DOI: the same work registered twice, perhaps
        $first = fn($p) => preg_match('/^\s*(\d+)/', (string) $p, $m) ? $m[1] : '';
        $also = array();
        foreach($items as $i => $it) {
            if($i == $best_i || (string) @$it['DOI'] === (string) @$best['DOI'] || $first(@$it['page']) === '') continue;
            if(mb_strtolower((string) @$it['container-title'][0]) === mb_strtolower((string) @$best['container-title'][0])
               && ltrim((string) @$it['volume'], '0') === ltrim((string) @$best['volume'], '0') && $first(@$it['page']) === $first(@$best['page']))
                $also[] = $it['DOI'] . " (" . (@$it['issue'] !== null && @$it['issue'] !== '' ? "issue " . $it['issue'] . ", " : '') . "pages " . @$it['page'] . ", " . self::item_year($it) . ")";
        }
        return $also;
    }
    static function doi_in_text($s)
    {   // a DOI written in the citation itself, e.g. "doi:10.1594/PANGAEA.692046", "DOI: 10.1080/09670262.2014.982202,", "https://doi.org/10..."; '' if none
        if(!preg_match('~(?:\bdoi\s*[:.]?\s*|https?://(?:dx\.)?doi\.org/)(10\.\d{4,9}/[^\s"<>,]+)~i', $s, $m)) return ''; // no comma: in "DOI 10.1007/s40415-014-0048-3,2014." the year follows
        $doi = $m[1];
        while($doi !== '' && (strpbrk(substr($doi, -1), '.,;:') !== false || (substr($doi, -1) === ')' && substr_count($doi, '(') < substr_count($doi, ')')))) $doi = substr($doi, 0, -1);
        return $doi; // a ")" that closes a "(" inside it stays, e.g. 10.1016/0044-8486(93)90035-w
    }
    static function doi_url($doi)
    {   // the DOI as a link, e.g. 10.1016/0044-8486(93)90035-w -> https://doi.org/10.1016/0044-8486(93)90035-w; '' when there is no DOI.
        // Characters that would break a URL (space, %, ", #, ?, <, >, ...) are percent-encoded; "/" and the usual DOI characters are kept as they are.
        $doi = trim((string) $doi);
        if($doi === '') return '';
        return "https://doi.org/" . preg_replace_callback('/[^A-Za-z0-9\-._~\/:;()\[\]@!$&\'*+,=]/', fn($m) => rawurlencode($m[0]), $doi);
    }
    // every pre-processing rule of clean_full_reference(): its code => what the guide says about it
    const TEXT_CHANGES = array(
        'spaces' => "Spaces at the start or end of the full_reference were removed.",
        'quotes' => "Double quotes around the whole full_reference were removed, and doubled quotes inside (\"\") made single, e.g. '\"Lebour, M. V. (1917). ...\"' -> 'Lebour, M. V. (1917). ...'. A spreadsheet adds them when it saves a file as text.",
        'ref_n'  => "A FishBase reference number at the very end, e.g. '(Ref. 3)', was removed. It is FishBase's own number, not part of the citation, and can mislead CrossRef.",
        'zero'   => "A stray '0' glued to the last author name, where FishBase exports put the year, was removed, e.g. 'Ruwet0 Ecology' -> 'Ruwet. Ecology', 'E.0 Tilapiine' -> 'E. Tilapiine', '(ed.)0 Tilapia' -> '(ed.) Tilapia' (the '(ed.)' is kept). The year itself is missing in these citations, so the year couldn't be checked.");
    private static function drop_title($j)
    {   /* "Title. Journal" -> "Journal", for citations like "Boucher RC (2004) New concepts of ... lung disease. Eur Resp J 23: 146-158", where the
           title sits between the year and the journal. Cut at the last ". " (or "? ", "! ") after a word (e.g. "disease.", "NBD1.") and before a capital
           letter, whose left side reads like a title:
           at least 3 full words (4+ letters, not abbreviations ending in "."). Abbreviated journal names are never cut, e.g.
           "Mem. Torrey Bot. Club", "Bot. J. Linn. Soc.", "Proc. R. Soc. Lond., Ser. B: Biol. Sci." stay whole. */
        if(!preg_match_all('/[\p{L}\d]{2,}[.?!]\s+(?=\p{Lu})/u', $j, $b, PREG_OFFSET_CAPTURE)) return $j;
        foreach(array_reverse($b[0]) as $x) {
            $cut = $x[1] + strlen($x[0]);
            if(preg_match_all('/\b\p{L}{4,}\b(?![.])/u', substr($j, 0, $cut), $w) >= 3) return substr($j, $cut);
        }
        return $j;
    }
    static function clean_full_reference($s, &$changes = null)
    {   /* $changes: code => the change in plain words, for this text (empty: nothing was changed). Every rule is in TEXT_CHANGES.
           Removes what only confuses CrossRef, before the search (the report keeps the original full_reference):
             - quotes around the whole text, added by a spreadsheet: "\"Lebour, M. V. (1917). ...\"" -> "Lebour, M. V. (1917). ..." (and "" inside -> ")
             - a FishBase reference number at the end, e.g. "... ICLARM Conf. Proc. 7. (Ref. 3)" -> "... ICLARM Conf. Proc. 7."
             - a FishBase "0" glued to the authors where the year should be (the year is lost), e.g.
               "Trewavas, E.0 Tilapiine fishes ..." -> "Trewavas, E. Tilapiine fishes ...", "J.-C. Ruwet0 Ecology ..." -> "J.-C. Ruwet. Ecology ..."
               "Pullin, R.S.V. (ed.)0 Tilapia genetic ..." -> "Pullin, R.S.V. (ed.) Tilapia genetic ..." (after an editor's "(ed.)", "(eds.)", "(comp.)", kept)
               only the first one, and only a lone 0 right after a letter, a dot or a ")" and right before a capital letter */
        $changes = array(); $s = (string) $s;
        if(($t = trim($s)) !== $s) { $changes['spaces'] = "removed spaces at the start or end"; $s = $t; }
        // quotes a spreadsheet adds around a field when it saves it as text, with the quotes inside doubled: "Lebour, M. V. ""The Peridiniales ..."" ..."
        if(strlen($s) >= 2 && $s[0] === '"' && substr($s, -1) === '"') {
            $inner = substr($s, 1, -1); $doubled = str_contains($inner, '""');
            $changes['quotes'] = "removed the quotes around the whole citation" . ($doubled ? ", and made the doubled quotes inside single" : '');
            $s = trim(str_replace('""', '"', $inner));
        }
        if(preg_match('/\s*(\(Ref\.\s*\d+\))\s*$/u', $s, $m)) { $changes['ref_n'] = "removed '" . $m[1] . "' at the end"; $s = rtrim(substr($s, 0, -strlen($m[0]))); }
        if(preg_match('/(\S*[\p{L}.)])0(?=\s+\p{Lu})/u', $s, $m, PREG_OFFSET_CAPTURE)) { // only the first one: "E.0 " -> "E. ", "Ruwet0 " -> "Ruwet. ", "(ed.)0 " -> "(ed.) "
            $word = $m[1][0]; $fixed = (str_ends_with($word, '.') || str_ends_with($word, ')')) ? $word : $word . '.';
            $changes['zero'] = "removed a stray '0' after the authors ('" . $word . "0' -> '" . $fixed . "')";
            $s = substr($s, 0, $m[0][1]) . $fixed . substr($s, $m[0][1] + strlen($m[0][0]));
        }
        return $s; // nothing else changes, so every other full_reference keeps its cached CrossRef answer
    }
    function parse_full_reference($s)
    {   /* Reads the year, journal, volume, issue and page(s) from a full_reference, e.g.
             "1998. Brittonia,50(1): 59,1998."                                     -> 1998, Brittonia, 50, 1, 59
             "Saint-Hilaire, A. & Tulasne. 1842. Ann. Sci. Nat., Bot.,ser. 2, 17: 138,1842." -> 1842, "Ann. Sci. Nat., Bot.,ser. 2", 17, '', 138
             "Bartoli, A. ... 1999. Revisión ... (Asteraceae: Astereae).,Kurtziana 27(3):327-359.,1999." -> 1999, Kurtziana, 27, 3, 327-359
           Anything it can't read is left blank, and that check is skipped. */
        $ref = array('year' => '', 'journal' => '', 'volume' => '', 'issue' => '', 'page' => '', 'page_end' => '', 'author' => '');
        // the first author's surname: the words before the first comma, letters only, e.g. "Boulenger, G.A. ..." -> Boulenger, "Thys van den Audenaerde, D.F.E." -> as is
        //   also "Knowles MR, Boucher RC (2002) ..." and "Boucher RC (2004) ..." (surname + initials, no comma) -> Knowles, Boucher; a leading list number "1. " is skipped (not a year: "1998. Brittonia,50(1): 59" has no author)
        if(preg_match('/^\s*(?:\d{1,3}[.)]\s+)?(\p{L}[\p{L}\'\x{2019}\- ]{1,40}?)\s*,/u', $s, $m)) $ref['author'] = trim(preg_replace('/\s+\p{Lu}{1,3}$/u', '', trim($m[1])));
        elseif(preg_match('/^\s*(?:\d{1,3}[.)]\s+)?(\p{Lu}[\p{L}\'\x{2019}\-]+)\s+\p{Lu}{1,3}\b/u', $s, $m)) $ref['author'] = $m[1];
        $Y = '(1[5-9]\d\d|20\d\d)'; $P = '(\d{1,5})(?:\s*[-\x{2013}\x{2014}]\s*(\d{1,5}))?'; // a year; a page or a page range (a hyphen, an en dash or an em dash)
        $g = fn($m, $i) => (isset($m[$i]) && $m[$i][1] >= 0) ? $m[$i][0] : ''; // a group of a PREG_OFFSET_CAPTURE match, '' when it didn't match
        // the year, in this order:
        //   reference.tab's own style, with the year repeated at the end ("1992. Bradea,9: 85 (1991) ...,1992."): the first one, as before (last rule)
        //   a year alone in parentheses, e.g. "Boucher RC (2004) ...", "Lebour, M. V. (1917). ..." (APA), "... (2008)." (Chicago)
        //   a year followed by a month, e.g. "... United Kingdom. 1963 Nov" (Vancouver) -- not "in August 1962" in its title
        //   else the first one that isn't part of a range, e.g. not "1904" in "the Third Tanganyika Expedition, 1904-1905" (a range isn't a publication year)
        $tab_style = (bool) preg_match('/,\s*' . $Y . '\.?\s*$/', $s);
        if(!$tab_style && preg_match('/\(' . $Y . '[a-z]?\)/', $s, $m, PREG_OFFSET_CAPTURE)) { $ref['year'] = $m[1][0]; $year_end = $m[1][1] + 4; }
        elseif(!$tab_style && preg_match('/\b' . $Y . '\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\b/', $s, $m, PREG_OFFSET_CAPTURE)) { $ref['year'] = $m[1][0]; $year_end = $m[1][1] + 4; }
        elseif(preg_match_all('/\b' . $Y . '\b/', $s, $years, PREG_OFFSET_CAPTURE)) foreach($years[1] as $y) {
            if(preg_match('/\d\s*(?:-|\x{2013})\s*$/u', substr($s, 0, $y[1])) || preg_match('/^\s*(?:-|\x{2013})\s*\d/u', substr($s, $y[1] + 4))) continue;
            $ref['year'] = $y[0]; $year_end = $y[1] + 4; break;
        }
        // the volume, issue and page(s), the last one of these:
        //   (a) Chicago "11, no. 2 (1917): 183-200", MLA "11.2 (1917): 183-200", or "11 (1917): 183-200": the year in parentheses is the publication year
        //   (b) "VOLUME[(ISSUE)]: PAGE[-PAGE]", e.g. "50(1): 59", "17: 138", "27(3):327-359", "81A:423-426" (volume 81 of Part A)
        //   (c) APA / Harvard "11(2), 183-200", "11(2), pp.183-200": only with a page range, so a lone number after a comma isn't taken for a page
        $m = null; $vol_at = null;
        if(preg_match_all('/(?<![\w.])(\d{1,4}[A-Z]?)(?:\.(\d{1,4})|\s*,\s*no\.\s*([\w\/-]{1,8}))?\s*\(\s*(?:\p{L}+\.?\s+)?' . $Y . '\s*\)\s*:\s*' . $P . '/u', $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $m = end($all);
            $ref['volume'] = $g($m, 1); $ref['issue'] = $g($m, 2) !== '' ? $g($m, 2) : $g($m, 3); $ref['year'] = $g($m, 4); $ref['page'] = $g($m, 5); $ref['page_end'] = $g($m, 6);
            $vol_at = $m[0][1]; unset($year_end); // the year comes after the journal here
        }
        elseif(preg_match_all('/(?<![\w.])(\d{1,4}[A-Z]?)\s*(?:\(([^()]{1,20})\))?\s*:\s*' . $P . '/u', $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)
            || (preg_match_all('/(?<![\w.])(\d{1,4}[A-Z]?)\s*(?:\(([^()]{1,20})\))?\s*,\s*(?:pp?\.\s*)?(\d{1,5})\s*[-\x{2013}\x{2014}]\s*(\d{1,5})(?!\d)/u', $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)
                && ($all = array_values(array_filter($all, fn($x) => !(preg_match('/^' . $Y . '$/', $x[3][0]) && preg_match('/^' . $Y . '$/', $x[4][0])))))) ) { // not a year range, e.g. "Select. Hexandr. Pl.,9,1831-1834"

            $m = end($all);
            $ref['volume'] = $g($m, 1); $ref['issue'] = trim($g($m, 2)); $ref['page'] = $g($m, 3); $ref['page_end'] = $g($m, 4);
            $vol_at = $m[0][1];
            if($ref['volume'] === $ref['year']) $ref['volume'] = ''; // e.g. "Mem. Math. Phys. Inst. Par.,1811: 82" -> the year, not a volume
        }
        if($vol_at !== null) { // the journal
            $before = substr($s, 0, $vol_at);
            // a title in quotes (Chicago, MLA): the journal is what follows it, e.g. '"The Peridiniales ... breakwater." Journal of the ... Kingdom 11, no. 2'
            // (not a name in a journal title, e.g. 'Anais Bot. Herb. "Barbosa Rodrigues"': a quoted title ends with ".", "?" or "!" inside the quotes)
            if(preg_match_all('/["\x{201C}][^"\x{201C}\x{201D}]{10,}[.?!]["\x{201D}]/u', $before, $q, PREG_OFFSET_CAPTURE)) {
                $last = end($q[0]);
                $ref['journal'] = trim(substr($before, $last[1] + strlen($last[0])), " .,;()");
            }
            elseif(isset($year_end) && $vol_at > $year_end) { // between the year and the volume; in "Title.,Journal 27(3)" the part after the last ".,"
                $j = trim(substr($s, $year_end, $vol_at - $year_end), " .,;()"); // e.g. ". Kew Bull.," -> "Kew Bull"; "(2004) Title. Eur Resp J" -> after ")"
                if(($p = strrpos($j, '.,')) !== false) $j = substr($j, $p + 2);
                $ref['journal'] = trim(preg_replace('/,\s*v(?:ol)?\.?$/i', '', trim(self::drop_title($j), " .,;")), " .,;"); // "Fl. Venez. Guayana,v. 6" -> without ",v" 
            }
        }
        return $ref;
    }
    private function crossref_url($full_reference, $year)
    {   // the CrossRef search for one full_reference; also the key of its cached answer
        $q = array('query.bibliographic' => $full_reference, 'rows' => 5, 'select' => 'DOI,score,title,container-title,volume,issue,page,issued,published,type');
        if($year) $q['filter'] = "from-pub-date:" . ($year - 1) . ",until-pub-date:" . ($year + 1);
        return $this->crossref_works_url . "?" . http_build_query($q);
    }
    function count_rows_to_search($file, $start = 1, $limit = 0)
    {   /* For an estimate before a search: of the rows a search of $file would do (same start/limit rules), how many are
             'new'     -> sent to CrossRef (about 3 seconds each),
             'cached'  -> searched before: their answer comes from the cache, instantly,
             'skipped' -> too short to search.
           Makes no call. Returns array('rows' => n, 'new' => n, 'cached' => n, 'skipped' => n), or false. */
        if(!($in = @fopen($file, 'r'))) return false;
        $header = explode("\t", rtrim(preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets($in)), "\r\n"));
        $col_ref = array_search('full_reference', $header);
        if($col_ref === false) { fclose($in); return false; }
        $n = array('rows' => 0, 'new' => 0, 'cached' => 0, 'skipped' => 0); $row_no = 0; $start = max(1, (int) $start); $seen = array();
        while(($line = fgets($in)) !== false) {
            if(trim($line) === '') continue;
            $row_no++;
            if($row_no < $start) continue;
            if($limit && $n['rows'] >= $limit) break;
            $n['rows']++;
            $ref = self::clean_full_reference(trim((string) (explode("\t", rtrim($line, "\r\n"))[$col_ref] ?? ''))); // as find_DOI() searches it
            if(mb_strlen($ref) < self::MIN_LENGTH) { $n['skipped']++; continue; }
            $url = $this->crossref_url($ref, $this->parse_full_reference($ref)['year']);
            if(isset($seen[$url]) || Functions::url_already_cached($url, $this->crossref_options)) $n['cached']++; // a repeat in the same file is asked only once
            else { $n['new']++; $seen[$url] = 1; }
        }
        fclose($in);
        return $n;
    }
    private function search_crossref($full_reference, $year)
    {   /* the top 5 CrossRef candidates (best score first), only from the year and the years next to it when a year was read.
           false = this row failed (marked 'error', tried again on the next run); 'stop' = CrossRef keeps asking us to slow down: stop the search.
           A cached answer is used as is: the same full_reference is never sent twice. */
        $url = $this->crossref_url($full_reference, $year);
        if(Functions::url_already_cached($url, $this->crossref_options)) $json = Functions::lookup_with_cache($url, $this->crossref_options); // read from the cache, no call
        else {
            $json = $this->call_crossref($url);
            if($json === 'stop' || $json === false) return $json;
        }
        $d = @json_decode((string) $json, true);
        if(($d['status'] ?? '') !== 'ok') return false;
        return $d['message']['items'] ?? array();
    }
    private function call_crossref($url, $opt = null)
    {   /* $opt: the options of the service called: crossref_options (default) or datacite_options (DATACITE): its cache, name and success test.
           One CrossRef call, politely: one at a time, a PAUSE after each call, and our contact email in the User-Agent (CROSSREF_MAILTO).
           - 200: the answer is saved in the same cache lookup_with_cache() uses (so it's never asked again) and returned.
           - 429 (too many requests) or 503 (busy): wait -- CrossRef's Retry-After, else 1, 2, 4, 8 minutes -- and try again.
             After THROTTLE_LIMIT such answers in a row, returns 'stop': the search stops cleanly and can be resumed with "start".
           - anything else (no connection, 5xx, ...): tried 3 times, 5 seconds apart; then false (this row = 'error'). */
        $opt = $opt ?? $this->crossref_options; $name = $opt['name'];
        for($attempt = 1, $other_failures = 0; ; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => true,
                                         CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => $opt['user_agent']));
            $res = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            usleep(self::PAUSE_SECONDS * 1000000); // the pause between calls, whatever the answer
            $headers = $res === false ? '' : substr($res, 0, $header_size);
            $body    = $res === false ? '' : substr($res, $header_size);
            if($code == 200 && preg_match('/' . $opt['validation_regex'] . '/', $body)) {
                $this->throttled_in_a_row = 0;
                $this->save_to_cache($url, $body, $opt);
                return $body;
            }
            if($code == 404 && (str_contains($url, '/works/10.') || str_contains($url, '/dois/10.'))) return 'missing'; // a DOI it doesn't have (e.g. a DataCite DOI in CrossRef)
            if($code == 429 || $code == 503) {
                $this->throttled_in_a_row = ($this->throttled_in_a_row ?? 0) + 1;
                if($this->throttled_in_a_row >= self::THROTTLE_LIMIT) return 'stop';
                $wait = preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m) ? min((int) $m[1], 600) : 60 * (2 ** ($this->throttled_in_a_row - 1));
                echo "$name answered HTTP $code (" . ($code == 429 ? 'too many requests' : 'busy') . "): waiting " . $wait . " seconds, then trying again"
                   . " ($this->throttled_in_a_row of " . self::THROTTLE_LIMIT . " before stopping).\n";
                sleep(max(1, $wait));
                continue;
            }
            if(++$other_failures >= 3) { echo "$name call failed (HTTP $code) 3 times; this row is marked 'error' and will be tried again on the next run.\n"; return false; }
            sleep(5);
        }
    }
    private function save_to_cache($url, $body, $opt = null)
    {   // the same file lookup_with_cache($url, $opt) reads: <cache>/crossref/ab/cd/<md5>.cache (DataCite: <cache>/datacite/...)
        $opt = $opt ?? $this->crossref_options;
        $md5 = md5($url);
        $dir = DOC_ROOT . $GLOBALS['MAIN_CACHE_PATH'] . $opt['resource_id'] . "/" . substr($md5, 0, 2) . "/" . substr($md5, 2, 2) . "/";
        if(!is_dir($dir)) @mkdir($dir, 0777, true);
        @file_put_contents($dir . "$md5.cache", $body);
    }
    private static function item_year($item)
    {   return (string) (@$item['issued']['date-parts'][0][0] ?: @$item['published']['date-parts'][0][0]);
    }
    function journal_agrees($ref_journal, $container)
    {   /* true / false / null (can't compare). Each word of the citation's abbreviation must be the start of a word of CrossRef's title,
           e.g. "Ann. Missouri Bot. Gard." ~ "Annals of the Missouri Botanical Garden"; "Mem. Torrey Bot. Club" !~ "Bulletin of the Torrey Botanical Club".
           One word may be missing only in a long abbreviation (5+ words). */
        $words = self::words($ref_journal); $cwords = self::words($container);
        if(!$words || !$cwords) return null;
        $miss = count(array_filter($words, function($w) use ($cwords) { foreach($cwords as $c) if(str_starts_with($c, $w)) return false; return true; }));
        return $miss == 0 || (count($words) >= 5 && $miss == 1);
    }
    function check_candidate($ref, $item)
    {   // Returns array('status' => matched / possible / not found, 'checks' => what agreed (list), 'agree' => n checks that agree, 'why' => reason it isn't matched)
        $checks = array(); $ok = true; $why = ''; $fails = array();
        $fail = function($check, $reason) use (&$checks, &$ok, &$why, &$fails) { $checks[] = $check; if($ok) $why = $reason; $ok = false; $fails[] = $reason; };
        // type: a whole issue or volume is never the answer
        $type = (string) @$item['type'];
        if(in_array($type, self::NOT_AN_ARTICLE)) $fail("type=$type", 'not an article');
        // journal
        $journal = $this->journal_agrees($ref['journal'], (string) @$item['container-title'][0]);
        if($journal === true) $checks[] = 'journal=OK';
        elseif($journal === false) $fail('journal=differs', 'journal');
        else $checks[] = 'journal=unknown';
        // volume
        $num = fn($v) => ltrim(trim((string) $v), '0');
        if($ref['volume'] !== '') {
            if(strtoupper($num($ref['volume'])) === strtoupper($num(@$item['volume']))) $checks[] = 'volume=OK';
            // "81A" = volume 81 of "Part A": CrossRef often has volume "81" and the part in the journal title, e.g. "Comparative Biochemistry and Physiology Part A: Physiology"
            elseif(preg_match('/^(\d+)([A-Z])$/', $num($ref['volume']), $vl) && $vl[1] === $num(@$item['volume'])
                   && preg_match('/\bPart\s+' . $vl[2] . '\b/i', (string) @$item['container-title'][0])) $checks[] = 'volume=OK';
            else $fail('volume=differs', 'volume');
        }
        else $fail('volume=unknown', 'no volume'); // without a volume, a candidate can't be confirmed
        // issue: only when both are plain numbers, e.g. "2" vs "3"
        $issue = trim((string) @$item['issue']);
        if(ctype_digit($ref['issue']) && ctype_digit($issue)) {
            if($num($ref['issue']) === $num($issue)) $checks[] = 'issue=OK';
            else $fail('issue=differs', 'issue');
        }
        // year: the cited year can be a year off the issue date
        $y = self::item_year($item);
        if($ref['year'] !== '' && $y !== '') {
            $diff = abs((int) $ref['year'] - (int) $y);
            if($diff == 0) $checks[] = 'year=OK'; elseif($diff == 1) $checks[] = 'year=next to it'; else $fail('year=differs', 'year');
        }
        // page: the page cited is in the article's range (or is its first page)
        $page = trim((string) @$item['page']); $page_ok = null;
        if($ref['page'] !== '' && preg_match('/^(\d+)(?:\s*-\s*(\d+))?$/', $page, $p)) {
            $first = (int) $p[1]; $last = isset($p[2]) ? (int) $p[2] : null; $cited = (int) $ref['page'];
            if($cited == $first)                                          { $page_ok = true;  $checks[] = "page=first page $first"; }
            elseif($last !== null && $cited > $first && $cited <= $last)  { $page_ok = true;  $checks[] = "page=in $first-$last"; }
            elseif($last === null && $cited > $first)                     { $checks[] = "page=after first page $first, end unknown"; }
            else $fail("page=$cited not in $page", 'page');
        }
        else $checks[] = 'page=unknown';
        $agree = count(array_filter($checks, fn($c) => str_ends_with($c, '=OK') || str_starts_with($c, 'page=first') || str_starts_with($c, 'page=in')));
        if(!$ok) return array('status' => 'not found', 'checks' => $checks, 'agree' => $agree, 'why' => $why, 'fails' => $fails);
        $status = ($page_ok && $journal === true) ? 'matched' : 'possible'; // 'matched' needs the journal and the page confirmed
        return array('status' => $status, 'checks' => $checks, 'agree' => $agree, 'why' => '', 'fails' => array());
    }
    private function note_no_candidates($ref)
    {   $note = "CrossRef has no records" . ($ref['year'] ? " from " . ($ref['year'] - 1) . "-" . ($ref['year'] + 1) : '') . " that resemble this citation.";
        return $note . $this->note_old_work($ref);
    }
    private function note_old_work($ref)
    {   return ($ref['year'] !== '' && (int) $ref['year'] < 1900) ? " Works from before 1900 rarely have DOIs; the Biodiversity Heritage Library (biodiversitylibrary.org) may have it." : '';
    }
    private function review_note($ref, $row, $c)
    {   // what was found, in plain words, for the Data Managers' final check
        $cand = $row['crossref_journal'] === '' ? "a " . ($row['crossref_type'] !== '' ? $row['crossref_type'] : 'record') . " (" . $row['crossref_year'] . ")" // e.g. a book
              : "\"" . $row['crossref_journal'] . "\" " . $row['crossref_volume'] . ($row['crossref_issue'] !== '' ? "(" . $row['crossref_issue'] . ")" : '')
              . ($row['crossref_page'] !== '' ? ": " . $row['crossref_page'] : '') . " (" . $row['crossref_year'] . ")";
        $cited = "page " . $ref['page'];
        $agree = $this->agreed_text($c['checks']); // e.g. "Journal, volume and year agree": only what was really compared and agreed
        $unchecked = $this->unchecked_text($ref, $c['checks']) . $this->author_note($ref, $row, $c); // e.g. " The year couldn't be checked: no year could be read from the citation."
        $year_note = in_array('year=next to it', $c['checks']) ? " The cited year ({$ref['year']}) is one off CrossRef's ({$row['crossref_year']}), which is common (cover date vs. publication date)." : '';
        if($c['status'] == 'matched') {
            return "$agree, and $cited is in $cand, \"" . mb_substr($row['crossref_title'], 0, 120) . "\"." . $year_note . $unchecked
                 . " Accept, unless the article clearly isn't the one cited.";
        }
        if($c['status'] == 'possible') {
            $checks = implode(' ', $c['checks']);
            if(($c['via'] ?? '') === 'author and title') // AUTHOR/TITLE: no volume or pages in the citation
                $n = "$agree, but no volume or pages could be read from the citation (a book, or a citation style without them), so the numbers couldn't be compared. Check that this is the work cited (another edition or a reprint has its own DOI).";
            elseif(str_contains($checks, 'end unknown'))   $n = "$agree, but CrossRef gives only the first page ({$row['crossref_page']}), not the last. Check that the article runs to $cited.";
            elseif($ref['page'] === '')               $n = "$agree, but no page could be read from the citation. Check that this is the article cited.";
            elseif(str_contains($checks, 'page=first page') || str_contains($checks, 'page=in ')) $n = "$agree, and $cited is in the article's pages ({$row['crossref_page']}).";
            elseif($row['crossref_page'] === '')      $n = "$agree, but CrossRef has no page numbers for this article. Check that it contains $cited.";
            else                                      $n = "$agree, but the page couldn't be confirmed (CrossRef gives \"{$row['crossref_page']}\"). Check that the article contains $cited.";
            if(in_array('journal=unknown', $c['checks']) && $row['crossref_journal'] !== '' && !in_array('journal=in the citation', $c['checks'])) $n .= " The journal name couldn't be compared: check it is \"{$row['crossref_journal']}\".";
            return $n . $unchecked . " Candidate: $cand, \"" . mb_substr($row['crossref_title'], 0, 120) . "\"." . $year_note;
        }
        // not found: why the closest candidate was rejected
        $closest = " Closest CrossRef record: $cand, DOI " . $row['crossref_DOI'] . " -- not the one cited.";
        switch($c['why']) {
            case 'no volume':     $n = "No volume and page could be read from the citation (a book, or an unusual format), so no CrossRef record can be confirmed."; $closest = ''; break;
            case 'journal':       $n = "None of CrossRef's top 5 candidates are from \"{$ref['journal']}\": this journal may not have DOIs in CrossRef."; break;
            case 'not an article':$n = "CrossRef's best candidate is a whole issue or volume, not an article; the article itself wasn't among its top 5 candidates."; break;
            case 'volume':        $n = "CrossRef's top 5 candidates include \"{$row['crossref_journal']}\", but none from volume {$ref['volume']}."; break;
            case 'issue':         $n = "CrossRef's top 5 candidates include \"{$row['crossref_journal']}\" volume {$ref['volume']}, but none from issue {$ref['issue']} with this page."; break;
            case 'year':          $n = "CrossRef's closest candidate is from a different year."; break;
            case 'page':          $n = "$agree, but $cited isn't in the article's pages ({$row['crossref_page']}): the article cited may not be in CrossRef."; break;
            case 'author and title': // AUTHOR/TITLE
                $n = "$agree, but it's another work: the authors differ (the citation has \"{$ref['author']}\", CrossRef has \"{$row['crossref_authors']}\")"
                   . " and CrossRef's title isn't in the citation."; break;
            default:              $n = "No CrossRef record agrees with this citation.";
        }
        return $n . $closest . $this->note_old_work($ref);
    }
    private function author_note($ref, $row, $c)
    {   // AUTHOR/TITLE: what the author check found, when it matters; and another record for the same pages (always)
        $n = '';
        foreach($c['checks'] as $k) {
            if(str_starts_with($k, 'author=nearly')) $n .= " The first author is spelled slightly differently in CrossRef (\"" . substr($k, 15, -1) . "\" for \"{$ref['author']}\").";
            if($k === 'author=differs') $n .= " The authors differ: the citation has \"{$ref['author']}\", CrossRef has \"{$row['crossref_authors']}\". Check that this is the work cited.";
        }
        if(!empty($c['also'])) $n .= " CrossRef also has " . implode(' and ', $c['also']) . " for the same journal, volume and first page: possibly the same work registered twice. Check which DOI to use.";
        return $n;
    }
    private function agreed_text($checks)
    {   // "Journal, volume and year agree", from the checks that really agreed (a year one off counts: the note says so)
        $names = array();
        foreach(array('journal', 'volume', 'issue', 'year') as $k)
            if(in_array("$k=OK", $checks) || ($k == 'year' && in_array('year=next to it', $checks)) || ($k == 'journal' && in_array('journal=in the citation', $checks))) $names[] = $k;
        if(in_array('author=OK', $checks)) $names[] = 'first author'; // AUTHOR/TITLE
        foreach($checks as $k) if(str_starts_with($k, 'title=OK')) $names[] = 'title'; // AUTHOR/TITLE
        if(!$names) return "Nothing could be compared";
        $last = array_pop($names);
        return ucfirst($names ? implode(', ', $names) . " and $last agree" : "$last agrees");
    }
    private function unchecked_text($ref, $checks)
    {   // the year, when it couldn't be compared, so a note never implies it was checked
        foreach($checks as $c) if(str_starts_with($c, 'year=')) return '';
        return $ref['year'] === '' ? " The year couldn't be checked: no year could be read from the citation." : " The year couldn't be checked: CrossRef has no year for this record.";
    }
    private static function words($s)
    {   // lowercase words of 3+ letters, accents removed, e.g. "Ann. Missouri Bot. Gard." -> ann, missouri, bot, gard
        $s = mb_strtolower((string) $s);
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if($t !== false) $s = $t;
        preg_match_all('/[a-z]{3,}/', $s, $m);
        return array_values(array_diff(array_unique($m[0]), array('the', 'and', 'ser', 'des', 'der', 'del', 'for', 'von', 'und', 'zur', 'van')));
    }
    private function write_guide($out_file, $in_file, $start, $last_row, $done, $counts, $changed)
    {   // a plain-text guide next to the report, for the Data Managers
        $guide = preg_replace('/\.tsv$/', '_guide.txt', $out_file);
        $c = $counts;
        $prep = '';
        $at = $this->author_title_checks // AUTHOR/TITLE: said in the guide either way
            ? "  ON for this report. The first author's surname read from the citation (parsed_author) is compared with CrossRef's
  authors (crossref_authors), and CrossRef's title words with the citation:
  - 'checks' shows author=OK / nearly (a small spelling difference, e.g. a scanning error) / differs / unknown, and
    title=OK / partly / not in citation (with the share of CrossRef's title words found in the citation) / unknown.
  - They break ties between candidates whose journal, volume, year and page agree equally.
  - A candidate whose numbers all agree but whose authors clearly differ is 'possible', not 'matched', with a note.
  - Only when BOTH disagree -- the authors differ AND under 30% of CrossRef's title is in the citation -- is a candidate
    rejected ('not found'), e.g. a fish paper whose volume and page happen to match a paper on chickens. One alone is never
    enough: CrossRef's author and title data for old works is often incomplete or garbled, and many citations have no
    title. 'title=not in citation' is not a warning by itself.
  - No volume or pages in the citation (a book, or a style without them, e.g. Vancouver '... Journal. 1917 May'):
    a candidate can still be 'possible' (never 'matched') when nothing differs, the first author agrees (or nearly), at
    least 90% of CrossRef's title is in the citation, and, for a journal article, the journal is named in the citation.
    E.g. old books with DOIs from the Biodiversity Heritage Library (10.5962/bhl.title...). Check the edition.
  - The authors cost one CrossRef call for each candidate that passes the other checks (at most " . self::MAX_AUTHOR_LOOKUPS . " a row, cached),
    so a search can take a little longer than its estimate.
"
            : "  OFF for this report: only journal, volume, issue, year and page were compared (see HOW IT WAS DONE).
";
        $dc = $this->datacite_search // DATACITE: said in the guide either way
            ? "  ON for this report. Rows CrossRef couldn't confirm, where no volume or pages could be read (datasets, reports, theses,
  books), were also searched in DataCite (https://datacite.org), which registers DOIs CrossRef doesn't have, e.g. PANGAEA,
  GBIF and Zenodo datasets (10.1594/..., 10.15468/..., 10.5281/...). A DataCite record is given only when at least 90% of
  its title is in the citation, the first author agrees (or nearly), and the year is within 1 (a cited year inside its title,
  e.g. '... in winter 1995', is allowed). It is at most 'possible'; DOI_source says 'DataCite'. A GBIF copy of a dataset is
  named in the review_note but not given when the original is found. A DOI written in the citation that CrossRef doesn't
  have is looked up in DataCite too.
"
            : "  OFF for this report: only CrossRef was searched.
";
        foreach(self::TEXT_CHANGES as $code => $what) $prep .= str_pad(number_format($changed[$code] ?? 0), 9, ' ', STR_PAD_LEFT) . " row(s): " . wordwrap($what, 100, "\n                   ") . "\n";
        $txt = "DOIs found in CrossRef for references with only a full_reference -- guide for the Data Managers
=============================================================================================
Report : " . basename($out_file) . "
Input  : " . basename($in_file) . " (data rows $start to $last_row; $done searched)
Made   : " . date("Y-m-d H:i") . "

RESULT
  matched   : {$c['matched']}
  possible  : {$c['possible']}
  not found : {$c['not found']}
  skipped   : {$c['skipped']}
  error     : {$c['error']}

BEFORE THE SEARCH (pre-processing)
  Some full_reference texts were cleaned before they were sent to CrossRef. The full_reference column keeps the text
  exactly as in the input file; searched_text shows what was sent; text_changes says what was changed in that row
  (empty = nothing). These are the only changes made; nothing else in the text is touched.
$prep
AUTHOR AND TITLE CHECKS
$at
DATACITE (a second search)
$dc
HOW IT WAS DONE
  Each full_reference (as in searched_text) was searched in CrossRef (the same free-text search as
  https://apps.crossref.org/SimpleTextQuery), limited to the cited year and the years next to it. CrossRef always
  returns candidates, even wrong ones, so each of its top 5 candidates was checked against what could be read from the citation: year, journal, volume, issue and page.
  The parsed_* columns show what was read; the crossref_* columns show the candidate; 'checks' lists each comparison.

WHAT TO DO WITH EACH STATUS
  matched   -> Journal, volume, year and page all agree. The DOI is very likely right. Accept it, unless the
               review_note or the title shows it clearly isn't the work cited.
  possible  -> Journal, volume and year agree, but the page can't be confirmed (CrossRef often gives only an article's
               first page). Open the DOI and check that the article covers the cited page, then accept or reject it.
               Or (author and title checks on): no volume or pages could be read, but the first author and the title
               agree; the review_note says so. Check that it's the work (and edition) cited.
               Watch out: one article often appears for several citations of later pages in the same volume; those
               later pages may belong to a different article.
  not found -> No candidate agrees. The DOI column is left empty on purpose; the closest candidate is described in the
               review_note and crossref_* columns only so you can see why it was rejected. Usually there is no DOI:
               old works (before 1900), books, and many regional journals aren't in CrossRef.
               The Biodiversity Heritage Library (biodiversitylibrary.org) may have these.
  skipped   -> Too short to search.
  error     -> CrossRef couldn't be reached; the search will be redone on the next run.

GOOD TO KNOW
  - Citations here usually give the page where a name was published, not the article's first page; that page is
    checked against the article's page range.
  - A cited year one off from CrossRef's is accepted (cover date vs. publication date); the review_note says so.
  - Whole-issue or whole-volume records are never accepted as the answer.
  - Journal abbreviations are compared word by word with CrossRef's journal title (e.g. \"Ann. Missouri Bot. Gard.\" =
    \"Annals of the Missouri Botanical Garden\"); a different series, e.g. \"Mem. Torrey Bot. Club\" vs
    \"Bulletin of the Torrey Botanical Club\", is rejected.
  - Only CrossRef's top 5 candidates per citation are checked. A 'not found' row can still have a DOI that the free-text
    search didn't rank high enough, especially short citations of a page deep inside a long article: if another row
    of the same journal, volume and pages was matched, that DOI may apply here too.
  - If CrossRef has another record with the same journal, volume and first page, the review_note names it: the same work
    is sometimes registered twice (e.g. once per scan). Check which DOI to use.
  - A DOI written in the citation itself (e.g. 'doi:10.1594/PANGAEA.692046') is checked in CrossRef instead of searching:
    'matched' when CrossRef has it and its title is in the citation; 'possible' when CrossRef doesn't have it (it may be
    registered elsewhere, e.g. with DataCite for PANGAEA datasets, or be mistyped) or its title isn't in the citation.
  - Nothing was changed anywhere: this is a report only.

COLUMNS
  identifier, full_reference : from the input file
  searched_text              : what was sent to CrossRef, after the pre-processing above (the same text when nothing was changed)
  text_changes               : what the pre-processing changed in this row, e.g. removed '(Ref. 3)' at the end; empty = nothing
  status, DOI                : the result; DOI only for matched and possible
  DOI_URL                    : the same DOI as a link (https://doi.org/...), to open it in a browser; empty when DOI is empty
  DOI_source                 : where the DOI was found: CrossRef, DataCite, or citation (written in the citation, checked)
  review_note                : what was found, in plain words
  parsed_*                   : the first author's surname, year, journal, volume, issue and page read from the full_reference
  crossref_*                 : the CrossRef record shown (DOI, score, type, year, journal, volume, issue, page, title, and
                               its authors' surnames when the author check ran)
  checks                     : each comparison, e.g. journal=OK volume=OK year=OK page=in 305-313
";
        file_put_contents($guide, $txt);
        return $guide;
    }
}
?>
