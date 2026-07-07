<?php
namespace php_active_record;
/* 
This reads the final source of textmine keyword mapping metadata from ticket #37: https://github.com/EOL/ContentImport/issues/37
This reads a local TSV file generated from the orig Google Spreadsheet.

task: trait annotation
Worksheets related:
- TraitAnnotator
- UpdateLocalTextminingStrings
*/
class LocalTextmineKeywordMapAnnotator
{
    public $params; // Declare the property
    public $local_textmine_strings, $mapped_strings_file, $download_options;
    public $keyword_uri, $uri_predicate;
    function __construct($download_options = array())
    {   
        $this->local_textmine_strings = DOC_ROOT . '../cp_new/neo4j_tasks/Textmining_Strings_-_mapped_strings.tsv';
        $this->mapped_strings_file = "https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/Textmining_Strings_-_mapped_strings.tsv";
        $this->download_options = $download_options;
    }
    function get_keyword_mappings($sought_predicate)
    {
        // /* We can comment this if it is being called multiple times. But for now we leave as is.
        print_r($this->download_options); echo " -> check if download_options was passed correctly.\n -> sought_predicate: [$sought_predicate]\n";
        // */
        if($local = Functions::save_remote_file_to_local($this->mapped_strings_file, $this->download_options)) {}
        else exit("\nERROR: mapped_strings_file can't be accessed.\n"."\nWill terminate.\n");
        $i = 0;
        foreach(new FileIterator($local) as $line_number => $line) {
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
                self::do_assign($string, $value_uri, $predicate_uri);

                // /* new: plural form
                $new_string = self::get_plural_of_string($string);
                if($new_string != $string) self::do_assign($new_string, $value_uri, $predicate_uri);
                // */
            }
        }
        unlink($local);
        // if(isset($this->keyword_uri)) {
            echo "\nkeyword_uri 1: ".count($this->keyword_uri); // print_r($this->keyword_uri);
            echo "\nuri_predicate 1: ".count($this->uri_predicate); // print_r($this->uri_predicate);
        // }
    }
    private function do_assign($string, $value_uri, $predicate_uri)
    {
        if($value_uri && $string) {
            $this->keyword_uri[$string][] = $value_uri;
            $this->keyword_uri[$string] = array_unique($this->keyword_uri[$string]); //make values unique
            $this->uri_predicate[$value_uri] = $predicate_uri;
        }
    }
    private function get_plural_of_string($string)
    {
        $new_string = $string;
        $lastChar = strtolower(substr($string, -1));
        if($lastChar != 's') $new_string .= "s";
        return $new_string;
    }
}
?>