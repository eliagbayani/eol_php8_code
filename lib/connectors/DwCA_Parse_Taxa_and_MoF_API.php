<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from clients:] 
1st client: treatmentbank_adjust.php
2nd client: xxx.php
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class DwCA_Parse_Taxa_and_MoF_API
{
    function __construct($archive_builder, $resource_id)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        
        // $this->download_options = array('cache' => 1, 'resource_id' => $resource_id, 'expire_seconds' => 60*60*24*30*4, 'download_wait_time' => 1000000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        // $this->download_options['expire_seconds'] = false; //comment after first harvest
    }
    /*================================================================= STARTS HERE ======================================================================*/
    function start($info)
    {   
        require_library('connectors/TraitGeneric'); 
        $this->func = new TraitGeneric($this->resource_id, $this->archive_builder);
        /* START DATA-1841 terms remapping */
        $this->func->initialize_terms_remapping(60*60*24); //param is $expire_seconds. 0 means expire now.
        /* END DATA-1841 terms remapping */
        
        $tables = $info['harvester']->tables;
        self::process_taxon($tables['http://rs.tdwg.org/dwc/terms/taxon'][0], 'info');
        self::process_taxon($tables['http://rs.tdwg.org/dwc/terms/taxon'][0], 'write_taxa');
        
        self::process_occurrence($tables['http://rs.tdwg.org/dwc/terms/occurrence'][0], 'write_occurrence');
        self::process_measurementorfact($tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0], 'write_MoF');
    }
    private function initialize_mapping()
    {   $mappings = Functions::get_eol_defined_uris(false, true);     //1st param: false means will use 1day cache | 2nd param: opposite direction is true
        echo "\n".count($mappings). " - default URIs from EOL registry.";
        $this->uris = Functions::additional_mappings($mappings); //add more mappings used in the past
    }
    private function process_taxon($meta, $what)
    {   //print_r($meta);
        echo "\nprocess_taxon...[$what]\n"; $i = 0;
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
            } //print_r($rec); exit;
            /**/
            //===========================================================================================================================================================
            $taxonID = $rec['http://rs.tdwg.org/dwc/terms/taxonID'];
            $phylum = $rec['http://rs.tdwg.org/dwc/terms/phylum'];
            $class = $rec['http://rs.tdwg.org/dwc/terms/class'];
            $order = $rec['http://rs.tdwg.org/dwc/terms/order'];
            $kingdom = $rec['http://rs.tdwg.org/dwc/terms/kingdom'];

            if($what == 'info') {
            } //end what == 'info'
            //===========================================================================================================================================================
            if($what == 'write_taxa') {
                //-----------------------------------------------------------
                /* start write */
                $o = new \eol_schema\Taxon();
                $uris = array_keys($rec);
                foreach($uris as $uri) {
                    $field = pathinfo($uri, PATHINFO_BASENAME);
                    $o->$field = $rec[$uri];
                }
                $this->archive_builder->write_object_to_file($o);
            } //end what == 'write_taxa'
            //===========================================================================================================================================================

            // if($i >= 10) break; //debug only
        }
    }
    private function process_measurementorfact($meta, $what)
    {   //print_r($meta);
        echo "\nprocess_measurementorfact...[$what]\n"; $i = 0;
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
            } //print_r($rec); exit;
            /**/
            //===========================================================================================================================================================
            $occurrenceID = $rec['http://rs.tdwg.org/dwc/terms/occurrenceID'];
            if(isset($this->occurrenceIDs_to_delete[$occurrenceID])) continue;
            
            $o = new \eol_schema\MeasurementOrFact_specific();
            $uris = array_keys($rec);
            foreach($uris as $uri) {
                $field = pathinfo($uri, PATHINFO_BASENAME);
                $o->$field = $rec[$uri];
            }
    
            // if($i >= 10) break; //debug only
        }
    }
    private function process_occurrence($meta, $what)
    {   //print_r($meta);
        echo "\nprocess_occurrence...[$what]\n"; $i = 0;
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
            // print_r($rec); exit("\ndebug...\n");
            /**/
            
            $taxonID      = $rec['http://rs.tdwg.org/dwc/terms/taxonID'];
            $occurrenceID = $rec['http://rs.tdwg.org/dwc/terms/occurrenceID'];
                        
            $uris = array_keys($rec);
            $o = new \eol_schema\Occurrence_specific();
            foreach($uris as $uri) {
                $field = pathinfo($uri, PATHINFO_BASENAME);
                $o->$field = $rec[$uri];
            }
            $this->archive_builder->write_object_to_file($o);
            // if($i >= 10) break; //debug only
        }
    }
    /*================================================================= ENDS HERE ======================================================================*/
}
?>