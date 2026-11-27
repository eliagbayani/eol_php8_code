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
  - Only the references the MoF or the associations use are searched (Nov 26, 2026): those whose identifier is in a MoF or Association
    referenceID (several joined by ';' or '|'). A reference used only by media, occurrences or taxa keeps its identifier
    ('not searched (not used by the MoF or associations)').
  - reference.tab is trimmed first (Nov 26, 2026): only the rows whose identifier is used as a referenceID or source in the MoF
    (measurement_or_fact[_specific].tab), Association, Taxon or Occurrence (occurrence[_specific].tab) are kept; the others are removed
    (not searched, not written). Nov 27, 2026: checked against the rows that are written: the kept MoF and association rows (rules 1-4, 8),
    the occurrences they keep (rule 6), every taxon, and every row of the other extensions (e.g. media). So no reference a written row uses
    is removed, and no orphan is left: the run log and debug report say "reference.tab: N rows written, orphans (used by no written row): 0".
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
  - Options for the search (Nov 25, 2026), from analyze_MoF.php's JSON, as in do_full_reference_search.php: "min_year" (e.g. 1900: references
    published before it are 'skipped', not searched, and keep their identifier) and "datacite_search" (false: CrossRef only). Without them:
    no year limit and DataCite on. Use the same options as the resource's Full Reference Search run, so the lookups come from the cache.
    They are said in the run log and the debug report.
  - Always on. A new full_reference costs about 3 seconds (CrossRef's polite pace); cached ones are instant, so a re-run is fast.
    If CrossRef keeps answering HTTP 429/503, the lookups stop for this run and the remaining references keep their identifiers.
  - Every reference with its result: resources/neo4j_analyzed/[resource_id]_references_DOI_[date].tsv
    (old_identifier, status, DOI_URL, new_identifier, full_reference).
  - Also Full Reference Search's own report and guide, as the tool writes them, in CONTENT_RESOURCE_LOCAL_PATH/Reference_maintenance/:
    [resource_id]_reference_DOIs_[YYYYmmdd_HHMMSS].tsv + _guide.txt (counted with the tool's reports: only the newest 50 are kept).
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
  Pass 0 ('collect_occ') - reads the occurrenceIDs of the occurrence table (for rule 8).
  Pass 1 ('collect') - applies rules 1-4 and 8 to MoF and Association: fills del_mID, flag_oID and child_parent.
  Then the references (Nov 27, 2026: after pass 1, so only the rows that will be written count):
    'collect_used_refs' - the identifiers used as referenceID or source by the rows that will be written, in every table (mof_kept(),
      assoc_kept()); the references not in it are removed from reference.tab.
    'collect_mof_refs' - the identifiers of the references the kept MoF and association rows use: only these get a DOI lookup.
    'collect_ref' - looks up the DOI of each of those references (Full Reference Search): fills ref_map (old identifier => DOI_URL).
  Pass 2 ('write') - writes Reference and Taxon (with the new identifiers), then MoF, Association, Occurrence, applying rules 1-8. While writing MoF and Association it
    records every occurrenceID that an included record uses (used_oID), so that Occurrence can apply rule 6.
  Run it in the Jenkins container (12g); the web container (1g) runs out of memory on large resources like WoRMS.
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class AnalyzeMoF_API
{
    function __construct($archive_builder, $resource_id, $archive_path, $frs_options = array())
    {
        $this->resource_id = $resource_id;
        $this->frs_options = (array) $frs_options; // Full Reference Search options: "min_year", "datacite_search" (Nov 25, 2026)
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

        // --------------------- References (after pass 1, so only the rows that will be written count; Nov 27, 2026)
        // (Nov 26, 2026) only the references the kept MoF and association rows use get a DOI (e.g. not those used only by media)
        $this->mof_refs = array();
        // reference.tab is trimmed: a reference is kept only if a row that is written uses it (referenceID or source), in any table:
        // the kept MoF and association rows, the occurrences they keep (rule 6), every taxon, and every row of the other extensions (e.g. media, copied as-is)
        $this->used_refs = array(); $this->ref_removed = 0; $this->ref_total = 0; $this->kept_oID = array(); $this->other_ext_refs = array();
        $done_tbls = array('http://eol.org/schema/reference/reference' => 'reference', 'http://rs.tdwg.org/dwc/terms/measurementorfact' => 'mof',
                           'http://eol.org/schema/association' => 'association', 'http://rs.tdwg.org/dwc/terms/occurrence' => 'occurrence', 'http://rs.tdwg.org/dwc/terms/taxon' => 'taxon');
        foreach($done_tbls as $tbl => $class) if($class != 'reference' && ($meta = @$tables[$tbl][0])) self::process_table($meta, 'collect_used_refs', $class); // MoF and association first: they decide the occurrences
        foreach($tables as $tbl => $metas) if(!isset($done_tbls[$tbl]) && ($meta = @$metas[0])) self::process_table($meta, 'collect_used_refs', 'other');
        unset($this->kept_oID);
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";    if($meta = @$tables[$tbl][0]) self::process_table($meta, 'collect_mof_refs', 'mof');
        $tbl = "http://eol.org/schema/association";                 if($meta = @$tables[$tbl][0]) self::process_table($meta, 'collect_mof_refs', 'association');
        $tbl = "http://eol.org/schema/reference/reference";         if($meta = @$tables[$tbl][0]) self::lookup_reference_DOIs($meta);

        // --------------------- 2nd pass: write extensions, excluding every record linked to a deleted occurrence or deleted parent measurement
        $this->ref_written = array(); $this->ref_merged = 0; $this->ref_uses_out = array(); // ref_uses_out: the reference IDs the written rows use (for the orphan check)
        $tbl = "http://eol.org/schema/reference/reference";         if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'reference');
        if($this->ref_merged) {
            echo "\nReferences merged (same DOI as another, the better one kept): [{$this->ref_merged}]\n";
            $this->debug['References: identifier replaced by DOI_URL']["merged (same DOI as another reference; the better one kept): {$this->ref_merged}"] = '';
            foreach($this->ref_best as $url => $b) if($b['dropped']) $this->debug['References merged (same DOI): the one kept, then the ones dropped']["$url: kept " . $b['info'] . "; dropped " . implode("; ", $b['dropped'])] = '';
        }
        $ref_written_ids = $this->ref_written; unset($this->ref_written, $this->ref_best);
        $tbl = "http://rs.tdwg.org/dwc/terms/taxon";                if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'taxon');
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";    if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'mof');
        unset($this->occ_exists); // free memory; only needed for rule 8 on MoF
        $tbl = "http://eol.org/schema/association";                 if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'association');
        $tbl = "http://rs.tdwg.org/dwc/terms/occurrence";           if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'occurrence');
        if(isset($tables['http://eol.org/schema/reference/reference'])) { // (Nov 27, 2026) no orphan in the output reference.tab: every row is used by a written row
            foreach($this->other_ext_refs as $id => $x) $this->ref_uses_out[$this->ref_map[$id] ?? $id] = ''; // other extensions are copied as-is, with their old IDs
            $orphans = count(array_diff_key($ref_written_ids, $this->ref_uses_out));
            $c = "reference.tab: " . number_format(count($ref_written_ids)) . " rows written, orphans (used by no written row): $orphans";
            echo "\n$c" . ($orphans ? "  WARNING" : "") . "\n"; $this->debug['References: identifier replaced by DOI_URL'][$c] = '';
        }
        unset($ref_written_ids, $this->ref_uses_out, $this->other_ext_refs);
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
        $this->frs = new FullReferenceSearchAPI($this->frs_options); // the run's options (none: its defaults, author/title checks and DataCite on, as the tool)
        $o = "Full Reference Search options: " . (!empty($this->frs_options['min_year']) ? "min_year " . (int) $this->frs_options['min_year'] . " (references published before it are skipped, not searched)" : "no year limit")
           . "; DataCite " . ($this->frs->datacite_search ? "on" : "off (CrossRef only)");
        echo "\n$o\n"; $this->debug['References: identifier replaced by DOI_URL'][$o] = '';
        $this->frs->report_open($this->resource_id . "_reference"); // the tool's own report + guide, in Reference_maintenance/
        $file = $this->neo4j_analyzed_folder . "/" . $this->resource_id . "_references_DOI_" . date("Y-m-d") . ".tsv";
        $this->ref_tsv = fopen($file, 'w');
        fwrite($this->ref_tsv, implode("\t", array('old_identifier', 'status', 'DOI_URL', 'new_identifier', 'full_reference')) . "\n");
        $this->ref_counts = array(); $this->frs_stopped = false; $this->ref_done = 0;
        // the estimate, before the first call to CrossRef: how many references are new (about 3 seconds each) and how many are cached
        $this->ref_texts = array();
        self::process_table($meta, 'collect_ref_text', 'reference');
        $estimate = $this->frs->count_references_to_search($this->ref_texts); unset($this->ref_texts);
        $u = "References used by the MoF or associations: " . number_format(count($this->mof_refs)) . " (only these are searched; the others keep their identifier)";
        echo "\n$u\n"; $this->debug['References: identifier replaced by DOI_URL'][$u] = '';
        echo "\nReferences: looking up their DOIs with Full Reference Search.\n" . FullReferenceSearchAPI::estimate_text($estimate) . "\n";
        $this->debug['References: identifier replaced by DOI_URL'][FullReferenceSearchAPI::estimate_text($estimate)] = '';
        self::process_table($meta, 'collect_ref', 'reference');
        fclose($this->ref_tsv);
        $frs_report = $this->frs->report_close("reference.tab of " . $this->resource_id . " (analyze_MoF)");
        unset($this->frs, $this->ref_tsv);
        if($frs_report) $this->debug['References: identifier replaced by DOI_URL']["Full Reference Search report: " . basename($frs_report['file']) . " (+ _guide.txt) in Reference_maintenance/"] = '';
        $t = "reference.tab trimmed: " . number_format($this->ref_removed) . " of " . number_format($this->ref_total) . " rows removed (identifier not used as referenceID or source by any row that is written); " . number_format($this->ref_total - $this->ref_removed) . " kept";
        echo "\n$t\n"; $this->debug['References: identifier replaced by DOI_URL'][$t] = '';
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
        $this->ref_total++;
        if($id === '' || !isset($this->used_refs[$id])) { $this->ref_removed++; return; } // (Nov 26, 2026) not used by MoF, Association, Taxon or Occurrence: removed
        $this->ref_done++;
        if(!isset($this->mof_refs[$id])) $status = 'not searched (not used by the MoF or associations)'; // (Nov 26, 2026) only those get a DOI; e.g. one used only by media keeps its identifier
        elseif($this->frs_stopped) $status = 'not searched (CrossRef asked us to slow down)';
        else {
            $r = $this->frs->find_DOI($full, '', $id); // (Nov 27, 2026) with its identifier: a person's ID (ORCID, FishBase or WoRMS person page) is never searched
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
    {   // referenceID(s), e.g. "a1;b2" or "density|TRY|mass" (Nov 26, 2026: TreeCarbonEstimates joins them with "|"): each old identifier replaced by
        // its DOI_URL (ref_map); duplicates removed, order kept, joined again with the separator the value used
        $value = (string) $value;
        if($value === '' || !$this->ref_map) return $value;
        $ids = array();
        foreach(self::split_reference_ids($value) as $id) $ids[$this->ref_map[$id] ?? $id] = '';
        return implode(str_contains($value, ';') ? ';' : (str_contains($value, '|') ? '|' : ';'), array_keys($ids));
    }
    private static function split_reference_ids($value)
    {   // "a1;b2", "a1|b2" or "a1; b2" -> array('a1', 'b2'): the identifiers in a referenceID value
        return array_values(array_filter(array_map('trim', preg_split('/[;|]/', (string) $value)), fn($x) => $x !== ''));
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
    private function mof_kept($rec)
    {   // (Nov 27, 2026) the write pass's tests for a MoF row, also used before it (collect_used_refs, collect_mof_refs)
        $oID = @$rec['occurrenceID'];
        if($oID && $this->occ_exists !== false && !isset($this->occ_exists[$oID])) return false; // rule 8
        if(self::mof_problems($rec)) return false;                                                  // rules 1-3
        if(self::is_measurement_deleted(@$rec['measurementID'])) return false;                      // its parent/ancestor was deleted
        return true;
    }
    private function assoc_kept($rec)
    {   return !self::is_undefined_aType(@$rec['associationType']); } // rule 4
    private function add_used_refs($rec, &$set)
    {   // the reference IDs a row uses: its referenceID and source (the whole source too, e.g. a URL with a '|' in it)
        foreach(array('referenceID', 'source') as $f) {
            $v = trim((string) @$rec[$f]); if($v === '') continue;
            if($f == 'source') $set[$v] = '';
            foreach(self::split_reference_ids($v) as $id) $set[$id] = '';
        }
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
            if($what == 'collect_used_refs') { // the references used by the rows that will be written (Nov 26-27, 2026)
                if($class == 'mof')         { if(!self::mof_kept($rec)) continue; if($oID = @$rec['occurrenceID']) $this->kept_oID[$oID] = ''; }
                if($class == 'association') { if(!self::assoc_kept($rec)) continue; if($oID = @$rec['occurrenceID']) $this->kept_oID[$oID] = ''; if($tID = @$rec['targetOccurrenceID']) $this->kept_oID[$tID] = ''; }
                if($class == 'occurrence' && !isset($this->kept_oID[(string) @$rec['occurrenceID']])) continue; // rule 6 deletes it
                self::add_used_refs($rec, $this->used_refs);
                if($class == 'other') self::add_used_refs($rec, $this->other_ext_refs);
                continue;
            }
            if($what == 'collect_mof_refs') {
                if($class == 'mof' && !self::mof_kept($rec)) continue; if($class == 'association' && !self::assoc_kept($rec)) continue; // (Nov 27, 2026) kept rows only
                foreach(self::split_reference_ids((string) @$rec['referenceID']) as $id) $this->mof_refs[$id] = ''; continue; } // the references the MoF and the associations use
            if($what == 'collect_ref_text') { if((string) @$rec['identifier'] !== '' && isset($this->mof_refs[(string) $rec['identifier']]) && FullReferenceSearchAPI::is_person_id((string) $rec['identifier']) === '') $this->ref_texts[] = (string) @$rec['full_reference']; continue; } // for the estimate: only those searched (a person's ID isn't, Nov 27, 2026)
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
                    // Skip the row that has an undefined term, and rows whose parent/ancestor measurement was deleted (rules 1-3); mof_kept().
                    if(!self::mof_kept($rec)) continue;
                    // MoF is kept: its occurrence must be kept too.
                    if($oID) $this->used_oID[$oID] = '';
                }
                if($class == "association") {
                    // Skip only the association that has the undefined associationType.
                    if(!self::assoc_kept($rec)) continue;
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
                    if($old === '' || !isset($this->used_refs[$old])) continue; // (Nov 26, 2026) not used: removed from reference.tab
                    if(isset($this->ref_map[$old]) && $this->ref_best[$new]['id'] !== $old) { $this->ref_merged++; continue; }
                    if(isset($this->ref_written[$new])) { $this->ref_merged++; continue; } // the same identifier twice in the source
                    $this->ref_written[$new] = '';
                    $rec['identifier'] = $new;
                }
                if(isset($rec['referenceID'])) $rec['referenceID'] = self::map_reference_ids($rec['referenceID']); // MoF, Association, Occurrence, Taxon
                if($class != "reference") self::add_used_refs($rec, $this->ref_uses_out); // (Nov 27, 2026) for the orphan check

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