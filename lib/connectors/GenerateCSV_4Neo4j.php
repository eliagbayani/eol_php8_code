<?php
namespace php_active_record;
/* Library that will generate CSV files for Neo4j Admin Import utility */
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class GenerateCSV_4Neo4j
{
    function __construct() {
        $this->download_options = array('resource_id' => 'neo4j', 'cache' => 1, 'download_wait_time' => 1000000, 'expire_seconds' => 60*60*24*1, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1, 'resource_id' => 26);
        $this->debug = array();
        $this->urls['raw predicates'] = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/raw_predicates.tsv';
        $this->files['predicates'] = CONTENT_RESOURCE_LOCAL_PATH."reports/predicates.tsv";
    }
    function assemble_data($resource_id) {
        $dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/' . $resource_id . '.tar.gz';
        $dwca_file = WEB_ROOT . "/applications/content_server/resources_3/" . $resource_id . ".tar.gz"; //maybe the way to go
        require_library('connectors/ResourceUtility');
        $func = new ResourceUtility(false, $resource_id);
        $ret = $func->prepare_archive_for_access($dwca_file, $this->download_options);
        $temp_dir = $ret['temp_dir'];
        $tables = $ret['tables'];
        $index = array_keys($tables);
        if(!($tables["http://rs.tdwg.org/dwc/terms/taxon"][0]->fields)) { // take note the index key is all lower case
            debug("Invalid archive file. Program will terminate.");
            return false;
        } else echo "\nValid DwCA [$resource_id].\n";

        $extensions = array_keys($tables); print_r($extensions);

        $tbl = "http://rs.tdwg.org/dwc/terms/occurrence"; $meta = $tables[$tbl][0];
        self::process_table($meta, 'build_occurrence_info');
        if(in_array('http://eol.org/schema/association', $extensions)) {
            $tbl = "http://eol.org/schema/association"; $meta = $tables[$tbl][0];
            self::process_table($meta, 'build_association_info');
        }

        // $tbl = "http://rs.tdwg.org/dwc/terms/taxon"; $meta = $tables[$tbl][0];
        // self::process_table($meta, 'assemble_taxa');

    }
    private function process_table($meta, $what)
    {
        echo "\nprocess_table: [$what] [$meta->file_uri]...\n"; $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) { $i++;
            if(($i % 10000) == 0) echo "\n".number_format($i)." - ";
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                if(!$field['term']) continue;
                $rec[$field['term']] = $tmp[$k];
                $k++;
            }
            // print_r($rec); exit;
            if($what == 'build_occurrence_info') self::build_occurrence_info($rec);
            elseif($what == 'build_association_info') self::build_association_info($rec);
        }
    }
    private function build_association_info($rec)
    {   /*Array(
            [http://eol.org/schema/associationID] => 4cb8806ffd419983bc7080a1a50b02b4
            [http://rs.tdwg.org/dwc/terms/occurrenceID] => 9a9e31fb999985e6631623c65385b984
            [http://eol.org/schema/associationType] => http://purl.obolibrary.org/obo/RO_0002556
            [http://eol.org/schema/targetOccurrenceID] => 6e5210acd02426f7ade33cbb6e8e9d46
            [http://rs.tdwg.org/dwc/terms/measurementDeterminedDate] => 
            [http://rs.tdwg.org/dwc/terms/measurementDeterminedBy] => 
            [http://rs.tdwg.org/dwc/terms/measurementMethod] => 
            [http://rs.tdwg.org/dwc/terms/measurementRemarks] => 
            [http://purl.org/dc/terms/source] => Sarah E Miller. 12/20/2016. Species associations manually extracted from Mhaisen, F.T., Ali, A.H. and Khamees, N.R., Checklists of Protozoans and Myxozoans of Freshwater and Marine Fishes of Basrah Province, Iraq.
            [http://purl.org/dc/terms/bibliographicCitation] => 
            [http://purl.org/dc/terms/contributor] => 
            [http://eol.org/schema/reference/referenceID] => 211bebbd914337ab8ce89e18880cd8bf
        )*/
    }
    private function build_occurrence_info($rec)
    {   /*Array(
        [http://rs.tdwg.org/dwc/terms/occurrenceID] => 749fe40cdd56a4a6e33167b5950740aa
        [http://rs.tdwg.org/dwc/terms/taxonID] => EOL:1002964
        [http://rs.tdwg.org/dwc/terms/institutionCode] => */
        if($taxonID = $rec['http://rs.tdwg.org/dwc/terms/taxonID']) {
            if($occurrenceID = $rec['http://rs.tdwg.org/dwc/terms/occurrenceID']) $this->occurrence[$taxonID] = $occurrenceID;
        }
    }
    function buildup_predicates()
    {
        require_library('connectors/EOLterms_ymlAPI');
        $func = new EOLterms_ymlAPI();
        $ret = $func->get_terms_yml('ALL'); //REMINDER: labels can have the same value but different uri. Possible values: 'measurement', 'value', 'ALL', 'WoRMS value'
        foreach($ret as $label => $uri) $uris[$label] = $uri;
        /*[eat] => http://purl.obolibrary.org/obo/RO_0002470
          [co-roost with] => http://purl.obolibrary.org/obo/RO_0002801*/
        $WRITE = Functions::file_open($this->files['predicates'], 'w');
        $tmp_file = Functions::save_remote_file_to_local($this->urls['raw predicates'], $this->download_options);
        $i = 0;
        foreach(new FileIterator($tmp_file) as $line => $row) { $i++;
            $row = Functions::conv_to_utf8($row); 
            if($i == 1) $fields = explode("\t", $row);
            else {
                if(!$row) continue;
                $tmp = explode("\t", $row);
                $rec = array(); $k = 0;
                foreach($fields as $field) {
                    $rec[$field] = $tmp[$k];
                    $k++;
                }
                $rec = array_map('trim', $rec); // print_r($rec); exit;
                /*Array( [EOL_predicate_id] => 12748
                         [Label] => Body symmetry )*/
                $label = $rec['Label'];
                $uri = $uris[$label];
                $rec['URI'] = $uri;
                if($i == 2) {
                    $headers = array_keys($rec);
                    fwrite($WRITE, implode("\t", $headers)."\n");
                }
                fwrite($WRITE, implode("\t", $rec)."\n");
            }
        }
        fclose($WRITE);
    }
}
?>