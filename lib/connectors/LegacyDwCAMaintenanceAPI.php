<?php
namespace php_active_record;
/* Library for the Legacy DwCA process: maintenance of the legacy DwCA resources on Zenodo (e.g. AmphibiaWeb, concept ID 13318109).
Called from: update_resources/connectors/process_legacy_dwca.php
Downloads go to [CONTENT_RESOURCE_LOCAL_PATH]/Legacy_DwCA_files/ (the References/TraitBank tasks use TB_files/).
Re-uses the Zenodo download code of ZenodoTraitBankAPI.
Tasks:
- download_file_from_zenodo: see process_legacy_dwca.php
- convert_dwca_to_new_input_files: a legacy DwCA -> TraitBank input files Taxon.tsv, Traits.tsv and References.tsv, the same kind of files as the
  Zenodo TraitBank datasets' input_files (e.g. TB_files/22943466_Sexual_Syst_and_Char_TraitBank/input_files/). Rules in process_legacy_dwca.php.
----------------------------------------------------
*/
// convert_dwca_to_new_input_files: child MoF records (with a parentMeasurementID) that must NOT become a metadata column in Traits.tsv.
// By short name (e.g. 'contributor') or full measurementType URI (e.g. 'http://purl.org/dc/terms/contributor').
// Default: none excluded, every child record becomes a column. Edit this list as needed.
$GLOBALS['excluded_metadata_as_columns'] = array();

// convert_dwca_to_new_input_files: the columns of the 21 Zenodo TraitBank datasets (their input_files taxon.tsv/taxon.txt and traits.tsv headers,
// as of Nov 2026), by short name. Columns of Taxon.tsv / Traits.tsv not in these lists are kept, and listed on screen and in the report
// as new columns introduced by the legacy DwCA. taxonID and TaxonID: both spellings are used by the datasets. Add new columns here as needed.
$GLOBALS['taxon_columns'] = array('taxonID', 'TaxonID', 'canonicalName', 'scientificName', 'parentNameUsageID', 'taxonRank', 'taxonKey', 'taxonMap',
    'taxonRemarks', 'source', 'higherClassification', 'kingdom', 'phylum', 'class', 'order', 'family', 'superfamily', 'genus');
$GLOBALS['trait_columns'] = array('measurementID', 'taxonID', 'TaxonID', 'scientificName', 'taxonKey', 'infer', 'exclude', 'measurementType',
    'measurementValue', 'measurementRemarks', 'measurementUnit', 'statisticalMethod', 'lifeStage', 'sex', 'bodyPart', 'SampleSize',
    'verbatimScientificName', 'Standard_Deviation', // Standard_Deviation: http://ncicb.nci.nih.gov/xml/owl/EVS/Thesaurus.owl#Standard_Deviation
    'source', 'referenceID');

use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class LegacyDwCAMaintenanceAPI extends ZenodoTraitBankAPI
{
    function __construct($param) {
        parent::__construct();
        $this->param = $param; print_r($param);
        $this->zenodo_folder = 'Legacy_DwCA_files'; // used by zenodo_local_dir() in ZenodoTraitBankAPI
    }
    function start()
    {   // Runs the task; also returns its result.
        $task = $this->param['task'];
        // one run at a time: a second run waits here until the first one is done. Released when this function returns.
        $lock_dir = $this->zenodo_local_dir();
        if(!is_dir($lock_dir)) @mkdir($lock_dir, 0777, true);
        $lock = @fopen($lock_dir . ".legacy_dwca_mtce.lock", 'c');
        if($lock) { @chmod($lock_dir . ".legacy_dwca_mtce.lock", 0666); flock($lock, LOCK_EX); }
        $result = null;

        if($task == 'download_file_from_zenodo') {
            $result = self::download_file_from_zenodo();
        }
        elseif($task == 'convert_dwca_to_new_input_files') {
            $result = self::convert_dwca_to_new_input_files();
        }
        else echo "\nERROR: Unknown task [$task].\n";

        if($lock) { flock($lock, LOCK_UN); fclose($lock); }
        return $result;
    }
    function download_file_from_zenodo()
    {   // e.g. concept_id 13318109 (AmphibiaWeb): Zenodo's latest version -> Legacy_DwCA_files/13318109_AmphibiaWeb_Amph_text_w_trai_base_on_Pens_Anno/input_files/Archive.zip
        // Downloads only if Zenodo's latest version differs from ours (Legacy_DwCA_files/[concept_id].record_id). Not extracted.
        // Optional "redownload_zip_file_YN": 1 -> asks Zenodo for its latest version now (not from the 1-day cache).
        $concept_id = trim((string) @$this->param['concept_id']);
        if(!preg_match('/^\d+$/', $concept_id)) exit("\nERROR: Missing or invalid \"concept_id\", e.g. \"concept_id\": \"13318109\".\n");

        // Step 1: get latest Zenodo record info using the concept ID
        if(!($arr = $this->get_zenodo_info_using_conceptID($concept_id))) exit("\nERROR: Cannot get Zenodo info for concept ID [$concept_id].\n");
        echo "\nZenodo: [{$arr['title']}] concept ID $concept_id, latest record {$arr['id']}" . ($arr['version'] ? ", version {$arr['version']}" : '') . "\n";
        // folder name: [concept_id]_[supplied_name] e.g. "supplied_name": "AmphibiaWeb" -> 13318109_AmphibiaWeb
        // without supplied_name: [concept_id]_[abbreviated title] e.g. 13318109_AmphibiaWeb_Amph_text_w_trai_base_on_Pens_Anno
        $supplied_name = trim((string) @$this->param['supplied_name']);
        if($supplied_name !== '') {
            if(!preg_match('/^[A-Za-z0-9_-]+$/', $supplied_name)) exit("\nERROR: \"supplied_name\" [$supplied_name] may only have letters, digits, _ and -, e.g. \"supplied_name\": \"AmphibiaWeb\".\n");
            $folder_name = $concept_id . "_" . $supplied_name;
        }
        else $folder_name = $this->get_dataset_folder_name($concept_id, $arr['title']);

        // Step 2, 1st option: the latest version's zip archive -> Legacy_DwCA_files/[concept_id].zip (not extracted)
        if($arr['archive_url'] && ($zip_file = $this->download_zenodo_zip_file($arr['archive_url'], $concept_id))) {
            $paths = array($zip_file);
        }
        // Step 2, 2nd option: no archive_url (e.g. AmphibiaWeb), download the record's files individually
        //         -> Legacy_DwCA_files/[concept_id]_[abbreviated title]/input_files/
        elseif($arr['files']) {
            echo "No usable Zenodo archive URL, will download the files individually.\n";
            if(!($input_dir = $this->download_zenodo_files_individually($arr, $concept_id, $folder_name))) exit("\nERROR: Cannot download Zenodo files individually.\n");
            $paths = array();
            foreach($arr['files'] as $f) $paths[] = $input_dir . basename($f['key']);
        }
        else exit("\nERROR: Zenodo record has no archive URL and no files.\n");

        echo "\nLocal file(s):\n";
        foreach($paths as $path) echo " - $path (" . (is_file($path) ? number_format(filesize($path)) . " bytes" : "missing") . ")\n";

        // Step 3 (optional "file_type", e.g. ".tar.gz"): the final version -> CONTENT_RESOURCE_LOCAL_PATH/[concept_id]_[supplied_name][file_type]
        // e.g. 13318109_AmphibiaWeb.tar.gz. Converted if Zenodo's file is another type (e.g. .zip), else copied as is.
        if(isset($this->param['file_type'])) {
            $file_type = strtolower(trim((string) $this->param['file_type']));
            $final = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/" . $folder_name . $file_type;
            if(!($source = self::pick_archive_file($paths))) exit("\nERROR: Cannot tell which downloaded file to save as [$file_type].\n");
            if(!self::save_final_version($source, $final, $file_type, dirname($source, 2) . "/temp_convert/")) exit("\nERROR: Cannot save final version [$final].\n");
            $paths['final'] = $final;
        }
        return $paths;
    }
    /*================================================================= convert_dwca_to_new_input_files =================================================================*/
    // Taxon.tsv: these columns first, in this order (when present), then every other taxon column in file order
    const TAXON_COLUMNS = array('taxonID', 'canonicalName', 'scientificName', 'parentNameUsageID', 'taxonKey', 'taxonMap', 'taxonRank', 'kingdom', 'phylum',
                                'class', 'order', 'family', 'superfamily', 'genus', 'higherClassification', 'taxonRemarks', 'source');
    const MOF_NOT_CARRIED = array('occurrenceID', 'measurementOfTaxon', 'parentMeasurementID'); // MoF columns not written to Traits.tsv
    const TRAITS_FIRST    = array('measurementID', 'taxonID', 'scientificName', 'taxonKey');      // Traits.tsv: first columns
    const TRAITS_LAST     = array('source', 'referenceID');                                       // Traits.tsv: last columns, as in the Zenodo TraitBank datasets
    function convert_dwca_to_new_input_files()
    {   // e.g. concept_id 13318109, supplied_name AmphibiaWeb -- or the same as "source": "13318109_AmphibiaWeb_TB_1_0.tar.gz":
        //   CONTENT_RESOURCE_LOCAL_PATH/13318109_AmphibiaWeb_TB_1_0.tar.gz -> TB_files_from_DwCA/13318109_AmphibiaWeb_TraitBank/input_files/Taxon.tsv and Traits.tsv
        $both_ways = "Give either \"concept_id\" + \"supplied_name\", e.g. \"concept_id\": \"13318109\", \"supplied_name\": \"AmphibiaWeb\", or \"source\", e.g. \"source\": \"13318109_AmphibiaWeb_TB_1_0.tar.gz\".";
        $concept_id    = trim((string) @$this->param['concept_id']);
        $supplied_name = trim((string) @$this->param['supplied_name']);
        $source        = trim((string) @$this->param['source']);
        if($source !== '') { // the input file's name in CONTENT_RESOURCE_LOCAL_PATH: [concept_id]_[supplied_name]_TB_1_0.tar.gz
            if(strpos($source, '/') !== false || strpos($source, '\\') !== false) exit("\nERROR: \"source\" [$source] must be a file name only (no folder): the file in CONTENT_RESOURCE_LOCAL_PATH, e.g. \"source\": \"13318109_AmphibiaWeb_TB_1_0.tar.gz\".\n");
            if(!preg_match('/^(\d+_[A-Za-z0-9_-]+)_TB_1_0\.tar\.gz$/', $source, $m)) exit("\nERROR: Invalid \"source\" [$source]: it must be [concept_id]_[supplied_name]_TB_1_0.tar.gz (supplied_name: letters, digits, _ and - only), e.g. \"source\": \"13318109_AmphibiaWeb_TB_1_0.tar.gz\".\n");
            $name = $m[1];
            if($concept_id !== '' || $supplied_name !== '') { // also given: must name the same file
                $other = $concept_id . "_" . $supplied_name;
                if($other !== $name) exit("\nERROR: \"source\" [$source] and \"concept_id\" + \"supplied_name\" [{$other}_TB_1_0.tar.gz] name different files. Give only one of them.\n");
            }
        }
        else {
            if($concept_id === '' && $supplied_name === '') exit("\nERROR: No input given. $both_ways\n");
            if(!preg_match('/^\d+$/', $concept_id)) exit("\nERROR: Missing or invalid \"concept_id\" [$concept_id]. $both_ways\n");
            if(!preg_match('/^[A-Za-z0-9_-]+$/', $supplied_name)) exit("\nERROR: Missing or invalid \"supplied_name\" [$supplied_name]: letters, digits, _ and - only. $both_ways\n");
            $name = $concept_id . "_" . $supplied_name;
        }
        $dwca = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/" . $name . "_TB_1_0.tar.gz";
        if(!is_file($dwca)) exit("\nERROR: Legacy DwCA not found: [$dwca]\n");
        $out_root = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files_from_DwCA";
        $this->conv_out_dir = "$out_root/{$name}_TraitBank/";
        $this->conv_temp_dir = '';
        $this->conv_debug = array();
        $this->conv_columns = array();
        $this->conv_ref_used = array(); // referenceIDs used in Traits.tsv
        echo "\nConverting [$dwca]\n      -> [{$this->conv_out_dir}input_files/]\n";

        // read the DwCA
        require_library('connectors/INBioAPI');
        $func = new INBioAPI();
        $paths = $func->extract_archive_file($dwca, "meta.xml", array('timeout' => 172800, 'expire_seconds' => 0));
        if(!$paths) exit("\nERROR: Cannot extract [$dwca], or it has no meta.xml.\n");
        $this->conv_temp_dir = $paths['temp_dir'];
        $harvester = new ContentArchiveReader(NULL, $paths['archive_path']);
        $tables = $harvester->tables; // keys are lower-case rowTypes
        $taxon_meta = @$tables['http://rs.tdwg.org/dwc/terms/taxon'][0];
        $mof_meta   = @$tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0];
        $occ_meta   = @$tables['http://rs.tdwg.org/dwc/terms/occurrence'][0];
        $ref_meta   = @$tables['http://eol.org/schema/reference/reference'][0];
        if(!$taxon_meta) self::conv_exit("The DwCA has no taxon table.");
        if(!$mof_meta)   self::conv_exit("The DwCA has no MoF (measurement or fact) table.");
        if(!$occ_meta)   self::conv_exit("The DwCA has no occurrence table: it's needed to link each MoF row to its taxon (occurrenceID -> taxonID).");
        self::conv_input_row_counts($tables);

        // a fresh output folder each run
        if(is_dir($this->conv_out_dir)) recursive_rmdir($this->conv_out_dir);
        if(!mkdir($this->conv_out_dir . "input_files/", 0775, true)) self::conv_exit("Cannot create folder [{$this->conv_out_dir}input_files/].");

        $taxa     = self::conv_write_taxon($taxon_meta, $this->conv_out_dir . "input_files/Taxon.tsv");
        $occ      = self::conv_read_occurrences($occ_meta);
        $children = self::conv_collect_child_metadata($mof_meta);
        self::conv_write_traits($mof_meta, $this->conv_out_dir . "input_files/Traits.tsv", $taxa, $occ, $children);
        self::conv_write_references($ref_meta, $this->conv_out_dir . "input_files/References.tsv");

        recursive_rmdir($this->conv_temp_dir);
        foreach($this->conv_debug as $topic => $arr) { // counts as "name: count" lines in the report
            foreach($arr as $sub => $v) if(is_int($v)) { unset($this->conv_debug[$topic][$sub]); $this->conv_debug[$topic]["$sub: $v"] = ''; }
        }
        Functions::start_print_debug($this->conv_debug, $name . "_convert", $out_root);
        echo "\nReport: [$out_root/{$name}_convert_debug_" . date('Y-m-d') . ".txt]\nDone: [{$this->conv_out_dir}input_files/]\n";
        return array('Taxon' => $this->conv_out_dir . "input_files/Taxon.tsv", 'Traits' => $this->conv_out_dir . "input_files/Traits.tsv",
                     'References' => $this->conv_out_dir . "input_files/References.tsv");
    }
    private function conv_input_row_counts($tables)
    {   // rows per file in the legacy DwCA (header and blank lines not counted), and what the conversion does with each file
        $use = array('http://rs.tdwg.org/dwc/terms/taxon'             => 'Taxon.tsv',
                     'http://rs.tdwg.org/dwc/terms/measurementorfact' => 'Traits.tsv',
                     'http://rs.tdwg.org/dwc/terms/occurrence'        => 'links MoF rows to their taxa; extra columns go to Traits.tsv',
                     'http://eol.org/schema/reference/reference'      => 'References.tsv (only the references Traits.tsv uses)');
        $topic = "Rows in the legacy DwCA (input)";
        echo "\n$topic:\n";
        foreach($tables as $row_type => $metas) {
            foreach($metas as $meta) {
                $n = 0; $i = 0;
                if($f = fopen($meta->file_uri, 'r')) {
                    while(($row = fgets($f)) !== false) {
                        $i++;
                        if($i <= (int) $meta->ignore_header_lines || trim($row) === '') continue;
                        $n++;
                    }
                    fclose($f);
                }
                $extra = '';
                if($row_type == 'http://rs.tdwg.org/dwc/terms/measurementorfact') {
                    $child = 0;
                    foreach(self::conv_table_rows($meta) as list($line, $rec)) if((string) @$rec['parentMeasurementID'] !== '') $child++;
                    $extra = " (" . number_format($n - $child) . " without a parentMeasurementID, " . number_format($child) . " child rows)";
                }
                $l = str_pad(basename($meta->file_uri), 32) . str_pad(number_format($n), 10, ' ', STR_PAD_LEFT) . " rows$extra -- " . (@$use[$row_type] ?: "not used") . " [$row_type]";
                echo " - $l\n";
                $this->conv_debug[$topic][$l] = '';
            }
        }
    }
    private function conv_exit($reason)
    {   // stops the conversion: no half-written output is left
        echo "\nERROR: $reason\nWill terminate.\n";
        if($this->conv_temp_dir && is_dir($this->conv_temp_dir)) recursive_rmdir($this->conv_temp_dir);
        if(@$this->conv_out_dir && is_dir($this->conv_out_dir)) recursive_rmdir($this->conv_out_dir);
        exit(1);
    }
    private function conv_report_new_columns($file_label, $columns, $known)
    {   // information only: columns not in the 21 Zenodo TraitBank datasets ($GLOBALS['taxon_columns'] / ['trait_columns']) are kept
        $topic = "New columns: not in the 21 Zenodo TraitBank datasets (kept)";
        $new = array_values(array_diff($columns, $known));
        if(!$new) { echo "\n$file_label: no new columns"; $this->conv_debug[$topic]["$file_label: no new columns"] = ''; return; }
        echo "\nNOTE: $file_label has " . count($new) . " column(s) not in the 21 Zenodo TraitBank datasets: " . implode(', ', $new) . " (kept)";
        foreach($new as $c) $this->conv_debug[$topic]["$file_label: $c"] = '';
    }
    private function conv_short_name($uri)
    {   // column name from a term URI, e.g. 'http://purl.org/dc/terms/contributor' -> 'contributor' (same rule as Functions::shorten_record())
        return (string) key(Functions::shorten_record(array($uri => '')));
    }
    private function conv_table_columns($meta)
    {   // column position => name, from meta.xml; plus columns in the file's header row beyond those in meta.xml (e.g. sex, lifeStage added by hand)
        if(isset($this->conv_columns[$meta->file_uri])) return $this->conv_columns[$meta->file_uri];
        $names = array();
        foreach($meta->fields as $index => $field) if($field['term']) $names[$index] = self::conv_short_name($field['term']);
        if($meta->ignore_header_lines && ($f = fopen($meta->file_uri, 'r'))) {
            $head = explode("\t", rtrim((string) fgets($f), "\r\n"));
            fclose($f);
            $max = $names ? max(array_keys($names)) : -1;
            foreach($head as $index => $h) {
                $h = trim($h);
                if($index <= $max || $h === '' || in_array($h, $names)) continue;
                $names[$index] = $h;
                $this->conv_debug["Columns read from the file's header row (not in meta.xml)"][basename($meta->file_uri) . ": $h"] = '';
            }
        }
        return $this->conv_columns[$meta->file_uri] = $names;
    }
    private function conv_table_rows($meta)
    {   // yields array(line number, record keyed by column name)
        $names = self::conv_table_columns($meta);
        $i = 0;
        foreach(new FileIterator($meta->file_uri) as $row) {
            $i++;
            if($i <= (int) $meta->ignore_header_lines) continue;
            $row = rtrim((string) $row, "\r\n");
            if(trim($row) === '') continue;
            $tmp = explode("\t", $row);
            $rec = array();
            foreach($names as $index => $col) $rec[$col] = trim((string) @$tmp[$index]);
            yield array($i, $rec);
        }
    }
    private function conv_write_taxon($meta, $file)
    {   // taxon table -> Taxon.tsv (EOLid renamed taxonKey). Returns taxonID => array(scientificName, taxonKey)
        $where = "Taxon table [" . basename($meta->file_uri) . "]";
        $cols = array_values(self::conv_table_columns($meta));
        $required = "Required: taxonID, scientificName (never blank), taxonKey (or EOLid) and taxonMap (may be blank).";
        foreach(array('taxonID', 'scientificName', 'taxonMap') as $c) if(!in_array($c, $cols)) self::conv_exit("$where has no [$c] column. $required");
        $has_eolid = in_array('EOLid', $cols);
        if($has_eolid && in_array('taxonKey', $cols)) self::conv_exit("$where has both EOLid and taxonKey columns. EOLid becomes taxonKey, so it must have only one of them.");
        if(!$has_eolid && !in_array('taxonKey', $cols)) self::conv_exit("$where has no [taxonKey] column (nor EOLid, which becomes taxonKey). $required");
        if($has_eolid) $cols[array_search('EOLid', $cols)] = 'taxonKey';
        // taxonRemarks: only real remarks are kept (match_taxa_2DH's notes removed); the column is left out if no real remark is left
        $remarks = array('kept' => 0, 'notes removed' => 0);
        if(in_array('taxonRemarks', $cols)) {
            $any = false;
            foreach(self::conv_table_rows($meta) as list($line, $rec)) if(self::conv_clean_taxon_remarks($rec['taxonRemarks']) !== '') { $any = true; break; }
            if(!$any) {
                $cols = array_values(array_diff($cols, array('taxonRemarks')));
                $l = "Taxon.tsv: taxonRemarks left out, it had only match_taxa_2DH's notes (e.g. 'Trait: [ IndexGroup:[...] ... ]', 'No canonical match')";
                echo "\n$l"; $this->conv_debug["Counts"][$l] = '';
            }
        }
        $order = array_merge(array_values(array_intersect(self::TAXON_COLUMNS, $cols)), array_values(array_diff($cols, self::TAXON_COLUMNS)));

        $f = Functions::file_open($file, "w");
        fwrite($f, implode("\t", $order) . "\n");
        self::conv_report_new_columns('Taxon.tsv', $order, $GLOBALS['taxon_columns']);
        $taxa = array(); $written = 0;
        foreach(self::conv_table_rows($meta) as list($line, $rec)) {
            if($has_eolid) { $rec['taxonKey'] = $rec['EOLid']; unset($rec['EOLid']); }
            if(isset($rec['higherClassification'])) $rec['higherClassification'] = trim($rec['higherClassification'], "| "); // 'Animalia|...|Ranidae|' -> 'Animalia|...|Ranidae'
            if(in_array('taxonRemarks', $order)) {
                $clean = self::conv_clean_taxon_remarks($rec['taxonRemarks']);
                if($clean !== $rec['taxonRemarks']) $remarks['notes removed']++;
                if($clean !== '') $remarks['kept']++;
                $rec['taxonRemarks'] = $clean;
            }
            if($rec['taxonID'] === '')        { fclose($f); self::conv_exit("$where, line $line: blank taxonID (scientificName [{$rec['scientificName']}]). $required"); }
            if($rec['scientificName'] === '') { fclose($f); self::conv_exit("$where, line $line: blank scientificName (taxonID [{$rec['taxonID']}]). $required"); }
            if(isset($taxa[$rec['taxonID']])) { // keep the first
                $this->conv_debug["Taxon: duplicate taxonID, only its first row written"]["taxonID {$rec['taxonID']}, line $line"] = '';
                continue;
            }
            $out = array();
            foreach($order as $c) $out[] = @$rec[$c];
            fwrite($f, implode("\t", $out) . "\n");
            $taxa[$rec['taxonID']] = array($rec['scientificName'], $rec['taxonKey']);
            $written++;
        }
        fclose($f);
        echo "\nTaxon.tsv: [" . number_format($written) . "] rows" . ($has_eolid ? ", EOLid renamed taxonKey" : '') . ".\n";
        $this->conv_debug["Counts"]["Taxon.tsv rows: $written" . ($has_eolid ? " (EOLid renamed taxonKey)" : '')] = '';
        if(in_array('taxonRemarks', $order)) {
            $l = "Taxon.tsv taxonRemarks: real remarks kept in {$remarks['kept']} rows; match_taxa_2DH's notes removed in {$remarks['notes removed']} rows";
            echo "$l\n"; $this->conv_debug["Counts"][$l] = '';
        }
        return $taxa;
    }
    private function conv_clean_taxon_remarks($str)
    {   // taxonRemarks without the notes match_taxa_2DH.php writes while matching names to EOL IDs (DwCA_MatchTaxa2DH.php), e.g.
        // 'Trait: [ IndexGroup:[Tetrapoda] - IndexHC:[...] ]', 'No canonical match'. Notes are joined to other remarks with ' || '.
        $notes = array('Cannot be matched at all', 'No canonical match', 'No higherClassification', 'With higherClassification but cannot be mapped to any index group.');
        $keep = array();
        foreach(explode(' || ', (string) $str) as $part) {
            $part = trim(str_replace('-no rank-', '', $part));
            $part = trim(preg_replace('/\s*=>\s*conflict IndexGroup mapping \[[^\]]*\]/', '', $part));
            if($part === '' || in_array($part, $notes) || preg_match('/^Trait: \[ IndexGroup:/', $part)) continue;
            $keep[] = $part;
        }
        return implode(' || ', $keep);
    }
    private function conv_read_occurrences($meta)
    {   // occurrenceID => array('taxonID' => ..., 'meta' => array(column => value)); 'meta' = any other non-blank column, e.g. sex, lifeStage
        $where = "Occurrence table [" . basename($meta->file_uri) . "]";
        $cols = array_values(self::conv_table_columns($meta));
        foreach(array('occurrenceID', 'taxonID') as $c) if(!in_array($c, $cols)) self::conv_exit("$where has no [$c] column.");
        $extra = array_values(array_diff($cols, array('occurrenceID', 'taxonID')));
        $occ = array(); $n = 0;
        foreach(self::conv_table_rows($meta) as list($line, $rec)) {
            $n++;
            $m = array();
            foreach($extra as $c) if($rec[$c] !== '') $m[$c] = $rec[$c];
            $occ[$rec['occurrenceID']] = array('taxonID' => $rec['taxonID'], 'meta' => $m);
        }
        $this->conv_occ_columns = $extra;
        $msg = "Occurrence rows: $n; " . ($extra ? "columns added to Traits.tsv: " . implode(", ", $extra) : "only occurrenceID and taxonID, used only to link MoF rows to their taxa");
        echo "\n$msg\n";
        $this->conv_debug["Counts"][$msg] = '';
        return $occ;
    }
    private function conv_collect_child_metadata($meta)
    {   // MoF pass 1: child rows (with a parentMeasurementID) -> top-level measurementID => array(column => values)
        $where = "MoF table [" . basename($meta->file_uri) . "]";
        $cols = array_values(self::conv_table_columns($meta));
        foreach(array('measurementID', 'occurrenceID', 'measurementType', 'measurementValue') as $c) if(!in_array($c, $cols)) self::conv_exit("$where has no [$c] column.");
        $excluded = array_flip((array) @$GLOBALS['excluded_metadata_as_columns']);
        $parent_of = array(); $child_vals = array();
        $this->conv_child_columns = array(); // first-seen order
        foreach(self::conv_table_rows($meta) as list($line, $rec)) {
            $pID = (string) @$rec['parentMeasurementID'];
            if($pID === '') continue;
            $parent_of[$rec['measurementID']] = $pID;
            $col = self::conv_short_name($rec['measurementType']);
            if(isset($excluded[$col]) || isset($excluded[$rec['measurementType']])) { @$this->conv_debug["Child rows not made into columns (excluded_metadata_as_columns)"][$col]++; continue; }
            if($rec['measurementValue'] === '' || $col === '') { @$this->conv_debug["Child rows left out: blank measurementType or measurementValue"]["$where"]++; continue; }
            if(in_array($col, array_merge(self::TRAITS_FIRST, self::MOF_NOT_CARRIED))) { @$this->conv_debug["Child rows left out: their column name is reserved"][$col]++; continue; }
            $child_vals[$rec['measurementID']] = array($col, $rec['measurementValue']);
            $this->conv_child_columns[$col] = '';
        }
        $this->conv_child_columns = array_keys($this->conv_child_columns);
        $children = array();
        foreach($child_vals as $mID => $cv) {
            // the top-level ancestor: a grandchild's values go to the trait at the top of its chain
            $top = $mID; $seen = array();
            while(isset($parent_of[$top]) && !isset($seen[$top])) { $seen[$top] = ''; $top = $parent_of[$top]; }
            if(isset($parent_of[$top])) { $this->conv_debug["Child rows left out: parentMeasurementID loop"][$mID] = ''; continue; }
            $children[$top][$cv[0]][] = $cv[1];
        }
        $this->conv_child_total = count($parent_of);
        return $children;
    }
    private function conv_merge_values($existing, $vals)
    {   // "a|b" + array(b, c) -> "a|b|c": pipe-separated, unique, no blanks
        $all = $existing === '' ? array() : explode('|', $existing);
        $all = array_filter(array_map('trim', array_merge($all, $vals)), fn($v) => $v !== '');
        return implode('|', array_values(array_unique($all)));
    }
    private function conv_reference_ids($str)
    {   // "a;b" -> "a|b". Every ";" is a separator except the one in old-style (SICI) DOIs, which end in "CO;2" or "co;2-3",
        // e.g. https://doi.org/10.1890/0012-9615(2001)071[0401:RAROAL]2.0.CO;2 -- protected first, restored after the split.
        $str = preg_replace('/(co);(\d(?:-[0-9a-z])?)(?=\s*(?:;|\||$))/i', "$1\x01$2", $str);
        $parts = preg_split('/[;|]/', $str);
        return self::conv_merge_values('', str_replace("\x01", ';', $parts));
    }
    private function conv_write_traits($meta, $file, $taxa, $occ, $children)
    {   // MoF pass 2: every row without a parentMeasurementID -> a Traits.tsv row
        $mof_cols = array_values(array_diff(self::conv_table_columns($meta), self::MOF_NOT_CARRIED));
        $meta_cols = array_values(array_diff(array_merge($this->conv_child_columns, $this->conv_occ_columns), $mof_cols, self::TRAITS_FIRST, self::MOF_NOT_CARRIED));
        $all = array_values(array_unique(array_merge($mof_cols, $meta_cols)));
        $head = array_merge(self::TRAITS_FIRST, array_values(array_diff($all, self::TRAITS_FIRST, self::TRAITS_LAST)), array_values(array_intersect(self::TRAITS_LAST, $all)));

        $f = Functions::file_open($file, "w");
        fwrite($f, implode("\t", $head) . "\n");
        self::conv_report_new_columns('Traits.tsv', $head, $GLOBALS['trait_columns']);
        $written = 0; $context = 0; $ref_changed = 0; $used = array();
        $skip = array('no occurrence' => 0, 'no taxon' => 0);
        foreach(self::conv_table_rows($meta) as list($line, $rec)) {
            if((string) @$rec['parentMeasurementID'] !== '') continue; // child rows are columns of their parent
            $mID = $rec['measurementID'];
            if(!($o = @$occ[$rec['occurrenceID']])) {
                $skip['no occurrence']++;
                $this->conv_debug["Traits left out: occurrenceID not in the occurrence table"][$rec['occurrenceID']] = '';
                continue;
            }
            if(!($t = @$taxa[$o['taxonID']])) {
                $skip['no taxon']++;
                $this->conv_debug["Traits left out: the occurrence's taxonID not in the taxon table"][$o['taxonID']] = '';
                continue;
            }
            if(strtolower((string) @$rec['measurementOfTaxon']) != 'true') $context++;
            $rec['taxonID'] = $o['taxonID']; $rec['scientificName'] = $t[0]; $rec['taxonKey'] = $t[1];
            foreach(@$children[$mID] ?: array() as $col => $vals) $rec[$col] = self::conv_merge_values((string) @$rec[$col], $vals);
            foreach($o['meta'] as $col => $val) if(in_array($col, $head)) $rec[$col] = self::conv_merge_values((string) @$rec[$col], array($val));
            if(isset($rec['referenceID']) && $rec['referenceID'] !== '') {
                $new = self::conv_reference_ids($rec['referenceID']);
                if($new !== $rec['referenceID']) { $ref_changed++; $rec['referenceID'] = $new; }
                foreach(explode('|', $rec['referenceID']) as $id) if($id !== '') $this->conv_ref_used[$id] = '';
            }
            $out = array();
            foreach($head as $c) $out[] = (string) @$rec[$c];
            fwrite($f, implode("\t", $out) . "\n");
            $used[$mID] = '';
            $written++;
        }
        fclose($f);

        // child rows whose top-level parent wasn't written
        foreach($children as $top => $cols) {
            if(isset($used[$top])) continue;
            foreach($cols as $col => $vals) @$this->conv_debug["Child rows left out: their parent trait isn't in Traits.tsv"]["$col (parent $top)"] += count($vals);
        }
        $merged = array();
        foreach($children as $top => $cols) if(isset($used[$top])) foreach($cols as $col => $vals) @$merged[$col] += count($vals);

        $lines = array("Traits.tsv rows: $written (MoF rows without a parentMeasurementID)",
                       "Child MoF rows: {$this->conv_child_total}" . ($merged ? "; added to their parent trait as columns: " . implode(", ", array_map(fn($c, $n) => "$c $n", array_keys($merged), $merged)) : ''),
                       "Context rows (no parentMeasurementID, measurementOfTaxon not true), written as trait rows: $context",
                       "Traits left out: occurrenceID not in the occurrence table: {$skip['no occurrence']}; taxonID not in the taxon table: {$skip['no taxon']}",
                       "referenceID: ';' changed to '|' in $ref_changed rows",
                       "Traits.tsv columns: " . implode(", ", $head));
        foreach($lines as $l) { echo "\n$l"; $this->conv_debug["Counts"][$l] = ''; }
        echo "\n";
    }
    private function conv_write_references($meta, $file)
    {   // reference table -> References.tsv (identifier, full_reference, as the shared TB_files/22776838_References/input_files/references.tsv):
        // only the references Traits.tsv uses, in the order of the reference table; compared with the shared references.tsv
        $topic = "References.tsv";
        $f = Functions::file_open($file, "w");
        fwrite($f, "identifier\tfull_reference\n");
        $written = array(); $blank = 0;
        if($meta) {
            $cols = array_values(self::conv_table_columns($meta));
            foreach(array('identifier', 'full_reference') as $c) if(!in_array($c, $cols)) { fclose($f); self::conv_exit("Reference table [" . basename($meta->file_uri) . "] has no [$c] column."); }
            foreach(self::conv_table_rows($meta) as list($line, $rec)) {
                $id = $rec['identifier'];
                if(!isset($this->conv_ref_used[$id]) || isset($written[$id])) continue; // not used in Traits.tsv, or a duplicate identifier (first kept)
                $full = trim(preg_replace('/\s+/', ' ', $rec['full_reference'])); // no tabs or line breaks inside a field
                if($full === '') { $blank++; $this->conv_debug["References.tsv: blank full_reference (written anyway)"][$id] = ''; }
                fwrite($f, "$id\t$full\n");
                $written[$id] = $full;
            }
        }
        fclose($f);
        $missing = array_diff_key($this->conv_ref_used, $written);
        foreach(array_keys($missing) as $id) $this->conv_debug["References.tsv: referenceID used in Traits.tsv but not in the reference table"][$id] = '';

        // compared with the shared references (TB_files/22776838_References/input_files/references.tsv)
        $shared_file = @glob(rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files/22776838_*/input_files/references.tsv")[0];
        $in_shared = 0; $differs = 0;
        if($shared_file && ($h = fopen($shared_file, 'r'))) {
            while(($row = fgets($h)) !== false) {
                $tmp = explode("\t", rtrim($row, "\r\n"));
                $id = trim((string) @$tmp[0]);
                if(!isset($written[$id])) continue;
                $in_shared++;
                $theirs = trim(preg_replace('/\s+/', ' ', (string) @$tmp[1]));
                if($theirs !== $written[$id]) { $differs++; $this->conv_debug["References.tsv: already in the shared references.tsv, with a different full_reference"]["$id -- ours: [{$written[$id]}] shared: [$theirs]"] = ''; }
            }
            fclose($h);
        }
        $lines = array("References.tsv rows: " . count($written) . " (of " . count($this->conv_ref_used) . " distinct referenceIDs in Traits.tsv); not in the reference table: " . count($missing) . "; blank full_reference: $blank",
                       $shared_file ? "Already in the shared references.tsv: $in_shared (same full_reference: " . ($in_shared - $differs) . ", different: $differs); new: " . (count($written) - $in_shared)
                                    : "Shared references.tsv (TB_files/22776838_*/input_files/) not found: not compared");
        foreach($lines as $l) { echo "\n$l"; $this->conv_debug["Counts"][$l] = ''; }
        echo "\n";
        if(!$meta && $this->conv_ref_used) echo "\nWARNING: the DwCA has no reference table: References.tsv has only its header.\n";
    }
    const ARCHIVE_TYPES = array('.tar.gz', '.tgz', '.zip'); // supported Zenodo file types; ".tar.gz" and ".zip" as "file_type"
    private function archive_type($file)
    {   // e.g. Archive.zip -> ".zip", x.TAR.GZ -> ".tar.gz", x.tgz -> ".tar.gz"; false if not an archive
        foreach(self::ARCHIVE_TYPES as $type) {
            if(str_ends_with(strtolower($file), $type)) return $type == '.tgz' ? '.tar.gz' : $type;
        }
        return false;
    }
    private function pick_archive_file($paths)
    {   // the downloaded file to save as the final version: the only file, or else the only archive (.zip, .tar.gz, .tgz)
        if(count($paths) == 1) return reset($paths);
        $archives = array_values(array_filter($paths, fn($p) => self::archive_type($p)));
        if(count($archives) == 1) return $archives[0];
        echo "\nThe Zenodo record has " . count($archives) . " archive files among its " . count($paths) . " files:\n - " . implode("\n - ", $paths) . "\n";
        return false;
    }
    private function save_final_version($source, $final, $file_type, $temp_dir)
    {   // $source (e.g. .zip) -> $final (e.g. .tar.gz): copied if same type, else converted (files at the top of the archive, as in our other DwCA .tar.gz files)
        if(!in_array($file_type, array('.tar.gz', '.zip'))) { echo "\n\"file_type\" [$file_type] not supported: use \".tar.gz\" or \".zip\".\n"; return false; }
        if(!($source_type = self::archive_type($source))) { echo "\nDownloaded file [" . basename($source) . "] isn't a .zip or .tar.gz, cannot convert it to [$file_type].\n"; return false; }
        // nothing to do if the final version was made from this same download (a new download is newer)
        if(is_file($final) && filesize($final) > 0 && filemtime($final) >= filemtime($source)) {
            echo "\nFinal version already up to date, no change: [$final]\n";
            return true;
        }
        $part = $final . ".part"; // never leave a half-written final version
        if($source_type == $file_type) {
            $ok = copy($source, $part);
            echo "\nSame type as Zenodo's file [" . basename($source) . "]: copied, no conversion.\n";
        }
        else {
            // extract to a fresh temp folder, without Mac junk (__MACOSX/, ._ files), then pack as $file_type
            if(is_dir($temp_dir)) recursive_rmdir($temp_dir);
            if(!mkdir($temp_dir, 0775, true)) { echo "\nCannot create folder [$temp_dir]\n"; return false; }
            if($source_type == '.zip') $cmd = "unzip -q -o " . escapeshellarg($source) . " -x '__MACOSX/*' '*/.DS_Store' '.DS_Store' -d " . escapeshellarg($temp_dir);
            else                       $cmd = "tar -xzf " . escapeshellarg($source) . " -C " . escapeshellarg($temp_dir) . " --exclude='__MACOSX' --exclude='._*' --exclude='.DS_Store'";
            exec("$cmd 2>&1", $output, $exit_code);
            if($exit_code !== 0) { echo "\nCannot extract [$source]:\n" . implode("\n", $output) . "\n"; recursive_rmdir($temp_dir); return false; }
            if(is_file($part)) unlink($part);
            if($file_type == '.tar.gz') $cmd = "tar -czf " . escapeshellarg($part) . " -C " . escapeshellarg($temp_dir) . " .";
            else                        $cmd = "cd " . escapeshellarg($temp_dir) . " && zip -q -r -X " . escapeshellarg($part) . " .";
            $output = array();
            exec("$cmd 2>&1", $output, $exit_code);
            $ok = ($exit_code === 0);
            if(!$ok) echo "\nCannot pack [$part]:\n" . implode("\n", $output) . "\n";
            else echo "\nConverted [" . basename($source) . "] ($source_type) to $file_type.\n";
            recursive_rmdir($temp_dir);
        }
        if(!$ok || !rename($part, $final)) { if(is_file($part)) unlink($part); return false; }
        chmod($final, 0644);
        echo "Final version saved: [$final] (" . number_format(filesize($final)) . " bytes)\n";
        return true;
    }
}
?>