<?php
namespace php_active_record;
/* 
This reads the final source of textmine keyword mapping metadata from ticket #37: https://github.com/EOL/ContentImport/issues/37
This reads a local TSV file generated from the orig Google Spreadsheet.

task: trait annotation
Worksheets related:
- TraitAnnotator
*/
class LocalTextmineKeywordMapAnnotator
{
    public $params; // Declare the property
    public $local_textmine_strings;
    public $keyword_uri, $uri_predicate;
    function __construct()
    {   
        $this->local_textmine_strings = DOC_ROOT . 'update_resources/connectors/files/Textmining_Strings_-_mapped_strings.tsv';
    }
    function get_keyword_mappings($sought_predicate)
    {
        $i = 0;
        foreach(new FileIterator($this->local_textmine_strings) as $line_number => $line) {
            $line = explode("\t", $line); $i++; 
            if($i == 1) $fields = $line;
            else {
                if(!$line[0]) break;
                $rek = array(); $k = 0;
                foreach($fields as $fld) {
                    $rek[$fld] = $line[$k]; $k++;
                } 
                $rek = array_map('trim', $rek);
                // print_r($rek); //exit;
                /*Array( only these first 5 columns are relevant
                    [string] => crepuscular
                    [value] => crepuscular
                    [value uri] => http://purl.obolibrary.org/obo/ECOCORE_00000078
                    [predicate] => behavioral circadian rhythm
                    [predicate uri] => http://purl.obolibrary.org/obo/VT_0001502
                )*/

                $predicate = @$rek['predicate'];
                if($sought_predicate == 'ALL') {}
                elseif(!$sought_predicate) {} //sought_predicate is false or blank ''
                else {
                    if($sought_predicate != $predicate) continue;
                }
                
                $string = @$rek['string'];
                $value_uri = @$rek['value uri'];
                $predicate_uri = @$rek['predicate uri'];
                if($value_uri && $string) {
                    $this->keyword_uri[$string][] = $value_uri;
                    $this->keyword_uri[$string] = array_unique($this->keyword_uri[$string]); //make values unique
                    $this->uri_predicate[$value_uri] = $predicate_uri;
                }
            }
        }
        // if(isset($this->keyword_uri)) {
            echo "\nkeyword_uri 1: ".count($this->keyword_uri); // print_r($this->keyword_uri);
            echo "\nuri_predicate 1: ".count($this->uri_predicate); // print_r($this->uri_predicate);
        // }
    }
}
?>