<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from resource_utility.php 
1st client is WoRMS: 
*/
class Remove_MoF_WithoutTaxa
{
    function __construct($resource_id, $archive_builder)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
    }
    /*================================================================= STARTS HERE ======================================================================*/
    function start($info)
    {
        $tables = $info['harvester']->tables; // print_r($tables); exit("\ncha1\n");        
        self::process_generic_table($tables['http://rs.tdwg.org/dwc/terms/taxon'][0], 'taxa_info_list');
        self::process_generic_table($tables['http://rs.tdwg.org/dwc/terms/occurrence'][0], 'occurrence_info_list_and_write');
        self::process_generic_table($tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0], 'MoF_write');
    }
    private function process_generic_table($meta, $what)
    {
        echo "\nclass: Remove_MoF_WithoutTaxa: process $what...\n"; $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) {
            $i++; if(($i % 100000) == 0) echo "\n".number_format($i);
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                if(!$field['term']) continue;
                $rec[$field['term']] = $tmp[$k];
                $k++;
            }
            $rec= array_map('trim', $rec);
            // print_r($rec); exit("\nelix 1\n");
            if($what == 'taxa_info_list') {
                /*Array(
                    [http://rs.tdwg.org/dwc/terms/taxonID] => 1
                    [http://purl.org/dc/terms/source] => http://www.marinespecies.org/aphia.php?p=taxdetails&id=1
                    [http://eol.org/schema/reference/referenceID] => WoRMS:citation:1
                    [http://rs.tdwg.org/dwc/terms/acceptedNameUsageID] => 
                    [http://rs.tdwg.org/dwc/terms/scientificName] => Biota
                    [http://rs.tdwg.org/dwc/terms/namePublishedIn] => 
                    [http://rs.tdwg.org/dwc/terms/kingdom] => 
                    [http://rs.tdwg.org/dwc/terms/phylum] => 
                    [http://rs.tdwg.org/dwc/terms/class] => 
                    [http://rs.tdwg.org/dwc/terms/order] => 
                    [http://rs.tdwg.org/dwc/terms/family] => 
                    [http://rs.tdwg.org/dwc/terms/genus] => 
                    [http://rs.tdwg.org/dwc/terms/taxonRank] => kingdom
                    [http://rs.tdwg.org/dwc/terms/taxonomicStatus] => accepted
                    [http://rs.tdwg.org/dwc/terms/taxonRemarks] => 
                    [http://purl.org/dc/terms/rightsHolder] => WoRMS Editorial Board
                )*/
                $this->taxonIDs[$rec['http://rs.tdwg.org/dwc/terms/taxonID']] = '';
            }
            elseif($what == 'occurrence_info_list_and_write') {
                /*Array(
                    [http://rs.tdwg.org/dwc/terms/occurrenceID] => 0191a5b6bbee617be3f101758872e911_26
                    [http://rs.tdwg.org/dwc/terms/taxonID] => 1054700
                )*/
                $taxonID = $rec['http://rs.tdwg.org/dwc/terms/taxonID'];
                $occurrenceID = $rec['http://rs.tdwg.org/dwc/terms/occurrenceID'];
                if(isset($this->taxonIDs[$taxonID])) { //proceed to write
                    $o = new \eol_schema\Occurrence_specific();
                    $uris = array_keys($rec);
                    foreach($uris as $uri) {
                        $field = pathinfo($uri, PATHINFO_BASENAME);
                        $o->$field = $rec[$uri];
                    }
                    $this->archive_builder->write_object_to_file($o);
                }
                else $this->delete_occurrenceIDs[$occurrenceID] = '';
            }
            elseif($what == 'MoF_write') {
                /*Array(
                    [http://rs.tdwg.org/dwc/terms/measurementID] => 286376_1054700
                    [http://rs.tdwg.org/dwc/terms/occurrenceID] => 0191a5b6bbee617be3f101758872e911_26
                    [http://eol.org/schema/measurementOfTaxon] => true
                    [http://rs.tdwg.org/dwc/terms/measurementType] => http://rs.tdwg.org/dwc/terms/habitat
                    [http://rs.tdwg.org/dwc/terms/measurementValue] => http://purl.obolibrary.org/obo/ENVO_01000024
                    [http://rs.tdwg.org/dwc/terms/measurementDeterminedDate] => 
                    [http://rs.tdwg.org/dwc/terms/measurementDeterminedBy] => 
                    [http://rs.tdwg.org/dwc/terms/measurementMethod] => inherited from urn:lsid:marinespecies.org:taxname:101, Gastropoda Cuvier, 1795
                    [http://rs.tdwg.org/dwc/terms/measurementRemarks] => 
                    [http://purl.org/dc/terms/source] => http://www.marinespecies.org/aphia.php?p=taxdetails&id=1054700
                    [http://purl.org/dc/terms/contributor] => 
                    [http://eol.org/schema/reference/referenceID] => 
                )*/
                $occurrenceID = $rec['http://rs.tdwg.org/dwc/terms/occurrenceID'];
                if(!isset($this->delete_occurrenceIDs[$occurrenceID])) {
                    $o = new \eol_schema\MeasurementOrFact_specific();
                    $uris = array_keys($rec);
                    foreach($uris as $uri) {
                        $field = pathinfo($uri, PATHINFO_BASENAME);
                        $o->$field = $rec[$uri];
                    }
                    $this->archive_builder->write_object_to_file($o);
                }
            }
            else exit("\nInvestigate [$what]\n");            
            // if($i >= 10) break; //debug only
        } //end foreach()
    }
    /*================================================================= ENDS HERE ======================================================================*/
}
?>