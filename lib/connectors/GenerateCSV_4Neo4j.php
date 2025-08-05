<?php
namespace php_active_record;
/* Library that will generate CSV files for Neo4j Admin Import utility */
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class GenerateCSV_4Neo4j
{
    function __construct()
    {        
        $this->download_options = array('resource_id' => 'neo4j', 'cache' => 1, 'download_wait_time' => 1000000, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1, 'resource_id' => 26);
        $this->download_options["expire_seconds"] = 60*60*24*1;
        $this->debug = array();
        $this->urls['raw predicates'] = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/raw_predicates.tsv';
        $this->files['predicates'] = CONTENT_RESOURCE_PATH."reports/predicates.tsv";
    }
    function buildup_predicates()
    {
        require_library('connectors/EOLterms_ymlAPI');
        $func = new EOLterms_ymlAPI();
        $ret = $func->get_terms_yml('ALL'); //REMINDER: labels can have the same value but different uri. Possible values: 'measurement', 'value', 'ALL', 'WoRMS value'
        foreach($ret as $label => $uri) $this->uris[$label] = $uri;
        // print_r($this->uris);
        /*[eat] => http://purl.obolibrary.org/obo/RO_0002470
          [co-roost with] => http://purl.obolibrary.org/obo/RO_0002801*/
        
        
        $tmp_file = Functions::save_remote_file_to_local($this->urls['raw predicates'], $this->download_options);
        $i = 0;
        foreach(new FileIterator($tmp_file) as $line => $row) {
            $row = Functions::conv_to_utf8($row);
            $i++; 
            if($i == 1) $fields = explode("\t", $row);
            else {
                if(!$row) continue;
                $tmp = explode("\t", $row);
                $rec = array(); $k = 0;
                foreach($fields as $field) {
                    $rec[$field] = $tmp[$k];
                    $k++;
                }
                $rec = array_map('trim', $rec);
                print_r($rec); exit;
                /*Array(
                    [EOL_predicate_id] => 12748
                    [Label] => Body symmetry
                )*/
                $label = $rec['Label'];
                $uri = $uris[$label];

            }
        }

    }
}
?>