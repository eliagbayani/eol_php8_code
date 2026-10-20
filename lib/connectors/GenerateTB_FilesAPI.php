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
        $this->concept_id = $this->get_concept_id_from_resource_id($this->resource_id);
        $this->orig_taxon_input_file = array();
        $this->orig_occurrence_input_file = array();
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

        // step 0:
        $this->orig_taxon_input_file = self::get_orig_input_file($this->concept_id, 'taxon');               //print_r($this->orig_taxon_input_file); exit;
        $this->orig_occurrence_input_file = self::get_orig_input_file($this->concept_id, 'occurrence');     //print_r($this->orig_occurrence_input_file); exit("\n".count($this->orig_occurrence_input_file)."\n");

        // step 0.1: check input files for duplicate IDs (reported on screen and in the debug file)
        if($this->concept_id) {
            $this->check_file_integrity($this->concept_id, 'taxon');
            $this->check_file_integrity($this->concept_id, 'traits');
            if($this->orig_occurrence_input_file) $this->check_file_integrity($this->concept_id, 'occurrence'); //occurrence file is optional
        }

        // step 1: builup json files for all extensions
        $tbl = "http://rs.tdwg.org/dwc/terms/taxon";                if($meta = @$tables[$tbl][0]) $this->with_old_taxonKey_valuesYN = self::process_table($meta, 'do_we_have_old_taxonKey_valuesYN');
        $tbl = "http://rs.tdwg.org/dwc/terms/taxon";                if($meta = @$tables[$tbl][0]) self::process_table($meta, 'buildup_taxon');          //exit("\nstop muna taxon\n");
        $tbl = "http://rs.tdwg.org/dwc/terms/occurrence";           if($meta = @$tables[$tbl][0]) self::process_table($meta, 'buildup_occurrence');     //exit("\nstop muna occurrence\n");
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";    if($meta = @$tables[$tbl][0]) self::process_table($meta, 'buildup_mof');            //exit("\nstop muna mof\n");
        $tbl = "http://eol.org/schema/association";                 if($meta = @$tables[$tbl][0]) self::process_table($meta, 'buildup_association');

        // step 1.1:
        if (!($local = $this->get_generic_file_path($this->concept_id, 'occurrence', false))) { //3rd param is: $processedYN
            echo("\nNo occurrence.tsv\n");
            $this->proceed_write_OccrrencesYN = false;
        }
        else $this->proceed_write_OccrrencesYN = true;
        if($this->proceed_write_OccrrencesYN) self::write_Occurrence_input_file($this->orig_occurrence_input_file);


        // step 2: do the data chain linkup
        $tbl = "http://rs.tdwg.org/dwc/terms/taxon";                if($meta = @$tables[$tbl][0]) self::process_table($meta, 'data_chain_linkup');

        // step 2.1: report occurrences whose taxonID is not in the taxon table -- their MoF records were not written
        self::check_orphan_occurrences();

        if($this->concept_id) { //only resource_type = 'tb_dwca'
            echo "\n\n----------STATS----------[".date('D Y-m-d h:i:s A')."]";
            $row_counts = $this->list_files_row_counts($this->concept_id); //print_r($row_counts);
            echo "\n----------STATS end----------\n";
        }

        // $this->archive_builder->finalize(TRUE); //copied template
        if($this->debug) Functions::start_print_debug($this->debug, $this->concept_id.'_checkErrors', $this->TB_folder);
        unset($this->debug);
        $this->zip_TB_folder($this->resource_id); //e.g. TB_files/22943003_Palm_TraitBank.zip
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
            if($what == 'do_we_have_old_taxonKey_valuesYN') {
                if(@$rec['old_taxonKey']) return true;
            }
            if($what == 'buildup_taxon') self::buildup_taxon($rec);
            if($what == 'buildup_occurrence') self::buildup_occurrence($rec);
            if($what == 'buildup_mof') self::buildup_mof($rec);
            if($what == 'data_chain_linkup') {
                $this->taxon_compiled = self::data_chain_linkup($rec); //print_r($this->taxon_compiled);
                self::write_input_files($this->taxon_compiled, $rec['taxonID']);
            }

            //========================================================================================================= 
            if($what == 'write') { exit("\nERROR: Does it go here at all?\n");
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
    private function data_chain_linkup($rec) //$rec is from DwCA
    {   /*Array(
            [taxonID] => 47138010
            [scientificName] => Plumeria rubra L
            [higherClassification] => Plumeria|
            [genus] => Plumeria
            [taxonRank] => species
            [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Plumeria\|.*?] ] || source_taxonID: [d6b158fbfeaa7914ce528b3c4df341a7]
            [canonicalName] => Plumeria rubra
            [EOLid] => 47138010
            [source_taxonID] => blablabla
        )*/
        $taxonID = $rec['taxonID'];
        $this->linked_taxonIDs[$taxonID] = ''; //for check_orphan_occurrences()
        $taxon_info = self::retrieve_data($taxonID, 'taxon');
        $taxonKey = $taxon_info['taxonKey'];

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

        /* works, but no need to monitor anymore... since it can happen anyway...
        if(count($mof_info) != count($occur_info)) {
            $this->debug['diff totals mof and occur'][$rec['taxonID']][] = $occurrenceID;
            // echo("\nIt happnes: diff totals for mof [".count($mof_info)."] and occurrence [".count($occur_info)."].\n");
        }*/

        $final[$taxonID] = array('taxon' => $taxon_info, 'occurrences' => $occur_info, 'mof' => $mof_info);
        return $final;
    }
    private function check_orphan_occurrences()
    {   // data_chain_linkup() goes through the taxon table, so an occurrence whose taxonID has no taxon row is never reached
        // and its MoF is silently left out of Traits.tsv
        // e.g. 22923616 (Fungi): occurrence taxonID [Caliciella parasitica] but the taxon row has taxonID [Arthonia Ach. s.l.]
        $orphans = 0; $mofs = 0; $orphan_debug = array();
        foreach(@$this->info_taxonID_occurrenceIDs ?: array() as $taxonID => $occurrenceIDs) {
            if(isset($this->linked_taxonIDs[$taxonID])) continue;
            foreach($occurrenceIDs as $occurrenceID) {
                $orphans++;
                $measurementID = '';
                if($mof_json = self::retrieve_data($occurrenceID, 'mof')) {
                    $m = json_decode($mof_json, true);
                    $measurementID = @$m['measurementID'];
                    $mofs++;
                }
                $orphan_debug[$taxonID]["measurementID: " . ($measurementID ?: '(no MoF)')] = '';
            }
        }
        if($orphans) {
            $warning = "WARNING: $orphans case(s) with a taxonID not in the taxon table, $mofs MoF record(s) not written to Traits.tsv.";
            echo "\n$warning See checkErrors.txt.\n";
            $this->debug["orphan cases: taxonID not in taxon table, MoF not written\n$warning"] = $orphan_debug; //2-line heading in the debug file
        }
        else         echo "\nNo orphan cases.\n";
    }
    private function write_input_files($taxon_compiled, $taxonID)
    {
        $arr = $taxon_compiled[$taxonID]; //print_r($arr); //exit("\nelix 4\n");
        // $source_taxonID = self::get_source_taxonID($arr['taxon']['taxonRemarks']); // print_r($arr); exit("\n[$source_taxonID]\nsample chain\n"); --- NOT NEEDED ANYMORE
        
        // $source_taxonID = $arr['taxon']['source_taxonID'];
        // $arr['taxon']['source_taxonID'] = $source_taxonID;

        self::write_Traits_input_file($arr);
        self::write_Taxon_input_file($arr['taxon']);
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
        /*Array( PalmTraits
            [taxonID] => 1133516
            [scientificName] => Acanthophoenix crinita
            [higherClassification] => Arecales|Acanthophoenix|
            [order] => Arecales
            [family] => 
            [genus] => Acanthophoenix
            [taxonRank] => species
            [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Arecales\|.*?] ] || source_taxonID: [Acanthophoenix crinita]
            [canonicalName] => Acanthophoenix crinita
            [EOLid] => 1133516
            [taxonMap] => auto
            [superfamily] => 
            [old_taxonKey] => 
        )*/
        
        if(!$this->with_old_taxonKey_valuesYN) {
            if(isset($rec['old_taxonKey'])) unset($rec['old_taxonKey']);
        }
        
        $taxonID = $rec['taxonID'];

        // /* here is we assign things == dito pala
        $rec['source_taxonID'] = self::get_source_taxonID($rec['taxonRemarks']);
        $rec['taxonID'] = $rec['source_taxonID']; //overwrite
        if(!$rec['EOLid']) {
            $rec['taxonKey'] = '';
            // /* --- new
            if($val = @$rec['old_taxonKey']) { //Eli's initiative
                $rec['taxonKey'] = $val;
                $rec['taxonMap'] = '';
            }
            // --- */
        }
        else $rec['taxonKey'] = $rec['EOLid']; //which is EOLid
        // */

        if(isset($rec['taxonRemarks'])) {
            if($val = $rec['taxonRemarks']) $rec['taxonRemarks'] = self::bringback_orig_taxonRemarks($val);
        }

        if(isset($rec['higherClassification'])) {
            $rec['higherClassification'] = self::bringback_orig_higherClassification($rec['taxonID']);
        }

        $arr = array(); $arr = $rec;
        if(substr($taxonID,0,4) == 'http') {}
        else self::save2json($taxonID, $arr, 'taxon');
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
        
        // $rec = self::bring_back_big3($rec); //taxonKey, infer, exclude --- NO LONGER NEEDED

        $occurrenceID = $rec['occurrenceID'];
        $arr = array();
        $arr = json_encode($rec);
        self::save2json($occurrenceID, $arr, 'mof');        
    }
    private function save2json($id, $arr, $extension)
    {
        $json = json_encode($arr);
        $destination = self::get_json_path($id, $extension, true); // exit("\n[$destination]\n");
        if(!($f = Functions::file_open($destination, "w"))) exit("\nERROR: cannot write to [$destination]\n");
        fwrite($f, $json);
        fclose($f);
    }
    private function retrieve_data($id, $extension)
    {
        $source = self::get_json_path($id, $extension, false); // exit("\n[$source]\n");
        if(file_exists($source)) {
            $json = file_get_contents($source);
            $arr = json_decode($json, true);
            return $arr;
        }
        else {
            // echo "\nxxxxxxxxxx\n"; exit("\ninvestigate 2 [$id] [$extension]\n");
        }
    }
    private function get_json_path($id, $extension, $createYN)
    {   // temp/[extension]/[2 chars]/[md5].json -- md5 of the ID: always ASCII, fixed length, no '/' etc.
        // e.g. 'Kükenthalia' -> temp/taxon/[2 chars of md5]/[md5].json
        // before: temp/taxon/K?/Kükenthalia.json -- substr($id,0,2) cut the 2-byte 'ü' in half -> invalid UTF-8 folder name, mkdir failed
        $md5 = md5((string) $id);
        $dir = $this->TB_folder . "/$this->resource_id/temp/$extension/" . substr($md5, 0, 2);
        if($createYN && !is_dir($dir)) mkdir($dir, 0775, true);
        return "$dir/$md5.json";
    }
    private function bringback_orig_taxonRemarks($str)
    {   //e.g. "abc <{first one}> xyz" -> "first one"
        if(preg_match("/<\{(.*?)\}>/ims", $str, $a)) return $a[1];
    }
    private function bringback_orig_higherClassification($taxonID)
    {
        if($rek = @$this->orig_taxon_input_file[$taxonID]) {
            return @$rek['higherClassification'];
        }       
    }
    private function bring_back_big3($rec) //taxonKey, infer, exclude
    {   //dark period only || {"taxonKey":","infer":"TRUE","exclude":"}
        $mRemarks = $rec['measurementRemarks'];
        $ret = $this->get_taxonKey_infer_exclude($mRemarks); // print_r($ret); exit("\nelix 1\n");
        $rec['infer'] = $ret['infer'];
        $rec['exclude'] = $ret['exclude'];
        return $rec;
    }
}