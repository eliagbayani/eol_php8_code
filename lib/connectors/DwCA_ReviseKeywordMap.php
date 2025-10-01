<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from revise_textmine_keyword_map.php] */
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class DwCA_ReviseKeywordMap
{
    function __construct($archive_builder, $resource_id, $archive_path)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        $this->archive_path = $archive_path;
        $this->download_options = array('cache' => 1, 'resource_id' => $resource_id, 'expire_seconds' => 60*60*24*1, 'download_wait_time' => 500000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        $this->debug = array();
    }
    /*================================================================= STARTS HERE ======================================================================*/
    private function initialize()
    {
        require_library('connectors/TextmineKeywordMapAPI');
        $func = new TextmineKeywordMapAPI();
        $func->get_keyword_mappings();
        echo "\nuri_in_question 2: ".count($func->uri_in_question);
        echo "\nnew_keywords 2: ".count($func->new_keywords);
        // exit("\nEli 200\n");
    }
    function start($info)
    {
        self::initialize();
        $tables = $info['harvester']->tables; //print_r($tables); exit;
        $extensions = array_keys($tables); //print_r($extensions); exit;
        /*Array(
            [2] => http://rs.tdwg.org/dwc/terms/occurrence
            [3] => http://rs.tdwg.org/dwc/terms/taxon
            [4] => http://rs.tdwg.org/dwc/terms/measurementorfact
        )*/
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";
        $meta = $tables[$tbl][0];
        self::process_table($meta, 'evaluate_MoF');

        /* writing taxa - copied template
        $tbl = "http://rs.tdwg.org/dwc/terms/taxon";
        $meta = $tables[$tbl][0];
        self::process_table($meta, 'write_archive');
        */
        if($this->debug) Functions::start_print_debug($this->debug, $this->resource_id);
    }
    private function process_table($meta, $what)
    {   //print_r($meta);
        echo "\nprocess_table: [$what] [$meta->file_uri]...\n"; $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) { $i++;
            if(($i % 10000) == 0) echo "\n".number_format($i)." - ";
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                if(!$field['term']) continue;
                $rec[Functions::get_field_from_uri($field['term'])] = $tmp[$k];
                $k++;
            }
            print_r($rec); exit;
            /*Array(
                [http://rs.tdwg.org/dwc/terms/taxonID] => 12
                [http://rs.tdwg.org/ac/terms/furtherInformationURL] => http://reflora.jbrj.gov.br/reflora/listaBrasil/FichaPublicaTaxonUC/FichaPublicaTaxonUC.do?id=FB12
                [http://rs.tdwg.org/dwc/terms/acceptedNameUsageID] => 
                [http://rs.tdwg.org/dwc/terms/parentNameUsageID] => 120181
                [http://rs.tdwg.org/dwc/terms/scientificName] => Agaricales
                [http://rs.tdwg.org/dwc/terms/namePublishedIn] => 
                [http://rs.tdwg.org/dwc/terms/kingdom] => Fungi
                [http://rs.tdwg.org/dwc/terms/phylum] => Basidiomycota
                [http://rs.tdwg.org/dwc/terms/class] => 
                [http://rs.tdwg.org/dwc/terms/order] => Agaricales
                [http://rs.tdwg.org/dwc/terms/family] => 
                [http://rs.tdwg.org/dwc/terms/genus] => 
                [http://rs.tdwg.org/dwc/terms/taxonRank] => order
                [http://rs.tdwg.org/dwc/terms/scientificNameAuthorship] => 
                [http://rs.tdwg.org/dwc/terms/taxonomicStatus] => accepted
                [http://purl.org/dc/terms/modified] => 2018-08-10 11:58:06.954
            )*/

            /* Not recognized fields e.g. WoRMS2EoL.zip
            if(isset($rec['http://purl.org/dc/terms/rights'])) unset($rec['http://purl.org/dc/terms/rights']);
            if(isset($rec['http://purl.org/dc/terms/rightsHolder'])) unset($rec['http://purl.org/dc/terms/rightsHolder']);
            */
            if($what == 'evaluate_MoF') { // print_r($rek); exit;
                if($ret = self::evaluate_MoF($rek)) {}
                {

                }
            }



            if($what == 'write_archive') {
                $o = new \eol_schema\Taxon();
                $uris = array_keys($rec); // print_r($uris); //exit;
                foreach($uris as $uri) {
                    $field = Functions::get_field_from_uri($uri);
                    $o->$field = $rec[$uri];
                }
                $this->archive_builder->write_object_to_file($o);
            }
            if($i >= 5) break;
        }
    }
    private function evaluate_MoF($rek)
    {   /*Array(
            [measurementID] => cf79b546359dea3915383ff3bc583c8c_617_ENV
            [occurrenceID] => bce4fa8b6c08381b674c545409717a17_617_ENV
            [measurementOfTaxon] => true
            [measurementType] => http://purl.obolibrary.org/obo/RO_0002303
            [measurementValue] => http://purl.obolibrary.org/obo/ENVO_00000067
            [measurementRemarks] => source text: "thicket a reed-bed a _cave_ or some other sheltered"
            [source] => http://en.wikipedia.org/w/index.php?title=Lion&oldid=1276469077
        )*/
        
    }
}
?>