<?php
namespace php_active_record;
/* Library for the Legacy DwCA process: maintenance of the legacy DwCA resources on Zenodo (e.g. AmphibiaWeb, concept ID 13318109).
Called from: update_resources/connectors/process_legacy_dwca.php
Downloads go to [CONTENT_RESOURCE_LOCAL_PATH]/Legacy_DwCA_files/ (the References/TraitBank tasks use TB_files/).
Re-uses the Zenodo download code of ZenodoTraitBankAPI.
Tasks:
- download_file_from_zenodo: see process_legacy_dwca.php
- convert_dwca_to_new_input_files: a legacy DwCA -> TraitBank input files Taxon.tsv, Traits.tsv and Sources.tsv, the same kind of files as the
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
    'source', 'referenceID', 'contributor');

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
    // (Nov 27, 2026) contributors are Sources (Data Managers' plan): a contributor ID that is a real, unique person ID (FullReferenceSearchAPI::is_person_id():
    // an ORCID, a FishBase collaborator page or a WoRMS person page) goes into referenceID, and into Sources.tsv with the person's name as
    // its citation (Nov 28, 2026: the master sources.tsv is not changed here, only compared). Any other contributor ID is left out (not carried forward), e.g. a made-up EOL URI or a hash. There is no contributor column any more.
    const MADE_UP_EOL_URI = '~^https?://(?:www\.)?eol\.org/schema/terms/~i'; // e.g. http://eol.org/schema/terms/Amphibiaweb_Zheng_Oong
    const HASH_ID         = '~^[0-9a-f]{32}(?:_[A-Za-z0-9_]+)?$~i';           // e.g. 2db8f17d140d381babcb4976cb0a2e8e or ..._21_ENV (md5)
    function convert_dwca_to_new_input_files()
    {   // e.g. concept_id 13318109, supplied_name AmphibiaWeb -- or the same as "source": "13318109_AmphibiaWeb_TB_1_0.tar.gz":
        //   CONTENT_RESOURCE_LOCAL_PATH/13318109_AmphibiaWeb_TB_1_0.tar.gz -> TB_files_from_DwCA/13318109_AmphibiaWeb_TraitBank/input_files/Taxon.tsv and Traits.tsv
        // (Nov 28, 2026) "source" alone is enough: any legacy DwCA in CONTENT_RESOURCE_LOCAL_PATH, e.g. "FishBase_TraitBank_1_0.tar.gz"
        //   -> TB_files_from_DwCA/FishBase_TraitBank/. concept_id and supplied_name are only used to name the input, the output folder and the report.
        $both_ways = "Give either \"source\" (the DwCA's file name), e.g. \"source\": \"FishBase_TraitBank_1_0.tar.gz\", or \"concept_id\" + \"supplied_name\", e.g. \"concept_id\": \"13318109\", \"supplied_name\": \"AmphibiaWeb\" (for 13318109_AmphibiaWeb_TB_1_0.tar.gz).";
        $concept_id    = trim((string) @$this->param['concept_id']);
        $supplied_name = trim((string) @$this->param['supplied_name']);
        $source        = trim((string) @$this->param['source']);
        if($source !== '') { // the input file's name in CONTENT_RESOURCE_LOCAL_PATH, e.g. 13318109_AmphibiaWeb_TB_1_0.tar.gz or FishBase_TraitBank_1_0.tar.gz
            if(strpos($source, '/') !== false || strpos($source, '\\') !== false) exit("\nERROR: \"source\" [$source] must be a file name only (no folder): the file in CONTENT_RESOURCE_LOCAL_PATH, e.g. \"source\": \"13318109_AmphibiaWeb_TB_1_0.tar.gz\".\n");
            // (Nov 28, 2026) any [name].tar.gz; the name for the output folder and the report is the file name without .tar.gz and without
            // a trailing _TB_1_0 or _TraitBank_1_0: 13318109_AmphibiaWeb_TB_1_0.tar.gz -> 13318109_AmphibiaWeb; FishBase_TraitBank_1_0.tar.gz -> FishBase
            if(!preg_match('/^([A-Za-z0-9_-]+?)(?:_TB_1_0|_TraitBank_1_0)?\.tar\.gz$/', $source, $m)) exit("\nERROR: Invalid \"source\" [$source]: it must be a .tar.gz file whose name has only letters, digits, _ and -, e.g. \"source\": \"FishBase_TraitBank_1_0.tar.gz\" or \"13318109_AmphibiaWeb_TB_1_0.tar.gz\".\n");
            $name = $m[1];
            $dwca_file = $source;
            if($concept_id !== '' || $supplied_name !== '') { // also given: must name the same file
                $other = $concept_id . "_" . $supplied_name;
                if($other . "_TB_1_0.tar.gz" !== $source) exit("\nERROR: \"source\" [$source] and \"concept_id\" + \"supplied_name\" [{$other}_TB_1_0.tar.gz] name different files. Give only one of them.\n");
            }
        }
        else {
            if($concept_id === '' && $supplied_name === '') exit("\nERROR: No input given. $both_ways\n");
            if(!preg_match('/^\d+$/', $concept_id)) exit("\nERROR: Missing or invalid \"concept_id\" [$concept_id]. $both_ways\n");
            if(!preg_match('/^[A-Za-z0-9_-]+$/', $supplied_name)) exit("\nERROR: Missing or invalid \"supplied_name\" [$supplied_name]: letters, digits, _ and - only. $both_ways\n");
            $name = $concept_id . "_" . $supplied_name;
            $dwca_file = $name . "_TB_1_0.tar.gz";
        }
        $dwca = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/" . $dwca_file;
        if(!is_file($dwca)) exit("\nERROR: Legacy DwCA not found: [$dwca]\n");
        $out_root = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files_from_DwCA";
        $this->conv_out_dir = "$out_root/{$name}_TraitBank/";
        $this->conv_temp_dir = '';
        $this->conv_debug = array();
        $this->conv_columns = array();
        $this->conv_ref_used = array(); // referenceIDs used in Traits.tsv and (Nov 28, 2026) Taxon.tsv
        $this->conv_src_used = array(); // (Nov 28, 2026) values (whole, and their "|"/";" parts) of the source columns of Traits.tsv and Taxon.tsv
        $this->conv_summary  = array(); // (Nov 28, 2026) Sources.tsv rows: identifier => array(type, in the master?) for the summary tables
        $this->conv_contrib = array('kept' => array(), 'excluded' => array(), 'values' => 0); // contributor IDs: kept (id => rows) or excluded (kind => id => rows)
        echo "\nConverting [$dwca]\n      -> [{$this->conv_out_dir}input_files/]\n";

        // read the DwCA
        require_library('connectors/INBioAPI');
        require_library('connectors/GenerateTB_Files_Functions');
        require_library('connectors/FullReferenceSearchAPI'); // is_person_id(): which contributor IDs are kept (Nov 27, 2026)
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
        $names = self::conv_contributor_names(@$tables['http://eol.org/schema/agent/agent'][0]); // kept contributor ID => name
        self::conv_write_references($ref_meta, $this->conv_out_dir . "input_files/Sources.tsv", $names);
        self::conv_compare_contributors_with_master($names, $name);

        recursive_rmdir($this->conv_temp_dir);
        foreach($this->conv_debug as $topic => $arr) { // counts as "name: count" lines in the report
            foreach($arr as $sub => $v) if(is_int($v)) { unset($this->conv_debug[$topic][$sub]); $this->conv_debug[$topic]["$sub: " . number_format($v)] = ''; }
        }
        Functions::start_print_debug($this->conv_debug, $name . "_convert", $out_root);
        self::conv_sources_summary("$out_root/{$name}_convert_debug_" . date('Y-m-d') . ".txt"); // (Nov 28, 2026) the 3 tables, on screen and at the end of the report
        echo "\nReport: [$out_root/{$name}_convert_debug_" . date('Y-m-d') . ".txt]\nDone: [{$this->conv_out_dir}input_files/]\n";
        return array('Taxon' => $this->conv_out_dir . "input_files/Taxon.tsv", 'Traits' => $this->conv_out_dir . "input_files/Traits.tsv",
                     'Sources' => $this->conv_out_dir . "input_files/Sources.tsv");
    }
    private function conv_input_row_counts($tables)
    {   // rows per file in the legacy DwCA (header and blank lines not counted), and what the conversion does with each file
        $use = array('http://rs.tdwg.org/dwc/terms/taxon'             => 'Taxon.tsv',
                     'http://rs.tdwg.org/dwc/terms/measurementorfact' => 'Traits.tsv',
                     'http://rs.tdwg.org/dwc/terms/occurrence'        => 'links MoF rows to their taxa; extra columns go to Traits.tsv',
                     'http://eol.org/schema/reference/reference'      => 'Sources.tsv (only the references Traits.tsv uses)',
                     'http://eol.org/schema/agent/agent'              => 'only the names of contributors kept (term_homepage -> term_name), if the Terms master has none');
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
        echo "\nNOTE: $file_label has " . number_format(count($new)) . " column(s) not in the 21 Zenodo TraitBank datasets: " . implode(', ', $new) . " (kept)";
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
        // furtherInformationURL goes into source: appended with "|" if source already has a value; pipe-separated values are unique
        $has_url = in_array('furtherInformationURL', $cols);
        if($has_url) {
            $cols = array_values(array_diff($cols, array('furtherInformationURL')));
            if(!in_array('source', $cols)) $cols[] = 'source';
        }
        $url = array('moved' => 0, 'appended' => 0, 'already there' => 0);
        $order = array_merge(array_values(array_intersect(self::TAXON_COLUMNS, $cols)), array_values(array_diff($cols, self::TAXON_COLUMNS)));

        $f = Functions::file_open($file, "w");
        fwrite($f, implode("\t", $order) . "\n");
        self::conv_report_new_columns('Taxon.tsv', $order, $GLOBALS['taxon_columns']);
        $taxa = array(); $written = 0; $taxon_ref_changed = 0;
        foreach(self::conv_table_rows($meta) as list($line, $rec)) {
            if($has_eolid) { $rec['taxonKey'] = $rec['EOLid']; unset($rec['EOLid']); }
            if(isset($rec['higherClassification'])) $rec['higherClassification'] = trim($rec['higherClassification'], "| "); // 'Animalia|...|Ranidae|' -> 'Animalia|...|Ranidae'
            if(in_array('taxonRemarks', $order)) {
                $clean = self::conv_clean_taxon_remarks($rec['taxonRemarks']);
                if($clean !== $rec['taxonRemarks']) $remarks['notes removed']++;
                if($clean !== '') $remarks['kept']++;
                $rec['taxonRemarks'] = $clean;
            }
            if($rec['taxonID'] === '')        { fclose($f); self::conv_exit("$where, line " . number_format($line) . ": blank taxonID (scientificName [{$rec['scientificName']}]). $required"); }
            if($rec['scientificName'] === '') { fclose($f); self::conv_exit("$where, line " . number_format($line) . ": blank scientificName (taxonID [{$rec['taxonID']}]). $required"); }
            if(isset($taxa[$rec['taxonID']])) { // keep the first
                $this->conv_debug["Taxon: duplicate taxonID, only its first row written"]["taxonID {$rec['taxonID']}, line " . number_format($line)] = '';
                continue;
            }
            if($has_url) {
                $u = trim((string) $rec['furtherInformationURL']); $s = trim((string) @$rec['source']);
                if($u !== '') {
                    if(in_array($u, array_map('trim', explode('|', $s)))) $url['already there']++;
                    else { $url['moved']++; if($s !== '') $url['appended']++; }
                }
                $rec['source'] = self::conv_merge_values($s, array($u));
                unset($rec['furtherInformationURL']);
            }
            if(isset($rec['referenceID']) && $rec['referenceID'] !== '') { // (Nov 28, 2026) as in Traits.tsv: ";" -> "|"; its references go into Sources.tsv
                $new = self::conv_reference_ids($rec['referenceID']);
                if($new !== $rec['referenceID']) { $taxon_ref_changed++; $rec['referenceID'] = $new; }
                foreach(explode('|', $rec['referenceID']) as $id) if($id !== '') $this->conv_ref_used[$id] = '';
            }
            self::conv_collect_source((string) @$rec['source']);
            $out = array();
            foreach($order as $c) $out[] = @$rec[$c];
            fwrite($f, implode("\t", $out) . "\n");
            $taxa[$rec['taxonID']] = array($rec['scientificName'], $rec['taxonKey']);
            $written++;
        }
        fclose($f);
        echo "\nTaxon.tsv: [" . number_format($written) . "] rows" . ($has_eolid ? ", EOLid renamed taxonKey" : '') . ".\n";
        $this->conv_debug["Counts"]["Taxon.tsv rows: " . number_format($written) . ($has_eolid ? " (EOLid renamed taxonKey)" : '')] = '';
        if(in_array('referenceID', $order)) { $l = "Taxon.tsv referenceID: ';' changed to '|' in " . number_format($taxon_ref_changed) . " rows"; echo "$l\n"; $this->conv_debug["Counts"][$l] = ''; }
        if($has_url) {
            $l = "Taxon.tsv: furtherInformationURL put in source in " . number_format($url['moved']) . " rows (appended with \"|\" to an existing source in " .
                 number_format($url['appended']) . "; already in source, not repeated, in " . number_format($url['already there']) . ")";
            echo "$l\n"; $this->conv_debug["Counts"][$l] = '';
        }
        if(in_array('taxonRemarks', $order)) {
            $l = "Taxon.tsv taxonRemarks: real remarks kept in " . number_format($remarks['kept']) . " rows; match_taxa_2DH's notes removed in " . number_format($remarks['notes removed']) . " rows";
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
        $msg = "Occurrence rows: " . number_format($n) . "; " . ($extra ? "columns added to Traits.tsv: " . implode(", ", $extra) : "only occurrenceID and taxonID, used only to link MoF rows to their taxa");
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
    private function conv_collect_source($value)
    {   // (Nov 28, 2026) a source value, whole and its "|"/";" parts: those that are reference identifiers are written to Sources.tsv
        $value = trim($value);
        if($value === '') return;
        $this->conv_src_used[$value] = '';
        foreach(preg_split('/[;|]/', $value) as $part) if(($part = trim($part)) !== '') $this->conv_src_used[$part] = '';
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
        $has_contributor = in_array('contributor', $all); // (Nov 27, 2026) no contributor column: kept IDs go to referenceID (see MADE_UP_EOL_URI)
        $all = array_values(array_diff($all, array('contributor')));
        if($has_contributor && !in_array('referenceID', $all)) $all[] = 'referenceID';
        $head = array_merge(self::TRAITS_FIRST, array_values(array_diff($all, self::TRAITS_FIRST, self::TRAITS_LAST)), array_values(array_intersect(self::TRAITS_LAST, $all)));

        $f = Functions::file_open($file, "w");
        fwrite($f, implode("\t", $head) . "\n");
        self::conv_report_new_columns('Traits.tsv', $head, $GLOBALS['trait_columns']);
        $written = 0; $context = 0; $ref_changed = 0; $used = array(); $taxa_with_traits = array();
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
            self::conv_collect_source((string) @$rec['source']); // (Nov 28, 2026) a source that is a reference identifier goes into Sources.tsv
            if($has_contributor && (string) @$rec['contributor'] !== '') { // after the referenceIDs: a contributor ID kept is added to them
                $kept = self::conv_sort_contributors($rec['contributor']);
                if($kept) $rec['referenceID'] = self::conv_merge_values((string) @$rec['referenceID'], $kept);
            }
            $out = array();
            foreach($head as $c) $out[] = (string) @$rec[$c];
            fwrite($f, implode("\t", $out) . "\n");
            $used[$mID] = '';
            $taxa_with_traits[$o['taxonID']] = '';
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

        $lines = array("Traits.tsv rows: " . number_format($written) . " (MoF rows without a parentMeasurementID)",
                       "Taxa with traits: " . number_format(count($taxa_with_traits)) . " (of " . number_format(count($taxa)) . " in Taxon.tsv)",
                       "Child MoF rows: " . number_format($this->conv_child_total) . ($merged ? "; added to their parent trait as columns: " . implode(", ", array_map(fn($c, $n) => "$c " . number_format($n), array_keys($merged), $merged)) : ''),
                       "Context rows (no parentMeasurementID, measurementOfTaxon not true), written as trait rows: " . number_format($context),
                       "Traits left out: occurrenceID not in the occurrence table: " . number_format($skip['no occurrence']) . "; taxonID not in the taxon table: " . number_format($skip['no taxon']),
                       "referenceID: ';' changed to '|' in " . number_format($ref_changed) . " rows",
                       "Traits.tsv columns: " . implode(", ", $head));
        foreach($lines as $l) { echo "\n$l"; $this->conv_debug["Counts"][$l] = ''; }
        if($has_contributor) self::conv_report_contributors();
        echo "\n";
    }
    private function conv_sort_contributors($value)
    {   // (Nov 27, 2026) one row's contributor value ("id1|id2", or ";"): returns the IDs kept (real person IDs); the others are counted as left out
        $kept = array();
        foreach(preg_split('/[;|]/', (string) $value) as $id) {
            $id = trim($id);
            if($id === '') continue;
            $this->conv_contrib['values']++;
            if(FullReferenceSearchAPI::is_person_id($id) !== '') { $kept[] = $id; @$this->conv_contrib['kept'][$id]++; continue; }
            if(preg_match(self::MADE_UP_EOL_URI, $id)) $kind = 'a made-up EOL URI';
            elseif(preg_match(self::HASH_ID, $id))     $kind = 'a hash';
            else                                        $kind = 'not an ORCID, FishBase collaborator page or WoRMS person page';
            @$this->conv_contrib['excluded'][$kind][$id]++;
        }
        return $kept;
    }
    private function conv_report_contributors()
    {   // counts of the contributor IDs kept (moved to referenceID) and left out, with examples
        $c = $this->conv_contrib; $topic = "Contributors (no contributor column any more; Data Managers' plan, Nov 27, 2026)";
        $n_out = array_sum(array_map('count', $c['excluded']));
        $lines = array("Contributor values read: " . number_format($c['values']) . " (the MoF's contributor column and its child rows of type contributor)",
                       "Kept, moved to referenceID (an ORCID, a FishBase collaborator page or a WoRMS person page): " . number_format(count($c['kept'])) . " distinct IDs, "
                           . number_format(array_sum($c['kept'])) . " values",
                       "Left out, not carried forward: " . number_format($n_out) . " distinct IDs, " . number_format(array_sum(array_map('array_sum', $c['excluded']))) . " values");
        foreach($c['excluded'] as $kind => $ids) {
            $ex = array_slice(array_keys($ids), 0, 3);
            $lines[] = "  left out, $kind: " . number_format(count($ids)) . " distinct IDs, e.g. " . implode(", ", $ex);
        }
        foreach($lines as $l) { echo "\n$l"; $this->conv_debug[$topic][$l] = ''; }
        foreach($c['excluded'] as $kind => $ids) foreach($ids as $id => $n) $this->conv_debug["Contributors left out: $kind"]["$id ($n values)"] = '';
    }
    private function conv_contributor_names($agent_meta)
    {   // (Nov 27, 2026) the name of each contributor ID kept: from the Terms master (terms.tsv: uri -> name), else the DwCA's agent table
        // (term_homepage -> term_name). Returns array(id => name); an ID with no name isn't in it.
        $want = $this->conv_contrib['kept'];
        if(!$want) return array();
        $names = array();
        if($terms = $this->get_generic_file_path(22776577, 'terms')) { // 22776577 = Terms
            $h = fopen($terms, 'r');
            $head = array_map('trim', explode("\t", rtrim((string) fgets($h), "\r\n")));
            $c_uri = array_search('uri', $head); $c_name = array_search('name', $head);
            if($c_uri !== false && $c_name !== false) {
                while(($line = fgets($h)) !== false) {
                    $f = explode("\t", rtrim($line, "\r\n"));
                    $uri = trim((string) @$f[$c_uri]); $nm = trim((string) @$f[$c_name]);
                    if($uri !== '' && $nm !== '' && isset($want[$uri]) && !isset($names[$uri])) $names[$uri] = $nm;
                }
            }
            fclose($h);
            echo "\nContributor names from the Terms master [" . basename($terms) . "]: " . number_format(count($names)) . " of " . number_format(count($want));
        }
        else echo "\nWARNING: the Terms master (terms.tsv) wasn't found: contributor names come only from the DwCA's agent table, if it has one.";
        if($agent_meta && count($names) < count($want)) {
            $cols = array_values(self::conv_table_columns($agent_meta)); $from_agent = 0;
            if(in_array('term_homepage', $cols) && in_array('term_name', $cols)) {
                foreach(self::conv_table_rows($agent_meta) as list($line, $rec)) {
                    $id = $rec['term_homepage']; $nm = trim(preg_replace('/\s+/', ' ', $rec['term_name']));
                    if($id !== '' && $nm !== '' && isset($want[$id]) && !isset($names[$id])) { $names[$id] = $nm; $from_agent++; }
                }
            }
            echo "\nContributor names from the DwCA's agent table: " . number_format($from_agent);
        }
        return $names;
    }
    private function conv_compare_contributors_with_master($names, $name)
    {   // (Nov 27, 2026) the kept contributors with a name are written to Sources.tsv (identifier = their ID, citation = their name).
        // (Nov 28, 2026) the master sources.tsv is NOT changed here any more: it is only compared (read-only), and the report says which
        // contributors are new to it. They are added to the master later, like any other new source (e.g. References Maintenance's "Citations to add").
        // A kept ID with no name stays in referenceID only; References Maintenance's unmappable list will list it.
        $topic = "Contributors vs the master sources.tsv (master not changed)";
        if(!$this->conv_contrib['kept']) return;
        $no_name = array_diff_key($this->conv_contrib['kept'], $names);
        foreach(array_keys($no_name) as $id) $this->conv_debug["Contributors kept, but with no name (not in Sources.tsv; in referenceID)"][$id] = '';
        $master = array(); // identifier => citation, only for our contributors
        $shared_file = @glob(rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files/22776838_*/input_files/sources.tsv")[0];
        if($shared_file && ($h = fopen($shared_file, 'r'))) {
            while(($row = fgets($h)) !== false) {
                $tmp = explode("\t", rtrim($row, "\r\n"));
                $id = trim((string) @$tmp[0]);
                if(isset($names[$id]) && !isset($master[$id])) $master[$id] = trim(preg_replace('/\s+/', ' ', (string) @$tmp[1]));
            }
            fclose($h);
        }
        $new = array_diff_key($names, $master);
        $differs = array_filter($master, fn($cit, $id) => $cit !== $names[$id], ARRAY_FILTER_USE_BOTH);
        $lines = array("Contributors in Sources.tsv (with a name): " . number_format(count($names)),
                       $shared_file ? "Already in the master sources.tsv: " . number_format(count($master)) . " (with a different citation: " . number_format(count($differs)) . "); new to the master: " . number_format(count($new)) . " (not added: the master is not changed by this task)"
                                    : "The master sources.tsv (TB_files/22776838_*/input_files/) not found: not compared",
                       "Kept, but with no name (not in Sources.tsv; in referenceID, so References Maintenance will list it as unmappable): " . number_format(count($no_name)));
        foreach($lines as $l) { echo "\n$l"; $this->conv_debug[$topic][$l] = ''; }
        foreach($new as $id => $cit) $this->conv_debug["Contributors new to the master sources.tsv (in Sources.tsv; not added to the master)"]["$id\t$cit"] = '';
        foreach($differs as $id => $cit) $this->conv_debug["Contributors already in the master with a different citation"]["$id -- master: [$cit] ours: [{$names[$id]}]"] = '';
        echo "\n";
    }
    private function conv_sources_summary($report_file)
    {   // (Nov 28, 2026) 3 tables about Sources.tsv, printed and appended to the report (the report printer sorts lines, so they're written here):
        //   1. Sources.tsv rows by type (source, referenceID, contributor): already in the master / new, to be added to the master / total
        //   2. the new referenceID rows: with a DOI as identifier / without / total
        //   3. the contributor IDs: valid (ORCID, FishBase or WoRMS person page) / only a made-up EOL URI or a hash / other left out / total
        $m = !empty($this->conv_master_found); $n = fn($x) => number_format($x);
        $t1 = array(); foreach(array('source', 'referenceID', 'contributor') as $k) $t1[$k] = array('in' => 0, 'new' => 0);
        $doi = 0; $no_doi = 0;
        foreach($this->conv_summary as $id => list($type, $in)) {
            $t1[$type][($m && $in) ? 'in' : 'new']++;
            if($type == 'referenceID' && !($m && $in)) { if(preg_match('~^https?://(?:dx\.)?doi\.org/~i', $id)) $doi++; else $no_doi++; }
        }
        $valid = count((array) @$this->conv_contrib['kept']);
        $ex = (array) @$this->conv_contrib['excluded'];
        $madeup = count((array) @$ex['a made-up EOL URI']) + count((array) @$ex['a hash']);
        $other  = count((array) @$ex['not an ORCID, FishBase collaborator page or WoRMS person page']);
        $with_name = $t1['contributor']['in'] + $t1['contributor']['new'];
        $row = fn($cells, $w) => implode('  ', array_map(fn($c, $i) => $i == 0 ? str_pad($c, $w[$i]) : str_pad($c, $w[$i], ' ', STR_PAD_LEFT), $cells, array_keys($cells)));
        $out = array();
        $total = 0; foreach($t1 as $v) $total += $v['in'] + $v['new'];
        $w = array(14, 18, 30, 10);
        $out[] = "Table 1. Sources.tsv: " . $n($total) . " rows" . ($m ? '' : " (the master sources.tsv was not found: all counted as new)");
        $out[] = $row(array('type', 'already in master', 'new, to be added to master', 'total'), $w);
        foreach($t1 as $k => $v) $out[] = $row(array("/$k", $m ? $n($v['in']) : 'n/a', $n($v['new']), $n($v['in'] + $v['new'])), $w);
        $out[] = $row(array('total', $m ? $n(array_sum(array_column($t1, 'in'))) : 'n/a', $n(array_sum(array_column($t1, 'new'))), $n($total)), $w);
        $out[] = "";
        $w = array(14, 12, 14, 10);
        $out[] = "Table 2. New, to be added to the master: " . $n($doi + $no_doi) . " referenceID rows";
        $out[] = $row(array('', 'with DOI', 'without DOI', 'total'), $w);
        $out[] = $row(array('/referenceID', $n($doi), $n($no_doi), $n($doi + $no_doi)), $w);
        $out[] = "";
        $w = array(24, 40, 18, 10);
        $out[] = "Table 3. Contributors: " . $n($valid + $madeup + $other) . " distinct IDs";
        $out[] = $row(array('with valid identifier', 'only made-up EOL URI or hash identifier', 'other, left out', 'total'), $w);
        $out[] = $row(array($n($valid), $n($madeup), $n($other), $n($valid + $madeup + $other)), $w);
        $out[] = "(valid = an ORCID, a FishBase collaborator page or a WoRMS person page: " . $n($with_name) . " with a name, in Sources.tsv as /contributor; " . $n($valid - $with_name) . " with no name, in referenceID only)";
        echo "\n\nSources.tsv summary\n" . implode("\n", $out) . "\n";
        if($report_file && ($h = @fopen($report_file, 'a'))) {
            fwrite($h, "=============================================================[" . date('Y-m-d') . "]\nSources.tsv summary\n" . implode("\n", $out) . "\n");
            fclose($h);
        }
    }
    private function conv_write_references($meta, $file, $names = array())
    {   // reference table -> Sources.tsv (identifier, citation, as the master TB_files/22776838_References/input_files/sources.tsv):
        // only the references Traits.tsv uses, in the order of the reference table; compared with the master sources.tsv.
        // (Nov 27, 2026) then the contributors kept, with their name as citation ($names: id => name). Was References.tsv (identifier, full_reference).
        $topic = "Sources.tsv";
        $f = Functions::file_open($file, "w");
        fwrite($f, "identifier\tcitation\n");
        $written = array(); $blank = 0;
        if($meta) {
            $cols = array_values(self::conv_table_columns($meta));
            foreach(array('identifier', 'full_reference') as $c) if(!in_array($c, $cols)) { fclose($f); self::conv_exit("Reference table [" . basename($meta->file_uri) . "] has no [$c] column."); }
            foreach(self::conv_table_rows($meta) as list($line, $rec)) {
                $id = $rec['identifier'];
                if((!isset($this->conv_ref_used[$id]) && !isset($this->conv_src_used[$id])) || isset($written[$id])) continue; // not used as referenceID (Traits.tsv, Taxon.tsv) or source, or a duplicate identifier (first kept)
                $full = trim(preg_replace('/\s+/', ' ', $rec['full_reference'])); // no tabs or line breaks inside a field
                if($full === '') { $blank++; $this->conv_debug["Sources.tsv: blank citation (written anyway)"][$id] = ''; }
                fwrite($f, "$id\t$full\n");
                $written[$id] = $full;
            }
        }
        $people = 0;
        foreach(array_keys($written) as $id) $this->conv_summary[$id] = array(isset($this->conv_ref_used[$id]) ? 'referenceID' : 'source', false); // (Nov 28, 2026)
        foreach($names as $id => $nm) { if(isset($written[$id])) continue; fwrite($f, "$id\t$nm\n"); $people++; $this->conv_summary[$id] = array('contributor', false); } // contributors kept (Nov 27, 2026)
        fclose($f);
        $missing = array_diff_key($this->conv_ref_used, $written);
        foreach(array_keys($missing) as $id) $this->conv_debug["Sources.tsv: referenceID used in Traits.tsv but not in the reference table"][$id] = '';

        // compared with the master sources (TB_files/22776838_References/input_files/sources.tsv)
        $shared_file = @glob(rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files/22776838_*/input_files/sources.tsv")[0];
        $this->conv_master_found = (bool) $shared_file;
        $in_shared = 0; $differs = 0; $in_master = array(); // (Nov 28, 2026) identifier => '' for the references already in the master
        if($shared_file && ($h = fopen($shared_file, 'r'))) {
            while(($row = fgets($h)) !== false) {
                $tmp = explode("\t", rtrim($row, "\r\n"));
                $id = trim((string) @$tmp[0]);
                if(isset($this->conv_summary[$id])) $this->conv_summary[$id][1] = true; // (Nov 28, 2026) for the summary tables (references and contributors)
                if(!isset($written[$id]) || isset($in_master[$id])) continue;
                $in_shared++; $in_master[$id] = '';
                $theirs = trim(preg_replace('/\s+/', ' ', (string) @$tmp[1]));
                if($theirs !== $written[$id]) { $differs++; $this->conv_debug["Sources.tsv: already in the master sources.tsv, with a different citation"]["$id -- ours: [{$written[$id]}] shared: [$theirs]"] = ''; }
            }
            fclose($h);
        }
        $lines = array("Sources.tsv rows: " . number_format(count($written) + $people) . ": references " . number_format(count($written)) . " (of " . number_format(count($this->conv_ref_used)) . " distinct referenceIDs in Traits.tsv and Taxon.tsv from the reference table); not in the reference table: " . number_format(count($missing)) . "; blank citation: " . number_format($blank)
                           . "; contributors kept, with their name: " . number_format($people),
                       $shared_file ? "References already in the master sources.tsv: " . number_format($in_shared) . " (same citation: " . number_format($in_shared - $differs) . ", different: " . number_format($differs) . "); new: " . number_format(count($written) - $in_shared)
                                    : "The master sources.tsv (TB_files/22776838_*/input_files/) not found: not compared");
        foreach($lines as $l) { echo "\n$l"; $this->conv_debug["Counts"][$l] = ''; }
        echo "\n";
        if(!$meta && $this->conv_ref_used) echo "\nWARNING: the DwCA has no reference table: Sources.tsv has no references.\n";
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
        echo "\nThe Zenodo record has " . number_format(count($archives)) . " archive files among its " . number_format(count($paths)) . " files:\n - " . implode("\n - ", $paths) . "\n";
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