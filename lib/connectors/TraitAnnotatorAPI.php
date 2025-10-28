<?php
namespace php_active_record;
/* connector: [annotator.php]
These ff. workspaces work together:
*/
// use \AllowDynamicProperties; //for PHP 8.2
// #[AllowDynamicProperties] //for PHP 8.2
class TraitAnnotatorAPI
{
    // var $uri_in_question = array();
    // var $uri_in_question_current = array();
    // var $uri_katjauri_map = array();
    // var $uris_with_new_kwords = array();
    public $initializedYN;
    public $keyword_uri, $ontologies;

    function __construct()
    {
        $this->growth_ontology_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/Pensoft_project/ontologies/ver_4/growth_form.csv';
    }
    function initialize($ontology)
    {
        if($ontology == 'envo') self::initialize_envo();
        if($ontology == 'growth') self::initialize_growth();
    }
    function annotate($params)
    {
        if($this->initializedYN) echo "\nInitialized OK\n";
        else echo "\nNot yet initialized.\n";
        print_r($params);
        if($val = @$params['ontologies']) {
            $ontologies = explode(",", $val);
            print_r($ontologies);
        }
        else exit("\nERROR: Missing ontology.\n");
        echo "\nStart here...\n";
        foreach($ontologies as $ontology) {
            self::process_ontology($ontology);
        }
    }
    private function process_ontology($ontology)
    {
        if(!$this->initialized_YN[$ontology]) self::initialize($ontology);
    }
    private function initialize_envo()
    {
        echo "\nInitializing envo...\n";
        $this->initialized_YN['envo'] = true;
        require_library('connectors/TextmineKeywordMapAnnotate');
        $func = new TextmineKeywordMapAnnotate();
        $func->get_keyword_mappings();
        $this->keyword_uri['envo'] = $func->keyword_uri;
        unset($func);
        echo "\nkeyword_uri envo 2: ".count($this->keyword_uri['envo']);
    }
    private function initialize_growth()
    {

    }
}
