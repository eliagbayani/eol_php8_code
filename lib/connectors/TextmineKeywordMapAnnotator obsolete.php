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
    public $local_textmine_strings, $new_file;
    function __construct()
    {   exit("\nObsolete TextmineKeywordMapAnnotator. Will terminate.\n");
        /* worksheet: [mapped strings]
        https://docs.google.com/spreadsheets/d/1sK-rGa1l1jQ7-ui5BXI3-44NVHS00E-ErsGyaGVficA/edit?gid=0#gid=0        
        */
        $params['spreadsheetID'] = '1sK-rGa1l1jQ7-ui5BXI3-44NVHS00E-ErsGyaGVficA';
        $params['expire_seconds'] = 0; //60*60*24*1; //1 day cache is ideal OK //this is working as intended, functional OK!

        $params['range'] = 'mapped strings!A1:E1400'; //where "A" is the starting column, "E" is the ending column, and "1" is the starting row.
        $this->params['mapped strings'] = $params;

        require_library('connectors/GoogleClientAPI');
        $this->func = new GoogleClientAPI();

        require_library('connectors/LocalTextmineKeywordMapAnnotator');
        $func = new LocalTextmineKeywordMapAnnotator(); //uses a local TSV file based from the orig Google Spreadsheet.
        $this->local_textmine_strings = $func->local_textmine_strings;
        echo("\nlocal_textmine_strings: [$this->local_textmine_strings]\n");
        /* local_textmine_strings: [/var/www/html/eol_php8_code/update_resources/connectors/files/Textmining_Strings_-_mapped_strings.tsv] */
        // $this->new_file = str_replace("strings.tsv", "strings_new.tsv", $this->local_textmine_strings); //didn't have a temp file anymore
        $this->new_file = $this->local_textmine_strings;

    }
    function refresh_local_textmining_strings()
    {
        $params = $this->params['mapped strings'];
        $arr = $this->func->access_google_sheet($params);
        echo "\nTotal rows: [".count($arr)."]\n";
        self::massage_result($arr);
    }
    private function massage_result($arr)
    {   //start massage array
        $WRITE = fopen($this->new_file, "w");
        $fields = array('string', 'value', 'value uri', 'predicate', 'predicate uri');
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