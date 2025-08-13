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

        self::prepare_taxa_csv($tables);

        // /*
        $meta = $tables['http://rs.tdwg.org/dwc/terms/occurrence'][0];  self::process_table($meta, 'build_occurrence_info');
        $meta = $tables['http://rs.tdwg.org/dwc/terms/taxon'][0];  self::process_table($meta, 'build_taxon_info');

        if(in_array('http://eol.org/schema/association', $extensions) || 
           in_array('http://rs.tdwg.org/dwc/terms/measurementorfact', $extensions)) {
            self::process_tsv($this->files['predicates'], 'gen_allowed_uri_predicates'); //print_r($this->allowed_uri_predicates); exit;
        }
        
        if(in_array('http://eol.org/schema/association', $extensions)) {
            self::prepare_predicates_csv_association($tables);
        }
        if(in_array('http://rs.tdwg.org/dwc/terms/measurementorfact', $extensions)) {
            self::prepare_measurements_csv($tables);
            self::prepare_predicates_csv_measurement($tables);
        }
        // */
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
            // print_r($rec); //exit;
            if($what == 'generate-taxa-csv') self::generate_taxa_csv($rec);
            elseif($what == 'generate-measurements-csv') {
                if($rec['measurementOfTaxon'] == 'true' && !$rec['parentMeasurementID']) {
                    self::generate_measurements_csv($rec);
                }
            }
            elseif($what == 'generate-predicates-csv')              self::generate_predicates_csv($rec);
            elseif($what == 'generate-predicates-measurements-csv') self::generate_predicates_measurements_csv($rec);
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
        // $csv = '".$rec['taxonID'].",".$rec['scientificName'].",".$rec['taxonRank'].",".$rec['higherClassification']."';
        $fields = array('taxonID', 'vernacularName', 'scientificName', 'taxonRank', 'higherClassification');
        // print_r($rec); print_r($fields); exit;
        $csv = self::format_csv_entry($rec, $fields);
        $csv .= 'Taxon';
        fwrite($this->WRITE, $csv."\n");
    }
    private function generate_measurements_csv($rec)
    {
        /*Array(
            [measurementID] => 118e29317da0c8eae6c6e44e84959862
            [occurrenceID] => e36713aea279079ed39099826601f8f6
            [measurementOfTaxon] => true
            [parentMeasurementID] => 
            [measurementType] => http://rs.tdwg.org/dwc/terms/habitat
            [measurementValue] => http://purl.obolibrary.org/obo/ENVO_01000024
            [measurementUnit] => 
            [statisticalMethod] => 
            [measurementDeterminedDate] => 
            [measurementDeterminedBy] => 
            [measurementMethod] => inherited from urn:lsid:marinespecies.org:taxname:101, Gastropoda Cuvier, 1795
            [measurementRemarks] => 
            [source] => http://www.marinespecies.org/aphia.php?p=taxdetails&id=1054700
            [contributor] => 
            [referenceID] => 
        )*/
        $fields = array('measurementID', 'measurementValue', 'measurementUnit', 'statisticalMethod', 'source', 'referenceID');

        // print_r($rec); print_r($fields); exit;
        $csv = self::format_csv_entry($rec, $fields);
        $csv .= 'Measurement';
        fwrite($this->WRITE, $csv."\n");
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
        if($ret = @$this->allowed_uri_predicates[$rec['associationType']]) {
            $predicate = strtoupper($ret['Label']);
            $predicate = str_replace(" ", "_", $predicate);
        }
        else return; //exit("\nPredicate not found. [".$rec['associationType']."]\n");
        // print_r($rec); //exit("\nstop 3\n");

        $taxonID_1 = ''; $taxonID_2 = '';
        
        if($taxonID_1 = $this->occurrence[$rec['occurrenceID']]) {
            if($ret = @$this->taxon[$taxonID_1]) $name1 = $ret['cN'];
            else {
                print_r($rec); exit("\nassociations: 1st oID not found\n");
            }
        }
        if($taxonID_2 = $this->occurrence[$rec['targetOccurrenceID']]) {
            if($ret = @$this->taxon[$taxonID_2]) $name2 = $ret['cN'];
            else {
                print_r($rec); exit("\nassociations: 2nd oID not found\n");
            }
        }

        if($taxonID_1 && $taxonID_2) {
            $arr = array($taxonID_1, $rec['associationType'], $taxonID_2, $predicate);
            $csv = self::format_csv_entry_array($arr);
            fwrite($this->WRITE, $csv."\n");
        }
    }
    private function generate_predicates_measurements_csv($rec)
    {
        // print_r($rec); exit("\ngoes here...\n");
        /*Array(
            [measurementID] => 118e29317da0c8eae6c6e44e84959862
            [occurrenceID] => e36713aea279079ed39099826601f8f6
            [measurementOfTaxon] => true
            [parentMeasurementID] => 
            [measurementType] => http://rs.tdwg.org/dwc/terms/habitat
            [measurementValue] => http://purl.obolibrary.org/obo/ENVO_01000024
            [measurementUnit] => 
            [statisticalMethod] => 
            [measurementDeterminedDate] => 
            [measurementDeterminedBy] => 
            [measurementMethod] => inherited from urn:lsid:marinespecies.org:taxname:101, Gastropoda Cuvier, 1795
            [measurementRemarks] => 
            [source] => http://www.marinespecies.org/aphia.php?p=taxdetails&id=1054700
            [contributor] => 
            [referenceID] => 
        )*/
        if($ret = @$this->allowed_uri_predicates[$rec['measurementType']]) {
            $predicate = strtoupper($ret['Label']);
            $predicate = str_replace(" ", "_", $predicate);
        }
        else return; //exit("\nPredicate not found. [".$rec['measurementType']."]\n");
        // print_r($rec); //exit("\nstop 3\n");

        $taxonID_1 = '';
        if($taxonID_1 = $this->occurrence[$rec['occurrenceID']]) {
            if($ret = @$this->taxon[$taxonID_1]) $name1 = $ret['cN'];
            else {
                // print_r($rec); exit("\nmeasurements: 1st oID not found\n");
                @$this->debug['measurements: taxonID not found in taxa'] .= " [".$rec['occurrenceID']."-$taxonID_1]";
            }
        }
        if($taxonID_1) {
            $arr = array($taxonID_1, $rec['measurementType'], $rec['measurementID'] ,$predicate);
            $csv = self::format_csv_entry_array($arr);
            fwrite($this->WRITE, $csv."\n");
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
    /* obsolete
    function buildup_predicates()
    {
        require_library('connectors/EOLterms_ymlAPI');
        $func = new EOLterms_ymlAPI();
        //REMINDER: labels can have the same value but different uri. Possible values: 'measurement', 'value', 'ALL', 'WoRMS value'
        $this->uris = $func->get_terms_yml('neo4j');         
        $local_tsv = Functions::save_remote_file_to_local($this->urls['raw predicates'], $this->download_options);
        self::process_tsv($local_tsv, 'buildup_predicates');
        unlink($local_tsv);
        unset($this->uris);
    } */
    function buildup_predicates_all()
    {
        require_library('connectors/EOLterms_ymlAPI');
        $func = new EOLterms_ymlAPI();
        //REMINDER: labels can have the same value but different uri. Possible values: 'measurement', 'value', 'ALL', 'WoRMS value'
        $terms = $func->get_terms_yml('neo4j_v2');
        $WRITE = Functions::file_open($this->files['predicates'], 'w');
        fwrite($WRITE, implode("\t", array('Label', 'URI', 'type'))."\n");
        foreach($terms as $uri => $rek) {
            // echo "\n[$uri]\n"; print_r($rek); exit;
            /*Array(
                [name] => abundance
                [type] => measurement
            )*/
            $rec = array();
            $rec[] = $rek['name'];
            $rec[] = $uri;
            $rec[] = $rek['type'];
            fwrite($WRITE, implode("\t", $rec)."\n");
        }
        fclose($WRITE);
    }
    private function process_tsv($local_tsv, $task)
    {
        if($task == 'buildup_predicates') {
            $this->WRITE = Functions::file_open($this->files['predicates'], 'w');
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
                    $rec['URI'] = $this->uris[$label]['uri'];
                    $rec['type'] = $this->uris[$label]['type'];
                    if($i == 2) {
                        $headers = array_keys($rec);
                        fwrite($this->WRITE, implode("\t", $headers)."\n");
                    }
                    fwrite($this->WRITE, implode("\t", $rec)."\n");
                }
                // ==================================================================================================
                if($task == 'gen_allowed_uri_predicates') { // print_r($rec); exit("\nelix 1\n");
                    /*Array(
                        [EOL_predicate_id] => 12748
                        [Label] => Body symmetry
                        [URI] => http://eol.org/schema/terms/body_symmetry
                    )*/
                    // if($rec['Label'] != 'eat') continue; //dev only
                    $this->allowed_uri_predicates[$rec['URI']] = array('predicate_id' => @$rec['EOL_predicate_id'], 'Label' => $rec['Label']);
                }
                // ==================================================================================================
            }
        }
        if($task == 'buildup_predicates') {
            fclose($this->WRITE);
        }
    }
    private function prepare_taxa_csv($tables)
    {
        $this->WRITE = Functions::file_open($this->path.'/taxa.csv', 'w');
        fwrite($this->WRITE, "taxonID:ID(Taxon){label:Taxon},vernacularName,scientificName,taxonRank,higherClassification,:LABEL"."\n");
        $meta = $tables['http://rs.tdwg.org/dwc/terms/taxon'][0];
        self::process_table($meta, 'generate-taxa-csv');
        fclose($this->WRITE);
    }
    private function prepare_measurements_csv($tables)
    {
        /*Array(
            [measurementID] => 118e29317da0c8eae6c6e44e84959862
            [occurrenceID] => e36713aea279079ed39099826601f8f6
            [measurementOfTaxon] => true
            [parentMeasurementID] => 
            [measurementType] => http://rs.tdwg.org/dwc/terms/habitat
            [measurementValue] => http://purl.obolibrary.org/obo/ENVO_01000024
            [measurementUnit] => 
            [statisticalMethod] => 
            [measurementDeterminedDate] => 
            [measurementDeterminedBy] => 
            [measurementMethod] => inherited from urn:lsid:marinespecies.org:taxname:101, Gastropoda Cuvier, 1795
            [measurementRemarks] => 
            [source] => http://www.marinespecies.org/aphia.php?p=taxdetails&id=1054700
            [contributor] => 
            [referenceID] => 
        )*/
        $this->WRITE = Functions::file_open($this->path.'/measurements.csv', 'w');
        fwrite($this->WRITE, "measurementID:ID(Measurement){label:Measurement},measurementValue,measurementUnit,statisticalMethod,source,referenceID,:LABEL"."\n");
        $meta = $tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0];
        self::process_table($meta, 'generate-measurements-csv');
        fclose($this->WRITE);
    }

    private function prepare_predicates_csv_association($tables)
    {
        $this->WRITE = Functions::file_open($this->path.'/predicates.csv', 'w');
        fwrite($this->WRITE, ":START_ID(Taxon),associationType,:END_ID(Taxon),:TYPE"."\n");
        $meta = $tables['http://eol.org/schema/association'][0];
        self::process_table($meta, 'generate-predicates-csv');
        fclose($this->WRITE);
    }
    private function prepare_predicates_csv_measurement($tables)
    {
        $this->WRITE = Functions::file_open($this->path.'/predicates_measurements.csv', 'w');
        fwrite($this->WRITE, ":START_ID(Taxon),measurementType,:END_ID(Measurement),:TYPE"."\n");
        $meta = $tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0];
        self::process_table($meta, 'generate-predicates-measurements-csv');
        fclose($this->WRITE);
    }
    private function clean_csv_item($str)
    {
        return str_replace('"', '""', $str);
    }
    private function initialize_folders($resource_id)
    {
        $path = CONTENT_RESOURCE_LOCAL_PATH . $resource_id . '_csv';
        if(is_dir($path)) recursive_rmdir($path);
        mkdir($path);
        $this->path = $path;
    }
    private function format_csv_entry($rec, $fields)
    {
        $csv = "";
        foreach($fields as $field) {
            if($field == 'vernacularName') {
                $val = $rec['vernacularName'] ? $rec['vernacularName'] : ""; //$rec['scientificName'];
            }
            else $val = $rec[$field];
            $csv .= '"' . self::clean_csv_item($val) . '",'; 
        }
        //exit("\n[$csv]\n");
        return $csv;
    }
    private function format_csv_entry_array($arr)
    {
        $csv = "";
        foreach($arr as $val) $csv .= '"' . self::clean_csv_item($val) . '",';
        $csv = substr($csv, 0, -1); //exit("\n[$csv]\nstop 1\n");
        return $csv;
    }
    private function small_field($uri)
    {
        return pathinfo($uri, PATHINFO_FILENAME);
    }
    /*
    =========================================================================== Globi
    cypher-shell -u neo4j -p eli_neo4j -d system "STOP DATABASE elidb;"
    neo4j-admin database import full elidb --overwrite-destination \
    --nodes=import/globi_assoc/taxa.csv \
    --relationships=import/globi_assoc/predicates.csv \
    --verbose --array-delimiter="U+007C"
    cypher-shell -u neo4j -p eli_neo4j -d system "START DATABASE elidb;"
    =========================================================================== WoRMS
    cypher-shell -u neo4j -p eli_neo4j -d system "STOP DATABASE elidb;"
    neo4j-admin database import full elidb --overwrite-destination \
    --nodes=import/WoRMS/taxa.csv \
    --nodes=import/WoRMS/measurements.csv \
    --relationships=import/WoRMS/predicates_measurements.csv \
    --verbose --array-delimiter="U+007C"
    cypher-shell -u neo4j -p eli_neo4j -d system "START DATABASE elidb;"
    =========================================================================== dump database
    cypher-shell -u neo4j -p eli_neo4j -d system "STOP DATABASE elidb;"
    neo4j-admin database dump --to-path=import/dumps/ elidb
    cypher-shell -u neo4j -p eli_neo4j -d system "START DATABASE elidb;"
    ===========================================================================
    */
}
?>