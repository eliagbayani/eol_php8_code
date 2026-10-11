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

        $this->resource_id = $param['resource_id'];
        $this->archive_builder = $archive_builder;

        $this->download_options = array('resource_id' => 'neo4j_tb', 'cache' => 1, 'download_wait_time' => 1000000, 'expire_seconds' => 60*60*24*1, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        $this->debug = array();
        // $this->urls['raw predicates'] = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/raw_predicates.tsv'; //obsolete
        $this->files['predicates'] = CONTENT_RESOURCE_LOCAL_PATH."reports/predicates.tsv";
        // self::initialize_folders($this->resource_id); //exit("\nstop muna ito...\n"); not needed here I suppose
        $this->files['EOL resources'] = 'https://raw.githubusercontent.com/eliagbayani/EOL-connector-data-files/refs/heads/master/EOL/resources.csv'; //old
        $this->files['EOL resources'] = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/EOL/TraitBank_datasets.csv'; //new
        $this->is_first_resourceYN = ($this->resource_id == '23067562') ? true: false;

        $dir = DOC_ROOT . $GLOBALS['MAIN_CACHE_PATH'] . 'zenodo/';
        if(!is_dir($dir)) mkdir($dir);

        $this->main_datasets = array('Terms_conceptID' => 22776577, 'References_conceptID' => 22776838, 'DH_conceptID' => 22776209);
        /*  22776577 - Terms: Data vocabulary for trait records.
            22776838 - References: Scientific works cited by trait records
            22776209 - TraitBank Reference Hierarchy Version 0.9: Names and taxonomic relationships to support the new TraitBank information architecture.            
        */
    }
    private function initialize($concept_id)
    {
    }
    function convert_2DwCA($concept_id) 
    {
        $this->do_zenodo_stuff($concept_id);
        if(@$this->param['task'] == 'download_only') { echo "\nTask is to download dataset ($concept_id) only. Done.\n"; return; }
        self::initialize($concept_id);
        //step 1
        if (!($taxon_file = $this->get_generic_file_path($concept_id, 'taxon'))) exit("\nERROR: No taxon.tsv\n");
        else self::process_table($taxon_file, 'compile_taxon_info_from_taxon_file'); //1st source
        if (!($traits_file = $this->get_generic_file_path($concept_id, 'traits'))) exit("\nERROR: No traits.tsv\n");
        else self::process_table($traits_file, 'compile_taxon_info_from_traits_file'); //2nd source

        //step 2
        self::write_taxon_ext(); //this will use the output of the 2 previous steps

        //step 2.1
        self::initialize_references_info();


        //step 3
        if (!($traits_file = $this->get_generic_file_path($concept_id, 'traits'))) exit("\nERROR: No traits.tsv\n");
        else self::process_table($traits_file, 'build_mof_and_occurrences_array');


        $this->archive_builder->finalize(true);

        Functions::start_print_debug($this->debug, $concept_id.'_UndefinedRefIDs');
        // recursive_rmdir($temp_dir);
        // debug("\n temporary directory removed: " . $temp_dir);
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
                $field = $this->small_field($field);
                if(!$field) continue;
                $rec[$field] = $tmp[$k];
                $k++;
            }
            if($what == 'compile_taxon_info_from_taxon_file') { //print_r($rec); exit;
                /*Array(
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
                $taxonID = $rec['taxonID']; //md5(trim($rec['taxonID'].$rec['scientificName']));
                $this->taxon[$taxonID] = $rec;
            }
            elseif($what == 'compile_taxon_info_from_traits_file') { //print_r($rec); exit;
                /*Array( only fields that are tax concerned
                    [taxonID] => Remipedia
                    [scientificName] => Remipedia
                    [taxonKey] => 
                    [infer] => TRUE
                    [exclude] =>  
                )*/ 
                $fields_2combine = array('scientificName', 'taxonKey', 'infer', 'exclude');
                $taxonID = $rec['taxonID'];
                if($t = @$this->taxon[$taxonID]) { //let us combine values
                    foreach($fields_2combine as $field) {
                        if(!isset($rec[$field])) continue; //new
                        if($rec[$field]) {
                            if(@$t[$field] != $rec[$field]) {
                                $t[$field] = $rec[$field];
                                $this->taxon[$taxonID] = $t;
                            }
                        }
                    }
                }
            }
            elseif($what == 'build_mof_and_occurrences_array') self::build_mof_and_occurrences_array($rec);
            elseif($what == 'initialize_references_info') {
                /*Array(
                    [identifier] => https://doi.org/10.1093/jn/44.1.43
                    [full_reference] => A. A. Heidebrecht;R. MacVicar;O. B. Ross;C. K. Whitehair. 1951. Composition of swine milk. I. Major constituents and carotene, vitamin A and vitamin C. pp. 43-50. Journal of Nutrition. vol. 44
                )*/
                $this->references_info[$rec['identifier']] = $rec['full_reference'];
            }            
        }
    }
    private function build_mof_and_occurrences_array($rec)
    {   /*Array(
            [measurementID] => toxins1
            [taxonID] => Remipedia
            [scientificName] => Remipedia
            [taxonKey] => 
            [infer] => TRUE
            [exclude] => 
            [measurementType] => https://www.wikidata.org/entity/Q3386847
            [measurementValue] => http://purl.obolibrary.org/obo/OMIT_0027854
            [measurementUnit] => 
            [measurementRemarks] => 
            [source] => https://doi.org/10.1093/molbev/mst199
            [referenceID] => 
            and probably more...
        )*/
        $occurrence_id = md5(json_encode($rec));
        $rec['occurrenceID'] = $occurrence_id;
        //step 1: write occurrence
        $o = new \eol_schema\Occurrence_specific();
        $o->occurrenceID = $occurrence_id;
        $o->taxonID = $rec['taxonID'];
        if(!isset($this->occurrence_ids[$occurrence_id])) {
            $this->archive_builder->write_object_to_file($o);
            $this->occurrence_ids[$occurrence_id] = '';
        }
        //step 2: write mof
        unset($rec['taxonID']);
        unset($rec['scientificName']);
        unset($rec['taxonKey']);
        unset($rec['infer']);
        unset($rec['exclude']);
        // print_r($rec); exit("\nstop x 1\n");
        $mof = new \eol_schema\MeasurementOrFact_specific();
        $fields = array_keys($rec);
        foreach($fields as $field) $mof->$field = $rec[$field];
        if(!isset($mof->measurementID)) $mof->measurementID = Functions::generate_measurementID($mof, $this->resource_id);
        $this->archive_builder->write_object_to_file($mof);
        //step 3: write Reference
        if($rec['referenceID']) {
            self::write_references($rec);
        }
    }
    private function write_references($rec)
    {   /*Array(
            [measurementID] => Milkdata_284
            [measurementType] => http://eol.org/schema/terms/AminoAcidCompositionOfMilk
            [measurementValue] => http://purl.obolibrary.org/obo/CHEBI_26271
            [measurementUnit] => 
            [measurementRemarks] => Values converted by Dr. van Tienhoven from units of micromol/g or from number of residues.  Data reported as number of amino acid residues per molecule of protein were converted to concentrations by multiplying the molecular weight (mw) of each amino acid by the number of residues (n) and then determining the sum of the product nmw, and finally converting these to a percentage for each amino acid.  The particular protein that was analyzed for its amino acid content: lysozyme.
            [source] => https://doi.org/10.1007/BF00230951
            [referenceID] => https://doi.org/10.1016/S0021-9258(19)43726-5
            [occurrenceID] => 01d2c6f4538a959130b2fdd9b1785a3a
        )*/
        $referenceIDs = explode("|", $rec['referenceID']);
        $referenceIDs = array_map('trim', $referenceIDs);
        foreach($referenceIDs as $referenceID) {
            if($full_ref = @$this->references_info[$referenceID]) {
                $r = new \eol_schema\Reference();
                $r->full_reference = $full_ref;
                $r->identifier = $referenceID;
                if(!isset($this->reference_ids[$r->identifier])){
                    $this->reference_ids[$r->identifier] = '';
                    $this->archive_builder->write_object_to_file($r);
                }
            }
            else $this->debug['undefined referenceID'][$referenceID] = '';
        }
    }
    private function report_to_admin($referenceID, $msg)
    {
        echo "\nUndefined referenceID [$referenceID]\n";                        
    }
    private function write_taxon_ext()
    {
        foreach($this->taxon as $key => $rek) {
            $t = new \eol_schema\Taxon();
            $fields = array_keys($rek);
            foreach($fields as $field) {
                if($field == 'taxonKey') {
                    if($val = $rek[$field]) {
                        if(is_numeric($val)) $t->EOLid = $val;
                    }
                }
                else $t->$field = $rek[$field];
            }
            // $t->scientificName = '';
            // $t->kingdom = '';
            $this->archive_builder->write_object_to_file($t);
        }
    }
    private function initialize_references_info()
    {
        $concept_id = $this->main_datasets['References_conceptID'];
        if (!($local = $this->get_generic_file_path($concept_id, 'references'))) exit("\nERROR: No references.tsv\n");
        else self::process_table($local, 'initialize_references_info');
    }


    
}
?>