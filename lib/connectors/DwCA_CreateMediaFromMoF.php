<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from: dwca_create_Media_from_MoF.php]
Related Workspaces:
- AntWeb_Traits.code-workspace
- DwCA_CreateMediaFromMoF.code-workspace
*/
class DwCA_CreateMediaFromMoF
{
    function __construct($archive_builder, $resource_id)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        $this->download_options = array('cache' => 1, 'resource_id' => $resource_id, 'expire_seconds' => 60*60*24*30*4, 'download_wait_time' => 1000000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        // $this->download_options['expire_seconds'] = false; //comment after first harvest
        $this->debug = array();
        $this->class_name = 'DwCA_CreateMediaFromMoF';
    }
    function start($info)
    {   echo "\n$this->class_name...\n";
        $tables = $info['harvester']->tables;
        print_r(array_keys($tables));
        
        if($this->resource_id == '24_legacy_onwards1') { //AntWeb
            if($meta = @$tables['http://eol.org/schema/media/document'][0])             self::process_extension($meta, 'append_media_objects');
            if($meta = @$tables['http://rs.tdwg.org/dwc/terms/occurrence'][0])          self::process_extension($meta, 'build_occurrenceID_taxonID_info');
            if($meta = @$tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0])   self::process_extension($meta, 'loop_then_write_2media');
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
            if($what == 'append_media_objects') { //carry-over if Media extension exists
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
                self::proceed_2write($rec, 'document');
            }
            if($what == 'build_occurrenceID_taxonID_info') {
                $this->occurrenceID_taxonID_info[$rec['occurrenceID']] = $rec['taxonID'];
            }
            if($what == 'loop_then_write_2media') { //this is MoF
                /*Array( AntWeb
                    [http://rs.tdwg.org/dwc/terms/measurementID] => bb95b45e06000d2bb272cd7b7a7234c0_24
                    [http://rs.tdwg.org/dwc/terms/occurrenceID] => 7077ef3769c1656b97ffe4c585bc2ce5_24
                    [http://eol.org/schema/measurementOfTaxon] => true
                    [http://rs.tdwg.org/dwc/terms/measurementType] => http://purl.obolibrary.org/obo/RO_0002303
                    [http://rs.tdwg.org/dwc/terms/measurementValue] => http://eol.org/schema/terms/wet_forest
                    [http://rs.tdwg.org/dwc/terms/measurementRemarks] => lowland wet forest
                    [http://purl.org/dc/terms/source] => https://www.antweb.org/description.do?genus=wasmannia&species=rochai&rank=species&project=allantwebants
                    [http://purl.org/dc/terms/bibliographicCitation] => AntWeb. Version 8.45.1. California Academy of Science, online at https://www.antweb.org. Accessed 15 November 2024.
                )*/
                if($taxonID = $this->occurrenceID_taxonID_info[$rec['occurrenceID']]) {
                    $identifier = md5(json_encode($rec));
                    $s = array();
                    $s['identifier'] = $identifier;
                    $s['taxonID'] = $taxonID;
                    $s['type'] = 'http://purl.org/dc/dcmitype/Text';
                    $s['format'] = 'text/html';
                    $s['CVterm'] = 'http://rs.tdwg.org/ontology/voc/SPMInfoItems#Description';
                    $s['title'] = 'From MoF measurementRemarks';
                    $s['description'] = $rec['measurementRemarks'];
                    $s['furtherInformationURL'] = $rec['source'];
                    $s['language'] = 'en';
                    $s['UsageTerms'] = 'http://creativecommons.org/licenses/by-nc-sa/4.0/';
                    $s['Owner'] = ''; //California Academy of Sciences
                    $s['bibliographicCitation'] = $rec['bibliographicCitation'];
                    $s['accessURI'] = '';
                    $s['CreateDate'] = '';
                    $s['agentID'] =  '';
                    self::proceed_2write($s, 'document');                    
                }
                else exit("\noccurrenceID in MoF not found in occurrences: [".$rec['occurrenceID']."\n");                
            }
            // =======================================================================================================
            // =======================================================================================================
            // =======================================================================================================
            // =======================================================================================================
            /* copied template
            elseif($what == 'Rotifer_round_1') {
                if($class == 'MoF') {
                    if($measurementValue == 'http://eol.org/schema/terms/littoralGlacialSand') { // print_r($rec); exit("\nstop 1\n");
                        $values = array('http://purl.obolibrary.org/obo/ENVO_01000017', 'http://eol.org/schema/terms/littoralZone');
                        foreach($values as $value) {
                            $rec['http://rs.tdwg.org/dwc/terms/measurementValue'] = $value;
                            $o = new \eol_schema\MeasurementOrFact_specific();
                            $uris = array_keys($rec); //print_r($uris); exit("\ndito eli\n");
                            foreach($uris as $uri) {
                                $field = pathinfo($uri, PATHINFO_BASENAME);
                                $o->$field = $rec[$uri];
                            }
                            $old_mID = $o->measurementID;
                            $o->measurementID = Functions::generate_measurementID($o, '726'); //$this->resource_id
                            $this->old_new_mID[$old_mID] = $o->measurementID;
                            $this->archive_builder->write_object_to_file($o);
                        }
                    }
                    else self::proceed_2write($rec, $class);
                }                
            }
            elseif($what == 'Rotifer_round_2') {
                if($class == 'MoF') {
                    if($parent_id = $rec['http://eol.org/schema/parentMeasurementID']) {
                        if($new_parent_id = @$this->old_new_mID[$parent_id]) $rec['http://eol.org/schema/parentMeasurementID'] = $new_parent_id;
                    }
                    self::proceed_2write($rec, $class);
                }                
            }
            */
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