<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from first client: dwca_MoF_reassign_values.php]

Purpose of this API is to reassign MoF values.
First client for MADtraits: from this ticket: https://github.com/EOL/ContentImport/issues/28 (Tweak for MADtraits)
    for records where measurementValue=
        http://eol.org/schema/terms/lecithotrophic
        OR
        http://eol.org/schema/terms/planktotrophic
    Please change measurementType   from:   http://eol.org/schema/terms/TrophicGuild
                                    TO:     http://eol.org/schema/terms/MarineLarvalDevelopmentStrategy

2nd group of clients from: https://github.com/EOL/ContentImport/issues/34#event-19122939107 (Misc terms mappings)
*/
class DWCA_Measurements_ReassignValuesAPI
{
    function __construct($archive_builder, $resource_id)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        $this->download_options = array('cache' => 1, 'resource_id' => $resource_id, 'expire_seconds' => 60*60*24*30*4, 'download_wait_time' => 1000000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        // $this->download_options['expire_seconds'] = false; //comment after first harvest
        $this->debug = array();
    }
    function start($info)
    {   echo "\nDWCA_Measurements_ReassignValuesAPI...\n";
        $tables = $info['harvester']->tables;
        if($this->resource_id == 'natdb_temp_1') { //MADtraits
            self::process_extension($tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0], 'MoF', 'write_MADtraits');
        }
        elseif($this->resource_id == 'TreatmentBank_adjustment_04') { //TreatmentBank
            self::process_extension($tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0], 'MoF', 'write_TreatmentBank');
        }
        elseif($this->resource_id == 'polytraits_new') { //Polytraits
            self::process_extension($tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0], 'MoF', 'write_Polytraits');
        }
        elseif($this->resource_id == '726_meta_recoded_01') { //Rotifer World Catalog
            self::process_extension($tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0], 'MoF', 'Rotifer_round_1');
            self::save_array_2json_textfile($this->old_new_mID, CONTENT_RESOURCE_LOCAL_PATH.'/Rotifer_temp_mID.json');
        }
        elseif($this->resource_id == '726_meta_recoded_02') { //Rotifer World Catalog
            $this->old_new_mID = self::retrive_json_textfile_2array(CONTENT_RESOURCE_LOCAL_PATH.'/Rotifer_temp_mID.json');
            self::process_extension($tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0], 'MoF', 'Rotifer_round_2');
        }
        else exit("\nResource ID not initialized [from: DWCA_Measurements_ReassignValuesAPI][$this->resource_id]\n");
    }
    private function process_extension($meta, $class, $what)
    {   //print_r($meta);
        echo "\nprocess_extension [$class][$what]...DWCA_Measurements_ReassignValuesAPI...\n"; $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) {
            $i++; if(($i % 100000) == 0) echo "\n".number_format($i);
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                // /* some fields have '#', e.g. "http://schemas.talis.com/2005/address/schema#localityName"
                $parts = explode("#", $field['term']);
                if($parts[0]) $field = $parts[0];
                if(@$parts[1]) $field = $parts[1];
                // */
                if(!$field) continue;
                $rec[$field] = $tmp[$k];
                $k++;
            } //print_r($rec); exit;
            /*Array(
                [http://rs.tdwg.org/dwc/terms/measurementID] => M315930
                [http://rs.tdwg.org/dwc/terms/occurrenceID] => CT100000
                [http://eol.org/schema/measurementOfTaxon] => true
                [http://eol.org/schema/parentMeasurementID] => 
                [http://rs.tdwg.org/dwc/terms/measurementType] => http://eol.org/schema/terms/Present
                [http://rs.tdwg.org/dwc/terms/measurementValue] => http://www.geonames.org/6252001
                [http://purl.org/dc/terms/source] => https://www.gbif.org/occurrence/map?taxon_key=9576216&geometry=POLYGON((-90.706%2029.151%2C%20-122.761%2047.269%2C%20-75.09%2038.321%2C%20-81.461%2030.757%2C%20-90.706%2029.151%2C%20-90.706%2029.151))
                [http://purl.org/dc/terms/contributor] => Compiler: Anne E Thessen
                [http://eol.org/schema/reference/referenceID] => R01|R02
            )*/
            //===========================================================================================================================================================
            //===========================================================================================================================================================
            if($class == 'MoF') {
                $measurementValue = $rec['http://rs.tdwg.org/dwc/terms/measurementValue'];
                $measurementType = $rec['http://rs.tdwg.org/dwc/terms/measurementType'];
            }
            // =======================================================================================================
            if($what == 'build-up') {
                if($class == 'MoF') {
                    $this->measurementIDs[$rec['http://rs.tdwg.org/dwc/terms/measurementID']] = '';
                }
            }
            // =======================================================================================================
            elseif($what == 'write_MADtraits') {
                if($class == 'MoF') {
                    if(in_array($measurementValue, array('http://eol.org/schema/terms/lecithotrophic', 'http://eol.org/schema/terms/planktotrophic'))) {
                        if($measurementType == 'http://eol.org/schema/terms/TrophicGuild') $rec['http://rs.tdwg.org/dwc/terms/measurementType'] = 'http://eol.org/schema/terms/MarineLarvalDevelopmentStrategy';
                        else                                                               $rec['http://rs.tdwg.org/dwc/terms/measurementType'] = 'http://eol.org/schema/terms/MarineLarvalDevelopmentStrategy'; //assign it anyway
                    }                    
                }
                self::proceed_2write($rec, $class);
            }
            // =======================================================================================================
            elseif($what == 'write_TreatmentBank') {
                if($class == 'MoF') {
                    if($measurementType == 'http://purl.obolibrary.org/obo/ENVO_09200008') $rec['http://rs.tdwg.org/dwc/terms/measurementType'] = 'http://purl.obolibrary.org/obo/RO_0002303';
                }
                self::proceed_2write($rec, $class);
            }
            // =======================================================================================================
            elseif($what == 'write_Polytraits') {
                /* for records with measurementType=http://polytraits.lifewatchgreece.eu/terms/EP
                    IF the record value is
                    http://polytraits.lifewatchgreece.eu/terms/EP_ENDOB
                    http://polytraits.lifewatchgreece.eu/terms/EP_EPIB
                    http://polytraits.lifewatchgreece.eu/terms/EP_EL
                    please replace http://polytraits.lifewatchgreece.eu/terms/EP with http://purl.obolibrary.org/obo/RO_0002303

                    IF the record value is
                    http://polytraits.lifewatchgreece.eu/terms/EP_LITH
                    http://polytraits.lifewatchgreece.eu/terms/EP_EPIZ
                    http://polytraits.lifewatchgreece.eu/terms/EP_EPIP
                    please replace http://polytraits.lifewatchgreece.eu/terms/EP with http://eol.org/schema/terms/EcomorphologicalGuild                        
                */
                if($class == 'MoF') {
                    if($measurementType == 'http://polytraits.lifewatchgreece.eu/terms/EP') {
                        if(in_array($measurementValue, array('http://polytraits.lifewatchgreece.eu/terms/EP_ENDOB', 'http://polytraits.lifewatchgreece.eu/terms/EP_EPIB', 'http://polytraits.lifewatchgreece.eu/terms/EP_EL'))) {
                            $rec['http://rs.tdwg.org/dwc/terms/measurementType'] = 'http://purl.obolibrary.org/obo/RO_0002303';                        
                        }                    
                        if(in_array($measurementValue, array('http://polytraits.lifewatchgreece.eu/terms/EP_LITH', 'http://polytraits.lifewatchgreece.eu/terms/EP_EPIZ', 'http://polytraits.lifewatchgreece.eu/terms/EP_EPIP'))) {
                            $rec['http://rs.tdwg.org/dwc/terms/measurementType'] = 'http://eol.org/schema/terms/EcomorphologicalGuild';
                        }
                    }
                    self::proceed_2write($rec, $class);
                }
            }
            // =======================================================================================================
            elseif($what == 'Rotifer_round_1') {
                /* for records with value= http://eol.org/schema/terms/littoralGlacialSand, 
                please replace with two records with all the same data, 
                but one with value= http://purl.obolibrary.org/obo/ENVO_01000017
                and one with value= http://eol.org/schema/terms/littoralZone                
                Array(
                    [http://rs.tdwg.org/dwc/terms/measurementID] => be13b9db67bf953a70bb4b5d9dfa00fa_726
                    [http://rs.tdwg.org/dwc/terms/occurrenceID] => b087e2a4775d863ee488aa73ecf4af45O24b241fb5b316f48de5f825d743d9dcc
                    [http://eol.org/schema/measurementOfTaxon] => true
                    [http://eol.org/schema/parentMeasurementID] => 
                    [http://rs.tdwg.org/dwc/terms/measurementType] => http://purl.obolibrary.org/obo/RO_0002303
                    [http://rs.tdwg.org/dwc/terms/measurementValue] => http://eol.org/schema/terms/littoralGlacialSand
                    [http://rs.tdwg.org/dwc/terms/measurementUnit] => 
                    [http://rs.tdwg.org/dwc/terms/measurementMethod] => 
                    [http://rs.tdwg.org/dwc/terms/measurementRemarks] => 
                    [http://purl.org/dc/terms/source] => http://rotifera.hausdernatur.at/Species/Index/670
                )*/
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
            // =======================================================================================================
        }
    }
    private function proceed_2write($rec, $class)
    {
        if($class == 'MoF')             $o = new \eol_schema\MeasurementOrFact_specific();
        elseif($class == 'occurrence')  $o = new \eol_schema\Occurrence_specific();
        elseif($class == 'reference')   $o = new \eol_schema\Reference();
        else exit("\nclass not defined [$class].\n");
        $uris = array_keys($rec); //print_r($uris); exit("\ndito eli\n");
        foreach($uris as $uri) {
            $field = pathinfo($uri, PATHINFO_BASENAME);
            $o->$field = $rec[$uri];
        }
        $this->archive_builder->write_object_to_file($o);
    }
    private function save_array_2json_textfile($arr, $filename)
    {
        $json = json_encode($arr);
        $WRITE = fopen($filename, 'w');
        fwrite($WRITE, $json);
        fclose($WRITE);
    }
    private function retrive_json_textfile_2array($filename)
    {
        $json = file_get_contents($filename);
        return json_decode($json, true);
    }
}
?>