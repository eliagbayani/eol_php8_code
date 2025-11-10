<?php
namespace php_active_record;
/* copied from TextmineKeywordMapAnnotate.php

This reads the final source of textmine keyword mapping metadata from ticket #37: https://github.com/EOL/ContentImport/issues/37
This is latest as of 10Nov2025.

task: trait annotation
Worksheets related:
- TraitAnnotator
*/
class TextmineKeywordMapAnnotator
{
    public $params; // Declare the property
    public $func;
    public $keyword_uri;
    function __construct()
    {   /* worksheet: [mapped strings]
        https://docs.google.com/spreadsheets/d/1sK-rGa1l1jQ7-ui5BXI3-44NVHS00E-ErsGyaGVficA/edit?gid=0#gid=0        
        */
        $params['spreadsheetID'] = '1sK-rGa1l1jQ7-ui5BXI3-44NVHS00E-ErsGyaGVficA';
        $params['expire_seconds'] = 60*60*24*1; //1 day cache is ideal OK

        $params['range'] = 'mapped strings!A1:E1400'; //where "A" is the starting column, "E" is the ending column, and "1" is the starting row.
        $this->params['mapped strings'] = $params;

        require_library('connectors/GoogleClientAPI');
        $this->func = new GoogleClientAPI();
    }
    function get_keyword_mappings($item = false)
    {
        if(!$item) $items = array('mapped strings'); //array('river', 'lake', 'mountain', 'coastal');
        else       $items = array($item);
        foreach($items as $what) { echo "\nAccessing [$what]...";
            $params = $this->params[$what]; //$what e.g. 'coastal'
            $arr = $this->func->access_google_sheet($params);
            self::massage_result($arr, $what);
        }
        echo "\nkeyword_uri 1: ".count($this->keyword_uri); // print_r($this->keyword_uri);
    }
    private function massage_result($arr, $what)
    {   //start massage array
        $i = 0;
        foreach($arr as $item) { $i++;
            if($i == 1) $fields = $item;
            else {
                $rek = array(); $k = 0;
                foreach($fields as $fld) {
                    if($fld) $rek[$fld] = @$item[$k];
                    $k++;
                }
                @$ctr++;
                $rek['new_uid'] = "NEW_".$ctr;
                // $rek = array_map('trim', $rek); //works ok but needs all sheet columns filled up. Replaced by array_map_eol() below.
                $rek = Functions::array_map_eol($rek); //print_r($rek); exit("\n[]\nstop muna\n");
                /*Array(
                    [string] => a montane species
                    [value] => montane
                    [value uri] => https://www.wikidata.org/entity/Q1141462
                    [predicate] => habitat
                    [predicate uri] => http://purl.obolibrary.org/obo/RO_0002303
                    [new_uid] => NEW_1
                )*/
                $string = @$rek['string'];
                $value_uri = @$rek['value uri'];
                if($value_uri && $string) {
                    $this->keyword_uri[$string][] = $value_uri;
                    $this->keyword_uri[$string] = array_unique($this->keyword_uri[$string]); //make values unique
                }
            }
        }
        // end massage array
    }
    function xxx($params) 
    {   
        require_library('connectors/GoogleClientAPI');
        $func = new GoogleClientAPI(); //get_declared_classes(); will give you how to access all available classes
        $params = $this->params['coastal'];
        $arr = $func->access_google_sheet($params); 
        /* IMPORTANT:
        if 1st param has $params['expire_seconds'], will follow value accordingly
        else
            2nd param if blank, will use cache
            2nd param if true, will use cache
            2nd param if false, will re-access remote
        */

        //start massage array
        $i = 0;
        foreach($arr as $item) { $i++;
            if($i == 1) $fields = $item;
            else {
                $rek = array(); $k = 0;
                foreach($fields as $fld) {
                    if($fld) $rek[$fld] = @$item[$k];
                    $k++;
                }
                @$ctr++;
                $rek['new_uid'] = "NEW_".$ctr;
                $rek = array_map('trim', $rek);
                print_r($rek); exit;
            }
        }
        // end massage array
        /* copied template
        $source = "/Volumes/AKiTiO4/d_w_h/last_smasher/test/taxonomy.tsv"; //results_2021_02_24.zip
        $destination = "/Volumes/AKiTiO4/d_w_h/last_smasher/test/final_taxonomy.tsv";
        $WRITE = Functions::file_open($destination, "w");
        $i = 0;
        foreach(new FileIterator($source) as $line => $row) { $i++;
            $rek = array_map('trim', $rek);
        }
        */
    }
}
?>