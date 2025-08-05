<?php
namespace php_active_record;
/* Library that will generate CSV files for Neo4j Admin Import utility */
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class GenerateCSV_4Neo4j
{
    function __construct()
    {        
        $this->download_options = array('cache' => 1, 'download_wait_time' => 1000000, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1, 'resource_id' => 26);
        $this->download_options["expire_seconds"] = false;
        $this->debug = array();
    }
    public static function buildup_predicates()
    {
        echo "\nEli is here...\n";   
    }
}
?>