<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from analyze_MoF.php]
e.g. php update_resources/connectors/analyze_MoF.php _ '{"resource_id": "AmphibiaWeb_finale"}'
     reads:  resources/AmphibiaWeb_finale.tar.gz
     writes: resources/AmphibiaWeb_finale_analyzed.tar.gz

What it does:
Reads a DwCA and writes a new one in which the MeasurementOrFact (MoF), Association and Occurrence records are checked against
the EOL terms list. Records that can't be supported by the terms list are dropped; unknown URIs in qualifier fields are blanked.
DwCA_Utility copies all other extensions (taxon, media, reference, agent, vernacular, etc.) as-is.

Terms list (the authority on terms):
  applications/content_server/resources/TB_files/22776577_Terms/input_files/terms_1.0.tsv
  loaded via ConvertNewModel_2DwCA::generate_measurements_values_associations(), which returns its 4 term types:
  measurement, value, association, metadata. The filename may change in later versions (e.g. terms_1.1.tsv).

Principle: include as many records as possible; drop only what can't be supported, i.e. the record with the unknown term,
plus only what depends on it. One bad record never drops a sibling record.
  - A trait whose own measurementType and measurementValue are valid is always included, even when some of its context or
    qualifiers are dropped or blanked: "femur = 1590 mm" is included when "adult female" can't be supported.
  - A lone "60" is included when its measurementUnit URI is unknown: the unit is blanked and reported.

A MoF row has an unknown term if:
  - measurementValue is a URI not in EOL values, or
  - trait row (measurementOfTaxon = true): measurementType is not an EOL measurement term, or
  - context or child row (measurementOfTaxon not true): measurementType is not an EOL measurement or metadata term
    (e.g. sex, lifeStage, sample size, locality, contributor are metadata terms).
  A measurementType that is in the terms list only as a value term is misused rather than undefined. The row is still dropped,
  and reported separately. Examples: WoRMS's brooding (CEPH_0000036) and asexual reproduction (GO_0019954) traits;
  natdb4's "mean annual temperature" context rows. Such terms are not remapped and not added to the terms list (decided 2026-11-18).
An association has an unknown term if associationType is a URI not in EOL associations.

Rules:
  1. Trait row (has occurrenceID, measurementOfTaxon = true) with an unknown term
     => only this row is dropped, with its child rows. Other MoF and associations of the occurrence are included.
  2. Context row (has occurrenceID, measurementOfTaxon not true; e.g. sex, lifestage, sample size) with an unknown term
     => only this context row is dropped, with its child rows. The traits of the occurrence are included.
  3. Child row (no occurrenceID; linked to its parent by parentMeasurementID) with an unknown term
     => only this child row is dropped, with its own child rows. Its parent and occurrence are included,
        even if the child qualified the parent.
  4. Association with an unknown associationType
     => only this association is dropped. MoF and other associations are included.
  5. Any row whose parent/ancestor measurement is dropped is dropped too.
  6. Every orphaned occurrence is dropped: one that no included MoF row and no included association uses (as occurrenceID or
     targetOccurrenceID). This covers occurrences that lost all their records here, and occurrences that were already
     orphaned in the source; the run log counts the two separately.
  7. Qualifier fields: a URI value not in EOL values is blanked, and the record is included. Plain-text (non-URI) values are
     left as-is. Only included records are looked at.
       MoF:         measurementUnit, statisticalMethod, sex, lifeStage, bodyPart, measurementDeterminedBy
       Association: measurementDeterminedBy
       Occurrence:  sex, lifeStage, reproductiveCondition, establishmentMeans, physiologicalState, bodyPart
     Occurrence basisOfRecord and behavior are left as-is (no archive had values in them as of 2026-11-18).
  8. A MoF row whose occurrenceID is not in the occurrence table is dropped, with its child rows (rule 5).
     Not applied if the archive has no occurrence table.

References (Nov 20, 2026): each reference's identifier is replaced by its DOI as a link (DOI_URL, e.g. https://doi.org/10.1093/jn/44.1.43),
found by Full Reference Search (FullReferenceSearchAPI::find_DOI(), the same code and the same CrossRef/DataCite cache as the tool).
  - Only 'matched' and 'possible' DOIs are used; 'not found', 'skipped' and 'error' references keep their identifier.
  - Every referenceID (MoF, Association, Occurrence, Taxon; several IDs joined by ';' too) is changed the same way, so no link breaks.
  - A ';' in a DOI (old SICI DOIs, e.g. 10.1656/1528-7092(2003)002[0159:ANSOEC]2.0.CO;2) is written as %3B, since ';' separates referenceIDs;
    https://doi.org/...2.0.CO%3B2 opens the same page.
  - Two references with the same DOI are merged: one row is kept, and every ID points to it (Nov 22, 2026: the better one, not just the first):
      1. 'matched' over 'possible'
      2. then the one more of whose parts agree with CrossRef's record (journal, volume, issue, year, page, author, title)
      3. then the one with more parts read from it (author, year, journal, volume, issue, page): the more complete citation
      4. then the one that needed fewer clean-ups before the search (e.g. %...% markup, HTML): the cleaner text
      5. then the longer full_reference; and last, the first one in the file
    Each merge is listed in the debug report: the reference kept and the ones dropped, with their status and counts.
  - Always on. A new full_reference costs about 3 seconds (CrossRef's polite pace); cached ones are instant, so a re-run is fast.
    If CrossRef keeps answering HTTP 429/503, the lookups stop for this run and the remaining references keep their identifiers.
  - Every reference with its result: resources/neo4j_analyzed/[resource_id]_references_DOI_[date].tsv
    (old_identifier, status, DOI_URL, new_identifier, full_reference).
  - Also Full Reference Search's own report and guide, as the tool writes them, in CONTENT_RESOURCE_LOCAL_PATH/Reference_maintenance/:
    [resource_id]_reference_DOIs_[YYYYmmdd_HHMMSS].tsv + _guide.txt (counted with the tool's reports: only the newest 20 are kept).
  - Reference and Taxon are written here (not copied by DwCA_Utility), since both can have referenceIDs.

Report: resources/neo4j_analyzed/[resource_id]_analyzed_debug_[date].txt, with these sections (only those that occur):
  Undefined mValue                                    - value URIs not in the terms list (rules 1-3)
  Undefined mType                                     - trait types not in the terms list (rule 1)
  mType is an EOL value term, not a measurement term  - trait types that are value terms (rule 1)
  Undefined mType of a context/child row              - (rules 2-3)
  mType of a context/child row is an EOL value term, not a measurement or metadata term  - (rules 2-3)
  Undefined aType                                     - association types not in the terms list (rule 4)
  Orphaned occurrences in the source (deleted)        - count (rule 6)
  Blanked [extension].[field] (URI not in EOL values) - each blanked URI with its number of records (rule 7)
  MoF rows pointing to a missing occurrence (deleted) - rows and distinct occurrenceIDs (rule 8)
  References: identifier replaced by DOI_URL          - counts by Full Reference Search status, merged rows

Passes:
  Pass 0 ('collect_ref') - looks up the DOI of every reference (Full Reference Search): fills ref_map (old identifier => DOI_URL).
  Pass 0 ('collect_occ') - reads the occurrenceIDs of the occurrence table (for rule 8).
  Pass 1 ('collect') - applies rules 1-4 and 8 to MoF and Association: fills del_mID, flag_oID and child_parent.
  Pass 2 ('write') - writes Reference and Taxon (with the new identifiers), then MoF, Association, Occurrence, applying rules 1-8. While writing MoF and Association it
    records every occurrenceID that an included record uses (used_oID), so that Occurrence can apply rule 6.
  Run it in the Jenkins container (12g); the web container (1g) runs out of memory on large resources like WoRMS.
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class AnalyzeMoF_API
{
    function __construct($archive_builder, $resource_id, $archive_path)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        $this->archive_path = $archive_path;
        $this->download_options = array('cache' => 1, 'resource_id' => $resource_id, 'expire_seconds' => 60*60*24*1, 'download_wait_time' => 500000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        $this->debug = array();
        $temp = CONTENT_RESOURCE_LOCAL_PATH . 'neo4j_analyzed'; if(!is_dir($temp)) mkdir($temp);
        $this->neo4j_analyzed_folder = $temp;
        $this->modulo = 20000;
        if(strpos($this->resource_id, 'globi_assoc_finale') === 0) $this->modulo = 100000;
    }
    /*================================================================= STARTS HERE ======================================================================*/
    private function initial()
    {
        require_library('connectors/ZenodoTraitBankAPI');
        require_library('connectors/ConvertNewModel_2DwCA');
        $func = new ConvertNewModel_2DwCA(false, array());
        $ret = $func->generate_measurements_values_associations();
        $this->eol_term_values = $ret['values']; $this->eol_term_measurements = $ret['measurements']; $this->eol_term_associations = $ret['associations'];
        $this->eol_term_metadata = @$ret['metadata'] ?: array();
        if(!$this->eol_term_metadata) exit("\nERROR: no metadata terms loaded; context and child MoF rows can't be checked. Will terminate.\n");
        echo "\neol_term_values: [".count($this->eol_term_values)."]";
        echo "\neol_term_metadata: [".count($this->eol_term_metadata)."]";
        echo "\neol_term_measurements: [".count($this->eol_term_measurements)."]";
        echo "\neol_term_associations: [".count($this->eol_term_associations)."]";
        // print_r($this->eol_term_values); print_r($this->eol_term_measurements); print_r($this->eol_term_associations); exit("\nstopx 3\n");
    }
    function start($info)
    {   
        // progress echo every [modulo] rows in process_table(); must be set before the 1st process_table() call
        // resource_id here already ends in "_analyzed", e.g. "globi_assoc_finale_analyzed"
        self::initial();
        // /* Read the DwCA in question:
        $tables = $info['harvester']->tables; // print_r($tables); exit;
        $extensions = array_keys($tables); print_r($extensions); //exit;

        // --------------------- 1st pass: find the records with undefined terms (see rules at the top of this file)
        $this->del_mID = array();       // measurementIDs to delete (their descendants are deleted too)
        $this->flag_oID = array();      // occurrenceIDs that lost a record; deleted in 2nd pass only if no kept MoF or kept association uses them
        $this->used_oID = array();      // occurrenceIDs used by a kept MoF or kept association (filled in 2nd pass); all others are orphans
        $this->orphans = array('lost all its records' => 0, 'already orphaned in the source' => 0);
        // Rule 7: qualifier fields whose URI value must be in EOL values, else the value is blanked (record kept)
        $this->blank_fields = array(
            'mof'         => array('measurementUnit', 'statisticalMethod', 'sex', 'lifeStage', 'bodyPart', 'measurementDeterminedBy'),
            'association' => array('measurementDeterminedBy'),
            'occurrence'  => array('sex', 'lifeStage', 'reproductiveCondition', 'establishmentMeans', 'physiologicalState', 'bodyPart'));
        $this->blanked = array();       // "class.field" => array(uri => number of records blanked)
        $this->child_parent = array();  // measurementID => parentMeasurementID
        // Rule 8: occurrenceIDs present in the occurrence table; MoF rows pointing to any other occurrenceID are deleted.
        // If the archive has no occurrence table, rule 8 is not applied (false).
        $this->occ_exists = false;
        $this->missing_occ = array('rows' => 0, 'occurrenceIDs' => array());
        // References: old identifier => DOI_URL, by Full Reference Search (see "References" at the top of this file)
        $this->ref_map = array();
        $this->ref_best = array(); // DOI_URL => the reference kept when several have it: array('key' => ranking, 'id' => identifier, 'info' => for the debug report)
        $tbl = "http://eol.org/schema/reference/reference";         if($meta = @$tables[$tbl][0]) self::lookup_reference_DOIs($meta);
        $tbl = "http://rs.tdwg.org/dwc/terms/occurrence";           if($meta = @$tables[$tbl][0]) { $this->occ_exists = array(); self::process_table($meta, 'collect_occ', 'occurrence'); }
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";    if($meta = @$tables[$tbl][0]) self::process_table($meta, 'collect', 'mof');
        $tbl = "http://eol.org/schema/association";                 if($meta = @$tables[$tbl][0]) self::process_table($meta, 'collect', 'association');
        echo "\nMeasurements to delete (excl. descendants): [".count($this->del_mID)."]";
        echo "\nOccurrences flagged (lost a record): [".count($this->flag_oID)."]";
        if($this->occ_exists === false) echo "\nNo occurrence table: rule 8 (MoF pointing to a missing occurrence) not applied.";
        else {
            $n_occ = count($this->missing_occ['occurrenceIDs']);
            echo "\nMoF rows pointing to a missing occurrence (deleted, excl. descendants): [".$this->missing_occ['rows']."], distinct occurrenceIDs: [$n_occ]";
            if($n_occ) $this->debug['MoF rows pointing to a missing occurrence (deleted)']["rows: ".$this->missing_occ['rows']."; distinct occurrenceIDs: $n_occ"] = '';
        }
        unset($this->missing_occ['occurrenceIDs']); // free memory
        echo "\n";

        // --------------------- 2nd pass: write extensions, excluding every record linked to a deleted occurrence or deleted parent measurement
        $this->ref_written = array(); $this->ref_merged = 0;
        $tbl = "http://eol.org/schema/reference/reference";         if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'reference');
        if($this->ref_merged) {
            echo "\nReferences merged (same DOI as another, the better one kept): [{$this->ref_merged}]\n";
            $this->debug['References: identifier replaced by DOI_URL']["merged (same DOI as another reference; the better one kept): {$this->ref_merged}"] = '';
            foreach($this->ref_best as $url => $b) if($b['dropped']) $this->debug['References merged (same DOI): the one kept, then the ones dropped']["$url: kept " . $b['info'] . "; dropped " . implode("; ", $b['dropped'])] = '';
        }
        unset($this->ref_written, $this->ref_best);
        $tbl = "http://rs.tdwg.org/dwc/terms/taxon";                if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'taxon');
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";    if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'mof');
        unset($this->occ_exists); // free memory; only needed for rule 8 on MoF
        $tbl = "http://eol.org/schema/association";                 if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'association');
        $tbl = "http://rs.tdwg.org/dwc/terms/occurrence";           if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'occurrence');
        echo "\nOrphaned occurrences deleted - lost all their records: [".$this->orphans['lost all its records']."]";
        echo "\nOrphaned occurrences deleted - already orphaned in the source: [".$this->orphans['already orphaned in the source']."]\n";
        if($n = $this->orphans['already orphaned in the source']) $this->debug['Orphaned occurrences in the source (deleted)']["count: $n"] = '';
        foreach($this->blanked as $class_field => $uris) {
            $n = array_sum($uris);
            echo "\nBlanked [$class_field]: [$n] values, [".count($uris)."] distinct URIs";
            foreach($uris as $uri => $rows) $this->debug["Blanked $class_field (URI not in EOL values)"]["$uri  (records: $rows)"] = '';
        }
        echo "\n";

        // $this->archive_builder->finalize(TRUE);

        // */
        if($this->debug) Functions::start_print_debug($this->debug, $this->resource_id, $this->neo4j_analyzed_folder);
        unset($this->debug);
    }
    private function lookup_reference_DOIs($meta)
    {   // Pass 0: the DOI of every reference, by Full Reference Search (same code, same cache). Fills $this->ref_map (old identifier => DOI_URL)
        // for 'matched' and 'possible' only, and writes every reference's result to neo4j_analyzed/[resource_id]_references_DOI_[date].tsv.
        require_library('connectors/GenerateTB_Files_Functions');
        require_library('connectors/FullReferenceSearchAPI');
        $this->frs = new FullReferenceSearchAPI(array()); // its defaults: author/title checks and DataCite on, as the tool
        $this->frs->report_open($this->resource_id . "_reference"); // the tool's own report + guide, in Reference_maintenance/
        $file = $this->neo4j_analyzed_folder . "/" . $this->resource_id . "_references_DOI_" . date("Y-m-d") . ".tsv";
        $this->ref_tsv = fopen($file, 'w');
        fwrite($this->ref_tsv, implode("\t", array('old_identifier', 'status', 'DOI_URL', 'new_identifier', 'full_reference')) . "\n");
        $this->ref_counts = array(); $this->frs_stopped = false; $this->ref_done = 0;
        // the estimate, before the first call to CrossRef: how many references are new (about 3 seconds each) and how many are cached
        $this->ref_texts = array();
        self::process_table($meta, 'collect_ref_text', 'reference');
        $estimate = $this->frs->count_references_to_search($this->ref_texts); unset($this->ref_texts);
        echo "\nReferences: looking up their DOIs with Full Reference Search.\n" . FullReferenceSearchAPI::estimate_text($estimate) . "\n";
        $this->debug['References: identifier replaced by DOI_URL'][FullReferenceSearchAPI::estimate_text($estimate)] = '';
        self::process_table($meta, 'collect_ref', 'reference');
        fclose($this->ref_tsv);
        $frs_report = $this->frs->report_close("reference.tab of " . $this->resource_id . " (analyze_MoF)");
        unset($this->frs, $this->ref_tsv);
        if($frs_report) $this->debug['References: identifier replaced by DOI_URL']["Full Reference Search report: " . basename($frs_report['file']) . " (+ _guide.txt) in Reference_maintenance/"] = '';
        $replaced = count($this->ref_map);
        echo "\nReferences: [" . number_format($this->ref_done) . "] looked at, identifier replaced by DOI_URL: [" . number_format($replaced) . "] ";
        foreach($this->ref_counts as $status => $n) { echo "[$status: $n] "; $this->debug['References: identifier replaced by DOI_URL']["$status: $n"] = ''; }
        $this->debug['References: identifier replaced by DOI_URL']["identifiers replaced (matched + possible): $replaced; every reference: " . basename($file)] = '';
        if($this->frs_stopped) echo "\nWARNING: CrossRef kept asking us to slow down (HTTP 429/503): the remaining references were not searched and keep their identifiers. Run again later.";
        echo "\nEvery reference with its result: [$file]\n";
    }
    private function lookup_reference_DOI($rec)
    {   // one reference: its DOI_URL in ref_map when Full Reference Search gives 'matched' or 'possible'
        $id = (string) @$rec['identifier']; $full = (string) @$rec['full_reference'];
        if($id === '') return;
        $this->ref_done++;
        if($this->frs_stopped) $status = 'not searched (CrossRef asked us to slow down)';
        else {
            $r = $this->frs->find_DOI($full);
            $this->frs->report_row($id, $full, $r); // the tool's report (a 'stop' isn't written)
            $status = $r['status'];
            if($status == 'stop') { $this->frs_stopped = true; $status = 'not searched (CrossRef asked us to slow down)'; }
            elseif(in_array($status, array('matched', 'possible')) && ($url = (string) @$r['DOI_URL']) !== '')
            {
                $this->ref_map[$id] = $url = str_replace(';', '%3B', $url); // ';' separates referenceIDs, so it's percent-encoded (old SICI DOIs end in ";2"); doi.org reads it the same
                self::rank_reference($url, $id, $full, $r);
            }
        }
        @$this->ref_counts[$status]++;
        $clean = fn($s) => str_replace(array("\t", "\r", "\n"), ' ', (string) $s);
        fwrite($this->ref_tsv, implode("\t", array_map($clean, array($id, $status, $this->ref_map[$id] ?? '', $this->ref_map[$id] ?? $id, $full))) . "\n");
        if($this->ref_done % 100 == 0) { fflush($this->ref_tsv); echo "\n  references: " . number_format($this->ref_done) . " done, " . number_format(count($this->ref_map)) . " with a DOI"; }
    }
    private function rank_reference($url, $id, $full, $r)
    {   // MERGE: of the references with the same DOI_URL, the one to keep (see "References" at the top): the highest key wins; on a tie, the first one
        $agree = preg_match_all('/\b(?:journal|volume|issue|year|page|author|title)=(?:OK|nearly|first page|next to it|in the citation)\b/', (string) @$r['checks']);
        $parts = 0; foreach(array('author', 'year', 'journal', 'volume', 'issue', 'page') as $k) if(trim((string) @$r["parsed_$k"]) !== '') $parts++;
        $changes = count((array) @$r['change_codes']);
        $key = array($r['status'] == 'matched' ? 1 : 0, $agree, $parts, -$changes, mb_strlen(trim($full)));
        $info = "$id ({$r['status']}, $agree agree, $parts parts read, $changes clean-ups, " . mb_strlen(trim($full)) . " characters)";
        $best = $this->ref_best[$url] ?? null;
        if(!$best) { $this->ref_best[$url] = array('key' => $key, 'id' => $id, 'info' => $info, 'dropped' => array()); return; }
        if($key > $best['key']) { $this->ref_best[$url] = array('key' => $key, 'id' => $id, 'info' => $info, 'dropped' => array_merge($best['dropped'], array($best['info']))); return; }
        $this->ref_best[$url]['dropped'][] = $info;
    }
    private function map_reference_ids($value)
    {   // referenceID(s), e.g. "a1;b2": each old identifier replaced by its DOI_URL (ref_map); duplicates removed, order kept
        $value = (string) $value;
        if($value === '' || !$this->ref_map) return $value;
        $ids = array();
        foreach(explode(';', $value) as $id) { $id = trim($id); if($id === '') continue; $ids[$this->ref_map[$id] ?? $id] = ''; }
        return implode(';', array_keys($ids));
    }
    private function is_measurement_deleted($mID)
    {   // true if this measurement or any of its ancestors (via parentMeasurementID) is deleted
        $seen = array();
        while($mID && !isset($seen[$mID])) {
            if(isset($this->del_mID[$mID])) return true;
            $seen[$mID] = '';                       // guards against circular parentMeasurementID references
            $mID = @$this->child_parent[$mID];      // walk up to parent; stops at a top-level row (not in child_parent)
        }
        return false;
    }
    private function mof_problems($rec)
    {   // returns array(debug_key => uri) of the undefined terms in this MoF row; empty array if the row is fine
        $ret = array();
        $mValue = @$rec['measurementValue'];
        $mType = @$rec['measurementType'];
        if(substr($mValue, 0, 4) == 'http' && !isset($this->eol_term_values[$mValue])) $ret['Undefined mValue'] = $mValue;
        if(strtolower(@$rec['measurementOfTaxon']) == 'true') {     // trait row: mType must be a measurement term
            if(!isset($this->eol_term_measurements[$mType])) {
                if(isset($this->eol_term_values[$mType])) $ret['mType is an EOL value term, not a measurement term'] = $mType; // misused, not undefined
                else                                      $ret['Undefined mType'] = $mType;
            }
        }
        else {                                                      // context or child row: mType must be a measurement or metadata term
            if(!isset($this->eol_term_measurements[$mType]) && !isset($this->eol_term_metadata[$mType])) {
                if(isset($this->eol_term_values[$mType])) $ret['mType of a context/child row is an EOL value term, not a measurement or metadata term'] = $mType;
                else                                      $ret['Undefined mType of a context/child row'] = $mType;
            }
        }
        return $ret;
    }
    private function is_undefined_aType($aType)
    {
        return substr($aType, 0, 4) == 'http' && !isset($this->eol_term_associations[$aType]);
    }
    private function process_table($meta, $what, $class = false)
    {   echo "\nprocess_table analyze: [$what] [$meta->file_uri]...\n"; $i = 0;
        foreach (new FileIterator($meta->file_uri) as $line => $row) {
            $i++;
            if (($i % $this->modulo) == 0) echo "\n" . number_format($i) . " - ";
            if ($meta->ignore_header_lines && $i == 1) continue;
            if (!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array();
            $k = 0;
            foreach ($meta->fields as $field) {
                if (!$field['term']) continue;
                $rec[$field['term']] = $tmp[$k];
                $k++;
            } 
            $rec = Functions::shorten_record($rec);
            $rec = array_map('trim', $rec);
            // print_r($rec); exit;
            /* copied template
            $rec = self::not_recongized_fields($rec); //remove not recognized fields
            */
            /*Array(
                [measurementID] => 40e92024f3639e4c9f800dbbc1accd00_42
                [occurrenceID] => be19b9235e7e85b6940de0a31f58bc16_42
                [measurementOfTaxon] => true
                [measurementType] => http://purl.org/obo/owlATOL_0001659
                [measurementValue] => 60.0
                [measurementUnit] => http://purl.obolibrary.org/obo/UO_0000015
                [statisticalMethod] => 
                [measurementMethod] => Standard length; the length of a fish, measured from the tip of the snout to the tip of the hypural bone, or of the fleshy part of the caudal peduncle (i.e., excluding the caudal fin).
                [measurementRemarks] => 
                [source] => http://www.fishbase.org/summary/SpeciesSummary.php?id=2
                [bibliographicCitation] => Froese, R. and D. Pauly. Editors. 2026.FishBase. World Wide Web electronic publication. www.fishbase.org, ( 02/2026 )
                [contributor] => https://www.fishbase.de/collaborators/CollaboratorSummary.php?id=2
                [referenceID] => 1c6aa37fd9a66304a0f3232fdb521710
            )*/
            //========================================================================================================= 
            if($what == 'collect') { // 1st pass: flag occurrences of records with undefined terms
                if($class == "mof") {
                    $oID = @$rec['occurrenceID'];
                    $mID = @$rec['measurementID'];
                    if($mID && ($pID = @$rec['parentMeasurementID'])) $this->child_parent[$mID] = $pID;
                    // Rule 8: row points to an occurrence that is not in the occurrence table: delete this row (and its descendants).
                    if($oID && $this->occ_exists !== false && !isset($this->occ_exists[$oID])) {
                        $this->missing_occ['rows']++;
                        $this->missing_occ['occurrenceIDs'][$oID] = '';
                        if($mID) $this->del_mID[$mID] = ''; // so its child rows are deleted too (rule 5)
                        continue;
                    }
                    if($problems = self::mof_problems($rec)) {
                        foreach($problems as $debug_key => $uri) $this->debug[$debug_key][$uri] = '';
                        // Rules 1-3: delete only this row (trait, context or child row) and its descendants.
                        // Other MoF rows of the occurrence are kept, even when this row was context (e.g. sex, lifestage) for them.
                        if($mID) $this->del_mID[$mID] = '';
                        if($oID) $this->flag_oID[$oID] = '';
                    }
                }
                if($class == "association") {
                    /*Array(
                        [associationType] => http://purl.obolibrary.org/obo/RO_0002556
                        [measurementDeterminedBy] =>
                        [measurementMethod] =>
                        [measurementRemarks] =>
                        [bibliographicCitation] =>
                        [contributor] =>
                    )*/
                    $aType = @$rec['associationType'];
                    if(self::is_undefined_aType(@$rec['associationType'])) {
                        $this->debug['Undefined aType'][$rec['associationType']] = '';
                        // flag its occurrences; they are deleted in the 2nd pass only if no kept MoF or kept association uses them
                        if($oID = @$rec['occurrenceID']) $this->flag_oID[$oID] = '';
                        if($tID = @$rec['targetOccurrenceID']) $this->flag_oID[$tID] = '';
                    }
                }
                continue;
            }
            //=========================================================================================================
            if($what == 'collect_ref_text') { if((string) @$rec['identifier'] !== '') $this->ref_texts[] = (string) @$rec['full_reference']; continue; } // for the estimate
            if($what == 'collect_ref') { self::lookup_reference_DOI($rec); continue; } // 0: references' DOIs
            //=========================================================================================================
            if($what == 'collect_occ') { // 0: occurrenceIDs present in the occurrence table (rule 8)
                if($oID = @$rec['occurrenceID']) $this->occ_exists[$oID] = '';
                continue;
            }
            //=========================================================================================================
            if($what == 'write') { // 2nd pass
                $oID = @$rec['occurrenceID'];
                if($class == "mof") {
                    // Rule 8: skip rows pointing to an occurrence that is not in the occurrence table (re-checked here, so rows
                    // without measurementID are covered too).
                    if($oID && $this->occ_exists !== false && !isset($this->occ_exists[$oID])) continue;
                    // Skip the row that has an undefined term (re-checked here, so rows without measurementID are covered too).
                    if(self::mof_problems($rec)) continue;
                    // Skip rows whose parent/ancestor measurement was deleted.
                    if(self::is_measurement_deleted(@$rec['measurementID'])) continue;
                    // MoF is kept: its occurrence must be kept too.
                    if($oID) $this->used_oID[$oID] = '';
                }
                if($class == "association") {
                    // Skip only the association that has the undefined associationType.
                    if(self::is_undefined_aType(@$rec['associationType'])) continue;
                    // Association is kept: its occurrences must be kept too.
                    if($oID) $this->used_oID[$oID] = '';
                    if($tID = @$rec['targetOccurrenceID']) $this->used_oID[$tID] = '';
                }
                if($class == "occurrence") {
                    // Delete every orphaned occurrence: one that no kept MoF row or kept association uses.
                    if(!$oID || !isset($this->used_oID[$oID])) {
                        if($oID && isset($this->flag_oID[$oID])) $this->orphans['lost all its records']++;
                        else                                    $this->orphans['already orphaned in the source']++;
                        continue;
                    }
                }

                if($class == "reference") { // identifier -> its DOI_URL; of the references with the same DOI only the best is written (MERGE, rank_reference())
                    $old = (string) @$rec['identifier']; $new = $this->ref_map[$old] ?? $old;
                    if(isset($this->ref_map[$old]) && $this->ref_best[$new]['id'] !== $old) { $this->ref_merged++; continue; }
                    if(isset($this->ref_written[$new])) { $this->ref_merged++; continue; } // the same identifier twice in the source
                    $this->ref_written[$new] = '';
                    $rec['identifier'] = $new;
                }
                if(isset($rec['referenceID'])) $rec['referenceID'] = self::map_reference_ids($rec['referenceID']); // MoF, Association, Occurrence, Taxon

                // Rule 7: blank URI values not in EOL values in the qualifier fields; $rec is modified before $o is populated below.
                foreach($this->blank_fields[$class] ?? array() as $field) {
                    $val = trim((string) @$rec[$field]);
                    if(substr($val, 0, 4) == 'http' && !isset($this->eol_term_values[$val])) {
                        @$this->blanked["$class.$field"][$val]++;
                        $rec[$field] = '';
                    }
                }

                /*
User Warning: Undefined property `basisOfRecord` on eol_schema\Occurrence as defined by `http://editors.eol.org/other_files/ontology/occurrence_extension.xml` in /var/www/html/eol_php8_code/vendor/eol_content_schema_v2/DarwinCoreExtensionBase.php on line 241
User Warning: Undefined property `physiologicalState` on eol_schema\Occurrence as defined by `http://editors.eol.org/other_files/ontology/occurrence_extension.xml` in /var/www/html/eol_php8_code/vendor/eol_content_schema_v2/DarwinCoreExtensionBase.php on line 241
User Warning: Undefined property `bodyPart` on eol_schema                
                */
                // Populate the output object only after validation, so changes to $rec are carried over.
                    if($class == "occurrence")      $o = new \eol_schema\Occurrence_specific();
                elseif($class == "mof")             $o = new \eol_schema\MeasurementOrFact_specific();
                elseif($class == "association")     $o = new \eol_schema\Association();
                elseif($class == "reference")       $o = new \eol_schema\Reference();
                elseif($class == "taxon")           $o = new \eol_schema\Taxon();
                else exit("\nUndefined class [$class]. Will terminate.\n");
                foreach(array_keys($rec) as $uri) {
                    $field = pathinfo($uri, PATHINFO_BASENAME);
                    $parts = explode("#", $field);
                    if($parts[0]) $field = $parts[0];
                    if(@$parts[1]) $field = $parts[1];
                    $o->$field = $rec[$uri];
                }
                $this->archive_builder->write_object_to_file($o);
            }
            //========================================================================================================= 

            // if($i >= 100) break; //dev only
        }
    }
}