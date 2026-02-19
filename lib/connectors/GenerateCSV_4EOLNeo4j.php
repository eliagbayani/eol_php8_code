<?php
namespace php_active_record;
/* Library that reads an EOL DwCA and generates CSV files for Neo4j Admin Import utility 
These ff. workspaces work together:
- generate_higherClassification_8.code-workspace
- DHConnLib_8.code-workspace
- GNParserAPI_8.code-workspace
- DwCA_MatchTaxa2DH.code-workspace
- UseEOLidInTaxon.code-workspace
- GenerateCSV_4EOLNeo4j.code-workspace
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class GenerateCSV_4EOLNeo4j
{
    function __construct($param) {
        $this->resource_id = $param['resource_id'];
        $this->param = $param;
        $this->download_options = array('resource_id' => 'neo4j', 'cache' => 1, 'download_wait_time' => 1000000, 'expire_seconds' => 60*60*24*1, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1, 'resource_id' => 26);
        $this->debug = array();
        // $this->urls['raw predicates'] = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/raw_predicates.tsv'; //obsolete
        $this->files['predicates'] = CONTENT_RESOURCE_LOCAL_PATH."reports/predicates.tsv";
        self::initialize_folders($this->resource_id);
        // /* ========== This can come from an RDBMS
        $this->EOL_resources['worms']       = array('eol_resource_id' => 'worms',     'resource_name' => 'World Register of Marine Species');
        $this->EOL_resources['globi']       = array('eol_resource_id' => 'globi',     'resource_name' => 'Global Biotic Interactions');
        $this->EOL_resources['wikipedia']   = array('eol_resource_id' => 'wikipedia', 'resource_name' => 'Wikipedia English - traits (inferred records)');
        // ========== */
    }
    function assemble_data($resource_id) 
    {
        // $dwca_file = 'https://editors.eol.org/eol_php_code/applications/content_server/resources/' . $resource_id . '.tar.gz';
        $dwca_file = CONTENT_RESOURCE_LOCAL_PATH . $resource_id . ".tar.gz"; //maybe the way to go for all resources

        $ret = self::prep_dwca($resource_id, $dwca_file);
        $temp_dir = $ret['temp_dir'];
        $tables = $ret['tables'];
        $extensions = array_keys($tables); print_r($extensions);

        // /* ========== start Jan 27, 2026 ==========

        /*
        // Step 8: generate Metadata node
        if($meta = @$tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0]) self::process_table($meta, 'get_reference_ids');
        if($meta = @$tables['http://eol.org/schema/association'][0])              self::process_table($meta, 'get_reference_ids');
        if($meta = @$tables['http://eol.org/schema/reference/reference'][0])      self::process_table($meta, 'build_reference_info');
        if($meta = @$tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0]) self::prepare_MetadataNode_csv($meta);
        if($meta = @$tables['http://eol.org/schema/association'][0])              self::prepare_MetadataNode_csv($meta);
        unset($meta);
        */

        // Step 0: generate a Term node
        // /* 
        self::prepareTermNode_csv(); //using EOL Terms file
        // */

        // Step 1: generate Page node; PARENT edge
        $meta = $tables['http://rs.tdwg.org/dwc/terms/taxon'][0];
        self::process_table($meta, 'generate_taxon_info');    // step 1a: generate_taxon_info = all taxa with EOLid

        /* is now replaced by: prepare_PageNode_csv_from_DH()
        self::prepare_PageNode_csv_from_resource($meta); //OBSOLETE      // step 1b: 
        self::prepare_ParentEdge_csv($meta);             //OBSOLETE
        */
        unset($meta);

        // /* part of main operation
        self::prepare_PageNode_csv_from_DH();
        // */

        // /*
        // Step 2: generate Vernacular node; VERNACULAR edge
        $vernacular_meta = @$tables['http://rs.gbif.org/terms/1.0/vernacularname'][0];
        self::prepare_VernacularNode_csv($vernacular_meta);         // step 2a
        self::prepare_VernacularEdge_csv($vernacular_meta);         // step 2b
        unset($vernacular_meta);
        
        // Step 3: generate Resource node
        self::prepare_ResourceNode_csv();                        // step 3a: 
        // */

        // Step 4: generate Trait node
        $meta = $tables['http://rs.tdwg.org/dwc/terms/occurrence'][0];
        self::process_table($meta, 'generate_occur_info');
        if($meta = @$tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0]) self::prepare_TraitNode_csv($meta);
        if($meta = @$tables['http://eol.org/schema/association'][0])              self::prepare_TraitNode_csv($meta);
        unset($meta);
        unset($this->occur_info);

        // Step 5: generate Page TRAIT Relationship
        self::prepare_TRAIT_Edge_csv();
        self::prepare_INFERRED_TRAIT_Edge_csv();
        
        // Step 6: PREDICATE relationship between Trait and Term nodes
        self::prepare_PREDICATE_Edge_csv();

        // Step 6.1: OBJECT_TERM relationship between Trait and Term nodes
        self::prepare_OBJECT_TERM_Edge_csv();

        // Step 6.2: NORMAL_UNITS_TERM relationship between Trait and Term nodes
        self::prepare_NORMAL_UNITS_TERM_Edge_csv();

        // Step 6.3: UNITS_TERM relationship between Trait and Term nodes
        self::prepare_UNITS_TERM_Edge_csv();

        // Step 6.4: OBJECT_PAGE relationship between Trait and Page nodes
        self::prepare_OBJECT_PAGE_Edge_csv();

        // Step 6.5: DETERMINED_BY relationship between Trait and Term nodes
        self::prepare_DETERMINED_BY_Edge_csv();

        // Step 6.6: CONTRIBUTOR relationship between Trait and Term nodes
        self::prepare_CONTRIBUTOR_Edge_csv();

        // Step 7: SUPPLIER relationship between Trait and Resource nodes
        self::prepare_SUPPLIER_Edge_csv();

        //    ========== end Jan 27, 2026 ========== */

        /* copied template
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
        }*/

        self::do_stats();
        Functions::start_print_debug($this->debug, $this->param['eol_resource_id'].'_CSV', $this->path); //old 2nd param = Gen_Neo4j_CSV
        recursive_rmdir($temp_dir);
        debug("\n temporary directory removed: " . $temp_dir);
    }
    private function do_stats()
    {
        $files = array('/nodes/Trait.csv');
        foreach($files as $file) {
            $file = $this->path . $file;
            if(is_file($file)) $this->debug['Totals'][$file] = shell_exec('wc -l '.$file);
        }
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
            /*
            nodes/Page.csv
            page_id:ID(Page-ID),canonical,rank,:LABEL
            gadus_m,Gadus morhua,species,page
            chanos_c,Chanos chanos,species,page
            gadus,Gadus,genus,page
            chanos,Chanos,genus,page

            edges/parent.csv
            page_id:START_ID(Page-ID),page_id:END_ID(Page-ID),:TYPE
            gadus_m,gadus,parent
            chanos_c,chanos,parent

            node/trait.csv
            eol_pk:ID(Trait-ID),resource_pk:string,citation:string,source

            edges/metadata.csv
            */
            if($what == 'generate_taxon_info') { //step 1a
                /*Array(
                    [taxonID] => 44475
                    [source] => https://www.wikidata.org/wiki/Q25243
                    [parentNameUsageID] => Q4085525
                    [scientificName] => Betula
                    [higherClassification] => Biota|Eukaryota|Plantae|Viridiplantae|Streptophyta|Embryophytes|Tracheophytes|Spermatophytes|Magnoliophyta|Magnoliopsida|Hamamelididae|Juglandanae|Corylales|Betulaceae|Betuloideae|
                    [taxonRank] => genus
                    [scientificNameAuthorship] => Carl Linnaeus, 1753
                    [vernacularName] => birches
                    [taxonRemarks] => With higherClassification but cannot be mapped to any index group.
                    [canonicalName] => Betula
                    [EOLid] => 44475
                )*/
                if($rec['taxonID'] == $rec['EOLid']) {
                    $this->taxon_info[$rec['taxonID']] = array('sN' => $rec['scientificName']);
                }
            }
            if($what == 'generate-PageNode-csv') { //step 1b
                if(self::is_valid_taxonID($rec['taxonID'])) {
                    if(!@$rec['canonicalName']) $this->debug['No canonicalName'][$rec['taxonID']."-".$rec['scientificName']] = '';
                    self::generate_PageNode_row($rec);
                }
            }
            if($what == 'generate-VernacularNode-csv') { //step 2a
                if(self::is_valid_taxonID($rec['taxonID'])) {
                    $rec['vernacularName'] = self::safe_utf8($rec['vernacularName']);
                    self::generate_VernacularNode_row($rec);
                }
            }
            if($what == 'generate-ParentEdge-csv') { //step 1c
                $taxonID = $rec['taxonID'];
                if(self::is_valid_taxonID($taxonID)) {
                    if($parentNameUsageID = @$rec['parentNameUsageID']) { //Note: not all resources have parentNameUsageID
                        if(self::is_valid_taxonID($parentNameUsageID)) self::generate_ParentEdge_row($rec);
                    }
                }
            }
            if($what == 'generate-VernacularEdge-csv') { //step 2b
                $taxonID = $rec['taxonID'];
                if(self::is_valid_taxonID($taxonID)) {
                    $rec['vernacularName'] = self::safe_utf8($rec['vernacularName']);
                    self::generate_VernacularEdge_row($rec);                
                }
            }

            if($what == 'generate_occur_info') { //this is occurence.tab
                /*  Array(  can be: occurrenceID	taxonID	sex
                        [occurrenceID] => e36713aea279079ed39099826601f8f6
                        [taxonID] => 1054700 )  */
                $taxonID = $rec['taxonID'];
                if(self::is_valid_taxonID($taxonID)) {
                    $scientificName = $this->taxon_info[$taxonID]['sN'];
                    $this->occur_info[$rec['occurrenceID']] = array('tI' => $taxonID, 'sN' => $scientificName, 'sx' => @$rec['sex'], 'lS' => @$rec['lifeStage']);
                }
            }
            if($what == 'generate-TraitNode-csv') { //this is MoF record
                $occurrenceID = $rec['occurrenceID'];
                if($taxon = @$this->occur_info[$occurrenceID]) { //exit("\ngoes here 10\n");
                    /*Array( $taxon
                        [tI] => 46501030
                        [sN] => Aahithis Schallreuter, 1988
                        [sx] => e.g. http://eol.org/schema/terms/maleAndFemale
                        [lS]
                    )*/
                    $taxonID = $taxon['tI'];
                    $scientificName = $taxon['sN'];
                    $sex = $taxon['sx'];
                    $lifeStage = $taxon['lS'];
                    if($taxonID && $scientificName) { //exit("\ngoes here 11\n");
                        // echo("\ntaxonID: [$taxonID] | sn: [$scientificName]\n");
                        if(self::is_valid_taxonID($taxonID)) { //exit("\ngoes here 12\n");
                            $rec['page_id'] = $taxonID;
                            $rec['scientific_name'] = $scientificName;
                            $rec['sex'] = $sex;
                            $rec['lifestage'] = $lifeStage;
                            // /* ========== start if Association
                            if(@$rec['associationID']) { 
                                $targetOccurrenceID = $rec['targetOccurrenceID'];
                                if($target_taxon = @$this->occur_info[$targetOccurrenceID]) {
                                    $rec['object_page_id'] = $target_taxon['tI'];
                                    $rec['target_scientific_name'] = $target_taxon['sN'];
                                }
                                else {
                                    $this->debug['target taxon is not valid'][$targetOccurrenceID] = '';
                                    continue;
                                }
                            }
                            // ========== */
                            // exit("\nGoes here 100\n");
                            self::generate_TraitNode_row($rec);                
                        }
                        else {
                            $this->debug['source taxon is not valid'][$taxonID] = '';
                            continue;
                        }
                    }
                }
                // if($i >= 500) break; //debug only
                //end if($what == 'generate-TraitNode-csv')
            }

            if($what == 'generate-MetadataNode-csv') { //this is MoF or Association
                self::generate_MetadataNode_row($rec);
            }
            
            if($what == 'get_reference_ids') {
                if($val = $rec['referenceID']) $this->reference_ids[$val] = '';
            }
            if($what == 'build_reference_info') { //this is reference.tab
                /*Array(
                    [identifier] => c_4f32591232b4ade18be079dba527d520
                    [publicationType] => 
                    [full_reference] => Observation photo published by db_admin
                    [primaryTitle] => 
                    [title] => 
                    [pages] => 
                    [pageStart] => 
                    [pageEnd] => 
                    [volume] => 
                    [edition] => 
                    [publisher] => 
                    [authorList] => 
                    [editorList] => 
                    [created] => 
                    [language] => 
                    [uri] => 
                    [doi] => 
                    [schema#localityName] => 
                )*/
                $ref_id = $rec['identifier'];
                if(isset($this->reference_ids[$ref_id])) {
                    $this->reference_ids[$ref_id] = array('literal' => self::format_literal($rec));
                }

            }


            
            /* copied template
            elseif($what == 'generate-measurements-csv') {
                if($rec['measurementOfTaxon'] == 'true' && !@$rec['parentMeasurementID']) {
                    self::generate_measurements_csv($rec);
                }
            }
            elseif($what == 'generate-predicates-csv')              self::generate_predicates_csv($rec);
            elseif($what == 'generate-predicates-measurements-csv') self::generate_predicates_measurements_csv($rec);
            elseif($what == 'build_association_info') self::build_association_info($rec);
            */
        }
    }
    private function format_literal($rec)
    {
        $uri_part = false;
        if($uri = $rec['uri']) {
            if(filter_var($uri, FILTER_VALIDATE_URL)) $uri_part = "<a href='$uri'>link</a>";
        }
        $literal = false;
        if($val = $rec['full_reference']) $literal = $val;
        elseif($val = $rec['primaryTitle']) $literal = $val;
        elseif($val = $rec['title']) $literal = $val;
        elseif($val = $rec['identifier']) $literal = $val;        
        if($literal) {
            if($uri_part) $literal .= " $uri_part";
            return $literal;
        }
        else return false;
    }
    private function prepareTermNode_csv()
    {
        require_library('connectors/EOLterms_ymlAPI');
        $func = new EOLterms_ymlAPI(false, false);
        $terms = $func->get_terms_yml_4Neo4j(); //from EOL terms file.
        /*[1413] => Array(
            [uri] => http://eol.org/schema/terms/determinateGrowth
            [name] => determinate growth
            [type] => value
            [definition] => determinate growth stops once a genetically pre-determined structure has completely formed
            [comment] => 
            [attribution] => https://en.wikipedia.org/wiki/Indeterminate_growth
            [section_ids] => 
            [is_hidden_from_overview] => false
            [is_hidden_from_glossary] => false
            [position] => 
            [trait_row_count] => 
            [distinct_page_count] => 
            [exclusive_to_clade] => 
            [incompatible_with_clade] => 
            [parent_term] => 
            [synonym_of] => 
            [object_for_predicate] => 
        )*/
        unset($func);

        // ===== start to create the csv
        /*  nodes/Term.csv
            uri:ID(Term-ID),name, type, definition, comment, attribution, section_ids, is_hidden_from_overview, is_hidden_from_glossary, position, trait_row_count, distinct_page_count, exclusive_to_clade, incompatible_with_clade, parent_term, synonym_of, object_for_predicate,:LABEL   */
        $this->WRITE = Functions::file_open($this->path.'/nodes/Term.csv', 'w');
        fwrite($this->WRITE, "uri:ID(Term-ID),name, type, definition, comment, attribution, section_ids, is_hidden_from_overview, is_hidden_from_glossary, position, trait_row_count, distinct_page_count, exclusive_to_clade, incompatible_with_clade, parent_term, synonym_of, object_for_predicate,:LABEL"."\n");
        foreach($terms as $rec) {
            $fields = array('uri', 'name', 'type', 'definition', 'comment', 'attribution', 'section_ids', 'is_hidden_from_overview', 'is_hidden_from_glossary', 'position', 'trait_row_count', 'distinct_page_count', 'exclusive_to_clade', 'incompatible_with_clade', 'parent_term', 'synonym_of', 'object_for_predicate');
            $csv = self::format_csv_entry($rec, $fields);
            $csv .= 'Term'; //Labels are preferred to be singular nouns
            fwrite($this->WRITE, $csv."\n");
        }
        fclose($this->WRITE);        
    }
    private function is_valid_taxonID($taxon_id)
    {
        if(isset($this->taxon_info[$taxon_id])) return true;
        else return false;
    }
    private function generate_PageNode_row($rec)
    {   /*  nodes/Page.csv
            page_id:ID(Page-ID),canonical,rank,:LABEL
            gadus_m,Gadus morhua,species,page
            chanos_c,Chanos chanos,species,page
            gadus,Gadus,genus,page
            chanos,Chanos,genus,page
        */
        $fields = array('taxonID', 'canonicalName', 'taxonRank');
        $csv = self::format_csv_entry($rec, $fields);
        $csv .= 'Page'; //Labels are preferred to be singular nouns
        fwrite($this->WRITE, $csv."\n");
    }
    private function generate_VernacularNode_row($rec)
    {   /*  nodes/Vernacular.csv
            vernacular_id:ID(Vernacular-ID),string,language_code,is_preferred_name,supplier,:LABEL
            WoRMS    Array(
                        [vernacularName] => dieren
                        [source] => 
                        [language] => DUT
                        [isPreferredName] => 0
                        [taxonID] => 2
                    )                
        */
        if($val = $rec['vernacularName']) {
            $unique_id = $val."_".$rec['taxonID']."_".$rec['language'];
            $unique_id = str_replace(" ", "_", $unique_id);
            if(!isset($this->unique_vernaculars[$unique_id])) {
                $this->unique_vernaculars[$unique_id] = '';
                $fields = array('md5_vernacularName_taxonID_language', 'vernacularName', 'language', 'isPreferredName', 'supplier');
                $rec['supplier'] = $this->param['eol_resource_id'];
                $csv = self::format_csv_entry($rec, $fields);
                $csv .= 'Vernacular'; //Labels are preferred to be singular nouns
                fwrite($this->WRITE, $csv."\n");
            }
            else $this->debug['duplicate vernaculars'][$unique_id] = '';
        }
    }
    /*
    private function generate_MetadataNode_row($rec) 
    this should loop Trait.csv not MoF.tab
    in Trait.csv add another column: metadata which has a json value: referenceID: xxx, locality: xxxx, measurementDeterminedDate: xxxx
    {   //eol_pk	trait_eol_pk	predicate	literal	measurement	value_uri	units	sex	lifestage	statistical_method	source	is_external

        // referenceID
        if($referenceID = $rec['referenceID']) {
            if($r = @$this->reference_ids[$referenceID]) {
                print_r($rec); print_r($r); exit("\nelix\n");
            }
        }    
    }
    */

    private function generate_TraitNode_row($rec)
    {   /* WoRMS
        nodes/Trait.csv
        eol_pk:ID(Trait-ID),page_id,scientific_name,resource_pk,predicate,sex,lifestage,statistical_method,object_page_id,target_scientific_name,value_uri,literal,measurement,units,normal_measurement,normal_units_uri,sample_size,citation,source,remarks,method,contributor_uri,compiler_uri,determined_by_uri,:LABEL                    
        Array( WoRMS
            [measurementID] => 6727294cfe63431fc4bd57e07223e119
            [occurrenceID] => da1da3ead698fd03083cd18c4c8942e9
            [measurementOfTaxon] => true
            [parentMeasurementID] => 
            [measurementType] => http://www.marinespecies.org/traits/SupportingStructuresEnclosures
            [measurementValue] => http://purl.obolibrary.org/obo/UBERON_0006611
            [measurementUnit] => 
            [statisticalMethod] => 
            [measurementDeterminedDate] => 
            [measurementDeterminedBy] => 
            [measurementMethod] => inherited from urn:lsid:marinespecies.org:taxname:155944, Podocopa Müller, 1894
            [measurementRemarks] => 
            [source] => http://www.marinespecies.org/aphia.php?p=taxdetails&id=769244
            [contributor] => 
            [referenceID] => 
            [page_id] => 46501030
            [scientific_name] => Aahithis Schallreuter, 1988
        )
        Array( GloBI
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
            [page_id] => 2915297
            [scientific_name] => Trichodina domerguei
            [sex] => 
            [lifestage] => 
        )*/

        // print_r($rec); exit("\nelix 2\n");
        // eol_pk	page_id	scientific_name	resource_pk	predicate	sex	lifestage	statistical_method	object_page_id	target_scientific_name	value_uri	literal	
        // measurement	units	normal_measurement	normal_units_uri	sample_size	citation	source	remarks	method	contributor_uri	compiler_uri	determined_by_uri
        $s = array();
        $s['page_id'] = $rec['page_id'];
        $s['scientific_name'] = $rec['scientific_name'];
        
        if($val = @$rec['measurementID']) $s['resource_pk'] = $val;
        elseif($val = @$rec['associationID']) $s['resource_pk'] = $val;

        if($val = @$rec['measurementType']) $s['predicate'] = $val;
        elseif($val = @$rec['associationType']) $s['predicate'] = $val;

        $s['sex'] = $rec['sex'];
        $s['lifestage'] = $rec['lifestage'];
        $s['statistical_method'] = @$rec['statisticalMethod'];
        // /* for Associations
        $s['object_page_id'] = @$rec['object_page_id'];
        $s['target_scientific_name'] = @$rec['target_scientific_name'];
        // */
        $s['value_uri'] = self::value_for($rec, 'value_uri');
        $s['literal'] = self::value_for($rec, 'literal');
        $s['measurement'] = self::value_for($rec, 'measurement');
        $s['units'] = @$rec['measurementUnit'];

        if(!self::value_is_uri_YN(@$rec['measurementValue'])) $s['normal_measurement'] = @$rec['measurementValue'];
        else                                                  $s['normal_measurement'] = '';
        if(self::value_is_uri_YN(@$rec['measurementUnit'])) $s['normal_units_uri'] = @$rec['measurementUnit'];
        else                                                $s['normal_units_uri'] = '';

        $s['sample_size'] = '';
        $s['citation'] = @$rec['bibliographicCitation'];
        $s['source'] = $rec['source']; //e.g. http://www.marinespecies.org/aphia.php?p=taxdetails&id=1034038
        $s['remarks'] = @$rec['measurementRemarks'];
        $s['method'] = @$rec['measurementMethod'];
        $s['contributor_uri'] = @$rec['contributor']; //e.g. https://www.marinespecies.org/imis.php?module=person&persid=9544
        $s['compiler_uri'] = '';
        $s['determined_by_uri'] = @$rec['measurementDeterminedBy'];
        
        $fields = array_keys($s);
        array_unshift($fields, "eol_pk"); //put 'eol_pk' to beginning of an array
        $s['eol_pk'] = $this->param['eol_resource_id'].'_'.md5(json_encode($s));

        // /* for Metadata
        $s['metadata'] = self::build_metadata_json($rec);
        // */

        $csv = self::format_csv_entry($s, $fields);
        $csv .= 'Trait'; //Labels are preferred to be singular nouns
        fwrite($this->WRITE, $csv."\n");
    }
    private function value_for($rec, $field)
    {
        if($measurementValue = @$rec['measurementValue']) {
            if($field == 'value_uri') {
                if(self::value_is_uri_YN($measurementValue)) return $measurementValue;
            }
            if($field == 'literal') { //can be measurementValue that is URI = http://eol.org/schema/terms/extinct OR uncontrolled vocab = 'extinct' But not numeric e.g. 100
                if(!is_numeric($measurementValue)) return $measurementValue;
            }
            if($field == 'measurement') { //numeric values
                if(is_numeric($measurementValue)) return $measurementValue;
            }
        }
    }
    private function generate_ParentEdge_row($rec)
    {   /*  edges/parent.csv
            page_id:START_ID(Page-ID),page_id:END_ID(Page-ID),:TYPE
            gadus_m,gadus,parent
            chanos_c,chanos,parent */
        $fields = array('taxonID', 'parentNameUsageID');
        $csv = self::format_csv_entry($rec, $fields);
        $csv .= 'PARENT'; //Type are preferred to be singular nouns
        fwrite($this->WRITE, $csv."\n");
    }
    private function generate_VernacularEdge_row($rec)
    {   /*  edges/vernacular.csv
            page_id:START_ID(Page-ID),vernacular_id:END_ID(Vernacular-ID),:TYPE
            WoRMS    Array(
                        [vernacularName] => dieren
                        [source] => 
                        [language] => DUT
                        [isPreferredName] => 0
                        [taxonID] => 2
                    )                
            */
        $fields = array('taxonID', 'md5_vernacularName_taxonID_language');
        $csv = self::format_csv_entry($rec, $fields);
        $csv .= 'VERNACULAR'; //Type are preferred to be singular nouns
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
    private function prepare_PageNode_csv_from_resource($meta)
    {   /*  Array(
                [taxonID] => 44475
                [source] => https://www.wikidata.org/wiki/Q25243
                [parentNameUsageID] => Q4085525
                [scientificName] => Betula
                [higherClassification] => Biota|Eukaryota|Plantae|Viridiplantae|Streptophyta|Embryophytes|Tracheophytes|Spermatophytes|Magnoliophyta|Magnoliopsida|Hamamelididae|Juglandanae|Corylales|Betulaceae|Betuloideae|
                [taxonRank] => genus
                [scientificNameAuthorship] => Carl Linnaeus, 1753
                [vernacularName] => birches
                [taxonRemarks] => With higherClassification but cannot be mapped to any index group.
                [canonicalName] => Betula
                [EOLid] => 44475
            )
            nodes/Page.csv
            page_id:ID(Page-ID),canonical,rank,:LABEL
            gadus_m,Gadus morhua,species,page
            chanos_c,Chanos chanos,species,page
            gadus,Gadus,genus,page
            chanos,Chanos,genus,page
        */
        $this->WRITE = Functions::file_open($this->path.'/nodes/Page.csv', 'w');
        fwrite($this->WRITE, "page_id:ID(Page-ID){label:Page},canonical,rank,:LABEL"."\n");
        self::process_table($meta, 'generate-PageNode-csv');
        fclose($this->WRITE);
    }
    private function prepare_PageNode_csv_from_DH()
    {
        require_library('connectors/DHConnLib');
        $func = new DHConnLib();

        // Page Node
        $WRITE = Functions::file_open($this->path.'/nodes/Page.csv', 'w');
        fwrite($WRITE, "page_id:ID(Page-ID){label:Page},canonical,rank,:LABEL"."\n");
        $param = array('task' => 'generate_PageNode_csv', 'fhandle' => $WRITE);
        $ret = $func->do_things_from_DH($param);
        fclose($WRITE);

        // start Parent Edge
        $WRITE = Functions::file_open($this->path.'/edges/Parent.csv', 'w');
        fwrite($WRITE, "page_id:START_ID(Page-ID),page_id:END_ID(Page-ID),:TYPE"."\n");
        $param = array('task' => 'generate_ParentEdge_csv', 'fhandle' => $WRITE);
        $ret = $func->prepare_ParentEdge($param);
        fclose($WRITE);

        unset($func);
    }
    private function prepare_TRAIT_Edge_csv()
    {
        $WRITE = Functions::file_open($this->path.'/edges/Trait.csv', 'w');
        fwrite($WRITE, "page_id:START_ID(Page-ID),eol_pk:END_ID(Trait-ID),:TYPE"."\n");
        $param = array('task' => 'generate_TRAIT_Edge_csv', 'fhandle' => $WRITE);
        $ret = self::do_things_in_a_csv($param);
        fclose($WRITE);
    }
    private function prepare_INFERRED_TRAIT_Edge_csv()
    {
        $WRITE = Functions::file_open($this->path.'/edges/Inferred_Trait.csv', 'w');
        fwrite($WRITE, "page_id:START_ID(Page-ID),eol_pk:END_ID(Trait-ID),:TYPE"."\n");
        $param = array('task' => 'generate_INFERRED_TRAIT_Edge_csv', 'fhandle' => $WRITE);
        $ret = self::do_things_in_a_csv($param);
        fclose($WRITE);
    }
    private function prepare_PREDICATE_Edge_csv()
    {
        $WRITE = Functions::file_open($this->path.'/edges/Predicate.csv', 'w');
        fwrite($WRITE, "eol_pk:START_ID(Trait-ID),uri:END_ID(Term-ID),:TYPE"."\n");
        $param = array('task' => 'generate_PREDICATE_Edge_csv', 'fhandle' => $WRITE);
        $ret = self::do_things_in_a_csv($param);
        fclose($WRITE);
    }
    private function prepare_OBJECT_TERM_Edge_csv()
    {
        $WRITE = Functions::file_open($this->path.'/edges/Object_Term.csv', 'w');
        fwrite($WRITE, "eol_pk:START_ID(Trait-ID),uri:END_ID(Term-ID),:TYPE"."\n");
        $param = array('task' => 'generate_OBJECT_TERM_Edge_csv', 'fhandle' => $WRITE);
        $ret = self::do_things_in_a_csv($param);
        fclose($WRITE);
    }
    private function prepare_NORMAL_UNITS_TERM_Edge_csv()
    {
        $WRITE = Functions::file_open($this->path.'/edges/Normal_Units_Term.csv', 'w');
        fwrite($WRITE, "eol_pk:START_ID(Trait-ID),uri:END_ID(Term-ID),:TYPE"."\n");
        $param = array('task' => 'generate_NORMAL_UNITS_TERM_Edge_csv', 'fhandle' => $WRITE);
        $ret = self::do_things_in_a_csv($param);
        fclose($WRITE);
    }
    private function prepare_UNITS_TERM_Edge_csv()
    {
        $WRITE = Functions::file_open($this->path.'/edges/Units_Term.csv', 'w');
        fwrite($WRITE, "eol_pk:START_ID(Trait-ID),uri:END_ID(Term-ID),:TYPE"."\n");
        $param = array('task' => 'generate_UNITS_TERM_Edge_csv', 'fhandle' => $WRITE);
        $ret = self::do_things_in_a_csv($param);
        fclose($WRITE);
    }
    private function prepare_OBJECT_PAGE_Edge_csv()
    {
        $WRITE = Functions::file_open($this->path.'/edges/Object_Page.csv', 'w');
        fwrite($WRITE, "eol_pk:START_ID(Trait-ID),page_id:END_ID(Page-ID),:TYPE"."\n");
        $param = array('task' => 'generate_OBJECT_PAGE_Edge_csv', 'fhandle' => $WRITE);
        $ret = self::do_things_in_a_csv($param);
        fclose($WRITE);
    }
    private function prepare_DETERMINED_BY_Edge_csv()
    {
        $WRITE = Functions::file_open($this->path.'/edges/Determined_By.csv', 'w');        
        fwrite($WRITE, "eol_pk:START_ID(Trait-ID),uri:END_ID(Term-ID),:TYPE"."\n");
        $param = array('task' => 'generate_DETERMINED_BY_Edge_csv', 'fhandle' => $WRITE);
        $ret = self::do_things_in_a_csv($param);
        fclose($WRITE);
    }
    private function prepare_CONTRIBUTOR_Edge_csv()
    {
        $WRITE = Functions::file_open($this->path.'/edges/Contributor.csv', 'w');        
        fwrite($WRITE, "eol_pk:START_ID(Trait-ID),uri:END_ID(Term-ID),:TYPE"."\n");
        $param = array('task' => 'generate_CONTRIBUTOR_Edge_csv', 'fhandle' => $WRITE);
        $ret = self::do_things_in_a_csv($param);
        fclose($WRITE);
    }


    private function prepare_SUPPLIER_Edge_csv()
    {
        $WRITE = Functions::file_open($this->path.'/edges/Supplier.csv', 'w');
        fwrite($WRITE, "eol_pk:START_ID(Trait-ID),resource_id:END_ID(Resource-ID),:TYPE"."\n");
        $param = array('task' => 'generate_SUPPLIER_Edge_csv', 'fhandle' => $WRITE);
        $ret = self::do_things_in_a_csv($param);
        fclose($WRITE);
    }

    private function do_things_in_a_csv($param)
    {
        $task = $param['task']; echo "\ntask: [$task]\n";
        $fhandle = $param['fhandle'];
        // ---------- start customize part ----------
        if($param['task'] == 'generate_TRAIT_Edge_csv') {
            $csv_file = $this->path.'/nodes/Trait.csv'; //source
        }
        elseif($param['task'] == 'generate_INFERRED_TRAIT_Edge_csv') {
            $csv_file = $this->path.'/nodes/Trait.csv'; //source
        }
        elseif($param['task'] == 'generate_PREDICATE_Edge_csv') {
            $csv_file = $this->path.'/nodes/Trait.csv'; //source
        }
        elseif($param['task'] == 'generate_OBJECT_TERM_Edge_csv') {
            $csv_file = $this->path.'/nodes/Trait.csv'; //source
        }
        elseif($param['task'] == 'generate_NORMAL_UNITS_TERM_Edge_csv') {
            $csv_file = $this->path.'/nodes/Trait.csv'; //source
        }
        elseif($param['task'] == 'generate_UNITS_TERM_Edge_csv') {
            $csv_file = $this->path.'/nodes/Trait.csv'; //source
        }
        elseif($param['task'] == 'generate_OBJECT_PAGE_Edge_csv') {
            $csv_file = $this->path.'/nodes/Trait.csv'; //source
        }
        elseif($param['task'] == 'generate_DETERMINED_BY_Edge_csv') {
            $csv_file = $this->path.'/nodes/Trait.csv'; //source
        }
        elseif($param['task'] == 'generate_CONTRIBUTOR_Edge_csv') {
            $csv_file = $this->path.'/nodes/Trait.csv'; //source
        }
        elseif($param['task'] == 'generate_SUPPLIER_Edge_csv') {
            $csv_file = $this->path.'/nodes/Trait.csv'; //source
        }
        // ---------- end customize part ----------
        if($this->param['eol_resource_id'] == 'globi') $mod = 50000;
        else                                           $mod = 5000;
        $i = 0;
        $file = Functions::file_open($csv_file, "r");
        while(!feof($file)) {
            $row = fgetcsv($file);
            if(!$row) break;
            // $row = self::clean_html($row); print_r($row);
            $i++; if(($i % $mod) == 0) echo "\n $i ";
            if($i == 1) {
                $fields = $row;
                $fields = array_map('trim', $fields);
                // $fields = self::fill_up_blank_fieldnames($fields);
                $count = count($fields);
                // print_r($fields);
            }
            else { //main records
                $values = $row;
                if($count != count($values)) { //row validation - correct no. of columns
                    // print_r($values); print_r($rec);
                    exit("\nERROR: Wrong CSV format for this row.\n");
                    // $this->debug['wrong csv'][$class]['identifier'][$rec['identifier']] = '';
                    continue;
                }
                $k = 0;
                $rec = array();
                foreach($fields as $field) {
                    $rec[$field] = $values[$k];
                    $k++;
                }
                $rec = array_map('trim', $rec); //print_r($rec); exit("\nstopx\n");
                /*Array(
                    [eol_pk:ID(Trait-ID)] => worms_38a1316e08d5c41d90ac3f4220a9ee77
                    [page_id] => 46501030
                    [scientific_name] => Aahithis Schallreuter, 1988
                    [resource_pk] => 6727294cfe63431fc4bd57e07223e119
                    [predicate] => http://www.marinespecies.org/traits/SupportingStructuresEnclosures
                    [sex] => 
                    [lifestage] => 
                    [statistical_method] => 
                    [object_page_id] => 
                    [target_scientific_name] => 
                    [value_uri] => http://purl.obolibrary.org/obo/UBERON_0006611
                    [literal] => http://purl.obolibrary.org/obo/UBERON_0006611
                    [measurement] => 
                    [units] => 
                    [normal_measurement] => 
                    [normal_units_uri] => 
                    [sample_size] => 
                    [citation] => 
                    [source] => http://www.marinespecies.org/aphia.php?p=taxdetails&id=769244
                    [remarks] => 
                    [method] => inherited from urn:lsid:marinespecies.org:taxname:155944, Podocopa Müller, 1894
                    [contributor_uri] => 
                    [compiler_uri] => 
                    [determined_by_uri] => 
                    [:LABEL] => Trait
                )*/

                if($task == 'generate_TRAIT_Edge_csv') { //page_id:START_ID(Page-ID),eol_pk:END_ID(Trait-ID),:TYPE
                    if(!self::trait_is_inferred_YN($rec['remarks'])) {
                        $fieldz = array('page_id', 'eol_pk:ID(Trait-ID)');
                        $csv = self::format_csv_entry($rec, $fieldz);
                        $csv .= 'TRAIT'; //relationships are designed to be in upper-case
                        fwrite($fhandle, $csv."\n");
                    }
                }
                elseif($task == 'generate_INFERRED_TRAIT_Edge_csv') { //page_id:START_ID(Page-ID),eol_pk:END_ID(Trait-ID),:TYPE
                    if(self::trait_is_inferred_YN($rec['remarks'])) {
                        $fieldz = array('page_id', 'eol_pk:ID(Trait-ID)');
                        $csv = self::format_csv_entry($rec, $fieldz);
                        $csv .= 'INFERRED_TRAIT'; //relationships are designed to be in upper-case
                        fwrite($fhandle, $csv."\n");
                    }
                }
                
                if($task == 'generate_PREDICATE_Edge_csv') { //predicate:START_ID(Trait),uri:ID(Term-ID),:TYPE
                    /*Array( from Trait.csv
                        [eol_pk:ID(Trait-ID)] => worms_38a1316e08d5c41d90ac3f4220a9ee77
                        [page_id] => 46501030
                        [scientific_name] => Aahithis Schallreuter, 1988
                        [resource_pk] => 6727294cfe63431fc4bd57e07223e119
                        [predicate] => http://www.marinespecies.org/traits/SupportingStructuresEnclosures
                        [sex] => 
                        [lifestage] => 
                        [statistical_method] => 
                        [object_page_id] => 
                        [target_scientific_name] => 
                        [value_uri] => http://purl.obolibrary.org/obo/UBERON_0006611
                        [literal] => http://purl.obolibrary.org/obo/UBERON_0006611
                        [measurement] => 
                        [units] => 
                        [normal_measurement] => 
                        [normal_units_uri] => 
                        [sample_size] => 
                        [citation] => 
                        [source] => http://www.marinespecies.org/aphia.php?p=taxdetails&id=769244
                        [remarks] => 
                        [method] => inherited from urn:lsid:marinespecies.org:taxname:155944, Podocopa Müller, 1894
                        [contributor_uri] => 
                        [compiler_uri] => 
                        [determined_by_uri] => 
                        [:LABEL] => Trait
                    )*/
                    // print_r($rec); exit("\nstop 4\n");
                    if(!self::URI_in_EOL_terms_YN($rec['predicate'])) continue; //not found in EOL Terms file
                    $fieldz = array('eol_pk:ID(Trait-ID)', 'predicate');
                    $csv = self::format_csv_entry($rec, $fieldz);
                    $csv .= 'PREDICATE'; //relationships are designed to be in upper-case
                    fwrite($fhandle, $csv."\n");
                }
                if($task == 'generate_OBJECT_TERM_Edge_csv') {
                    // if($rec['value_uri'] == "null") continue; //cannot be blank //didn't work
                    if(!$rec['value_uri']) continue; //cannot be blank                    
                    if(!self::value_is_uri_YN($rec['value_uri'])) continue; //should always be a valid URI
                    if(!self::URI_in_EOL_terms_YN($rec['value_uri'])) continue; //not found in EOL Terms file
                    $fieldz = array('eol_pk:ID(Trait-ID)', 'value_uri');
                    $csv = self::format_csv_entry($rec, $fieldz);
                    $csv .= 'OBJECT_TERM'; //relationships are designed to be in upper-case
                    fwrite($fhandle, $csv."\n");
                }
                if($task == 'generate_NORMAL_UNITS_TERM_Edge_csv') {
                    if(!$rec['normal_measurement']) continue; //cannot be blank                                        
                    if(!$rec['normal_units_uri']) continue; //cannot be blank                    
                    if(!self::value_is_uri_YN($rec['normal_units_uri'])) continue; //should always be a valid URI
                    if(!self::URI_in_EOL_terms_YN($rec['normal_units_uri'])) continue; //not found in EOL Terms file
                    $fieldz = array('eol_pk:ID(Trait-ID)', 'normal_units_uri');
                    $csv = self::format_csv_entry($rec, $fieldz);
                    $csv .= 'NORMAL_UNITS_TERM'; //relationships are designed to be in upper-case
                    fwrite($fhandle, $csv."\n");
                }
                if($task == 'generate_UNITS_TERM_Edge_csv') {
                    if(!$rec['measurement']) continue; //cannot be blank                                        
                    if(!$rec['units']) continue; //cannot be blank                    
                    if(!self::value_is_uri_YN($rec['units'])) continue; //should always be a valid URI
                    if(!self::URI_in_EOL_terms_YN($rec['units'])) continue; //not found in EOL Terms file
                    $fieldz = array('eol_pk:ID(Trait-ID)', 'units');
                    $csv = self::format_csv_entry($rec, $fieldz);
                    $csv .= 'UNITS_TERM'; //relationships are designed to be in upper-case
                    fwrite($fhandle, $csv."\n");
                }
                if($task == 'generate_OBJECT_PAGE_Edge_csv') {
                    // if($rec['object_page_id'] == "null") continue; //cannot be blank //didn't work
                    if(!$rec['object_page_id']) continue; //cannot be blank                                        
                    $fieldz = array('eol_pk:ID(Trait-ID)', 'object_page_id');
                    $csv = self::format_csv_entry($rec, $fieldz);
                    $csv .= 'OBJECT_PAGE'; //relationships are designed to be in upper-case
                    fwrite($fhandle, $csv."\n");
                }
                if($task == 'generate_DETERMINED_BY_Edge_csv') {
                    if(!$rec['determined_by_uri']) continue; //cannot be blank                    
                    if(!self::value_is_uri_YN($rec['determined_by_uri'])) continue; //should always be a valid URI
                    if(!self::URI_in_EOL_terms_YN($rec['determined_by_uri'])) continue; //not found in EOL Terms file
                    $fieldz = array('eol_pk:ID(Trait-ID)', 'determined_by_uri');
                    $csv = self::format_csv_entry($rec, $fieldz);
                    $csv .= 'DETERMINED_BY'; //relationships are designed to be in upper-case
                    fwrite($fhandle, $csv."\n");
                }
                if($task == 'generate_CONTRIBUTOR_Edge_csv') {
                    if(!$rec['contributor_uri']) continue; //cannot be blank                    
                    if(!self::value_is_uri_YN($rec['contributor_uri'])) continue; //should always be a valid URI
                    if(!self::URI_in_EOL_terms_YN($rec['contributor_uri'])) continue; //not found in EOL Terms file
                    $fieldz = array('eol_pk:ID(Trait-ID)', 'contributor_uri');
                    $csv = self::format_csv_entry($rec, $fieldz);
                    $csv .= 'CONTRIBUTOR'; //relationships are designed to be in upper-case
                    fwrite($fhandle, $csv."\n");
                }

                if($task == 'generate_SUPPLIER_Edge_csv') { //eol_pk:START_ID(Trait-ID),resource_id:END_ID(Resource-ID),:TYPE
                    $fieldz = array('eol_pk', 'supplier');
                    $rek = array();
                    $rek['eol_pk'] = $rec['eol_pk:ID(Trait-ID)'];
                    $rek['supplier'] = $this->param['eol_resource_id'];
                    $csv = self::format_csv_entry($rek, $fieldz);
                    $csv .= 'SUPPLIER'; //relationships are designed to be in upper-case
                    fwrite($fhandle, $csv."\n");
                }
            } //end main records
        } //end while()
    }
    private function URI_in_EOL_terms_YN($predicate)
    {
        if(in_array($predicate, array('http://purl.obolibrary.org/obo/RO_0008509', 'http://purl.obolibrary.org/obo/RO_0002555', 'http://purl.obolibrary.org/obo/RO_0002236'))) return false;
        return true;
    }
    private function trait_is_inferred_YN($remarks)
    {
        if(substr($remarks, 0, 12) == 'source text:') return true;
        else return false;
    }
    private function prepare_VernacularNode_csv($meta)
    {   /*  nodes/Vernacular.csv
            vernacular_id:ID(Vernacular-ID),supplier,string,language_code,is_preferred_name,:LABEL   */
        $this->WRITE = Functions::file_open($this->path.'/nodes/Vernacular.csv', 'w');
        fwrite($this->WRITE, "vernacular_id:ID(Vernacular-ID),string,language_code,is_preferred_name,supplier,:LABEL"."\n");
        if($meta) self::process_table($meta, 'generate-VernacularNode-csv');
        fclose($this->WRITE);
    }
    private function prepare_TraitNode_csv($meta)
    {   /*  nodes/Trait.csv
            eol_pk:ID(Trait-ID),page_id,scientific_name,resource_pk,predicate,sex,lifestage,statistical_method,object_page_id,target_scientific_name,value_uri,literal,measurement,units,normal_measurement,normal_units_uri,sample_size,citation,source,remarks,method,contributor_uri,compiler_uri,determined_by_uri,:LABEL
        */
        $this->WRITE = Functions::file_open($this->path.'/nodes/Trait.csv', 'w');
        fwrite($this->WRITE, "eol_pk:ID(Trait-ID),page_id,scientific_name,resource_pk,predicate,sex,lifestage,statistical_method,object_page_id,target_scientific_name,value_uri,literal,measurement,units,normal_measurement,normal_units_uri,sample_size,citation,source,remarks,method,contributor_uri,compiler_uri,determined_by_uri,:LABEL"."\n");
        self::process_table($meta, 'generate-TraitNode-csv');
        fclose($this->WRITE);
    }
    private function prepare_MetadataNode_csv($meta)
    {   /*  nodes/Metadata.csv
            eol_pk:ID(Metadata-ID),trait_eol_pk,predicate,literal,measurement,value_uri,units,sex,lifestage,statistical_method,source,is_external,:LABEL
            eol_pk	trait_eol_pk	predicate	literal	measurement	value_uri	units	sex	lifestage	statistical_method	source	is_external            
        */
        $this->WRITE = Functions::file_open($this->path.'/nodes/Metadata.csv', 'w');
        fwrite($this->WRITE, "eol_pk:ID(Metadata-ID),trait_eol_pk,predicate,literal,measurement,value_uri,units,sex,lifestage,statistical_method,source,is_external,:LABEL"."\n");
        self::process_table($meta, 'generate-MetadataNode-csv');
        fclose($this->WRITE);
    }
    private function prepare_ResourceNode_csv()
    {   /*  nodes/Resource.csv
            resource_id:ID(Resource-ID),name,:LABEL   */
        $this->WRITE = Functions::file_open($this->path.'/nodes/Resource.csv', 'w');
        fwrite($this->WRITE, "resource_id:ID(Resource-ID),name,:LABEL"."\n");
        // $this->EOL_resources['worms']       = array('eol_resource_id' => 'worms',     'resource_name' => 'World Register of Marine Species');
        // $this->EOL_resources['wikipedia']   = array('eol_resource_id' => 'wikipedia', 'resource_name' => 'Wikipedia English - traits (inferred records)');
        foreach($this->EOL_resources as $eol_resource_id => $rec) {
            $fields = array('eol_resource_id', 'resource_name');
            $csv = self::format_csv_entry($rec, $fields);
            $csv .= 'Resource'; //Labels are preferred to be singular nouns
            fwrite($this->WRITE, $csv."\n");
        }
        fclose($this->WRITE);
    }
    private function prepare_ParentEdge_csv($meta)
    {   /*  edges/parent.csv
            page_id:START_ID(Page-ID),page_id:END_ID(Page-ID),:TYPE
            gadus_m,gadus,parent
            chanos_c,chanos,parent
        */
        $this->WRITE = Functions::file_open($this->path.'/edges/Parent.csv', 'w');
        fwrite($this->WRITE, "page_id:START_ID(Page-ID),page_id:END_ID(Page-ID),:TYPE"."\n");
        self::process_table($meta, 'generate-ParentEdge-csv');
        fclose($this->WRITE);
    }
    private function prepare_VernacularEdge_csv($meta)
    {   /*  edges/vernacular.csv
            personId:START_ID(Person-ID),posterId:END_ID(Poster-ID),:TYPE
            page_id:START_ID(Page-ID),vernacular_id:END_ID(Vernacular-ID),:TYPE
        */
        $this->WRITE = Functions::file_open($this->path.'/edges/Vernacular.csv', 'w');
        fwrite($this->WRITE, "page_id:START_ID(Page-ID),vernacular_id:END_ID(Vernacular-ID),:TYPE"."\n");
        if($meta) self::process_table($meta, 'generate-VernacularEdge-csv');
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
    private function initialize_folders($resource_id)
    {
        $path = CONTENT_RESOURCE_LOCAL_PATH . 'neo4j_imports';
        if(!is_dir($path)) mkdir($path);
        $path .= '/' . $resource_id . '_csv';
        if(is_dir($path)) recursive_rmdir($path);
        mkdir($path);
        $this->path = $path;
        $temp_dir = $path.'/nodes'; mkdir($temp_dir);
        $temp_dir = $path.'/edges'; mkdir($temp_dir);
    }
    // private function clean_csv_item($str)
    // {
    //     if($str) return str_replace('"', '""', $str);
    //     else return $str;
    // }
    function format_csv_entry($rec, $fields)
    {
        $csv = ""; $i = -1;
        foreach($fields as $field) { $i++;
            if(substr($field,0,4) == 'md5_') { //e.g. md5_vernacularName_taxonID
                $val = self::process_md5_fields($field, $rec);
            }
            else $val = @$rec[$field];
            /* working OK
            $tmp = '"' . self::clean_csv_item($val) . '",';
            $csv .= $tmp;
            */
            if($i > 0) $csv .= ','; // Add delimiter for all but the first field
            $csv .= Functions::manuallyEscapeForCSV($val);
        }
        $csv .= ','; //add comma as last char
        return $csv;
    }
    private function format_csv_entry_array($arr)
    {
        $csv = ""; $i = -1;
        foreach($arr as $val) { $i++;
            /* working OK
            $tmp = '"' . self::clean_csv_item($val) . '",';
            $csv .= $tmp;
            */
            if($i > 0) $csv .= ','; // Add delimiter for all but the first field
            $csv .= Functions::manuallyEscapeForCSV($val);
        }
        return $csv;
    }
    private function small_field($uri)
    {
        return pathinfo($uri, PATHINFO_FILENAME);
    }
    private function process_md5_fields($str, $rec) //e.g. "md5_vernacularName_taxonID"
    {
        $fields = explode("_", $str);
        array_shift($fields);

        $combined = "";
        foreach($fields as $field) {
            $val = @$rec[$field];
            // $combined .= self::clean_csv_item($val) . '_'; //replaced by one below
            $combined .= Functions::manuallyEscapeForCSV($val) . '_'; 
        }
        $combined = substr($combined, 0, -1); //remove last char: "plants_42430800_" becomes "plants_42430800"
        $combined = str_replace(" ", "_", $combined);
        // exit("\ncombined: [$combined]\n");
        // return $combined;
        return md5($combined);
    }
    private function safe_utf8($text)
    {
        return $text;
        // below messes up chars not working
        // $encoding = mb_detect_encoding($text, "UTF-8, ISO-8859-1, Windows-1252", true);
        // if ($encoding !== false) {
        //     $utf8String = mb_convert_encoding($text, "UTF-8", $encoding);
        //     return $utf8String;
        // } else {
        //     // Handle the case where encoding could not be reliably detected
        //     exit("\nCould not detect encoding, unable to convert safely.\n");
        // }
    }
    private function prep_dwca($resource_id, $dwca_file)
    {
        require_library('connectors/ResourceUtility');
        $func = new ResourceUtility(false, $resource_id);
        $ret = $func->prepare_archive_for_access($dwca_file, $this->download_options);
        $temp_dir = $ret['temp_dir'];
        $tables = $ret['tables'];
        if(!($tables["http://rs.tdwg.org/dwc/terms/taxon"][0]->fields)) { // take note the index key is all lower case
            debug("Invalid archive file. Program will terminate."); return false;
        } else echo "\nValid DwCA [$resource_id].\n";
        return $ret;
    }
    private function value_is_uri_YN($value)
    {
        if(!$value) return false;
        if(substr($value, 0, 5) == 'http:') return true;
        if(substr($value, 0, 6) == 'https:') return true;
        return false;
    }
    private function build_metadata_json($rec) //this is MoF or Association
    {
        // http://rs.tdwg.org/dwc/terms/locality                        ==>> locality comes from a child MoF record
        // http://eol.org/schema/reference/referenceID                  ==>> from MoF
        // http://rs.tdwg.org/dwc/terms/measurementDeterminedDate       ==>> from MoF
        
        if(@$rec['measurementDeterminedDate'] || @$rec['referenceID']) {
            $arr = array('mDD' => @$rec['measurementDeterminedDate'], 'rI' => @$rec['referenceID']);
            return json_encode($arr);
        }
        else return false;

        /* locality comes from a child MoF record
        measurementID	occurrenceID	measurementOfTaxon	parentMeasurementID	measurementType	measurementValue	measurementUnit	statisticalMethod	measurementDeterminedDate	measurementDeterminedBy	measurementMethod	measurementRemarks	source	referenceID	contributor        
        015afbb5e4398e462b257aa2b50cd48e	b57cedf8a4df37545cd3fcb528a47eb2	true		http://purl.obolibrary.org/obo/CMO_0000013	1	http://purl.obolibrary.org/obo/UO_0000015	http://semanticscience.org/resource/SIO_001114					http://www.marinespecies.org/aphia.php?p=taxdetails&id=103235		
        25ef920b4f642c4accad4cae3f08ea7e			015afbb5e4398e462b257aa2b50cd48e	http://rs.tdwg.org/dwc/terms/locality	http://www.geonames.org/6255148									
        */
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