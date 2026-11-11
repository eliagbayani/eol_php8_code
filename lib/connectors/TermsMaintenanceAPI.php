<?php
namespace php_active_record;
/* Maintenance of the TraitBank Terms file (Zenodo concept 22776577), called by update_resources/connectors/do_terms_mtce.php
   The master of the Terms is a Google Sheet, edited by the data managers:
       https://docs.google.com/spreadsheets/d/1D5L2ySCI23sWR_LBGc7ClOl7J5_oUtnBY7bW-9wObC4
   Task update_terms_from_googlesheet: download the sheet -> build terms.tsv -> quality control (QC)
       -> QC fails:   Terms_file_errors_YYYY-MM-DD-HH-MM.tsv (to be emailed to the data manager), nothing is uploaded
       -> QC passes:  terms.tsv is uploaded to Zenodo as terms_1.0.tsv (a draft, unless "publish": 1)
----------------------------------------------------
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class TermsMaintenanceAPI extends GenerateTB_Files_Functions
{
    const GOOGLE_SHEET_ID  = '1D5L2ySCI23sWR_LBGc7ClOl7J5_oUtnBY7bW-9wObC4';
    const ZENODO_FILENAME  = 'terms_1.0.tsv'; // the file name on Zenodo; our terms.tsv replaces it
    // the published columns, in the published order; the sheet's other columns (delete?, new term for dataset, notes) are working columns, not published
    const COLUMNS          = array('attribution', 'definition', 'name', 'type', 'uri', 'parent uris', 'synonym of uri', 'units term',
                                   'symmetrical assoc', 'inverse of', 'incompatible with clade', 'exclusive to clade');
    const PRIMARY_FIELDS   = array('name', 'type', 'uri');                                                  // must not be blank
    const URI_FIELDS       = array('uri', 'parent uris', 'synonym of uri', 'units term', 'inverse of');      // must be valid URIs ('parent uris': each part)
    const MULTI_URI_FIELDS = array('parent uris');                                                          // several values, separated by '|' only
    const BOOLEAN_FIELDS   = array('symmetrical assoc');                                                    // TRUE, FALSE or blank
    const TYPES            = array('association', 'measurement', 'value', 'metadata');                      // the only valid [type] values

    function __construct($param) {
        $this->param = $param; print_r($param);
        $this->download_options = array('resource_id' => 'neo4j_tb', 'cache' => 1, 'download_wait_time' => 1000000, 'expire_seconds' => 60*60*24*1, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        $this->debug = array();
        $this->main_datasets = array('Terms_conceptID' => 22776577, 'References_conceptID' => 22776838, 'DH_conceptID' => 22776209);
        $this->work_dir = CONTENT_RESOURCE_LOCAL_PATH . "Terms_maintenance/"; // all our files: the sheet downloads, terms.tsv, the error reports
        $this->report = array(); // QC findings: array(severity, check, sheet_row, uri, name, type, field, value, message)
    }
    function start()
    {
        $task = $this->param['task'];
        if($task == 'update_terms_from_googlesheet') {
            // /* Google Sheet -> terms.tsv -> QC -> error report, or upload to Zenodo (draft, unless "publish": 1). "dry_run_YN": 1 = QC only
            $ret = self::update_terms_from_googlesheet();
            if($ret) echo "\nResult: " . json_encode($ret) . "\n";
            return $ret; // used by applications/terms_maintenance/main.php
            // */
        }
        else echo "\nERROR: Unknown task [$task]\n";
        return false;
    }
    private function update_terms_from_googlesheet()
    {   /* 1. download the Google Sheet as TSV (or use "file": a local TSV, e.g. for tests)
           2. build CONTENT_RESOURCE_LOCAL_PATH/Terms_maintenance/terms.tsv: the 12 published columns, all rows, CRLF, no trailing newline (= the published format,
              so an unchanged sheet gives a byte-identical file, and nothing is uploaded)
           3. QC; 4. errors -> report, nothing uploaded; 5. no errors -> upload (not in a dry run)
           Returns array('rows', 'errors', 'warnings', 'report', 'uploaded', 'source'), or false. 'source' = the sheet download (or "file") used. */
        $dry_run = !empty($this->param['dry_run_YN']);
        $publish = !empty($this->param['publish']);
        if(!is_dir($this->work_dir) && !mkdir($this->work_dir, 0755, true) && !is_dir($this->work_dir)) { echo "\nERROR: Cannot create the folder [$this->work_dir]\n"; return false; }
        // 1.
        if(!empty($this->param['file'])) {
            $source = $this->param['file'];
            if(!is_file($source)) { echo "\nERROR: No file [$source]\n"; return false; }
            echo "\nUsing the local file: [$source]\n";
        }
        else {
            $source = $this->work_dir . "terms_googlesheet_" . date("Ymd_His") . ".tsv";
            $url = "https://docs.google.com/spreadsheets/d/" . self::GOOGLE_SHEET_ID . "/export?format=tsv";
            echo "\nDownloading the Google Sheet: [$url]\n";
            if(!$this->download_url_to_file($url, $source)) { echo "ERROR: Cannot download the Google Sheet. Nothing was uploaded.\n"; return false; }
            echo "Saved as: [$source]\n";
        }
        $text = file_get_contents($source);
        if(ltrim($text) !== '' && ltrim($text)[0] === '<') { // e.g. a Google login page, if the sheet is no longer shared
            echo "ERROR: [$source] is a web page, not a TSV. Is the Google Sheet still shared ('anyone with the link')? Nothing was uploaded.\n"; return false;
        }
        $lines  = explode("\n", $text);
        $header = explode("\t", rtrim($lines[0], "\r"));
        $col    = array(); // published column => its position in the sheet (found by name, so the sheet's column order may change)
        foreach(self::COLUMNS as $name) { $i = array_search($name, $header, true); if($i !== false) $col[$name] = $i; }
        $missing = array_values(array_diff(self::COLUMNS, array_keys($col)));
        if($missing) {
            foreach($missing as $name) self::add('ERROR', 'header', 1, '', '', '', $name, '', "column [$name] is missing from the header");
            return self::finish(0, false, $dry_run, $publish);
        }
        // 2. rows: sheet_row => array(column => value); blank lines dropped
        $rows = array();
        foreach($lines as $i => $line) {
            if($i == 0) continue;
            $line = rtrim($line, "\r");
            if(trim($line) === '') continue;
            $values = explode("\t", $line);
            if(count($values) != count($header)) self::add('ERROR', 'header', $i + 1, '', '', '', '', mb_substr($line, 0, 200),
                                                         count($values) . " fields, the header has " . count($header) . " (a tab or a line break inside a cell?)");
            $rec = array();
            foreach(self::COLUMNS as $name) $rec[$name] = $values[$col[$name]] ?? '';
            $rows[$i + 1] = $rec; // as numbered in the Google Sheet (header = row 1)
        }
        $out = array(implode("\t", self::COLUMNS));
        foreach($rows as $rec) $out[] = implode("\t", $rec);
        $terms_tsv = $this->work_dir . "terms.tsv";
        $tmp = "$terms_tsv.tmp"; // write to a temp file first, so a failed write can't leave a half-written TSV
        if(file_put_contents($tmp, implode("\r\n", $out)) === false || !rename($tmp, $terms_tsv)) { @unlink($tmp); echo "ERROR: Cannot write [$terms_tsv]\n"; return false; }
        echo "Built: [$terms_tsv] (" . number_format(count($rows)) . " rows, " . count(self::COLUMNS) . " columns)\n";

        // 3. QC
        if(($zenodo = self::get_zenodo_terms()) === false) return false;
        self::qc($rows, $zenodo['dup_groups']);
        $ret = self::finish(count($rows), $terms_tsv, $dry_run, $publish, $zenodo);
        if($ret) $ret['source'] = $source;
        return $ret;
    }
    private function qc($rows, $zenodo_dup_groups)
    {
        $by_uri = array(); $by_name_type = array();
        foreach($rows as $r => $rec) {
            [$name, $type, $uri] = array($rec['name'], $rec['type'], $rec['uri']);
            // #3/#4 primary fields not blank
            foreach(self::PRIMARY_FIELDS as $f) if(trim($rec[$f]) === '') self::add('ERROR', 'blank primary field', $r, $uri, $name, $type, $f, $rec[$f], "[$f] is blank");
            // leading/trailing spaces in a checked field
            foreach(array_unique(array_merge(self::PRIMARY_FIELDS, self::URI_FIELDS, self::BOOLEAN_FIELDS)) as $f) {
                $v = $rec[$f];
                if(trim($v) !== '' && preg_match('/^[\s\p{Z}]|[\s\p{Z}]$/u', $v)) self::add('ERROR', 'leading/trailing space', $r, $uri, $name, $type, $f, $v, "[$f] starts or ends with a space");
            }
            // #5 / #6 URI fields
            foreach(self::URI_FIELDS as $f) {
                $v = $rec[$f];
                if(trim($v) === '') continue;
                if(in_array($f, self::MULTI_URI_FIELDS)) {
                    if(preg_match('/[,;]/', $v)) self::add('ERROR', 'separator', $r, $uri, $name, $type, $f, $v, "[$f] separator must be | (found , or ;)");
                    foreach(explode("|", $v) as $k => $part) {
                        if($part === '') { self::add('ERROR', 'separator', $r, $uri, $name, $type, $f, $v, "[$f] has an empty value (|| or a leading/trailing |)"); continue; }
                        if($msg = self::uri_problem($part)) self::add('ERROR', 'not a URI', $r, $uri, $name, $type, $f, $v, "[$f] value " . ($k + 1) . " [$part]: $msg");
                    }
                }
                elseif($msg = self::uri_problem($v)) self::add('ERROR', 'not a URI', $r, $uri, $name, $type, $f, $v, "[$f]: $msg");
            }
            // #7 boolean fields
            foreach(self::BOOLEAN_FIELDS as $f) if(!in_array($rec[$f], array('', 'TRUE', 'FALSE'), true)) self::add('ERROR', 'boolean', $r, $uri, $name, $type, $f, $rec[$f], "[$f] must be TRUE, FALSE or blank");
            // #8 [type] is one of the valid types (exact match; a blank type is already reported above)
            if(trim($type) !== '' && !in_array($type, self::TYPES, true)) self::add('ERROR', 'invalid type', $r, $uri, $name, $type, 'type', $type, "[type] must be one of: " . implode(", ", self::TYPES));
            if($uri !== '') $by_uri[$uri][] = $r;
            if($name !== '' && $type !== '') $by_name_type["$name\t$type"][] = $r;
        }
        // #1 uri unique
        foreach($by_uri as $uri => $rs) {
            if(count($rs) < 2) continue;
            foreach($rs as $r) self::add('ERROR', 'duplicate uri', $r, $uri, $rows[$r]['name'], $rows[$r]['type'], 'uri', $uri, "[uri] is on " . count($rs) . " rows: " . implode(", ", $rs));
        }
        // #9 [parent uris]: a parent must be a term in this file, of the same [type] as the row
        foreach($rows as $r => $rec) {
            if(trim($rec['parent uris']) === '' || trim($rec['type']) === '') continue;
            foreach(explode("|", $rec['parent uris']) as $part) {
                if($part === '') continue; // already reported as a separator error
                if(!isset($by_uri[$part])) {
                    self::add('ERROR', 'parent uri not in the Terms file', $r, $rec['uri'], $rec['name'], $rec['type'], 'parent uris', $part, "parent [$part] is not a [uri] in the Terms file, so its type can't be checked");
                    continue;
                }
                $parent_types = array_values(array_unique(array_map(fn($pr) => $rows[$pr]['type'], $by_uri[$part])));
                if($parent_types != array($rec['type'])) self::add('ERROR', 'parent of a different type', $r, $rec['uri'], $rec['name'], $rec['type'], 'parent uris', $part,
                    "parent [$part] (row " . implode(", ", $by_uri[$part]) . ") is of type [" . implode("/", $parent_types) . "], this row is [{$rec['type']}]: parents must be of the same type");
            }
        }
        // #2 name + type unique; a duplicate already in Zenodo's latest file (same group, no new uri) is only a warning
        foreach($by_name_type as $key => $rs) {
            if(count($rs) < 2) continue;
            [$name, $type] = explode("\t", $key, 2);
            $uris = array_map(fn($r) => $rows[$r]['uri'], $rs);
            $existing = isset($zenodo_dup_groups[$key]) && !array_diff($uris, $zenodo_dup_groups[$key]);
            foreach($rs as $r) self::add($existing ? 'WARNING' : 'ERROR', $existing ? 'duplicate name+type (already on Zenodo)' : 'duplicate name+type (new)',
                                         $r, $rows[$r]['uri'], $name, $type, 'name+type', "$name | $type",
                                         "[name]+[type] is on " . count($rs) . " rows: " . implode(", ", $rs) . ($existing ? " -- already a duplicate in Zenodo's latest file" : ''));
        }
    }
    private function uri_problem($v)
    {   // '' if $v is a valid URI (http:// or https://, a host, no whitespace or hidden characters), else what's wrong
        if(preg_match('/[\s\p{Z}\p{C}]/u', $v, $m, PREG_OFFSET_CAPTURE)) {
            $cp = mb_ord($m[0][0], 'UTF-8');
            $names = array(0x20 => 'space', 0xA0 => 'non-breaking space', 0x09 => 'tab', 0x200B => 'zero-width space', 0xFEFF => 'byte order mark');
            return sprintf("contains U+%04X%s at position %d", $cp, isset($names[$cp]) ? " ({$names[$cp]})" : '', mb_strlen(substr($v, 0, $m[0][1]), 'UTF-8') + 1);
        }
        if(!preg_match('#^https?://[^/?\#]+#i', $v)) return "not a URI (must start with http:// or https://, then a host)";
        return '';
    }
    private function get_zenodo_terms()
    {   // Zenodo's latest Terms version (fresh): its terms file is downloaded to a temp file (deleted afterwards) to find its name+type duplicate groups.
        // Returns array('record_id', 'checksum', 'dup_groups' => array("name\ttype" => array(uri, ...))), or false.
        $concept_id = $this->main_datasets['Terms_conceptID'];
        if(!($latest = $this->get_zenodo_info_using_conceptID($concept_id, 0))) { echo "ERROR: Cannot get the latest Terms version from Zenodo\n"; return false; }
        $file = null;
        foreach($latest['files'] as $f) if(basename($f['key']) == self::ZENODO_FILENAME) { $file = $f; break; }
        if(!$file) { echo "ERROR: Zenodo's latest Terms version (record {$latest['id']}) has no [" . self::ZENODO_FILENAME . "]\n"; return false; }
        $tmp = tempnam(sys_get_temp_dir(), 'terms_zenodo_');
        if(!$this->download_url_to_file($file['url'], $tmp)) { @unlink($tmp); echo "ERROR: Cannot download Zenodo's " . self::ZENODO_FILENAME . "\n"; return false; }
        $lines = explode("\n", file_get_contents($tmp)); unlink($tmp);
        $h = explode("\t", rtrim($lines[0], "\r"));
        [$in, $it, $iu] = array(array_search('name', $h), array_search('type', $h), array_search('uri', $h));
        $groups = array(); $n = 0;
        foreach($lines as $i => $line) {
            if($i == 0 || trim($line) === '') continue;
            $v = explode("\t", rtrim($line, "\r")); $n++;
            $groups[($v[$in] ?? '') . "\t" . ($v[$it] ?? '')][] = $v[$iu] ?? '';
        }
        echo "Zenodo's latest Terms: record {$latest['id']}, [" . self::ZENODO_FILENAME . "] " . number_format($n) . " rows\n";
        return array('record_id' => (string) $latest['id'], 'checksum' => strtolower((string) $file['checksum']),
                     'dup_groups' => array_filter($groups, fn($uris) => count($uris) > 1));
    }
    private function add($severity, $check, $row, $uri, $name, $type, $field, $value, $message)
    {   // one QC finding = one report row; tabs and line breaks in values are replaced, so the report stays a valid TSV
        $clean = fn($s) => str_replace(array("\t", "\r", "\n"), ' ', (string) $s);
        $this->report[] = array_map($clean, array($severity, $check, $row, $uri, $name, $type, $field, $value, $message));
    }
    private function finish($n_rows, $terms_tsv, $dry_run, $publish, $zenodo = null)
    {   // 4. report if any ERROR; 5. else upload (not in a dry run)
        $errors   = array_values(array_filter($this->report, fn($x) => $x[0] == 'ERROR'));
        $warnings = array_values(array_filter($this->report, fn($x) => $x[0] == 'WARNING'));
        $ret = array('rows' => $n_rows, 'errors' => count($errors), 'warnings' => count($warnings), 'report' => null, 'uploaded' => false);
        $by_check = array(); foreach($this->report as $x) @$by_check["$x[0]: $x[1]"]++;
        ksort($by_check);
        echo "\nQC:\n"; if(!$by_check) echo "    no problems found\n";
        foreach($by_check as $k => $n) echo "    $k: " . number_format($n) . " row(s)\n";
        if($errors) {
            $ret['report'] = $this->work_dir . "Terms_file_errors_" . date("Y-m-d-H-i") . ".tsv";
            $lines = array("severity\tcheck\tsheet_row\turi\tname\ttype\tfield\tvalue\tmessage");
            foreach(array_merge($errors, $warnings) as $x) $lines[] = implode("\t", $x);
            file_put_contents($ret['report'], implode("\n", $lines) . "\n");
            foreach(array_slice($errors, 0, 10) as $x) echo "    row $x[2]: $x[8]\n";
            echo "\nQC failed: " . number_format(count($errors)) . " error(s). Report: [{$ret['report']}]\nNothing was uploaded.\n";
            return $ret;
        }
        echo "\nQC passed" . ($warnings ? " (" . number_format(count($warnings)) . " warning(s): name+type duplicates already in Zenodo's latest file)" : '') . ".\n";
        if($dry_run) { echo "Dry run: nothing was uploaded.\n"; return $ret; }
        if($zenodo && $zenodo['checksum'] === "md5:" . md5_file($terms_tsv)) {
            echo "Zenodo's latest version (record {$zenodo['record_id']}) already has this exact file. Nothing to upload.\n"; return $ret;
        }
        $options = array('filename' => self::ZENODO_FILENAME, 'publish' => $publish);
        if(isset($this->param['version'])) $options['version'] = (string) $this->param['version'];
        $up = $this->upload_new_version_to_zenodo($this->main_datasets['Terms_conceptID'], $terms_tsv, $options);
        $ret['uploaded'] = $up ? ($up['published'] ? "published record " . $up['record_id'] : "draft " . $up['draft_url']) : false;
        if($up && $up['published']) {
            echo "\nTo refresh our local copy (TB_files) used by the pipelines:\n"
               . "php generate_csv_new_model.php _ '{\"concept_id\": \"" . $this->main_datasets['Terms_conceptID'] . "\", \"redownload_zip_file_YN\": 1, \"task\": \"download_only\", \"what\": \"Main:Terms file\"}'\n";
        }
        return $ret;
    }
}
?>
