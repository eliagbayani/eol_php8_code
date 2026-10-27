<?php
namespace php_active_record;
/* Library that processes the Zenodo TraitBank datasets and converts it to DwCA
These ff. workspaces work together:
- generate_higherClassification_8.code-workspace
- DHConnLib_8.code-workspace
- GNParserAPI_8.code-workspace
- DwCA_MatchTaxa2DH.code-workspace
- UseEOLidInTaxon.code-workspace
- GenerateCSV_4EOLNeo4j.code-workspace (replaced by below)
- GenerateCSV_NewModel-workspace (new)
- GenerateTB_FilesAPI.code-workspace

----------------------------------------------------
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class ReferenceMaintenanceAPI extends GenerateTB_Files_Functions
{
    function __construct($param) {
        $this->param = $param; print_r($param);
        $this->download_options = array('resource_id' => 'neo4j_tb', 'cache' => 1, 'download_wait_time' => 1000000, 'expire_seconds' => 60*60*24*1, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        $this->debug = array();
        $this->main_datasets = array('Terms_conceptID' => 22776577, 'References_conceptID' => 22776838, 'DH_conceptID' => 22776209);

        // $this->main_datasets['References_conceptID'] this equals to 22776838
        // $dir = DOC_ROOT . $GLOBALS['MAIN_CACHE_PATH'] . 'zenodo/';
        // if(!is_dir($dir)) mkdir($dir);
    }
    private function initialize($concept_id)
    {}
    function start()
    {
        $task = $this->param['task'];
        $basename = 'undefined_referenceIDs_20261027';

        if($task == 'prepare_undefined_referenceIDs_list') {
            // /* Step 1: prepare the source TSV with undefined referenceIDs or identifiers
            $paths = self::get_all_UndefinedRefIDs_files();
            $paths = self::delete_and_leave_only_the_latest_dates($paths);
            if($paths) {
                print_r($paths);
                $tsv = self::save_unique_referenceIDs_2tsv($paths); //TSV with 2 columns: referenceID and concept_id
                echo "\nTSV saved: [$tsv]\n"; //e.g. undefined_referenceIDs_20261023.tsv
            }
            else echo "\nNo undefined referenceIDs recorded on files at the moment.\n";
            // exit("\nend: Step 1\n");
            // */

            // /* Step 2: create a new TSV with additional columns: citation and status
            $source_tsv = $basename.".tsv";
            $target_tsv = $basename."_processed.tsv";
            $ret = self::process_undefined_reference_IDs_file($source_tsv, $target_tsv);
            echo "\nProcessed TSV saved: $ret\n"; //e.g. undefined_referenceIDs_20261023_processed.tsv
            exit("\nend: Steps 1 & 2\n");
            // */
        }

        if($task == 'update_References_from_UndefinedRefIDs_files') {
            // /* Step 3: update the master References file. Add the newly created referenceID and citation to the master: identifier and full_reference.
            $processed_tsv = $basename."_processed.tsv";
            $added = self::update_References_file($processed_tsv);
            echo "\nReferences added: [$added]\n";
            // exit("\nend: Step 3\n");
            // */

            // /* Step 4: save the identifiers with no citation (status not 'OK') to unmappable_identifiers.tsv
            $processed_tsv = $basename."_processed.tsv";
            $added = self::update_unmappable_identifiers($processed_tsv);
            echo "\nUnmappable identifiers added: [$added]\n";
            exit("\nend: Steps 3 & 4\n");
            // */
        }

        if($task == 'refresh_unmappable_identifiers_usingMaster') {
            // /* Step 5: refresh our unmappable_identifiers.tsv
            $removed = self::refresh_unmappable_identifiers_usingMaster();
            echo "\nUnmappable identifiers removed, since master already have them: [$removed]\n";
            // exit("\nend: Step 5\n");
            // */
        }
        if($task == 'refresh_unmappable_identifiers_usingItself') {
            // /* Step 5.1: also refresh our unmappable_identifiers.tsv
            self::refresh_unmappable_identifiers_usingItself();
            // */
        }

        if($task == 'utility_report_duplicate_identifiers_from_master') {
            // /* Step 6: A utility. Report the identifiers that appear more than once in the master References file
            $tsv = self::save_duplicate_identifiers_2tsv();
            echo "\nDuplicate identifiers TSV: [$tsv]\n";
            // exit("\nend: Step 6\n");
            // */
        }

        if($task == 'merge_zenodo_references') {
            // /* Merge the changes in Zenodo's copy of references.tsv (saved beside the folder after a new download) into our local master
            $ret = $this->merge_zenodo_copy_into_local($this->main_datasets['References_conceptID'], 'references.tsv');
            echo "\nMerge result: " . json_encode($ret) . "\n";
            // */
        }
    }
    private function refresh_unmappable_identifiers_usingItself()
    {
        $date = date("Y-m-d");
        $target = 'unmappable_identifiers_fromGH_'.$date.'.tsv';
        $expire_seconds = 60*60*24;
        $expire_seconds = 0;
        $from_GitHub = self::get_file_from_github('unmappable_identifiers.tsv', $target, $expire_seconds);

        $source1 = CONTENT_RESOURCE_LOCAL_PATH . 'unmappable_identifiers.tsv';
        $source2 = $from_GitHub;
        echo "\nsource 1: [$source1]\nsource 2: [$source2]";
        $ret = self::compare_unmappable_identifiers_using_self($source1, $source2);
        print_r($ret);
    }
    private function get_file_from_github($source, $target, $expire_seconds) //e.g. unmappable_identifiers.tsv
    {
        $url = "https://github.com/EOL/unmappable_reference_identifiers/blob/main/".$source;
        $path = Functions::get_github_file($url, array('local_path' => CONTENT_RESOURCE_LOCAL_PATH . $target, 'expire_seconds' => $expire_seconds)); // saves it, returns the path
        echo "\nDownloaded from GitHub: [$path]\n";
        return $path;
        /* usage
        $contents = Functions::get_github_file($url);                                        // returns the contents
        $path     = Functions::get_github_file($url, array('local_path' => CONTENT_RESOURCE_LOCAL_PATH . "eli.tsv")); // saves it, returns the path
        $contents = Functions::get_github_file($url, array('expire_seconds' => 60*60*24));   // download at most once a day
        $contents = Functions::get_github_file($url, array('expire_seconds' => false));      // cache never expires
        $contents = Functions::get_github_file($url);                                        // no cache: always downloads (same as before)
        */
    }
    function get_citation_from_CrossRef($doi)
    {   // e.g. "https://doi.org/10.1126/science.233.4770.1314" (also "10.1126/..." or "doi:10.1126/...") ->
        // "Dacey, J. W. H., & Wakeham, S. G. (1986). Oceanic Dimethylsulfide: Production During Zooplankton Grazing on Phytoplankton. Science, 233(4770), 1314-1316. https://doi.org/10.1126/science.233.4770.1314"
        // APA-like; parts missing in CrossRef are left out. Returns false if the DOI is not found in CrossRef.
        // $this->crossref_status is set to why it failed ("Not a DOI", "CrossRef: no record", ...) or "OK"
        $this->crossref_status = '';
        $doi = urldecode(trim((string) $doi, " \t\"'")); // e.g. "10.1111%2Fj.1756-..." -> "10.1111/j.1756-..."; drop stray quotes
        $doi = preg_replace('#^(https?://(dx\.)?doi\.org/|doi:\s*)#i', '', $doi);
        if(!preg_match('#^10\.\d{4,}/\S+$#', $doi)) { echo "\nNot a DOI: [$doi]\n"; $this->crossref_status = 'Not a DOI'; return false; }

        $options = array('resource_id' => 'crossref', 'expire_seconds' => false, // a published record rarely changes; cache never expires
                         'timeout' => 60, 'download_attempts' => 2, 'download_wait_time' => 3000000,
                         'user_agent' => 'EOL-connector/1.0',
                         'validation_regex' => '"status":"ok"'); // only use cache if it is a real CrossRef record
        $url = "https://api.crossref.org/works/" . rawurlencode($doi);
        if(!($json = Functions::lookup_with_cache($url, $options))) { echo "\nCrossRef: no record for [$doi]\n"; $this->crossref_status = 'CrossRef: no record'; return false; }
        $m = @json_decode($json, true)['message'];
        if(!$m) { $this->crossref_status = 'CrossRef: invalid response'; return false; }
        $clean = fn($str) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $str), ENT_QUOTES | ENT_HTML5, 'UTF-8'))); // titles may have <i>, <sub>, &amp;

        // Authors: "Family, G. N." -- or the organization name
        $authors = array();
        foreach(@$m['author'] ?? array() as $a) {
            if($family = $clean(@$a['family'])) {
                $initials = array();
                foreach(preg_split('/\s+/', $clean(@$a['given']), -1, PREG_SPLIT_NO_EMPTY) as $name) { // "John W. H." -> "J. W. H."; "Jean-Paul" -> "J.-P."
                    $initials[] = implode('-', array_map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)) . '.', explode('-', $name)));
                }
                $authors[] = $initials ? "$family, " . implode(' ', $initials) : $family;
            }
            elseif($name = $clean(@$a['name'])) $authors[] = $name;
        }
        if(count($authors) > 1) $authors_str = implode(', ', array_slice($authors, 0, -1)) . ', & ' . end($authors);
        else                    $authors_str = (string) @$authors[0];

        $year      = @$m['issued']['date-parts'][0][0] ?: @$m['published']['date-parts'][0][0];
        $title     = $clean(@$m['title'][0]);
        $container = $clean(@$m['container-title'][0]); // journal, or the book title for a chapter
        $volume    = $clean(@$m['volume']);
        $issue     = $clean(@$m['issue']);
        $pages     = $clean(@$m['page']);
        $publisher = $clean(@$m['publisher']);
        $type      = (string) @$m['type'];

        // Assemble: Authors (Year). Title. Container, Volume(Issue), Pages. [Publisher.] https://doi.org/DOI
        $parts = array();
        if($authors_str) $parts[] = $authors_str . ($year ? " ($year)." : (str_ends_with($authors_str, '.') ? '' : '.')); // keep the last initial's '.', e.g. "S. G. (1986)."
        elseif($year)    $parts[] = "($year).";
        if($title) $parts[] = rtrim($title, '.') . '.';
        $source = $container;
        if($volume) $source .= ($source ? ', ' : '') . $volume . ($issue ? "($issue)" : '');
        elseif($issue) $source .= ($source ? ', ' : '') . "($issue)";
        if($pages) $source .= ($source ? ', ' : '') . $pages;
        if($source) $parts[] = "$source.";
        if($publisher && in_array($type, array('book', 'book-chapter', 'edited-book', 'monograph', 'reference-book', 'report'))) $parts[] = rtrim($publisher, '.') . '.';
        $parts[] = "https://doi.org/" . (@$m['DOI'] ?: $doi);
        $this->crossref_status = 'OK';
        return implode(' ', $parts);
    }

    private function get_all_UndefinedRefIDs_files()
    {   // e.g. 23073097_UndefinedRefIDs_debug_2026-10-19.txt
        $paths = array();
        foreach(glob(CONTENT_RESOURCE_LOCAL_PATH . "*_UndefinedRefIDs_debug_*.txt") as $path) {
            if(preg_match("/^\d+_UndefinedRefIDs_debug_.*\.txt$/", basename($path))) $paths[] = $path;
        }
        return $paths;
    }
    private function delete_and_leave_only_the_latest_dates($paths)
    {   // per concept_id, keep only the file with the latest date; unlink the rest
        $latest = array(); // concept_id => array('date' => ..., 'path' => ...)
        foreach($paths as $path) {
            if(!preg_match("/^(\d+)_UndefinedRefIDs_debug_(\d{4}-\d{2}-\d{2})\.txt$/", basename($path), $arr)) continue;
            $concept_id = $arr[1]; $date = $arr[2];
            if(!isset($latest[$concept_id]) || $date > $latest[$concept_id]['date']) $latest[$concept_id] = array('date' => $date, 'path' => $path);
        }
        $remaining = array();
        foreach($latest as $rec) $remaining[] = $rec['path'];
        foreach($paths as $path) {
            if(!in_array($path, $remaining) && preg_match("/^\d+_UndefinedRefIDs_debug_\d{4}-\d{2}-\d{2}\.txt$/", basename($path))) unlink($path);
        }
        return $remaining;
    }
    private function save_unique_referenceIDs_2tsv($paths)
    {   // save unique (referenceID, concept_id) pairs to TSV, then prefix processed files with "x_"
        $tsv = CONTENT_RESOURCE_LOCAL_PATH . "undefined_referenceIDs_" . date("Ymd") . ".tsv";
        $unique = array();
        foreach($paths as $path) {
            if(!preg_match("/^(\d+)_UndefinedRefIDs_debug_/", basename($path), $arr)) continue;
            $concept_id = $arr[1];
            $section = ''; $prev = '';
            foreach(file($path, FILE_IGNORE_NEW_LINES) as $line) {
                if(substr($prev, 0, 5) == '=====') $section = trim($line);
                $prev = $line;
                if($section != 'undefined referenceID') continue;
                if(!preg_match("/^----- (.+?)\s+total: \d+/", $line, $arr)) continue;
                $refID = trim($arr[1], " \t\"'"); // drop stray quotes, e.g. '"http://dx.doi.org/...' -- a leading " breaks the TSV in spreadsheet apps
                if($refID == '' || $refID == '#N/A') continue;
                $unique["$refID\t$concept_id"] = '';
            }
        }
        if(file_exists($tsv)) { // append; skip rows already in the file
            foreach(file($tsv, FILE_IGNORE_NEW_LINES) as $row) unset($unique[$row]);
            $fhandle = fopen($tsv, "a");
        }
        else {
            $fhandle = fopen($tsv, "w");
            fwrite($fhandle, "referenceID\tconcept_id\n");
        }
        foreach(array_keys($unique) as $row) fwrite($fhandle, $row . "\n");
        fclose($fhandle);
        foreach($paths as $path) rename($path, dirname($path) . "/x_" . basename($path));
        return $tsv;
    }
    private function process_undefined_reference_IDs_file($source_tsv, $target_tsv)
    {   // source: referenceID, concept_id --> target: referenceID, citation, status (one row per referenceID)
        if(strpos($source_tsv, '/') === false) $source_tsv = CONTENT_RESOURCE_LOCAL_PATH . $source_tsv;
        if(strpos($target_tsv, '/') === false) $target_tsv = CONTENT_RESOURCE_LOCAL_PATH . $target_tsv;
        $fhandle = fopen($target_tsv, "w");
        fwrite($fhandle, "referenceID\tcitation\tstatus\n");
        $done = array(); $stats = array(); $i = 0;
        foreach(file($source_tsv, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            if($line_no == 0) continue; // header
            $refID = trim(explode("\t", $line)[0]);
            if($refID == '' || isset($done[$refID])) continue;
            $done[$refID] = '';
            $citation = self::get_citation_from_CrossRef($refID);
            $status = $this->crossref_status ?: ($citation ? 'OK' : 'Unknown error');
            $citation = $citation ? preg_replace('/[\t\r\n]+/', ' ', $citation) : '';
            fwrite($fhandle, "$refID\t$citation\t$status\n");
            @$stats[$status]++;
            $i++; if(($i % 50) == 0) echo "\nprocessed: $i\n";
        }
        fclose($fhandle);
        echo "\nTotal referenceIDs: $i\n"; print_r($stats);
        return $target_tsv;
    }
    private function update_References_file($processed_tsv)
    {   // append the 'OK' rows of the processed TSV (referenceID, citation, status) to the master references.tsv (identifier, full_reference)
        // skips identifiers already in the master; backs up the master first. Returns no. of rows added, or false.
        if(strpos($processed_tsv, '/') === false) $processed_tsv = CONTENT_RESOURCE_LOCAL_PATH . $processed_tsv;
        if(!is_file($processed_tsv)) { echo "\nERROR: No processed TSV [$processed_tsv]\n"; return false; }
        if(!($references_tsv = $this->get_generic_file_path($this->main_datasets['References_conceptID'], 'references', false))) { echo "\nERROR: No references.tsv\n"; return false; } //3rd param is: $processedYN
        echo "\nReferences Master: [$references_tsv]\n";
        $orig = self::count_data_rows($references_tsv);

        $existing = array();
        foreach(file($references_tsv, FILE_IGNORE_NEW_LINES) as $line) $existing[trim(explode("\t", $line)[0])] = '';
        $rows = array();
        foreach(file($processed_tsv, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            if($line_no == 0) continue; // header
            [$refID, $citation, $status] = array_map('trim', explode("\t", $line) + array('', '', ''));
            if($status != 'OK' || $refID == '' || $citation == '') continue;
            if(isset($existing[$refID])) continue;
            $existing[$refID] = '';
            $rows[] = "$refID\t$citation";
        }
        if(!$rows) { echo "\nNo new references to add.\n"; self::print_row_stats($orig, 0, $orig); return 0; }
        if(!self::append_to_references_master($references_tsv, $rows)) return false;
        self::print_row_stats($orig, count($rows), self::count_data_rows($references_tsv));
        return count($rows);
    }
    private function append_to_references_master($references_tsv, $rows)
    {   // append rows ("identifier\tfull_reference") to the master references.tsv; backs up the master first. Returns true, or false.
        $backup = CONTENT_RESOURCE_LOCAL_PATH . "references_" . $this->main_datasets['References_conceptID'] . "_backup_" . date("Ymd_His") . ".tsv";
        if(!copy($references_tsv, $backup)) { echo "\nERROR: Cannot backup [$references_tsv]\n"; return false; }
        echo "\nBackup: [$backup]\n";

        $eol = "\r\n"; // master uses CRLF line endings
        $fhandle = fopen($references_tsv, "a");
        if(substr(file_get_contents($references_tsv, false, null, -1), -1) !== "\n") fwrite($fhandle, $eol); // last row has no line ending
        foreach($rows as $row) fwrite($fhandle, $row . $eol);
        fclose($fhandle);
        echo "\nUpdated: [$references_tsv]\n";
        return true;
    }
    private function update_unmappable_identifiers($processed_tsv)
    {   // non-'OK' rows of the processed TSV (referenceID, citation, status) --> unmappable_identifiers.tsv (identifier, status, full_reference)
        // full_reference is left blank, to be filled in by hand
        // creates the file if it doesn't exist yet, else appends; skips identifiers already in the file. Returns no. of rows added, or false.
        if(strpos($processed_tsv, '/') === false) $processed_tsv = CONTENT_RESOURCE_LOCAL_PATH . $processed_tsv;
        if(!is_file($processed_tsv)) { echo "\nERROR: No processed TSV [$processed_tsv]\n"; return false; }
        $tsv = CONTENT_RESOURCE_LOCAL_PATH . "unmappable_identifiers.tsv";
        $orig = self::count_data_rows($tsv); // 0 if the file doesn't exist yet
        $existing = array();
        if(file_exists($tsv)) {
            foreach(file($tsv, FILE_IGNORE_NEW_LINES) as $line) $existing[trim(explode("\t", $line)[0])] = '';
        }
        $rows = array();
        foreach(file($processed_tsv, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            if($line_no == 0) continue; // header
            [$refID, $citation, $status] = array_map('trim', explode("\t", $line) + array('', '', ''));
            if($status == 'OK' || $refID == '' || isset($existing[$refID])) continue;
            $existing[$refID] = '';
            $rows[] = "$refID\t$status\t"; // blank full_reference
        }
        if(file_exists($tsv)) $fhandle = fopen($tsv, "a");
        else {
            $fhandle = fopen($tsv, "w");
            fwrite($fhandle, "identifier\tstatus\tfull_reference\n");
        }
        foreach($rows as $row) fwrite($fhandle, $row . "\n");
        fclose($fhandle);
        echo "\nUpdated: [$tsv]\n";
        self::print_row_stats($orig, count($rows), self::count_data_rows($tsv));
        return count($rows);
    }
    private function refresh_unmappable_identifiers_usingMaster()
    {   // remove from unmappable_identifiers.tsv the identifiers that now have a non-blank full_reference in the master references.tsv
        // Returns no. of rows removed, or false.
        $tsv = CONTENT_RESOURCE_LOCAL_PATH . "unmappable_identifiers.tsv";
        if(!is_file($tsv)) { echo "\nNo [$tsv] yet.\n"; return 0; }
        if(!($references_tsv = $this->get_generic_file_path($this->main_datasets['References_conceptID'], 'references', false))) { echo "\nERROR: No references.tsv\n"; return false; } //3rd param is: $processedYN
        echo "\nReferences Master: [$references_tsv]\n";

        $mapped = array(); // identifiers in the master with a full_reference
        foreach(file($references_tsv, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            if($line_no == 0) continue; // header
            [$identifier, $full_reference] = array_map('trim', explode("\t", $line) + array('', '')); // trim also drops the CRLF's \r
            if($identifier != '' && $full_reference != '') $mapped[$identifier] = '';
        }
        $orig = self::count_data_rows($tsv);
        $keep = array(); $removed = array();
        foreach(file($tsv, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            if($line_no == 0) { $keep[] = $line; continue; } // header
            if(trim($line) == '') continue;
            $identifier = trim(explode("\t", $line)[0]);
            if(isset($mapped[$identifier])) $removed[] = $identifier;
            else                            $keep[] = $line;
        }
        if(!$removed) {
            echo "\nNo unmappable identifiers are in the master yet.\n";
            self::print_row_stats($orig, 0, $orig, 'removed');
            return 0;
        }
        $tmp = $tsv . ".tmp"; // write to a temp file first, so a failed write can't leave a half-written TSV
        if(file_put_contents($tmp, implode("\n", $keep) . "\n") === false || !rename($tmp, $tsv)) { echo "\nERROR: Cannot update [$tsv]\n"; return false; }
        echo "\nUpdated: [$tsv]\nRemoved (now in the master):\n" . implode("\n", $removed) . "\n";
        self::print_row_stats($orig, count($removed), self::count_data_rows($tsv), 'removed');
        return count($removed);
    }
    private function compare_unmappable_identifiers_using_self($source1, $source2)
    {   // $source1, $source2: two copies of unmappable_identifiers.tsv (identifier, status, full_reference), e.g. local and from GitHub
        // Step 1: the two files must be byte-identical, else stop
        // Step 2: rows of $source1 with identifier + full_reference --> master references.tsv, if the identifier isn't there yet
        // Step 3: delete from $source1 the rows whose identifier now has a full_reference in the master (copied now, or already there)
        // Returns array('added' => n, 'deleted' => n), or false.
        if(strpos($source1, '/') === false) $source1 = CONTENT_RESOURCE_LOCAL_PATH . $source1;
        if(strpos($source2, '/') === false) $source2 = CONTENT_RESOURCE_LOCAL_PATH . $source2;
        foreach(array($source1, $source2) as $file) {
            if(!is_file($file)) { echo "\nERROR: No file [$file]\n"; return false; }
        }

        // Step 1
        if(file_get_contents($source1) !== file_get_contents($source2)) {
            echo "\nFiles not identical. Please investigate.\n[$source1]\n[$source2]\n";
            echo "Differences (source1 = 1st file, source2 = 2nd file):\n- " . implode("\n- ", self::explain_tsv_difference($source1, $source2)) . "\n";
            return false;
        }
        else echo "\nFiles are identical. Proceeding...\n";

        // Step 2
        if(!($references_tsv = $this->get_generic_file_path($this->main_datasets['References_conceptID'], 'references', false))) { echo "\nERROR: No references.tsv\n"; return false; } //3rd param is: $processedYN
        echo "\nReferences Master: [$references_tsv]\n";
        $master = array(); // identifier => full_reference
        foreach(file($references_tsv, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            if($line_no == 0) continue; // header
            [$identifier, $full_reference] = array_map('trim', explode("\t", $line) + array('', '')); // trim also drops the CRLF's \r
            if($identifier != '' && !isset($master[$identifier])) $master[$identifier] = $full_reference;
        }
        $orig = self::count_data_rows($references_tsv);
        $rows = array(); $copied = array(); $differs = array();
        foreach(file($source1, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            if($line_no == 0) continue; // header
            [$identifier, $status, $full_reference] = array_map('trim', explode("\t", $line) + array('', '', ''));
            if($identifier == '' || $full_reference == '') continue;
            if(isset($master[$identifier])) {
                if(!isset($copied[$identifier]) && $master[$identifier] != $full_reference) $differs[] = $identifier;
                continue;
            }
            $master[$identifier] = $full_reference;
            $copied[$identifier] = '';
            $rows[] = "$identifier\t$full_reference";
        }
        if($rows) {
            if(!self::append_to_references_master($references_tsv, $rows)) return false;
            $final = self::count_data_rows($references_tsv);
        }
        else { echo "\nNo new references to add to the master.\n"; $final = $orig; }
        self::print_row_stats($orig, count($rows), $final);
        if($differs) echo "NOTE: already in the master with a different full_reference (master kept as is):\n" . implode("\n", array_unique($differs)) . "\n";

        // Step 3
        $orig1 = self::count_data_rows($source1);
        $keep = array(); $deleted = array('copied now' => 0, 'already in master' => 0);
        foreach(file($source1, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            if($line_no == 0) { $keep[] = $line; continue; } // header
            $identifier = trim(explode("\t", $line)[0]);
            if($identifier != '' && @$master[$identifier] != '') {
                if(isset($copied[$identifier])) $deleted['copied now']++;
                else                            $deleted['already in master']++;
            }
            else $keep[] = $line;
        }
        $total_deleted = array_sum($deleted);
        if($total_deleted) {
            $tmp = $source1 . ".tmp"; // write to a temp file first, so a failed write can't leave a half-written TSV
            if(file_put_contents($tmp, implode("\n", $keep) . "\n") === false || !rename($tmp, $source1)) { echo "\nERROR: Cannot update [$source1]\n"; return false; }
            echo "\nUpdated: [$source1]\n";
        }
        else echo "\nNo rows to delete from [$source1].\n";
        self::print_row_stats($orig1, $total_deleted, self::count_data_rows($source1), 'deleted');
        echo "(copied now: " . $deleted['copied now'] . ", already in master: " . $deleted['already in master'] . ")\n";
        return array('added' => count($rows), 'deleted' => $total_deleted);
    }
    private function explain_tsv_difference($file1, $file2, $max_examples = 10)
    {   // why two TSV files (1st column = identifier) are not byte-identical. Returns lines of explanation.
        // values are shown JSON-encoded, so tabs and \r are visible, e.g. "FJ\tNot a DOI\t" has a blank 3rd column
        $show = fn($s) => json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $raw = array(1 => file_get_contents($file1), 2 => file_get_contents($file2));
        $why = array(); $lines = array();
        foreach($raw as $k => $str) {
            $info[$k] = array('size' => strlen($str), 'bom' => substr($str, 0, 3) === "\xEF\xBB\xBF",
                              'crlf' => substr_count($str, "\r\n"), 'final_newline' => substr($str, -1) === "\n");
            $str = str_replace("\r\n", "\n", $info[$k]['bom'] ? substr($str, 3) : $str);
            $lines[$k] = ($str === '') ? array() : explode("\n", rtrim($str, "\n"));
        }
        // formatting differences
        $why[] = "file size: " . number_format($info[1]['size']) . " vs " . number_format($info[2]['size']) . " bytes";
        if($info[1]['bom'] != $info[2]['bom'])                     $why[] = "BOM (invisible mark at the start of the file): " . ($info[1]['bom'] ? "source1 only" : "source2 only");
        if(($info[1]['crlf'] > 0) != ($info[2]['crlf'] > 0))       $why[] = "line endings: source1 has {$info[1]['crlf']} CRLF (Windows) lines, source2 has {$info[2]['crlf']}";
        if($info[1]['final_newline'] != $info[2]['final_newline']) $why[] = "line ending after the last row: " . ($info[1]['final_newline'] ? "source1 only" : "source2 only");
        if($lines[1] === $lines[2]) { $why[] = "the rows themselves are the same; only the formatting above differs"; return $why; }

        // content differences
        $why[] = "rows (incl. header): " . count($lines[1]) . " vs " . count($lines[2]);
        if(@$lines[1][0] !== @$lines[2][0]) $why[] = "header: " . $show(@$lines[1][0]) . " vs " . $show(@$lines[2][0]);
        $rows = array(1 => array(), 2 => array());
        foreach($lines as $k => $arr) {
            foreach(array_slice($arr, 1) as $line) {
                $id = trim(explode("\t", $line)[0]);
                if(isset($rows[$k][$id])) $why[] = "identifier listed more than once in source$k: " . $show($id);
                else $rows[$k][$id] = $line;
            }
        }
        $only1 = array_diff_key($rows[1], $rows[2]);
        $only2 = array_diff_key($rows[2], $rows[1]);
        $changed = array();
        foreach(array_intersect_key($rows[1], $rows[2]) as $id => $line) if($line !== $rows[2][$id]) $changed[$id] = $line;
        $examples = fn($arr) => implode("", array_map(fn($line) => "\n    " . $show($line), array_slice($arr, 0, $max_examples))) . (count($arr) > $max_examples ? "\n    ..." : '');
        if($only1) $why[] = count($only1) . " row(s) only in source1:" . $examples($only1);
        if($only2) $why[] = count($only2) . " row(s) only in source2:" . $examples($only2);
        if($changed) {
            $str = count($changed) . " row(s) with the same identifier but different values (source1 / source2):";
            foreach(array_slice($changed, 0, $max_examples, true) as $id => $line) {
                $n1 = count(explode("\t", $line)); $n2 = count(explode("\t", $rows[2][$id]));
                $str .= "\n    " . $show($line) . "\n    " . $show($rows[2][$id]) . ($n1 != $n2 ? "   <-- $n1 vs $n2 columns" : '');
            }
            $why[] = $str . (count($changed) > $max_examples ? "\n    ..." : '');
        }
        if(!$only1 && !$only2 && !$changed && array_keys($rows[1]) !== array_keys($rows[2])) $why[] = "same rows, but in a different order";
        return $why;
    }
    private function save_duplicate_identifiers_2tsv()
    {   // rows of the master references.tsv whose identifier appears more than once --> references_duplicate_identifiers_YYYYMMDD.tsv
        // columns: identifier, full_reference, master_row (line no. in the master, header = 1); sorted by identifier, then master_row
        // a snapshot report: overwrites the same day's file. Returns the TSV path, or false.
        if(!($references_tsv = $this->get_generic_file_path($this->main_datasets['References_conceptID'], 'references', false))) { echo "\nERROR: No references.tsv\n"; return false; } //3rd param is: $processedYN
        echo "\nReferences Master: [$references_tsv]\n";

        $recs = array(); $count = array();
        foreach(file($references_tsv, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            if($line_no == 0) continue; // header
            $tmp = explode("\t", $line);
            $identifier = trim($tmp[0]);
            if($identifier == '') continue; // blank identifier is not a duplicate
            $full_reference = rtrim((string) @$tmp[1], "\r"); // master uses CRLF line endings
            $recs[] = array($identifier, $full_reference, $line_no + 1);
            @$count[$identifier]++;
        }
        $dups = array_values(array_filter($recs, fn($r) => $count[$r[0]] > 1));
        usort($dups, fn($a, $b) => strcmp($a[0], $b[0]) ?: $a[2] <=> $b[2]);

        $tsv = CONTENT_RESOURCE_LOCAL_PATH . "references_duplicate_identifiers_" . date("Ymd") . ".tsv";
        $rows = array("identifier\tfull_reference\tmaster_row");
        foreach($dups as $r) $rows[] = implode("\t", $r);
        $tmp = $tsv . ".tmp"; // write to a temp file first, so a failed write can't leave a half-written TSV
        if(file_put_contents($tmp, implode("\n", $rows) . "\n") === false || !rename($tmp, $tsv)) { echo "\nERROR: Cannot write [$tsv]\n"; return false; }
        $ids = count(array_unique(array_column($dups, 0)));
        echo "\nSaved: [$tsv]\nduplicate identifiers: " . number_format($ids) . "\nrows involved: " . number_format(count($dups)) . "\n";
        return $tsv;
    }
    private function count_data_rows($file)
    {   // no. of non-blank rows, excluding the header; 0 if the file doesn't exist
        if(!is_file($file)) return 0;
        $n = 0;
        foreach(file($file, FILE_IGNORE_NEW_LINES) as $line) if(trim($line) !== '') $n++;
        return max($n - 1, 0);
    }
    private function print_row_stats($orig, $changed, $final, $label = 'added')
    {   // $label: 'added', or 'removed' / 'deleted'
        $minus = in_array($label, array('removed', 'deleted'));
        echo "orig total rows: "  . number_format($orig)    . "\n";
        echo "$label rows: "      . number_format($changed) . "\n";
        echo "final total rows: " . number_format($final)   . "\n";
        $expected = $minus ? $orig - $changed : $orig + $changed;
        if($expected != $final) echo "WARNING: orig " . ($minus ? '-' : '+') . " $label != final\n";
    }
    private function upload_file_toZenodo()
    {
        Usage, e.g. from ReferenceMaintenanceAPI:
        $concept_id = $this->main_datasets['References_conceptID'];   // 22776838
        $file = $this->get_generic_file_path($concept_id, 'references', false);
        $r = $this->upload_new_version_to_zenodo($concept_id, $file);                                            // draft only, for review
        $r = $this->upload_new_version_to_zenodo($concept_id, $file, array('publish' => true, 'version' => date("Y-m-d"))); // publish now        
    }
}
?>