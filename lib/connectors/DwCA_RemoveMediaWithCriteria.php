<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from: dwca_remove_Media_with_criteria.php]
Related Workspaces:
- AntWeb_Traits.code-workspace
- DwCA_CreateMediaFromMoF.code-workspace
- DwCA_RemoveMediaWithCriteria.code-workspace
*/
class DwCA_RemoveMediaWithCriteria
{
    function __construct($archive_builder, $resource_id)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        $this->download_options = array('cache' => 1, 'resource_id' => $resource_id, 'expire_seconds' => 60*60*24*30*4, 'download_wait_time' => 1000000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        // $this->download_options['expire_seconds'] = false; //comment after first harvest
        $this->debug = array();
        $this->class_name = 'DwCA_RemoveMediaWithCriteria';
    }
    function start($info)
    {   echo "\n$this->class_name...\n";
        $tables = $info['harvester']->tables;
        print_r(array_keys($tables));
        
        if($this->resource_id == 'AntWeb_ENV_4') { //AntWeb
            if($meta = @$tables['http://eol.org/schema/media/document'][0]) self::process_extension($meta, 'write_media');
        }
        else exit("\nResource ID not initialized [from: $this->class_name][$this->resource_id]\n");
    }
    private function process_extension($meta, $what)
    {   //print_r($meta);
        echo "\nprocess_extension [$what]...$this->class_name...\n"; $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) {
            $i++; if(($i % 100000) == 0) echo "\n".number_format($i);
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                $field['term'] = self::small_field($field['term']);
                // /* some fields have '#', e.g. "http://schemas.talis.com/2005/address/schema#localityName"
                $parts = explode("#", $field['term']);
                if($parts[0]) $field = $parts[0];
                if(@$parts[1]) $field = $parts[1];
                // */
                if(!$field) continue;
                $rec[$field] = $tmp[$k];
                $k++;
            } //print_r($rec); exit;
            //===========================================================================================================================================================
            if($what == 'write_media') {
                /*Array(
                    [identifier] => acanthognathus_brevicornis_TaxHis
                    [taxonID] => acanthognathus_brevicornis
                    [type] => http://purl.org/dc/dcmitype/Text
                    [format] => text/html
                    [CVterm] => http://rs.tdwg.org/ontology/voc/SPMInfoItems#Description
                    [title] => Taxonomic History
                    [description] => <i>Acanthognathus brevicornis</i> <a title="Smith, M. R. 1944c. A key to the genus Acanthognathus Mayr, with the description of a...
                    [furtherInformationURL] => https://www.antweb.org/description.do?genus=acanthognathus&species=brevicornis&rank=species&project=allantwebants
                    [language] => en
                    [UsageTerms] => http://creativecommons.org/licenses/by-nc-sa/4.0/
                    [Owner] => California Academy of Sciences
                    [bibliographicCitation] => AntWeb. Version 8.45.1. California Academy of Science, online at https://www.antweb.org. Accessed 15 November 2024.
                    [accessURI] => 
                    [CreateDate] => 
                    [agentID] => 
                )*/
                if($rec['title'] != 'From MoF measurementRemarks') self::proceed_2write($rec, 'document');
            }
            // =======================================================================================================
            // =======================================================================================================
        }
    }
    private function proceed_2write($rec, $class)
    {
        if($class == "document")        $o = new \eol_schema\MediaResource();        
        // elseif($class == 'MoF')         $o = new \eol_schema\MeasurementOrFact_specific();
        // elseif($class == 'occurrence')  $o = new \eol_schema\Occurrence_specific();
        // elseif($class == 'reference')   $o = new \eol_schema\Reference();
        else exit("\nclass not defined [$class].\n");

        $uris = array_keys($rec); //print_r($uris); exit("\ndito eli\n");
        foreach($uris as $uri) {
            $field = pathinfo($uri, PATHINFO_BASENAME);
            $o->$field = $rec[$uri];
        }
        $this->archive_builder->write_object_to_file($o);
    }
    private function small_field($uri)
    {
        return pathinfo($uri, PATHINFO_FILENAME);
    }
}
?>