<?php
namespace php_active_record;
/* connector: [760]
original: DATA-1426 Scrape invasive species data from GISD & CABI ISC
latest ticket: https://eol-jira.bibalex.org/browse/TRAM-794
*/

class InvasiveSpeciesCompendiumAPI
{
    function __construct($folder)
    {
        $this->resource_id = $folder;
        $this->taxa = array();
        $this->path_to_archive_directory = CONTENT_RESOURCE_LOCAL_PATH . '/' . $folder . '_working/';
        $this->archive_builder = new \eol_schema\ContentArchiveBuilder(array('directory_path' => $this->path_to_archive_directory));
        $this->occurrence_ids = array();
        $this->taxon_ids = array();
        $this->download_options = array('resource_id' => $folder, 'download_wait_time' => 1000000, 'timeout' => 60*2, 'download_attempts' => 1, 'cache' => 1); // 'expire_seconds' => 0
        $this->download_options['expire_seconds'] = false;
        $this->debug = array();
        
        $this->taxa_list['ISC'] = LOCAL_HOST."/cp_new/Invasive%20Species%20Compendium/ExportedRecords.csv";
        $this->taxa_list['ISC'] = "https://github.com/eliagbayani/EOL-connector-data-files/raw/master/Invasive%20Species%20Compendium/ExportedRecords.csv";
        $this->domain['ISC'] = "https://www.cabi.org";
        
        $this->considered_as_Present = array("Present", "Localised", "Widespread", "Present only in captivity/cultivation", "Restricted distribution", 
        "Reported present or known to be present", "Present only under cover/indoors", "Transient: actionable, under surveillance", "Indigenous, localized", "Introduced, not established", 
        "Serological evidence and/or isolation of the agent", "Introduced, establishment uncertain", "Confined (quarantine)", "Introduced, established", "Indigenous");
    }

    function generate_invasiveness_data()
    {
        self::start_ISC();
        $this->archive_builder->finalize(TRUE);
        if($this->debug) {
            echo "\nun-mapped string location: ".count($this->debug['un-mapped string']['location'])."\n";
            Functions::start_print_debug($this->debug, $this->resource_id);
        }
    }
    private function start_ISC()
    {
        // /* un-comment in real operation
        $mappings = Functions::get_eol_defined_uris(false, true); //1st param: false means will use 1day cache | 2nd param: opposite direction is true
        echo "\n".count($mappings). " - default URIs from EOL registry.";
        $this->uri_values = Functions::additional_mappings($mappings); //add more mappings used in the past
        // */
        // print_r($this->uri_values);
        // exit("\nstopx\n");
        
        $csv_file = Functions::save_remote_file_to_local($this->taxa_list['ISC'], $this->download_options);
        $file = Functions::file_open($csv_file, "r");
        $i = 0;
        while(!feof($file)) {
            $i++;
            if(($i % 100) == 0) echo "\n count:[$i] ";
            $row = fgetcsv($file);
            if($i == 1) $fields = $row;
            else {
                $vals = $row;
                if(count($fields) != count($vals)) {
                    print_r($vals);
                    echo("\nNot same count ".count($fields)." != ".count($vals)."\n");
                    continue;
                }
                if(!$vals[0]) continue;
                $k = -1; $rec = array();
                foreach($fields as $field) {
                    $k++;
                    $rec[$field] = $vals[$k];
                }
                /* debug only - forced URL
                $rec['URL'] = "https://www.cabi.org/isc/datasheet/120279/aqb";
                */
                
                /*
                $rec['Scientific name'] = str_ireplace(" infections", "", $rec['Scientific name']);
                $rec['Scientific name'] = str_ireplace(" infection", "", $rec['Scientific name']);
                $rec['Scientific name'] = str_ireplace(" diseases", "", $rec['Scientific name']);
                $rec['Scientific name'] = str_ireplace(" disease", "", $rec['Scientific name']);
                */
                $rec['Scientific name'] = str_ireplace(" [ISC]", "", $rec['Scientific name']);
                if($rec['Scientific name'] == "Chytridiomycosis") continue; //name of a disease, exclude
                if(!ctype_upper(substr($rec['Scientific name'],0,1))) continue; //exclude likes of "abalone viral ganglioneuritis"

                if(preg_match("/\/datasheet\/(.*?)\//ims", $rec['URL'], $arr)) $rec['taxon_id'] = $arr[1]; // datasheet/121524/aqb
                else exit("\nInvestigate 01 ".$rec['Scientific name']."\n");
                
                $rec["bibliographicCitation"] = "CABI International Invasive Species Compendium, " . date("Y") . ". " . $rec["Scientific name"] . ". Available from: " . $rec["URL"] . " [Accessed " . date("M-d-Y") . "].";
                // print_r($rec);
                
                if($rec['Scientific name']) {
                    $url = $rec['URL'];
                    if($html = Functions::lookup_with_cache($url, $this->download_options)) {
                        $rec['taxon_ranges'] = self::get_native_introduced_invasive_ranges($html, $rec);
                        $rec['source_url'] = $url;
                        $rec['ancestry'] = self::get_ancestry($html, $rec);
                    }
                    // print_r($rec);
                    if($rec['Scientific name'] && $rec['taxon_ranges']) {
                        $this->create_instances_from_taxon_object($rec);
                        $this->process_GISD_distribution($rec);
                    }
                    // if($i == 3) break; //debug only
                }
            }
        }
        unlink($csv_file);
        // exit("\nstopx\n");
    }
    private function get_ancestry($html, $rec) //$rec here is just for debug
    {
        /*
        <div id='totaxonomicTree' class='Product_data-item'>
               <h3>Taxonomic Tree</h3>
               <a href='#top-page' class='Product_data-item-top'>Top of page</a>
               <?xml version="1.0" encoding="utf-16"?><ul class="Product_data-item"><li>Domain: Eukaryota</li><li>    Kingdom: Plantae</li><li>        Phylum: Spermatophyta</li><li>            Subphylum: Angiospermae</li><li>                Class: Dicotyledonae</li><li>                    Order: Asterales</li><li>                        Family: Asteraceae</li><li>                            Species: Iva xanthiifolia</li></ul>
           </div>
        */
        $final = array();
        if(preg_match("/<div id=\'totaxonomicTree\'(.*?)<\/div>/ims", $html, $arr)) {
            if(preg_match_all("/<li>(.*?)<\/li>/ims", $arr[1], $arr2)) {
                foreach($arr2[1] as $str) {
                    $str = str_replace(" ", "", $str);
                    $tmp = explode(":", $str);
                    $tmp = array_map('trim', $tmp);
                    $final[strtolower($tmp[0])] = $tmp[1];
                }
                // print_r($final); exit;
                return $final;
            }
        }
        else { //this else block is just for debug purposes
            /*
            if(in_array($rec['taxon_id'], array(95039, 95040, 78183, 108068, 107786, 92832, 107788, 90892, 90245, 108160, 87383, 108067, 106720, 102603, 108161))) {} //these taxon_id's are for dieseases names e.g. 'African swine fever' OR non-taxon names
            elseif(in_array($rec['taxon_id'], array(121671, 120803, 120994, 109730))) {} //acceptable to have no ancestry e.g. 'Bothriocephalus acheilognathi infection' but with ranges
            else {
                print_r($rec);
                echo("\nInvestigate no ancestry\n");
            }
            if($rec['taxon_ranges']) {
                echo("\nInvestigate no ancestry. Ranges total: ".count($rec['taxon_ranges'])."\n");
            }
            */
        }
        return $final;
    }
    private function get_native_introduced_invasive_ranges($html, $rec)
    {
        $final = array();
        if(preg_match("/<div id=\'todistributionTable\'(.*?)<\/div>/ims", $html, $arr)) { //<div id='todistributionTable' class='Product_data-item'>xxx yyy</div>
            // echo "\n".$arr[1];
            if(preg_match_all("/<tr>(.*?)<\/tr>/ims", $arr[1], $arr2)) {
                // print_r($arr2[1]);
                $i = 0;
                foreach($arr2[1] as $block) {
                    if($i === 0) {
                        $fields = self::get_fields($block);
                        // print_r($fields);
                    }
                    else {
                        $cols = self::get_values($block);
                        $rek = array(); $k = 0;
                        foreach($fields as $fld) {
                            $rek[$fld] = $cols[$k];
                            $k++;
                        }
                        if($val = self::valid_rek($rek, $rec)) $final[] = $val;
                    }
                    $i++;
                }
            }
            // exit("\n\n");
        }
        // else exit("\nInvestigate no Distribution Table ".$rec['Scientific name']."\n");
        return $final;
    }
    private function valid_rek($rek, $rec)
    {
        /*
        Array(
            [region] => <a href="/isc/datasheet/108785">-Russian Far East</a>
            [Distribution] => Present
            [Last Reported] => 
            [Origin] => Native
            [First Reported] => 
            [Invasive] => Invasive
            [Reference] => <a href="#81FD72A0-4561-4289-9CC1-03CC152F019E">Reshetnikov,
         1998</a>; <a href="#720991F8-F58F-4243-BD6B-7FC4EADDC706">Shed'ko,
         2001</a>; <a href="#9CE8C4A4-6317-4B9A-BB5C-00C8EC2904E9">Kolpakov et al.,
         2010</a>
            [Notes] => Native in Amur drainage and Khanka Lake; introduced and invasive in the Artemovka River (Ussuri Bay, Sea of Japan / East Sea) and Razdolnaya River (Peter the Great Bay, Sea of Japan/East Sea)
        )
        */
        $good = array();
        $rem = "";
        if($val = $rek['Notes']) $rem .= $val.". ";
        if($val = $rek['First Reported']) $rem .= "First reported: $val. ";
        if($val = $rek['Last Reported']) $rem .= "Last reported: $val. ";
        
        $refs = array();
        if($val = $rek['Reference']) $refs = self::assemble_references($val, $rec);
        if(in_array($rek['Origin'], array("Native", "Introduced")))                                       $good[] = array('region' => $rek['region'], 'range' => $rek['Origin'], "refs" => $refs, 'measurementRemarks' => $rem);
        if(in_array($rek['Invasive'], array("Invasive", "Not invasive")))                                 $good[] = array('region' => $rek['region'], 'range' => $rek['Invasive'], "refs" => $refs, 'measurementRemarks' => $rem);
        if(in_array($rek['Distribution'], array("Present, few occurrences", "Absent, formerly present", "Eradicated"))) $good[] = array('region' => $rek['region'], 'range' => $rek['Distribution'], "refs" => $refs, 'measurementRemarks' => $rem);
        if(!$good) { //only the time to add "http://eol.org/schema/terms/Present"
            if(in_array($rek['Distribution'], $this->considered_as_Present)) $good[] = array('region' => $rek['region'], 'range' => $rek['Distribution'], "refs" => $refs, 'measurementRemarks' => $rem);
        }
        if($val = @$rek['Distribution']) $this->debug['Distribution'][$val] = ''; //just for stats
        return $good;
    }
    private function assemble_references($ref_str, $rec)
    {
        $final = array();
        $html = Functions::lookup_with_cache($rec['URL'], $this->download_options);
        // print_r($rec);
        if(preg_match_all("/<a href=\"(.*?)\"/ims", $ref_str, $arr)) { //<a href="#6F3C79AC-42E4-40E3-A84D-57017C5A9414">
            // print_r($arr[1]);
            foreach($arr[1] as $anchor_id) {
                $final[] = self::lookup_ref_using_anchor_id($anchor_id, $html);
            }
        }
        return $final;
        // exit("\n$ref_str\n");
    }
    private function lookup_ref_using_anchor_id($anchor_id, $html)
    {
        $parts = array();
        $anchor_id = str_replace("#", "", $anchor_id);
        if(preg_match("/<p id=\"".$anchor_id."\" class=\"reference\">(.*?)<\/p>/ims", $html, $arr)) { //<p id="6F3C79AC-42E4-40E3-A84D-57017C5A9414" class="reference">
            // echo "\n$arr[1]\n";
            if(preg_match("/<a href=\"(.*?)\"/ims", $arr[1], $arr2)) $parts['ref_url'] = $arr2[1];
            $parts['full_ref'] = strip_tags($arr[1], "<i>");
        }
        return $parts;
    }
    private function get_values($block)
    {
        $block = str_replace("<td />", "<td></td>", $block);
        if(preg_match_all("/<td>(.*?)<\/td>/ims", $block, $arr)) {
            $cols = $arr[1];
            $cols = self::clean_columns($cols);
            // print_r($cols);
            return $cols;
        }
    }
    private function clean_columns($cols)
    {
        $final = array();
        $cols = array_map('trim', $cols);
        foreach($cols as $col) {
            $col = Functions::remove_whitespace($col);
            $final[] = $col;
        }
        return $final;
    }
    private function get_fields($block)
    {
        if(preg_match_all("/<th>(.*?)<\/th>/ims", $block, $arr)) {
            $fields = $arr[1];
            if($fields[0] == "Continent/Country/Region") {
                $fields[0] = 'region';
                return $fields;
            }
            else exit("\nHeaders changed...\n");
        }
        else exit("\nInvestigate no table headers\n");
    }
    private function capitalize_first_letter_of_country_names($names)
    {
        $final = array();
        foreach($names as $name) {
            $name = str_replace(array("\n", "\t"), "", $name);
            $name = trim(strtolower($name));
            $name = Functions::remove_whitespace($name);
            $tmp = explode(" ", $name);
            $tmp = array_map('ucfirst', $tmp);
            $tmp = array_map('trim', $tmp);
            $final[] = implode(" ", $tmp);
        }
        return $final;
    }
    private function get_rank($ancestry, $sciname)
    {
        $ranks = array_keys($ancestry);
        foreach($ranks as $rank) {
            if($ancestry[$rank] == $sciname) {
                if($rank == "species" && substr_count($sciname," ") == 1) return $rank;
                // else just ignore it, probably subspecies. Something CABI ISC doesn't specify correctly
            }
        }
    }
    private function create_instances_from_taxon_object($rec)
    {
        /*  [Scientific name] => Abbottina rivularis
            [Common name] => Chinese false gudgeon
            [Coverage] => Full
            [URL] => https://www.cabi.org/isc/datasheet/110570/aqb
            [taxon_id] => 110570
            [ancestry] => Array(
                     [domain] => Eukaryota
                     [kingdom] => Metazoa
                     [phylum] => Chordata
                     [subphylum] => Vertebrata
                     [class] => Actinopterygii
                     [order] => Cypriniformes
                     [family] => Cyprinidae
                     [genus] => Abbottina
                     [species] => Abbottina rivularis
                 )
        */
        $taxon = new \eol_schema\Taxon();
        $taxon->taxonID         = $rec["taxon_id"];
        $taxon->scientificName  = $rec["Scientific name"];
        $taxon->taxonRank = self::get_rank($rec['ancestry'], $rec["Scientific name"]);
        
        foreach(array_keys($rec['ancestry']) as $rank) {
            if($rank == $taxon->taxonRank) break;
            if(in_array($rank, array('kingdom', 'phylum', 'class', 'order', 'family', 'genus'))) {
                $taxon->$rank = $rec['ancestry'][$rank];
            }
        }
        $taxon->furtherInformationURL = $rec["source_url"];
        if(!isset($this->taxon_ids[$taxon->taxonID])) {
            $this->archive_builder->write_object_to_file($taxon);
            $this->taxon_ids[$taxon->taxonID] = '';
        }
        
        if($common_name = @$rec['Common name']) {
            $v = new \eol_schema\VernacularName();
            $v->taxonID = $rec["taxon_id"];
            $v->vernacularName = trim($common_name);
            $v->language = "en";
            $vernacular_id = md5("$v->taxonID|$v->vernacularName|$v->language");
            if(!isset($this->vernacular_ids[$vernacular_id])) {
                $this->vernacular_ids[$vernacular_id] = '';
                $this->archive_builder->write_object_to_file($v);
            }
        }
    }
    private function get_mtype_for_range($range)
    {
        switch($range) {
            case "Introduced":                  return "http://eol.org/schema/terms/IntroducedRange";
            case "Invasive":                    return "http://eol.org/schema/terms/InvasiveRange";
            case "Native":                      return "http://eol.org/schema/terms/NativeRange";
            case "Not invasive":                return "http://eol.org/schema/terms/NonInvasiveRange";
            case "Present, few occurrences":    return "http://eol.org/schema/terms/presentAndRare";
            case "Absent, formerly present":    return "http://eol.org/schema/terms/absentFormerlyPresent";
            case "Eradicated":                  return "http://eol.org/schema/terms/absentFormerlyPresent";
            case "Localised":                   return "http://eol.org/schema/terms/Present";
            case "Widespread":                  return "http://eol.org/schema/terms/Present";
            case "Present":                     return "http://eol.org/schema/terms/Present";
        }
        if(in_array($range, $this->considered_as_Present)) return "http://eol.org/schema/terms/Present";
    }
    private function process_GISD_distribution($rec)
    {
        foreach($rec['taxon_ranges'] as $ranges) {
            foreach($ranges as $r) {
                /* Array (
                    [region] => <a href="/isc/datasheet/108785">-Russian Far East</a>
                    [range] => Native
                    [refs] => Array (
                            [0] => Array (
                                    [full_ref] => Reshetnikov YuS, 1998. Annotated catalog of cyclostomes and fishes of continental waters of Russia. Moscow, Russia: Nauka, 220 pp.
                                )
                            [1] => Array (
                                    [full_ref] => Shed'ko NE, 2001. List of cyclostomes and fishes of fresh waters of Primorye coast,. In: Chteniya pamyati Vladimira Yakovlevicha Levanidova. 220-249.
                                )
                            [2] => Array (
                                    [ref_url] => /isc/abstract/20113034542
                                    [full_ref] => Kolpakov NV; Barabanshchikov EI; Chepurnoi AYu, 2010. Species composition, distribution, and biological conditions of nonindigenous fishes in the estuary of the Razdol'naya River (Peter the Great Bay, Sea of Japan). Russian Journal of Biological Invasions, 1(2):87-94. http://www.maik.ru/abstract/bioinv/10/bioinv0087_abstract.pdf
                                )
                        )
                )
                */
                $location = strip_tags($r['region']);
                $location = str_replace("-", "", $location);
                $rec['catnum'] = $r['range']."_" . str_replace(" ", "_", $location);
                
                //start refs
                $reference_ids = array();
                if(@$r['refs'][0]['full_ref']) {
                    foreach($r['refs'] as $ref) {
                        if($val = self::write_reference($ref)) $reference_ids[] = $val;
                    }
                }
                //end refs
                
                self::add_string_types("true", $rec, $r['range'], self::get_value_uri($location, 'location'), self::get_mtype_for_range($r['range']), $reference_ids, $location, $r);
                /* deliberately excluded
                if($val = $rec["Species"])                  self::add_string_types(null, $rec, "Scientific name", $val, "http://rs.tdwg.org/dwc/terms/scientificName");
                */
                /* will now be added to the main record.
                if($val = $rec["bibliographicCitation"])    self::add_string_types(null, $rec, "Citation", $val, "http://purl.org/dc/terms/bibliographicCitation");
                */
            }
        }
    }
    private function write_reference($ref)
    {
        if(!@$ref['full_ref']) return false;
        $re = new \eol_schema\Reference();
        $re->identifier = md5($ref['full_ref']);
        $re->full_reference = $ref['full_ref'];
        if($path = @$ref['ref_url']) $re->uri = $this->domain['ISC'].$path; // e.g. https://www.cabi.org/isc/abstract/20000808896
        if(!isset($this->reference_ids[$re->identifier])) {
            $this->archive_builder->write_object_to_file($re);
            $this->reference_ids[$re->identifier] = '';
        }
        return $re->identifier;
    }
    private function add_string_types($measurementOfTaxon, $rec, $label, $value, $mtype, $reference_ids = array(), $orig_value = "", $range_rec = array())
    {
        // print_r($range_rec);
        $taxon_id = $rec["taxon_id"];
        $catnum = $rec["catnum"];
        $m = new \eol_schema\MeasurementOrFact();
        $occurrence = $this->add_occurrence($taxon_id, $catnum, $rec);
        $m->occurrenceID = $occurrence->occurrenceID;
        $m->measurementType = $mtype;
        $m->measurementValue = $value;
        if($val = $measurementOfTaxon) {
            $m->measurementOfTaxon = $val;
            $m->source = $rec["source_url"];
            if($reference_ids) $m->referenceID = implode("; ", $reference_ids);
            $m->bibliographicCitation = $rec['bibliographicCitation']; //will be added to the main record per Jen: https://eol-jira.bibalex.org/browse/TRAM-794?focusedCommentId=62697&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-62697
            $m->measurementRemarks = "";
            if($orig_value) $m->measurementRemarks = ucfirst($orig_value).". ";
            $m->measurementRemarks .= $range_rec['measurementRemarks'];
            // $m->contributor = ''; $m->measurementMethod = '';
        }
        $m->measurementID = Functions::generate_measurementID($m, $this->resource_id);
        if(!isset($this->measurement_ids[$m->measurementID])) {
            $this->archive_builder->write_object_to_file($m);
            $this->measurement_ids[$m->measurementID] = '';
        }
    }

    private function add_occurrence($taxon_id, $catnum, $rec)
    {
        $occurrence_id = md5($taxon_id . '_' . $catnum);
        if(isset($this->occurrence_ids[$occurrence_id])) return $this->occurrence_ids[$occurrence_id];
        $o = new \eol_schema\Occurrence();
        $o->occurrenceID = $occurrence_id;
        $o->taxonID = $taxon_id;
        $this->archive_builder->write_object_to_file($o);
        $this->occurrence_ids[$occurrence_id] = $o;
        return $o;
    }
    private function get_value_uri($string, $type)
    {
        switch ($string) { //others were added in https://raw.githubusercontent.com/eliagbayani/EOL-connector-data-files/master/GISD/mapped_location_strings.txt
            case "brackish":                      return "http://purl.obolibrary.org/obo/ENVO_00000570";
            case "marine_freshwater_brackish":    return "http://purl.obolibrary.org/obo/ENVO_00002030"; //based here: https://eol-jira.bibalex.org/browse/TRAM-794?focusedCommentId=62690&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-62690
            case "terrestrial_freshwater_marine": return false; //skip based here: https://eol-jira.bibalex.org/browse/TRAM-794?focusedCommentId=62690&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-62690
        }

        /* from DATA-1806:
        For the CABI Invasive Species Compendium: I missed the homonym pair of Georgia and Georgia. Happily, the correct values are still tagged in measurementRemarks, 
        though it's still a bit messy. Where measurementRemarks CONTAINS "Georgia (Republic of)." please leave the measurementValue as it is. 
        Where measurementRemarks CONTAINS "Georgia. " but not "(Republic of)", please assign measurementValue= https://www.geonames.org/4197000 */
        if($string == "Georgia") return 'https://www.geonames.org/4197000';
        

        if($val = @$this->uri_values[$string]) return $val;
        else {
            $this->debug['un-mapped string'][$type][$string] = '';
            if($type == 'habitat')      return false;
            elseif($type == 'location') return $string;
        }
    }
    /* not used, just for reference
    private function format_habitat($desc)
    {
        $desc = trim(strtolower($desc));
        elseif($desc == "marine/freshwater")        return "http://eol.org/schema/terms/freshwaterAndMarine";
        elseif($desc == "ubiquitous")               return "http://eol.org/schema/terms/ubiquitous";
        else {
            echo "\n investigate undefined habitat [$desc]\n";
            return $desc;
        }
    }
    */

}
?>