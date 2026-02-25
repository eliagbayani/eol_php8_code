<?php
namespace php_active_record;
/* This lib is all about accesing EOL terms in .yml:
https://raw.githubusercontent.com/EOL/eol_terms/main/resources/terms.yml
-> actual yml file
https://github.com/EOL/eol_terms/blob/main/resources/terms.yml
-> Github entry

1st client: [USDAPlants2019.php] -> called by [727.php] -> [USDAPlants.tmproj]
2nd client: [XenoCantoAPI.php]
3rd client: [WormsArchiveAPI]
4th client*: [Trait spreadsheet to DwC-A Tool] -> [http://localhost/eol_php_code/applications/trait_data_import/] - DID NOT MATERIALIZE
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class EOLterms_ymlAPI
{
    function __construct($archive_builder = false, $resource_id = false)
    {
        $this->download_options = array('cache' => 1, 'expire_seconds' => 60*60*24*1, 'download_wait_time' => 1000000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        // $this->download_options['expire_seconds'] = false; //comment after first harvest
        $this->EOL_terms_yml_url = "https://raw.githubusercontent.com/EOL/eol_terms/main/resources/terms.yml";
    }
    function get_terms_yml($sought_type = 'ALL') //possible values: 'measurement', 'value', 'ALL', 'WoRMS value'
    {                                            //output structure: $final[label] = URI;
        $final = array();
        if($yml = Functions::lookup_with_cache($this->EOL_terms_yml_url, $this->download_options)) { //orig 1 day cache
            $yml .= "alias: ";
            if(preg_match_all("/name\:(.*?)alias\:/ims", $yml, $a)) {
                $arr = array_map('trim', $a[1]);
                foreach($arr as $block) { // echo "\n$block\n"; exit;
                    /*
                     [10713] => verbatim coordinates
                    type: measurement
                    uri: http://rs.tdwg.org/dwc/terms/verbatimCoordinates
                    parent_uris:
                    synonym_of_uri: []
                    units_term_uri:
                    */
                    $rek = array();
                    if(preg_match("/elicha(.*?)\n/ims", "elicha".$block, $a)) $rek['name'] = trim($a[1]);
                    if(preg_match("/type\: (.*?)\n/ims", $block, $a)) $rek['type'] = trim($a[1]);
                    if(preg_match("/uri\: (.*?)\n/ims", $block, $a)) $rek['uri'] = trim($a[1]); //https://eol.org/schema/terms/thallus_length
                    $rek = array_map('trim', $rek);
                    // print_r($rek);
                    /*Array(
                        [name] => compound fruit
                        [type] => value
                        [uri] => https://www.wikidata.org/entity/Q747463
                    )*/
                    $name = self::remove_quote_delimiters($rek['name']);
                    if($sought_type == 'ALL')               $final[$name] = $rek['uri'];
                    elseif($sought_type == 'neo4j') {
                        if(in_array(@$rek['type'], array('measurement', 'association'))) {
                            $final[$name] = array('uri' => $rek['uri'], 'type' => $rek['type']);
                        }
                    }
                    elseif($sought_type == 'neo4j_v2') {
                        if(in_array(@$rek['type'], array('measurement', 'association'))) {
                            $final[$rek['uri']] = array('name' => $name, 'type' => $rek['type']);
                        }
                    }
                    elseif($sought_type == 'ALL_URI')       $final[$rek['uri']] = $name;
                    elseif($sought_type == 'WoRMS value') {
                        if(@$rek['type'] == 'value') $final[$rek['uri']] = $name;
                    }
                    elseif($sought_type == 'ONE_TO_MANY') { //ideal for country names
                        if(substr(strtolower($name),0,4) == 'the ') $name = trim(substr($name, 4, strlen($name)));
                        $final[$name][] = $rek['uri'];
                    }
                    elseif(@$rek['type'] == $sought_type)   $final[$name] = $rek['uri'];
                    @$this->debug['EOL terms type'][@$rek['type']]++; //just for stats
                    /*
                    else {
                        echo "\n-----------------------\n";
                        echo "\n[$block]\n";
                        print_r($rek);
                        exit("\nUndefined sought type: [$sought_type]\n");
                        echo "\n-----------------------\n";
                    }
                    */
                }
            }
            else exit("\nInvestigate: EOL terms file structure had changed.\n");
        }
        else exit("Remote EOL terms (.yml) file not accessible.");
        print_r($this->debug); //just for stats
        return $final;
    } //end get_terms_yml()
    private function remove_quote_delimiters($str)
    {
        // $str = "'123456'"; // $str = '"123456"';
        $str = trim($str); // echo("\norig: [$str]\n");
        $first = substr($str,0,1);
        $last = substr($str, -1); // echo("\n[$first] [$last]\n");
        if($first == "'" && $last == "'") $str = substr($str, 1, strlen($str)-2);
        if($first == '"' && $last == '"') $str = substr($str, 1, strlen($str)-2);
        // exit("\nfinal: [$str]\n");
        return $str;
    }
    function parse_terms_yaml()
    {
        $yaml_string = Functions::lookup_with_cache($this->EOL_terms_yml_url, $this->download_options);
        $array_output = yaml_parse($yaml_string);
        print_r($array_output['terms'][525]);        
    }
    function get_terms_yml_4Neo4j()
    {
        $final = array();
        if($yml = Functions::lookup_with_cache($this->EOL_terms_yml_url, $this->download_options)) { //orig 1 day cache
            $yml .= "alias: ";
            $yml = str_replace("\\r\\n", " ", $yml);

            // Fix for missing "http://eol.org/schema/terms/TrophicGuild"
            $source = "- definition: A group of species that exploit the same food resources";
            $destination = "- attribution: ''
              definition: A group of species that exploit the same food resources";
            $yml = str_replace($source, $destination, $yml);

            // exit("\n$yml\n");
            // if(preg_match_all("/\- attribution\:(.*?)\- attribution\:/ims", $yml, $a)) {
            if(preg_match_all("/\- attribution\:(.*?)alias\:/ims", $yml, $a)) {
                $arr = array_map('trim', $a[1]);
                // print_r($arr); exit("\nstop muna eli\n");
                foreach($arr as $block) { //echo "\n$block\n"; exit;
                    /*''
                    definition: a measure of specific growth rate
                    is_hidden_from_select: false
                    is_hidden_from_overview: false
                    is_hidden_from_glossary: false
                    is_text_only: false
                    name: "%/month"
                    type: value
                    uri: http://eol.org/schema/terms/percentPerMonth
                    parent_uris: []
                    synonym_of_uri:
                    units_term_uri:*/
                    $rek = array();
                    if(preg_match("/uri\: (.*?)\n/ims", $block, $a)) $rek['uri'] = trim($a[1]);     //http://eol.org/schema/terms/percentPerMonth
                    if(preg_match("/name\: (.*?)\n/ims", $block, $a)) $rek['name'] = self::remove_quote_delimiters(trim($a[1]));   //%/month
                    if(preg_match("/type\: (.*?)\n/ims", $block, $a)) $rek['type'] = trim($a[1]);   //"measurement", "association", "value", and "metadata"
                    if(preg_match("/definition\: (.*?)\n/ims", $block, $a)) $rek['definition'] = self::remove_quote_delimiters(trim($a[1]));   //
                    $rek['comment'] = ''; //EOL curator note
                    if(preg_match("/elicha(.*?)\n/ims", "elicha".$block, $a)) $rek['attribution'] = self::remove_quote_delimiters(trim($a[1]));
                    $rek['section_ids'] = ''; //from webpage
                    if(preg_match("/is_hidden_from_overview\: (.*?)\n/ims", $block, $a)) $rek['is_hidden_from_overview'] = trim($a[1]);   //
                    if(preg_match("/is_hidden_from_glossary\: (.*?)\n/ims", $block, $a)) $rek['is_hidden_from_glossary'] = trim($a[1]);   //
                    $rek['position'] = ''; //from webpage
                    $rek['trait_row_count'] = ''; //a periodically calculated (offline) count
                    $rek['distinct_page_count'] = ''; //a periodically calculated (offline) count
                    $rek['exclusive_to_clade'] = ''; //
                    $rek['incompatible_with_clade'] = ''; //
                    $rek['parent_term'] = ''; //
                    $rek['synonym_of'] = ''; //
                    $rek['object_for_predicate'] = ''; //a periodically calculated (offline) count
                    $rek = array_map('trim', $rek);
                    /* Commented since many traits e.g. WoRMS have these URIs.
                    if(stripos($rek['uri'], "marineregions.org") !== false) continue;   //string is found   - filter
                    if(stripos($rek['uri'], "geonames.org") !== false) continue;        //string is found   - filter
                    */
                    $final[] = $rek;
                    // print_r($rek); //exit("\nelix...\n");
                    /*Array(
                        [attribution] => ''
                        [uri] => http://eol.org/schema/terms/percentPerMonth
                        [name] => %/month
                        [type] => value
                        [definition] => a measure of specific growth rate
                        [comment] => 
                        [section_ids] => 
                        [is_hidden_from_overview] => false
                        [is_hidden_from_glossary] => false
                        [position] => 
                        [trait_row_count] => 
                        [distinct_page_count] => 
                        [exclusive_to_clade] => 
                        [incompatible_with_clade] => 
                        [parent_term] => 
                        [synonym_of] => 
                        [object_for_predicate] => 
                    )*/
                    @$this->debug['type'][@$rek['type']] = '';               //just for stats
                    @$this->debug['attribution'][@$rek['attribution']] = ''; //just for stats
                }
            }
            else exit("\nInvestigate: EOL terms file structure had changed.\n");
        }
        else exit("Remote EOL terms (.yml) file not accessible.");
        // print_r($this->debug); //just for stats
        echo "\nTotal term records: ".count($final)."\n";
        return $final;
    } //end get_terms_yml_4Neo4j()
}
?>