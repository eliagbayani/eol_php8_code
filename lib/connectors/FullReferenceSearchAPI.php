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
    const OUTPUT_COLUMNS = array('identifier', 'full_reference', 'status', 'DOI', 'review_note',
                                 'parsed_year', 'parsed_journal', 'parsed_volume', 'parsed_issue', 'parsed_page',
                                 'crossref_DOI', 'crossref_score', 'crossref_type', 'crossref_year', 'crossref_journal',
                                 'crossref_volume', 'crossref_issue', 'crossref_page', 'crossref_title', 'checks');
    const MIN_LENGTH = 10; // shorter full_reference values aren't searched
    const PROGRESS_EVERY = 20; // a progress line ("N rows done ...") every this many rows; the web page reads it
    const KEEP_REPORTS = 20;   // only the newest 20 reports (each with its _guide.txt) are kept in Reference_maintenance/; older ones are deleted
    const REPORT_RE = '/^[A-Za-z0-9._-]+_DOIs_(\d{8}_\d{6})\.tsv$/'; // e.g. reference_DOIs_20261114_013451.tsv; the time it was made sorts them
    // CrossRef record types that are a whole issue/volume/journal or a part of an article, not an article: never the answer
    const NOT_AN_ARTICLE = array('journal-issue', 'journal-volume', 'journal', 'component', 'proceedings', 'book-series', 'book-set', 'report-series');
    function __construct($param) {
        $this->param = $param; print_r($param);
        $this->work_dir = CONTENT_RESOURCE_LOCAL_PATH . "Reference_maintenance/";
        if(!is_dir($this->work_dir)) @mkdir($this->work_dir, 0777, true);
        // CrossRef's "polite pool" (twice the rate limit) needs an email: optional constant CROSSREF_MAILTO in config/environments/<env>.php (not in GitHub).
        // It goes in the User-Agent, not the URL, so the cache doesn't depend on it.
        $user_agent = 'EOL-connector/1.0' . (defined('CROSSREF_MAILTO') && CROSSREF_MAILTO ? ' (mailto:' . CROSSREF_MAILTO . ')' : '');
        $this->crossref_options = array('resource_id' => 'crossref', 'expire_seconds' => false, // never expires: the same full_reference is never asked twice
                                        'timeout' => 60, 'download_attempts' => 3, 'download_wait_time' => 500000, // 0.5 second between calls
                                        'user_agent' => $user_agent,
                                        'validation_regex' => '"status":"ok"'); // a failed call isn't kept as a valid cache
    }
    function start()
    {   // Runs the task; also returns its result.
        $task = $this->param['task'] ?? '';
        // one run at a time: a second run waits here until the first one is done. Released when this function returns.
        $lock = @fopen($this->work_dir . ".full_reference_search.lock", 'c');
        if($lock) { @chmod($this->work_dir . ".full_reference_search.lock", 0666); flock($lock, LOCK_EX); }
        $result = null;
        if($task == 'search_DOIs_for_full_references') $result = $this->search_DOIs_for_full_references();
        else echo "\nERROR: Unknown task [$task]. Available: search_DOIs_for_full_references\n";
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
        $time = time(); $row_no = 0; $done = 0;
        while(($line = fgets($in)) !== false) {
            $line = rtrim($line, "\r\n");
            if(trim($line) === '') continue;
            $row_no++;
            if($row_no < $start) continue;
            if($limit && $done >= $limit) break;
            $f = explode("\t", $line);
            $identifier = (string) ($f[$col_id] ?? ''); $full_reference = trim((string) ($f[$col_ref] ?? ''));
            $r = $this->find_DOI($full_reference);
            $counts[$r['status']]++; $done++;
            $row = array_merge(array('identifier' => $identifier, 'full_reference' => $full_reference), $r);
            fwrite($out, implode("\t", array_map(fn($c) => str_replace(array("\t", "\r", "\n"), ' ', (string) ($row[$c] ?? '')), self::OUTPUT_COLUMNS)) . "\n");
            if($done % self::PROGRESS_EVERY == 0) { fflush($out); echo "  $done rows done (row $row_no): " . self::counts_str($counts) . "\n"; }
        }
        fclose($in); fclose($out);
        $guide = $this->write_guide($out_file, $file, $start, $row_no, $done, $counts);
        echo "\nDone: $done rows in " . gmdate("H:i:s", time() - $time) . " -- " . self::counts_str($counts) . "\nReport: [$out_file]\nGuide for the Data Managers: [$guide]\n";
        return array('file' => $out_file, 'guide' => $guide, 'counts' => $counts);
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
    {   // Searches CrossRef for one full_reference and checks its candidates. Returns the OUTPUT_COLUMNS from 'status' on.
        $ref = $this->parse_full_reference($full_reference);
        $row = array_fill_keys(array_slice(self::OUTPUT_COLUMNS, 2), '');
        foreach(array('year', 'journal', 'volume', 'issue') as $k) $row["parsed_$k"] = $ref[$k];
        $row['parsed_page'] = $ref['page'] . ($ref['page_end'] !== '' ? '-' . $ref['page_end'] : '');
        if(mb_strlen($full_reference) < self::MIN_LENGTH) return array_merge($row, array('status' => 'skipped', 'review_note' => 'Too short to search.'));
        $items = $this->search_crossref($full_reference, $ref['year']);
        if($items === false) return array_merge($row, array('status' => 'error', 'review_note' => 'CrossRef could not be reached. Run again: this row will be searched again.'));
        if(!$items) return array_merge($row, array('status' => 'not found', 'review_note' => $this->note_no_candidates($ref)));

        // the best candidate: matched > possible > not found; then the most checks that agree; then CrossRef's order (best score first)
        $rank = array('matched' => 2, 'possible' => 1, 'not found' => 0); $best = null;
        foreach($items as $i => $item) {
            $c = $this->check_candidate($ref, $item);
            $key = array($rank[$c['status']], $c['agree'], -$i);
            if(!$best || $key > $best['key']) $best = array('key' => $key, 'c' => $c, 'item' => $item);
        }
        $c = $best['c']; $item = $best['item'];
        $clean = fn($s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $row = array_merge($row, array('status' => $c['status'], 'DOI' => $c['status'] == 'not found' ? '' : (string) @$item['DOI'],
                     'crossref_DOI' => (string) @$item['DOI'], 'crossref_score' => round((float) @$item['score'], 1), 'crossref_type' => (string) @$item['type'],
                     'crossref_year' => self::item_year($item), 'crossref_journal' => $clean(@$item['container-title'][0]),
                     'crossref_volume' => $clean(@$item['volume']), 'crossref_issue' => $clean(@$item['issue']), 'crossref_page' => $clean(@$item['page']),
                     'crossref_title' => $clean(@$item['title'][0]), 'checks' => implode(' ', $c['checks'])));
        $row['review_note'] = $this->review_note($ref, $row, $c);
        return $row;
    }
    function parse_full_reference($s)
    {   /* Reads the year, journal, volume, issue and page(s) from a full_reference, e.g.
             "1998. Brittonia,50(1): 59,1998."                                     -> 1998, Brittonia, 50, 1, 59
             "Saint-Hilaire, A. & Tulasne. 1842. Ann. Sci. Nat., Bot.,ser. 2, 17: 138,1842." -> 1842, "Ann. Sci. Nat., Bot.,ser. 2", 17, '', 138
             "Bartoli, A. ... 1999. Revisión ... (Asteraceae: Astereae).,Kurtziana 27(3):327-359.,1999." -> 1999, Kurtziana, 27, 3, 327-359
           Anything it can't read is left blank, and that check is skipped. */
        $ref = array('year' => '', 'journal' => '', 'volume' => '', 'issue' => '', 'page' => '', 'page_end' => '');
        if(preg_match('/\b(1[5-9]\d\d|20\d\d)\b/', $s, $m, PREG_OFFSET_CAPTURE)) { $ref['year'] = $m[1][0]; $year_end = $m[1][1] + 4; }
        // the last "VOLUME[(ISSUE)]: PAGE[-PAGE]", e.g. "50(1): 59", "17: 138", "27(3):327-359"
        if(preg_match_all('/(?<![\w.])(\d{1,4})\s*(?:\(([^()]{1,20})\))?\s*:\s*(\d{1,5})(?:\s*-\s*(\d{1,5}))?/u', $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $m = end($all);
            $ref['volume'] = $m[1][0]; $ref['issue'] = trim((string) @$m[2][0]); $ref['page'] = $m[3][0]; $ref['page_end'] = (string) @$m[4][0];
            if($ref['volume'] === $ref['year']) $ref['volume'] = ''; // e.g. "Mem. Math. Phys. Inst. Par.,1811: 82" -> the year, not a volume
            if(isset($year_end) && $m[0][1] > $year_end) { // journal: between the year and the volume; in "Title.,Journal 27(3)" the part after the last ".,"
                $j = trim(substr($s, $year_end, $m[0][1] - $year_end), " .,;"); // e.g. ". Kew Bull.," -> "Kew Bull"
                if(($p = strrpos($j, '.,')) !== false) $j = substr($j, $p + 2);
                $ref['journal'] = trim($j, " .,;");
            }
        }
        return $ref;
    }
    private function search_crossref($full_reference, $year)
    {   // the top 5 CrossRef candidates (best score first), only from the year and the years next to it when a year was read. false = CrossRef unreachable.
        $q = array('query.bibliographic' => $full_reference, 'rows' => 5, 'select' => 'DOI,score,title,container-title,volume,issue,page,issued,published,type');
        if($year) $q['filter'] = "from-pub-date:" . ($year - 1) . ",until-pub-date:" . ($year + 1);
        $url = "https://api.crossref.org/works?" . http_build_query($q);
        if(!($json = Functions::lookup_with_cache($url, $this->crossref_options))) return false;
        $d = @json_decode($json, true);
        if(($d['status'] ?? '') !== 'ok') return false;
        return $d['message']['items'] ?? array();
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
        $checks = array(); $ok = true; $why = '';
        $fail = function($check, $reason) use (&$checks, &$ok, &$why) { $checks[] = $check; if($ok) $why = $reason; $ok = false; };
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
            if($num($ref['volume']) === $num(@$item['volume'])) $checks[] = 'volume=OK';
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
        if(!$ok) return array('status' => 'not found', 'checks' => $checks, 'agree' => $agree, 'why' => $why);
        $status = ($page_ok && $journal === true) ? 'matched' : 'possible'; // 'matched' needs the journal and the page confirmed
        return array('status' => $status, 'checks' => $checks, 'agree' => $agree, 'why' => '');
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
        $cand = "\"" . $row['crossref_journal'] . "\" " . $row['crossref_volume'] . ($row['crossref_issue'] !== '' ? "(" . $row['crossref_issue'] . ")" : '')
              . ($row['crossref_page'] !== '' ? ": " . $row['crossref_page'] : '') . " (" . $row['crossref_year'] . ")";
        $cited = "page " . $ref['page'];
        $year_note = in_array('year=next to it', $c['checks']) ? " The cited year ({$ref['year']}) is one off CrossRef's ({$row['crossref_year']}), which is common (cover date vs. publication date)." : '';
        if($c['status'] == 'matched') {
            return "Journal, volume, year and page agree: $cited is in $cand, \"" . mb_substr($row['crossref_title'], 0, 120) . "\"." . $year_note
                 . " Accept, unless the article clearly isn't the one cited.";
        }
        if($c['status'] == 'possible') {
            $checks = implode(' ', $c['checks']);
            if(str_contains($checks, 'end unknown'))   $n = "Journal, volume and year agree, but CrossRef gives only the first page ({$row['crossref_page']}), not the last. Check that the article runs to $cited.";
            elseif($ref['page'] === '')               $n = "Journal, volume and year agree, but no page could be read from the citation. Check that this is the article cited.";
            else                                      $n = "Journal, volume and year agree, but CrossRef has no page numbers for this article. Check that it contains $cited.";
            if(in_array('journal=unknown', $c['checks'])) $n = str_replace('Journal, volume', 'Volume', $n) . " The journal name couldn't be compared: check it is \"{$row['crossref_journal']}\".";
            return $n . " Candidate: $cand, \"" . mb_substr($row['crossref_title'], 0, 120) . "\"." . $year_note;
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
            case 'page':          $n = "Journal, volume and year agree, but $cited isn't in the article's pages ({$row['crossref_page']}): the article cited may not be in CrossRef."; break;
            default:              $n = "No CrossRef record agrees with this citation.";
        }
        return $n . $closest . $this->note_old_work($ref);
    }
    private static function words($s)
    {   // lowercase words of 3+ letters, accents removed, e.g. "Ann. Missouri Bot. Gard." -> ann, missouri, bot, gard
        $s = mb_strtolower((string) $s);
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if($t !== false) $s = $t;
        preg_match_all('/[a-z]{3,}/', $s, $m);
        return array_values(array_diff(array_unique($m[0]), array('the', 'and', 'ser', 'des', 'der', 'del', 'for', 'von', 'und', 'zur', 'van')));
    }
    private function write_guide($out_file, $in_file, $start, $last_row, $done, $counts)
    {   // a plain-text guide next to the report, for the Data Managers
        $guide = preg_replace('/\.tsv$/', '_guide.txt', $out_file);
        $c = $counts;
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

HOW IT WAS DONE
  Each full_reference was searched in CrossRef (the same free-text search as https://apps.crossref.org/SimpleTextQuery),
  limited to the cited year and the years next to it. CrossRef always returns candidates, even wrong ones, so each of its
  top 5 candidates was checked against what could be read from the citation: year, journal, volume, issue and page.
  The parsed_* columns show what was read; the crossref_* columns show the candidate; 'checks' lists each comparison.

WHAT TO DO WITH EACH STATUS
  matched   -> Journal, volume, year and page all agree. The DOI is very likely right. Accept it, unless the
               review_note or the title shows it clearly isn't the work cited.
  possible  -> Journal, volume and year agree, but the page can't be confirmed (CrossRef often gives only an article's
               first page). Open the DOI and check that the article covers the cited page, then accept or reject it.
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
  - Nothing was changed anywhere: this is a report only.

COLUMNS
  identifier, full_reference : from the input file
  status, DOI                : the result; DOI only for matched and possible
  review_note                : what was found, in plain words
  parsed_*                   : year, journal, volume, issue and page read from the full_reference
  crossref_*                 : the CrossRef record shown (DOI, score, type, year, journal, volume, issue, page, title)
  checks                     : each comparison, e.g. journal=OK volume=OK year=OK page=in 305-313
";
        file_put_contents($guide, $txt);
        return $guide;
    }
}
?>
