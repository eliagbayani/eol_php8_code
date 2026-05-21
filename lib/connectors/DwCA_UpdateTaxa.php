<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from: dwca_update_taxa.php]
Related Workspaces:
*/
class DwCA_UpdateTaxa
{
    function __construct($archive_builder, $resource_id)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        $this->download_options = array('cache' => 1, 'resource_id' => $resource_id, 'expire_seconds' => 60*60*24*30*4, 'download_wait_time' => 1000000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        // $this->download_options['expire_seconds'] = false; //comment after first harvest
        $this->debug = array();
        $this->class_name = 'DwCA_UpdateTaxa';
    }
    function start($info)
    {   echo "\n$this->class_name...\n";
        $tables = $info['harvester']->tables;
        print_r(array_keys($tables));
        
        if($this->resource_id == 'MoftheAES_resources_taxaFixed') { //specific for this resource
            if($meta = @$tables['http://rs.tdwg.org/dwc/terms/taxon'][0]) self::process_extension($meta, 'add_genus_ancestry');
        }
        elseif($this->resource_id == 'NorthAmericanFlora_All_2025_taxaFixed') { //specific for this resource
            if($meta = @$tables['http://rs.tdwg.org/dwc/terms/taxon'][0]) self::process_extension($meta, 'add_genus_ancestry');
        }
        else exit("\nResource ID not initialized [from: $this->class_name][$this->resource_id]\n");
    }
    private function process_extension($meta, $what)
    {   //print_r($meta);
        echo "\nprocess_extension [$what]...$this->class_name...\n"; $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) {
            $i++; if(($i % 100000) == 0) echo "\n".number_format($i);
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                $field['term'] = self::small_field($field['term']);
                // /* some fields have '#', e.g. "http://schemas.talis.com/2005/address/schema#localityName"
                $parts = explode("#", $field['term']);
                if($parts[0]) $field = $parts[0];
                if(@$parts[1]) $field = $parts[1];
                // */
                if(!$field) continue;
                $rec[$field] = $tmp[$k];
                $k++;
            } 
            $rec = array_map('trim', $rec); //print_r($rec); exit;
            //===========================================================================================================================================================
            if($what == 'add_genus_ancestry') { //Eli's initiative
                /* Array(
                    [taxonID] => be2eca213a4df16097242469dd7a99f0
                    [scientificName] => Lyda abdominalis
                )*/
                $arr = explode(" ", Functions::canonical_form($rec['scientificName']));
                if(count($arr) == 2) {
                    $rec['genus'] = $arr[0];
                    $rec['taxonRank'] = 'species';
                }
                elseif(count($arr) == 3) {
                    $rec['genus'] = $arr[0];
                    // $rec['taxonRank'] = 'subspecies'; //may not be true always, thus commented.
                }
                else {
                    // echo "\n[".count($arr)."]"; print_r($rec);
                }
                self::proceed_2write($rec, 'taxon');
            }
            // =======================================================================================================
            // =======================================================================================================
        }
    }
    private function proceed_2write($rec, $class)
    {
        if($class == "taxon")              $o = new \eol_schema\Taxon();
        // elseif($class == "document")    $o = new \eol_schema\MediaResource();        
        // elseif($class == 'MoF')         $o = new \eol_schema\MeasurementOrFact_specific();
        // elseif($class == 'occurrence')  $o = new \eol_schema\Occurrence_specific();
        else exit("\nclass not defined [$class].\n");

        $uris = array_keys($rec); //print_r($uris); exit("\ndito eli\n");
        foreach($uris as $uri) {
            $field = pathinfo($uri, PATHINFO_BASENAME);
            $o->$field = $rec[$uri];
        }
        $this->archive_builder->write_object_to_file($o);
    }
    private function small_field($uri)
    {
        return pathinfo($uri, PATHINFO_FILENAME);
    }
}
?>