<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from use_EOLid_as_taxonID.php] 
These ff. workspaces work together:
- generate_higherClassification_8.code-workspace
- DHConnLib_8.code-workspace
- GNParserAPI_8.code-workspace
- DwCA_MatchTaxa2DH.code-workspace
- UseEOLidInTaxon.code-workspace
- GenerateCSV_4Neo4j.code-workspace

If EOLid exists.
This lib. will now use EOLid for: taxon->taxonID
                                  vernacular->taxonID  
                                  media->taxonID
                                  occurrence->taxonID
It will delete/exclude those taxon, vernaculars, media, occurrence and MoF without the EOLid.
*It can also assign DH taxonRank and canonicalName to resource document, but not anymore.
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class DwCA_UseEOLidInTaxa
{
    function __construct($archive_builder, $resource_id, $archive_path)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        $this->archive_path = $archive_path;
        $this->debug = array();
        $this->download_options = array('cache' => 1, 'resource_id' => 'neo4j', 'expire_seconds' => 60*60*24, 'download_wait_time' => 1000000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        $this->urls['preferred_vernaculars'] = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/english_preferred_vernaculars_by_page.tsv';
        $this->debug['Duplicate taxonIDs']['First taxonID is not displayed'] = ''; //remark for print_debug()
    }
    /*================================================================= STARTS HERE ======================================================================*/
    function start($info)
    {
        self::lookup_JRice_vernacular_list();
        // print_r($this->taxonID_vernacular); exit("\n".count($this->taxonID_vernacular)."\n"); //as of 9Aug2025 n=239,514

        // /* Read the DwCA in question:
        $tables = $info['harvester']->tables; // print_r($tables); exit;
        $extensions = array_keys($tables); print_r($extensions);
        // */

        if($meta = @$tables['http://rs.tdwg.org/dwc/terms/taxon'][0]) {
            self::process_table($meta, 'build_taxon_info');
            /* working but may not be needed anymore
            self::get_DH_info_4EOLids();
            */
            self::process_table($meta, 'write_taxon');        
        }
        else exit("\nERROR: Cannot proceed without Taxon extension.\n");

        if($meta = @$tables['http://rs.tdwg.org/dwc/terms/occurrence'][0]) {
            self::process_table($meta, 'write_occurrence');
        }
        else exit("\nERROR: Cannot proceed without Occurrence extension.\n");

        if($meta = @$tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0]) {
            self::process_table($meta, 'write_MoF');
        }
        if($meta = @$tables['http://eol.org/schema/association'][0]) {
            self::process_table($meta, 'write_Association');
        }
        if($meta = @$tables['http://rs.gbif.org/terms/1.0/vernacularname'][0]) {
            self::process_table($meta, 'write_other_extensions', 'vernacular');
        }
        if($meta = $tables['http://eol.org/schema/media/document'][0]) {
            self::process_table($meta, 'write_other_extensions', 'document');
        }
        if($this->debug) Functions::start_print_debug($this->debug, $this->resource_id); //works OK
    }
    private function get_DH_info_4EOLids()
    {
        require_library('connectors/DHConnLib');
        $func = new DHConnLib(1);
        $this->DH_subset_info = $func->grab_from_DH('get_DH_info_forEOLids', $this->EOLids);
        unset($func);
        // print_r($this->DH_subset_info); exit("\nstop muna 1\n");
        echo "\nDH_subset_info: ".count($this->DH_subset_info)."\n";
    }
    private function process_table($meta, $what, $class = false) //3rd param $class is optional
    { 
        echo "\nprocess_table: [$what] [$meta->file_uri]...\n";
        $i = 0;
        foreach (new FileIterator($meta->file_uri) as $line => $row) {
            $i++;
            if (($i % 500000) == 0) echo "\n" . number_format($i) . " - "; //10k orig
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
            } //print_r($rec); exit;
            /*Array(
                [http://rs.tdwg.org/dwc/terms/taxonID] => COL:74YCG
                [http://rs.tdwg.org/ac/terms/furtherInformationURL] => https://www.catalogueoflife.org/data/taxon/74YCG
                [http://eol.org/schema/reference/referenceID] => 
                [http://rs.tdwg.org/dwc/terms/parentNameUsageID] => 
                [http://rs.tdwg.org/dwc/terms/scientificName] => Orthosia pacifica
                [http://rs.tdwg.org/dwc/terms/namePublishedIn] => 
                [http://rs.tdwg.org/dwc/terms/higherClassification] => Animalia|Arthropoda|Insecta|Lepidoptera|Noctuidae|Orthosia|
                [http://rs.tdwg.org/dwc/terms/kingdom] => Animalia
                [http://rs.tdwg.org/dwc/terms/phylum] => Arthropoda
                [http://rs.tdwg.org/dwc/terms/class] => Insecta
                [http://rs.tdwg.org/dwc/terms/order] => Lepidoptera
                [http://rs.tdwg.org/dwc/terms/family] => Noctuidae
                [http://rs.tdwg.org/dwc/terms/genus] => Orthosia
                [http://rs.tdwg.org/dwc/terms/taxonRank] => species
                [http://rs.tdwg.org/dwc/terms/taxonomicStatus] => 
                [http://rs.tdwg.org/dwc/terms/taxonRemarks] => 
                [http://rs.gbif.org/terms/1.0/canonicalName] => Orthosia pacifica
                [http://eol.org/schema/EOLid] => 465299
            )*/
            /*
            $rec = self::not_recongized_fields($rec);
            $this->rec = $rec;
            */
            if(!in_array($what, array('write_MoF', 'write_Association'))) $taxonID = $rec['http://rs.tdwg.org/dwc/terms/taxonID'];
            if ($what == 'build_taxon_info') {
                if($EOLid = $rec['http://eol.org/schema/EOLid']) $this->taxonID_EOLid[$taxonID] = $EOLid;
                else $this->taxonID_EOLid[$taxonID] = false;
                /* new step: Assign DH taxonRank and canonicalName to resource document. Working but may not be needed anymore.
                if($EOLid) $this->EOLids[$EOLid] = '';
                */
            }
            if($what == 'write_taxon') {
                if($new_taxonID = $this->taxonID_EOLid[$taxonID]) { //there is EOLid for this taxon
                    if(isset($this->unique_taxonID[$new_taxonID])) {
                        @$this->debug['Duplicate taxonIDs'][$new_taxonID] .= '_'.$taxonID;
                        continue; //prevented duplicate taxonIDs, which is possible e.g. Globi DwCA.
                    }
                    else {
                        $this->unique_taxonID[$new_taxonID] = '';
                        $rec['http://rs.tdwg.org/dwc/terms/taxonID'] = $new_taxonID; //is the EOLid

                        /* working but may not be used anymore
                        $rec['http://rs.tdwg.org/dwc/terms/vernacularName'] = self::get_vernacularName($new_taxonID);
                        */

                        /* working but may not be used anymore
                        $rec = self::assign_DH_rank_and_canonical_to_resource_taxon($rec, $new_taxonID); //new assign DH taxonRank and canonicalName to resource taxon
                        */
                    }
                }
                else continue; //this will drastically lessen our taxon count
                if($parentNameUsageID = @$rec['http://rs.tdwg.org/dwc/terms/parentNameUsageID']) {
                    if($new_taxonID = @$this->taxonID_EOLid[$parentNameUsageID]) $rec['http://rs.tdwg.org/dwc/terms/parentNameUsageID'] = $new_taxonID;
                    else $rec['http://rs.tdwg.org/dwc/terms/parentNameUsageID'] = ''; //since there is no EOLid for this parentID, we set it to blank.
                }
                self::write_2archive($rec, 'taxon'); continue;                
            }
            if ($what == 'write_occurrence') {
                if($new_taxonID = @$this->taxonID_EOLid[$taxonID]) $rec['http://rs.tdwg.org/dwc/terms/taxonID'] = $new_taxonID;
                else {
                    @$this->debug['Taxa in Occur.tab has no EOLid'][$taxonID] = '';
                    $this->occurrenceID_to_delete[$rec['http://rs.tdwg.org/dwc/terms/occurrenceID']] = '';
                    continue; //don't save
                }
                self::write_2archive($rec, 'occurrence_specific'); continue;
            }
            if($what == 'write_MoF') {
                $occurrenceID = $rec['http://rs.tdwg.org/dwc/terms/occurrenceID'];
                if(isset($this->occurrenceID_to_delete[$occurrenceID])) continue;
                else {
                    self::write_2archive($rec, 'measurementorfact'); continue;                    
                }
            }
            if($what == 'write_Association') {
                $occurrenceID = $rec['http://rs.tdwg.org/dwc/terms/occurrenceID'];
                $targetOccurrenceID = $rec['http://eol.org/schema/targetOccurrenceID'];
                if(isset($this->occurrenceID_to_delete[$occurrenceID])) continue;
                elseif(isset($this->occurrenceID_to_delete[$targetOccurrenceID])) continue;
                else {
                    self::write_2archive($rec, 'association'); continue;                    
                }
            }

            if ($what == 'write_other_extensions') {
                if($class == 'document') {
                    if($rec['http://purl.org/dc/terms/type'] == 'http://purl.org/dc/dcmitype/Text') {
                        if($new_taxonID = @$this->taxonID_EOLid[$taxonID]) $rec['http://rs.tdwg.org/dwc/terms/taxonID'] = $new_taxonID;
                        else {
                            @$this->debug["Taxa in $class .tab has no EOLid"][$taxonID] = '';                            
                            continue; //don't save
                        }
                    }
                    else continue; //non-text is excluded as well
                }
                elseif($class == 'vernacular') {
                    if($new_taxonID = @$this->taxonID_EOLid[$taxonID]) $rec['http://rs.tdwg.org/dwc/terms/taxonID'] = $new_taxonID;
                    else {
                        @$this->debug["Taxa in $class .tab has no EOLid"][$taxonID] = '';
                        continue; //don't save
                    }
                }
                self::write_2archive($rec, $class); continue;
            }
            // if($i >= 100) break; //dev only
        }
    }
    private function assign_DH_rank_and_canonical_to_resource_taxon($rec, $new_taxonID)
    {
        $taxonRank = $rec['http://rs.tdwg.org/dwc/terms/taxonRank'];
        $canonicalName = $rec['http://rs.gbif.org/terms/1.0/canonicalName'];
        if($ret = @$this->DH_subset_info[$new_taxonID]) { //print_r($ret); exit;
            /*Array(
                [r] => kingdom
                [c] => Metazoa
            )*/
            if($taxonRank != $ret['r'])     $rec['http://rs.tdwg.org/dwc/terms/taxonRank']     = $ret['r']; //gets the value from DH
            if($canonicalName != $ret['c']) $rec['http://rs.gbif.org/terms/1.0/canonicalName'] = $ret['c']; //gets the value from DH
            // Below is just for stats. Let us capture it if needed.
            if($taxonRank != $ret['r']) {
                // print_r($rec); exit("\nPartner taxonRank is different from DH taxonRank (".$ret['r'].")\n");
                $this->debug['Partner taxonRank is different from DH taxonRank']["($taxonRank)(".$ret['r'].")"] = '';
            }
            if($canonicalName != $ret['c']) {
                // print_r($rec); exit("\nPartner canonicalName is different from DH canonicalName (".$ret['c'].")\n");
                $this->debug['Partner canonicalName is different from DH canonicalName']["($canonicalName)(".$ret['c'].")"] = '';
            }
        }
    }
    private function write_2archive($rec, $class)
    {
        $o = self::get_eol_schema($class);
        $uris = array_keys($rec);
        foreach ($uris as $uri) {
            $field = self::get_field_from_uri($uri);
            $o->$field = $rec[$uri];
        }
        $this->archive_builder->write_object_to_file($o);
    }
    private function get_eol_schema($class)
    {
        if($class == "taxon")                   $c = new \eol_schema\Taxon();
        elseif($class == "occurrence")          $c = new \eol_schema\Occurrence();
        elseif($class == "occurrence_specific") $c = new \eol_schema\Occurrence_specific();
        elseif($class == "vernacular")          $c = new \eol_schema\VernacularName();
        elseif($class == "document")             $c = new \eol_schema\MediaResource();
        elseif($class == "measurementorfact")    $c = new \eol_schema\MeasurementOrFact();
        elseif($class == "association")          $c = new \eol_schema\Association();
        /* not used here
        elseif($class == "agent")                $c = new \eol_schema\Agent();
        elseif($class == "reference")            $c = new \eol_schema\Reference();
        */
        else exit("\nUndefined class [$class]. Will terminate*.\n");
        return $c;
    }
    private function get_field_from_uri($uri)
    {
        $field = pathinfo($uri, PATHINFO_BASENAME);
        $parts = explode("#", $field);
        if ($parts[0]) $field = $parts[0];
        if (@$parts[1]) $field = $parts[1];
        return $field;
    }
    private function lookup_JRice_vernacular_list()
    {
        $local_tsv = Functions::save_remote_file_to_local($this->urls['preferred_vernaculars'], $this->download_options);
        $i = 0;
        foreach(new FileIterator($local_tsv) as $line => $row) { $i++;
            $row = Functions::conv_to_utf8($row); 
            if($i == 1) $fields = explode("\t", $row);
            else {
                if(!$row) continue;
                $tmp = explode("\t", $row);
                $rec = array(); $k = 0;
                foreach($fields as $field) {
                    $rec[$field] = $tmp[$k];
                    $k++;
                }
                $rec = array_map('trim', $rec); //print_r($rec); exit;
                /*Array(
                    [EOLid] => 328090
                    [vernacularName] => Brown Palm Civet
                )*/
                if(is_numeric($rec['EOLid'])) {
                    if(self::valid_vernacular($rec['vernacularName'])) {
                        $this->taxonID_vernacular[$rec['EOLid']] = $rec['vernacularName'];
                    }
                    else exit("\nInvalid vernacularName [".$rec['vernacularName']."]\n");
                }
                else exit("\nInvalid EOLid [".$rec['EOLid']."]\n");
            }
        }
        unlink($local_tsv);
    }
    private function get_vernacularName($eol_id)
    {
        if($val = @$this->taxonID_vernacular[$eol_id]) {
            if($val == '""') return "";
            else return $val;
        }
        return "";
    }
    private function valid_vernacular($vernacular)
    {
        $count = substr_count($vernacular, '"');
        if(!self::isEven($count)) {
            exit("\nCheck vernacular [".$vernacular."]\n");
        }
        return true;
    }
    private function isEven($num) {
        return ($num % 2 == 0);
    }
}