<?php
namespace php_active_record;
/* Library that will generate CSV files for Neo4j Admin Import utility */
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class GenerateCSV_4Neo4j
{
    function __construct($resource_id) {
        $this->resource_id = $resource_id;
        $this->download_options = array('resource_id' => 'neo4j', 'cache' => 1, 'download_wait_time' => 1000000, 'expire_seconds' => 60*60*24*1, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1, 'resource_id' => 26);
        $this->debug = array();
        $this->urls['raw predicates'] = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/raw_predicates.tsv';
        $this->files['predicates'] = CONTENT_RESOURCE_LOCAL_PATH."reports/predicates.tsv";

        self::initialize_folders($resource_id);
    }
    function assemble_data($resource_id) 
    {
        $dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/' . $resource_id . '.tar.gz';
        $dwca_file = WEB_ROOT . "/applications/content_server/resources_3/" . $resource_id . ".tar.gz"; //maybe the way to go
        $dwca_file = CONTENT_RESOURCE_LOCAL_PATH . $resource_id . ".tar.gz"; //maybe the way to go

        require_library('connectors/ResourceUtility');
        $func = new ResourceUtility(false, $resource_id);
        $ret = $func->prepare_archive_for_access($dwca_file, $this->download_options);
        $temp_dir = $ret['temp_dir'];
        $tables = $ret['tables'];
        $index = array_keys($tables);
        if(!($tables["http://rs.tdwg.org/dwc/terms/taxon"][0]->fields)) { // take note the index key is all lower case
            debug("Invalid archive file. Program will terminate."); return false;
        } else echo "\nValid DwCA [$resource_id].\n";

        $extensions = array_keys($tables); print_r($extensions);

        // self::prepate_taxa_csv($tables);

        // /*
        $meta = $tables['http://rs.tdwg.org/dwc/terms/occurrence'][0];  self::process_table($meta, 'build_occurrence_info');
        $meta = $tables['http://rs.tdwg.org/dwc/terms/taxon'][0];  self::process_table($meta, 'build_taxon_info');

        if(in_array('http://eol.org/schema/association', $extensions)) {
            $meta = $tables['http://eol.org/schema/association'][0];
            self::process_tsv($this->files['predicates'], 'gen_allowed_uri_predicates'); //print_r($this->allowed_uri_predicates); exit;
            // self::process_table($meta, 'build_association_info');
            self::prepare_predicates_csv($tables);
        }
        // */

        // $tbl = "http://rs.tdwg.org/dwc/terms/taxon"; $meta = $tables[$tbl][0];
        // self::process_table($meta, 'assemble_taxa');

    }
    private function process_table($meta, $what)
    {
        echo "\nprocess_table: [$what] [$meta->file_uri]...\n"; $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) { $i++;
            if(($i % 500000) == 0) echo "\n".number_format($i)." - ";
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                $field['term'] = self::small_field($field['term']);
                if(!$field['term']) continue;
                $rec[$field['term']] = $tmp[$k];
                $k++;
            }
            // print_r($rec); exit;
            if($what == 'generate-taxa-csv') self::generate_taxa_csv($rec);
            elseif($what == 'generate-predicates-csv') self::generate_predicates_csv($rec);
            elseif($what == 'build_occurrence_info') self::build_occurrence_info($rec);
            elseif($what == 'build_taxon_info') self::build_taxon_info($rec);
            elseif($what == 'build_association_info') self::build_association_info($rec);
        }
    }
    private function generate_taxa_csv($rec)
    {   /*Array(
            [taxonID] => COL:74YCG
            [furtherInformationURL] => https://www.catalogueoflife.org/data/taxon/74YCG
            [referenceID] => 
            [parentNameUsageID] => 
            [scientificName] => Orthosia pacifica
            [namePublishedIn] => 
            [higherClassification] => Animalia|Arthropoda|Insecta|Lepidoptera|Noctuidae|Orthosia|
            [kingdom] => Animalia
            [phylum] => Arthropoda
            [class] => Insecta
            [order] => Lepidoptera
            [family] => Noctuidae
            [genus] => Orthosia
            [taxonRank] => species
            [taxonomicStatus] => 
            [taxonRemarks] => 
            [canonicalName] => Orthosia pacifica
            [EOLid] => 465299
        )*/
        // print_r($rec); exit("\ncha\n");
        // $csv = '".$rec['taxonID'].",".$rec['scientificName'].",".$rec['taxonRank'].",".$rec['higherClassification']."';
        $fields = array('taxonID', 'scientificName', 'taxonRank', 'higherClassification');
        $csv = self::format_csv_entry($rec, $fields);
        fwrite($WRITE, $csv."\n");
    }
    private function format_csv_entry($rec, $fields)
    {
        $csv = "";
        foreach($fields as $field) $csv .= '"' . $rec[$field] . '",';
        // exit("\n[$csv]\n");
        return $csv;
    }
    private function format_csv_entry_array($arr)
    {
        $csv = "";
        foreach($arr as $val) $csv .= '"' . $val . '",';
        exit("\n[$csv]\nstop 1\n");
        return $csv;
    }
 
    private function generate_predicates_csv($rec)
    {
        // print_r($rec); exit("\ngoes here...\n");
        /*Array(
            [associationID] => 4cb8806ffd419983bc7080a1a50b02b4
            [occurrenceID] => 9a9e31fb999985e6631623c65385b984
            [associationType] => http://purl.obolibrary.org/obo/RO_0002556
            [targetOccurrenceID] => 6e5210acd02426f7ade33cbb6e8e9d46
            [measurementDeterminedDate] => 
            [measurementDeterminedBy] => 
            [measurementMethod] => 
            [measurementRemarks] => 
            [source] => Sarah E Miller. 12/20/2016. Species associations manually extracted from Mhaisen, F.T., Ali, A.H. and Khamees, N.R., Checklists of Protozoans and Myxozoans of Freshwater and Marine Fishes of Basrah Province, Iraq.
            [bibliographicCitation] => 
            [contributor] => 
            [referenceID] => 211bebbd914337ab8ce89e18880cd8bf
        )*/
        if(!isset($this->allowed_uri_predicates[$rec['associationType']])) return;
        // print_r($rec); //exit("\nstop 3\n");

        // fwrite($WRITE, ":START_ID(Taxon),associationType,referenceID,:END_ID(Taxon),:TYPE"."\n");
        $name1 = ''; $name2 = '';
        
        if($taxonID = $this->occurrence[$rec['occurrenceID']]) {
            if($ret = @$this->taxon[$taxonID]) $name1 = $ret['cN'];
            else {
                print_r($rec); exit("\n1st oID not found\n");
            }
        }
        if($taxonID = $this->occurrence[$rec['targetOccurrenceID']]) {
            if($ret = @$this->taxon[$taxonID]) $name2 = $ret['cN'];
            else {
                print_r($rec); exit("\n2nd oID not found\n");
            }
        }

        if($name1 && $name2) {
            $arr = array($name1, $rec['associationType'], $rec['referenceID'], $name2);
            $csv = self::format_csv_entry_array($arr);
            fwrite($WRITE, $csv."\n");
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
        $associationType = $rec['http://eol.org/schema/associationType'];
        // if(isset($this->allowed_uri_predicates[$associationType])) {}
    }
    private function build_occurrence_info($rec)
    {   /*Array(
        [occurrenceID] => 749fe40cdd56a4a6e33167b5950740aa
        [taxonID] => EOL:1002964
        [institutionCode] => */
        if($taxonID = $rec['taxonID']) {
            if($occurrenceID = $rec['occurrenceID']) $this->occurrence[$occurrenceID] = $taxonID;
        }
    }
    private function build_taxon_info($rec)
    {
        /*Array(
            [taxonID] => COL:74YCG
            [furtherInformationURL] => https://www.catalogueoflife.org/data/taxon/74YCG
            [referenceID] => 
            [parentNameUsageID] => 
            [scientificName] => Orthosia pacifica
            [namePublishedIn] => 
            [higherClassification] => Animalia|Arthropoda|Insecta|Lepidoptera|Noctuidae|Orthosia|
            [kingdom] => Animalia
            [phylum] => Arthropoda
            [class] => Insecta
            [order] => Lepidoptera
            [family] => Noctuidae
            [genus] => Orthosia
            [taxonRank] => species
            [taxonomicStatus] => 
            [taxonRemarks] => 
            [canonicalName] => Orthosia pacifica
            [EOLid] => 465299
        )*/
        $sciname = $rec['canonicalName'] ? $rec['canonicalName'] : $rec['scientificName'];
        $this->taxon[$rec['taxonID']] = array('cN' => $sciname, 'r' => $rec['taxonRank']);
    }
    function buildup_predicates()
    {
        require_library('connectors/EOLterms_ymlAPI');
        $func = new EOLterms_ymlAPI();
        $ret = $func->get_terms_yml('ALL'); //REMINDER: labels can have the same value but different uri. Possible values: 'measurement', 'value', 'ALL', 'WoRMS value'
        foreach($ret as $label => $uri) $this->uris[$label] = $uri;
        /*[eat] => http://purl.obolibrary.org/obo/RO_0002470
          [co-roost with] => http://purl.obolibrary.org/obo/RO_0002801*/
        $local_tsv = Functions::save_remote_file_to_local($this->urls['raw predicates'], $this->download_options);
        self::process_tsv($local_tsv, 'buildup_predicates');
        unlink($local_tsv);
        unset($this->uris);
    }
    private function process_tsv($local_tsv, $task)
    {
        if($task == 'buildup_predicates') {
            $WRITE = Functions::file_open($this->files['predicates'], 'w');
        }
        $i = 0;
        foreach(new FileIterator($local_tsv) as $line => $row) { $i++;
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
                // ==================================================================================================
                if($task == 'buildup_predicates') {
                    /*Array( [EOL_predicate_id] => 12748
                            [Label] => Body symmetry )*/
                    $label = $rec['Label'];
                    $uri = $this->uris[$label];
                    $rec['URI'] = $uri;
                    if($i == 2) {
                        $headers = array_keys($rec);
                        fwrite($WRITE, implode("\t", $headers)."\n");
                    }
                    fwrite($WRITE, implode("\t", $rec)."\n");
                }
                // ==================================================================================================
                if($task == 'gen_allowed_uri_predicates') { // print_r($rec); exit("\nelix 1\n");
                    /*Array(
                        [EOL_predicate_id] => 12748
                        [Label] => Body symmetry
                        [URI] => http://eol.org/schema/terms/body_symmetry
                    )*/
                    if($rec['Label'] != 'eat') continue;
                    $this->allowed_uri_predicates[$rec['URI']] = array('predicate_id' => $rec['EOL_predicate_id'], 'Label' => $rec['Label']);
                }
                // ==================================================================================================
            }
        }
        if($task == 'buildup_predicates') {
            fclose($WRITE);
        }
    }
    private function prepate_taxa_csv($tables)
    {
        $WRITE = Functions::file_open($this->path.'/taxa.csv', 'w');
        fwrite($WRITE, "taxonID:ID(Taxon){label:Taxon},scientificName,taxonRank,higherClassification:LABEL"."\n");
        $meta = $tables['http://rs.tdwg.org/dwc/terms/taxon'][0];
        self::process_table($meta, 'generate-taxa-csv');
        fclose($WRITE);
    }
    private function prepare_predicates_csv($tables)
    {
        $WRITE = Functions::file_open($this->path.'/predicates.csv', 'w');
        fwrite($WRITE, ":START_ID(Taxon),associationType,referenceID,:END_ID(Taxon),:TYPE"."\n");
        $meta = $tables['http://eol.org/schema/association'][0];
        self::process_table($meta, 'generate-predicates-csv');
        fclose($WRITE);
    }
    private function initialize_folders($resource_id)
    {
        $path = CONTENT_RESOURCE_LOCAL_PATH . $resource_id . '_csv';
        if(is_dir($path)) recursive_rmdir($path);
        mkdir($path);
        $this->path = $path;
    }
    private function small_field($uri)
    {
        return pathinfo($uri, PATHINFO_FILENAME);
    }
}
?>