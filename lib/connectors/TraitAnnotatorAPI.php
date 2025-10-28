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

    // var $uri_in_question = array();
    // var $uri_in_question_current = array();
    // var $uri_katjauri_map = array();
    // var $uris_with_new_kwords = array();
    public $keyword_uri;

    function __construct()
    {
    }
    function initialize()
    {
        echo "\nInitializing...\n";
        $this->initializedYN = true;
        require_library('connectors/TextmineKeywordMapAnnotate');
        $func = new TextmineKeywordMapAnnotate();
        $func->get_keyword_mappings();
        $this->keyword_uri     = $func->keyword_uri;
        unset($func);
        echo "\nkeyword_uri 2: ".count($this->keyword_uri);
        print_r($this->keyword_uri);
    }
    function annotate($params)
    {
        if($this->initializedYN) echo "\nInitialized OK\n";
        else echo "\nNot yet initialized.\n";
        print_r($params);
        echo "\nStart here...\n";
    }
}
