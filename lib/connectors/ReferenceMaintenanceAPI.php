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
    {   // Runs the task; also returns its result (used by applications/reference_maintenance/main.php). What each task prints is unchanged.
        $task = $this->param['task'];
        // one run at a time (command line or web page): a second run waits here until the first one is done. Released when this function returns.
        $lock_dir = CONTENT_RESOURCE_LOCAL_PATH . "Reference_maintenance/";
        if(!is_dir($lock_dir)) @mkdir($lock_dir, 0777, true);
        $lock = @fopen($lock_dir . ".reference_mtce.lock", 'c');
        if($lock) { @chmod($lock_dir . ".reference_mtce.lock", 0666); flock($lock, LOCK_EX); }
        $result = null;
        // the undefined_referenceIDs_YYYYMMDD.tsv used by steps 2, 3 & 4: the one given ("basename": "undefined_referenceIDs_20261106"),
        // else the newest one in CONTENT_RESOURCE_LOCAL_PATH (step 1 below makes today's). No longer typed in here by hand.
        $basename = self::undefined_referenceIDs_basename();

        if($task == 'prepare_undefined_referenceIDs_list') {
            // /* Step 1: prepare the source TSV with undefined referenceIDs or identifiers
            $paths = self::get_all_UndefinedRefIDs_files();
            $paths = self::delete_and_leave_only_the_latest_dates($paths);
            if($paths) {
                print_r($paths);
                $tsv = self::save_unique_referenceIDs_2tsv($paths); //TSV with 2 columns: referenceID and concept_id
                echo "\nTSV saved: [$tsv]\n"; //e.g. undefined_referenceIDs_20261023.tsv
                $basename = pathinfo($tsv, PATHINFO_FILENAME); // step 2 uses the file just made
            }
            else echo "\nNo undefined referenceIDs recorded on files at the moment.\n";
            // exit("\nend: Step 1\n");
            // */

            // /* Step 2: create a new TSV with additional columns: citation and status
            if(!$basename) exit("\nERROR: No undefined_referenceIDs_YYYYMMDD.tsv to process.\n");
            echo "\nStep 2 uses: [$basename.tsv]\n";
            $source_tsv = $basename.".tsv";
            $target_tsv = $basename."_processed.tsv";
            $ret = self::process_undefined_reference_IDs_file($source_tsv, $target_tsv); //this is the one that calls CrossRef
            echo "\nProcessed TSV saved: $ret\n"; //e.g. undefined_referenceIDs_20261023_processed.tsv
            exit("\nend: Steps 1 & 2\n");
            // */
        }

        if($task == 'update_References_from_UndefinedRefIDs_files') {
            // /* Step 3: update the master References file. Add the newly created referenceID and citation to the master: identifier and full_reference.
            if(!$basename) exit("\nERROR: No undefined_referenceIDs_YYYYMMDD.tsv found. Run prepare_undefined_referenceIDs_list first.\n");
            echo "\nSteps 3 & 4 use: [{$basename}_processed.tsv]\n";
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
            $removed = $result = self::refresh_unmappable_identifiers_usingMaster();
            echo "\nUnmappable identifiers removed, since master already have them: [$removed]\n";
            // exit("\nend: Step 5\n");
            // */
        }
        if($task == 'refresh_unmappable_identifiers_usingItself') {
            // /* Step 5.1: also refresh our unmappable_identifiers.tsv
            self::refresh_unmappable_identifiers_usingItself();
            // */
        }
        if($task == 'refresh_unmappable_identifiers_usingDatasets') {
            // /* Step 5.2: remove from unmappable_identifiers.tsv the identifiers no TraitBank dataset cites any more, in /referenceID or /source (local copies, must be up to date)
            $removed = $result = self::refresh_unmappable_identifiers_usingDatasets();
            if($removed !== false) echo "\nUnmappable identifiers removed, since no dataset cites them any more: [$removed]\n";
            // */
        }
        if($task == 'sync_unmappable_identifiers_with_datasets') {
            // /* One step, reading the datasets directly: add the cited IDs (/referenceID or /source) the master doesn't have; remove those now in the master
            //    or no longer cited. Optional "crossref_YN": 1 (look the new ones up in CrossRef first), "dry_run_YN": 1 (report only)
            $ret = $result = self::sync_unmappable_identifiers_with_datasets(null, !empty($this->param['dry_run_YN']), !empty($this->param['crossref_YN']));
            if($ret !== false) echo "\nSync result: " . json_encode($ret) . "\n";
            // */
        }

        if($task == 'utility_report_duplicate_identifiers_from_master') {
            // /* Step 6: A utility. Report the identifiers that appear more than once in the master References file
            $tsv = $result = self::save_duplicate_identifiers_2tsv();
            echo "\nDuplicate identifiers TSV: [$tsv]\n";
            // exit("\nend: Step 6\n");
            // */
        }

        if($task == 'upload_file_toZenodo') {
            // /* Upload our local file of a dataset to Zenodo as a new version. Draft only, unless "publish": 1
            $ret = $result = $this->upload_file_toZenodo();
            if($ret) echo "\nUpload result: " . json_encode($ret) . "\n";
            // */
        }

        if($task == 'compare_master_with_zenodo') {
            // /* Report only, changes nothing: is our local master references.tsv the same as the one in Zenodo's latest version?
            $ret = $result = self::compare_master_with_zenodo();
            if($ret) echo "\nCompare result: " . json_encode($ret) . "\n";
            // */
        }

        if($task == 'merge_zenodo_references') {
            // /* Merge the changes in Zenodo's copy of references.tsv (saved beside the folder after a new download) into our local master
            $ret = $result = $this->merge_zenodo_copy_into_local($this->main_datasets['References_conceptID'], 'references.tsv');
            echo "\nMerge result: " . json_encode($ret) . "\n";
            // */
        }
        if($task == 'sync_zenodo_references') {
            // /* Sync merge: apply Zenodo's additions, changes AND deletions (since the base) to our local master; our own additions are kept
            $ret = $result = $this->sync_zenodo_copy_into_local($this->main_datasets['References_conceptID'], 'references.tsv', null, !empty($this->param['dry_run_YN']));
            echo "\nSync result: " . json_encode($ret) . "\n";
            // */
        }
        if($task == 'set_zenodo_base_references') {
            // /* One-time setup of the sync merge: the newest Zenodo copy becomes the base. Only if it has nothing our master doesn't have.
            $result = self::set_zenodo_base_references();
            // */
        }
        if($task == 'update_master_references_with_datamanager_supplied_file_of_unmappable_identifiers') {
            // /* Data manager's filled-in unmappable list -> master references.tsv (+ GitHub copy, Zenodo upload) -> remove them from unmappable_identifiers.tsv (+ GitHub copy)
            if(empty($this->param['file'])) echo "\nERROR: \"file\" is required: the path of the data manager's TSV\n";
            else {
                $ret = $result = self::update_master_with_datamanager_file($this->param['file'], !empty($this->param['dry_run_YN']), null, !empty($this->param['skip_unmappable_update_YN']), !empty($this->param['replace_existing_YN']));
                echo "\nResult: " . json_encode($ret) . "\n";
            }
            // */
        }
        if($task == 'fix_duplicate_issue_in_master_references') {
            // /* Delete from the master the rows the Data Manager listed (identifier, full_reference, master_row) -> GitHub copy -> Zenodo upload
            if(empty($this->param['file'])) echo "\nERROR: \"file\" is required: the path of the data manager's TSV\n";
            else {
                $ret = $result = self::remove_master_rows_from_datamanager_file($this->param['file'], !empty($this->param['dry_run_YN']));
                echo "\nResult: " . json_encode($ret) . "\n";
            }
            // */
        }
        return $result;
    }
    private function upload_references_master()
    {   // uploads our master references.tsv to Zenodo with upload_file_toZenodo(): draft only, unless $this->param['publish']. Returns its result, or false.
        // $this->param['no_upload_YN']: no upload, e.g. when another change follows and one upload should carry both
        if(!empty($this->param['no_upload_YN'])) {
            echo "\nNo upload to Zenodo (no_upload_YN). Upload the master later, e.g. by the next task without no_upload_YN, or:\n"
               . "php do_reference_mtce.php _ '{\"task\": \"upload_file_toZenodo\", \"concept_id\": \"" . $this->main_datasets['References_conceptID'] . "\"}'\n";
            return false;
        }
        echo "\nUploading the master to Zenodo" . (empty($this->param['publish']) ? " (draft only)" : " (PUBLISH)") . "...\n";
        $this->param['concept_id'] = (string) $this->main_datasets['References_conceptID'];
        $dm_param = $this->param['file'] ?? null;
        unset($this->param['file']); // 'file' here is the data manager's TSV; for upload_file_toZenodo() it would be the file to upload -> use its default, references.tsv
        $uploaded = $this->upload_file_toZenodo();
        if($dm_param !== null) $this->param['file'] = $dm_param;
        if(!$uploaded) echo "\nNOTE: the master was updated, but NOT uploaded to Zenodo. Upload it later:\n"
                          . "php do_reference_mtce.php _ '{\"task\": \"upload_file_toZenodo\", \"concept_id\": \"{$this->param['concept_id']}\"}'\n";
        return $uploaded;
    }
    private function remove_master_rows_from_datamanager_file($dm_file, $dry_run = false)
    {   /* $dm_file: the data manager's list of master rows to delete (identifier, full_reference, master_row), e.g. picked from our duplicates report
        Step 1: a master row is deleted if its identifier and full_reference are exactly those in $dm_file, at row master_row (header = row 1).
                If master_row is blank, the identifier + full_reference match is enough: every master row with that exact pair is deleted.
        Step 2: the master is copied to the GitHub folder
        Step 3: the master is uploaded to Zenodo: draft only, unless $this->param['publish']
        $dry_run: report step 1 only, change nothing.
        Returns array('deleted', 'skipped', 'still_duplicated', 'uploaded'), or false. */
        if(!is_file($dm_file)) { echo "\nERROR: No file [$dm_file]\n"; return false; }
        $lines = explode("\n", file_get_contents($dm_file));
        $header = array_map('trim', explode("\t", (string) $lines[0]));
        if($header !== array('identifier', 'full_reference', 'master_row')) { echo "\nERROR: [$dm_file] header must be: identifier, full_reference, master_row\n"; return false; }
        echo "\nData manager's file: [$dm_file]\n";
        if(!($references_tsv = $this->get_generic_file_path($this->main_datasets['References_conceptID'], 'references', false))) { echo "\nERROR: No references.tsv\n"; return false; } //3rd param is: $processedYN
        echo "\nReferences Master: [$references_tsv]\n";

        // Step 1
        $master = explode("\n", file_get_contents($references_tsv)); // each line keeps its own \r (header LF, rows CRLF), so what's kept is written back as is
        $rows_of = array(); // "identifier\tfull_reference" => master rows (header = 1)
        foreach($master as $i => $line) if($i > 0 && rtrim($line, "\r") !== '') $rows_of[rtrim($line, "\r")][] = $i + 1;
        $delete = array(); $skipped = array(); $notes = array();
        foreach($lines as $n => $line) {
            $line = rtrim($line, "\r");
            if($n == 0 || trim($line) == '') continue; // header
            $c = explode("\t", $line);
            $pair = $c[0] . "\t" . ($c[1] ?? '');
            $row = trim($c[2] ?? '');
            $what = "line " . ($n + 1) . " [{$c[0]}]";
            if($row === '') { // no master_row: the exact identifier + full_reference is enough
                if(empty($rows_of[$pair])) { $skipped[] = "$what: not in the master"; continue; }
                foreach($rows_of[$pair] as $r) if(!isset($delete[$r])) $delete[$r] = "$what (no master_row)";
                $notes[] = "$what had no master_row: matched at row " . implode(', ', $rows_of[$pair]) . (count($rows_of[$pair]) > 1 ? " (all its copies)" : '');
                continue;
            }
            if(!ctype_digit($row))                  { $skipped[] = "$what: bad master_row [$row]"; continue; }
            $row = (int) $row;
            if($row < 2 || $row > count($master))   { $skipped[] = "$what: row $row out of range"; continue; }
            if(isset($delete[$row]))                { $skipped[] = "$what: row $row listed twice"; continue; }
            if(rtrim($master[$row - 1], "\r") === $pair) { $delete[$row] = $what; continue; }
            $elsewhere = $rows_of[$pair] ?? array();
            $skipped[] = "$what: doesn't match the master at row $row" . ($elsewhere ? " (that row is at " . implode(', ', $elsewhere) . ")" : " (not in the master)");
        }
        $count = array(); // identifiers still on more than one row after deletion
        foreach($master as $i => $line) {
            if($i == 0 || isset($delete[$i + 1]) || rtrim($line, "\r") === '') continue;
            $id = trim(explode("\t", $line)[0]);
            if($id != '') @$count[$id]++;
        }
        $still = array_keys(array_filter($count, fn($k) => $k > 1));
        echo "\n" . ($dry_run ? "DRY RUN, nothing is changed.\nWould be deleted" : "To be deleted") . " from master: " . count($delete) . "\n";
        if($notes)   echo "NOTE:\n    " . implode("\n    ", $notes) . "\n";
        echo "Skipped: " . count($skipped) . ($skipped ? "\n    " . implode("\n    ", $skipped) : '') . "\n";
        echo "Identifiers still on more than one row afterwards: " . count($still) . ($still ? "\n    " . implode("\n    ", $still) : '') . "\n";
        $ret = array('deleted' => count($delete), 'skipped' => count($skipped), 'still_duplicated' => count($still), 'uploaded' => false);
        if($dry_run) return $ret;
        if(!$delete) { echo "\nNothing to delete. Nothing was changed or uploaded.\n"; return $ret; }

        $backup = self::master_backup_path();
        if(!copy($references_tsv, $backup)) { echo "\nERROR: Cannot backup [$references_tsv]\n"; return false; }
        echo "\nBackup: [$backup]\n";
        $orig = self::count_data_rows($references_tsv);
        $log = array("master_row\tidentifier\tfull_reference");
        ksort($delete);
        foreach(array_keys($delete) as $r) { $log[] = "$r\t" . rtrim($master[$r - 1], "\r"); unset($master[$r - 1]); }
        $tmp = $references_tsv . ".tmp"; // write to a temp file first, so a failed write can't leave a half-written TSV
        if(file_put_contents($tmp, implode("\n", $master)) === false || !rename($tmp, $references_tsv)) { echo "\nERROR: Cannot update [$references_tsv]\n"; return false; }
        echo "Updated: [$references_tsv]\n";
        $log_file = CONTENT_RESOURCE_LOCAL_PATH . "references_" . $this->main_datasets['References_conceptID'] . "_removed_duplicates_" . date("Ymd_His") . ".tsv";
        if(file_put_contents($log_file, implode("\n", $log) . "\n") !== false) echo "Deleted rows saved in: [$log_file]\n";
        self::print_row_stats($orig, count($delete), self::count_data_rows($references_tsv), 'deleted');

        // Step 2
        $this->copy_to_github_folder($references_tsv);
        // Step 3
        $ret['uploaded'] = self::upload_references_master();
        return $ret;
    }
    private function update_master_with_datamanager_file($dm_file, $dry_run = false, $tsv = null, $skip_unmappable = false, $replace_existing = false)
    {   /* $dm_file: the data manager's copy of unmappable_identifiers.tsv (identifier, status, full_reference), with full_reference filled in;
                  or a 2-column file of references to add (identifier, full_reference), e.g. references_add_template.tsv
        Step 1: rows with a full_reference --> master references.tsv, if the identifier isn't there yet (else ignored)
        Step 2: the master is copied to the GitHub folder (done by append_to_references_master())
        Step 3: the master is uploaded to Zenodo: draft only, unless $this->param['publish']
        Step 4: the identifiers added in step 1 are removed from our unmappable_identifiers.tsv ($tsv; a trailing '/' is ignored when matching)
        Step 5: unmappable_identifiers.tsv is copied to the GitHub folder
        $dry_run: report steps 1 and 4 only, change nothing.
        $skip_unmappable: stop after step 3; our unmappable_identifiers.tsv is left as is (steps 4 & 5 not done).
        $replace_existing: in step 1, an identifier already in the master with a different full_reference gets the new one, in place (same row);
                           only if the identifier is on exactly one master row. The replaced rows are saved, with the old and the new full_reference.
        Returns array('added', 'replaced', 'ignored', 'uploaded', 'deleted'), or false. */
        if(!$tsv) $tsv = CONTENT_RESOURCE_LOCAL_PATH . "unmappable_identifiers.tsv";
        if(!is_file($dm_file)) { echo "\nERROR: No file [$dm_file]\n"; return false; }
        $lines = file($dm_file, FILE_IGNORE_NEW_LINES);
        $header = array_map('trim', explode("\t", (string) @$lines[0]));
        if(!in_array($header, array(array('identifier', 'status', 'full_reference'), array('identifier', 'full_reference')), true)) { echo "\nERROR: [$dm_file] header must be: identifier, status, full_reference -- or: identifier, full_reference\n"; return false; }
        $ref_col = array_search('full_reference', $header); // 2 in the unmappable list layout, 1 in the 2-column layout (e.g. references_add_template.tsv)
        echo "\nData manager's file: [$dm_file]\n";

        // Step 1
        if(!($references_tsv = $this->get_generic_file_path($this->main_datasets['References_conceptID'], 'references', false))) { echo "\nERROR: No references.tsv\n"; return false; } //3rd param is: $processedYN
        echo "\nReferences Master: [$references_tsv]\n";
        $master = explode("\n", file_get_contents($references_tsv)); // each line keeps its own \r, so a replaced row keeps its line ending
        $existing = array(); // identifier => master line indexes (0 = header)
        foreach($master as $i => $line) if($i > 0 && rtrim($line, "\r") !== '') $existing[trim(explode("\t", $line)[0])][] = $i;
        $orig = self::count_data_rows($references_tsv);
        $rows = array(); $added = array(); $seen = array(); $ignored = array(); $repeated = array(); $blank = 0;
        $replace = array(); $same = array(); $several = array(); // $replace: master line index => array(identifier, old full_reference, new full_reference)
        foreach($lines as $line_no => $line) {
            if($line_no == 0 || trim($line) == '') continue; // header
            $c = array_map('trim', explode("\t", $line) + array('', '', ''));
            [$identifier, $full_reference] = array($c[0], $c[$ref_col]);
            if($identifier == '' || $full_reference == '') { $blank++; continue; }
            if(isset($seen[$identifier]))    { $repeated[] = $identifier; continue; }
            $seen[$identifier] = '';
            if(isset($existing[$identifier])) {
                if(!$replace_existing) { $ignored[] = $identifier; continue; }
                if(count($existing[$identifier]) > 1) { $several[] = $identifier; continue; }
                $i = $existing[$identifier][0];
                $old = trim((string) @explode("\t", rtrim($master[$i], "\r"), 2)[1]);
                if($old === $full_reference) $same[] = $identifier;
                else $replace[$i] = array($identifier, $old, $full_reference);
                continue;
            }
            $added[$identifier] = '';
            $rows[] = "$identifier\t$full_reference";
        }
        echo "\n" . ($dry_run ? "DRY RUN, nothing is changed.\nWould be added" : "To be added") . " to master: " . count($rows) . "\n";
        if($replace_existing) {
            echo ($dry_run ? "Would be replaced" : "To be replaced") . " (citation of an identifier already in master): " . count($replace) . ($replace ? "\n    " . implode("\n    ", array_column($replace, 0)) : '') . "\n";
            if($same)    echo "Ignored, already in master with the same citation: " . count($same) . "\n    " . implode("\n    ", $same) . "\n";
            if($several) echo "Ignored, already on more than one master row (not replaced): " . count($several) . "\n    " . implode("\n    ", $several) . "\n";
            $ignored = array_merge($same, $several);
        }
        else echo "Ignored, already in master: " . count($ignored) . ($ignored ? "\n    " . implode("\n    ", $ignored) : '') . "\n";
        if($repeated) echo "Ignored, listed again in the file: " . count($repeated) . "\n    " . implode("\n    ", $repeated) . "\n";
        if($blank)    echo "Skipped, blank full_reference: $blank\n";

        // Step 4, worked out first: rows of our unmappable list whose identifier was added (a trailing '/' is ignored)
        if($skip_unmappable) { echo "\nSteps 4 & 5 skipped (skip_unmappable_update_YN): [$tsv] is left as is.\n"; $tsv = null; }
        $added_noslash = array();
        foreach(array_keys($added) as $id) $added_noslash[rtrim($id, '/')] = $id;
        $keep = array(); $deleted = array(); $slash_notes = array();
        if($tsv && is_file($tsv)) {
            foreach(file($tsv, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
                if($line_no == 0) { $keep[] = $line; continue; } // header
                if(trim($line) == '') continue;
                $identifier = trim(explode("\t", $line)[0]);
                $key = rtrim($identifier, '/');
                if($identifier != '' && isset($added_noslash[$key])) {
                    $deleted[] = $identifier;
                    if(!isset($added[$identifier])) $slash_notes[] = "[$identifier] matched [{$added_noslash[$key]}] (trailing '/' ignored)";
                }
                else $keep[] = $line;
            }
        }
        elseif($tsv) echo "\nNo [$tsv] yet.\n";
        $not_listed = array_diff(array_keys($added_noslash), array_map(fn($id) => rtrim($id, '/'), $deleted));
        if($dry_run) {
            if($skip_unmappable) return array('added' => count($rows), 'replaced' => count($replace), 'ignored' => count($ignored) + count($repeated), 'uploaded' => false, 'deleted' => 0);
            echo "\nWould be deleted from [$tsv]: " . count($deleted) . "\n";
            if($slash_notes) echo "NOTE:\n    " . implode("\n    ", $slash_notes) . "\n";
            if($not_listed)  echo "Added, but not on our unmappable list: " . count($not_listed) . "\n    " . implode("\n    ", array_map(fn($k) => $added_noslash[$k], $not_listed)) . "\n";
            return array('added' => count($rows), 'replaced' => count($replace), 'ignored' => count($ignored) + count($repeated), 'uploaded' => false, 'deleted' => count($deleted));
        }
        if(!$rows && !$replace) {
            echo "\nNo new references to add" . ($replace_existing ? " or replace" : '') . ". Nothing was changed or uploaded.\n";
            return array('added' => 0, 'replaced' => 0, 'ignored' => count($ignored) + count($repeated), 'uploaded' => false, 'deleted' => 0);
        }

        // Steps 1 & 2
        if($rows) { // appended first: its backup is then the master as it was
            if(!self::append_to_references_master($references_tsv, $rows)) return false; // also copies the master to the GitHub folder
            self::print_row_stats($orig, count($rows), self::count_data_rows($references_tsv));
        }
        if($replace) {
            if(!$rows) { // no backup made by append_to_references_master()
                $backup = self::master_backup_path();
                if(!copy($references_tsv, $backup)) { echo "\nERROR: Cannot backup [$references_tsv]\n"; return false; }
                echo "\nBackup: [$backup]\n";
            }
            $master = explode("\n", file_get_contents($references_tsv)); // as appended; the replaced rows are above the appended ones, so their indexes still hold
            $log = array("master_row\tidentifier\told_full_reference\tnew_full_reference");
            foreach($replace as $i => [$identifier, $old, $new]) {
                $first = explode("\t", $master[$i])[0]; // the identifier as written in the master
                $master[$i] = "$first\t$new" . (str_ends_with($master[$i], "\r") ? "\r" : '');
                $log[] = ($i + 1) . "\t$identifier\t$old\t$new";
            }
            $tmp = $references_tsv . ".tmp"; // write to a temp file first, so a failed write can't leave a half-written TSV
            if(file_put_contents($tmp, implode("\n", $master)) === false || !rename($tmp, $references_tsv)) { echo "\nERROR: Cannot update [$references_tsv]\n"; return false; }
            echo "\nReplaced the citation of " . count($replace) . " reference(s): [$references_tsv]\n";
            $log_file = CONTENT_RESOURCE_LOCAL_PATH . "references_" . $this->main_datasets['References_conceptID'] . "_replaced_citations_" . date("Ymd_His") . ".tsv";
            if(file_put_contents($log_file, implode("\n", $log) . "\n") !== false) echo "Old and new citations saved in: [$log_file]\n";
            $this->copy_to_github_folder($references_tsv);
        }

        // Step 3
        $uploaded = self::upload_references_master();
        if($skip_unmappable) return array('added' => count($rows), 'replaced' => count($replace), 'ignored' => count($ignored) + count($repeated), 'uploaded' => $uploaded, 'deleted' => 0);

        // Steps 4 & 5
        echo "\nUnmappable identifiers: [$tsv]\n";
        $orig1 = self::count_data_rows($tsv);
        if($deleted) {
            $backup = dirname($tsv) . "/unmappable_identifiers_backup_" . date("Ymd_His") . ".tsv";
            if(!copy($tsv, $backup)) { echo "\nERROR: Cannot backup [$tsv]\n"; return false; }
            echo "Backup: [$backup]\n";
            $tmp = $tsv . ".tmp"; // write to a temp file first, so a failed write can't leave a half-written TSV
            if(file_put_contents($tmp, implode("\n", $keep) . "\n") === false || !rename($tmp, $tsv)) { echo "\nERROR: Cannot update [$tsv]\n"; return false; }
            echo "Updated: [$tsv]\n";
            if($slash_notes) echo "NOTE:\n    " . implode("\n    ", $slash_notes) . "\n";
            $this->copy_to_github_folder($tsv);
        }
        else echo "No rows to delete.\n";
        self::print_row_stats($orig1, count($deleted), self::count_data_rows($tsv), 'deleted');
        if($not_listed) echo "Added, but not on our unmappable list: " . count($not_listed) . "\n    " . implode("\n    ", array_map(fn($k) => $added_noslash[$k], $not_listed)) . "\n";
        return array('added' => count($rows), 'replaced' => count($replace), 'ignored' => count($ignored) + count($repeated), 'uploaded' => $uploaded, 'deleted' => count($deleted));
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
                         'timeout' => 60, 'download_attempts' => 2, 'download_wait_time' => 3000000, //3 seconds interval between calls
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
        $backup = self::master_backup_path();
        if(!copy($references_tsv, $backup)) { echo "\nERROR: Cannot backup [$references_tsv]\n"; return false; }
        echo "\nBackup: [$backup]\n";

        $eol = "\r\n"; // master uses CRLF line endings
        $fhandle = fopen($references_tsv, "a");
        if(substr(file_get_contents($references_tsv, false, null, -1), -1) !== "\n") fwrite($fhandle, $eol); // last row has no line ending
        foreach($rows as $row) fwrite($fhandle, $row . $eol);
        fclose($fhandle);
        echo "\nUpdated: [$references_tsv]\n";
        $this->copy_to_github_folder($references_tsv);
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
        $this->copy_to_github_folder($tsv);
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
        $this->copy_to_github_folder($tsv);
        self::print_row_stats($orig, count($removed), self::count_data_rows($tsv), 'removed');
        return count($removed);
    }
    private function refresh_unmappable_identifiers_usingDatasets($tsv = null)
    {   // remove from unmappable_identifiers.tsv the identifiers that no TraitBank dataset cites any more
        // datasets: the Zenodo 'traitbank' community, except $this->main_datasets (Terms, References, DH)
        // cited IDs: the referenceID and source columns of each dataset's local traits file (get_generic_file_path(), no download),
        //             and of its taxon file, if it has one with these columns (e.g. Kubitzki: source in taxon.tsv)
        // stops and removes nothing if a local copy is missing or older than Zenodo's latest version
        // Returns no. of rows removed, or false.
        $tsv = $tsv ?? CONTENT_RESOURCE_LOCAL_PATH . "unmappable_identifiers.tsv";
        if(!is_file($tsv)) { echo "\nERROR: No file [$tsv]\n"; return false; }

        if(($cited = self::get_cited_ids_from_datasets()) === false) return false; // steps 1-3, nothing is removed if it fails


        // 4. keep the identifiers that are still cited
        $orig = self::count_data_rows($tsv);
        $keep = array(); $removed = array();
        foreach(file($tsv, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            if($line_no == 0) { $keep[] = $line; continue; } // header
            if(trim($line) == '') continue;
            if(isset($cited[trim(explode("\t", $line)[0])])) $keep[] = $line;
            else                                              $removed[] = $line;
        }
        if(!$removed) {
            echo "\nAll unmappable identifiers are still cited in some dataset. Nothing to remove.\n";
            self::print_row_stats($orig, 0, $orig, 'removed');
            return 0;
        }

        // 5. backup, record of the removed rows, then rewrite the list safely
        $dir = dirname($tsv) . "/"; $date = date("Ymd_His");
        $backup = $dir . "unmappable_identifiers_backup_$date.tsv";
        if(!copy($tsv, $backup)) { echo "\nERROR: Cannot backup [$tsv]\n"; return false; }
        $record = $dir . "unmappable_identifiers_removed_notInDatasets_$date.tsv";
        file_put_contents($record, $keep[0] . "\n" . implode("\n", $removed) . "\n");
        $tmp = $tsv . ".tmp"; // write to a temp file first, so a failed write can't leave a half-written TSV
        if(file_put_contents($tmp, implode("\n", $keep) . "\n") === false || !rename($tmp, $tsv)) { echo "\nERROR: Cannot update [$tsv]\n"; return false; }
        echo "\nBackup: [$backup]\nRemoved rows saved in: [$record]\nUpdated: [$tsv]\nRemoved (no dataset cites them any more):\n";
        foreach($removed as $line) echo explode("\t", $line)[0] . "\n";
        $this->copy_to_github_folder($tsv);
        self::print_row_stats($orig, count($removed), self::count_data_rows($tsv), 'removed');
        return count($removed);
    }
    private function sync_unmappable_identifiers_with_datasets($tsv = null, $dry_run = false, $crossref = false)
    {   /* One step: makes unmappable_identifiers.tsv = the IDs cited in the TraitBank datasets (/referenceID or /source) that our master doesn't have.
           Reads the datasets directly (get_cited_ids_from_datasets()), not the converter's UndefinedRefIDs debug files, so no conversion is needed first.
        - added:   cited IDs that aren't in the master (with a full_reference) and aren't on the list yet; status "Not in master", blank full_reference
                   ('#N/A', a spreadsheet's "no value", is never added)
        - removed: IDs now in the master (with a full_reference), and IDs no dataset cites any more
        - $crossref: the IDs to add are first looked up in CrossRef; those found go to the master (backup first) instead, the rest get CrossRef's status
        - $dry_run: report only, change nothing
        Stops and changes nothing if a dataset's local copy is missing or older than Zenodo's latest version.
        Backup first; a record of every added/removed row is saved (unmappable_identifiers_sync_<date>.tsv); both files are copied to the GitHub folder.
        Returns array('added', 'to_master', 'removed_in_master', 'removed_not_cited'), or false. */
        $tsv = $tsv ?? CONTENT_RESOURCE_LOCAL_PATH . "unmappable_identifiers.tsv";
        if(($cited = self::get_cited_ids_from_datasets()) === false) return false;
        if(!($references_tsv = $this->get_generic_file_path($this->main_datasets['References_conceptID'], 'references', false))) { echo "\nERROR: No references.tsv\n"; return false; }
        echo "\nReferences Master: [$references_tsv]\nUnmappable list:   [$tsv]" . ($dry_run ? " (dry run, nothing is changed)" : '') . "\n";
        $mapped = array(); // identifiers in the master with a full_reference -- what the converter counts as defined
        foreach(file($references_tsv, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            if($line_no == 0) continue; // header
            [$identifier, $full_reference] = array_map('trim', explode("\t", $line) + array('', ''));
            if($identifier != '' && $full_reference != '') $mapped[$identifier] = '';
        }

        // the list now: keep, or remove (in the master / not cited any more)
        $header = "identifier\tstatus\tfull_reference"; $keep = array(); $existing = array(); $removed = array(); // $removed: array(line, reason)
        if(is_file($tsv)) {
            foreach(file($tsv, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
                if($line_no == 0) { $header = $line; continue; }
                if(trim($line) == '') continue;
                $id = trim(explode("\t", $line)[0]);
                $existing[$id] = '';
                if(isset($mapped[$id]))     $removed[] = array($line, 'now in the master');
                elseif(!isset($cited[$id])) $removed[] = array($line, 'no dataset cites it any more');
                else                        $keep[] = $line;
            }
        }
        // to add: cited, not in the master, not on the list yet
        $to_add = array();
        foreach($cited as $id => $concept_ids) {
            if($id === '#N/A' || isset($mapped[$id]) || isset($existing[$id])) continue;
            $to_add[$id] = array_keys($concept_ids);
        }
        $new_rows = array(); $master_rows = array(); $status_of = array();
        foreach($to_add as $id => $concept_ids) {
            if($crossref) {
                $citation = self::get_citation_from_CrossRef($id);
                if($citation) { $master_rows[] = "$id\t" . preg_replace('/[\t\r\n]+/', ' ', $citation); $status_of[$id] = 'found in CrossRef, added to the master'; continue; }
                $status_of[$id] = $this->crossref_status ?: 'Not in master';
            }
            else $status_of[$id] = 'Not in master';
            $new_rows[] = "$id\t{$status_of[$id]}\t"; // blank full_reference, for the Data Manager
        }

        // report
        $n_in_master = count(array_filter($removed, fn($r) => $r[1] == 'now in the master'));
        $ret = array('added' => count($new_rows), 'to_master' => count($master_rows), 'removed_in_master' => $n_in_master, 'removed_not_cited' => count($removed) - $n_in_master);
        echo "\nOn the list now: " . number_format(count($existing)) . "\n";
        echo "To remove, now in the master: " . number_format($ret['removed_in_master']) . "\n";
        echo "To remove, no dataset cites them any more: " . number_format($ret['removed_not_cited']) . "\n";
        echo "To add, cited but not in the master: " . number_format(count($new_rows)) . "\n";
        if($crossref) echo "To add to the master, found in CrossRef: " . number_format(count($master_rows)) . "\n";
        foreach(array_slice($removed, 0, 10) as [$line, $why]) echo "    remove: " . mb_substr(trim(explode("\t", $line)[0]), 0, 120) . "  ($why)\n";
        foreach(array_slice(array_keys($to_add), 0, 10) as $id) echo "    add:    " . mb_substr($id, 0, 120) . "  (" . implode(", ", $to_add[$id]) . ")\n";
        $final = count($keep) + count($new_rows);
        echo "List rows: " . number_format(count($existing)) . " -> " . number_format($final) . ($dry_run ? " (after the sync)" : '') . "\n";
        if($dry_run) return $ret;
        if(!$removed && !$new_rows && !$master_rows) { echo "\nThe list is already in step with the datasets and the master. Nothing was changed.\n"; return $ret; }

        // write: master first (if CrossRef found any), then the list
        if($master_rows) {
            if(!self::append_to_references_master($references_tsv, $master_rows)) return false; // backup + GitHub copy
            echo "Added to the master (found in CrossRef): " . count($master_rows) . ". Upload it to Zenodo when ready (upload_file_toZenodo).\n";
        }
        $date = date("Ymd_His"); $dir = dirname($tsv) . "/";
        if(is_file($tsv)) {
            $backup = $dir . "unmappable_identifiers_backup_$date.tsv";
            if(!copy($tsv, $backup)) { echo "\nERROR: Cannot backup [$tsv]\n"; return false; }
            echo "Backup: [$backup]\n";
        }
        $record = array("action\tidentifier\treason\tconcept_ids\trow");
        foreach($removed as [$line, $why]) $record[] = "removed\t" . trim(explode("\t", $line)[0]) . "\t$why\t\t" . str_replace("\t", " | ", $line);
        foreach($to_add as $id => $concept_ids) $record[] = (str_starts_with($status_of[$id], 'found in CrossRef') ? "to master" : "added") . "\t$id\t{$status_of[$id]}\t" . implode(",", $concept_ids) . "\t";
        $record_file = $dir . "unmappable_identifiers_sync_$date.tsv";
        file_put_contents($record_file, implode("\n", $record) . "\n");
        $tmp = $tsv . ".tmp"; // write to a temp file first, so a failed write can't leave a half-written TSV
        if(file_put_contents($tmp, implode("\n", array_merge(array($header), $keep, $new_rows)) . "\n") === false || !rename($tmp, $tsv)) { echo "\nERROR: Cannot update [$tsv]\n"; return false; }
        echo "Updated: [$tsv]\nRecord of the changes: [$record_file]\n";
        $this->copy_to_github_folder($tsv);
        echo "orig total rows: " . number_format(count($existing)) . "\nadded rows: " . number_format(count($new_rows))
           . "\nremoved rows: " . number_format(count($removed)) . "\nfinal total rows: " . number_format(self::count_data_rows($tsv)) . "\n";
        return $ret;
    }
    private function undefined_referenceIDs_basename()
    {   // the undefined_referenceIDs_YYYYMMDD.tsv for steps 2-4, without .tsv: the one given in "basename" (with or without .tsv), else the newest one; or ''
        if(!empty($this->param['basename'])) return preg_replace('/(_processed)?\.tsv$/', '', basename($this->param['basename']));
        $files = glob(CONTENT_RESOURCE_LOCAL_PATH . "undefined_referenceIDs_[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9].tsv") ?: array();
        rsort($files); // the date is in the name, so the newest sorts first
        return $files ? pathinfo($files[0], PATHINFO_FILENAME) : '';
    }
    private function get_cited_ids_from_datasets()
    {   /* All IDs cited in the TraitBank datasets: array(identifier => array(concept_id => '')), or false (and nothing should be changed).
        datasets: the Zenodo 'traitbank' community, except $this->main_datasets (Terms, References, DH)
        cited IDs: the referenceID and source columns of each dataset's local traits file (get_generic_file_path(), no download),
                   and of its taxon file, if it has one with these columns (e.g. Kubitzki: source in taxon.tsv)
        returns false if a local copy is missing or older than Zenodo's latest version (prints the re-download commands),
        or if no ID is found at all. Used by refresh_unmappable_identifiers_usingDatasets() and sync_unmappable_identifiers_with_datasets(). */
        // 1. dataset list from Zenodo
        if(!($csv = $this->generate_Zenodo_TraitBank_datasets_inCSV())) { echo "\nERROR: Cannot get the TraitBank datasets list from Zenodo\n"; return false; }
        $datasets = array(); // concept_id => array(record_id, title)
        $fh = fopen($csv, "r");
        $fields = fgetcsv($fh, 0, ',', '"', '');
        while(($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $rec = array_combine($fields, array_pad($row, count($fields), ''));
            if(in_array($rec['concept_id'], $this->main_datasets)) continue; // Terms, References, DH
            $datasets[$rec['concept_id']] = array('record_id' => $rec['record_id'], 'title' => $rec['title']);
        }
        fclose($fh);
        echo "\nTraitBank datasets to check: " . count($datasets) . "\n";

        // 2. every local copy must be there and be Zenodo's latest version
        $problems = array(); $traits_files = array();
        foreach($datasets as $concept_id => $d) {
            $record_file = CONTENT_RESOURCE_LOCAL_PATH . "TB_files/$concept_id.record_id";
            $local = is_file($record_file) ? trim(file_get_contents($record_file)) : '';
            $file = ($local !== '') ? $this->get_generic_file_path($concept_id, 'traits', false) : false;
            if($local === '' || !$file) $problems[$concept_id] = "not downloaded";
            elseif($local !== $d['record_id']) $problems[$concept_id] = "local version $local is older than Zenodo's latest {$d['record_id']}";
            else $traits_files[$concept_id] = $file;
        }
        if($problems) {
            echo "\nThese local copies must be (re-)downloaded first:\n";
            foreach($problems as $concept_id => $why) {
                $title = $datasets[$concept_id]['title'];
                echo "- $concept_id \"$title\": $why\n"
                   . "    php generate_csv_new_model.php _ '{\"concept_id\": \"$concept_id\", \"redownload_zip_file_YN\": 1, \"task\": \"download_only\", \"what\": \"" . str_replace(array('"', "'"), '', $title) . "\"}'\n";
            }
            echo "Nothing was changed.\n";
            return false;
        }

        // 3. all IDs cited in the datasets: the referenceID column (a cell may hold several, e.g. "id1|id2") and the source column
        //    (one value per cell; ConvertNewModel_2DwCA reports a source missing from the master as an undefined referenceID too).
        //    Files: traits, and taxon where it has one of these columns (e.g. Kubitzki 22777078 has a source column in taxon.tsv)
        $cited = array(); $cited_ref = array(); $cited_src = array();
        foreach($traits_files as $concept_id => $file) {
            $files = array($file);
            foreach(array('taxon.tsv', 'taxon.txt') as $name) if(is_file(dirname($file) . "/$name")) { $files[] = dirname($file) . "/$name"; break; }
            foreach($files as $f) {
                $fh = fopen($f, "r");
                $fields = explode("\t", rtrim((string) fgets($fh), "\r\n"));
                $ref_col = false; $src_col = false;
                foreach($fields as $i => $field) {
                    $name = pathinfo(trim($field), PATHINFO_FILENAME); // e.g. http://rs.tdwg.org/dwc/terms/referenceID, http://purl.org/dc/terms/source
                    if($name == 'referenceID' && $ref_col === false) $ref_col = $i;
                    if($name == 'source'      && $src_col === false) $src_col = $i;
                }
                if($ref_col === false && $src_col === false) {
                    fclose($fh);
                    if($f === $file) echo "$concept_id: no referenceID or source column in [" . basename($f) . "]\n";
                    continue;
                }
                $rows = 0; $ref_ids = array(); $src_ids = array();
                while(($line = fgets($fh)) !== false) {
                    $rows++;
                    $values = explode("\t", rtrim($line, "\r\n"));
                    if($ref_col !== false) {
                        foreach(explode("|", $values[$ref_col] ?? '') as $id) {
                            // only spaces trimmed, never quotes: the converter looks the value up in the master as it is, and a quote can be
                            // part of a real ID, e.g. https://collections.si.edu/search/results.htm?q=598559&fq=tax_class:"Mammalia"
                            $id = trim($id);
                            if($id !== '') $ref_ids[$id] = '';
                        }
                    }
                    if($src_col !== false) {
                        $id = trim($values[$src_col] ?? ''); // same: no quotes trimmed
                        if($id !== '') $src_ids[$id] = '';
                    }
                }
                fclose($fh);
                foreach($ref_ids + $src_ids as $id => $x) $cited[$id][$concept_id] = '';
                $cited_ref += $ref_ids; $cited_src += $src_ids;
                echo "$concept_id " . basename($f) . ": " . number_format($rows) . " rows, "
                   . ($ref_col !== false ? number_format(count($ref_ids)) . " distinct referenceIDs" : "no referenceID column") . ", "
                   . ($src_col !== false ? number_format(count($src_ids)) . " distinct sources" : "no source column") . "\n";
            }
        }
        echo "Distinct IDs cited in all datasets: " . number_format(count($cited))
           . " (referenceID: " . number_format(count($cited_ref)) . ", source: " . number_format(count($cited_src)) . ")\n";
        if(!$cited) { echo "\nERROR: no referenceID or source found in any dataset. Nothing was changed.\n"; return false; } // never empty the whole list by mistake
        return $cited;
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
            $this->copy_to_github_folder($source1);
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
    private function compare_master_with_zenodo()
    {   /* Report only, changes nothing in our files: compares our local master references.tsv with the one in Zenodo's latest version.
        1. versions: Zenodo's latest record (fresh, not cached) vs ours in TB_files/22776838.record_id
        2. files: md5 of our master vs Zenodo's checksum -> identical, or not
        3. if not identical: Zenodo's file is downloaded to a temp file (deleted afterwards) and compared row by row,
           as (identifier, full_reference) pairs; a header row and blank lines are ignored, and so are line endings (CRLF/LF)
           -> rows only in our master, rows only on Zenodo, identifiers whose citation differs; all saved in a TSV
        Returns array('zenodo_record', 'local_record', 'same_version', 'identical', 'only_local', 'only_zenodo', 'different_citation', 'report'), or false. */
        $concept_id = $this->main_datasets['References_conceptID'];
        if(!($local_file = $this->get_generic_file_path($concept_id, 'references', false))) { echo "\nERROR: No local references.tsv\n"; return false; }
        if(!($latest = $this->get_zenodo_info_using_conceptID($concept_id, 0))) { echo "\nERROR: Cannot get the latest version of [$concept_id] from Zenodo\n"; return false; }
        $zfile = null;
        foreach($latest['files'] as $f) if(basename($f['key']) == 'references.tsv') { $zfile = $f; break; }
        if(!$zfile) { echo "\nERROR: Zenodo's latest version (record {$latest['id']}) has no references.tsv\n"; return false; }

        // 1. versions
        $record_file  = CONTENT_RESOURCE_LOCAL_PATH . "TB_files/$concept_id.record_id";
        $local_record = is_file($record_file) ? trim(file_get_contents($record_file)) : '';
        $same_version = ($local_record === (string) $latest['id']);
        echo "\nOur master:      [$local_file]\n";
        echo "Zenodo's latest: record {$latest['id']}" . ($latest['version'] ? ", version {$latest['version']}" : '') . "\n";
        echo "Our version:     record " . ($local_record ?: 'unknown') . ($same_version ? " (same as Zenodo's latest)" : " (NOT Zenodo's latest)") . "\n";

        // 2. files
        $local_md5  = md5_file($local_file);
        $zenodo_md5 = preg_match('/^md5:([0-9a-f]{32})$/i', (string) $zfile['checksum'], $m) ? strtolower($m[1]) : '';
        echo "md5 ours:        $local_md5\nmd5 Zenodo:      " . ($zenodo_md5 ?: 'unknown') . "\n";
        $ret = array('zenodo_record' => (string) $latest['id'], 'local_record' => $local_record, 'same_version' => $same_version,
                     'identical' => ($zenodo_md5 !== '' && $local_md5 === $zenodo_md5),
                     'only_local' => 0, 'only_zenodo' => 0, 'different_citation' => 0, 'report' => null);
        if($ret['identical']) {
            echo "\nIDENTICAL: our master is the same file as Zenodo's latest version. Rows: " . number_format(self::count_data_rows($local_file)) . "\n";
            return $ret;
        }

        // 3. row by row, using a temp copy of Zenodo's file
        echo "\nNOT identical. Downloading Zenodo's file to compare row by row...\n";
        $tmp = tempnam(sys_get_temp_dir(), 'references_zenodo_');
        if(!$this->download_url_to_file($zfile['url'], $tmp)) { @unlink($tmp); echo "ERROR: Cannot download Zenodo's references.tsv\n"; return false; }
        $read = function($file) { // array(identifier => array(full_reference => ''))
            $rows = array();
            foreach(file($file, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
                $line = rtrim($line, "\r");
                if(trim($line) === '') continue;
                $c = explode("\t", $line, 2);
                $id = trim($c[0]);
                if($line_no == 0 && $id === 'identifier') continue; // header
                $rows[$id][trim($c[1] ?? '')] = '';
            }
            return $rows;
        };
        $L = $read($local_file); $Z = $read($tmp);
        unlink($tmp);
        $report = array(); $only_local = 0; $only_zenodo = 0; $diff_ids = array();
        foreach(array(array($L, $Z, 'only in our master'), array($Z, $L, 'only on Zenodo')) as [$a, $b, $where]) {
            foreach($a as $id => $refs) {
                foreach($refs as $ref => $x) {
                    if(isset($b[$id][$ref])) continue;
                    $report[] = "$where\t$id\t$ref";
                    if($where == 'only in our master') $only_local++; else $only_zenodo++;
                    if(isset($b[$id])) $diff_ids[$id] = ''; // the identifier is on both sides, with a different citation
                }
            }
        }
        $ret['only_local'] = $only_local; $ret['only_zenodo'] = $only_zenodo; $ret['different_citation'] = count($diff_ids);
        $n_local = array_sum(array_map('count', $L)); $n_zenodo = array_sum(array_map('count', $Z));
        echo "Rows ours: " . number_format($n_local) . ", Zenodo: " . number_format($n_zenodo) . "\n";
        echo "Rows only in our master: " . number_format($only_local) . "\n";
        echo "Rows only on Zenodo:     " . number_format($only_zenodo) . "\n";
        echo "Identifiers on both, with a different citation: " . number_format(count($diff_ids)) . "\n";
        if(!$report) { echo "Same rows: the files differ only in their header, row order or line endings.\n"; return $ret; }
        foreach(array_slice($report, 0, 10) as $line) echo "    " . mb_substr($line, 0, 150) . "\n";
        $ret['report'] = CONTENT_RESOURCE_LOCAL_PATH . "references_{$concept_id}_compare_zenodo_record{$latest['id']}_" . date("Ymd_His") . ".tsv";
        file_put_contents($ret['report'], "where\tidentifier\tfull_reference\n" . implode("\n", $report) . "\n");
        echo "All differences saved in: [{$ret['report']}]\n";
        if(!$same_version) echo "Zenodo has a newer version than ours: download it and sync before uploading (see sync_zenodo_references).\n";
        else echo "Zenodo hasn't changed since our download, so these are our own changes (rows added, changed or deleted): "
                . "upload our master when ready (see upload_file_toZenodo).\n";
        return $ret;
    }
    private function master_backup_path()
    {   // references_22776838_backup_<Ymd_His>.tsv; never an existing one (two backups in the same second get _2, _3, ...)
        $base = CONTENT_RESOURCE_LOCAL_PATH . "references_" . $this->main_datasets['References_conceptID'] . "_backup_" . date("Ymd_His");
        $path = "$base.tsv";
        for($n = 2; file_exists($path); $n++) $path = "{$base}_$n.tsv";
        return $path;
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
    private function set_zenodo_base_references()
    {   // the newest Zenodo copy of references.tsv (merged or not) becomes the sync base -- only if it has no rows our master doesn't have,
        // i.e. our master is in step with it (plus our own additions). Returns the base path, or false.
        $concept_id = $this->main_datasets['References_conceptID'];
        $this->unmerged_checked["$concept_id/references.tsv"] = true;
        if(!($local_file = $this->get_generic_file_path($concept_id, 'references', false))) { echo "\nERROR: No local references.tsv\n"; return false; }
        $copies = glob(dirname(dirname($local_file)) . "/references_from_zenodo_*.tsv") ?: array();
        usort($copies, fn($a, $b) => filemtime($b) <=> filemtime($a));
        if(!$copies) { echo "\nERROR: No Zenodo copy of references.tsv yet. Download it first:\nphp generate_csv_new_model.php _ '{\"task\": \"refresh_all_TraitBank_datasets\", \"only\": \"References\"}'\n"; return false; }
        $diff = $this->compare_with_zenodo_copy($local_file, $copies[0]);
        if($diff['new_ids'] || $diff['conflicts']) {
            echo "\nSTOP: Zenodo's copy [" . basename($copies[0]) . "] has rows our master doesn't have (" . $diff['new_ids'] . " new identifiers, " . count($diff['conflicts']) . " different values).\n"
               . "It can't be the base. Nothing was changed.\n";
            return false;
        }
        $label = preg_match('/_from_zenodo_(record\d+)/', basename($copies[0]), $m) ? $m[1] : date("Ymd_His");
        if(!($base = $this->set_zenodo_base($local_file, 'references.tsv', $copies[0], $label))) return false;
        $merged = preg_replace('/\.tsv$/', '_merged.tsv', $copies[0]);
        if(!str_ends_with($copies[0], '_merged.tsv') && rename($copies[0], $merged)) echo "Zenodo's copy marked as merged: [" . basename($merged) . "]\n";
        return $base;
    }
    protected function upload_file_toZenodo()
    {   /* Uploads our local file of a dataset to Zenodo as a new version, using upload_new_version_to_zenodo().
        $this->param:
            'concept_id' => required, e.g. 22776838 (References)
            'file'       => optional, name of the file in input_files/ to upload, e.g. 'references'; default: the dataset's protected file (PROTECTED_INPUT_FILES)
            'publish'    => optional, 1 = publish now (CANNOT be undone); default 0 = stop at a draft, for review on Zenodo
            'version'    => optional, version label, e.g. '2026-10-28'; default: keep the previous version's label
            'remove_files' => optional, file names to remove from the new version, e.g. ["terms_0.9.tsv"] when uploading the renamed terms_1.0.tsv
        Stops and uploads nothing if Zenodo has changes we don't have yet: an unmerged Zenodo copy, or a newer version than the one we downloaded.
        Returns the result of upload_new_version_to_zenodo(), or false. */
        $concept_id = trim((string) ($this->param['concept_id'] ?? ''));
        if(!ctype_digit($concept_id)) { echo "\nERROR: concept_id is required, e.g. php do_reference_mtce.php _ '{\"task\": \"upload_file_toZenodo\", \"concept_id\": \"22776838\"}'\n"; return false; }
        $file = $this->param['file'] ?? (self::PROTECTED_INPUT_FILES[$concept_id][0] ?? null);
        if(!$file) { echo "\nERROR: No default file for dataset [$concept_id]; give one, e.g. \"file\": \"traits\"\n"; return false; }
        if(!($local_file = $this->get_generic_file_path($concept_id, $file, false))) { echo "\nERROR: No local file [$file] for dataset [$concept_id]\n"; return false; }
        echo "\nLocal file: [$local_file]\n";

        // 1. Zenodo's copy saved by a download, not merged yet -> uploading would drop Zenodo's changes
        if($copies = $this->find_unmerged_zenodo_copies($local_file, basename($local_file))) {
            echo "\nSTOP: Zenodo's copy [" . basename($copies[0]) . "] hasn't been merged into our local file yet. Merge it first:\n";
            echo "php do_reference_mtce.php _ '{\"task\": \"sync_zenodo_references\"}'\nNothing was uploaded.\n";
            return false;
        }
        // 2. a newer version on Zenodo than the one we downloaded (e.g. someone else published) -> download and merge it first
        if(!($latest = $this->get_zenodo_info_using_conceptID($concept_id, 0))) { echo "\nERROR: Cannot get the latest version of [$concept_id]\n"; return false; }
        $record_file = CONTENT_RESOURCE_LOCAL_PATH . "TB_files/$concept_id.record_id";
        $local = is_file($record_file) ? trim(file_get_contents($record_file)) : '';
        if($local !== (string) $latest['id']) {
            echo "\nSTOP: Zenodo's latest version is record {$latest['id']}, but our local copy is from record " . ($local ?: 'unknown') . ".\n";
            echo "Download it (our local file is kept), merge it if suggested, then upload again:\n";
            echo "php generate_csv_new_model.php _ '{\"concept_id\": \"$concept_id\", \"redownload_zip_file_YN\": 1, \"task\": \"download_only\", \"what\": \"{$latest['title']}\"}'\nNothing was uploaded.\n";
            return false;
        }

        $options = array('publish' => !empty($this->param['publish']));
        if(isset($this->param['version'])) $options['version'] = $this->param['version'];
        if(isset($this->param['remove_files'])) $options['remove_files'] = (array) $this->param['remove_files'];
        $ret = $this->upload_new_version_to_zenodo($concept_id, $local_file, $options);

        // after publishing, our local file IS the latest version -> record it, so the next upload isn't stopped by check 2
        if($ret && $ret['published'] && $ret['record_id']) {
            file_put_contents($record_file, $ret['record_id']);
            if(in_array(basename($local_file), self::PROTECTED_INPUT_FILES[$concept_id] ?? array())) // what we published is now the sync base
                $this->set_zenodo_base($local_file, basename($local_file), $local_file, "record" . $ret['record_id']);
        }
        return $ret;
    }
}
?>