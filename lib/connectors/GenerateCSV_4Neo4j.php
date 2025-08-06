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
        // print_r($ret);
        $temp_dir = $ret['temp_dir'];
        $tables = $ret['tables'];
        $index = array_keys($tables);
        if(!($tables["http://rs.tdwg.org/dwc/terms/taxon"][0]->fields)) { // take note the index key is all lower case
            debug("Invalid archive file. Program will terminate.");
            return false;
        } else echo "\nValid DwCA.\n";

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