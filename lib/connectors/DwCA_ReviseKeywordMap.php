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
        // get vars from TextmineKeywordMapAPI(), then unset() it.
        $this->uris_with_new_kwords     = $func->uris_with_new_kwords; //n=15
        $this->uri_in_question          = $func->uri_in_question; //print_r($func->uri_in_question); exit;
        $this->uri_in_question_current  = $func->uri_in_question_current;        
        // $this->uri_currenturi_map       = $func->uri_currenturi_map;        
        $this->uri_katjauri_map         = $func->uri_katjauri_map;        

        // $this->new_keywords_string_uri  = $func->new_keywords_string_uri;
        unset($func->uris_with_new_kwords);
        unset($func->uri_in_question);
        unset($func->uri_in_question_current);
        // unset($func->uri_currenturi_map);
        // unset($func->new_keywords_string_uri);
        unset($func->uri_katjauri_map);

        echo "\nuri_in_question 2: ".count($this->uri_in_question);
        echo "\nuri_in_question_current 2: ".count($this->uri_in_question_current);
        // echo "\nuri_currenturi_map 2: ".count($this->uri_currenturi_map);
        echo "\nuri_katjauri_map 2: ".count($this->uri_katjauri_map);

        // echo "\nnew_keywords 2: ".count($this->new_keywords_string_uri);
        echo "\nuris_with_new_kwords: ".count($this->uris_with_new_kwords); //print_r($this->uris_with_new_kwords);
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
        // /*

        // step 0-a
        $tbl = "http://rs.tdwg.org/dwc/terms/taxon";
        $meta = $tables[$tbl][0];
        self::process_table($meta, 'evaluate_taxa');
        // step 0-b
        $tbl = "http://rs.tdwg.org/dwc/terms/occurrence";
        $meta = $tables[$tbl][0];
        self::process_table($meta, 'evaluate_Occurrence');


        // step 1
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";
        $meta = $tables[$tbl][0];
        self::process_table($meta, 'evaluate_MoF');
        // step 2
        $tbl = "http://rs.tdwg.org/dwc/terms/occurrence";
        $meta = $tables[$tbl][0];
        self::process_table($meta, 'write_Occurrence');
        // step 3: delete children of deleted parent MoF
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";
        $meta = $tables[$tbl][0];
        self::process_table($meta, 'write_MoF');
        // */

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
            // print_r($rec); exit;
            /* Not recognized fields e.g. WoRMS2EoL.zip
            if(isset($rec['http://purl.org/dc/terms/rights'])) unset($rec['http://purl.org/dc/terms/rights']);
            if(isset($rec['http://purl.org/dc/terms/rightsHolder'])) unset($rec['http://purl.org/dc/terms/rightsHolder']);
            */
            if($what == 'evaluate_taxa') { //print_r($rec); exit("\nelix 100\n");
                /*Array(
                    [taxonID] => Q25243
                    [source] => https://www.wikidata.org/wiki/Q25243
                    [parentNameUsageID] => Q4085525
                    [scientificName] => Betula
                    [taxonRank] => genus
                    [scientificNameAuthorship] => Carl Linnaeus, 1753
                    [canonicalName] => 
                )*/
                if($taxonRank = strtolower(@$rec['taxonRank'])) {
                    if($taxonRank == 'species') $this->accepted_taxa[$rec['taxonID']] = '';
                }
                if($scientificName = @$rec['scientificName']) {
                    if(Functions::is_binomial($scientificName)) $this->accepted_taxa[$rec['taxonID']] = '';
                }
            }
            elseif($what == 'evaluate_Occurrence') {
                /*Array(
                    [occurrenceID] => bce4fa8b6c08381b674c545409717a17_617_ENV
                    [taxonID] => Q140
                )*/
                $taxonID = $rec['taxonID'];
                $occurrenceID = $rec['occurrenceID'];
                if(!isset($this->accepted_taxa[$taxonID])) { // print_r($rec);
                    $this->delete_occurrence_ids[$occurrenceID] = '';
                }
            }

            if($what == 'evaluate_MoF') { //print_r($rec); exit("\nelix 100\n");
                /*Array(
                    [measurementID] => cf79b546359dea3915383ff3bc583c8c_617_ENV
                    [occurrenceID] => bce4fa8b6c08381b674c545409717a17_617_ENV
                    [measurementOfTaxon] => true
                    [measurementType] => http://purl.obolibrary.org/obo/RO_0002303
                    [measurementValue] => http://purl.obolibrary.org/obo/ENVO_00000067
                    [measurementRemarks] => source text: "thicket a reed-bed a _cave_ or some other sheltered"
                    [source] => http://en.wikipedia.org/w/index.php?title=Lion&oldid=1276469077
                )*/
                $occurrenceID = $rec['occurrenceID'];
                $measurementID = $rec['measurementID'];
                $measurementValue = $rec['measurementValue'];

                if(isset($this->delete_occurrence_ids[$occurrenceID])) { //from evaluate_Occurrence: only include binomials
                    // print_r($rec);
                    $this->delete_measurement_ids[$measurementID] = '';
                }

                if(!self::an_MoF_child_record($rec)) {
                    $ret = self::evaluate_MoF($rec);
                    if($ret == "delete MoF") {
                        $this->delete_occurrence_ids[$occurrenceID] = '';
                        $this->delete_measurement_ids[$measurementID] = '';
                    }
                }
            }
            /* child record in MoF:
                - doesn't have: occurrenceID | measurementOfTaxon
                - has parentMeasurementID
                - has also a unique measurementID, as expected.
            minimum cols on a child record in MoF
                - measurementID
                - measurementType
                - measurementValue
                - parentMeasurementID */

            elseif($what == 'write_Occurrence') { //print_r($rec); exit("\nelix 200\n");
                /*Array(
                    [occurrenceID] => bce4fa8b6c08381b674c545409717a17_617_ENV
                    [taxonID] => Q140
                )*/
                $occurrenceID = $rec['occurrenceID'];
                if(isset($this->delete_occurrence_ids[$occurrenceID])) {}
                else self::write_extension($rec, 'occurrence');
            }
            elseif($what == 'write_MoF') { //processing MoF
                $occurrenceID = $rec['occurrenceID'];
                $measurementID = $rec['measurementID'];
                $measurementValue = $rec['measurementValue'];

                // /* new
                if($katja_uri = @$this->uri_katjauri_map[$measurementValue]) $rec['measurementValue'] = $katja_uri;
                // */

                if(self::an_MoF_child_record($rec)) {
                    $parentMeasurementID = @$rec['parentMeasurementID'];
                    if(isset($this->delete_measurement_ids[$parentMeasurementID])) continue; //don't write
                }
                else {
                    if(isset($this->delete_measurement_ids[$measurementID])) continue; //don't write
                }
                self::write_extension($rec, 'mof');
            }


            /* working OK
            if($what == 'write_archive') {
                $o = new \eol_schema\Taxon();
                $uris = array_keys($rec); // print_r($uris); //exit;
                foreach($uris as $uri) {
                    $field = Functions::get_field_from_uri($uri);
                    $o->$field = $rec[$uri];
                }
                $this->archive_builder->write_object_to_file($o);
            }
            */
            // if($i >= 5) break;
        }
    }
    private function evaluate_MoF($rec)
    {   /*Array(
            [measurementID] => 7ee2407dac4771b5fa5c8c925b5694d6_617_ENV
            [occurrenceID] => da3f17497355258f02ec4798ac4736c3_617_ENV
            [measurementOfTaxon] => true
            [measurementType] => http://purl.obolibrary.org/obo/RO_0002303
            [measurementValue] => http://purl.obolibrary.org/obo/ENVO_01000252
            [measurementRemarks] => source text: "in a tree near _Lake_ Nakuru Lions may live"
            [source] => http://en.wikipedia.org/w/index.php?title=Lion&oldid=1276469077
        )*/
        // http://purl.obolibrary.org/obo/ENVO_01000687	
        // source text: "or raven in Northwest _Coast_ traditions.[97] Retrieved from "https"

        $measurementValue = $rec['measurementValue'];
        // $measurementValue = 'http://purl.obolibrary.org/obo/ENVO_01000687'; //debug only force-assign
        $delete_MoF_YN_1 = false;
        $delete_MoF_YN_2 = false;
        // 1st case
        if(isset($this->uris_with_new_kwords[$measurementValue])) { //included in the list of 15 URIs. Please remove all keywords that currently map to these uris:
            if($match_strings = @$this->uri_in_question[$measurementValue]) { //this uri has a list of acceptable keywords/match_strings
                $measurementRemarks = $rec['measurementRemarks'];
                // $measurementRemarks = 'source text: "in a in this _Lake_ Nakuru Lions may live"'; //debug only force-assigne
                // print_r($rec); print_r($match_strings); echo(" --- huli ka 1\n");
                if(self::is_suggested_keyword_match_YN($measurementRemarks, $match_strings)) {} // echo "\nmatch_string found in mRemarks\n";
                else $delete_MoF_YN_1 = true; //echo " [del MoF 1] "; 
            }
            else $delete_MoF_YN_1 = true; //echo " [del MoF 2] ";
        }
        else { //not the magic 15
            // 2nd case: below block is copied above
            if($match_strings = @$this->uri_in_question[$measurementValue]) { //this uri has a list of acceptable keywords/match_strings
                $measurementRemarks = $rec['measurementRemarks'];
                // $measurementRemarks = 'source text: "in a in this _Lake_ Nakuru Lions may live"'; //debug only force-assigne
                // print_r($rec); print_r($match_strings); echo(" --- huli ka 2\n");
                if(self::is_suggested_keyword_match_YN($measurementRemarks, $match_strings)) {} // echo "\nmatch_string found in mRemarks\n";
                else $delete_MoF_YN_2 = true; //echo " [del MoF 3] ";
            }
            else {} //no acceptable list of keywords; then just ignore it but still accept the MoF record 
        }
        // exit("\neli x\n");
        // ============================================================================ needs to be reviewed...
        if($delete_MoF_YN_1) { //here is the magic 15
            // if($current_uri = @$this->uri_currenturi_map[$measurementValue]) {
            //     if($match_strings = @$this->uri_in_question_current[$current_uri]) { //this uri has a list of acceptable keywords/match_strings
            //         $measurementRemarks = $rec['measurementRemarks'];
            //         if(self::is_suggested_keyword_match_YN($measurementRemarks, $match_strings)) { $rec['measurementValue'] = $current_uri; } // echo "\nmatch_string found in mRemarks\n";
            //         else return "delete MoF";
            //     }
            //     else return "delete MoF";
            // }
            // else return "delete MoF"; //new line
            return "delete MoF";
        }
        // ============================================================================
        if($delete_MoF_YN_2) {
            // if($current_uri = @$this->uri_currenturi_map[$measurementValue]) {
            //     if($match_strings = @$this->uri_in_question_current[$current_uri]) { //this uri has a list of acceptable keywords/match_strings
            //         $measurementRemarks = $rec['measurementRemarks'];
            //         if(self::is_suggested_keyword_match_YN($measurementRemarks, $match_strings)) { $rec['measurementValue'] = $current_uri; } // echo "\nmatch_string found in mRemarks\n";
            //         else return "delete MoF";
            //     }
            //     else {} //no acceptable list of keywords; then just ignore it but still accept the MoF record 
            // }
            // else return "delete MoF";
            return "delete MoF";
        }
        // ============================================================================
        return $rec;
    }
    private function is_suggested_keyword_match_YN($measurementRemarks, $match_strings)
    {
        // echo "\n[$measurementRemarks]\n";
        $measurementRemarks = str_replace("_", "", $measurementRemarks);
        // echo "\n[$measurementRemarks]\n"; //exit;
        
        foreach($match_strings as $str) {
            if(stripos($measurementRemarks, " ".$str) !== false) return true; //string is found
        }
        return false;
    }
    private function an_MoF_child_record($rec)
    {   /* child record in MoF:
            - doesn't have: occurrenceID | measurementOfTaxon
            - has parentMeasurementID
            - has also a unique measurementID, as expected.
        minimum cols on a child record in MoF
            - measurementID
            - measurementType
            - measurementValue
            - parentMeasurementID */
        $occurrenceID = @$rec['occurrenceID'];
        $parentMeasurementID = @$rec['parentMeasurementID'];
        if($parentMeasurementID && !$occurrenceID) return true; //an MoF child record
        else return false;
    }
    private function write_extension($rec, $extension)
    {
        if($extension == 'mof')             $o = new \eol_schema\MeasurementOrFact_specific();
        elseif($extension == 'occurrence')  $o = new \eol_schema\Occurrence();
        $fields = array_keys($rec); // print_r($uris); //exit;
        foreach($fields as $field) {
            $o->$field = $rec[$field];
        }
        $this->archive_builder->write_object_to_file($o);
    }
}
?>