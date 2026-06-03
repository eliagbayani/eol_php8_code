<?php
namespace php_active_record;
/* */
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class DwCA_MatchTaxa2DH_Functions
{
    public $compatibleAncestors_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/EOL/compatibleAncestors.txt';
    // this file comes from Katja's Jupyter Notebook

    function __construct() {}
    function get_compatibleAncestors()
    {
        if($local = Functions::save_remote_file_to_local($this->compatibleAncestors_file, $this->download_options)) {
            $i = 0;
            foreach(new FileIterator($local) as $line_number => $line) {
                $line = trim($line); $i++; 
                if(!$line) break; // Animals; Annelida
                $arr = explode(";", $line);
                $new_line = trim("$arr[1]; $arr[0]");
                $ret[$line] = '';
                $ret[$new_line] = '';
            }
        }
        else exit("\nERROR: compatibleAncestors_file can't be accessed.\n"."\nWill terminate.\n");
        unlink($local);
        return $ret;
    }
    function have_compatibleAncestors($indexGroup1, $indexGroup2)
    {   /*Array(
            [Animals; Annelida] => 
            [Annelida; Animals] => 
            [Animals; Arthropoda] => 
            [Arthropoda; Animals] => */
        $needle = "$indexGroup1; $indexGroup2";
        if(isset($this->compatibleAncestors[$needle])) return true;
        $needle = "$indexGroup2; $indexGroup1";
        if(isset($this->compatibleAncestors[$needle])) return true;
        return false;
    }
}
?>