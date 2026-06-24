<?php
namespace php_active_record;
/* */
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class DwCA_MatchTaxa2DH_Functions
{
    /* the first version used
    public $compatibleAncestors_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/compatibleAncestors.txt';
    this file comes from Katja's Jupyter Notebook
    */
    public $compatibleAncestors_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/Ancestry Index - compatibleAncestors.tsv';

    function __construct() {}
    function get_compatibleAncestors()
    {
        if($local = Functions::save_remote_file_to_local($this->compatibleAncestors_file, $this->download_options)) {
            $i = 0;
            foreach(new FileIterator($local) as $line_number => $line) {
                $line = trim($line); $i++; 
                if(!$line) break; // Animals; Annelida
                // $arr = explode(";", $line); --- old version
                $arr = explode("\t", $line);
                $new_line = trim("$arr[1]; $arr[0]");
                $ret[$line] = '';
                $ret[$new_line] = '';
            }
        }
        else exit("\nERROR: compatibleAncestors_file can't be accessed.\n"."\nWill terminate.\n");
        unlink($local);
        return $ret;
    }
    // function have_compatibleAncestors($indexGroup1, $indexGroup2) //not used anymore...
    function are_the_IndexValues_compatible($index_values)
    {   
        $index_values = array_unique($index_values); //make unique
        $index_values = array_values($index_values); //reindex key
        if(count($index_values) == 1) return true;
        if(count($index_values) > 2) exit("\nWill terminate: more than 2 index_values!\n");
        $indexGroup1 = $index_values[0];
        $indexGroup2 = $index_values[1];
        if($indexGroup1 == $indexGroup2) return true;
        /*Array(
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
    function get_rightmost($pattern)
    {
        // e.g. .*?\|Hexapoda\|(.*?\|)?Pterygota\|.*?
        // e.g. .*?\|Odonata\|.*?
        $pattern = trim(str_replace(".*?", "", $pattern));
        $arr = explode("|", $pattern);
        $arr = array_filter($arr); //remove null arrays
        $lastItem = end($arr);
        return str_replace(array("|", ")", "?", "\\"), "", $lastItem);
    }
    function get_inner_array_with_greatest_posOfLastItem($nestedArray)
    {   /* Given this nexted array:
        Array(
            [0] => Array(
                    [IndexGroup] => Insecta
                    [IndexHC] => .*?\|Hexapoda\|(.*?\|)?Pterygota\|.*?
                    [lastItem_in_IndexHC] => Pterygota
                    [posOfLastItem] => 4
                )
            [1] => Array(
                    [IndexGroup] => Odonata
                    [IndexHC] => .*?\|Odonata\|.*?
                    [lastItem_in_IndexHC] => Odonata
                    [posOfLastItem] => 5
                )
        )
        I need to get the array with the greatest [posOfLastItem]
        Array(
            [IndexGroup] => Odonata
            [IndexHC] => .*?\|Odonata\|.*?
            [lastItem_in_IndexHC] => Odonata
            [posOfLastItem] => 5
        ) */
        // 1. Extract just the 'posOfLastItem' values into a flat array
        $scores = array_column($nestedArray, 'posOfLastItem');
        // 2. Find the key corresponding to the maximum score
        $maxKey = array_search(max($scores), $scores);
        // 3. Retrieve the full inner array
        $highestArray = $nestedArray[$maxKey];
        return $highestArray;
    }
}
?>