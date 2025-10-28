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
    function __construct()
    {
    }
    function initialize()
    {
        echo "\nInitialize...\n";
        $this->initializedYN = true;
    }
    function annotate($params)
    {
        print_r($params);
        echo "\nStart here...\n";
        if($this->initializedYN) echo "\nInitialized OK\n";
        else echo "\nNot yet initialized.\n";
    }
}
