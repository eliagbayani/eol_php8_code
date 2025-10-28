<?php
namespace php_active_record;
/* connector: [annotator.php]
These ff. workspaces work together:
*/
// use \AllowDynamicProperties; //for PHP 8.2
// #[AllowDynamicProperties] //for PHP 8.2
class TraitAnnotatorAPI
{
    function __construct()
    {

    }
    function annotate($params)
    {
        print_r($params);
        echo "\nStart here...\n";
    }
}
