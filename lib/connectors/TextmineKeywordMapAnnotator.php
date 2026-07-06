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
    public $local_textmine_strings, $destination_file;
    function __construct($what) //still being used by: [update_local_textmining_strings.php]
    {
        require_library('connectors/GoogleClientAPI');
        $this->func = new GoogleClientAPI();

        if($what == 'mapped_strings') {
            require_library('connectors/LocalTextmineKeywordMapAnnotator');
            $func = new LocalTextmineKeywordMapAnnotator(); //uses a local TSV file based from the orig Google Spreadsheet.
            $this->destination_file = $func->local_textmine_strings;
            echo("\n destination_file: [$this->destination_file]\n");
            /* destination_file: [/var/www/html/eol_php8_code/update_resources/connectors/helpers/Textmining_Strings_-_mapped_strings.tsv] */
        }
        elseif($what == 'AncestryIndex_new') {
            $this->destination_file = DOC_ROOT . '../cp_new/neo4j_tasks/AncestryIndex_new.tsv';
        }
        elseif($what == 'AncestryIndex_compatibleAncestors') {
            $this->destination_file = DOC_ROOT . '../cp_new/neo4j_tasks/AncestryIndex_compatibleAncestors.tsv';
        }
        else exit("\nERROR: Item to process not initialized.\n");
    }
    function refresh_local_file_using_GoogleSheet($params)
    {
        $arr = $this->func->access_google_sheet($params);
        echo "\nTotal rows: [".count($arr)."]\n";
        self::massage_result($arr, $params['fields']);
    }
    private function massage_result($arr, $fields)
    {   //start massage array
        $WRITE = fopen($this->destination_file, "w");
        fwrite($WRITE, implode("\t", $fields)."\n");
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
                // start write
                $values = array();
                foreach($fields as $field) {
                    $values[] = $rek[$field];
                }
                fwrite($WRITE, implode("\t", $values)."\n");
            }
        } //end foreach()
        fclose($WRITE);
    }
}
?>