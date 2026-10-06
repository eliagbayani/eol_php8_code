<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from generate_TB_files.php] 
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class GenerateTB_FilesAPI extends GenerateTB_Files_Functions
{
    function __construct($archive_builder, $resource_id, $archive_path)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        $this->archive_path = $archive_path;
        $this->download_options = array('cache' => 1, 'resource_id' => $resource_id, 'expire_seconds' => 60*60*24*1, 'download_wait_time' => 500000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        $this->debug = array();
        $temp = CONTENT_RESOURCE_LOCAL_PATH . 'TB_files'; if(!is_dir($temp)) mkdir($temp);
        $this->TB_folder = $temp;
    }
    /*================================================================= STARTS HERE ======================================================================*/
    private function initial()
    {
        $dir = $this->TB_folder . "/$this->resource_id"; 
        if(!is_dir($dir)) mkdir($dir);
        else {
            recursive_rmdir($dir); echo "\n remove: [$dir]\n";
            mkdir($dir);           echo "\n make: [$dir]\n";
        }
        $extensions = array('taxon', 'mof', 'occurrence', 'association');
        mkdir("$dir/temp/");
        foreach($extensions as $extension) mkdir("$dir/temp/$extension");
        mkdir("$dir/input_files/");
    }
    function start($info)
    {   
        self::initial(); //exit("\ncheck initial...\n");
        // /* Read the DwCA in question:
        $tables = $info['harvester']->tables; // print_r($tables); exit;
        $extensions = array_keys($tables); print_r($extensions); //exit;

        // step 1: builup json files for all extensions
        $tbl = "http://rs.tdwg.org/dwc/terms/taxon";                if($meta = @$tables[$tbl][0]) self::process_table($meta, 'buildup_taxon');          //exit("\nstop muna taxon\n");        
        $tbl = "http://rs.tdwg.org/dwc/terms/occurrence";           if($meta = @$tables[$tbl][0]) self::process_table($meta, 'buildup_occurrence');     //exit("\nstop muna occurrence\n");
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";    if($meta = @$tables[$tbl][0]) self::process_table($meta, 'buildup_mof');            //exit("\nstop muna mof\n");
        $tbl = "http://eol.org/schema/association";                 if($meta = @$tables[$tbl][0]) self::process_table($meta, 'buildup_association');
        // step 2: do the data chain linkup
        $tbl = "http://rs.tdwg.org/dwc/terms/taxon";                if($meta = @$tables[$tbl][0]) self::process_table($meta, 'data_chain_linkup'); 

        if($concept_id = $this->get_concept_id_from_resource_id($this->resource_id)) { //only resource_type = 'tb_dwca'
            $row_counts = $this->list_files_row_counts($concept_id); //print_r($row_counts);
        }

        // $this->archive_builder->finalize(TRUE); //copied template
        if($this->debug) Functions::start_print_debug($this->debug, $this->resource_id, $this->TB_folder);
        unset($this->debug);
    }
    private function process_table($meta, $what, $class = false)
    {   echo "\nprocess_table TB: [$what] [$meta->file_uri]...\n"; $i = 0;
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
            /**/
            //========================================================================================================= 
            if($what == 'buildup_taxon') self::buildup_taxon($rec);
            if($what == 'buildup_occurrence') self::buildup_occurrence($rec);
            if($what == 'buildup_mof') self::buildup_mof($rec);
            if($what == 'data_chain_linkup') {
                $this->taxon_compiled = self::data_chain_linkup($rec); //print_r($this->taxon_compiled);
                self::write_input_files($this->taxon_compiled, $rec['taxonID']);
            }

            //========================================================================================================= 
            if($what == 'write') {
                $uris = array_keys($rec);            
                    if($class == "occurrence")      $o = new \eol_schema\Occurrence_specific();
                elseif($class == "mof")             $o = new \eol_schema\MeasurementOrFact_specific();
                elseif($class == "association")     $o = new \eol_schema\Association();
                else exit("\nUndefined class [$class]. Will terminate.\n");                
                foreach($uris as $uri) {
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
    private function data_chain_linkup($rec)
    {   /*Array(
            [taxonID] => 47138010
            [scientificName] => Plumeria rubra L
            [higherClassification] => Plumeria|
            [genus] => Plumeria
            [taxonRank] => species
            [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Plumeria\|.*?] ] || source_taxonID: [d6b158fbfeaa7914ce528b3c4df341a7]
            [canonicalName] => Plumeria rubra
            [EOLid] => 47138010
        )*/
        $taxonID = $rec['taxonID'];
        $taxon_info = self::retrieve_data($taxonID, 'taxon');

        // /* occurrences
        $occur_info = array();
        if($occurrenceIDs = @$this->info_taxonID_occurrenceIDs[$taxonID]) { //print_r($occurrenceIDs);
            foreach($occurrenceIDs as $occurrenceID) {
                $occur_json = self::retrieve_data($occurrenceID, 'occurrence');
                $occur_info[$occurrenceID] = json_decode($occur_json, true);
            }
        }
        // */
        // /* mof
        $mof_info = array();
        if($occurrenceIDs = @$this->info_taxonID_occurrenceIDs[$taxonID]) { //print_r($occurrenceIDs);
            foreach($occurrenceIDs as $occurrenceID) {
                if($mof_json = self::retrieve_data($occurrenceID, 'mof')) $mof_info[] = json_decode($mof_json, true);
            }
        }
        // */

        if(count($mof_info) != count($occur_info)) {
            $this->debug['diff totals mof and occur'][$rec['taxonID']][] = $occurrenceID;
            // echo("\nIt happnes: diff totals for mof [".count($mof_info)."] and occurrence [".count($occur_info)."].\n");
        }

        $final[$taxonID] = array('taxon' => $taxon_info, 'occurrences' => $occur_info, 'mof' => $mof_info);
        return $final;
    }
    private function write_input_files($taxon_compiled, $taxonID)
    {
        $arr = $taxon_compiled[$taxonID]; //print_r($arr); exit("\nelix 4\n");
        $source_taxonID = self::get_source_taxonID($arr['taxon']['taxonRemarks']); // print_r($arr); exit("\n[$source_taxonID]\nsample chain\n");
        $arr['taxon']['source_taxonID'] = $source_taxonID;
        self::write_Traits_input_file($arr);
        self::write_Taxon_input_file($arr['taxon']);
        self::write_Occurrence_input_file($arr['occurrences']);
    }
    private function buildup_taxon($rec)
    {   /*Array( 23067562_Bioc_and_Natu_Prod
        [taxonID] => 484975
        [scientificName] => Ilex paraguariensis
        [higherClassification] => Archaeplastida|Aquifoliaceae|Ilex|
        [kingdom] => Archaeplastida
        [phylum] => 
        [class] => 
        [family] => Aquifoliaceae
        [genus] => Ilex
        [taxonRank] => species
        [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Aquifoliaceae\|.*?] ] || source_taxonID: [Ilex paraguariensis]
        [canonicalName] => Ilex paraguariensis
        [EOLid] => 484975
        [taxonMap] => auto
        [infer] => 
        [exclude] => 
        )*/
        $taxonID = $rec['taxonID'];
        $arr = array();
        $arr = $rec;
        self::save2json($taxonID, $arr, 'taxon');
    }
    private function buildup_occurrence($rec)
    {   /*Array(
            [occurrenceID] => 0602fb1aabecdfa65eb898018a7fef2e_10088_6943_ENV
            [taxonID] => 47138010
        )Array( 23067562_Bioc_and_Natu_Prod
            [occurrenceID] => bc20e2056e6f09966034c436d65c5589
            [taxonID] => 1495
        )*/
        $taxonID = $rec['taxonID'];
        $occurrenceID = $rec['occurrenceID'];
        $arr_occur = array();
        $arr_occur = json_encode($rec);
        self::save2json($occurrenceID, $arr_occur, 'occurrence');
        $this->info_taxonID_occurrenceIDs[$taxonID][] = $occurrenceID;
    }
    private function buildup_mof($rec)
    {   /*Array(
            [measurementID] => 36ddcab4209404ea8e04ab43387d04b8_10088_6943_ENV
            [occurrenceID] => 0602fb1aabecdfa65eb898018a7fef2e_10088_6943_ENV
            [measurementOfTaxon] => true
            [measurementType] => http://eol.org/schema/terms/Present
            [measurementValue] => http://www.geonames.org/7729901
            [measurementRemarks] => source text: "but erroneously reported from _Polynesia_ has narrow 0.5–1.5 cm"
            [source] => http://dx.doi.org/10.5479/si.0081024X.17
            [bibliographicCitation] => Grant, Martin Lawrence, Fosberg, F. Raymond, and Smith, Howard M. 1974. "Partial Flora of the Society Islands: Ericaceae to Apocynaceae." Smithsonian Contributions to Botany. 1-85. https://doi.org/10.5479/si.0081024X.17
        )Array( 23067562_Bioc_and_Natu_Prod
            [measurementID] => toxins1
            [occurrenceID] => bc20e2056e6f09966034c436d65c5589
            [measurementType] => https://www.wikidata.org/entity/Q3386847
            [measurementValue] => http://purl.obolibrary.org/obo/OMIT_0027854
            [measurementUnit] => 
            [measurementRemarks] => 
            [source] => https://doi.org/10.1093/molbev/mst199
            [referenceID] => 
        */
        $occurrenceID = $rec['occurrenceID'];
        $arr = array();
        $arr = json_encode($rec);
        self::save2json($occurrenceID, $arr, 'mof');        
    }
    private function save2json($id, $arr, $extension)
    {
        $json = json_encode($arr);
        $destination = $this->TB_folder . "/$this->resource_id/temp/$extension/"; 
        $path = self::generate_path_then_create($id, $destination);
        $destination .= "$path/$id.json"; // exit("\n[$destination]\n");
        if(!($f = Functions::file_open($destination, "w"))) exit("\nERROR: cannot write to [$destination]\n");
        fwrite($f, $json);
        fclose($f);
    }
    private function retrieve_data($id, $extension)
    {
        $source = $this->TB_folder . "/$this->resource_id/temp/$extension/";
        $path = self::generate_path_then_create($id, $source);
        $source .= "$path/$id.json"; // exit("\n[$source]\n");
        if(file_exists($source)) {
            $json = file_get_contents($source);
            $arr = json_decode($json, true);
            return $arr;
        }
        else {
            // echo "\nxxxxxxxxxx\n"; exit("\ninvestigate 2 [$id] [$extension]\n");
        }
    }
    private function generate_path_then_create($id, $path)
    {
        $length = 2; //given 4 returns 04
        $str = str_pad($id, $length, "0", STR_PAD_LEFT);
        $subfolder = substr($str,0,2);
        $dir = "$path/$subfolder";
        if(!is_dir($dir)) mkdir($dir);
        return "".$subfolder."";
    }
}