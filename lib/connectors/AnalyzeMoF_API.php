<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from analyze_MoF.php]
e.g. php update_resources/connectors/analyze_MoF.php _ '{"resource_id": "AmphibiaWeb_finale"}'
     reads:  resources/AmphibiaWeb_finale.tar.gz
     writes: resources/AmphibiaWeb_finale_analyzed.tar.gz

What it does:
Removes from a DwCA the MoF and Association records that use terms not defined in EOL's terms list (terms.tsv,
loaded via ConvertNewModel_2DwCA::generate_measurements_values_associations()), together with the records that depend on them.
DwCA_Utility copies all other extensions (taxon, media, reference, agent, vernacular, etc.) as-is; only these 3 are processed here:
MeasurementOrFact, Association and Occurrence.

Principle: keep as many records as possible; delete only what can't be supported, i.e. the record with the undefined term,
plus only what depends on it. One bad record never removes a sibling record. A trait whose own measurementType and value are
valid is kept, even if some of its context (e.g. sex, lifestage) is removed or blanked: "femur = 1590 mm" is still accepted
when "adult female" can't be supported.

A MoF row has an undefined term if:
  - measurementValue is a URI not in EOL values, or
  - measurementOfTaxon = true and measurementType is not in EOL measurements
    (reported separately if the mType is an EOL value term, i.e. misused rather than undefined)
An association has an undefined term if associationType is a URI not in EOL associations.

Rules:
  1. Trait row (has occurrenceID, measurementOfTaxon = true) with an undefined term
     => only this row is deleted, with its child rows (del_mID). Other MoF and associations of the occurrence are kept.
  2. Occurrence context row (has occurrenceID, measurementOfTaxon not true; e.g. sex, lifestage, sample size) with an undefined term
     => only this context row is deleted, with its child rows (del_mID). The traits of the occurrence are kept.
  3. Child row (no occurrenceID; linked to its parent by parentMeasurementID) with an undefined term
     => only this child row is deleted, with its own child rows (del_mID). Its parent and occurrence are kept.
  4. Association with an undefined associationType
     => only this association is deleted. MoF and other associations are kept.
  5. Any row whose parent/ancestor measurement is deleted is deleted too.
  6. Every orphaned occurrence is deleted: one that no kept MoF row and no kept association uses (as occurrenceID or
     targetOccurrenceID). This covers occurrences that lost all their records here, and occurrences that were already
     orphaned in the source; the run log counts the two separately.
  7. Qualifier fields: a URI value not in EOL values is blanked; the record is kept. Literal (non-URI) values are left as-is.
       MoF:         measurementUnit, statisticalMethod, sex, lifeStage, bodyPart, measurementDeterminedBy
       Association: measurementDeterminedBy
       Occurrence:  sex, lifeStage, reproductiveCondition, establishmentMeans, physiologicalState, bodyPart
     Only records that are kept are checked. Each blanked URI is listed in the debug report with its number of records.
  8. A MoF row whose occurrenceID is not in the occurrence table is deleted, with its child rows (rule 5).
     Not applied if the archive has no occurrence table. The run log and debug report give the number of rows deleted.

Pass 0 ('collect_occ') - reads the occurrenceIDs of the occurrence table (for rule 8).
Pass 1 ('collect') - applies rules 1-4 and 8 to MoF and Association: fills del_mID, flag_oID and child_parent.
Pass 2 ('write') - writes MoF, then Association, then Occurrence, applying rules 1-8. While writing MoF and Association it
  records every occurrenceID that a kept record uses (used_oID), so that Occurrence can apply rule 6.

The undefined terms found are listed in: resources/neo4j_analyzed/[resource_id]_debug_[date].txt
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
    }
    /*================================================================= STARTS HERE ======================================================================*/
    private function initial()
    {
        require_library('connectors/ZenodoTraitBankAPI');
        require_library('connectors/ConvertNewModel_2DwCA');
        $func = new ConvertNewModel_2DwCA(false, array());
        $ret = $func->generate_measurements_values_associations();
        $this->eol_term_values = $ret['values']; $this->eol_term_measurements = $ret['measurements']; $this->eol_term_associations = $ret['associations'];
        echo "\neol_term_values: [".count($this->eol_term_values)."]";
        echo "\neol_term_measurements: [".count($this->eol_term_measurements)."]";
        echo "\neol_term_associations: [".count($this->eol_term_associations)."]";
        // print_r($this->eol_term_values); print_r($this->eol_term_measurements); print_r($this->eol_term_associations); exit("\nstopx 3\n");
    }
    function start($info)
    {   
        self::initial();
        // /* Read the DwCA in question:
        $tables = $info['harvester']->tables; // print_r($tables); exit;
        $extensions = array_keys($tables); print_r($extensions); //exit;

        // --------------------- get undefined mTypes and mValues
        /* not needed anymore...
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";    if($meta = @$tables[$tbl][0]) self::process_table($meta, 'analyze_MoF');
        $tbl = "http://eol.org/schema/association";                 if($meta = @$tables[$tbl][0]) self::process_table($meta, 'analyze_Assoc');
        */

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
        if(strtolower(@$rec['measurementOfTaxon']) == 'true' && !isset($this->eol_term_measurements[$mType])) {
            if(isset($this->eol_term_values[$mType])) $ret['mType is an EOL value term, not a measurement term'] = $mType; // misused, not undefined
            else                                      $ret['Undefined mType'] = $mType;
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
            if (($i % 20000) == 0) echo "\n" . number_format($i) . " - ";
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
            if($what == 'analyze_MoF') {
                $mOfTaxon = strtolower($rec['measurementOfTaxon']);
                $mValue = $rec['measurementValue'];
                $mType = $rec['measurementType'];
                if(substr($mValue, 0, 4) == 'http') {
                    if(!isset($this->eol_term_values[$mValue])) $this->debug['Undefined mValue'][$mValue] = '';
                }
                if($mOfTaxon == 'true') {
                    if(!isset($this->eol_term_measurements[$mType])) $this->debug['Undefined mType'][$mType] = '';
                }
                if($val = @$rec['measurementDeterminedBy']) {
                    if(!isset($this->eol_term_values[$val])) $this->debug['Undefined measurementDeterminedBy'][$val] = '';
                }
            }
            //========================================================================================================= 
            if($what == 'analyze_Assoc') {
                /*Array(
                    [associationType] => http://purl.obolibrary.org/obo/RO_0002556
                    [measurementDeterminedBy] => 
                    [measurementMethod] => 
                    [measurementRemarks] => 
                    [bibliographicCitation] => 
                    [contributor] => 
                )*/
                $aType = $rec['associationType'];
                if(substr($aType, 0, 4) == 'http') {
                    if(!isset($this->eol_term_associations[$aType])) $this->debug['Undefined mType'][$aType] = '';
                }
                if($val = @$rec['measurementDeterminedBy']) {
                    if(!isset($this->eol_term_values[$val])) $this->debug['Undefined measurementDeterminedBy'][$val] = '';
                }
            }
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

                // Rule 7: blank URI values not in EOL values in the qualifier fields; $rec is modified before $o is populated below.
                foreach($this->blank_fields[$class] as $field) {
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