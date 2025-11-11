<?php
namespace php_active_record;
/* connector: [annotator.php]
These ff. workspaces work together:
*/
// use \AllowDynamicProperties; //for PHP 8.2
// #[AllowDynamicProperties] //for PHP 8.2
class TraitAnnotatorAPI
{
    public $initialized_YN;
    public $keyword_uri, $predicates;
    public $download_options, $results, $debug;
    public $predicates_list;
    function __construct()
    {
        $this->download_options = array(
            'resource_id'        => 'trait_annotator',
            'expire_seconds'     => 60*60*24*1, //1 day cache
            'download_wait_time' => 1000000, 'timeout' => 60*5, 'download_attempts' => 1, 'delay_in_minutes' => 0.5, 'cache' => 1);
        $this->predicates_list = array("behavioral circadian rhythm", "developmental mode", "habitat", "life cycle habit", "mating system", "reproduction", "sexual system"); //from Google spreadsheet
    }
    function annotate($params)
    {
        $this->results = array();
        print_r($params);
        if($val = @$params['predicates']) {
            if($val == 'ALL') $predicates = $this->predicates_list;
            else {
                $predicates = explode(",", $val);
                $predicates = array_map('trim', $predicates);
            }
        }
        else                  exit("\nERROR: Missing predicates.\n");
        if(!@$params['text']) exit("\nERROR: Missing text.\n");

        if($GLOBALS['ENV_DEBUG']) { echo "\nStart here...\n"; echo "\npredicates: "; print_r($predicates); }
        foreach($predicates as $predicate) {
            if(in_array($predicate, $this->predicates_list)) self::process_predicate($predicate, $params['text']);
        }
        if($GLOBALS['ENV_DEBUG']) { echo "\nResults: "; print_r($this->results); }
        $json = json_encode($this->results); 
        echo "\nelix1".$json."elix2\n"; //IMPORTANT step; will use to capture json string from cmdline output.
    }
    private function process_predicate($predicate, $text)
    {
        if(!@$this->initialized_YN[$predicate]) self::initialize($predicate);
        self::parse_text($predicate, $text);
    }
    private function initialize($predicate)
    {
        // /* Latest: our one-place for textmining metadata
        self::initialize_predicate($predicate); //$predicate is 'habitat' or 'mating system', etc.
        // */
    }
    private function parse_text($predicate, $text)
    {
        echo "\nsearching for predicate: [$predicate]\n";
        $keywords = array_keys($this->keyword_uri[$predicate]); //print_r($keywords);
        foreach($keywords as $kw) {
            $ret = self::find_needle_from_haystack($kw, $text, $predicate);
        }
    }
    private function find_needle_from_haystack($needle, $haystack, $predicate)
    {
        $position = strpos($haystack, $needle);
        if ($position !== false) {
            // echo "\nSubstring ($needle) found at position: " . $position; //good debug
            if(!self::boundary_chars_are_valid_YN($position, $needle, $haystack)) return;
            if($URIs = $this->keyword_uri[$predicate][$needle]) {
                foreach($URIs as $uri) {
                    $this->results['data'][] = array('id' => $uri, 'lbl' => $needle, 'context' => self::format_context($needle, $haystack), 
                        'ontology' => $predicate, 'measurementType' => $this->uri_predicate[$uri]);
                }
            }
            else exit("\nERROR: Investigate: No URIs for predicate:[$predicate] | needle:[$needle]\n");
        } else {
            // echo "Substring not found.";
        }
    }
    private function boundary_chars_are_valid_YN($position, $needle, $haystack)
    {
        $haystack = html_entity_decode($haystack);
        $boundary_chars = self::get_boundary_chars($position, $needle, $haystack);
        $leftmost = $boundary_chars['left'];
        $rightmost = $boundary_chars['right'];

        // print_r($boundary_chars);
        if(ctype_digit($leftmost)  || \IntlChar::isalpha($leftmost)  || ctype_alpha($leftmost) ) return false;
        if(ctype_digit($rightmost) || \IntlChar::isalpha($rightmost) || ctype_alpha($rightmost) ) return false;
        return true;
        // ctype_digit($char))      includes: 0-9
        // ctype_alpha($char)       includes: a-z A-A
        // IntlChar::isalpha($char) includes: with diacritimal markings e.g. "é" is still alpha
    }
    private function get_boundary_chars($position, $needle, $haystack)
    {   //left:
        if($position == 0) $leftmost = " ";
        else               $leftmost = mb_substr($haystack, $position - 1, 1);
        //right:
        $length_needle = strlen($needle);        
        $rightmost = mb_substr($haystack, $position + ($length_needle), 1);
        if($GLOBALS['ENV_DEBUG']) {
            echo "\nleftmost is: [$leftmost] from:[$needle][$haystack]\n";
            echo "\nrightmost is: [$rightmost] from[$needle][$haystack]\n";
        }
        return array("left" => $leftmost, "right" => $rightmost);
    }
    private function format_context($needle, $haystack)
    {
        return str_replace($needle, "<b>".$needle."</b>", $haystack);
    }
    private function initialize_predicate($predicate)
    {   
        echo "\nInitializing predicate [$predicate]...";
        require_library('connectors/TextmineKeywordMapAnnotator');
        $func = new TextmineKeywordMapAnnotator();
        $func->get_keyword_mappings($predicate);
        $this->keyword_uri[$predicate] = $func->keyword_uri;
        $this->uri_predicate           = $func->uri_predicate;
        unset($func);
        echo "\nkeyword_uri [$predicate] 2: ".count($this->keyword_uri[$predicate]);

        $this->initialized_YN[$predicate] = true;
    }
    private function loop_csv_file($local_csv)
    {
        $i = 0;
        $file = Functions::file_open($local_csv, "r");
        while(!feof($file)) {
            $row = fgetcsv($file);
            if(!$row) break;
            // $row = self::clean_html($row); // print_r($row); //copied template
            $i++; 
            if($i == 1) {
                $fields = $row;
                // $fields = self::fill_up_blank_fieldnames($fields); //copied template
                $count = count($fields);
            }
            else { //main records
                $values = $row;
                if($count != count($values)) { //row validation - correct no. of columns
                    exit("\nERROR: Wrong CSV format for this row.\n");
                    continue;
                }
                $k = 0;
                $rec = array();
                foreach($fields as $field) {
                    $rec[$field] = $values[$k];
                    $k++;
                }
                $rec = array_map('trim', $rec); // print_r($rec);
                /*Array(
                    [value.name] => herb
                    [value.uri] => http://purl.obolibrary.org/obo/FLOPO_0022142
                )*/
                $match_string = $rec['value.name'];
                $temp[$match_string][] = $rec['value.uri'];
                $temp[$match_string] = array_unique($temp[$match_string]); //make values unique
            } //main records
        }
        fclose($file);
        $this->keyword_uri['growth'] = $temp; //print_r($temp);
        $this->initialized_YN['growth'] = true;
    }
}