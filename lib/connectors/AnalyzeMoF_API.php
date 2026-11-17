<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from analyze_MoF.php]
e.g. php update_resources/connectors/analyze_MoF.php _ '{"resource_id": "AmphibiaWeb_finale"}'
     reads:  resources/AmphibiaWeb_finale.tar.gz
     writes: resources/AmphibiaWeb_finale_analyzed.tar.gz

What it does:
Removes from a DwCA the MoF and Association records that use terms not defined in EOL's terms list (terms.tsv,
loaded via ConvertNewModel_2DwCA::generate_measurements_values_associations()), together with the records linked to them.
DwCA_Utility copies all other extensions (taxon, media, reference, agent, vernacular, etc.) as-is; only these 3 are processed here:
MeasurementOrFact, Association and Occurrence.

Pass 1 ('collect') - find what to delete:
  MoF parent row (has occurrenceID):
    - measurementValue is a URI not in EOL values, or
    - measurementOfTaxon = true and measurementType is not in EOL measurements
    => its occurrenceID is deleted (del_oID).
  MoF child row (no occurrenceID; linked to its parent by parentMeasurementID):
    - measurementValue is a URI not in EOL values
    => only this child row is deleted (del_mID); its parent measurement and occurrence are kept.
  Association:
    - associationType is a URI not in EOL associations
    => both its occurrenceID and targetOccurrenceID are deleted (del_oID).
Pass 1b ('collect_mID') - the measurementIDs of MoF parent rows whose occurrence is deleted are added to del_mID,
  so that their child rows are deleted too.
Pass 2 ('write') - writes MoF, Association and Occurrence, skipping:
  - any record whose occurrenceID is in del_oID (association: also its targetOccurrenceID)
  - any MoF child row that is in del_mID, or whose parent/ancestor measurement is in del_mID
  Also, measurementDeterminedBy (MoF and Association) is blanked if its value is not in EOL values.

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

        // --------------------- 1st pass: collect occurrenceIDs to delete (MoF/associations with undefined terms)
        // Child MoF rows (no occurrenceID, linked by parentMeasurementID) with an undefined value are dropped individually (del_mID); parent and occurrence are kept.
        $this->del_oID = array();       // occurrenceIDs to delete
        $this->del_mID = array();       // measurementIDs to delete
        $this->child_parent = array();  // child measurementID => parentMeasurementID
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";    if($meta = @$tables[$tbl][0]) self::process_table($meta, 'collect', 'mof');
        $tbl = "http://eol.org/schema/association";                 if($meta = @$tables[$tbl][0]) self::process_table($meta, 'collect', 'association');
        // 1b: measurementIDs of parent rows whose occurrence is deleted, so their child rows are dropped too
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";    if($this->del_oID && ($meta = @$tables[$tbl][0])) self::process_table($meta, 'collect_mID', 'mof');
        echo "\nOccurrences to delete: [".count($this->del_oID)."]";
        echo "\nMeasurements to delete (excl. descendants): [".count($this->del_mID)."]\n";

        // --------------------- 2nd pass: write extensions, excluding every record linked to a deleted occurrence or deleted parent measurement
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";    if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'mof');
        $tbl = "http://eol.org/schema/association";                 if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'association');
        $tbl = "http://rs.tdwg.org/dwc/terms/occurrence";           if($meta = @$tables[$tbl][0]) self::process_table($meta, 'write', 'occurrence');

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
                    $mOfTaxon = strtolower(@$rec['measurementOfTaxon']);
                    $mValue = @$rec['measurementValue'];
                    $mType = @$rec['measurementType'];
                    $oID = @$rec['occurrenceID'];
                    $mID = @$rec['measurementID'];
                    if(!$oID && $mID && ($pID = @$rec['parentMeasurementID'])) $this->child_parent[$mID] = $pID;
                    if(substr($mValue, 0, 4) == 'http' && !isset($this->eol_term_values[$mValue])) {
                        $this->debug['Undefined mValue'][$mValue] = '';
                        if($oID) $this->del_oID[$oID] = '';     // parent row: delete its occurrence (and everything linked to it)
                        elseif($mID) $this->del_mID[$mID] = ''; // child row: delete only this row (and its own descendants)
                    }
                    if($mOfTaxon == 'true' && !isset($this->eol_term_measurements[$mType])) {
                        $this->debug['Undefined mType'][$mType] = '';
                        if($oID) $this->del_oID[$oID] = '';
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
                    if(substr($aType, 0, 4) == 'http' && !isset($this->eol_term_associations[$aType])) {
                        $this->debug['Undefined aType'][$aType] = '';
                        if($oID = @$rec['occurrenceID']) $this->del_oID[$oID] = '';
                        if($tID = @$rec['targetOccurrenceID']) $this->del_oID[$tID] = ''; //also remove the target occurrence of the removed association
                    }
                }
                continue;
            }
            //=========================================================================================================
            if($what == 'collect_mID') { // 1b: parent MoF rows of deleted occurrences
                if(($oID = @$rec['occurrenceID']) && isset($this->del_oID[$oID]) && ($mID = @$rec['measurementID'])) $this->del_mID[$mID] = '';
                continue;
            }
            //=========================================================================================================
            if($what == 'write') { // 2nd pass
                // Skip every MoF/association record linked to a deleted occurrence (this includes the records that failed validation in the 1st pass).
                if(($oID = @$rec['occurrenceID']) && isset($this->del_oID[$oID])) continue;
                // Skip child MoF rows that were deleted, or whose parent/ancestor measurement was deleted.
                if($class == "mof" && !$oID && self::is_measurement_deleted(@$rec['measurementID'])) continue;
                if($class == "association") {
                    if(isset($this->del_oID[@$rec['targetOccurrenceID']])) continue;
                }

                // Blank undefined measurementDeterminedBy; $rec is modified before $o is populated below.
                if($class == "mof" || $class == "association") {
                    if($val = @$rec['measurementDeterminedBy']) {
                        if(!isset($this->eol_term_values[$val])) { $this->debug['Undefined measurementDeterminedBy'][$val] = ''; $rec['measurementDeterminedBy'] = ''; }
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