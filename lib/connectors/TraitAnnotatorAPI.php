<?php
namespace php_active_record;
/* connector: [annotator.php]
These ff. workspaces work together:
*/
// use \AllowDynamicProperties; //for PHP 8.2
// #[AllowDynamicProperties] //for PHP 8.2
class TraitAnnotatorAPI
{
    var $initializedYN = false;

    var $uri_in_question = array();
    var $uri_in_question_current = array();
    var $uri_katjauri_map = array();
    var $uris_with_new_kwords = array();


    function __construct()
    {
    }
    function initialize()
    {
        echo "\nInitializing...\n";
        $this->initializedYN = true;
        require_library('connectors/TextmineKeywordMapAPI');
        $func = new TextmineKeywordMapAPI();
        $func->get_keyword_mappings();
        $this->uris_with_new_kwords     = $func->uris_with_new_kwords; //n=15
        $this->uri_in_question          = $func->uri_in_question; //print_r($func->uri_in_question); exit;
        $this->uri_in_question_current  = $func->uri_in_question_current;        
        $this->uri_katjauri_map         = $func->uri_katjauri_map;        

        unset($func);
        unset($func->uris_with_new_kwords);
        unset($func->uri_in_question);
        unset($func->uri_in_question_current);
        unset($func->uri_katjauri_map);

        echo "\nuri_in_question 2: ".count($this->uri_in_question);
        echo "\nuri_in_question_current 2: ".count($this->uri_in_question_current);
        echo "\nuri_katjauri_map 2: ".count($this->uri_katjauri_map);
        echo "\nuris_with_new_kwords: ".count($this->uris_with_new_kwords); //print_r($this->uris_with_new_kwords);

        print_r($this->uri_in_question);
        print_r($this->uris_with_new_kwords);

    }
    function annotate($params)
    {
        if($this->initializedYN) echo "\nInitialized OK\n";
        else echo "\nNot yet initialized.\n";
        print_r($params);
        echo "\nStart here...\n";
    }
}
