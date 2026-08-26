<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from resource_utility.php 
1st client is: eBirdClementsV69.tar.gz
Copied template from Change_measurementIDs.php
*/
class Add_measurementIDs
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
        self::process_generic_table($tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0], 'generate_measurementID');
    }
    private function process_generic_table($meta, $what)
    {   //print_r($meta);
        echo "\nclass: [$what]\n";
        echo "\nprocess $what...\n"; $i = 0;
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
            $rec= array_map('trim', $rec); //print_r($rec); exit;
            /*Array(
                [http://rs.tdwg.org/dwc/terms/occurrenceID] => struthio_camelus_es
                [http://eol.org/schema/measurementOfTaxon] => true
                [http://rs.tdwg.org/dwc/terms/measurementType] => http://eol.org/schema/terms/ExtinctionStatus
                [http://rs.tdwg.org/dwc/terms/measurementValue] => http://eol.org/schema/terms/extant
                [http://purl.org/dc/terms/contributor] => The Cornell Lab of Ornithology: Clements Checklist
                [http://eol.org/schema/reference/referenceID] => f7919906c81c53ef18448c5446697153
            )*/
            if($what == 'generate_measurementID') {
                $o = new \eol_schema\MeasurementOrFact_specific();
                $uris = array_keys($rec);
                foreach($uris as $uri) {
                    $field = pathinfo($uri, PATHINFO_BASENAME);
                    $o->$field = $rec[$uri];
                }
                if(!isset($o->measurementID)) $o->measurementID = Functions::generate_measurementID($o, $this->resource_id);
                $this->archive_builder->write_object_to_file($o);
            }
            /* from copied template
            elseif($what == 'vernacular') {
                if(isset($this->children_of_Aves[$rec['http://rs.tdwg.org/dwc/terms/taxonID']])) continue;
                $o = new \eol_schema\VernacularName();
            }
            elseif($what == 'occurrence') {
                if(isset($this->children_of_Aves[$rec['http://rs.tdwg.org/dwc/terms/taxonID']])) {
                    $this->remove_occurrence_id[$rec['http://rs.tdwg.org/dwc/terms/occurrenceID']] = '';
                    continue;
                }
                if(isset($this->Ostracoda_remove_occurrence_id[$rec['http://rs.tdwg.org/dwc/terms/occurrenceID']])) continue;
                $o = new \eol_schema\Occurrence();
            }
            elseif($what == 'MoF') {
                if(isset($this->remove_occurrence_id[$rec['http://rs.tdwg.org/dwc/terms/occurrenceID']])) continue;
                if(isset($this->Ostracoda_remove_occurrence_id[$rec['http://rs.tdwg.org/dwc/terms/occurrenceID']])) continue;
                $o = new \eol_schema\MeasurementOrFact_specific();
            }
            */
            else exit("\nInvestigate [$what]\n");            
            // if($i >= 10) break; //debug only
        }
    }
    /*================================================================= ENDS HERE ======================================================================*/
}
?>