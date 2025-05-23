<?php
namespace php_active_record;
/* connector: [26] WORMS archive connector
We received a Darwincore archive file from the partner.
Connector downloads the archive file, extracts, reads it, assembles the data and generates the EOL DWC-A resource.

[establishmentMeans] => Array
       (
           [] => 
           [Alien] =>                   used
           [Native - Endemic] =>        used
           [Native] =>                  used
           [Origin uncertain] => 
           [Origin unknown] => 
           [Native - Non-endemic] =>    used
       )
   [occurrenceStatus] => Array
       (
           [present] =>                 used
           [excluded] =>                used
           [doubtful] =>                used
       )

http://www.marinespecies.org/rest/#/
http://www.marinespecies.org/aphia.php?p=taxdetails&id=9
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class WormsArchiveAPI extends ContributorsMapAPI
{
    function __construct($folder)
    {
        $this->resource_id = $folder;
        $this->path_to_archive_directory = CONTENT_RESOURCE_LOCAL_PATH . '/' . $folder . '_working/';
        $this->archive_builder = new \eol_schema\ContentArchiveBuilder(array('directory_path' => $this->path_to_archive_directory));
        $this->taxon_ids = array();
        $this->object_ids = array();
        
        if(Functions::is_production()) {
            $url = "http://www.marinespecies.org/export/eol/WoRMS2EoL.zip";              //WORMS online copy
            if(Functions::ping_v2($url)) $this->dwca_file = $url;
            else                         $this->dwca_file = "https://editors.eol.org/other_files/WoRMS/WoRMS2EoL.zip";
        }
        else                            $this->dwca_file = "http://host.docker.internal:81/cp/WORMS/WoRMS2EoL.zip";                            //local - when developing only
        //                              $this->dwca_file = LOCAL_HOST."/cp/WORMS/Archive.zip";                              //local subset copy
        
        $this->occurrence_ids = array();
        $this->taxon_page = "http://www.marinespecies.org/aphia.php?p=taxdetails&id=";
        
        $this->webservice['AphiaClassificationByAphiaID'] = "http://www.marinespecies.org/rest/AphiaClassificationByAphiaID/";
        $this->webservice['AphiaRecordByAphiaID']         = "http://www.marinespecies.org/rest/AphiaRecordByAphiaID/";
        $this->webservice['AphiaChildrenByAphiaID']       = "http://www.marinespecies.org/rest/AphiaChildrenByAphiaID/";
        
        $this->download_options = array('cache' => 1, 'download_wait_time' => 1000000, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1, 'resource_id' => 26);
        $this->download_options["expire_seconds"] = false; //debug - false means it will use cache
        $this->debug = array();
        
        /* some remnants below, but seems not used when getting the regular WoRMS DwCA (media objects, trait). Also below part is commented, not running.
        $this->gnsparser = "http://parser.globalnames.org/api?q=";
        $this->smasher_download_options = array(
            'cache_path'         => '/Volumes/AKiTiO4/eol_cache_smasher/',
            'download_wait_time' => 500000, 'timeout' => 600, 'download_attempts' => 1, 'delay_in_minutes' => 0, 'expire_seconds' => false);
        */
        /* start DATA-1827 below */
        $this->match2mapping_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/master/WoRMS/worms_mapping1.csv';
        $this->value_uri_mapping_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/master/WoRMS/metastats-csv.tsv';       //old
        $this->value_uri_mapping_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/master/WoRMS/Feb2020/metastats-2.tsv'; //latest Feb 2020
        //mapping from here: https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=63730&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-63730
        $this->BsD_URI['length']                = 'http://purl.obolibrary.org/obo/CMO_0000013';
        $this->BsD_URI['total length (tl)']     = 'http://purl.obolibrary.org/obo/CMO_0000013';
        $this->BsD_URI['corresponding length']  = 'http://purl.obolibrary.org/obo/CMO_0000013';
        $this->BsD_URI['height']                = 'http://purl.obolibrary.org/obo/CMO_0000013';
        $this->BsD_URI['heigth']                = 'http://purl.obolibrary.org/obo/CMO_0000013';
        $this->BsD_URI['standard length (sl)']  = 'http://purl.obolibrary.org/obo/CMO_0000013'; //Eli's exec decision for this new index
        $this->BsD_URI['width'] = 'http://purl.obolibrary.org/obo/VT_0015039';
        $this->BsD_URI['breadth'] = 'http://purl.obolibrary.org/obo/VT_0015039';
        $this->BsD_URI['diameter'] = 'http://ncicb.nci.nih.gov/xml/owl/EVS/Thesaurus.owl#C25285';
        $this->BsD_URI['thallus diameter'] = 'http://purl.obolibrary.org/obo/FLOPO_0023069';
        $this->BsD_URI['thallus length'] = 'https://eol.org/schema/terms/thallus_length';
        $this->BsD_URI['thickness'] = 'http://purl.obolibrary.org/obo/PATO_0000915';
        $this->BsD_URI['volume'] = 'http://purl.obolibrary.org/obo/PATO_0001710';
        $this->BsD_URI['weight'] = 'http://purl.obolibrary.org/obo/PATO_0000125';
        $this->BsD_URI['width'] = 'http://purl.obolibrary.org/obo/VT_0015039';
        $this->BsD_URI['wingspan'] = 'http://www.wikidata.org/entity/Q245097';
        $this->BsD_URI['bell diameter'] = 'http://ncicb.nci.nih.gov/xml/owl/EVS/Thesaurus.owl#C25285';
        $this->BsD_URI['prosome length'] = 'http://eol.org/schema/terms/ProsomeLength';
        // NaN,ignore
        $this->mUnit['mm'] = 'http://purl.obolibrary.org/obo/UO_0000016';
        $this->mUnit['cm'] = 'http://purl.obolibrary.org/obo/UO_0000015';
        $this->mUnit['µm'] = 'http://purl.obolibrary.org/obo/UO_0000017';
        $this->mUnit['mm'] = 'http://purl.obolibrary.org/obo/UO_0000016';
        $this->mUnit['kg'] = 'http://purl.obolibrary.org/obo/UO_0000009';
        $this->mUnit['m'] = 'http://purl.obolibrary.org/obo/UO_0000008';
        $this->mUnit['ton'] = 'http://purl.obolibrary.org/obo/UO_0010038';
        $this->mUnit['mm'] = 'http://purl.obolibrary.org/obo/UO_0000016';
        $this->mUnit['cm³'] = 'http://purl.obolibrary.org/obo/UO_0000097';
        $this->mUnit['m²'] = 'http://purl.obolibrary.org/obo/UO_0000080';
        $this->children_mTypes = array("Body size > Gender" ,"Body size > Stage", "Body size > Type" ,"Feedingtype > Stage", "Functional group > Stage" ,"Body size > Locality (MRGID)");
        //Aug 24, 2019 - for associations | 'reg' for regular; 'rev' for reverse
        $this->fType_URI['ectoparasitic']['reg']    = 'http://purl.obolibrary.org/obo/RO_0002632';
        $this->fType_URI['parasitic']['reg']        = 'http://purl.obolibrary.org/obo/RO_0002444';
        $this->fType_URI['endoparasitic']['reg']    = 'http://purl.obolibrary.org/obo/RO_0002634';
        $this->fType_URI['endocommensal']['reg']    = 'https://eol.org/schema/terms/endosymbiontOf';
        $this->fType_URI['symbiotic']['reg']        = 'http://purl.obolibrary.org/obo/RO_0002440';
        $this->fType_URI['kleptovore']['reg']       = 'http://purl.obolibrary.org/obo/RO_0008503';
        $this->fType_URI['epizoic']['reg']          = 'https://eol.org/schema/terms/epibiontOf';
        $this->fType_URI['kleptivore']['reg']       = 'http://purl.obolibrary.org/obo/RO_0008503';
        $this->fType_URI['ectoparasitic']['rev']    = 'http://purl.obolibrary.org/obo/RO_0002633';
        $this->fType_URI['parasitic']['rev']        = 'http://purl.obolibrary.org/obo/RO_0002445';
        $this->fType_URI['endoparasitic']['rev']    = 'http://purl.obolibrary.org/obo/RO_0002635';
        $this->fType_URI['endocommensal']['rev']    = 'http://purl.obolibrary.org/obo/RO_0002453';
        $this->fType_URI['symbiotic']['rev']        = 'http://purl.obolibrary.org/obo/RO_0002453';
        $this->fType_URI['kleptovore']['rev']       = 'http://purl.obolibrary.org/obo/RO_0008504';
        $this->fType_URI['epizoic']['rev']          = 'http://purl.obolibrary.org/obo/RO_0002453';
        $this->fType_URI['kleptivore']['rev']       = 'http://purl.obolibrary.org/obo/RO_0008504';
        $this->real_parents = array('AMBI ecological group', 'Body size', 'Body size (qualitative)', 'Feedingtype', 'Fossil range', 'Functional group', 'Paraphyletic group', 'Species importance to society', 'Supporting structure & enclosure');

        $this->exclude_mType_mValue['Feedingtype']['endocommensal'] = '';
        $this->exclude_mType_mValue['Feedingtype']['symbiotic'] = '';
        $this->exclude_mType_mValue['Feedingtype']['unknown'] = '';
        $this->exclude_mType_mValue['Feedingtype']['not feeding'] = '';
        $this->exclude_mType_mValue['Feedingtype']['non-selective'] = '';
        $this->exclude_mType_mValue['Feedingtype']['commensal'] = '';
        $this->exclude_mType_mValue['Feedingtype']['epizoic'] = '';
        $this->exclude_mType_mValue['Feedingtype']['selective'] = '';
        $this->exclude_mType_mValue['Functional group']['macro'] = '';
        $this->exclude_mType_mValue['Functional group']['meso'] = '';
        $this->exclude_mType_mValue['Functional group']['not applicable'] = '';
    }
    private function get_valid_parent_id($id)
    {
        $taxa = self::AphiaClassificationByAphiaID($id);
        $last_rec = end($taxa);
        return $last_rec['parent_id'];
    }
    function get_all_taxa($what)
    {   /* tests
        $ids = self::get_branch_ids_to_prune(); print_r($ids); exit;
        */
        /* tests
        $arr = self::initialize_mapping(); print_r($arr);
        echo "\nGalicia: ".$arr['Galicia']."\n"; exit;
        */
        /* tests
        $ret = $this->format_remove_middle_initial("de Voogd, Nicole J."); exit("\nfinal = [$ret]\n");
        */
        // /* New: Jun 7, 2021 - get contributor mapping list: http://www.marinespecies.org/imis.php?module=person&show=search
        $this->contributor_id_name_info = $this->get_WoRMS_contributor_id_name_info(); //print_r($this->contributor_id_name_info); exit("\nstop muna...\n");

        // Oct 25, 2023 - added EOL Terms file as source for URIs - works OK!
        require_library('connectors/EOLterms_ymlAPI');
        $func = new EOLterms_ymlAPI($this->resource_id, $this->archive_builder);
        $this->eol_terms_label_uri = $func->get_terms_yml('value'); //sought_type is 'value' --- REMINDER: labels can have the same value but different uri
        foreach($this->eol_terms_label_uri as $label => $uri) {
            // uri: https://www.marinespecies.org/imis.php?module=person&persid=31659
            if(substr($uri,0,25) == "https://www.marinespecies") $this->contributor_id_name_info[$label] = $uri;
        }
        echo "\nTesting URI [Whipps, Christopher]: ".@$this->contributor_id_name_info['Whipps, Christopher'];
        echo "\nTesting URI [Wayland, Matthew]: ".@$this->contributor_id_name_info['Wayland, Matthew']."\n";

        // /* new Nov 15, 2023
        $uri_label = $func->get_terms_yml('WoRMS value'); //sought_type is 'WoRMS value' --- REMINDER: this one is better since uri is unique.
        foreach($uri_label as $uri => $label) $this->eol_terms_uri_value[$uri] = '';
        // */

        /* good debug only
        if(isset($this->eol_terms_uri_value["http://www.geonames.org/6640368"])) echo "\nOk test for eol_terms_uri_value\n";
        else {
            echo "\n-------------- error, will stop --------------\n";
            echo "\nEOL term file: ".count($this->eol_terms_uri_value)."\n";
            print_r($this->eol_terms_uri_value);
            exit("\nSomething is wrong with lookup to EOL Terms file.\n");
        }
        exit("\n- stop evaluate muna -\n");
        */

        unset($func); //exit;
        // */
        
        $temp = CONTENT_RESOURCE_LOCAL_PATH . "26_files";
        if(!file_exists($temp)) mkdir($temp);
        $this->what = $what; //either 'taxonomy' or 'media_objects'

        /* last 2 bad parents:
                Cristellaria Lamarck, 1816 (worms#390648)
                Corbiculidae Gray, 1847 (worms#414789)
        And there are six descendants of bad parents respectively:
                *Cristellaria arcuatula Stache, 1864 (worms#895743)
                *Cristellaria foliata Stache, 1864 (worms#903431)
                *Cristellaria vestuta d'Orbigny, 1850 (worms#924530)
                *Cristellaria obtusa (worms#925572)
                *Corbiculina Dall, 1903 (worms#818186)
                *Cyrenobatissa Suzuki & Oyama, 1943 (worms#818201)            
        */
        /* tests
        $this->children_of_synonyms = array(14769, 735405);
        $id = "24"; $id = "142"; $id = "5"; $id = "25"; $id = "890992"; $id = "834546";
        $id = "379702"; 
        $id = "127";
        $id = "14769";
        $id = "930326";

        $x2 = self::get_valid_parent_id($id);
        echo "\n parent_id from api: $x1\n";
        exit("\n valid parent_id: $x2\n");
        exit("\n");
        */
        /* tests
        $this->synonyms_without_children = self::get_synonyms_without_children(); //used so script will no longer lookup if this syn is known to have no children.
        // $taxo_tmp = self::get_children_of_taxon("100795");
        // $taxo_tmp = self::get_children_of_taxon(13);
        // $taxo_tmp = self::get_children_of_taxon(510462);
        $taxo_tmp = self::get_children_of_taxon("390648");
        print_r($taxo_tmp); exit("\n[".count($taxo_tmp)."] elix\n");
        */
        /* tests
        $this->synonyms_without_children = self::get_synonyms_without_children(); //used so script will no longer lookup if this syn is known to have no children.
        $ids = self::get_all_ids_to_prune();
        print_r($ids); exit("\n[".count($ids)."] total IDs to prune\n");
        */
        /*
        $str = "Cyclostomatida  incertae sedis";
        // $str = "Tubuliporoidea Incertae sedis";
        $str = "Lyssacinosida    incertae Sedis Tabachnick, 2002";
        echo "\n[$str]\n";
        $str = self::format_incertae_sedis($str);
        exit("\n[$str]\n");
        */

        // /* un-comment in real operation
        require_library('connectors/INBioAPI');
        $func = new INBioAPI();
        $paths = $func->extract_archive_file($this->dwca_file, "meta.xml", array('timeout' => 172800, 'expire_seconds' => true)); //true means it will re-download, will not use cache. Set TRUE when developing
        // print_r($paths); exit;
        // */
        /* for development only
        $paths = Array("archive_path" => "/opt/homebrew/var/www/eol_php_code/tmp/WORMS_dir_89994/", "temp_dir" => "/opt/homebrew/var/www/eol_php_code/tmp/WORMS_dir_89994/");
        */
        
        $archive_path = $paths['archive_path'];
        $temp_dir = $paths['temp_dir'];

        $harvester = new ContentArchiveReader(NULL, $archive_path);
        $tables = $harvester->tables;
        if(!($this->fields["taxa"] = $tables["http://rs.tdwg.org/dwc/terms/taxon"][0]->fields)) { // take note the index key is all lower case
            debug("Invalid archive file. Program will terminate.");
            return false;
        }

        if($this->what == "taxonomy") {
            /* First, get all synonyms, then using api, get the list of children, then exclude these children
            Based on latest: https://eol-jira.bibalex.org/browse/TRAM-520?focusedCommentId=60756&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-60756
            */
            $this->children_of_synonyms = self::get_all_children_of_synonyms($harvester->process_row_type('http://rs.tdwg.org/dwc/terms/Taxon')); //then we will exclude this in the main operation
            // /* uncomment in real operation
            //add ids to prune for those to be excluded: https://eol-jira.bibalex.org/browse/TRAM-520?focusedCommentId=60923&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-60923
            echo "\nBuilding up IDs to prune...\n"; $ids = self::get_all_ids_to_prune();
            $this->children_of_synonyms = array_merge($this->children_of_synonyms, $ids);
            $this->children_of_synonyms = array_unique($this->children_of_synonyms);
            // */
        }
        // exit("\n building up list of children of synonyms \n"); //comment in normal operation
        echo "\n1 of 8\n";  self::build_taxa_rank_array($harvester->process_row_type('http://rs.tdwg.org/dwc/terms/Taxon'));
        echo "\n2 of 8\n";  self::create_instances_from_taxon_object($harvester->process_row_type('http://rs.tdwg.org/dwc/terms/Taxon'));
        if($this->what == "taxonomy") {
            echo "\n3 of 8\n";  self::add_taxa_from_undeclared_parent_ids();
        }

        // /* block for DATA-1827 tasks ===========================================================================================
        require_library('connectors/TraitGeneric');
        $this->func = new TraitGeneric($this->resource_id, $this->archive_builder);
        /* START DATA-1841 terms remapping */
        $this->func->initialize_terms_remapping();
        /* END DATA-1841 terms remapping */
        echo "\nFrom local: ".count($this->func->remapped_terms)."\n";
        
        $this->match2map = self::csv2array($this->match2mapping_file, 'match2map'); //mapping csv to array
        $this->value_uri_map = self::tsv2array($this->value_uri_mapping_file);
        echo "\n01 of 8\n";  self::build_parentOf_childOf_data($tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0]);
        echo "\n02 of 8\n";  self::get_mIDs_2exclude($tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0]);
        echo "\n03 of 8\n";  self::get_measurements($tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0]);
        // print_r($this->debug);
        unset($this->childOf); unset($this->parentOf); unset($this->ToExcludeMeasurementIDs);
        unset($this->BodysizeDimension); unset($this->FeedingType); unset($this->lifeStageOf); unset($this->measurementIDz);
        // $this->archive_builder->finalize(TRUE); return; //debug only - delete row in normal operation
        // */ =====================================================================================================================
        
        if($this->what == "media_objects") {
            echo "\n4 of 8\n";  self::get_objects($harvester->process_row_type('http://eol.org/schema/media/Document'));
            echo "\n5 of 8\n";  self::get_references($harvester->process_row_type('http://rs.gbif.org/terms/1.0/Reference'));
            echo "\n6 of 8\n";  self::get_agents($harvester->process_row_type('http://eol.org/schema/agent/Agent'));
            echo "\n7 of 8\n";  self::get_vernaculars($harvester->process_row_type('http://rs.gbif.org/terms/1.0/VernacularName'));
        }
        unset($harvester);
        echo "\n8 of 8\n";  $this->archive_builder->finalize(TRUE);

        // remove temp dir
        recursive_rmdir($temp_dir);
        echo ("\n temporary directory removed: " . $temp_dir);
        print_r($this->debug);
    }
    private function process_fields($records, $class)
    {
        foreach($records as $rec) {
            if    ($class == "vernacular") $c = new \eol_schema\VernacularName();
            elseif($class == "agent")      $c = new \eol_schema\Agent();
            elseif($class == "reference")  $c = new \eol_schema\Reference();
            else exit("\nUndefined class. Investigate.\n");
            $keys = array_keys($rec);
            foreach($keys as $key) {
                $temp = pathinfo($key);
                $field = $temp["basename"];

                // some fields have '#', e.g. "http://schemas.talis.com/2005/address/schema#localityName"
                $parts = explode("#", $field);
                if($parts[0]) $field = $parts[0];
                if(@$parts[1]) $field = $parts[1];

                // /* manual adjustments
                if($class == "agent") { # test detected
                    if($field == "term_homepage") {
                        if($val = @$rec[$key]) $rec[$key] = str_replace("&amp;", "&", $val);
                    }
                }
                // */

                $c->$field = $rec[$key];
                if($field == "taxonID") $c->$field = self::get_worms_taxon_id($c->$field);

                // /* new: Oct 19, 2023
                if(in_array($field, array("full_reference", "primaryTitle", "title", "doi", "localityName"))) $c->$field = RemoveHTMLTagsAPI::remove_html_tags($c->$field);
                // */
            }
            
            // /* remove [source] == 'DEU' per https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=67026&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-67026
            if($class == "vernacular") {
                if($rec["http://purl.org/dc/terms/language"] == 'DEU') continue;
            }
            // */
            
            $this->archive_builder->write_object_to_file($c);
        } // end foreach()
    }
    /*
    synonym ->  379702	WoRMS:citation:379702	255040	Leptasterias epichlora (Brandt, 1835)
    child ->    934667	WoRMS:citation:934667		Leptasterias epichlora alaskensis Verrill, 1914	Verrill, A.E. (1914).
    child ->    934669	WoRMS:citation:934669		Leptasterias epichlora alaskensis var. siderea Verrill, 1914	Verrill, A.E. (1914). Monograph of the shallow-water 
    */
    private function get_all_children_of_synonyms($records = array())
    {
        $this->synonyms_without_children = self::get_synonyms_without_children(); //used so script will no longer lookup if this syn is known to have no children.
        //=====================================
        // /* commented when building up the file 26_children_of_synonyms.txt. 6 connectors running during build-up ----- COMMENT DURING BUILD-UP WITH 6 CONNECTORS, BUT UN-COMMENT IN REAL OPERATION ----- 
        $filename = CONTENT_RESOURCE_LOCAL_PATH . "26_files/" . $this->resource_id . "_children_of_synonyms.txt";
        if(file_exists($filename)) {
            $txt = file_get_contents($filename);
            $AphiaIDs = explode("\n", $txt);
            $AphiaIDs = array_filter($AphiaIDs);
            $AphiaIDs = array_unique($AphiaIDs);
            return $AphiaIDs;
        }
        // */
        
        // Continues here if 26_children_of_synonyms.txt hasn't been created yet.
        $filename = CONTENT_RESOURCE_LOCAL_PATH . "26_files/" . $this->resource_id . "_children_of_synonyms.txt";
        $WRITE = fopen($filename, "a");
        
        $AphiaIDs = array();
        $i = 0; //for debug
        $k = 0; $m = count($records)/6; //100000; //only for breakdown when caching
        foreach($records as $rec) {
            $k++; echo " ".number_format($k)." ";
            if(($k % 1000) == 0) echo " ".number_format($k)." ";
            /* breakdown when caching: total ~565,280
            $cont = false;
            // if($k >=  1    && $k < $m) $cont = true;     //1 -   100,000
            // if($k >=  $m   && $k < $m*2) $cont = true;   //100,000 - 200,000
            // if($k >=  $m*2 && $k < $m*3) $cont = true;   //200,000 - 300,000
            // if($k >=  $m*3 && $k < $m*4) $cont = true;   //300,000 - 400,000
            // if($k >=  $m*4 && $k < $m*5) $cont = true;   //400,000 - 500,000
            // if($k >=  $m*5 && $k < $m*6) $cont = true;   //500,000 - 600,000
            if(!$cont) continue;
            */
            
            $status = $rec["http://rs.tdwg.org/dwc/terms/taxonomicStatus"];
            
            //special case where "REMAP_ON_EOL" -> status also becomes 'synonym'
            $taxonRemarks = (string) $rec["http://rs.tdwg.org/dwc/terms/taxonRemarks"];
            if(is_numeric(stripos($taxonRemarks, 'REMAP_ON_EOL'))) $status = "synonym";
            
            if($status == "synonym") {
                $i++;
                $taxon_id = self::get_worms_taxon_id($rec["http://rs.tdwg.org/dwc/terms/taxonID"]);
                $taxo_tmp = self::get_children_of_taxon($taxon_id);
                if($taxo_tmp) fwrite($WRITE, implode("\n", $taxo_tmp) . "\n");
                // if($i >= 10) break; //debug
            }
        }
        fclose($WRITE);

        // /* //to make unique rows -> call the same function -> uncomment in real operation --- COMMENT DURING BUILD-UP WITH 6 CONNECTORS, BUT UN-COMMENT IN REAL OPERATION
        $AphiaIDs = self::get_all_children_of_synonyms();
        //save to text file
        $WRITE = fopen($filename, "w"); //will overwrite existing
        fwrite($WRITE, implode("\n", $AphiaIDs) . "\n");
        fclose($WRITE);
        // */
        
        return $AphiaIDs;
        /* sample children of a synonym e.g. AphiaID = 13
        [147416] =>
        [24] =>
        [147698] =>
        */
    }
    private function get_children_of_taxon($taxon_id)
    {   $taxo_tmp = array();
        //start ====
        $temp = self::get_children_of_synonym($taxon_id);
        $taxo_tmp = array_merge($taxo_tmp, $temp);
        //start 2nd loop -> process children of children
        foreach($temp as $id) {
            $temp2 = self::get_children_of_synonym($id);
            $taxo_tmp = array_merge($taxo_tmp, $temp2);
            //start 3rd loop -> process children of children of children
            foreach($temp2 as $id) {
                $temp3 = self::get_children_of_synonym($id);
                $taxo_tmp = array_merge($taxo_tmp, $temp3);
                //start 4th loop -> process children of children of children
                foreach($temp3 as $id) {
                    $temp4 = self::get_children_of_synonym($id);
                    $taxo_tmp = array_merge($taxo_tmp, $temp4);
                    //start 5th loop -> process children of children of children
                    foreach($temp4 as $id) {
                        $temp5 = self::get_children_of_synonym($id);
                        $taxo_tmp = array_merge($taxo_tmp, $temp5);
                        //start 6th loop -> process children of children of children
                        foreach($temp5 as $id) {
                            $temp6 = self::get_children_of_synonym($id);
                            $taxo_tmp = array_merge($taxo_tmp, $temp6);
                            //start 7th loop -> process children of children of children
                            foreach($temp6 as $id) {
                                $temp7 = self::get_children_of_synonym($id);
                                $taxo_tmp = array_merge($taxo_tmp, $temp7);
                                //start 8th loop -> process children of children of children
                                foreach($temp7 as $id) {
                                    $temp8 = self::get_children_of_synonym($id);
                                    $taxo_tmp = array_merge($taxo_tmp, $temp8);
                                    //start 9th loop -> process children of children of children
                                    foreach($temp8 as $id) {
                                        $temp9 = self::get_children_of_synonym($id);
                                        $taxo_tmp = array_merge($taxo_tmp, $temp9);
                                        //start 10th loop -> process children of children of children
                                        foreach($temp9 as $id) {
                                            $temp10 = self::get_children_of_synonym($id);
                                            $taxo_tmp = array_merge($taxo_tmp, $temp10);
                                            //start 11th loop -> process children of children of children
                                            foreach($temp10 as $id) {
                                                $temp11 = self::get_children_of_synonym($id);
                                                $taxo_tmp = array_merge($taxo_tmp, $temp11);
                                                //start 12th loop -> process children of children of children
                                                foreach($temp11 as $id) {
                                                    $temp12 = self::get_children_of_synonym($id);
                                                    $taxo_tmp = array_merge($taxo_tmp, $temp12);
                                                    //start 13th loop -> process children of children of children
                                                    foreach($temp12 as $id) {
                                                        // print("\nreaches 13th loop\n");
                                                        $temp13 = self::get_children_of_synonym($id);
                                                        $taxo_tmp = array_merge($taxo_tmp, $temp13);
                                                        //start 14th loop -> process children of children of children
                                                        foreach($temp13 as $id) {
                                                            print("\nreaches 14th loop\n");
                                                            $temp14 = self::get_children_of_synonym($id);
                                                            $taxo_tmp = array_merge($taxo_tmp, $temp14);
                                                            //start 15th loop -> process children of children of children
                                                            foreach($temp14 as $id) {
                                                                print("\nreaches 15th loop\n");
                                                                $temp15 = self::get_children_of_synonym($id);
                                                                $taxo_tmp = array_merge($taxo_tmp, $temp15);
                                                                //start 16th loop -> process children of children of children
                                                                foreach($temp15 as $id) {
                                                                    print("\nreaches 16th loop\n");
                                                                    $temp16 = self::get_children_of_synonym($id);
                                                                    $taxo_tmp = array_merge($taxo_tmp, $temp16);
                                                                    //start 17th loop -> process children of children of children
                                                                    foreach($temp16 as $id) {
                                                                        print("\nreaches 17th loop\n");
                                                                        $temp17 = self::get_children_of_synonym($id);
                                                                        $taxo_tmp = array_merge($taxo_tmp, $temp17);
                                                                        //start 18th loop -> process children of children of children
                                                                        foreach($temp17 as $id) {
                                                                            exit("\nreaches 18th loop\n");
                                                                            $temp18 = self::get_children_of_synonym($id);
                                                                            $taxo_tmp = array_merge($taxo_tmp, $temp18);
                                                                        }//end 18th loop
                                                                    }//end 17th loop
                                                                }//end 16th loop
                                                            }//end 15th loop
                                                        }//end 14th loop
                                                    }//end 13th loop
                                                }//end 12th loop
                                            }//end 11th loop
                                        }//end 10th loop
                                    }//end 9th loop
                                }//end 8th loop
                            }//end 7th loop
                        }//end 6th loop
                    }//end 5th loop
                }//end 4th loop
            }//end 3rd loop
        }//end 2nd loop
        $taxo_tmp = array_unique($taxo_tmp);
        $taxo_tmp = array_filter($taxo_tmp);
        //end ====
        return $taxo_tmp;
    }
    private function get_children_of_synonym($taxon_id)
    {
        if(in_array($taxon_id, $this->synonyms_without_children)) return array();
        $final = array();
        $options = $this->download_options;
        $options['download_wait_time'] = 500000; //500000 -> half a second; 1 million is 1 second
        $options['delay_in_minutes'] = 0;
        $options['download_attempts'] = 1;

        $offset = 1;
        if($json = Functions::lookup_with_cache($this->webservice['AphiaChildrenByAphiaID'].$taxon_id, $options)) {
            while(true) {
                // echo " $offset";
                if($offset == 1) $url = $this->webservice['AphiaChildrenByAphiaID'].$taxon_id;
                else             $url = $this->webservice['AphiaChildrenByAphiaID'].$taxon_id."?offset=$offset";
                if($json = Functions::lookup_with_cache($url, $options)) {
                    if($arr = json_decode($json, true)) {
                        foreach($arr as $a) $final[] = $a['AphiaID'];
                    }
                    if(count($arr) < 50) break;
                }
                else break;
                $offset = $offset + 50;
            }
        }
        else {
            $error_no = Functions::fake_user_agent_http_get($this->webservice['AphiaChildrenByAphiaID'].$taxon_id, array("return_error_no" => true));
            if($error_no == 0) {
                echo "\nAccess OK\n";
                echo "\nsave_2text_synonyms_without_children\n";
                self::save_2text_synonyms_without_children($taxon_id);
            }
            else echo "\nError access, will NOT save\n";
        }
        return $final;
    }
    private function save_2text_synonyms_without_children($taxon_id)
    {   $filename = CONTENT_RESOURCE_LOCAL_PATH . "26_files/" . $this->resource_id . "_synonyms_without_children.txt";
        $WRITE = fopen($filename, "a");
        fwrite($WRITE, $taxon_id . "\n");
        fclose($WRITE);
    }
    private function get_synonyms_without_children()
    {   $filename = CONTENT_RESOURCE_LOCAL_PATH . "26_files/" . $this->resource_id . "_synonyms_without_children.txt";
        if(file_exists($filename)) {
            $txt = file_get_contents($filename);
            $AphiaIDs = explode("\n", $txt);
            $AphiaIDs = array_filter($AphiaIDs);
            $AphiaIDs = array_unique($AphiaIDs);
            return $AphiaIDs;
        }
        return array();
    }
    private function get_worms_taxon_id($worms_id)
    {
        return str_ireplace("urn:lsid:marinespecies.org:taxname:", "", (string) $worms_id);
    }
    private function build_taxa_rank_array($records)
    {   foreach($records as $rec) {
            $taxon_id = self::get_worms_taxon_id($rec["http://rs.tdwg.org/dwc/terms/taxonID"]);
            $this->taxa_rank[$taxon_id]['r'] = (string) $rec["http://rs.tdwg.org/dwc/terms/taxonRank"];
            $this->taxa_rank[$taxon_id]['s'] = (string) $rec["http://rs.tdwg.org/dwc/terms/taxonomicStatus"];
            $this->taxa_rank[$taxon_id]['n'] = (string) $rec["http://rs.tdwg.org/dwc/terms/scientificName"];
        }
    }
    private function create_instances_from_taxon_object($records)
    {
        if($this->what == "taxonomy") $undeclared_ids = self::get_undeclared_parent_ids(); //uses a historical text file - undeclared parents. If not to use this, then there will be alot of API calls needed.
        $k = 0;
        foreach($records as $rec) {
            $rec = array_map('trim', $rec);
            $k++;
            // if(($k % 100) == 0) echo "\n count: $k";
            /* breakdown when caching:
            $cont = false;
            // if($k >=  1   && $k < 200000) $cont = true;
            // if($k >=  200000 && $k < 400000) $cont = true;
            // if($k >=  400000 && $k < 600000) $cont = true;
            if(!$cont) continue;
            */
            // print_r($rec); exit;
            /* Array(
                [http://rs.tdwg.org/dwc/terms/taxonID] => urn:lsid:marinespecies.org:taxname:1
                [http://rs.tdwg.org/dwc/terms/scientificName] => Biota
                [http://rs.tdwg.org/dwc/terms/parentNameUsageID] => 
                [http://rs.tdwg.org/dwc/terms/kingdom] => 
                [http://rs.tdwg.org/dwc/terms/phylum] => 
                [http://rs.tdwg.org/dwc/terms/class] => 
                [http://rs.tdwg.org/dwc/terms/order] => 
                [http://rs.tdwg.org/dwc/terms/family] => 
                [http://rs.tdwg.org/dwc/terms/genus] => 
                [http://rs.tdwg.org/dwc/terms/taxonRank] => kingdom
                [http://rs.tdwg.org/ac/terms/furtherInformationURL] => http://www.marinespecies.org/aphia.php?p=taxdetails&id=1
                [http://rs.tdwg.org/dwc/terms/taxonomicStatus] => accepted
                [http://rs.tdwg.org/dwc/terms/taxonRemarks] => 
                [http://rs.tdwg.org/dwc/terms/namePublishedIn] => 
                [http://eol.org/schema/media/referenceID] => WoRMS:citation:1
                [http://rs.tdwg.org/dwc/terms/acceptedNameUsageID] => urn:lsid:marinespecies.org:taxname:1
                [http://purl.org/dc/terms/rights] => http://creativecommons.org/licenses/by/4.0/
                [http://purl.org/dc/terms/rightsHolder] => WoRMS Editorial Board
                [http://rs.tdwg.org/dwc/terms/datasetName] => World Register of Marine Species (WoRMS)
            )*/
            $taxon = new \eol_schema\Taxon(); //for media_objects, MoF. Not for taxonomy.
            $taxon->taxonID = self::get_worms_taxon_id($rec["http://rs.tdwg.org/dwc/terms/taxonID"]);
            
            if($this->what == "taxonomy") {
                if(in_array($taxon->taxonID, $this->children_of_synonyms)) continue; //exclude children of synonyms
            }
            
            $taxon->scientificName  = (string) $rec["http://rs.tdwg.org/dwc/terms/scientificName"];
            $taxon->scientificName = self::format_incertae_sedis($taxon->scientificName);
            if(!$taxon->scientificName) continue;
            
            if($taxon->scientificName != "Biota") {
                $val = self::get_worms_taxon_id($rec["http://rs.tdwg.org/dwc/terms/parentNameUsageID"]);
                if($this->what == "taxonomy") {
                    if(in_array($val, $undeclared_ids)) $taxon->parentNameUsageID = self::get_valid_parent_id($taxon->taxonID); //based here: https://eol-jira.bibalex.org/browse/TRAM-520?focusedCommentId=60658&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-60658
                    else                                $taxon->parentNameUsageID = $val;
                }
                else { //media_objects
                    /* deliberately removed Oct 16, 2019
                    $taxon->parentNameUsageID = $val;
                    */
                }
            }
            
            $taxon->taxonRank       = (string) $rec["http://rs.tdwg.org/dwc/terms/taxonRank"];
            $this->debug['ranks'][$taxon->taxonRank] = '';
            
            $taxon->taxonomicStatus = (string) $rec["http://rs.tdwg.org/dwc/terms/taxonomicStatus"];
            $taxon->taxonRemarks    = (string) $rec["http://rs.tdwg.org/dwc/terms/taxonRemarks"];
            
            if($this->what == "taxonomy") { //based on https://eol-jira.bibalex.org/browse/TRAM-520?focusedCommentId=60923&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-60923
                if($taxon->taxonomicStatus == "") continue; //synonymous to cases where "unassessed" in taxonRemarks
            }
            
            if(is_numeric(stripos($taxon->taxonRemarks, 'REMAP_ON_EOL'))) $taxon->taxonomicStatus = "synonym";

            if($val = (string) $rec["http://rs.tdwg.org/dwc/terms/acceptedNameUsageID"]) $taxon->acceptedNameUsageID  = self::get_worms_taxon_id($val);
            else $taxon->acceptedNameUsageID = '';

            if($taxon->taxonomicStatus == "accepted") {
                if((string) $rec["http://rs.tdwg.org/dwc/terms/acceptedNameUsageID"]) $taxon->acceptedNameUsageID = "";
            }
            elseif($taxon->taxonomicStatus == "synonym") {
                if(!$taxon->acceptedNameUsageID) continue; //is syn but no acceptedNameUsageID, ignore this taxon
            }
            else { //not "synonym" and not "accepted"
                //not syn but has acceptedNameUsageID; seems possible, so just accept it
            }

            if($taxon->taxonID == @$taxon->acceptedNameUsageID) $taxon->acceptedNameUsageID = '';
            /* deliberately removed Oct 16, 2019
            if($taxon->taxonID == @$taxon->parentNameUsageID)   $taxon->parentNameUsageID = '';
            */
            if($taxon->taxonomicStatus == "synonym") { // this will prevent names to become synonyms of another where the ranks are different
                if($taxon->taxonRank != @$this->taxa_rank[$taxon->acceptedNameUsageID]['r']) continue;
                /* deliberately removed Oct 16, 2019
                $taxon->parentNameUsageID = ''; //remove the ParentNameUsageID data from all of the synonym lines
                */
            }
            
            if($this->what == "taxonomy") { //based on https://eol-jira.bibalex.org/browse/TRAM-520?focusedCommentId=60923&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-60923
                if(@$taxon->parentNameUsageID) {
                    if(!self::if_accepted_taxon($taxon->parentNameUsageID)) continue;
                }
            }
            
            // /* stats
            $this->debug["status"][$taxon->taxonomicStatus] = '';
            @$this->debug["count"][$taxon->taxonomicStatus]++;
            @$this->debug["count"]["count"]++;
            // */

            $taxon->namePublishedIn = RemoveHTMLTagsAPI::remove_html_tags((string) $rec["http://rs.tdwg.org/dwc/terms/namePublishedIn"]);
            $taxon->rightsHolder    = (string) $rec["http://purl.org/dc/terms/rightsHolder"];
            $taxon->source = $this->taxon_page . $taxon->taxonID;
            if($referenceID = self::prepare_reference((string) $rec["http://eol.org/schema/media/referenceID"])) $taxon->referenceID = self::use_correct_separator($referenceID);

            // /* new Aug 25, 2019 - 
            if($this->what != "taxonomy") {
                if($taxon->taxonomicStatus != "accepted") continue;
                /* deliberately removed Oct 16, 2019
                $taxon->parentNameUsageID = ''; //source has many parentNameUsageID but without its own taxon entry. So will not use at all.
                */
            }
            // */

            // /* new Oct 16, 2019: https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=64047&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-64047
            $taxon->kingdom = $rec['http://rs.tdwg.org/dwc/terms/kingdom'];
            $taxon->phylum = $rec['http://rs.tdwg.org/dwc/terms/phylum'];
            $taxon->class = $rec['http://rs.tdwg.org/dwc/terms/class'];
            $taxon->order = $rec['http://rs.tdwg.org/dwc/terms/order'];
            $taxon->family = $rec['http://rs.tdwg.org/dwc/terms/family'];
            $taxon->genus = $rec['http://rs.tdwg.org/dwc/terms/genus'];
            if($val = @$rec['http://rs.tdwg.org/dwc/terms/taxonRank']) {
                if(in_array($val, array('kingdom','phylum','class','order','family','genus'))) $taxon->$val = ''; //set to blank
            }
            // */

            if(!isset($this->taxon_ids[$taxon->taxonID])) {
                $this->taxon_ids[$taxon->taxonID] = '';
                $this->archive_builder->write_object_to_file($taxon);
                // Functions::lookup_with_cache($this->gnsparser.self::format_sciname($taxon->scientificName), $this->smasher_download_options);
            }
            /* not used:
            <field index="15" default="http://creativecommons.org/licenses/by/3.0/" term="http://purl.org/dc/terms/accessRights"/>
            <field index="17" default="World Register of Marine Species (WoRMS)" term="http://rs.tdwg.org/dwc/terms/datasetName"/>
            */
        }
    }
    private function format_sciname($str)
    {   //http://parser.globalnames.org/doc/api

        // $str = str_replace("&", "%26", $str);
        // $str = str_replace(" ", "+", $str);
        return urlencode($str);
    }
    private function if_accepted_taxon($taxon_id)
    {
        if($status = @$this->taxa_rank[$taxon_id]['s']) {
            if($status == "accepted") return true;
            else return false;
        }
        else { //let the API decide
            if($json = Functions::lookup_with_cache($this->webservice['AphiaRecordByAphiaID'].$taxon_id, $this->download_options)) {
                $arr = json_decode($json, true);
                // print_r($arr);
                if($arr['status'] == "accepted") return true;
            }
            return false;
        }
        return false;
    }
    private function build_parentOf_childOf_data($meta) // parentOf not used so far
    {   $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) {
            $i++; if(($i % 500000) == 0) echo "\n".number_format($i);
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                /* old x
                if(!$field['term']) continue;
                $rec[$field['term']] = $tmp[$k];
                $k++;
                */
                // /* new May 11, 2021
                $term = @$field['term'];
                $rec[$term] = @$tmp[$k];
                $k++;
                // */
            } //print_r($rec); exit;
            /*Array(
                [http://rs.tdwg.org/dwc/terms/MeasurementOrFact] => 1054700
                [http://rs.tdwg.org/dwc/terms/measurementID] => 286376_1054700
                [parentMeasurementID] => 
                [http://rs.tdwg.org/dwc/terms/measurementType] => Functional group
                [http://rs.tdwg.org/dwc/terms/measurementValueID] => 
                [http://rs.tdwg.org/dwc/terms/measurementValue] => benthos
                [http://rs.tdwg.org/dwc/terms/measurementUnit] => 
                [http://rs.tdwg.org/dwc/terms/measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:101
            )*/
            
            /* for stats only - comment in real operation
            if($rec['parentMeasurementID']) $withParentYN = 'with_Parent';
            else                            $withParentYN = 'without_Parent';
            $all_mtypes[$rec['http://rs.tdwg.org/dwc/terms/measurementType']][$withParentYN] = '';
            */
            
            $this->parentOf[$rec['http://rs.tdwg.org/dwc/terms/measurementID']] = @$rec['parentMeasurementID'];
            if($parent = @$rec['parentMeasurementID']) $this->childOf[$parent] = $rec['http://rs.tdwg.org/dwc/terms/measurementID'];
            
            //this is to store URI map. this->childOf and this->BodysizeDimension will work hand in hand later on.
            if($rec['http://rs.tdwg.org/dwc/terms/measurementType'] == 'Body size > Dimension') {
                $mValue = strtolower($rec['http://rs.tdwg.org/dwc/terms/measurementValue']);
                $this->BodysizeDimension[$rec['http://rs.tdwg.org/dwc/terms/measurementID']] = $this->BsD_URI[$mValue];
            }
            if($rec['http://rs.tdwg.org/dwc/terms/measurementType'] == 'Feedingtype') {
                $mValue = strtolower($rec['http://rs.tdwg.org/dwc/terms/measurementValue']);
                $this->FeedingType[$rec['http://rs.tdwg.org/dwc/terms/measurementID']] = $mValue;
            }
            
            // 292968 | 415014_292968 | 415013_292968 | Feedingtype > Stage |  | adult |  | 
            if($rec['http://rs.tdwg.org/dwc/terms/measurementType'] == 'Feedingtype > Stage') {
                $mValue = strtolower($rec['http://rs.tdwg.org/dwc/terms/measurementValue']);
                $this->lifeStageOf[$rec['http://rs.tdwg.org/dwc/terms/measurementID']] = $mValue;
            }
            $this->measurementIDz[$rec['http://rs.tdwg.org/dwc/terms/measurementID']] = '';
        }
        // ksort($all_mtypes); print_r($all_mtypes); exit; -- for stats only
        /* just testing
        // exit("\nsuper parent of [528458_768436]: ".self::get_super_parent('528458_768436')."\n");
        // $super_child = self::get_super_child('528452_768436'); exit("\nsuper child of [528452_768436]: ".$super_child."\n".$this->BodysizeDimension[$super_child]."\n");
        // exit("\nsuper parent of [168362_141433]: ".self::get_super_parent('168362_141433')."\n"); //super parent should be: 168359_141433 OK result
        */
    }
    private function get_mIDs_2exclude($meta)
    {   $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) {
            $i++; if(($i % 500000) == 0) echo "\n".number_format($i);
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                /* old x
                if(!$field['term']) continue;
                $rec[$field['term']] = $tmp[$k];
                $k++;
                */
                // /* new May 11, 2021
                $term = @$field['term'];
                $rec[$term] = @$tmp[$k];
                $k++;
                // */
            }
            // print_r($rec); exit;
            /*Array(
                [http://rs.tdwg.org/dwc/terms/MeasurementOrFact] => 1054700
                [http://rs.tdwg.org/dwc/terms/measurementID] => 286376_1054700
                [parentMeasurementID] => 
                [http://rs.tdwg.org/dwc/terms/measurementType] => Functional group
                [http://rs.tdwg.org/dwc/terms/measurementValueID] => 
                [http://rs.tdwg.org/dwc/terms/measurementValue] => benthos
                [http://rs.tdwg.org/dwc/terms/measurementUnit] => 
                [http://rs.tdwg.org/dwc/terms/measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:101
                                                                      inherited from urn:lsid:marinespecies.org:taxname:558
            )*/
            $mID = $rec['http://rs.tdwg.org/dwc/terms/measurementID'];
            $mType = $rec['http://rs.tdwg.org/dwc/terms/measurementType'];
            $mValue = $rec['http://rs.tdwg.org/dwc/terms/measurementValue'];
            $measurementAccuracy = $rec['http://rs.tdwg.org/dwc/terms/measurementAccuracy'];
            
            // /* 1st criteria for deletion
            if(isset($this->exclude_mType_mValue[$mType][$mValue])) {
                $this->ToExcludeMeasurementIDs[$mID] = '';
                if($child = @$this->childOf[$mID]) $this->ToExcludeMeasurementIDs[$child] = '';
            }
            // */
            
            // /* 2nd criteria for deletion: per https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=67036&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-67036
            /*
            To start with, let's filter out all records with measurementMethod = inherited from urn:lsid:marinespecies.org:taxname:558, Porifera Grant, 1836
            */
            if($measurementAccuracy == 'inherited from urn:lsid:marinespecies.org:taxname:558') {
                $this->ToExcludeMeasurementIDs[$mID] = '';
                if($child = @$this->childOf[$mID]) $this->ToExcludeMeasurementIDs[$child] = '';
            }
            // */
        }
    }
    private function get_super_child($id)
    {   $current = '';
        while(true) {
            if($parent = @$this->childOf[$id]) {
                $current = $parent;
                $id = $current;
            }
            else return $current;
        }
    }
    private function get_super_parent($id)
    {   $current = '';
        while(true) {
            if($parent = @$this->parentOf[$id]) {
                $current = $parent;
                $id = $current;
            }
            else return $current;
        }
    }
    private function get_measurements($meta)
    {   echo "\nprocess_measurementorfact...\n"; $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) {
            $i++; if(($i % 500000) == 0) echo "\n".number_format($i);
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                /* old x
                if(!$field['term']) continue;
                $rec[$field['term']] = $tmp[$k];
                $k++;
                */
                // /* new May 11, 2021
                $term = @$field['term'];
                $rec[$term] = @$tmp[$k] ? trim($tmp[$k]) : "";
                $k++;
                // */
            } // print_r($rec); exit;
            // if($rec) $rec = Functions::array_map_eol('trim', $rec); //worked OK - important!

            // /* Eli Dec 16. To remove 3 parentMoF without entry. From: https://editors.eol.org/eol_php_code/applications/content_server/resources/26_undefined_parentMeasurementIDs.txt
            $mID = $rec['http://rs.tdwg.org/dwc/terms/measurementID'];
            if(in_array($mID, array('749320_160945', '749321_160945', '749346_120936', '749347_120936', '749374_583525', '749375_583525'))) continue;
            // 160945   749320_160945       Functional group        meiobenthos     
            // 160945   749321_160945   749320_160945   Functional group > Stage        adult       
            // 120936   749346_120936       Functional group        meiobenthos     
            // 120936   749347_120936   749346_120936   Functional group > Stage        adult       
            // 583525   749374_583525       Functional group        meiobenthos     
            // 583525   749375_583525   749374_583525   Functional group > Stage        adult       
            // */

            /* just for testing...
            $mtype = $rec['http://rs.tdwg.org/dwc/terms/measurementType'];
            // if($mtype == 'Body size > Gender' && !$rec['parentMeasurementID']) print_r($rec);
            if($mtype == 'Species importance to society > IUCN Red List Category > Year Assessed' && !$rec['parentMeasurementID']) print_r($rec);
            continue;
            */
            
            if(isset($this->ToExcludeMeasurementIDs[$rec['http://rs.tdwg.org/dwc/terms/measurementID']])) continue;
            //========================================================================================================first task - association
            if($rec['http://rs.tdwg.org/dwc/terms/measurementType'] == 'Feedingtype > Host') { // print_r($rec); exit;
                /*Array(
                    [http://rs.tdwg.org/dwc/terms/MeasurementOrFact] => 292968
                    [http://rs.tdwg.org/dwc/terms/measurementID] => 415015_292968
                    [parentMeasurementID] => 415014_292968
                    [http://rs.tdwg.org/dwc/terms/measurementType] => Feedingtype > Host
                    [http://rs.tdwg.org/dwc/terms/measurementValueID] => urn:lsid:marinespecies.org:taxname:217662
                    [http://rs.tdwg.org/dwc/terms/measurementValue] => Saurida gracilis (Quoy & Gaimard, 1824)
                    [http://rs.tdwg.org/dwc/terms/measurementUnit] => 
                    [http://rs.tdwg.org/dwc/terms/measurementAccuracy] => 
                )*/
                // continue; //debug only
                /* source is: 292968   target is: 217662
                e.g. MoF
                occurrenceID , associationType , targetOccurrenceID
                292968_RO_0002454 , http://purl.obolibrary.org/obo/RO_0002454 , 217662_292968_RO_0002454
                */
                
                // /* new way to get predicate (and its reverse) instead of just 'RO_0002454' (and its reverse RO_0002453) per: https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=63753&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-63753
                // AphiaID | measurementID | parentMeasurementID | measurementType | measurementValueID | measurementValue | measurementUnit | measurementAccuracy
                // 292968 | 415013_292968 |  | Feedingtype |  | ectoparasitic |  | 
                // 292968 | 415014_292968 | 415013_292968 | Feedingtype > Stage |  | adult |  | 
                // 292968 | 415015_292968 | 415014_292968 | Feedingtype > Host | urn:lsid:marinespecies.org:taxname:217662 | Saurida gracilis (Quoy & Gaimard, 1824)
                $mID = $rec['http://rs.tdwg.org/dwc/terms/measurementID'];
                $super_parent = self::get_super_parent($mID);
                if($value_str = @$this->FeedingType[$super_parent]) {
                    $this->debug['FeedingType'][$value_str] = '';
                    if(in_array($value_str, array('carnivore', 'unknown', 'omnivore', 'commensal', 'on sessile prey', 'predator', 'scavenger'))) continue; //were not initialized in ticket, no instruction.
                    $predicate         = $this->fType_URI[$value_str]['reg'];
                    $predicate_reverse = $this->fType_URI[$value_str]['rev'];
                }
                else {
                    // print_r($rec);
                    print("\nInvestigate: cannot link to parent record [$super_parent].\n"); //e.g. 478430_458997 legit no parent record
                    continue;
                }
                //get lifeStage if any
                $lifeStage = '';
                if($parent = $rec['parentMeasurementID']) {
                    if($value_str = @$this->lifeStageOf[$parent]) { //e.g. 'adult'
                        $lifeStage = self::get_uri_from_value($value_str, 'mValue', 'lifeStage');
                    }
                }
                // */
                if($predicate) {
                    $param = array('source_taxon_id' => $rec['http://rs.tdwg.org/dwc/terms/MeasurementOrFact'],     'predicate' => $predicate, 
                                   'target_taxon_id' => $rec['http://rs.tdwg.org/dwc/terms/measurementValueID'],    'target_taxon_name' => $rec['http://rs.tdwg.org/dwc/terms/measurementValue'], 
                                   'lifeStage' => $lifeStage);
                    self::add_association($param);
                }

                /*Now do the reverse*/
                if($predicate_reverse) {
                    $sciname = 'will look up or create';
                    if($sciname = $this->taxa_rank[self::get_worms_taxon_id($rec['http://rs.tdwg.org/dwc/terms/MeasurementOrFact'])]['n']) {}
                    else {
                        print_r($rec);
                        exit("\nWill need to add taxon first\n");
                    }
                    $param = array('source_taxon_id' => self::get_worms_taxon_id($rec['http://rs.tdwg.org/dwc/terms/measurementValueID']), 'predicate' => $predicate_reverse, 
                                   'target_taxon_id' => $rec['http://rs.tdwg.org/dwc/terms/MeasurementOrFact'], 
                                   'target_taxon_name' => $sciname);
                    self::add_association($param);
                }
                // break; //debug only --- do this if you want to proceed create DwCA
                continue; //part of real operation. Can go next row now
            }
            //========================================================================================================next task --- worms_mapping1.csv
            /*Array( $this->match2map
                [Feedingtype] => Array(
                        [carnivore] => Array(
                                [mTypeURL] => http://www.wikidata.org/entity/Q1053008
                                [mValueURL] => https://www.wikidata.org/entity/Q81875 */
            $mtype = $rec['http://rs.tdwg.org/dwc/terms/measurementType'];      //e.g. 'Functional group'
            $mvalue = $rec['http://rs.tdwg.org/dwc/terms/measurementValue'];    //e.g. 'benthos'
            $taxon_id = self::get_worms_taxon_id($rec['http://rs.tdwg.org/dwc/terms/MeasurementOrFact']);
            
            /* debug only
            if($mtype == 'Functional group' && $mvalue == 'benthos') {}
            else continue;
            */
            
            if($info = @$this->match2map[$mtype][$mvalue]) { //$this->match2map came from a CSV mapping file
                // continue;
                // print_r($info); print_r($rec); exit;
                /*Array( $info
                    [mTypeURL] => http://rs.tdwg.org/dwc/terms/habitat
                    [mValueURL] => http://purl.obolibrary.org/obo/ENVO_01000024
                Array( $rec
                    [http://rs.tdwg.org/dwc/terms/MeasurementOrFact] => 1054700
                    [http://rs.tdwg.org/dwc/terms/measurementID] => 286376_1054700
                    [parentMeasurementID] => 
                    [http://rs.tdwg.org/dwc/terms/measurementType] => Functional group
                    [http://rs.tdwg.org/dwc/terms/measurementValueID] => 
                    [http://rs.tdwg.org/dwc/terms/measurementValue] => benthos
                    [http://rs.tdwg.org/dwc/terms/measurementUnit] => 
                    [http://rs.tdwg.org/dwc/terms/measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:101
                )*/
                $save = array();
                $save['measurementID'] = $rec['http://rs.tdwg.org/dwc/terms/measurementID'];
                $save['taxon_id'] = $taxon_id;
                $save["catnum"] = $taxon_id.'_'.$rec['http://rs.tdwg.org/dwc/terms/measurementType'].$rec['http://rs.tdwg.org/dwc/terms/measurementValue']; //making it unique. no standard way of doing it.
                // $save['measurementType'] = $info['mTypeURL'];        not needed for TraitGeneric
                // $save['measurementValue'] = $info['mValueURL'];      not needed for TraitGeneric
                $save['measurementRemarks'] = $info['mRemarks'];
                $save['source'] = $this->taxon_page.$taxon_id;
                $save = self::adjustments_4_measurementAccuracy($save, $rec);
                $save['measurementUnit'] = self::format_measurementUnit($rec); //no instruction here
                $this->func->pre_add_string_types($save, $info['mValueURL'], $info['mTypeURL'], "true");
                // print_r($save); exit;
                // break; //do this if you want to proceed create DwCA
                continue; //part of real operation. Can go next row now
            }
            //========================================================================================================next task --- "Body size"
            // if(in_array($mtype, $this->real_parents)) { //the parents -- first client was 'Body size'
            if($mtype == 'Body size') { //a parent
                /*Array( e.g. 'Body size'
                    [http://rs.tdwg.org/dwc/terms/MeasurementOrFact] => 768436
                    [http://rs.tdwg.org/dwc/terms/measurementID] => 528452_768436
                    [parentMeasurementID] => 
                    [http://rs.tdwg.org/dwc/terms/measurementType] => Body size
                    [http://rs.tdwg.org/dwc/terms/measurementValueID] => 
                    [http://rs.tdwg.org/dwc/terms/measurementValue] => 0.1
                    [http://rs.tdwg.org/dwc/terms/measurementUnit] => mm
                    [http://rs.tdwg.org/dwc/terms/measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:155944
                Array( e.g. of "Body size > Dimension" //the super child
                    [http://rs.tdwg.org/dwc/terms/MeasurementOrFact] => 768436
                    [http://rs.tdwg.org/dwc/terms/measurementID] => 528458_768436
                    [parentMeasurementID] => 528454_768436
                    [http://rs.tdwg.org/dwc/terms/measurementType] => Body size > Dimension
                    [http://rs.tdwg.org/dwc/terms/measurementValueID] => 
                    [http://rs.tdwg.org/dwc/terms/measurementValue] => length
                    [http://rs.tdwg.org/dwc/terms/measurementUnit] => 
                    [http://rs.tdwg.org/dwc/terms/measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:155944
                )*/
                // print_r($rec); exit("\nBody size\n");
                
                $save = array();
                $save['measurementID'] = $rec['http://rs.tdwg.org/dwc/terms/measurementID'];
                $save['taxon_id'] = $taxon_id;
                $save["catnum"] = $taxon_id.'_'.$rec['http://rs.tdwg.org/dwc/terms/measurementType'].$rec['http://rs.tdwg.org/dwc/terms/measurementValue']; //making it unique. no standard way of doing it.
                $save['measurementRemarks'] = ''; //no instruction here
                $save['source'] = $this->taxon_page.$taxon_id;
                $save = self::adjustments_4_measurementAccuracy($save, $rec);
                $save['measurementUnit'] = self::format_measurementUnit($rec);

                if($mtype == 'Body size') {
                    $measurementID = $rec['http://rs.tdwg.org/dwc/terms/measurementID']; //e.g. 528452_768436
                    $super_child = self::get_super_child($measurementID);                //e.g. 528458_768436
                    $mTypev = @$this->BodysizeDimension[$super_child];
                    if(!$mTypev) $mTypev = 'http://purl.obolibrary.org/obo/OBA_VT0100005'; //feedback from Jen: https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=63749&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-63749
                }
                /* not used for now
                else {
                    $mTypev = self::get_uri_from_value($rec['http://rs.tdwg.org/dwc/terms/measurementType'], 'mType');
                }
                */

                $mValuev = self::get_uri_from_value($rec['http://rs.tdwg.org/dwc/terms/measurementValue'], 'mValue', 'Body size');
                // print("\nsuper child of [$measurementID]: ".$super_child."\n".$mTypev."\n");
                
                $this->func->pre_add_string_types($save, $mValuev, $mTypev, "true");
                // print_r($save); exit;
                // break; //do this if you want to proceed create DwCA
                continue; //part of real operation. Can go next row now
            }
            //========================================================================================================next task --- child of "Body size"
            $mtype = $rec['http://rs.tdwg.org/dwc/terms/measurementType']; //e.g. 'Body size > Gender'
            if(in_array($mtype, $this->children_mTypes)) {
                // print_r($rec); exit("\na child record\n");
                /*Array(
                    [http://rs.tdwg.org/dwc/terms/MeasurementOrFact] => 880402
                    [http://rs.tdwg.org/dwc/terms/measurementID] => 17650_880402
                    [parentMeasurementID] => 17649_880402
                    [http://rs.tdwg.org/dwc/terms/measurementType] => Functional group > Stage
                    [http://rs.tdwg.org/dwc/terms/measurementValueID] => 
                    [http://rs.tdwg.org/dwc/terms/measurementValue] => adult
                    [http://rs.tdwg.org/dwc/terms/measurementUnit] => 
                    [http://rs.tdwg.org/dwc/terms/measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:1806
                )*/
                $save = array();
                $save['measurementID'] = $rec['http://rs.tdwg.org/dwc/terms/measurementID'];
                /* orig but not enough, use get_super_parent() for the right parent
                $save['parentMeasurementID'] = $rec['parentMeasurementID'];
                */
                $possible_pMID = self::get_super_parent($save['measurementID']);
                if(isset($this->measurementIDz[$possible_pMID])) $save['parentMeasurementID'] = $possible_pMID;
                else continue; //this child (e.g. 492937_734633) doesn't have a parent from actual source: measurementorfact.txt. Just disregard.
                
                $save['taxon_id'] = $taxon_id;
                $save["catnum"] = $taxon_id.'_'.$rec['http://rs.tdwg.org/dwc/terms/measurementType'].$rec['http://rs.tdwg.org/dwc/terms/measurementValue']; //making it unique. no standard way of doing it.
                $save['measurementRemarks'] = ''; //no instruction here
                // $save['source'] = $this->taxon_page.$taxon_id; //no instruction here
                $save = self::adjustments_4_measurementAccuracy($save, $rec);
                $save['measurementUnit'] = self::format_measurementUnit($rec); //no instruction here
                $mTypev = self::get_uri_from_value($rec['http://rs.tdwg.org/dwc/terms/measurementType'], 'mType', 'child of Body size');
                $mValuev = self::get_uri_from_value($rec['http://rs.tdwg.org/dwc/terms/measurementValue'], 'mValue', "child of Body size-".$rec['http://rs.tdwg.org/dwc/terms/measurementType']);
                // /* both of these are synonymous to one another. FILTER OUT - just the metadata, not the MoF record
                if($mValuev == 'DISCARD') continue; //no case yet in metastats-2.tsv
                if($mValuev == 'FILTER OUT') continue; //with case already in metastats-2.tsv
                // */
                $this->func->pre_add_string_types($save, $mValuev, $mTypev, "child");
                // break; //do this if you want to proceed create DwCA
                continue; //part of real operation. Can go next row now
            }
            //========================================================================================================end tasks
        }//end foreach
    }
    private function get_uri_from_value($val, $what, $what2)
    {   if(is_numeric($val)) return $val;
        $orig = $val;
        $val = trim(strtolower($val));
        if($uri = @$this->value_uri_map[$val]) return $uri;
        elseif($uri = @$this->value_uri_map[$orig]) return $uri;
        else {
            if(!is_numeric($orig)) {
                if(!isset($this->value_uri_map[$orig])) $this->debug['no uri'][$what][$what2][$orig] = ''; //log only non-numeric values
            }
            return $orig;
        }
    }
    private function format_measurementUnit($rec)
    {   if($val = trim(@$rec['http://rs.tdwg.org/dwc/terms/measurementUnit'])) { //e.g. mm
            if($uri = @$this->mUnit[$val]) return $uri;
            else $this->debug['undefined mUnit literal'][$val] = '';
        }
    }
    private function adjustments_4_measurementAccuracy($save, $rec)
    {   if($vtaxon_id = self::get_id_from_measurementAccuracy($rec['http://rs.tdwg.org/dwc/terms/measurementAccuracy'])) {
            if($sciname = @$this->taxa_rank[$vtaxon_id]['n']) {
                $save['measurementMethod'] = $rec['http://rs.tdwg.org/dwc/terms/measurementAccuracy'].', '.$sciname;
            }
            else {
                // print_r($rec);
                // print("\nsciname not found with id from measurementAccuracy -- ");
                if($sciname = self::lookup_worms_name($vtaxon_id)) {
                    $save['measurementMethod'] = $rec['http://rs.tdwg.org/dwc/terms/measurementAccuracy'].', '.$sciname;
                    // echo "\nfound [$sciname]";
                }
                else {
                    $this->debug['sciname not found with id from measurementAccuracy'][$vtaxon_id] = '';
                    $save['measurementMethod'] = $rec['http://rs.tdwg.org/dwc/terms/measurementAccuracy'];
                }
            }
        }
        return $save;
    }
    private function lookup_worms_name($vtaxon_id)
    {   $options = $this->download_options;
        $options['expire_seconds'] = false;
        if($json = Functions::lookup_with_cache($this->webservice['AphiaRecordByAphiaID'].$vtaxon_id, $options)) {
            $arr = json_decode($json, true); // print_r($arr); exit;
            return trim($arr['scientificname']." ".$arr['authority']);
        }
        exit("\nid not found [$vtaxon_id]\n");
        return false;
    }
    private function initialize_mapping()
    {   $mappings = Functions::get_eol_defined_uris(false, true);     //1st param: false means will use 1day cache | 2nd param: opposite direction is true
        echo "\n".count($mappings). " - default URIs from EOL registry.";
        /* good debug
        if(@$mappings[1] == "http://marineregions.org/mrgid/18075") { print_r($mappings); exit("-huli ka dito pala-"); } */
        $uris = Functions::additional_mappings($mappings, 0); //add more mappings used in the past. 2nd param is expire_seconds
        // print_r($uris); exit;
        echo "\nURIs total A: ".count($uris)."\n";
        
        // /* exclusive mapping for WoRMS only
        $url = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/master/WoRMS/WoRMS_native_intro_mapping.txt';
        $uris = Functions::additional_mappings($uris, 0, $url); //add a single mapping. 2nd param is expire_seconds
        // */
        echo "\nURIs total B: ".count($uris)."\n"; //print_r($uris);
        return $uris;
    }
    private function tsv2array($url)
    {   $options = $this->download_options;
        $options['expire_seconds'] = 60*60*1; //1 hour expires
        $local = Functions::save_remote_file_to_local($url, $options);
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
                $rec = array_map('trim', $rec);
                // print_r($rec); exit;
                /*Array(
                    [measurementValue] => Female
                    [valueURI] => http://purl.obolibrary.org/obo/PATO_0000383
                )*/
                $final[strtolower($rec['measurementValue'])] = $rec['valueURI'];
            }
        }
        unlink($local);
        
        $additional_mappings = self::initialize_mapping();
        $final = $additional_mappings + $final;
        echo "\nURIs total C: ".count($final)."\n";
        print_r($final['Europe']);
        echo "\n-end test block-\n";
        /* if(@$final[1]) exit("\nCannot have 1 or any numeric value as index. Cause for investigation.\n"); */
        return $final;
    }
    private function csv2array($url, $type)
    {   $options = $this->download_options;
        $options['expire_seconds'] = 60*60*24; //1 day expires
        $local = Functions::save_remote_file_to_local($url, $options);
        $file = fopen($local, 'r');
        $i = 0;
        while(($line = fgetcsv($file)) !== FALSE) { $i++; 
            if(($i % 1000000) == 0) echo "\n".number_format($i);
            if($i == 1) $fields = $line;
            else {
                $rec = array(); $k = 0;
                foreach($fields as $fld) {
                    $rec[$fld] = $line[$k]; $k++;
                }
                // print_r($rec); exit("\nstopx\n");
                /*Array( type = 'match2map'
                    [measurementType] => Feedingtype
                    [measurementTypeURL] => http://www.wikidata.org/entity/Q1053008
                    [measurementValue] => carnivore
                    [measurementValueURL] => https://www.wikidata.org/entity/Q81875
                    [measurementRemarks] => 
                )*/
                if($type == 'match2map') {
                    $final[$rec['measurementType']][$rec['measurementValue']] = array('mTypeURL' => $rec['measurementTypeURL'], 'mValueURL' => $rec['measurementValueURL'], 'mRemarks' => $rec['measurementRemarks']);
                }
            }
        }
        unlink($local); fclose($file); // print_r($final);
        return $final;
    }
    private function get_id_from_measurementAccuracy($str)
    {   $arr = explode(":", $str);
        return array_pop($arr);
    }
    private function add_association($param)
    {   $basename = pathinfo($param['predicate'], PATHINFO_BASENAME); //e.g. RO_0002454
        $taxon_id = $param['source_taxon_id'];
        $occurrenceID = $this->add_occurrence_assoc($taxon_id, $basename, @$param['lifeStage']);
        $related_taxonID = $this->add_taxon_assoc($param['target_taxon_name'], self::get_worms_taxon_id($param['target_taxon_id']));
        if(!$related_taxonID) return;
        $related_occurrenceID = $this->add_occurrence_assoc($related_taxonID, $taxon_id.'_'.$basename);
        $a = new \eol_schema\Association();
        $a->occurrenceID = $occurrenceID;
        $a->associationType = $param['predicate'];
        $a->targetOccurrenceID = $related_occurrenceID;
        $a->source = $this->taxon_page.$taxon_id.'#attributes';
        $this->archive_builder->write_object_to_file($a);
    }
    private function add_taxon_assoc($taxon_name, $taxon_id)
    {   if(isset($this->taxon_ids[$taxon_id])) return $taxon_id;
        $t = new \eol_schema\Taxon();
        $t->taxonID = $taxon_id;
        $t->scientificName = $taxon_name;
        if(!$t->scientificName) return false; //very unique situation...
        $this->archive_builder->write_object_to_file($t);
        $this->taxon_ids[$taxon_id] = '';
        return $taxon_id;
    }
    private function add_occurrence_assoc($taxon_id, $identification_string, $lifeStage = '')
    {   $occurrence_id = $taxon_id.'_'.$identification_string;
        if(isset($this->occurrence_ids[$occurrence_id])) return $occurrence_id;
        $o = new \eol_schema\Occurrence_specific();
        $o->occurrenceID = $occurrence_id;
        $o->taxonID = $taxon_id;
        $o->lifeStage = $lifeStage;
        $this->archive_builder->write_object_to_file($o);
        $this->occurrence_ids[$occurrence_id] = '';
        return $occurrence_id;
    }
    private function get_objects($records)
    {   foreach($records as $rec) {
            $rec = array_map('trim', $rec);
            $identifier = (string) $rec["http://purl.org/dc/terms/identifier"];
            $type       = (string) $rec["http://purl.org/dc/terms/type"];

            $rec["taxon_id"] = self::get_worms_taxon_id($rec["http://rs.tdwg.org/dwc/terms/taxonID"]);
            $rec["catnum"] = "";
            
            if(strpos($identifier, "WoRMS:distribution:") !== false) {
                $rec["catnum"] = (string) $rec["http://purl.org/dc/terms/identifier"];
                /* self::process_distribution($rec); removed as per DATA-1522 */ 
                $rec["catnum"] = str_ireplace("WoRMS:distribution:", "_", $rec["catnum"]);
                self::process_establishmentMeans_occurrenceStatus($rec); //DATA-1522
                continue;
            }
            
            // /* start new ticket DATA-1767: https://eol-jira.bibalex.org/browse/DATA-1767?focusedCommentId=62884&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-62884
            $title       = $rec["http://purl.org/dc/terms/title"];
            $description = $rec["http://purl.org/dc/terms/description"];
            if($title == "Fossil species" && $description != "fossil only") continue;
            if($title == "Fossil species" && $description == "fossil only") {
                // print_r($rec); exit;
                
                // /* Per Jen: https://eol-jira.bibalex.org/browse/DATA-1870?focusedCommentId=65468&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-65468
                // In the meantime, as long as we're reharvesting, I think there's something new we should filter out- just one record:
                //     measurementType = http://eol.org/schema/terms/ExtinctionStatus
                //     furtherInformationURL = http://www.marinespecies.org/aphia.php?p=taxdetails&id=1457542
                // Sorry to be handing you something so narrow, but I don't think these errors are very common. 
                // Somehow this coral species maps to all corals and gives us a record for all corals being extinct. Ooops...
                if($rec['taxon_id'] == '1457542') continue;
                // */
                
                $rec["catnum"] = (string) $rec["http://purl.org/dc/terms/identifier"];
                $rec["http://rs.tdwg.org/ac/terms/accessURI"] = $this->taxon_page.$rec['taxon_id']; //this becomes m->source
                self::add_string_types($rec, "true", "http://eol.org/schema/terms/extinct", "http://eol.org/schema/terms/ExtinctionStatus");
                continue;
            }
            //other traits:
            if(stripos($description, "parasit") !== false) self::additional_traits_DATA_1767($rec, 'https://www.wikidata.org/entity/Q12806437', 'http://www.wikidata.org/entity/Q1053008'); //string is found
            if(stripos($description, "detritus feeder") !== false) self::additional_traits_DATA_1767($rec, 'http://wikidata.org/entity/Q2750657', 'http://www.wikidata.org/entity/Q1053008'); //string is found
            if(stripos($description, "benthic") !== false) self::additional_traits_DATA_1767($rec, 'http://purl.obolibrary.org/obo/ENVO_01000024', 'http://purl.obolibrary.org/obo/RO_0002303'); //string is found
            if(stripos($description, "pelagic") !== false) self::additional_traits_DATA_1767($rec, 'http://purl.obolibrary.org/obo/ENVO_01000023', 'http://purl.obolibrary.org/obo/RO_0002303'); //string is found
            if(stripos($description, "sand") !== false) self::additional_traits_DATA_1767($rec, 'http://purl.obolibrary.org/obo/ENVO_00002118', 'http://purl.obolibrary.org/obo/RO_0002303'); //string is found
            if(stripos($description, "intertidal") !== false) self::additional_traits_DATA_1767($rec, 'http://purl.obolibrary.org/obo/ENVO_00000316', 'http://purl.obolibrary.org/obo/RO_0002303'); //string is found
            if(stripos($description, "tropical") !== false) self::additional_traits_DATA_1767($rec, 'http://eol.org/schema/terms/TropicalOcean', 'http://purl.obolibrary.org/obo/RO_0002303'); //string is found
            if(stripos($description, "temperate") !== false) self::additional_traits_DATA_1767($rec, 'http://eol.org/schema/terms/TemperateOcean', 'http://purl.obolibrary.org/obo/RO_0002303'); //string is found
            // */

            if($type == "http://purl.org/dc/dcmitype/StillImage") {
                // WoRMS:image:10299_106331
                $temp = explode("_", $identifier);
                $identifier = $temp[0];
            }

            $mr = new \eol_schema\MediaResource();
            $mr->taxonID        = $rec["taxon_id"];
            $mr->identifier     = $identifier;
            $mr->type           = $type;
            $mr->subtype        = (string) $rec["http://rs.tdwg.org/audubon_core/subtype"];
            $mr->Rating         = (string) $rec["http://ns.adobe.com/xap/1.0/Rating"];
            $mr->audience       = (string) $rec["http://purl.org/dc/terms/audience"];
            if($val = trim((string) $rec["http://purl.org/dc/terms/language"])) $mr->language = $val;
            else                                                                $mr->language = "en";
            $mr->format         = (string) $rec["http://purl.org/dc/terms/format"];
            $mr->title          = RemoveHTMLTagsAPI::remove_html_tags((string) $rec["http://purl.org/dc/terms/title"]);
            $mr->CVterm         = (string) $rec["http://iptc.org/std/Iptc4xmpExt/1.0/xmlns/CVterm"];
            $mr->creator        = (string) $rec["http://purl.org/dc/terms/creator"];
            $mr->CreateDate     = (string) $rec["http://ns.adobe.com/xap/1.0/CreateDate"];
            $mr->modified       = (string) $rec["http://purl.org/dc/terms/modified"];
            $mr->Owner          = (string) $rec["http://ns.adobe.com/xap/1.0/rights/Owner"];
            $mr->rights         = RemoveHTMLTagsAPI::remove_html_tags((string) $rec["http://purl.org/dc/terms/rights"]);
            $mr->UsageTerms     = (string) $rec["http://ns.adobe.com/xap/1.0/rights/UsageTerms"];
            $mr->description    = RemoveHTMLTagsAPI::remove_html_tags((string) $rec["http://purl.org/dc/terms/description"]);

            if($mr->format == "text/html") {
                if(!$mr->description) continue;
            }

            /* removed bibCite Oct 18, 2023
            $mr->bibliographicCitation = (string) $rec["http://purl.org/dc/terms/bibliographicCitation"];
            */
            $mr->derivedFrom     = (string) $rec["http://rs.tdwg.org/ac/terms/derivedFrom"];
            $mr->LocationCreated = RemoveHTMLTagsAPI::remove_html_tags((string) $rec["http://iptc.org/std/Iptc4xmpExt/1.0/xmlns/LocationCreated"]);
            $mr->spatial         = (string) $rec["http://purl.org/dc/terms/spatial"];
            $mr->lat             = (string) $rec["http://www.w3.org/2003/01/geo/wgs84_pos#lat"];
            $mr->long            = (string) $rec["http://www.w3.org/2003/01/geo/wgs84_pos#long"];
            $mr->alt             = (string) $rec["http://www.w3.org/2003/01/geo/wgs84_pos#alt"];
            $mr->publisher      = (string) $rec["http://purl.org/dc/terms/publisher"];
            $mr->contributor    = (string) $rec["http://purl.org/dc/terms/contributor"];
            
            if($agentID = (string) $rec["http://eol.org/schema/agent/agentID"]) {
                $ids = explode(",", $agentID); // not sure yet what separator Worms used, comma or semicolon - or if there are any
                if(count($ids) == 1) $ids = explode("_", $agentID);
                $agent_ids = array();
                foreach($ids as $id) $agent_ids[] = $id;
                $mr->agentID = implode("; ", $agent_ids);
            }

            if($referenceID = self::prepare_reference((string) $rec["http://eol.org/schema/reference/referenceID"])) $mr->referenceID = self::use_correct_separator($referenceID);
            
            if($mr->type != "http://purl.org/dc/dcmitype/Text") {
                $mr->accessURI      = self::complete_url((string) $rec["http://rs.tdwg.org/ac/terms/accessURI"]);
                $mr->thumbnailURL   = (string) $rec["http://eol.org/schema/media/thumbnailURL"];
                // below as of Oct 12, 2023
                if(!$mr->format) $mr->format = Functions::get_mimetype($mr->accessURI);
                if(!$mr->format) {
                    $this->debug['media with no mimetype, excluded'][$mr->accessURI] = '';
                    continue;
                }
            }
            
            if($source = (string) $rec["http://rs.tdwg.org/ac/terms/furtherInformationURL"]) $mr->furtherInformationURL = self::complete_url($source);
            else                                                                             $mr->furtherInformationURL = $this->taxon_page . $mr->taxonID;
            
            if(!isset($this->object_ids[$mr->identifier])) {
                $this->object_ids[$mr->identifier] = '';
                $this->archive_builder->write_object_to_file($mr);
            }
        }
    }
    private function additional_traits_DATA_1767($rec, $mval, $mtype)
    {
        $rec["http://rs.tdwg.org/ac/terms/accessURI"] = $this->taxon_page.$rec['taxon_id']; //this becomes m->source
        $rec["catnum"] = (string) $rec["http://purl.org/dc/terms/identifier"];
        self::add_string_types($rec, "true", $mval, $mtype);
    }
    private function complete_url($path)
    {   // http://www.marinespecies.org/aphia.php?p=sourcedetails&id=154106
        $path = trim($path);
        if(substr($path, 0, 10) == "aphia.php?") return "http://www.marinespecies.org/" . $path;
        elseif(stripos($path, "marineregions.org/gazetteer.php?p=details&id=") !== false) { //string is found
            /* per: https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=67177&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-67177
            http://www.marineregions.org/gazetteer.php?p=details&id=3314
            to the equivalent in this form:
            http://www.marineregions.org/mrgid/3314
            */
            if(preg_match("/id=(.*?)elix/ims", $path."elix", $arr)) {
                $id = $arr[1];
                return "http://www.marineregions.org/mrgid/".$id;
            }
            else exit("\nShould not go here. Code Elix_100.\n");
        }
        else return $path;
    }
    private function get_branch_ids_to_prune()
    {   require_library('connectors/GoogleClientAPI');
        $func = new GoogleClientAPI(); //get_declared_classes(); will give you how to access all available classes
        $params['spreadsheetID'] = '11jQ-6CUJIbZiNwZrHqhR_4rqw10mamdA17iaNELWCBQ';
        $params['range']         = 'Sheet1!A2:A2000'; //where "A" is the starting column, "C" is the ending column, and "1" is the starting row.
        $arr = $func->access_google_sheet($params);
        //start massage array
        foreach($arr as $item) $final[$item[0]] = '';
        $final = array_keys($final);
        return $final;
    }
    private function get_all_ids_to_prune()
    {   $final = array();
        $ids = self::get_branch_ids_to_prune(); //supposedly comes from a google spreadsheet
        foreach($ids as $id) {
            $arr = self::get_children_of_taxon($id);
            if($arr) $final = array_merge($final, $arr);
            $final = array_unique($final);
        }
        $final = array_merge($final, $ids);
        $final = array_unique($final);
        $final = array_filter($final);
        return $final;
    }
    private function format_incertae_sedis($str)
    {   /*
        case 1: [One-word-name] incertae sedis
            Example: Bivalvia incertae sedis
            To: unplaced [One-word-name]
        
        case 2: [One-word-name] incertae sedis [other words]
        Example: Lyssacinosida incertae sedis Tabachnick, 2002
        To: unplaced [One-word-name]

        case 3: [more than 1 word-name] incertae sedis
        :: leave it alone for now
        Examples: Ascorhynchoidea family incertae sedis
        */
        $str = Functions::remove_whitespace($str);
        $str = trim($str);
        if(is_numeric(stripos($str, " incertae sedis"))) {
            $str = str_ireplace("incertae sedis", "incertae sedis", $str); //this will capture Incertae sedis
            $arr = explode(" incertae sedis", $str);
            if($val = @$arr[0]) {
                $space_count = substr_count($val, " ");
                if($space_count == 0) return "unplaced " . trim($val);
                else return $str;
            }
        }
        else return $str;
    }
    /*
    private function process_distribution($rec) // structured data
    {   // not used yet
        // [] => WoRMS:distribution:274241
        // [http://purl.org/dc/terms/type] => http://purl.org/dc/dcmitype/Text
        // [http://rs.tdwg.org/audubon_core/subtype] => 
        // [http://purl.org/dc/terms/format] => text/html
        // [http://purl.org/dc/terms/title] => Distribution
        // [http://eol.org/schema/media/thumbnailURL] => 
        // [http://rs.tdwg.org/ac/terms/furtherInformationURL] => 
        // [http://purl.org/dc/terms/language] => en
        // [http://ns.adobe.com/xap/1.0/Rating] => 
        // [http://purl.org/dc/terms/audience] => 
        // [http://ns.adobe.com/xap/1.0/rights/UsageTerms] => http://creativecommons.org/licenses/by/3.0/
        // [http://purl.org/dc/terms/rights] => This work is licensed under a Creative Commons Attribution-Share Alike 3.0 License
        // [http://eol.org/schema/agent/agentID] => WoRMS:Person:10
        
        // other units:
        $derivedFrom     = "http://rs.tdwg.org/ac/terms/derivedFrom";
        $CreateDate      = "http://ns.adobe.com/xap/1.0/CreateDate"; // 2004-12-21T16:54:05+01:00
        $modified        = "http://purl.org/dc/terms/modified"; // 2004-12-21T16:54:05+01:00
        $LocationCreated = "http://iptc.org/std/Iptc4xmpExt/1.0/xmlns/LocationCreated";
        $spatial         = "http://purl.org/dc/terms/spatial";
        $lat             = "http://www.w3.org/2003/01/geo/wgs84_pos#lat";
        $long            = "http://www.w3.org/2003/01/geo/wgs84_pos#long";
        $alt             = "http://www.w3.org/2003/01/geo/wgs84_pos#alt";
        // for measurementRemarks
        $publisher  = "http://purl.org/dc/terms/publisher";
        $creator    = "http://purl.org/dc/terms/creator"; // db_admin
        $Owner      = "http://ns.adobe.com/xap/1.0/rights/Owner";

        $measurementRemarks = "";
        if($val = $rec["http://purl.org/dc/terms/description"])
        {
                                                        self::add_string_types($rec, "Distribution", $val, "http://rs.tdwg.org/ontology/voc/SPMInfoItems#Distribution");
            if($val = (string) $rec[$derivedFrom])      self::add_string_types($rec, "Derived from", $val, $derivedFrom);
            if($val = (string) $rec[$CreateDate])       self::add_string_types($rec, "Create date", $val, $CreateDate);
            if($val = (string) $rec[$modified])         self::add_string_types($rec, "Modified", $val, $modified);
            if($val = (string) $rec[$LocationCreated])  self::add_string_types($rec, "Location created", $val, $LocationCreated);
            if($val = (string) $rec[$spatial])          self::add_string_types($rec, "Spatial", $val, $spatial);
            if($val = (string) $rec[$lat])              self::add_string_types($rec, "Latitude", $val, $lat);
            if($val = (string) $rec[$long])             self::add_string_types($rec, "Longitude", $val, $long);
            if($val = (string) $rec[$alt])              self::add_string_types($rec, "Altitude", $val, $alt);
            if($val = (string) $rec[$publisher])        self::add_string_types($rec, "Publisher", $val, $publisher);
            if($val = (string) $rec[$creator])          self::add_string_types($rec, "Creator", $val, $creator);
            if($val = (string) $rec[$Owner])            self::add_string_types($rec, "Owner", $val, $Owner);
        }
    }
    */
    private function process_establishmentMeans_occurrenceStatus($rec) // structured data
    {   $location = $rec["http://purl.org/dc/terms/description"];
        if(!$location) return;
        $establishmentMeans = trim((string) @$rec["http://rs.tdwg.org/dwc/terms/establishmentMeans"]);
        $occurrenceStatus = trim((string) @$rec["http://rs.tdwg.org/dwc/terms/occurrenceStatus"]);

        // /* list down all possible values of the 2 new fields
        $this->debug["establishmentMeans"][$establishmentMeans] = '';
        $this->debug["occurrenceStatus"][$occurrenceStatus] = '';
        // */

        /*
        http://eol.org/schema/terms/Present --- lists locations
        If this condition is met:   occurrenceStatus=present, doubtful, or empty
        If occurrenceStatus=doubtful, add a metadata record in MeasurementOrFact:
        field= http://rs.tdwg.org/dwc/terms/measurementAccuracy, value= http://rs.tdwg.org/ontology/voc/OccurrenceStatusTerm#Questionable
        */
        if(in_array($occurrenceStatus, array("present", "doubtful", "")) || $occurrenceStatus == "") {
            $rec["catnum"] .= "_pr";
                                                self::add_string_types($rec, "true", $location, "http://eol.org/schema/terms/Present");
            /* removed Feb 11, 2020 per: https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=64538&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-64538
            if($occurrenceStatus == "doubtful") self::add_string_types($rec, "metadata", "http://rs.tdwg.org/ontology/voc/OccurrenceStatusTerm#Questionable", "http://rs.tdwg.org/dwc/terms/measurementAccuracy");
            */
        }
        
        /*
        http://eol.org/schema/terms/Absent --- lists locations
        If this condition is met:   occurrenceStatus=excluded
        */
        /* New: Jun 7, 2021: And let's remove all records with predicate=http://eol.org/schema/terms/Absent
        https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=66144&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-66144
        if($occurrenceStatus == "excluded") {
            $rec["catnum"] .= "_ex";
            self::add_string_types($rec, "true", $location, "http://eol.org/schema/terms/Absent");
        }
        */
        
        /*
        http://eol.org/schema/terms/NativeRange --- lists locations
        If this condition is met:   establishmentMeans=native or native - Endemic
        If establishmentMeans=native - Endemic, add a metadata record in MeasurementOrFact:
        field= http://rs.tdwg.org/dwc/terms/measurementRemarks, value= http://rs.tdwg.org/ontology/voc/OccurrenceStatusTerm#Endemic
        */
        if(in_array($establishmentMeans, array("Native", "Native - Endemic", "Native - Non-endemic"))) {
            $rec["catnum"] .= "_nr";
            // /* New: Jun 7, 2021
            $location_uri = self::get_uri_from_value($location, 'mValue', 'NativeRange');
            // */

            if(isset($this->eol_terms_uri_value[$location_uri])) {
                self::add_string_types($rec, "true", $location_uri, "http://eol.org/schema/terms/NativeRange");
                if($establishmentMeans == "Native - Endemic")         self::add_string_types($rec, "metadata", "http://rs.tdwg.org/ontology/voc/OccurrenceStatusTerm#Endemic", "http://rs.tdwg.org/dwc/terms/measurementRemarks");
                // elseif($establishmentMeans == "Native - Non-endemic") //no metadata -> https://jira.eol.org/browse/DATA-1522?focusedCommentId=59715&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-59715    
            }
            else {
                if(substr($location_uri,0,4) == 'http') $this->debug['Not found in EOL Terms file']['NativeRange'][$location_uri] = '';
            }
        }
        
        /*
        http://eol.org/schema/terms/IntroducedRange --- lists locations
        If both these conditions are met:
            occurrenceStatus=present, doubtful or empty
            establishmentMeans=Alien
        If occurrenceStatus=doubtful, add a metadata record in MeasurementOrFact:
        field= http://rs.tdwg.org/dwc/terms/measurementAccuracy, value= http://rs.tdwg.org/ontology/voc/OccurrenceStatusTerm#Questionable
        */
        if((in_array($occurrenceStatus, array("present", "doubtful", ""))) && $establishmentMeans == "Alien") {
            $rec["catnum"] .= "_ir";
            // /* New: Jun 7, 2021
            $location_uri = self::get_uri_from_value($location, 'mValue', 'IntroducedRange');
            // */

            if(isset($this->eol_terms_uri_value[$location_uri])) {
                self::add_string_types($rec, "true", $location_uri, "http://eol.org/schema/terms/IntroducedRange");
                /* removed Feb 11, 2020 per: https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=64538&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-64538
                if($occurrenceStatus == "doubtful") self::add_string_types($rec, "metadata", "http://rs.tdwg.org/ontology/voc/OccurrenceStatusTerm#Questionable", "http://rs.tdwg.org/dwc/terms/measurementAccuracy");
                */    
            }
            else {
                if(substr($location_uri,0,4) == 'http') $this->debug['Not found in EOL Terms file']['IntroducedRange'][$location_uri] = '';
            }
        }
    }
    private function add_string_types($rec, $label, $value, $measurementType)
    {   
        // /* new by Eli: Nov 7, 2023 ---> value must be a URI if mType == Present
        if($measurementType == "http://eol.org/schema/terms/Present" && substr($value, 0, 4) != "http") return;
        // */
        
        $m = new \eol_schema\MeasurementOrFact_specific();
        $occurrence_id = $this->add_occurrence($rec["taxon_id"], $rec["catnum"]);
        $m->occurrenceID = $occurrence_id;
        if($label == "Distribution" || $label == "true") { // so that measurementRemarks (and source, contributor, etc.) appears only once in the [measurement_or_fact.tab]
            $m->measurementOfTaxon = 'true';
            $m->measurementRemarks = '';
            $m->source = (string) $rec["http://rs.tdwg.org/ac/terms/accessURI"]; // http://www.marinespecies.org/aphia.php?p=distribution&id=274241
            /* removed bibCite Oct 18, 2023
            $m->bibliographicCitation = (string) $rec["http://purl.org/dc/terms/bibliographicCitation"];
            */
            $m->contributor = (string) $rec["http://purl.org/dc/terms/contributor"];
            if($referenceID = self::prepare_reference((string) $rec["http://eol.org/schema/reference/referenceID"])) {
                $m->referenceID = self::use_correct_separator($referenceID);
            }
            //additional fields per https://eol-jira.bibalex.org/browse/DATA-1767?focusedCommentId=62884&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-62884
            $m->measurementDeterminedDate = $rec['http://ns.adobe.com/xap/1.0/CreateDate'];
            
            // /* New: Jun 7, 2021
            if($val = trim(@$rec['http://purl.org/dc/terms/creator'])) {
                if($uri = @$this->contributor_id_name_info[$val]) {
                    if(isset($this->eol_terms_uri_value[$uri])) $m->measurementDeterminedBy = $uri;
                    else $this->debug['Not found in EOL Terms file']['measurementDeterminedBy'][$uri] = '';
                }
                else {
                    $new_val = $this->format_remove_middle_initial($val);
                    if($uri = @$this->contributor_id_name_info[$new_val]) {
                        if(isset($this->eol_terms_uri_value[$uri])) $m->measurementDeterminedBy = $uri;
                        else $this->debug['Not found in EOL Terms file']['measurementDeterminedBy'][$uri] = '';
                    }
                    else {
                        if($uri = self::last_chance_to_get_contributor_uri($val, $new_val)) {
                            if(isset($this->eol_terms_uri_value[$uri])) $m->measurementDeterminedBy = $uri;
                            else $this->debug['Not found in EOL Terms file']['measurementDeterminedBy'][$uri] = '';
                        }
                        else {
                            $this->debug['neglect uncooperative: DeterminedBy'][$val] = '';          //this one should be reported
                            // $this->debug['neglect uncooperative: DeterminedBy'][$val] = $rec;                            //with metadata - per Jen's request. Just one-time
                            // $this->debug['neglect uncooperative: DeterminedBy'][$val][$measurementType][$value] = $rec;  //with metadata - per Jen's request. Just one-time

                            // $this->debug['neglect uncooperative: DeterminedBy'][$new_val] = '';  //no need to report this

                            // these 2 are not worth reporting:
                            $this->debug['neglect uncooperative: DeterminedBy']['db_admin'] = '';
                            $this->debug['neglect uncooperative: DeterminedBy']['Demo, Account (TE)'] = '';

                            // /* generate a reference for a creator without URI - per Jen https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=67726&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-67726
                            $addtl_reference_id = self::create_reference_for_this_MoF_using_creator($val); //$val is creator
                            if($addtl_reference_id) {
                                if(@$m->referenceID) $m->referenceID .= "|" . $addtl_reference_id;
                                else                 $m->referenceID        = $addtl_reference_id;
                            }
                            // */
                        }
                        /* neglect the most uncooperative strings in any resource for contributor, compiler or determinedBy: per https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=66158&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-66158
                        $m->measurementDeterminedBy = $val;
                        */
                    }
                }
                if($rec['http://purl.org/dc/terms/type'] == "http://purl.org/dc/dcmitype/StillImage") {
                    $m->source = $rec["http://rs.tdwg.org/ac/terms/furtherInformationURL"];
                    /*
                    [http://purl.org/dc/terms/identifier] => WoRMS:image:127205
                    [http://purl.org/dc/terms/type] => http://purl.org/dc/dcmitype/StillImage
                    [http://rs.tdwg.org/ac/terms/furtherInformationURL] => https://www.marinespecies.org/aphia.php?p=image&pic=127205
                    */    
                }
            }
            // */
        }

        /* from Jen: Nov 6, 2023
        For trait records based on photo captions
        if the Creator is an identified WoRMS editor, that can continue to go to measurementDeterminedBy. 
        if the Creator cannot be mapped to an identified WoRMS editor, we'll have to send that string to a more appropriate field. 
            Let's do this: construct a reference, using the text "Observation photo published by [creator]"
        the sourceURL should point to the photo, from their furtherInformationURL, eg: https://www.marinespecies.org/aphia.php?p=image&pic=127205 . 
            I think you may already be doing this, but given that I totally forgot about this part of the connector, I thought I should make sure.
        */

        $m->measurementType = $measurementType;
        $m->measurementValue = (string) $value;
        $m->measurementMethod = '';
        // $m->measurementID = Functions::generate_measurementID($m, $this->resource_id, 'measurement', array('occurrenceID', 'measurementType', 'measurementValue'));

        /* START DATA-1841 terms remapping */
        $m = $this->func->given_m_update_mType_mValue($m, $this->resource_id);
        if(!$m) return;
        // echo "\nLocal: ".count($this->func->remapped_terms)."\n"; //just testing
        /* END DATA-1841 terms remapping */

        $m->measurementID = Functions::generate_measurementID($m, $this->resource_id);
        $this->archive_builder->write_object_to_file($m);
    }
    private function create_reference_for_this_MoF_using_creator($creator_str)
    {
        $r = new \eol_schema\Reference();
        $r->identifier      = "c_".md5($creator_str);
        $r->full_reference  = "Observation photo published by ".$creator_str;
        if(!isset($this->resource_reference_ids[$r->identifier])) {
            $this->resource_reference_ids[$r->identifier] = '';
            $this->archive_builder->write_object_to_file($r);
        }
        return $r->identifier;
    }
    private function last_chance_to_get_contributor_uri($val, $new_val)
    {   // 1st manual adjustment
        $strings[$val] = '';
        $strings[$new_val] = '';
        $strings = array_keys($strings); //make it unique
        foreach($strings as $str) {
            if(strlen($str) >= 10) {
                foreach($this->eol_terms_label_uri as $label => $uri) {
                    // WoRMS: Vonk, Ronald        
                    // EOL Terms: Vonk, Ronald, R.
                    if($str == substr($label,0,strlen($str))) return $uri;
                    /*  WoRMS       : Saraiva De Oliveira, Jessica
                        EOL Terms   : Saraiva de Oliveira, Jessica */
                    if(strtolower($str) == strtolower($label)) return $uri;
                }
            }
        }
        /* From EOL Terms file:
        name: Vanhoorne, Bart, B.
        type: value
        uri: https://www.marinespecies.org/imis.php?module=person&persid=8162
        */
        // Left is from WoRMS; Right is from: EOL Terms file.
        // Vonk, Ronald        name: Vonk, Ronald, R.
        // van Haaren, Ton     name: van Haaren, Ton, T.
        // Vanhoorne, Bart     name: Vanhoorne, Bart, B.
        // Walter, T. Chad     name: Walter, T. Chad, T.C.

        /* 2nd manual adjustment
        from WoRMS          : Verleye, Thomas
        from EOL Terms file : Thomas Verleye
        */
        foreach($strings as $str) {
            if(stripos($str, ",") !== false) {
                $parts = explode(",", $str);
                $parts = array_map('trim', $parts);
                $possible = $parts[1]." ".$parts[0];
                if($uri = @$this->contributor_id_name_info[$possible]) return $uri;
            }
        }
        return false;
    }
    private function use_correct_separator($str)
    {
        return str_replace("_", "|", $str);
    }
    private function prepare_reference($referenceID)
    {   if($referenceID) {
            $ids = explode(",", $referenceID); // not sure yet what separator Worms used, comma or semicolon - or if there are any
            $reference_ids = array();
            foreach($ids as $id) $reference_ids[] = $id;
            return implode("; ", $reference_ids);
        }
        return false;
    }
    private function add_occurrence($taxon_id, $catnum)
    {   $occurrence_id = $taxon_id . 'O' . $catnum; // suggested by Katja to use -- ['O' . $catnum]
        // $occurrence_id = md5($taxon_id . 'occurrence'); from environments

        $o = new \eol_schema\Occurrence_specific();
        $o->occurrenceID = $occurrence_id;
        $o->taxonID = $taxon_id;
        
        $o->occurrenceID = Functions::generate_measurementID($o, $this->resource_id, 'occurrence');

        if(isset($this->occurrence_ids[$o->occurrenceID])) return $o->occurrenceID;
        $this->archive_builder->write_object_to_file($o);

        $this->occurrence_ids[$o->occurrenceID] = '';
        return $o->occurrenceID;

        /* old ways
        $this->occurrence_ids[$occurrence_id] = '';
        return $occurrence_id;
        */
    }
    private function get_vernaculars($records)
    {   self::process_fields($records, "vernacular");
        // foreach($records as $rec) {
        //     $v = new \eol_schema\VernacularName();
        //     $v->taxonID         = $rec["http://rs.tdwg.org/dwc/terms/taxonID"];
        //     $v->taxonID         = str_ireplace("urn:lsid:marinespecies.org:taxname:", "", $v->taxonID);
        //     $v->vernacularName  = $rec["http://rs.tdwg.org/dwc/terms/vernacularName"];
        //     $v->source          = $rec["http://purl.org/dc/terms/source"];
        //     $v->language        = $rec["http://purl.org/dc/terms/language"];
        //     $v->isPreferredName = $rec["http://rs.gbif.org/terms/1.0/isPreferredName"];
        //     $this->archive_builder->write_object_to_file($v);
        // }
    }
    private function get_agents($records)
    {   self::process_fields($records, "agent");
        // foreach($records as $rec) {
        //     $r = new \eol_schema\Agent();
        //     $r->identifier      = (string) $rec["http://purl.org/dc/terms/identifier"];
        //     $r->term_name       = (string) $rec["http://xmlns.com/foaf/spec/#term_name"];
        //     $r->term_firstName  = (string) $rec["http://xmlns.com/foaf/spec/#term_firstName"];
        //     $r->term_familyName = (string) $rec["http://xmlns.com/foaf/spec/#term_familyName"];
        //     $r->agentRole       = (string) $rec["http://eol.org/schema/agent/agentRole"];
        //     $r->term_mbox       = (string) $rec["http://xmlns.com/foaf/spec/#term_mbox"];
        //     $r->term_homepage   = (string) $rec["http://xmlns.com/foaf/spec/#term_homepage"];
        //     $r->term_logo       = (string) $rec["http://xmlns.com/foaf/spec/#term_logo"];
        //     $r->term_currentProject = (string) $rec["http://xmlns.com/foaf/spec/#term_currentProject"];
        //     $r->organization        = (string) $rec["http://eol.org/schema/agent/organization"];
        //     $r->term_accountName    = (string) $rec["http://xmlns.com/foaf/spec/#term_accountName"];
        //     $r->term_openid         = (string) $rec["http://xmlns.com/foaf/spec/#term_openid"];
        //     $this->archive_builder->write_object_to_file($r);
        // }
    }
    private function get_references($records)
    {   self::process_fields($records, "reference");
        // foreach($records as $rec) {
        //     $r = new \eol_schema\Reference();
        //     $r->identifier      = (string) $rec["http://purl.org/dc/terms/identifier"];
        //     $r->publicationType = (string) $rec["http://eol.org/schema/reference/publicationType"];
        //     $r->full_reference  = (string) $rec["http://eol.org/schema/reference/full_reference"];
        //     $r->primaryTitle    = (string) $rec["http://eol.org/schema/reference/primaryTitle"];
        //     $r->title           = (string) $rec["http://purl.org/dc/terms/title"];
        //     $r->pages           = (string) $rec["http://purl.org/ontology/bibo/pages"];
        //     $r->pageStart       = (string) $rec["http://purl.org/ontology/bibo/pageStart"];
        //     $r->pageEnd         = (string) $rec["http://purl.org/ontology/bibo/pageEnd"];
        //     $r->volume          = (string) $rec["http://purl.org/ontology/bibo/volume"];
        //     $r->edition         = (string) $rec["http://purl.org/ontology/bibo/edition"];
        //     $r->publisher       = (string) $rec["http://purl.org/dc/terms/publisher"];
        //     $r->authorList      = (string) $rec["http://purl.org/ontology/bibo/authorList"];
        //     $r->editorList      = (string) $rec["http://purl.org/ontology/bibo/editorList"];
        //     $r->created         = (string) $rec["http://purl.org/dc/terms/created"];
        //     $r->language        = (string) $rec["http://purl.org/dc/terms/language"];
        //     $r->uri             = (string) $rec["http://purl.org/ontology/bibo/uri"];
        //     $r->doi             = (string) $rec["http://purl.org/ontology/bibo/doi"];
        //     $r->localityName    = (string) $rec["http://schemas.talis.com/2005/address/schema#localityName"];
        //     if(!isset($this->resource_reference_ids[$r->identifier])) {
        //        $this->resource_reference_ids[$r->identifier] = 1;
        //        $this->archive_builder->write_object_to_file($r);
        //     }
        // }
    }
    // =================================================================================== WORKING OK! BUT MAY HAVE BEEN JUST ONE-TIME IMPORT
    // START dynamic hierarchy ===========================================================
    // ===================================================================================
    // /*
    private function add_taxa_from_undeclared_parent_ids() //text file here is generated by utility check_if_all_parents_have_entries() in 26.php
    {   $file = CONTENT_RESOURCE_LOCAL_PATH . "26_files/" . $this->resource_id . "_undefined_parent_ids_archive.txt";
        if(file_exists($file)) {
            $i = 0;
            foreach(new FileIterator($file) as $line_number => $id) {
                $i++;
                $taxa = self::AphiaClassificationByAphiaID($id);
                self::create_taxa($taxa);
            }
        }
        // else exit("\n[$file] does not exist.\n");
    }
    private function AphiaClassificationByAphiaID($id)
    {   $taxa = self::get_ancestry_by_id($id);
        $taxa = self::add_authorship($taxa);
        // $taxa = self::add_parent_id($taxa); //obsolete
        $taxa = self::add_parent_id_v2($taxa);
        return $taxa;
    }
    private function get_ancestry_by_id($id)
    {   $taxa = array();
        if(!$id) return array();
        if($json = Functions::lookup_with_cache($this->webservice['AphiaClassificationByAphiaID'].$id, $this->download_options)) {
            $arr = json_decode($json, true);
            // print_r($arr);
            if(@$arr['scientificname'] && strlen(@$arr['scientificname']) > 1) $taxa[] = array('AphiaID' => @$arr['AphiaID'], 'rank' => @$arr['rank'], 'scientificname' => @$arr['scientificname']);
            while(true) {
                if(!$arr) break;
                foreach($arr as $i) {
                    if(@$i['scientificname'] && strlen(@$i['scientificname'])>1) {
                        $taxa[] = array('AphiaID' => @$i['AphiaID'], 'rank' => @$i['rank'], 'scientificname' => @$i['scientificname']);
                    }
                    $arr = $i;
                }
            }
        }
        return $taxa;
    }
    private function add_authorship($taxa) //and other metadata
    {   $i = 0;
        foreach($taxa as $taxon) {
            // [AphiaID] => 7
            // [rank] => Kingdom
            // [scientificname] => Chromista
            // [parent_id] => 1
            if($json = Functions::lookup_with_cache($this->webservice['AphiaRecordByAphiaID'].$taxon['AphiaID'], $this->download_options)) {
                $arr = json_decode($json, true);
                // print_r($arr);
                // [valid_AphiaID] => 1
                // [valid_name] => Biota
                // [valid_authority] => 
                $taxa[$i]['authority'] = $arr['authority'];
                $taxa[$i]['valid_name'] = trim($arr['valid_name'] . " " . $arr['valid_authority']);
                $taxa[$i]['valid_AphiaID'] = $arr['valid_AphiaID'];
                $taxa[$i]['status'] = $arr['status'];
                $taxa[$i]['citation'] = $arr['citation'];
            }
            $i++;
        }
        return $taxa;
    }
    private function create_taxa($taxa) //for dynamic hierarchy only
    {   
        foreach($taxa as $t) {
            // [AphiaID] => 24
            // [rank] => Class
            // [scientificname] => Zoomastigophora
            // [authority] => 
            // [valid_name] => 
            // [valid_AphiaID] => 
            // [status] => unaccepted
            // [parent_id] => 13
            if($t['status'] != "accepted") continue; //only add those that are 'accepted'
            $taxon = new \eol_schema\Taxon();
            $taxon->taxonID         = $t['AphiaID'];
            
            if(in_array($taxon->taxonID, $this->children_of_synonyms)) continue; //exclude children of synonyms
            
            $taxon->scientificName  = trim($t['scientificname'] . " " . $t['authority']);
            $taxon->scientificName = self::format_incertae_sedis($taxon->scientificName);
            if(!$taxon->scientificName) continue;
            
            $taxon->taxonRank       = $t['rank'];
            $taxon->taxonomicStatus = $t['status'];
            $taxon->source          = $this->taxon_page . $t['AphiaID'];
            if($t['scientificname'] != "Biota") $taxon->parentNameUsageID = $t['parent_id'];
            $taxon->acceptedNameUsageID     = $t['valid_AphiaID'];
            $taxon->bibliographicCitation   = $t['citation'];
            
            if($taxon->taxonID == @$taxon->acceptedNameUsageID) $taxon->acceptedNameUsageID = '';
            if($taxon->taxonID == @$taxon->parentNameUsageID)   $taxon->parentNameUsageID = '';
            
            if(!isset($this->taxon_ids[$taxon->taxonID])) {
                $this->taxon_ids[$taxon->taxonID] = '';
                $this->archive_builder->write_object_to_file($taxon);
            }
        }
    }
    // private function add_parent_id($taxa) //works OK, but chooses parent whatever is in the line, even if it is 'unaccepted'.
    // {
    //     $i = 0;
    //     foreach($taxa as $taxon) {
    //         if($i != 0) {
    //             for ($x = 1; $x <= count($taxa); $x++) {
    //                 if($val = @$taxa[$i-$x]['AphiaID']) {
    //                     $taxa[$i]['parent_id'] = $val;
    //                     break;
    //                 }
    //             }
    //         }
    //         $i++;
    //     }
    //     return $taxa;
    // }
    private function add_parent_id_v2($taxa)
    {   // Array (
        //     [AphiaID] => 25
        //     [rank] => Order
        //     [scientificname] => Choanoflagellida
        //     [authority] => Kent, 1880
        //     [valid_name] => Choanoflagellida Kent, 1880
        //     [valid_AphiaID] => 25
        //     [status] => accepted
        //     [citation] => WoRMS (2013). Choanoflagellida. In: Guiry, M.D. & Guiry, G.M. (2016). AlgaeBase. World-wide electronic publication,...
        // )
        $i = 0;
        foreach($taxa as $taxon) {
            if($taxon['scientificname'] != "Biota") {
                $parent_id = self::get_parent_of_index($i, $taxa);
                $taxa[$i]['parent_id'] = $parent_id;
            }
            $i++;
        }
        return $taxa;
    }
    private function get_parent_of_index($index, $taxa)
    {   $parent_id = "";
        for($k = 0; $k <= $index-1 ; $k++) {
            if($taxa[$k]['status'] == "accepted") {
                if(!in_array($taxa[$k]['AphiaID'], $this->children_of_synonyms)) $parent_id = $taxa[$k]['AphiaID']; //new
            }
        }
        return $parent_id;
    }
    public function trim_text_files() //a utility to make the text files ID [in folder /26_files/] entries unique. Advised to run this utility once the 6 connectors finished during build-up
    {   $files = array("26_taxonomy_synonyms_without_children.txt", "26_taxonomy_children_of_synonyms.txt");
        foreach($files as $file) {
            $filename = CONTENT_RESOURCE_LOCAL_PATH . "26_files/" . $file;
            echo "\nProcessing ($filename)...\n";
            if(file_exists($filename)) {
                $txt = file_get_contents($filename);
                $AphiaIDs = explode("\n", $txt);
                echo "\nOrig count: ".count($AphiaIDs)."\n";
                $AphiaIDs = array_filter($AphiaIDs);
                $AphiaIDs = array_unique($AphiaIDs);
                echo "\nUnique ID count: ".count($AphiaIDs)."\n";
                //write to file - overwrite, now with unique IDs
                $fn = Functions::file_open($filename, "w");
                fwrite($fn, implode("\n", $AphiaIDs));
                fclose($fn);
            }
        }
    }
    public function investigate_missing_parents_in_MoF()
    {   $filename = CONTENT_RESOURCE_LOCAL_PATH . "/26_undefined_parentMeasurementIDs_OK.txt";
        echo "\nProcessing ($filename)...\n";
        if(file_exists($filename)) {
            $txt = file_get_contents($filename);
            $AphiaIDs = explode("\n", $txt);
            $AphiaIDs = array_filter($AphiaIDs); //remove null arrays
            $AphiaIDs = array_unique($AphiaIDs); //make unique
            $AphiaIDs = array_values($AphiaIDs); //reindex key
            print_r($AphiaIDs);
        }
        else echo "\nFile not found\n";

        $i = 0;
        foreach(new FileIterator(CONTENT_RESOURCE_LOCAL_PATH . "26_ok/measurementorfact.txt") as $line_number => $line) {
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
                    [AphiaID] => 1054700
                    [measurementID] => 286376_1054700
                    [parentMeasurementID] => 
                    [measurementType] => Functional group
                    [measurementValueID] => 
                    [measurementValue] => benthos
                    [measurementUnit] => 
                    [measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:101
                )*/
                if(in_array($rec['measurementID'], $AphiaIDs)) {
                    $final[$rec['measurementType']][$rec['measurementValue']] = '';
                }
            }
        }
        print_r($final);
    }
    // */
    // ===================================================================================
    // END dynamic hierarchy ===========================================================
    // ===================================================================================
    private function get_undeclared_parent_ids()
    {   $ids = array();
        $url = CONTENT_RESOURCE_LOCAL_PATH . "26_files/" . $this->resource_id . "_undefined_parent_ids_archive.txt";
        if(file_exists($url)) {
            foreach(new FileIterator($url) as $line_number => $id) $ids[$id] = '';
        }
        return array_keys($ids);
    }
}
?>