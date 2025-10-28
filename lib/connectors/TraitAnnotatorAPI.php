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
    public $keyword_uri, $ontologies;
    public $download_options, $growth_ontology_file, $results;
    function __construct()
    {
        $this->download_options = array(
            'resource_id'        => 'trait_annotator',
            'expire_seconds'     => 60*60*24*1, //1 day cache
            'download_wait_time' => 1000000, 'timeout' => 60*5, 'download_attempts' => 1, 'delay_in_minutes' => 0.5, 'cache' => 1);
        $this->growth_ontology_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/Pensoft_project/ontologies/ver_4/growth_form.csv';
    }
    private function initialize($ontology)
    {
        if($ontology == 'envo') self::initialize_envo_ontology();
        if($ontology == 'growth') self::initialize_growth_ontology();
    }
    function annotate($params)
    {
        $this->results = array();
        print_r($params);
        if($val = @$params['ontologies']) {
            $ontologies = explode(",", $val);
            $ontologies = array_map('trim', $ontologies);
        }
        else exit("\nERROR: Missing ontology.\n");
        echo "\nStart here...\n"; print_r($ontologies);
        foreach($ontologies as $ontology) {
            self::process_ontology($ontology, $params['text']);
        }
        print_r($this->results);
        // $json = json_encode($this->results); echo "\n$json\n";
    }
    private function process_ontology($ontology, $text)
    {
        if(!@$this->initialized_YN[$ontology]) self::initialize($ontology);
        self::parse_text($ontology, $text);
    }
    private function parse_text($ontology, $text)
    {
        echo "\nsearching for ontology: [$ontology]\n";
        $keywords = array_keys($this->keyword_uri[$ontology]); //print_r($keywords); exit;
        foreach($keywords as $kw) {
            $ret = self::find_needle_from_haystack($kw, $text, $ontology);
        }
    }
    private function find_needle_from_haystack($needle, $haystack, $ontology)
    {
        $position = strpos($haystack, $needle);
        if ($position !== false) {
            echo "\nSubstring ($needle) found at position: " . $position;
            $URIs = $this->keyword_uri[$ontology][$needle];
            foreach($URIs as $uri) {
                $this->results['data'][] = array('lbl' => $needle, 'id' => $uri, 'context' => self::format_context($needle, $haystack), 'ontology' => $ontology);
            }
        } else {
            // echo "Substring not found.";
        }
    }
    private function format_context($needle, $haystack)
    {
        return str_replace($needle, "<b>".$needle."</b>", $haystack);
    }
    private function initialize_envo_ontology()
    {
        echo "\nInitializing envo ontology...";
        require_library('connectors/TextmineKeywordMapAnnotate');
        $func = new TextmineKeywordMapAnnotate();
        $func->get_keyword_mappings();
        $this->keyword_uri['envo'] = $func->keyword_uri;
        unset($func);
        echo "\nkeyword_uri envo 2: ".count($this->keyword_uri['envo']);
        $this->initialized_YN['envo'] = true;
    }
    private function initialize_growth_ontology()
    {
        echo "\nInitializing growth ontology...";
        $tmp_file = Functions::save_remote_file_to_local($this->growth_ontology_file, $this->download_options);
        self::loop_csv_file($tmp_file);
        unlink($tmp_file);
        echo "\nkeyword_uri growth 2: ".count($this->keyword_uri['growth'])."\n";
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
                    echo("\nWrong CSV format for this row.\n"); exit;
                    continue;
                }
                $k = 0;
                $rec = array();
                foreach($fields as $field) {
                    $rec[$field] = $values[$k];
                    $k++;
                }
                $rec = array_map('trim', $rec); // print_r($rec); exit;
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