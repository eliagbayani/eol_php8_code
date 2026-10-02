<?php
namespace php_active_record;
/* Library that processes the Zenodo TraitBank datasets and converts it to DwCA
These ff. workspaces work together:
- generate_higherClassification_8.code-workspace
- DHConnLib_8.code-workspace
- GNParserAPI_8.code-workspace
- DwCA_MatchTaxa2DH.code-workspace
- UseEOLidInTaxon.code-workspace
- GenerateCSV_4EOLNeo4j.code-workspace (replaced by below)
- GenerateCSV_NewModel-workspace (new)
- GenerateTB_FilesAPI.code-workspace

contributor_uri	compiler_uri	determined_by_uri
---------------------------------------------------- below are prompts used:
update our sh/import_append_data.sh, that is if these nodes are not available: 'Resource.csv', 'Page.csv', 'Term.csv', 'AppUser.csv', 'VernacularPageID.csv', 'AuditEvent.csv', 'AppSettings.csv'
then ignore and move to the next node.
Also if these edges are not available: 'PARENT.csv', 'PARENT_TERM.csv', 'SYNONYM_OF.csv'
then ignore and move to the next edge.
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
general question: eventually the graph Neo4j database and this codebase will be deployed to production in a Kubernetes cluster. 
Will I be able to run these sh files: import_dataset.sh, import_append_dataset.sh and the others in a K8s cluster ?
----------------------------------------------------
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class ConvertNewModel_2DwCA extends ZenodoTraitBankAPI
{
    function __construct($archive_builder, $param) {
        $this->param = $param;

        $this->resource_id = $param['concept_id'];
        $this->archive_builder = $archive_builder;

        $this->download_options = array('resource_id' => 'neo4j_tb', 'cache' => 1, 'download_wait_time' => 1000000, 'expire_seconds' => 60*60*24*1, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1, 'resource_id' => 26);
        $this->debug = array();
        // $this->urls['raw predicates'] = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/raw_predicates.tsv'; //obsolete
        $this->files['predicates'] = CONTENT_RESOURCE_LOCAL_PATH."reports/predicates.tsv";
        // self::initialize_folders($this->resource_id); //exit("\nstop muna ito...\n"); not needed here I suppose
        $this->files['EOL resources'] = 'https://raw.githubusercontent.com/eliagbayani/EOL-connector-data-files/refs/heads/master/EOL/resources.csv'; //old
        $this->files['EOL resources'] = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/EOL/TraitBank_datasets.csv'; //new
        $this->is_first_resourceYN = ($this->resource_id == '23067562') ? true: false;

        $dir = DOC_ROOT . $GLOBALS['MAIN_CACHE_PATH'] . 'zenodo/';
        if(!is_dir($dir)) mkdir($dir);

        $this->not_a_resource_dataset = array(22776578);
        /*  22776578 - Terms file
        */
    }
    private function initialize()
    {}
    function convert_2DwCA($concept_id) 
    {
        $this->do_zenodo_stuff($concept_id);
        if(@$this->param['task'] == 'download_only') { echo "\nTask is to download dataset ($concept_id) only. Done.\n"; return; }
        exit("\n-stop muna 1-\n");
        self::initialize();

        if (!($taxon_file = $this->get_generic_file_path($concept_id, 'taxon'))) exit("\nERROR: No taxon.tsv\n");
        self::process_table($taxon_file, 'generate_taxon_info');

        Functions::start_print_debug($this->debug, $this->param['eol_resource_id'].'_convert', $this->path);
        recursive_rmdir($temp_dir);
        debug("\n temporary directory removed: " . $temp_dir);
    }
    private function process_table($label_tsv_file, $what)
    {
        echo "\nprocess_table: [$what] [$label_tsv_file]...\n"; $i = 0;
        foreach(new FileIterator($label_tsv_file) as $line => $row) { $i++;
            if(($i % 500000) == 0) echo "\n".number_format($i)." - ";
            if(!$row) continue;
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            if($i == 1) { $fields = $tmp; continue; }
            foreach($fields as $field) {
                $field = self::small_field($field);
                if(!$field) continue;
                $rec[$field] = $tmp[$k];
                $k++;
            }
            // print_r($rec); exit;
            if($what == 'generate_taxon_info') { //step 1a
                /*Array( new schema
                    [taxonID] => Camellia sinensis
                    [scientificName] => Camellia sinensis
                    [taxonKey] => 
                    [genus] => 
                    [family] => 
                    [class] => 
                    [phylum] => 
                    [kingdom] => 
                    [higherClassification] => Archaeplastida
                )*/
                // print_r($rec); exit;
                if($rec['taxonID'] == $rec['EOLid']) {
                    if(is_numeric($rec['taxonID'])) {
                        $this->taxon_info[$rec['taxonID']] = array('sN' => $rec['scientificName']);
                    }
                }
            }
        }
    }
}
?>