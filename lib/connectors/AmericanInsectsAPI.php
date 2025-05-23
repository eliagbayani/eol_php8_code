<?php
namespace php_active_record;
// connector: [770]
class AmericanInsectsAPI
{
    function __construct($folder)
    {
        $this->resource_id = $folder;
        $this->path_to_archive_directory = CONTENT_RESOURCE_LOCAL_PATH . '/' . $folder . '_working/';
        $this->archive_builder = new \eol_schema\ContentArchiveBuilder(array('directory_path' => $this->path_to_archive_directory));
        $this->taxon_ids = array();
        $this->object_ids = array();

        $this->domain = "http://americaninsects.net/";
        $this->to_exclude = array("index.", "glossary.", "maps.", "acknowledgment", "about.html", "faq.html", "works-consulted", "23", "24", "periplaneta-americana", "/http:");
        $this->stored_offline_urls_dump_file = LOCAL_HOST."/cp/AmericanInsects/offline_urls.txt";
        $this->stored_offline_urls_dump_file = "http://opendata.eol.org/dataset/24452c49-7f19-42a2-9a51-68e21c87b174/resource/8552c33b-2102-4e29-ab0a-04218a39e0be/download/offlineurls.txt";
        $this->download_options = array("download_wait_time" => 1000000, "timeout" => 1800, "download_attempts" => 1, "cache" => 1, "expire_seconds" => 60*60*24*30); //expires in 30 days | "delay_in_minutes" => 2
        $this->download_options['expire_seconds'] = false; //doesn't expire
        $this->debug = array();
        
        //for stats
        $this->TEMP_DIR = create_temp_dir() . "/";
        $this->current_offline_urls_dump_file = $this->TEMP_DIR . "offline_urls.txt";
        $this->current_offline_urls_dump_file2 = $this->TEMP_DIR . "offline_urls_unique.txt";
    }
    function get_all_taxa()
    {
        require_library('connectors/TraitGeneric');
        $this->func = new TraitGeneric($this->resource_id, $this->archive_builder);
        
        $this->stored_offline_urls = $this->get_rows_from_dump_file($this->stored_offline_urls_dump_file);
        $this->create_reference();
        $urls = self::get_urls_to_process();
        $this->process_urls($urls);
        $this->manuall_add_taxon();
        $this->archive_builder->finalize(TRUE);
        recursive_rmdir($this->TEMP_DIR); // comment this line to check offline_urls.txt
        debug("\n temporary directory removed: " . $this->TEMP_DIR);
    }
    private function process_urls($urls)
    {
        $i = 0;
        $total = count($urls);
        foreach($urls as $url) {
            $i++;
            // echo "\n - $i of $total [$url]\n"; //good debug
            if(isset($this->stored_offline_urls[$url])) continue;
            
            /* breakdown when caching debug
            $cont = false;
            // if($i >= 1 && $i < 1000)     $cont = true;
            // if($i >= 1000 && $i < 2000)  $cont = true;
            // if($i >= 2000 && $i < 3000)  $cont = true;
            // if($i >= 3000 && $i < 4000)  $cont = true;
            if(!$cont) continue;
            */
            
            if($html = Functions::lookup_with_cache($url, $this->download_options)) {
                $html = trim(str_ireplace(array(' align="center"', ' class="style1"', ' class="style2"'), "", $html));
                if(preg_match("/>Family: (.*?)xxx/ims", $html . "xxx", $arr)) {
                    $rec["source"] = $url;
                    if(preg_match("/<h1>(.*?)<\/h1>/ims", $html, $arr) ||
                       preg_match("/<FONT FACE=\"Arial\">(.*?)<\/FONT>/ims", $html, $arr) ||
                       preg_match("/<h2>(.*?)<\/h2>/ims", $html, $arr))
                    {
                        $sciname = Functions::remove_whitespace(strip_tags($arr[1]));
                        $sciname = trim(str_replace(array(chr(13), chr(10)), " ", $sciname));
                        //manual adjustments
                        if($sciname == "Small Winter Stoneflies") $sciname = "Allocapnia sp.";
                        if($sciname == "Embioptera: Family Oligotomidae") $sciname = "Oligotomidae";

                        $sciname = trim($sciname);
                        $to_exclude = array("cf.", "sp.", "Unidentified Stonefly", "Family");
                        $include = true;
                        foreach($to_exclude as $exclude) {
                            if(is_numeric(stripos($sciname, $exclude))) $include = false;
                        }
                        if(!$include) continue;

                        $sciname = Functions::canonical_form($sciname);
                        // only species-level
                        if(stripos($sciname, " ") === false) continue;

                        // $images = self::parse_images($html, $url); working... temporarily commented
                        $images = array();
                        $info = self::parse_texts($html, $url);
                        $lengths = @$info["lengths"];
                        $wingspan = @$info["wingspan"];
                        
                        if($images || $lengths) {
                            $r = array();
                            $r["sciname"] = $sciname;
                            $r["taxon_id"] = str_replace(" ", "_", $r["sciname"]);
                            $r["source"] = $url;
                            self::create_instances_from_taxon_object($r);
                            $r["images"] = $images;
                            $r["lengths"] = $lengths;
                            $r["wingspan"] = $wingspan;
                            if($lengths) self::prepare_length_structured_data($r);
                            if($images) self::prepare_image_objects($r);
                        }
                    }
                    else echo "\n investigate: no sciname [$url]";
                }
            }
            else self::save_to_dump($url, $this->current_offline_urls_dump_file);
        }
        // print_r($this->debug);
        print "\n count:" . count($this->debug) . "\n";
    }
    private function manuall_add_taxon()
    {
        $records[] = array("sciname" => "Cordulegaster diastatops", "length" => "60-65", "url" => "http://www.americaninsects.net/d/cordulegaster-diastatops.html");
        $records[] = array("sciname" => "Cordulegaster bilineata", "length" => "60-65", "url" => "http://www.americaninsects.net/d/cordulegaster-diastatops.html");
        $records[] = array("sciname" => "Enallagma cyathigerum", "length" => "29-40", "url" => "http://americaninsects.net//d/enallagma-cyathigerum.html");
        $records[] = array("sciname" => "Enallagma boreale", "length" => "28-36", "url" => "http://americaninsects.net//d/enallagma-cyathigerum.html");
        foreach($records as $rec) {
            $r = array();
            $r["sciname"] = Functions::canonical_form($rec["sciname"]);
            $r["taxon_id"] = str_replace(" ", "_", $r["sciname"]);
            $r["source"] = $rec["url"];
            self::create_instances_from_taxon_object($r);
            $r["lengths"] = array($rec["length"]);
            self::prepare_length_structured_data($r);
        }
    }
    private function create_reference()
    {
        $r = new \eol_schema\Reference();
        $r->full_reference = "Cresswell, S. 2010-2014. American Insects. http://www.americaninsects.net";
        $r->identifier = md5($r->full_reference);
        $this->reference_id = $r->identifier;
        $this->archive_builder->write_object_to_file($r);
    }
    private function parse_texts($html, $url)
    {
        $texts = array();
        $length = "";
        $wingspan = false;
        if(preg_match("/>Wingspan: (.*?)</ims", $html, $arr)) {
            $wingspan = true;
            $html = str_replace("Wingspan: ", "Length: ", $html);
        }
        if(preg_match("/>Length: (.*?)</ims", $html, $arr)) {
            if($length = $arr[1]) {
                $to_exclude = array("in image", "top photo", "first photo", "lower photo", "upper photo", "the photo", "in photo", "pictured", 
                "Cantharini", "the right");
                foreach($to_exclude as $exclude) {
                    if(is_numeric(stripos($length, $exclude))) return array();
                }
                
                $length = trim(str_ireplace(array("females are a little larger than males", "Males usually smaller than the females", 
                "males average smaller than females", "males tend  to be smaller than females", "Both sexes somewhat larger in  western North America",
                "some in the tribe are", "most in tribe", "most commonly", "adults are", "most in this genus", 
                 "many in the tribe are", "in subfamily", "in the subfamily",  
                "in this family", "in the family", 
                "typically", "beetle", "barklouse", "many species", "in the genus are", "insect in photo", " mm", "often", "in the genus", "most are", 
                "about", "fly", "Caddis", "insect", "under", "wasp", "around", "&nbsp;",  
                "most in genus", "usually", "adults", "most in family are", "in genus", "approximately"
                ), "", $length));
                
                //manual adjustment
                $length = str_replace("1.5 - 6.0 ;  3.3", "1.5 - 6.0", $length);
                $length = str_replace("7 - 9. In genus, 5 - 13", "7 - 9", $length);
                
                $chars = array(".", ",", ";");
                foreach($chars as $char) {
                    if(substr($length, -1) == $char) $length = substr($length, 0, strlen($length)-1);
                }
                echo "\n[$length]\n";
                $texts[] = $length;
            }
        }
        return array("lengths" => $texts, "wingspan" => $wingspan);
    }
    private function parse_images($html, $url)
    {
        $images = array();
        $to_exclude = array("button");
        if(preg_match_all("/<img (.*?)>/ims", $html, $arr)) {
            foreach($arr[1] as $img) {
                $include = true;
                foreach($to_exclude as $exclude) {
                    if(strpos($img, $exclude) === false) {}
                    else $include = false;
                }
                if($include) {
                    $src = "";
                    $caption = "";
                    $image_path = "";
                    if(preg_match("/src=\"(.*?)\"/ims", $img, $arr2)) $src = $arr2[1];
                    if(preg_match("/alt=\"(.*?)\"/ims", $img, $arr2)) $caption = $arr2[1];
                    if($src) {
                        if($val = self::get_directory_name($url)) $image_path = $val . "/" . $src;
                        if($image_path) $images[] = array("title" => $caption, "image" => $image_path);
                    }
                }
            }
        }
        return $images;
    }
    private function get_directory_name($url)
    {
        $info = pathinfo($url);
        if($info["dirname"] == "http:") return $this->domain;
        else                            return $info["dirname"];
        return false;
    }
    private function get_urls_to_process()
    {
        $urls = array();
        $urls[0] = self::get_urls_from_page($this->domain);
        $urls[1] = self::get_deep_level_urls($urls[0]);
        $urls[2] = self::get_deep_level_urls($urls[1]);
        $urls[3] = self::get_deep_level_urls($urls[2]);
        $urls[4] = self::get_deep_level_urls($urls[3]);
        $urls[5] = self::get_deep_level_urls($urls[4]);
        $temp = array_merge($urls[1], $urls[2], $urls[3], $urls[4], $urls[5]);
        $urls = array_values(array_unique($temp));
        $i = 0;
        foreach($urls as $url) {
            foreach($this->to_exclude as $exclude) {
                if(strpos($url, $exclude) === false) {}
                else unset($urls[$i]);
            }
            $i++;
        }
        $urls = array_map('trim', $urls);
        $urls = array_values(array_unique($urls));
        return $urls;
    }
    private function get_deep_level_urls($urls_to_process)
    {
        $temp = array();
        $total = count($urls_to_process);
        $i = 0;
        foreach($urls_to_process as $url) {
            $i++;
            echo "\n $i of $total - ";
            $temp = array_merge($temp, self::get_urls_from_page($url));
        }
        $temp = array_values(array_unique($temp));
        $final = array();
        foreach($temp as $url) {
            $info = pathinfo($url);
            $basename = $info["basename"];
            if(isset($this->basenames[$basename])) continue;
            else $this->basenames[$basename] = 1;
            $final[] = $url;
        }
        return $final;
    }
    private function get_urls_from_page($url)
    {
        if(isset($this->stored_offline_urls[$url])) return array();
        foreach($this->to_exclude as $exclude) {
            if(is_numeric(stripos($url, $exclude))) return array();
        }
        // echo "\n processing [$url]\n"; //good debug
        $temp = array();
        if($html = Functions::lookup_with_cache($url, $this->download_options)) {
            $html = str_ireplace(array(' class="style1"', ' class="style2"', ' class="style3"', ' class="style4"', ' class="style5"', 
                                       ' class="style6"', ' class="style7"', ' class="style8"', ' class="style9"', ' class="style10"',
                                       ' class="navbar"', ' class="style20"', ' class="style13"'), "", $html);
            $html = str_ireplace("%20.htm", ".htm", $html);
            $html = str_ireplace(" .htm", ".htm", $html);
            if(preg_match_all("/<a href\=\"(.*?)\">/ims", $html, $arr)) $temp = array_values(array_unique($arr[1]));
            else echo " - no urls \n ";
        }
        else self::save_to_dump($url, $this->current_offline_urls_dump_file);
        // generate url
        $final = array();
        if($temp) {
            $dirname = self::get_directory_name($url);
            foreach($temp as $url) {
                $include = true;
                foreach($this->to_exclude as $exclude) {
                    if(strpos($url, $exclude) === false) {}
                    else $include = false;
                }
                if($include) $final[] = $dirname . "/" . $url;
            }
        }
        return $final;
    }
    private function save_to_dump($data, $filename)
    {
        if(!($WRITE = Functions::file_open($filename, "a"))) $return;
        if($data && is_array($data)) fwrite($WRITE, json_encode($data) . "\n");
        else                         fwrite($WRITE, $data . "\n");
        fclose($WRITE);
    }
    private function get_rows_from_dump_file($url) // utility
    {
        $path = Functions::save_remote_file_to_local($url, $this->download_options);
        $urls = array();
        foreach(new FileIterator($path) as $line_number => $line) {
            if($line) $urls[$line] = "";
        }
        unlink($path);
        return $urls;
    }
    // private function prepare_text_objects($rec)
    // {
    //     $articles = array();
    //     if(@$rec["texts"])
    //     {
    //         foreach($rec["texts"] as $type => $r) $articles[$type] = implode("<p>", $r);
    //         foreach($articles as $type => $description)
    //         {
    //             $description = trim($description);
    //             if(!$description) continue;
    //             $obj = array();
    //             $obj["description"] = "<p>" . $description;
    //             $obj["subject"] = self::get_subject($type);
    //             $obj["identifier"] = md5($rec["sciname"] . $description);
    //             $obj["type"] = "text";
    //             $obj["taxon_id"] = $rec["taxon_id"];
    //             $obj["source"] = $rec["source"];
    //             self::get_objects($obj);
    //         }
    //     }
    // }
    private function prepare_image_objects($rec)
    {
        if($imagez = @$rec["images"]) {
            foreach($imagez as $image) self::save_image_object($image, $rec);
        }
    }
    private function save_image_object($image, $rec)
    {
        $obj = array();
        $obj["description"] = $image["title"];
        $obj["title"]       = "";
        $obj["identifier"]  = md5($image["image"]);
        $obj["type"]        = "image";
        $obj["taxon_id"]    = $rec["taxon_id"];
        $obj["source"]      = $rec["source"];
        $obj["accessURI"]   = $image["image"];
        self::get_objects($obj);
    }
    private function get_objects($rec)
    {
        $mr = new \eol_schema\MediaResource();
        if($rec["type"] == "text") {
            $mr->type               = 'http://purl.org/dc/dcmitype/Text';
            $mr->format             = 'text/html';
            $mr->CVterm             = $rec["subject"];
        }
        elseif($rec["type"] == "image") {
            $mr->type               = 'http://purl.org/dc/dcmitype/StillImage';
            $mr->format             = 'image/jpeg';
            $mr->accessURI          = $rec["accessURI"];
            $mr->title              = $rec["title"];
        }
        $mr->taxonID                = $rec["taxon_id"];
        $mr->identifier             = $rec["identifier"];
        $mr->language               = 'en';
        $mr->furtherInformationURL  = $rec["source"];
        $mr->description            = $rec["description"];
        $mr->UsageTerms             = 'http://creativecommons.org/licenses/by/3.0/';
        $mr->Owner                  = '';
        if(!isset($this->object_ids[$mr->identifier])) {
            $this->object_ids[$mr->identifier] = 1;
            $this->archive_builder->write_object_to_file($mr);
        }
    }
    private function prepare_length_structured_data($rec)
    {
        foreach($rec["lengths"] as $len) {
            if($len) {
                $length = self::clean_length_value($len);
                $lengths = explode(";", $length);
                $lengths = array_map('trim', $lengths);
                $ctr = 0;
                foreach($lengths as $length) {
                    $rec["remark"] = $length;
                    $length_no = trim(str_replace(array("female", "male", "worker", "queen", "drone", "mm", "to apex of abdomen", "greater than"), "", $length));
                    if(!$length_no) continue;
                    if($val = self::is_range($length_no)) $length_no = $val; // "3.50 to 4.0"
                    $arr = explode("-", $length_no);
                    $arr = array_map('trim', $arr);
                    if(count($arr) == 1) {
                        $final = $length_no;
                        if(count(explode(" ", $length_no)) > 1) $rec["measurementRemarks"] = trim($length) . " (mm)";
                    }
                    else {
                        $final = self::get_average($arr[0], $arr[1]);
                        $rec["measurementRemarks"] = "Source data are expressed as a range: " . trim($length) . " mm.";
                    }
                    $final = trim(preg_replace('/\s*\([^)]*\)/', '', $final)); //remove parenthesis
                    if(preg_match('!\d+\.*\d*!', $final, $match)) $final = $match[0]; //remove letters
                    if(is_numeric(strpos(@$rec["measurementRemarks"], "Source data are expressed as a range:"))) $rec["measurementRemarks"] .= " ($final mm. average).";
                    $rec["catnum"] = "length";
                    if($ctr) $rec["catnum"] .= $ctr;
                    $rec["statistical_method"] = "http://eol.org/schema/terms/average";
                    $rec["measurementUnit"] = "http://purl.obolibrary.org/obo/UO_0000016";
                    $length_measurement = "http://purl.obolibrary.org/obo/CMO_0000013";
                    if(is_numeric(stripos($length, "wingspan")) || @$rec["wingspan"]) $length_measurement = "http://www.wikidata.org/entity/Q245097"; //"http://www.owl-ontologies.com/unnamed.owl#Wingspan"; remapped per DATA-1841

                    // print_r($rec);
                    /*Array(
                        [sciname] => Euchroma gigantea
                        [taxon_id] => Euchroma_gigantea
                        [source] => http://americaninsects.net//b/euchroma-gigantea.html
                        [images] => Array()
                        [lengths] => Array(
                                [0] => 50 - 60
                            )
                        [wingspan] => 
                        [remark] => 50 - 60
                        [measurementRemarks] => Source data are expressed as a range: 50 - 60 mm. (55 mm. average).
                        [catnum] => length
                        [statistical_method] => http://eol.org/schema/terms/average
                        [measurementUnit] => http://purl.obolibrary.org/obo/UO_0000016
                    )
                    proposed: template from another resource
                    $rec = array();
                    *$rec["taxon_id"] = $taxon_id;
                    *$rec["catnum"] = $taxon_id.$d->id;
                    *$rec['measurementRemarks'] = $d->annotation;
                    *$rec['source'] = "https://www.speciesplus.net/#/taxon_concepts/$taxon_id/legal";
                    */
                    $rec['statisticalMethod'] = $rec['statistical_method'];
                    $rec['referenceID'] = $this->reference_id;
                    $mtype = $length_measurement;
                    $rec['lifeStage'] = 'http://www.ebi.ac.uk/efo/EFO_0001272'; //new DATA-1808 - Jul 2019
                    
                    /* orig. Not using TraitGeneric yet at this point.
                    self::add_string_types("true", $rec, "length", $final, $length_measurement);
                    */
                    $this->func->add_string_types($rec, $final, $mtype, "true"); //using TraitGeneric
                    
                    $this->debug[$final] = @$rec["measurementRemarks"];
                    $ctr++;
                }
            }
        }
    }
    /* we're using TraitGeneric now
    private function add_string_types($measurementOfTaxon, $rec, $label, $value, $mtype)
    {
        $taxon_id = $rec["taxon_id"];
        $catnum = $rec["catnum"];
        $m = new \eol_schema\MeasurementOrFact();
        $occurrence = $this->add_occurrence($taxon_id, $catnum);
        $m->occurrenceID = $occurrence->occurrenceID;
        $m->measurementOfTaxon = $measurementOfTaxon;
        $m->source = $rec["source"];
        $m->measurementType = $mtype;
        $m->measurementValue = $value;
        $m->referenceID = $this->reference_id;
        if($val = @$rec["statistical_method"]) $m->statisticalMethod = $val;
        if($val = @$rec["measurementUnit"]) $m->measurementUnit = $val;
        if($val = @$rec["measurementRemarks"]) $m->measurementRemarks = $val;
        $this->archive_builder->write_object_to_file($m);
    }
    private function add_occurrence($taxon_id, $catnum)
    {
        $occurrence_id = $taxon_id . '_' . $catnum;
        if(isset($this->occurrence_ids[$occurrence_id])) return $this->occurrence_ids[$occurrence_id];
        $o = new \eol_schema\Occurrence();
        $o->occurrenceID = $occurrence_id;
        $o->taxonID = $taxon_id;
        $this->archive_builder->write_object_to_file($o);
        $this->occurrence_ids[$occurrence_id] = $o;
        return $o;
    }
    */
    private function clean_length_value($length)
    {
        $length = strtolower($length);
        $length = str_replace(array("\t", "\n", "males are smaller than females", "midilinae"), "", $length);
        $length = str_replace("&gt;", "greater than", $length);
        $length = str_replace("workers", "worker", $length);
        $length = str_replace(". female", "; female", $length);
        $length = str_replace(". male", "; male", $length);
        $length = str_replace(". work", "; work", $length);
        return $length;
    }
    private function get_average($num1, $num2)
    {
        if(preg_match('!\d+\.*\d*!', $num1, $match)) $num1 = $match[0]; //remove letters
        if(preg_match('!\d+\.*\d*!', $num2, $match)) $num2 = $match[0];
        return round(($num1+$num2)/2, 1);
    }
    private function is_range($str)
    {
        $arr = explode(" to ", $str);
        if(count($arr) == 2) {
            if(is_numeric($arr[0]) && is_numeric($arr[1])) return str_replace(" to ", "-", $str);
        }
        return false;
    }
    private function create_instances_from_taxon_object($rec)
    {
        $taxon = new \eol_schema\Taxon();
        $taxon->taxonID                     = $rec["taxon_id"];
        $taxon->scientificName              = $rec["sciname"];
        $taxon->furtherInformationURL       = $rec["source"];
        if(!isset($this->taxon_ids[$taxon->taxonID])) {
            $this->taxon_ids[$taxon->taxonID] = 1;
            $this->archive_builder->write_object_to_file($taxon);
        }
    }
    private function make_offline_urls_unique()
    {
        $stored_offline_urls = self::get_rows_from_dump_file($this->stored_offline_urls_dump_file);
        $temp = array_keys($stored_offline_urls);
        foreach($temp as $url) self::save_to_dump($url, $this->current_offline_urls_dump_file2);
    }
}
?>