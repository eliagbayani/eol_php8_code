<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from analyze_MoF.php] 
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class AnalyzeMoF_API
{
    function __construct($archive_builder, $resource_id, $archive_path)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        $this->archive_path = $archive_path;
        $this->download_options = array('cache' => 1, 'resource_id' => $resource_id, 'expire_seconds' => 60*60*24*1, 'download_wait_time' => 500000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        $this->debug = array();
    }
    /*================================================================= STARTS HERE ======================================================================*/
    private function initial()
    {
        require_library('connectors/EOLterms_ymlAPI');
        $func = new EOLterms_ymlAPI($this->resource_id, $this->archive_builder);
        $values = $func->get_terms_yml('value'); //sought_type is 'value' --- REMINDER: labels can have the same value but different uri
        $measurements = $func->get_terms_yml('measurement');

        foreach($values as $key => $val) $this->eol_term_values[$val] = '';                //list of URI values
        foreach($measurements as $key => $val) $this->eol_term_measurements[$val] = '';    //list of URI measurements

        // print_r($this->eol_term_values); print_r($this->eol_term_measurements); exit;
    }
    function start($info)
    {   
        self::initial();
        // /* Read the DwCA in question:
        $tables = $info['harvester']->tables; // print_r($tables); exit;
        $extensions = array_keys($tables); print_r($extensions); //exit;
        $tbl = "http://rs.tdwg.org/dwc/terms/measurementorfact";
        $meta = $tables[$tbl][0];
        // */
        self::process_table($meta, 'analyze_MoF');
        if($this->debug) Functions::start_print_debug($this->debug, $this->resource_id);
        unset($this->debug);
    }
    private function process_table($meta, $what)
    {   echo "\nprocess_table: [$what] [$meta->file_uri]...\n"; $i = 0;
        foreach (new FileIterator($meta->file_uri) as $line => $row) {
            $i++;
            if (($i % 20000) == 0) echo "\n" . number_format($i) . " - ";
            if ($meta->ignore_header_lines && $i == 1) continue;
            if (!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array();
            $k = 0;
            foreach ($meta->fields as $field) {
                if (!$field['term']) continue;
                $rec[$field['term']] = $tmp[$k];
                $k++;
            } 
            $rec = Functions::shorten_record($rec);
            $rec = array_map('trim', $rec);
            // print_r($rec); exit;
            /* copied template
            $rec = self::not_recongized_fields($rec); //remove not recognized fields
            */
            /*Array(
                [measurementID] => 40e92024f3639e4c9f800dbbc1accd00_42
                [occurrenceID] => be19b9235e7e85b6940de0a31f58bc16_42
                [measurementOfTaxon] => true
                [measurementType] => http://purl.org/obo/owlATOL_0001659
                [measurementValue] => 60.0
                [measurementUnit] => http://purl.obolibrary.org/obo/UO_0000015
                [statisticalMethod] => 
                [measurementMethod] => Standard length; the length of a fish, measured from the tip of the snout to the tip of the hypural bone, or of the fleshy part of the caudal peduncle (i.e., excluding the caudal fin).
                [measurementRemarks] => 
                [source] => http://www.fishbase.org/summary/SpeciesSummary.php?id=2
                [bibliographicCitation] => Froese, R. and D. Pauly. Editors. 2026.FishBase. World Wide Web electronic publication. www.fishbase.org, ( 02/2026 )
                [contributor] => https://www.fishbase.de/collaborators/CollaboratorSummary.php?id=2
                [referenceID] => 1c6aa37fd9a66304a0f3232fdb521710
            )*/
            //========================================================================================================= 
            if($what == 'analyze_MoF') {
                $mValue = $rec['measurementValue'];
                $mType = $rec['measurementType'];
                $mMethod = $rec['measurementMethod'];
                $mRemarks = $rec['measurementRemarks'];
                if(substr($mValue, 0, 4) == 'http') {
                    if(!isset($this->eol_term_values[$mValue])) $this->debug['Undefined mValue'][$mValue] = '';
                    if(!isset($this->eol_term_measurements[$mType])) $this->debug['Undefined mType'][$mType] = '';
                }
            }
            //========================================================================================================= 
            // if($i >= 100) break; //dev only
        }
    }
}