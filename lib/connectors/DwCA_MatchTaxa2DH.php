<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from match_taxa_2DH.php] 
These ff. workspaces work together:
- generate_higherClassification_8.code-workspace
- DHConnLib_8.code-workspace
- GNParserAPI_8.code-workspace
- DwCA_MatchTaxa2DH.code-workspace
- UseEOLidInTaxon.code-workspace
- GenerateCSV_4Neo4j.code-workspace

10088_6943_ENV
tar -czf 10088_6943_ENV.tar.gz 10088_6943_ENV/
SIcontrib2Botany
tar -czf SIcontrib2Botany.tar.gz SIcontrib2Botany/
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class DwCA_MatchTaxa2DH extends DwCA_MatchTaxa2DH_Functions
{
    function __construct($archive_builder, $resource_id, $archive_path, $AncestryIndexVer = 'none')
    {
        $this->resource_id = $resource_id;
        $this->AncestryIndexVer = $AncestryIndexVer; //exit("\nAncestryIndexVer: [".$AncestryIndexVer."]\n");
        $this->archive_builder = $archive_builder;
        $this->archive_path = $archive_path;
        // $this->paths['wikidata_hierarchy'] = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/master/wikidata/wikidataEOLidMappings.txt';
        $this->debug = array();

        // In general it's ok to match taxa with different ranks if the taxa have higher ranks like phyla, classes, and orders.
        $this->ok_match_higher_ranks = array('phylum', 'class', 'order');
        // It's also ok to match taxa with different ranks if both taxa have a subspecific rank, 
        // e.g., subspecies | variety | form | forma | infraspecies | infraspecific name | infrasubspecific name | subvariety | subform | proles | lusus | forma specialis
        $this->ok_match_subspecific_ranks = array('subspecies', 'variety', 'form', 'forma', 'infraspecies', 'infraspecific name', 'infrasubspecific name', 'subvariety', 'subform', 'proles', 'lusus', 'forma specialis');
        $this->ok_match_subspecific_ranks[] = 'species group';
        $this->ok_match_subspecific_ranks[] = 'species subgroup';
        
        // tar -czf Brazilian_Flora_Eli_neo4j_1.tar.gz Brazilian_Flora_Eli_neo4j_1/ -> generate .tar.gz

        $this->g_kingdom_domain = array('domain', 'kingdom');
        $this->g_phylum = array('phylum', 'division', 'subphylum');
        $this->g_class = array('class', 'subclass', 'superclass', 'infraclass', 'subterclass');
        $this->g_order = array('order', 'suborder', 'superorder', 'infraorder', 'parvorder');
        $this->g_family = array('family', 'subfamily', 'superfamily', 'epifamily');
        $this->g_tribe = array('tribe', 'supertribe', 'subtribe');
        $this->g_genus = array('genus', 'subgenus', 'genus group');
        $this->g_section = array('section', 'subsection', 'series');
        $this->g_species = array_merge(array('species'), $this->ok_match_subspecific_ranks);

        $this->download_options = array('cache' => 1, 'resource_id' => 'neo4j', 'expire_seconds' => 60*60*24*1, 'download_wait_time' => 1000000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        $this->ancestry_index_file_old = "https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/Ancestry_Index.tsv";
        $this->ancestry_index_file = "https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/AncestryIndex_new.tsv";

        $temp = CONTENT_RESOURCE_LOCAL_PATH . 'neo4j_debug'; if(!is_dir($temp)) mkdir($temp);
        $this->neo4j_debug_folder = $temp;

        $this->stats_path = CONTENT_RESOURCE_LOCAL_PATH . "/neo4j_debug/".$this->resource_id."_logs_".$this->AncestryIndexVer;
        if(is_dir($this->stats_path)) recursive_rmdir($this->stats_path);
        mkdir($this->stats_path);
        /*
        $pattern = '/.*?\|Chordata\|(.*?\|)?Leptocephalus\|.*?/';
        preg_match($pattern, $subject, $matches);
        */

        $this->run_debug2_YN = true;
        $this->run_debug3_YN = true;
        $this->run_debug4_YN = true;
        // if($this->resource_id == '[TreatmentBank_final-with-hC_neo4j_1_eolID]') {
        //     $this->run_debug2_YN = false;
        //     $this->run_debug3_YN = false;
        //     $this->run_debug4_YN = false;
        // }
        $this->debug3 = array();
        $this->debug4 = array();

        /* ===== start of entire detailed workflow ===== */
        $ranks = array('subspecies', 'variety', 'form', 'forma', 'infraspecies', 'infraspecific name', 'infrasubspecific name', 'subvariety', 'subform', 'proles', 'lusus', 'forma specialis');
        foreach($ranks as $rank) $this->subspecific_ranks[$rank] = '';
        $this->debugNow = false;
    }
    /*================================================================= STARTS HERE ======================================================================*/
    private function initialize()
    {
        $this->compatibleAncestors = $this->get_compatibleAncestors();
        require_library('connectors/DwCA_Utility_cmd');
    }
    function start($info)
    {
        self::initialize();
        // /* step 1: read info from DH
        require_library('connectors/DHConnLib');
        $this->DH = new DHConnLib(1);
        $this->DH->build_up_taxa_info(); //generates 4 info lookups
        echo "\naaa2:".count($this->DH->DHCanonical_info)."";
        echo "\nxxx2:".count($this->DH->DH)."";                                     // -> from DH: $this->DH[$taxonID] = array("c" => $canonicalName, "r" => $taxonRank); //get all records, should be no filter here
        echo "\nyyy2:".count($this->DH->DH_synonyms)."";                            // -> from DH: $this->DH_synonyms[$taxonID] = $acceptedNameUsageID;
        echo "\nzzz2:".count($this->DH->DH_acceptedNames)."\n"; //exit("\n");       // -> from DH: $this->DH_acceptedNames[$acceptedNameUsageID][$taxonID] = '';
        // */

        /* This is a good synonyms test
        // Array(
        //     [0] => Array(
        //             [r] => genus
        //             [e] => 
        //             [h] => 
        //             [c] => Rotula
        //             [t] => SYN-100000458295
        //             [s] => n
        //         )
        // )
        echo "\nThis is the synonym ID: [SYN-100000458295]";
        $acceptedNameUsageID = $this->DH->DH_synonyms['SYN-100000458295'];
        echo "\nThis is the acceptedNameUsageID: [$acceptedNameUsageID]";
        $accepted_rec = $this->DH->DH[$acceptedNameUsageID];
        echo "\nThis is the accepted record: "; print_r($accepted_rec);
        $rek = $this->DH->DHCanonical_info[$accepted_rec['c']][$acceptedNameUsageID];
        echo "This is a more complete record: "; print_r($rek);
        exit("\n-stop test-\n");
        */


        /* print_r($this->DH->DHCanonical_info['Aa brevis']);
        Array(
            [SYN-000000780034] => Array(
                    [r] => species
                    [e] => 
                    [h] => 
                    [c] => Aa brevis
                    [t] => SYN-000000780034
                    [s] => n
                )
        ) */

        // /* Read the DwCA in question:
        $tables = $info['harvester']->tables; // print_r($tables); exit;
        $extensions = array_keys($tables); //print_r($extensions); exit;
        $tbl = "http://rs.tdwg.org/dwc/terms/taxon";
        $meta = $tables[$tbl][0];
        // */

        /* ---------- Initialize so ancestry look-up is possible - COPIED TEMPLATE
        require_library('connectors/DHConnLib'); 
        $this->func = new DHConnLib(1, $meta->file_uri);
        $this->func->initialize_get_ancestry_func();
        echo "\nmeta file uri: [$meta->file_uri]\n";
        ---------- */

        $this->ancestry_index_info = self::retrieve_ancestry_index($this->ancestry_index_file); //new from Katja
        //print_r($this->ancestry_index_info); //exit("\nstop muna\n");
        foreach($this->ancestry_index_info as $hc => $indexes) { //checking integrity
            if(count($indexes) > 1) { //it goes here sometimes, not often. Better to keep this block.
                print_r($indexes);
                exit("\n[$hc] Non-unique higherClassification in AncestryIndex NEW.\n");
            }
        }

        /* ~~~~~~~~~~~~~~~~~~~~~~~~~~ this block is just for testing functions found in this library | force-assignment
        $hc_str = "Arthropoda|Hexapoda|Insecta|Pterygota|Odonata|Lestoidea|";   //sample of compatible multimatches
        // $hc_str = "Metazoa|Mollusca|unclassified Mollusca|Shishania|";          //sample of incompatible multimatches
        $hc_str = "Life|Cellular Organisms|Eukaryota|Archaeplastida|Chloroplastida|Streptophyta|Embryophytes|Tracheophyta|Spermatophytes|Angiosperms|Eudicots|Superrosids|Rosids|Sapindales|Rutaceae|Erythrochiton|";
        $hc_str = "Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Bilateria|Protostomia|Spiralia|Mollusca|Gastropoda|Heterobranchia|Euthyneura|Tectipleura|Eupulmonata|Stylommatophora|Helicina|Limacoidei|Helicarionoidea|Helicarionidae|Helicarioninae|";
        $hc_str = "Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Bilateria|Deuterostomia|Chordata|Vertebrata|Gnathostomata|Osteichthyes"; //no AI
        $ret = self::search_hc_string_from_AncestryIndex_regex($hc_str);
        print_r($ret); exit("\n--- end tests ---\n");
        ~~~~~~~~~~~~~~~~~~~~~~~~~~ */

        self::process_table($meta, 'generate_synonyms_info');
        self::process_table($meta, 'match_canonical');
        // self::process_table($meta, 'write_archive'); // COPIED TEMPLATE
        self::debug_reports();
    }
    private function process_table($meta, $what)
    {   //print_r($meta);
        echo "\nprocess_table: [$what] [$meta->file_uri]...\n";
        $i = 0;
        foreach (new FileIterator($meta->file_uri) as $line => $row) {
            $i++;
            if (($i % 10000) == 0) echo "\nrun: " . number_format($i) . " - "; //10k orig
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
            $rec = self::shorten_record($rec);
            // print_r($rec); exit;
            /* Array( e.g. Brazilian_flora
                [http://rs.tdwg.org/dwc/terms/taxonID] => 12
                [http://rs.tdwg.org/ac/terms/furtherInformationURL] => http://reflora.jbrj.gov.br/reflora/listaBrasil/FichaPublicaTaxonUC/FichaPublicaTaxonUC.do?id=FB12
                [http://rs.tdwg.org/dwc/terms/acceptedNameUsageID] => 
                [http://rs.tdwg.org/dwc/terms/parentNameUsageID] => 120181
                [http://rs.tdwg.org/dwc/terms/scientificName] => Agaricales
                [http://rs.tdwg.org/dwc/terms/namePublishedIn] => 
                [http://rs.tdwg.org/dwc/terms/kingdom] => Fungi
                [http://rs.tdwg.org/dwc/terms/phylum] => Basidiomycota
                [http://rs.tdwg.org/dwc/terms/class] => 
                [http://rs.tdwg.org/dwc/terms/order] => Agaricales
                [http://rs.tdwg.org/dwc/terms/family] => 
                [http://rs.tdwg.org/dwc/terms/genus] => 
                [http://rs.tdwg.org/dwc/terms/taxonRank] => order
                [http://rs.tdwg.org/dwc/terms/scientificNameAuthorship] => 
                [http://rs.tdwg.org/dwc/terms/taxonomicStatus] => accepted
                [http://purl.org/dc/terms/modified] => 2018-08-10 11:58:06.954
                [http://rs.gbif.org/terms/1.0/canonicalName] => Agaricales
            )*/
            // /*
            $rec = self::not_recongized_fields($rec); //remove not recognized fields
            $this->rec = $rec;
            // */

            $taxonID = $rec['taxonID'];
            $taxonRank = @$rec['taxonRank'];
            $taxonomicStatus = @$rec['taxonomicStatus'];
            $acceptedNameUsageID = @$rec['acceptedNameUsageID'];
            $canonicalName = self::format_canonical($rec['canonicalName']);
            //========================================================================================================= 
            if($what == 'match_canonical') { @$this->debug['total taxa']++;
                if(!self::valid_taxonomicStatus($taxonomicStatus)) {self::write_2archive($rec); @$this->debug['excluded: invalid taxa']++; continue;} 
                if(!$canonicalName)                                {self::write_2archive($rec); @$this->debug['excluded: no canonicalName']++; continue;} //trait taxon has no canonicalName
                if(@$rec['EOLid']) {
                    /* commented for: Body Length Data for North American Syrphidae & Tabanidae
                                    : Fungi ecomorphological trait data
                    self::write_2archive($rec);
                    @$this->debug['excluded: already has EOLid']++; 
                    continue;
                    */
                    // /* if above is commented, then this should be un-commented. Toggle with above.
                    $rec['EOLid'] = '';
                    // */
                    /*
                    To do: Check if EOLid exists, if not then set => $rec['EOLid'] = '';
                    Until then, it is safer to set => $rec['EOLid'] = ''; ... than to accept the given EOLid from the DwCA which oftenly is not in sync with latest working DH.
                    */

                } //trait taxon already has EOLid

                // priorities:
                // 1. if it can be tested with AncestryIndex then proceed to test and if it fails then stop there.
                // 2. if there is no hC and if there is hC but cannot be mapped to any of the IndexGroups, you can proceed matching...

                if($reks = @$this->DH->DHCanonical_info[$canonicalName]) { @$this->debug['Has canonical match']++;
                    if($this->debugNow) { echo "\n reks 1 => "; print_r($reks); }
                    $this->reks_1 = $reks; //use this to preserve the orig reks. Accepted and synonyms are included here.
                    $reks = self::filter_reks_by_what($reks, 'accepted');
                    if($this->debugNow) { echo "\n reks 2 => "; print_r($reks); }
                    if(!$reks) {self::write_2archive($rec); @$this->debug['Has canonical match with DH but without eolID']++; continue;}
                    $rec['EOLid'] = '';
                    $rec['taxonRemarks'] = '';
                    $ret = self::can_proceedYN_using_AncestryIndex($rec); //print_r($ret); exit("\nelix 1\n");
                    /*  Array(
                            [0] => Array( just an example:
                                    [taxonID] => 12
                                    [acceptedNameUsageID] => 
                                    [parentNameUsageID] => 120181
                                    [scientificName] => Agaricales
                                    [higherClassification] => Basidiomycota|
                                    [kingdom] => Fungi
                                    [phylum] => Basidiomycota
                                    [class] => 
                                    [order] => Agaricales
                                    [taxonRank] => order
                                    [scientificNameAuthorship] => 
                                    [taxonomicStatus] => accepted
                                    [canonicalName] => Agaricales
                                    [EOLid] => 
                                    [taxonRemarks] => Trait: [ IndexGroup:[Fungi] - IndexHC:[.*?\|Basidiomycota\|.*?] ]
                                )
                            [1] => 1
                        )                    
                    */
                                              $rec = $ret[0];
                    $can_proceed_with_AIndex_check = $ret[1];
                    // /* ----- NEW IMPLELENTATION ----- new detailed entire workflow
                    if($can_proceed_with_AIndex_check) {
                        if($ret = self::matching_routine_using_rank_v2($rec, $reks)) { //Step 3: Name matching - rank compatibility
                            $fromSynonyms = false;
                            if($ret2 = self::name_matching_ancestry_compatibility($ret, $fromSynonyms)) { //Step 4: Name matching - ancestry compatibility
                                if($this->debugNow) {
                                    print_r($ret2); exit("\nACCEPTED NAME: Reached this point.\n");
                                }

                                if(count($ret2) > 1) {
                                    // print_r($ret2); exit("\nSo it can happen: multiple accepted_name matches that pass both compatibility checks.\n");
                                    /* So it can happen to have two reks a this point. It is up to the next steps to pick 1. 
                                    [c] => Dichelyne diplocaecum    [t] => EOL-000003222666
                                    [c] => Dichelyne diplocaecum    [t] => EOL-000003222668
                                    */
                                }
                                $pair = self::choose_one_from_multiple_pairs($ret2, 'accepted');
                                $rec = self::major_assignment($pair);

                                // For reporting
                                if($rec['EOLid']) $this->debug['With DH EOLid assignments (accepted name)'][$taxonID] = $rec;
                                
                                /*
                                if($ret2[0][0]['taxonID'] == 'IRMNG:1444425') { //sample in GloBI
                                    print_r($rec); print_r($ret2); exit("\nThis came a long way the synonyms option.\n");
                                    // Array(
                                    //     [0] => Array(
                                    //             [0] => Array(
                                    //                     [taxonID] => IRMNG:1444425
                                    //                     [furtherInformationURL] => https://www.irmng.org/aphia.php?p=taxdetails&id=1444425
                                    //                     [referenceID] => 
                                    //                     [parentNameUsageID] => 
                                    //                     [scientificName] => Trichodina
                                    //                     [namePublishedIn] => 
                                    //                     [higherClassification] => Animalia|Mollusca|Gastropoda|Stylommatophora|Subulinidae|
                                    //                     [kingdom] => Animalia
                                    //                     [phylum] => Mollusca
                                    //                     [class] => Gastropoda
                                    //                     [order] => Stylommatophora
                                    //                     [family] => Subulinidae
                                    //                     [genus] => Trichodina
                                    //                     [taxonRank] => genus
                                    //                     [taxonomicStatus] => 
                                    //                     [taxonRemarks] => Trait: [ IndexGroup:[Gastropoda] - IndexHC:[.*?\|Gastropoda\|.*?] ]
                                    //                     [canonicalName] => Trichodina
                                    //                     [EOLid] => 
                                    //                     [AI] => Gastropoda
                                    //                 )
                                    //             [1] => Array(
                                    //                     [r] => genus
                                    //                     [e] => 
                                    //                     [h] => 
                                    //                     [c] => Trichodina
                                    //                     [t] => SYN-100000473021
                                    //                     [s] => n
                                    //                     [tR] => DH: [ IndexGroup:[Gastropoda] - IndexHC:[.*?\|Gastropoda\|.*?] ]
                                    //                     [AI] => Gastropoda
                                    //                     [c2] => Petriola
                                    //                     [e2] => 46988866
                                    //                     [h2] => Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Bilateria|Protostomia|Spiralia|Mollusca|Gastropoda|Heterobranchia|Euthyneura|Tectipleura|Eupulmonata|Stylommatophora|Achatinina|Achatinoidea|Achatinidae|Petriolinae
                                    //                 )
                                    //         )
                                    // )
                                }
                                */
                            }
                            else { //incompatible ancestry
                                if($this->debugNow) echo "\n => incompatible ancestry \n";
                                $this->debug['Cannot be matched at all'][$taxonID] = $rec;
                            }
                        }
                        else { //incompatible ranks
                            if($this->debugNow) echo "\n => incompatible ranks \n";

                            /* Eli's initiative only
                            if($syn_pair = $this->name_matching_through_synonyms($rec)) {
                                print_r($syn_pair); echo(" -> what now...\n");
                                $rec = self::major_assignment($syn_pair);
                                // For reporting
                                // if($rec['EOLid']) $this->debug['With DH EOLid assignments (accepted name)'][$taxonID] = $rec;
                            }
                            */
                            if(!$rec['EOLid']) $this->debug['Cannot be matched at all'][$taxonID] = $rec;
                        }
                    }
                    else { //no ancestry index
                        if($this->debugNow) echo "\n => no ancestry index \n";
                        $this->debug['Cannot be matched at all'][$taxonID] = $rec;
                    }
                    // */

                    /* ----- OLD IMPLEMENTATION ----- */
                }
                else $this->debug['No canonical match'][$taxonID] = $rec;

                if($this->debugNow) {
                    print_r($rec); self::debug_reports(); exit("\n-stop 1st rec-\n");
                }

                // /* uncomment in real operation
                self::write_2archive($rec); continue; //todo: $rec here has case where value is boolean; see jenkins 
                // */
                if($this->debugNow) break; //dev only ; process just 1 rec
            } //end match_canonical
            //========================================================================================================= 
            elseif($what == 'generate_synonyms_info') {
                $this->DWCA[$taxonID] = array("c" => $canonicalName, "r" => $taxonRank); //get all records, should be no filter here
                @$this->debug['taxonomicStatus'][$taxonomicStatus]++; //stats only
                if($acceptedNameUsageID) {
                    $this->acceptedNames[$acceptedNameUsageID][$taxonID] = '';
                    @$this->debug['total acceptedNameUsageID']++; //stats only
                    if(stripos($taxonomicStatus, "synonym") !== false) { //string is found
                        $this->synonyms[$taxonID] = $acceptedNameUsageID;
                    }
                }

                /* ver 1
                $this->DWCA[$taxonID] = array("c" => $canonicalName, "r" => $taxonRank); //get all records, should be no filter here
                @$this->debug['taxonomicStatus'][$taxonomicStatus]++; //stats only
                if($acceptedNameUsageID) {
                    @$this->debug['total acceptedNameUsageID']++; //stats only
                    if(stripos($taxonomicStatus, "synonym") !== false) { //string is found
                        $this->synonyms[$taxonID] = $acceptedNameUsageID;
                        $this->acceptedNames[$acceptedNameUsageID] = $taxonID;
                    }
                }
                */
            }
            //========================================================================================================= 
            // if($i >= 100) break; //dev only
        }
    }
    private function major_assignment($pair)
    {   // print_r($pair); exit("\nstopx 1\n");
        $rec = $pair[0];
        $rek = $pair[1];
        $rec['EOLid'] = $rek['e'] ? $rek['e'] : @$rek['e2'];
        unset($rec['AI']);
        return $rec;
    }
    private function the_synonyms_way($reks, $rec) {}
    private function append_string($orig, $tobe_added)
    {
        if($orig) {
            if($tobe_added) return "$tobe_added || $orig"; //to be added string is priority of order of appearance
            else return $orig;
        }
        else return $tobe_added;
    }
    private function can_proceedYN_using_AncestryIndex($rec)
    {   /*e.g. Array(
            [taxonID] => 12
            [furtherInformationURL] => http://reflora.jbrj.gov.br/reflora/listaBrasil/FichaPublicaTaxonUC/FichaPublicaTaxonUC.do?id=FB12
            [parentNameUsageID] => 120181
            [scientificName] => Agaricales
            [higherClassification] => Basidiomycota|
            [kingdom] => Fungi
            [phylum] => Basidiomycota
            [class] => 
            [order] => Agaricales
            [family] => 
            [genus] => 
            [taxonRank] => order
            [scientificNameAuthorship] => 
            [taxonomicStatus] => accepted
            [canonicalName] => Agaricales
        )*/
        $taxonID = $rec['taxonID'];
        if(!$rec['higherClassification']) {
            $rec['taxonRemarks'] = "No higherClassification";
            // $this->debug['M-m-w-a-i']['No hC'][$taxonID] = ''; --- wasn't used in old version anymore
            $this->debug['Matches made without_OR_lacking ancestry info'][$taxonID] = $rec;
                $rem = $rec['taxonRemarks'];
                $this->debug['without_OR_lacking'][$rem][$taxonID] = '';
            return array($rec, false);
        }
        else {
            $rec = self::let_us_try_to_assign_an_IndexGroup($rec);
            if(substr(@$rec['taxonRemarks'],0,6) == 'Trait:') return array($rec, true); //an IndexGroup was assigned
            else {
                $rec['taxonRemarks'] = "With higherClassification but cannot be mapped to any index group.";
                // $this->debug['M-m-w-a-i']['With hC but cannot be mapped to any index group'][$taxonID] = ''; --- wasn't used in old version anymore
                $this->debug['Matches made without_OR_lacking ancestry info'][$taxonID] = $rec;
                    $rem = $rec['taxonRemarks'];
                    $this->debug['without_OR_lacking'][$rem][$taxonID] = '';
                return array($rec, false);
            }
        }
        exit("\nWill terminate, should not go here.\n");
    }
    private function let_us_try_to_assign_an_IndexGroup($rec)
    {
        $hc = @$rec['higherClassification'];
        $hc_from_ancestry = self::get_names_from_ancestry($rec, $rec['canonicalName']); //2nd param is excluded name
        $hCs = array();
        if($hc) $hCs[] = $hc;
        if($hc_from_ancestry) $hCs[] = $hc_from_ancestry;

        foreach($hCs as $hc) {
            if($ret = self::given_hc_get_Ancestry_Group_and_Index($hc, 'E1')) { //2nd param is guide
                // print_r($rec); print_r($ret); exit("\ninvestigate muna\n"); //good debug
                /*Array(
                    [IndexGroup] => Angiosperms
                    [IndexHC] => Anacardiaceae|*
                    [SourceHC] => Anacardiaceae|
                )*/                
                $remarkz  = "Trait: [ IndexGroup:[".$ret['IndexGroup']."] - IndexHC:[".$ret['IndexHC']."] ]";
                $rec['taxonRemarks'] = $remarkz;
                return $rec;
            }
        }
        // $rec['taxonRemarks'] = "Cannot be assigned an index group."; //not needed, will be overwritten
        return $rec;
    }
    private function given_hc_get_Ancestry_Group_and_Index($hc, $guide)
    {
        // echo("\nneedle: [$hc][$guide]\n");
        $dwca_hc = explode("|", $hc);
        $dwca_hc = self::normalize_array($dwca_hc);
        $dwca_hc_string = implode("|", $dwca_hc)."|"; // "Plantae|" OR "Basidiomycota|"     // exit("\n[$dwca_hc_string]\nstop muna 1\n");
        if($ret = self::search_hc_string_from_AI($dwca_hc_string)) {
            /*Array(
                [IndexGroup] => Fungi
                [IndexHC] => .*?\|Basidiomycota\|.*?
                [lastItem_in_IndexHC] => Basidiomycota
            )*/
            $ret['SourceHC'] = $dwca_hc_string;
            // print_r($ret); echo("\nmay nakuha\n");
            return $ret;
        }
        // exit("\nwalang nakuha\n");
        return array();
    }    
    private function append_taxonRemarks($rec, $add_str, $guide)
    {
        $rem = @$rec['taxonRemarks'];
        if(substr($rem, 0, 8) == 'Trait: [') $add_str = "conflict IndexGroup mapping [$guide]";
        if($add_str) $rec['taxonRemarks'] .= " => $add_str";

        /* not fully tested
        $taxonID = $rec['taxonID'];
        if($val = @$this->sys[$taxonID]['remark']) $rec['taxonRemarks'] .= " => $val";
        */

        return $rec;
    }
    private function search_hc_string_from_AI($hc_str) //the regex implementation
    {   $hc_str = trim($hc_str);
        if($this->AncestryIndexVer == 'old') exit("\nDoes not go here anymore.\n");
        elseif($this->AncestryIndexVer == 'new') { //using regex index
            // /* using the regex index:
            @$this->debug['call ancestry index']['new index']++;
            if($this->run_debug4_YN) $this->debug4[$this->AncestryIndexVer.' - index ATTEMPTS'][$hc_str] = '';
            if($ret = self::search_hc_string_from_AncestryIndex_regex($hc_str)) {
                /*Array(
                    [IndexGroup] => Odonata
                    [IndexHC] => .*?\|Odonata\|.*?
                    [lastItem_in_IndexHC] => Odonata
                    [posOfLastItem] => 5
                )*/
                @$this->debug['call ancestry index']['new index success']++;
                if($this->run_debug3_YN) $this->debug3[$this->AncestryIndexVer.' - index'][$hc_str] = '';
                return $ret;
            }
            // */
        }
        else exit("\nERROR: AncestryIndex version was not set.\n");
        return false;
    }
    /* From: https://github.com/EOL/ContentImport/issues/33#issuecomment-4673604104
       Step 2: Preparing the resource file - ancestry (=higherClassification)
       #2: Create Ancestry Index values
    |Arthropoda|Hexapoda|Insecta|Pterygota|Odonata|Lestoidea|
    Insecta	.*?\|Hexapoda\|(.*?\|)?Pterygota\|.*?
    Odonata	.*?\|Odonata\|.*?
    Since .*?\|Odonata\|.*? matches closer to the end of the ancestry string than 
          .*?\|Hexapoda\|(.*?\|)?Pterygota\|.*?, we want to choose Odonata as the Index value here.

    print_r($this->ancestry_index_info);
    Array(
        [.*?\|Acipenseriformes\|.*?] => Array(
                [0] => Actinopterygii
            )
        [.*?\|Actinopteri\|.*?] => Array(
                [0] => Actinopterygii
            )
        [.*?\|Actinopterygii\|.*?] => Array(
                [0] => Actinopterygii
            )
    */
    function search_hc_string_from_AncestryIndex_regex($hc_str) //regex
    {
        if($hc_str == '|') return false;
        $pipe_hc_str = $this->add_pipe_2str($hc_str);
        $this->pipe_hc_str = $pipe_hc_str; //so it can be accessed in other functions, no need to pass it as param.
        // echo "\n needle or HCx: [$hc_str]";
        // echo "\n pipe needlex: [$pipe_hc_str]";
        // needle or HC: [Arthropoda|Hexapoda|Insecta|Pterygota|Odonata|Lestoidea|]
        // pipe needle: [|Arthropoda|Hexapoda|Insecta|Pterygota|Odonata|Lestoidea|]        

        // /* the regex implementation --- 2nd vers. (latest)
        $final = array(); 
        $index_values = array(); // the Index values for a multi-matched ancestry string
        foreach($this->ancestry_index_info as $index_hc => $indexes) {            
            $pattern = "/".$index_hc."/";
            $result = preg_match($pattern, $pipe_hc_str, $a);
            if($result === 1) {
                $final[] = array('IndexGroup' => $indexes[0], 'IndexHC' => $index_hc, 'lastItem_in_IndexHC' => self::get_rightmost($index_hc));
                $index_values[] = $indexes[0];
            }
            if($result === false) exit("\nERROR: invalid regex syntax\n");
        }
        if(count($final) == 1) return $final[0];

        // if(count($final) > 2) { print_r($final); echo("\nMore than 2 matched ancestry string\n"); } --- it is common to have > 2 matches
        // if(count($index_values) > 2) exit("\nShould not come here. More than 2 Index values for a multi-matched ancestry string.\n"); --- this is also common
        /* --- this is also common, 
        if(count($index_values) == 2) {
            if($index_values[0] == $index_values[1]) {
                echo "\n--will terminate--\n";
                print_r($final); print_r($index_values);
                echo("\nInvestigate: the same Index values for a multi-matched ancestry string.\n");
            }
        } */
        if(count($final) != count($index_values)) { echo "\n-Will terminate-\n"; print_r($final); print_r($index_values); exit("\nInvestigate: Different sums for [final] and [index_values]\n"); }
        /*  print_r($final);
            Array(
                [0] => Array(
                        [IndexGroup] => Insecta
                        [IndexHC] => .*?\|Hexapoda\|(.*?\|)?Pterygota\|.*?
                        [lastItem_in_IndexHC] => Pterygota
                    )
                [1] => Array(
                        [IndexGroup] => Odonata
                        [IndexHC] => .*?\|Odonata\|.*?
                        [lastItem_in_IndexHC] => Odonata
                    )
            ) */
        if(!$final) { 
            $this->debug['No_hits_in_AncestryIndex'][$pipe_hc_str] = "report"; // exit("\nNo hits in AncestryIndex. Please investigate.\n");
            return false;
        }
        else {
            /* debug only - force-assignment
            $index_values = array('Mollusca', 'Crustacea');
            */
            $index_values_str = implode("; ", $index_values);                    
            if(self::are_the_IndexValues_compatible($index_values, $final)) { //2nd param $final is just for debug //print_r($final);
                $this->debug['compatible_multimatches_v2'][$pipe_hc_str."\t".$index_values_str] = "report";
                $pipe_hc_array = explode("|", $pipe_hc_str); //print_r($pipe_hc_array);
                $i = -1;
                foreach($final as $a) { $i++;
                    $lastItem = $a['lastItem_in_IndexHC'];
                    $pos = array_search($lastItem, $pipe_hc_array);
                    $final[$i]['posOfLastItem'] = $pos;
                }
                if($ret = self::get_inner_array_with_greatest_posOfLastItem($final)) {
                    /* good debug
                    echo "\n --this is the inner array: "; print_r($ret);
                    if($index_values == array('Fungi', 'Fungi')) exit("\n--stop and check results--\n");
                    if($pipe_hc_str == '|Life|Cellular Organisms|Eukaryota|Archaeplastida|Chloroplastida|Streptophyta|Embryophytes|Tracheophyta|Spermatophytes|Angiosperms|Eudicots|Superrosids|Rosids|Sapindales|Rutaceae|') {
                        print_r($index_values); exit("\n--stop and check results--\n");
                    }*/
                    /*Array(
                        [IndexGroup] => Odonata
                        [IndexHC] => .*?\|Odonata\|.*?
                        [lastItem_in_IndexHC] => Odonata
                        [posOfLastItem] => 5
                    )*/
                    return $ret;
                }
            }
            else { //not compatible index values
                    $this->debug['incompatible_multimatches_v2'][$pipe_hc_str."\t".$index_values_str] = "report";
                    // echo "\nincompatible_multimatches_v2: "; print_r($this->debug['incompatible_multimatches_v2']); exit("\nstop muna: Incompatible multimatches\n");
                    return false;
            }
        }
        // exit("\nbeing developed...\n");
    }
    private function is_ending_in_asterisk($str)
    {
        $last_char = substr($str, -1);
        if($last_char == "*") return true;
        return false;
    }
    private function prepare_hc_string($pipe_delimited) //makes "Fungi|Ascomycota" to "Fungi|Ascomycota|"
    {
        $arr = explode("|", $pipe_delimited);
        $arr = self::normalize_array($arr);
        return implode("|", $arr)."|";
    }
    private function more_strict_matching_byEli($reks, $hc)
    {   //loop to all reks and check each higherClassification. If ALL scinames from DwCA hc is found in DH hc then that rek is returned.
        $hc = explode("|", $hc);
        $hc = self::normalize_array($hc); //print_r($hc); exit("\ncha 01\n");
        /*Array(
            [0] => Fungi
            [1] => Basidiomycota
        )*/
        $hits = array();
        foreach($reks as $id => $rek) {
            if($DH_hc = $rek['h']) {
                $DH_hc = explode("|", $DH_hc);
                $DH_hc = self::normalize_array($DH_hc); // print_r($DH_hc); exit("\ncha 02\n");
                /*Array(
                    [0] => Life
                    [1] => Cellular Organisms
                    [2] => Eukaryota
                    [3] => Opisthokonta
                    [4] => Nucletmycea
                    [5] => Fungi
                    [6] => Dikarya
                    [7] => Basidiomycota
                    [8] => Agaricomycetes
                )*/
                foreach($hc as $sciname) {
                    if(!in_array($sciname, $DH_hc)) return false;
                }
                if(($this->rec['taxonRank'] == $rek['r']) && $rek['r']) $hits[] = $rek;
            }
        }
        if(count($hits) > 1) {
            echo "\n--------------------------------- investigate here...\n";
            print_r($this->rec); print_r($hc); print_r($reks); print_r($hits); exit("\n\nSo this is possible hmmm.\n");
        }
        if($hits) return $hits[0];
    }
    private function are_these_synonyms_in_DwCA($taxonID, $DH_canonical, $type)
    {
        if($type == 1) $choices = array('genus', 'subgenus');
        elseif($type == 2) $choices = array_merge(array('species'), $this->ok_match_subspecific_ranks);
        /* reference only
        $this->synonyms[$taxonID] = $acceptedNameUsageID;
        $this->acceptedNames[$acceptedNameUsageID] = $taxonID;
        */
        if($accepted_id = @$this->synonyms[$taxonID]) {
            /* reference only            
            $this->DWCA[$taxonID] = array("c" => $canonicalName);
            */
            if($rec = $this->DWCA[$accepted_id]) {
                if($rec['c'] == $DH_canonical && in_array($rec['r'], $choices)) return true;
            }
        }
        if($SYN_ids = @$this->acceptedNames[$taxonID]) {
            /* reference only            
            $this->DWCA[$taxonID] = array("c" => $canonicalName);
            */
            foreach(array_keys($SYN_ids) as $SYN_id) {
                if($rec = $this->DWCA[$SYN_id]) {
                    if($rec['c'] == $DH_canonical && in_array($rec['r'], $choices)) return true;
                }
            }
        }
        return false;
    }
    private function are_these_synonyms_in_DH($taxonID, $DH_canonical, $type)
    {
            if($type == 1) $choices = array('genus', 'subgenus');
        elseif($type == 2) $choices = array_merge(array('species'), $this->ok_match_subspecific_ranks);

        if($accepted_id = @$this->DH->DH_synonyms[$taxonID]) {
            /* for reference only            
            $this->DH->DH[$taxonID] = array("c" => $canonicalName); */
            if($rec = $this->DH->DH[$accepted_id]) {
                if($rec['c'] == $DH_canonical && in_array($rec['r'], $choices)) return true;
            }
        }
        // SYN-000000207590	EOL-000000462763		Cassia pendula E.Agbayani	Senna pendula	E.Agbayani	variety	not accepted	COL-15	COL:a423c550b4fd0b0feefa2477637935ff	http://www.catalogueoflife.org/annual-checklist/2019/details/species/id/19f057e06cfc7dbd915c90b6bb2e5f70/synonym/a423c550b4fd0b0feefa2477637935ff			
        if($SYN_ids = @$this->DH->DH_acceptedNames[$taxonID]) { //exit("\nhere 01\n");
            /* reference only            
            $this->DH->DH[$taxonID] = array("c" => $canonicalName); */
            foreach(array_keys($SYN_ids) as $SYN_id) {
                if($syn_rec = $this->DH->DH[$SYN_id]) {
                    // print_r($syn_rec); print_r($choices); exit("\n[$DH_canonical]\nhere 02\n");
                    if($syn_rec['c'] == $DH_canonical && in_array($syn_rec['r'], $choices)) return true;
                }
            }
        }
        return false;
    }
    private function get_separator_in_higherClassification($hc)
    {
        if(stripos($hc, "|") !== false) return "|"; //string is found
        if(stripos($hc, ";") !== false) return ";"; //string is found
        if($hc) return 'is_1_word';
        return false;
    }
    private function format_canonical($canonicalName)
    {
        if ($canonicalName == '""') return false;
        if (!$canonicalName) return false;
        return $canonicalName;
    }
    private function not_recongized_fields($rec)
    {
        // /* Not recognized fields e.g. WoRMS2EoL.zip
        if (isset($rec['rights']))       unset($rec['rights']);
        if (isset($rec['rightsHolder'])) unset($rec['rightsHolder']);
        // */
        return $rec;
    }
    private function get_field_from_uri($uri)
    {
        $field = pathinfo($uri, PATHINFO_BASENAME);
        $parts = explode("#", $field);
        if ($parts[0]) $field = $parts[0];
        if (@$parts[1]) $field = $parts[1];
        return $field;
    }
    private function write_2archive($rec)
    {
        // print_r($rec);
        if($this->run_debug2_YN) {
            if($val = @$rec['EOLid']) {
                $this->debug2['total EOLids'][$val] = '';
                @$this->debug2['EOLid assignments']++;
            }
        }

        $o = new \eol_schema\Taxon();
        $uris = array_keys($rec);
        // print_r($uris);
        foreach ($uris as $uri) {
            $field = self::get_field_from_uri($uri);
            $o->$field = $rec[$uri];
            // echo "[$field] ";
        }
        // exit("\nstop muna x\n");
        $this->archive_builder->write_object_to_file($o);
    }    
    private function retrieve_ancestry_index($file_2use)
    {
        $options = $this->download_options;
        $options['expire_seconds'] = 60*60*24*1; //orig 1 day
        if($local = Functions::save_remote_file_to_local($file_2use, $options)) {
            $i = 0;
            foreach(new FileIterator($local) as $line_number => $line) {
                $line = explode("\t", $line); $i++; 
                if($i == 1) $fields = $line;
                else {
                    if(!$line[0]) break;
                    $rec = array(); $k = 0;
                    foreach($fields as $fld) {
                        $rec[$fld] = $line[$k]; $k++;
                    }
                    // print_r($rec); exit;
                    /*Array(
                        [Index] => Actinopterygii
                        [higherClassification] => Animalia|Chordata|Actinopterygii|*
                    )*/
                    if($rec['Index'] && $rec['higherClassification']) {
                        $ret[$rec['higherClassification']][] = $rec['Index'];
                    }
                }
            }
        }
        else exit("\nERROR: File can't be accessed.\n".$file_2use."\nWill terminate.\n");
        unlink($local); // exit("\n".count($ret)."\n");
        return $ret;
    }
    public static function get_names_from_ancestry($rec, $exclude_name = false)
    {   /* IMPORTANT: $rec fields here can be this type: $rec['http://rs.gbif.org/terms/1.0/scientificName OR this type $rec["sN"] */
        $fields = array('kingdom', 'phylum', 'class', 'order', 'family', 'genus');
        $names = array();
        foreach($fields as $field) {
            if($val = @$rec['http://rs.tdwg.org/dwc/terms/'.$field]) {}
            else {
                $field = DwCA_Utility_cmd::shorten_field($field);
                $val = @$rec[$field];
            }
            if($val) { //all the ancestry scinames
                if($exclude_name) {
                    if($val != $exclude_name) $names[$val] = '';
                }
                else $names[$val] = '';
            }
        }
        return array_keys($names);
    }
    private function get_names_from_hC($rec, $canonicalName)
    {
        $names = array();
        if($hc = @$rec['higherClassification']) {
            if($separator = self::get_separator_in_higherClassification($hc)) {
                if($separator == 'is_1_word') $names = array($hc);
                else                          $names = explode($separator, $hc);
            }
        }
        if($canonicalName) $names[] = $canonicalName;
        $names = self::normalize_array($names);
        return $names;
    }
    private function choose_from_matched_group($taxonRank, $reks)
    {
        foreach($reks as $DH_taxonIDx => $rek) {
            if(in_array($taxonRank, $this->g_kingdom_domain) && in_array($rek['r'], $this->g_kingdom_domain) && $rek['e']) return $rek;
            if(in_array($taxonRank, $this->g_phylum) && in_array($rek['r'], $this->g_phylum) && $rek['e']) return $rek;
            if(in_array($taxonRank, $this->g_class) && in_array($rek['r'], $this->g_class) && $rek['e']) return $rek;
            if(in_array($taxonRank, $this->g_order) && in_array($rek['r'], $this->g_order) && $rek['e']) return $rek;
            if(in_array($taxonRank, $this->g_family) && in_array($rek['r'], $this->g_family) && $rek['e']) return $rek;
            if(in_array($taxonRank, $this->g_tribe) && in_array($rek['r'], $this->g_tribe) && $rek['e']) return $rek;
            if(in_array($taxonRank, $this->g_genus) && in_array($rek['r'], $this->g_genus) && $rek['e']) return $rek;
            if(in_array($taxonRank, $this->g_section) && in_array($rek['r'], $this->g_section) && $rek['e']) return $rek;
            if(in_array($taxonRank, $this->g_species) && in_array($rek['r'], $this->g_species) && $rek['e']) return $rek;
        }
        $family_tribe = array_merge($this->g_family, $this->g_tribe);
        foreach($reks as $DH_taxonIDx => $rek) {
            if(in_array($taxonRank, $family_tribe) && in_array($rek['r'], $family_tribe) && $rek['e']) return $rek;
        }
        $genus_section = array_merge($this->g_genus, $this->g_section);
        foreach($reks as $DH_taxonIDx => $rek) {
            if(in_array($taxonRank, $genus_section) && in_array($rek['r'], $genus_section) && $rek['e']) return $rek;
        }
    }
    private function valid_taxonomicStatus($status)
    {
        if($status) {
            if(stripos($status, "synonym") !== false) return false; //string is found
        }
        return true;
    }
    private function normalize_array($arr)
    {
        $arr = array_filter($arr); //remove null arrays
        $arr = array_unique($arr); //make unique
        $arr = array_values($arr); //reindex key
        return $arr;
    }
    private function remove_last_char($str)
    {
        return substr($str, 0, -1);
    }
    private function path_to_canonical($taxonID) //an id of this form e.g. 'EOL-000000126301'
    {   //for reference: $this->DH[$taxonID] = array("c" => $canonicalName, "r" => $taxonRank);
        // print_r(); exit("\nstop muna\n");
        if($arr = @$this->DH->DH[$taxonID]) {
            return $arr['c']; //returns the canonicalName
        }
    }
    private function get_acceptedRek_if_synonym($rek)
    {
        $taxonID = $rek['t']; //an id of this form e.g. 'EOL-000000126301'
        if(substr($taxonID,0,3) == 'EOL') return $rek; //not a synonym
        if($rek['s'] == 'a')              return $rek; //accepted name
        if($acceptedNameUsageID = @$this->DH->DH_synonyms[$taxonID]) { //echo "\nsyn ID: [$taxonID] | acceptedNameUsageID: [$acceptedNameUsageID]\n"; //good debug
            // for reference: $this->DH[$taxonID] = array("c" => $canonicalName, "r" => $taxonRank);
            if($accepted_rek = $this->DH->DH[$acceptedNameUsageID]) { // print_r($rek); print_r($accepted_rek);
                /*Array( e.g. $accepted_rek
                    [c] => Aristolochiaceae
                    [r] => family
                )*/
                // exit("\nfound accepted rek for a synonym rek\n");
                // for reference: $this->DHCanonical_info[$canonicalName][$taxonID]
                if($canonicalName = $accepted_rek['c']) {
                    if($final_rek = $this->DH->DHCanonical_info[$canonicalName][$acceptedNameUsageID]) { // print_r($final_rek); exit("\nfound final rek\n");
                        @$this->debug['synonyms OK']++;
                        $syn_canonical = self::path_to_canonical($taxonID);
                        $accepted_canonical = self::path_to_canonical($acceptedNameUsageID);
                        /* note that $syn_canonical and $canonicalName are equal */
                        $final_rek['tR'] = "syn ID: [$taxonID][$syn_canonical] | acceptedNameUsageID: [$acceptedNameUsageID][$accepted_canonical]";
                        return $final_rek; //tested OK!
                    }
                    else exit("\nInvestigate: should not go here at least...\n");
                }
            }
            else {
                print_r($rek);
                exit("\nInvestigate 02: can't locate rek for this acceptedNameUsageID: [$acceptedNameUsageID]\n");
            }
        }
        else {
            print_r($rek);
            exit("\nInvestigate 01: synonym doesn't have an acceptedNameUsageID\n");
        }
        return $rek;
    }
    /* copied template
    private function get_taxonID_EOLid_list()
    {
        $tmp_file = Functions::save_remote_file_to_local($this->paths[$this->resource_id], $this->download_options);
        $i = 0;
        foreach(new FileIterator($tmp_file) as $line_number => $line) {
            $i++; if(($i % 1000) == 0) echo "\n".number_format($i)." ";
            // if($i == 1) $line = strtolower($line);
            $row = explode("\t", $line); // print_r($row);
            if($i == 1) {
                $fields = $row;
                $fields = array_filter($fields); //print_r($fields);
                $tmp_fields = $fields;
                continue;
            }
            else {
                if(!@$row[0]) continue;
                $k = 0; $rec = array();
                foreach($fields as $fld) { $rec[$fld] = @$row[$k]; $k++; }
            }
            $rec = array_map('trim', $rec);
            // print_r($rec); exit("\nstopx\n");
            if($val = @$rec['EOLid']) $this->taxonID_EOLid_info[$rec['taxonID']] = $val;
        }
        unlink($tmp_file);
    }*/
}