<?php
namespace php_active_record;
/* connector: [26] WORMS archive connector
We received a Darwincore archive file from the partner.
Connector downloads the archive file, extracts, reads it, assembles the data and generates the EOL DWC-A resource.

http://www.marinespecies.org/rest/#/
http://www.marinespecies.org/aphia.php?p=taxdetails&id=9
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class WormsArchiveAPI2026 extends ContributorsMapAPI
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
        else                            $this->dwca_file = "http://host.docker.internal:81/cp/WORMS/WoRMS2EoL.zip";         //local - when developing only
        //                              $this->dwca_file = LOCAL_HOST."/cp/WORMS/Archive.zip";                              //local subset copy
        
        $this->occurrence_ids = array();
        $this->taxon_page = "http://www.marinespecies.org/aphia.php?p=taxdetails&id=";
        
        $this->webservice['AphiaClassificationByAphiaID'] = "http://www.marinespecies.org/rest/AphiaClassificationByAphiaID/";
        $this->webservice['AphiaRecordByAphiaID']         = "http://www.marinespecies.org/rest/AphiaRecordByAphiaID/";
        $this->webservice['AphiaChildrenByAphiaID']       = "http://www.marinespecies.org/rest/AphiaChildrenByAphiaID/";
        
        $this->download_options = array('cache' => 1, 'download_wait_time' => 1000000, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1, 'resource_id' => 26);
        $this->download_options["expire_seconds"] = false; //debug - false means it will use cache
        $this->debug = array();
        
        /* start DATA-1827 below */
        // $this->match2mapping_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/master/WoRMS/worms_mapping1.csv';      //old
        // $this->value_uri_mapping_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/master/WoRMS/metastats-csv.tsv';       //old

        $this->match2mapping_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/master/WoRMS/worms_mapping_ver2.csv';  //latest Mar 2026
        $this->value_uri_mapping_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/master/WoRMS/Feb2020/metastats-2.tsv'; //latest Feb 2020
        //mapping from here: https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=63730&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-63730

        // Exclusive mapping for WoRMS only
        $this->native_intro_mapping = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/master/WoRMS/WoRMS_native_intro_mapping.txt';

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
        
        // Before 2026:
        $this->children_mTypes = array("Body size > Gender" ,"Body size > Stage", "Body size > Type" ,"Feedingtype > Stage", "Functional group > Stage" ,"Body size > Locality (MRGID)");
        // /* as of Feb 2026, these are the new child mTypes found in WoRMS2EoL.zip: n = 60
        $this->children_mTypes = array("Supporting structure & enclosure > Structure", "Supporting structure & enclosure > Structure > Composition", "Functional group > Life stage", 
        "Mobility > Life stage", "Body size > Type", "Body size > Dimension", "Body size (qualitative) > Life stage", 
        "Species importance to society > IUCN Red List Category", "Species importance to society > IUCN Red List Category > Year Assessed", 
        "Species importance to society > Identifier", "Body size > Sex", "Ecological interactions > Life stage", "Ecological interactions > Host", 
        "Feeding method > Life stage", "Body size > Life stage", "Body size > Locality (MRGID)", 
        "Species importance to society > Mediterranean proposed indicators - Mediterranean Sea", "Feeding method > Food source", 
        "Species importance to society > HELCOM Red List Category", "Species importance to society > HELCOM core biodiversity indicators", 
        "Environmental position > Life stage", "Species importance to society > CITES Annex", "Zonation > Life stage", "Body size (qualitative) > Sex", 
        "Species importance to society > IUCN Red List Category > Criteria", "Species importance to society > OSPAR candidate indicators: Celtic Seas", "Species importance to society > OSPAR candidate indicators: Greater North Sea including outside EU", "Body size > Corresponding width", "Body size > Corresponding length", 
        "Asexual reproduction > Locality (MRGID)", "Species importance to society > Habitats Directive Annex", "Species importance to society > OSPAR Region where species is under threat and/or in decline", "Life span > Life stage", "Asexual reproduction > Life stage", "Calcification > Life stage", "Cytomorphology > Life stage", "Body shape > Life stage", "Life cycle > Life stage", "Spawning > Life stage", "Tolerance to pollutants > Life stage", "Dispersion mode > Life stage", "Gamete type > Life stage", "Thallus vertical space used > Life stage", "Gametophyte arrangement > Life stage", "Trophic level > Life stage", "Trophic level > Food source", "Species importance to society > OSPAR common indicators: Celtic Seas", "Species importance to society > OSPAR common indicators: Bay of Biscay and Iberian Coast", "Species importance to society > OSPAR candidate indicators: North Sea", "Species importance to society > OSPAR common indicators: Greater North Sea", "Species importance to society > Birds Directive Annex", "Species importance to society > Mediterranean proposed indicators - Adriatic Sea", "Species importance to society > Black Sea proposed indicators", "Species importance to society > OSPAR candidate indicators: Bay of Biscay and the Iberian Coast", "Species importance to society > Mediterranean proposed indicators - Aegean-Levantine Sea", "Species importance to society > Mediterranean proposed indicators - Ionian Sea", "Species importance to society > Mediterranean proposed indicators - Western Mediterranean", "Generation time > Life stage", "Reproductive frequency > Life stage", "Species importance to society > OSPAR common indicators: Greater North Sea including outside EU");
        // */

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
        $this->real_parents = array("AMBI ecological group", "Asexual reproduction", "Body shape", "Body size", "Body size (qualitative)", "Brooding", "Calcification", "Cytomorphology", "Development", "Dispersion mode", 
        "Ecological interactions", 
        "Environmental position", "Etymology classification", 
        "Feeding method", "Fossil range", 
        "Functional group", "Gamete type", "Gametophyte arrangement", "Generation time", "Life cycle", "Life span", "Mobility", "Modes of reproduction", "Nomenclature code", "Paraphyletic group", "Plant habit", "Reproductive frequency", "Sociability", "Spawning", "Species exhibits underwater soniferous behaviour", "Species importance to society", "Supporting structure & enclosure", "Thallus vertical space used", "Tolerance to pollutants", "Trophic level", "Zonation");

        // formerly 'Feedingtype'
        $this->exclude_mType_mValue['Ecological interactions']['commensal'] = '';
        $this->exclude_mType_mValue['Ecological interactions']['endocommensal'] = '';
        $this->exclude_mType_mValue['Ecological interactions']['symbiotic'] = '';
        $this->exclude_mType_mValue['Ecological interactions']['unknown'] = '';
        $this->exclude_mType_mValue['Feeding method']['not feeding'] = '';
        $this->exclude_mType_mValue['Feeding method']['selective'] = '';
        $this->exclude_mType_mValue['Feeding method']['non-selective'] = '';
        $this->exclude_mType_mValue['Environmental position']['epizoic'] = '';
        // formerly 'Functional group' {no change}
        $this->exclude_mType_mValue['Functional group']['macro'] = '';
        $this->exclude_mType_mValue['Functional group']['meso'] = '';
        $this->exclude_mType_mValue['Functional group']['not applicable'] = '';

        $this->schema_uri['locality']   = 'http://rs.tdwg.org/dwc/terms/locality';
        $this->schema_uri['sex']        = 'http://rs.tdwg.org/dwc/terms/sex';
        $this->schema_uri['lifeStage']  = 'http://rs.tdwg.org/dwc/terms/lifeStage';
    }
    private function init_contributor_info()
    {
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

        /* New March 1, 2026 -> this is just for stats
        require_library('connectors/EOLterms_ymlAPI');
        $func = new EOLterms_ymlAPI(false, false);
        $eol_terms = $func->convert_EOL_Terms_2array();
        echo "\nTerms count from EOL Terms file: [".count($eol_terms['terms'])."]\n";
        foreach($eol_terms['terms'] as $rec) { 
            $this->eol_terms_value_uri[trim($rec['name'])][] = trim($rec['uri']);
        }
        */
    }
    private function init_trait_generic()
    {
        require_library('connectors/TraitGeneric');
        $this->func = new TraitGeneric($this->resource_id, $this->archive_builder);
        $this->func->initialize_terms_remapping(); //DATA-1841 terms remapping
        echo "\nFrom local: ".count($this->func->remapped_terms)."\n";
    }
    private function init_text_mappings()
    {
        $this->match2map = self::csv2array($this->match2mapping_file, 'match2map'); //mapping csv to array
        $this->value_uri_map = self::tsv2array($this->value_uri_mapping_file);
    }
    private function initialize()
    {
        /*
        $temp = CONTENT_RESOURCE_LOCAL_PATH . "26_files";
        if(!file_exists($temp)) mkdir($temp);
        */
        self::init_contributor_info();
        self::init_trait_generic();
        self::init_text_mappings();
    }
    function start()
    {   
        self::initialize();
        /* un-comment in real operation
        require_library('connectors/INBioAPI');
        $func = new INBioAPI();
        $paths = $func->extract_archive_file($this->dwca_file, "meta.xml", array('timeout' => 172800, 'expire_seconds' => true)); //true means it will re-download, will not use cache. Set TRUE when developing
        // print_r($paths); exit;
        */
        // /* dev only
        $paths['archive_path'] = '/Volumes/T5_Black_SSD/eol_php_code_tmp/dir_59440/';
        $paths['temp_dir'] = '/Volumes/T5_Black_SSD/eol_php_code_tmp/dir_59440/';
        // */
        $archive_path = $paths['archive_path'];
        $temp_dir = $paths['temp_dir'];
        $harvester = new ContentArchiveReader(NULL, $archive_path);
        $tables = $harvester->tables;
        print_r(array_keys($tables));
        
        $meta_taxon = @$tables['http://rs.tdwg.org/dwc/terms/taxon'][0];
        echo "\n1 of 8"; self::process_extension($meta_taxon, 'write_taxon'); unset($meta_taxon); //PofMO

        /* PofMO -- this block handles measurementorfact.txt
        if($meta_MoF = @$tables['http://rs.tdwg.org/dwc/terms/measurementorfact'][0]) {}
        else exit("\nERROR: No MoF extension. Please investigate.\n");
        echo "\n2 of 8"; self::process_extension($meta_MoF, 'before_MoF');
        echo "\n3 of 8"; self::process_extension($meta_MoF, 'prepare_MoF');
        echo "\n4 of 8"; self::process_extension($meta_MoF, 'write_MoF');
        unset($meta_MoF);
        */
        unset($this->childOf); unset($this->parentOf); unset($this->ToExcludeMeasurementIDs);
        unset($this->BodysizeDimension); unset($this->FeedingType); unset($this->lifeStageOf); unset($this->measurementIDz);

        // /* PofMO
        $records = $harvester->process_row_type('http://eol.org/schema/media/Document');        echo "\n5 of 8";  self::get_objects($records);
        // $records = $harvester->process_row_type('http://rs.gbif.org/terms/1.0/Reference');      echo "\n6 of 8"; self::process_fields($records, "reference");
        // $records = $harvester->process_row_type('http://eol.org/schema/agent/Agent');           echo "\n7 of 8"; self::process_fields($records, "agent");
        // $records = $harvester->process_row_type('http://rs.gbif.org/terms/1.0/VernacularName'); echo "\n8 of 8"; self::process_fields($records, "vernacular");
        // */
            
        $this->archive_builder->finalize(TRUE);

        // remove temp dir
        /*
        recursive_rmdir($temp_dir);
        echo ("\n temporary directory removed: " . $temp_dir);
        */
        if($this->debug) Functions::start_print_debug($this->debug, $this->resource_id);
    }
    private function process_extension($meta, $what)
    {   //print_r($meta);
        echo "\nprocess_extension [$what]...\n"; $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) {
            $i++; if(($i % 500000) == 0) echo "\n".number_format($i). " $what";
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                $field['term'] = self::small_field($field['term']);
                // /* some fields have '#', e.g. "http://schemas.talis.com/2005/address/schema#localityName"
                $parts = explode("#", $field['term']);
                if($parts[0]) $field = $parts[0];
                if(@$parts[1]) $field = $parts[1];
                // */
                if(!$field) continue;
                $rec[$field] = @$tmp[$k];
                /* WoRMS DwCA .txt files (extensions) sometimes have inconsistent number of tabs. Thus needed to add @ in $rec[$field] = @$tmp[$k];
                if(count($tmp) < $k) {
                    print_r($tmp); print_r($meta->fields); echo "\nk = [$k]\n";
                    exit("\ninvestigate\n");
                }
                */
                $k++;
            } 
            // $rec = Functions::array_map_eol('trim', $rec); //caused errors
            // print_r($rec); exit;
            //===========================================================================================================================================================
            if($what == 'write_taxon') {
                /*Array(
                    [taxonID] => urn:lsid:marinespecies.org:taxname:1
                    [scientificName] => Biota
                    [parentNameUsageID] => 
                    [kingdom] =>    [phylum] =>     [class] =>      [order] =>  [family] =>     [genus] => 
                    [taxonRank] => kingdom
                    [furtherInformationURL] => https://www.marinespecies.org/aphia.php?p=taxdetails&id=1
                    [taxonomicStatus] => https://rs.gbif.org/vocabulary/gbif/taxonomicStatus/accepted
                    [taxonRemarks] => 
                    [namePublishedIn] => 
                    [referenceID] => WoRMS:citation:1
                    [acceptedNameUsageID] => urn:lsid:marinespecies.org:taxname:1
                    [rights] => 
                    [rightsHolder] => 
                    [datasetName] => 
                )*/
                $rec = self::format_worms_fields($rec);
                if($rec['taxonomicStatus'] != 'accepted') continue;
                $this->taxon_ids[$rec['taxonID']] = '';
                unset($rec['rights']); //no 'rights' in EoL taxa extension schema
                // /* for later lookup
                $taxon_id = $rec['taxonID'];
                $this->taxa_rank[$taxon_id]['r'] = (string) $rec["taxonRank"];
                $this->taxa_rank[$taxon_id]['s'] = (string) $rec["taxonomicStatus"];
                $this->taxa_rank[$taxon_id]['n'] = (string) $rec["scientificName"];
                // */
                self::proceed_2write($rec, 'taxon');
            }
            if($what == 'before_MoF') { if($rec['MeasurementOrFact']) self::before_MoF($rec); }
            elseif($what == 'prepare_MoF') { if($rec['MeasurementOrFact']) self::prepare_MoF($rec); }
            elseif($what == 'write_MoF') { if($rec['MeasurementOrFact']) self::write_MoF($rec); }

            elseif($what == 'append_media_objects') { //carry-over if Media extension exists
                self::proceed_2write($rec, 'document');
            }
            // =======================================================================================================
            // =======================================================================================================
            // =======================================================================================================
            // =======================================================================================================
            // =======================================================================================================
            // if($i >= 1000) break; //debug only //2026
        } //end foreach()
    }
    private function before_MoF($rec)
    {
        $mID = $rec['measurementID'];
        $parentMID = $rec['parentMeasurementID'];
        $this->parentOf[$mID] = $parentMID;
        if($parentMID) $this->childOf[$parentMID] = $mID;
    }
    private function prepare_MoF($rec)
    {   
        self::get_mIDs_2exclude($rec);
        /*Array(
            [MeasurementOrFact] => 769244       --> this is the AphiaID in the measurementorfact.txt. Which is the taxonID
            [measurementID] => 17679_769244
            [parentMeasurementID] => 
            [measurementType] => Functional group
            [measurementValueID] => 
            [measurementValue] => benthos
            [measurementUnit] => 
            [measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:155944
        )
        Array(
            [MeasurementOrFact] => 769244
            [measurementID] => 17680_769244
            [parentMeasurementID] => 17679_769244
            [measurementType] => Functional group > Life stage
            [measurementValueID] => 
            [measurementValue] => adult
            [measurementUnit] => 
            [measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:155944
        )*/
        if(!$rec['measurementType']) { print_r($rec); exit("\nERROR: Should not go here anymore.\n"); }
        $mID = $rec['measurementID'];
        $mType = $rec['measurementType'];
        $mValue = $rec['measurementValue'];
        $mValueID = $rec['measurementValueID'];
        $parentMID = $rec['parentMeasurementID'];
        // if(stripos($mType, "Functional group") !== false) print_r($rec) //found string //debug only

        $parts = array(' > Life stage', ' > Sex', ' > Locality (MRGID)');
        foreach($parts as $part) {
            if(stripos($mType, $part) !== false) { //exit("\nhere 1\n");
                $arr = explode(">", $mType); $arr = array_map('trim', $arr);
                $parent_mType = $arr[0]; //e.g. 'Functional group'
                $child_mType = $arr[1]; //e.g. 'Life stage'
                $super_parent = self::get_super_parent($parentMID);
                // /*
                if($part == ' > Locality (MRGID)') {
                    // $mValue = Functions::valid_uri_url($mValueID) ? $mValueID : $mValue; //WoRMS provides URI for their locality.
                    $mValue = self::get_uri_from_value($mValue, 'mValue', 'locality');
                }
                // */
                $this->child_of_parent[$super_parent][strtolower($child_mType)] = $mValue;
            }
        }
        // --------------------------------------------
        //this is to store URI map. this->childOf and this->BodysizeDimension will work hand in hand later on.
        if($mType == 'Body size > Dimension') {
            $super_parent = self::get_super_parent($mID);
            $this->BodysizeDimension[$super_parent] = $this->BsD_URI[strtolower($mValue)];
        }
        // --------------------------------------------
        // --------------------------------------------
    }
    private function write_MoF($rec)
    {
        $mID = $rec['measurementID'];
        if(isset($this->ToExcludeMeasurementIDs[$mID])) return;

        $parentMID = trim($rec['parentMeasurementID']);
        if(!$parentMID) { //no parent ID means a parent MoF; not a child
            self::proceed_save_mof($rec);
        }
    }
    private function proceed_save_mof($rec)
    {  
        $taxon_id = $rec['MeasurementOrFact'];
        $mID = $rec['measurementID'];
        $mType = $rec['measurementType'];
        $mValue = $rec['measurementValue'];

        $save = array();
        $save['measurementID'] = $mID;
        $save['taxon_id'] = $taxon_id;
        $save["catnum"] = $taxon_id.'_'.$mType.$mValue; //making it unique. no standard way of doing it.
        $save['measurementRemarks'] = '';
        $save['source'] = $this->taxon_page.$taxon_id;
        $save = self::adjustments_4_measurementAccuracy($save, $rec);
        $save['measurementUnit'] = self::format_measurementUnit($rec); //no instruction here

        if($val = @$this->child_of_parent[$mID]['life stage'])  $save['occur']['lifeStage'] = self::get_uri_from_value($val, 'mValue', 'lifeStage');
        if($val = @$this->child_of_parent[$mID]['sex'])         $save['occur']['sex'] = self::get_uri_from_value($val, 'mValue', 'sex');

        $cont_save_child_MoF_YN = false;

        // --------------------------------------------------
        if($mType == 'Body size') {                       //e.g. 528452_768436 measurementID

            // $super_child = self::get_super_child($mID);   //e.g. 528458_768436
            $mTypev = @$this->BodysizeDimension[$mID];
            if(!$mTypev) $mTypev = 'http://purl.obolibrary.org/obo/OBA_VT0100005'; //feedback from Jen: https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=63749&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-63749
        }
        // --------------------------------------------------

        if($info = @$this->match2map[$mType][$mValue]) { //$this->match2map came from a CSV mapping file
            /*Array( $info
                [mTypeURL] => http://rs.tdwg.org/dwc/terms/habitat
                [mValueURL] => http://purl.obolibrary.org/obo/ENVO_01000024
                [mRemarks] => 
            )
            Array( $rec
                [MeasurementOrFact] => 1054700
                [measurementID] => 286376_1054700
                [parentMeasurementID] => 
                [measurementType] => Functional group
                [measurementValueID] => 
                [measurementValue] => benthos
                [measurementUnit] => 
                [measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:101
            )*/
            $this->func->pre_add_string_types($save, $info['mValueURL'], $info['mTypeURL'], "true");
            $cont_save_child_MoF_YN = true;
        }
        else { //the rest goes here
            /*Array(
                [MeasurementOrFact] => 159931
                [measurementID] => 528455_159931
                [parentMeasurementID] => 
                [measurementType] => Body size
                [measurementValueID] => 
                [measurementValue] => 1
                [measurementUnit] => mm
                [measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:155944
            )*/
            if(is_numeric($mValue)) { //print_r($rec); //exit("\nnumeric value ito\n");
                if($mTypeURL = self::get_uri_from_value($mType, 'mType', 'numeric value measurement')) {
                    $this->func->pre_add_string_types($save, $mValue, $mTypeURL, "true");
                    $cont_save_child_MoF_YN = true;

                }
            }
            else { //non-numeric measurement value
                if($mTypeURL = self::get_uri_from_value($mType, 'mType', $mValue)) { //'non-numeric value measurement'
                    if($mValueURL = self::get_uri_from_value($mValue, 'mValue', 'wala')) {
                        $this->func->pre_add_string_types($save, $mValueURL, $mTypeURL, "true");
                        $cont_save_child_MoF_YN = true;
                    }
                }
            }
        }
        if($cont_save_child_MoF_YN) {
            if($val = @$this->child_of_parent[$mID]['locality (mrgid)']) {
                $mTypev = $this->schema_uri['locality'];
                self::add_child_mof($val, $mTypev, $mID);
            }
            /* Works OK. But don't add child MoF for sex; since we are now adding a col in Occurrence for sex.
            if($val = @$this->child_of_parent[$mID]['sex']) {
                $mTypev = $this->schema_uri['sex'];
                self::add_child_mof($val, $mTypev, $mID);
            }*/
        }
    }

    private function add_child_mof($val_string, $mTypev, $mID)
    {   /* write child record in MoF: SampleSize
        child record in MoF:
            - doesn't have: occurrenceID | measurementOfTaxon
            - has parentMeasurementID
            - has also a unique measurementID, as expected.
        minimum cols on a child record in MoF
            - measurementID
            - measurementType
            - measurementValue
            - parentMeasurementID
        */
        $mValuev = self::get_uri_from_value($val_string, 'mValue', 'locality');
        $save_child = array();
        $save_child['measurementID'] = '';
        $save_child['measurementType'] = $mTypev;
        $save_child['measurementValue'] = $mValuev;
        $save_child['parentMeasurementID'] = $mID;
        if($mTypev && $mValuev) $this->func->pre_add_string_types($save_child, $mValuev, $mTypev, "child");
    }
    private function proceed_2write($rec, $class)
    {
        if($class == "taxon")           $o = new \eol_schema\Taxon();
        elseif($class == "document")    $o = new \eol_schema\MediaResource();        
        elseif($class == 'MoF')         $o = new \eol_schema\MeasurementOrFact_specific();
        elseif($class == 'occurrence')  $o = new \eol_schema\Occurrence_specific();
        elseif($class == 'reference')   $o = new \eol_schema\Reference();
        else exit("\nclass not defined [$class].\n");

        $uris = array_keys($rec); //print_r($uris); exit("\ndito eli\n");
        foreach($uris as $uri) {
            $field = pathinfo($uri, PATHINFO_BASENAME);
            $o->$field = $rec[$uri];
        }
        $this->archive_builder->write_object_to_file($o);
    }
    private function small_field($uri)
    {
        return pathinfo($uri, PATHINFO_FILENAME);
    }
    private function get_worms_taxon_id($worms_id)
    {
        return trim(str_ireplace("urn:lsid:marinespecies.org:taxname:", "", (string) $worms_id));
    }
    private function format_worms_fields($rec)
    {
        $fields = array('taxonID', 'parentNameUsageID', 'acceptedNameUsageID');
        foreach($fields as $field) {
            if(isset($rec[$field])) $rec[$field] = self::get_worms_taxon_id($rec[$field]);
        }
        
        $fields = array('taxonomicStatus');
        foreach($fields as $field) {
            if(isset($rec[$field])) $rec[$field] = self::format_status($rec[$field]);
        }

        return $rec;
    }
    private function format_status($string)
    {   //e.g. 'https://rs.gbif.org/vocabulary/gbif/taxonomicStatus/accepted';
        if(substr($string, 0, 4) == 'http') $status = pathinfo($string, PATHINFO_FILENAME);
        else $status = $string;
        @$this->debug['taxonomicStatus'][$status]++;
        return $status;
    }
    private function get_EoL_terms()
    {
        require_library('connectors/EOLterms_ymlAPI');
        $func = new EOLterms_ymlAPI(false, false);
        $eol_terms = $func->convert_EOL_Terms_2array();
        echo "\nTerms count from EOL Terms file: [".count($eol_terms['terms'])."]\n";
        $final = array();
        foreach($eol_terms['terms'] as $rec) { 
            $uri = $rec['uri'];
            $name = Functions::remove_quote_delimiters($rec['name']);   //%/month
            $final[$name] = $uri;
        }
        return $final;
    }
    private function initialize_mapping()
    {                           
        /* Obsolete
        $mappings = Functions::get_eol_defined_uris(false, true);     //1st param: false means will use 1day cache | 2nd param: opposite direction is true
        echo "\n".count($mappings). " - default URIs from EOL registry.";
        */
        $mappings = self::get_EoL_terms(); //first layer of [label => uri] values

        $uris = Functions::additional_mappings($mappings, 60*60*24); //add more mappings used in the past. 2nd param is expire_seconds. 0 means expires now
        echo "\nURIs total A: ".count($uris)."\n";
        
        // /* exclusive mapping for WoRMS only
        $url = $this->native_intro_mapping;
        $uris = Functions::additional_mappings($uris, 60*60*24, $url); //add a single mapping. 2nd param is expire_seconds
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
        if($final['Europe'] == 'http://www.geonames.org/6255148') echo "\nTest URI lookup OK\n";
        else echo "\nERROR: URI lookup failed\n";
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
    private function format_measurementUnit($rec)
    {   if($val = @$rec['measurementUnit']) { //e.g. mm
            $val = trim($val);
            if($uri = @$this->mUnit[$val]) return $uri;
            else $this->debug['undefined mUnit literal'][$val] = '';
        }
    }
    private function adjustments_4_measurementAccuracy($save, $rec)
    {   if($vtaxon_id = self::get_id_from_measurementAccuracy($rec['measurementAccuracy'])) {
            if($sciname = @$this->taxa_rank[$vtaxon_id]['n']) {
                $save['measurementMethod'] = $rec['measurementAccuracy'].', '.$sciname;
            }
            else {
                if($sciname = self::lookup_worms_name($vtaxon_id)) {
                    $save['measurementMethod'] = $rec['measurementAccuracy'].', '.$sciname;
                }
                else {
                    $this->debug['sciname not found with id from measurementAccuracy'][$vtaxon_id] = '';
                    $save['measurementMethod'] = $rec['measurementAccuracy'];
                }
            }
        }
        return $save;
    }
    private function get_id_from_measurementAccuracy($str)
    {   
        if($str) {
            $arr = explode(":", $str);
            return array_pop($arr);
        }
        return false;
    }
    private function get_uri_from_value($val, $type_or_value, $what2, $uriRequiredYN = false)
    {   
        // print_r($this->value_uri_map); exit("\nstop muna\n");
        if(is_numeric($val)) return $val;
        $orig = $val;
        $val = trim(strtolower($val));
            if($uri = @$this->value_uri_map[$val]) return $uri;
        elseif($uri = @$this->value_uri_map[$orig]) return $uri;
        else {
            $this->debug["No URI - $type_or_value"]["[$orig]--($type_or_value)--($what2)"] = ''; //log only non-numeric values
            $this->debug["No URI* - $type_or_value"][$orig] = '';
            if($type_or_value == 'mType') return false;
            if($type_or_value == 'mValue') {
                if($uriRequiredYN) return false;
                else return $orig;
            }
        }
    }
    private function lookup_worms_name($vtaxon_id)
    {   $options = $this->download_options;
        $options['expire_seconds'] = false;
        if($json = Functions::lookup_with_cache($this->webservice['AphiaRecordByAphiaID'].$vtaxon_id, $options)) { //exit("\nlookup 1\n");
            $arr = json_decode($json, true); // print_r($arr); exit;
            return trim($arr['scientificname']." ".$arr['authority']);
        }
        exit("\nid not found [$vtaxon_id]\n");
        return false;
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
    private function get_children_of_MoF($mID)
    {
        $final = array();
        while(true) {
            if($child = @$this->childOf[$mID]) {
                $final[$child] = '';
                $mID = $child;
            }
            else break;
        }
        return array_keys($final);
    }
    private function get_mIDs_2exclude($rec)
    {   /*Array(
            [MeasurementOrFact] => 1054700
            [measurementID] => 2687300_1054700
            [parentMeasurementID] => 
            [measurementType] => Nomenclature code
            [measurementValueID] => 
            [measurementValue] => The International Code of Zoological Nomenclature (ICZN)
            [measurementUnit] => 
            [measurementAccuracy] => inherited from urn:lsid:marinespecies.org:taxname:2
        )*/
        $mID = $rec['measurementID'];
        $mType = $rec['measurementType'];
        $mValue = $rec['measurementValue'];
        $measurementAccuracy = $rec['measurementAccuracy'];
        
        // /* 1st criteria for deletion
        if(isset($this->exclude_mType_mValue[$mType][$mValue])) {
            $this->ToExcludeMeasurementIDs[$mID] = '';
            if($children = self::get_children_of_MoF($mID)) { //print_r($children); exit("\nchildren of $mID\n");
                foreach($children as $child) $this->ToExcludeMeasurementIDs[$child] = '';
            }
            if($child = @$this->childOf[$mID]) $this->ToExcludeMeasurementIDs[$child] = '';
        }
        // */
                
        /* 2nd criteria for deletion: per https://eol-jira.bibalex.org/browse/DATA-1827?focusedCommentId=67036&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-67036
        To start with, let's filter out all records with measurementMethod = inherited from urn:lsid:marinespecies.org:taxname:558, Porifera Grant, 1836
        */
        if($measurementAccuracy == 'inherited from urn:lsid:marinespecies.org:taxname:558') {
            $this->ToExcludeMeasurementIDs[$mID] = '';
            if($child = @$this->childOf[$mID]) $this->ToExcludeMeasurementIDs[$child] = '';
            if($children = self::get_children_of_MoF($mID)) {
                foreach($children as $child) $this->ToExcludeMeasurementIDs[$child] = '';
            }
        }
        // */
    }
    // ####################################################################################################################
    // ========================================================================================== below is copied template
    // ####################################################################################################################
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
                if($rec["language"] == 'DEU') continue;
                /*Array(
                    [http://rs.tdwg.org/dwc/terms/taxonID] => urn:lsid:marinespecies.org:taxname:2
                    [http://rs.tdwg.org/dwc/terms/vernacularName] => dieren
                    [http://purl.org/dc/terms/source] => 
                    [http://purl.org/dc/terms/language] => NLD
                    [http://rs.gbif.org/terms/1.0/isPreferredName] => 0
                )*/
                $temp_id = self::get_worms_taxon_id($rec['http://rs.tdwg.org/dwc/terms/taxonID']);
                if(isset($this->debug['Excluded taxa'][$temp_id])) continue;
            }
            // */
            
            // /* agent.tab must have a name, cannot be blank
            if($class == "agent") {
                /*Array(
                    [http://purl.org/dc/terms/identifier] => WoRMS:Author:–NOAA–OE
                    [http://xmlns.com/foaf/spec/#term_name] => – (NOAA–OE) 
                    [http://xmlns.com/foaf/spec/#term_firstName] => 
                    [http://xmlns.com/foaf/spec/#term_familyName] => 
                    [http://eol.org/schema/agent/agentRole] => 
                    [http://xmlns.com/foaf/spec/#term_mbox] => 
                    [http://xmlns.com/foaf/spec/#term_homepage] => 
                    [http://xmlns.com/foaf/spec/#term_logo] => 
                    [http://xmlns.com/foaf/spec/#term_currentProject] => 
                    [http://eol.org/schema/agent/organization] => 
                    [http://xmlns.com/foaf/spec/#term_accountName] => 
                    [http://xmlns.com/foaf/spec/#term_openid] => 
                )*/
                if(@$rec['http://xmlns.com/foaf/spec/#term_name'] || @$rec['http://xmlns.com/foaf/spec/#term_firstName'] || @$rec['http://xmlns.com/foaf/spec/#term_familyName']) {}
                else continue; 
            }
            // */

            $this->archive_builder->write_object_to_file($c);
        } // end foreach()
    }
    private function format_sciname($str)
    {   //http://parser.globalnames.org/doc/api

        // $str = str_replace("&", "%26", $str);
        // $str = str_replace(" ", "+", $str);
        return urlencode($str);
    }
    private function get_uri_case_insensitive($measurementValue)
    {
        if($val = @$this->eol_terms_value_uri[$measurementValue]) return $val;
        if($val = @$this->eol_terms_value_uri[strtolower($measurementValue)]) return $val;        
    }
    private function get_super_child($id)
    {   $current = '';
        while(true) {
            if($child = @$this->childOf[$id]) {
                $current = $child;
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
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                // /* new May 11, 2021
                $term = @$field['term'];
                $rec[$term] = @$tmp[$k] ? trim($tmp[$k]) : "";
                $k++;
                // */
            } // print_r($rec); exit;

            $measurementType = $rec['http://rs.tdwg.org/dwc/terms/measurementType'];
            @$this->debug['measurementType'][$measurementType]++;

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

            $measurementType = $rec['http://rs.tdwg.org/dwc/terms/measurementType'];
            $association_mtypes = array('Ecological interactions > Host', 'Feeding method > Food source', 'Trophic level > Food source');

            // if($measurementType == 'Feedingtype > Host/prey') { //old
            if(in_array($measurementType, $association_mtypes)) {
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
                    print("\nInvestigate: cannot link to parent record [$super_parent].\n"); //e.g. 478430_458997 legit no parent record
                    continue;
                }
                //get lifeStage if any
                $lifeStage = ''; $sex = ''; $locality = '';
                if($parent = $rec['parentMeasurementID']) {
                    if($value_str = @$this->lifeStageOf[$parent]) { //e.g. 'adult'
                        $lifeStage = self::get_uri_from_value($value_str, 'mValue', 'lifeStage');
                    }
                    if($value_str = @$this->sexOf[$parent]) { //e.g. 'female'
                        $sex = self::get_uri_from_value($value_str, 'mValue', 'Sex');
                    }
                    if($value_str = @$this->localityOf[$parent]) { //e.g. 'Europe'
                        $locality = self::get_uri_from_value($value_str, 'mValue', 'locality');
                    }
                }
                // */
                if($predicate) {
                    $param = array('source_taxon_id' => $rec['http://rs.tdwg.org/dwc/terms/MeasurementOrFact'],     'predicate' => $predicate, 
                                   'target_taxon_id' => $rec['http://rs.tdwg.org/dwc/terms/measurementValueID'],    'target_taxon_name' => $rec['http://rs.tdwg.org/dwc/terms/measurementValue'], 
                                   'lifeStage' => $lifeStage, 'sex' => $sex, 'locality' => $locality);
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
            if($info = @$this->match2map[$mtype][$mvalue]) {} //$this->match2map came from a CSV mapping file
            //========================================================================================================next task --- "Body size"
            if($mtype == 'Body size') {} //this mType is for a parent MoF
            //========================================================================================================next task --- child of "Body size"
            $mtype = $rec['http://rs.tdwg.org/dwc/terms/measurementType']; //e.g. 'Body size > Gender'
            if(in_array($mtype, $this->children_mTypes)) {}
            //========================================================================================================end tasks
        }//end foreach
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

        $this->archive_builder->write_object_to_file($t); //write taxon 2
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
    private function small_field_rec($rec)
    {   $r = array();
        $fields = array_keys($rec);
        foreach($fields as $field) {
            $sfield = self::small_field($field);
            $r[$sfield] = $rec[$field];        
        }
        return $r;
    }
    private function get_objects($records) //2026
    {
        foreach($records as $rec) { 
            $rec = self::small_field_rec($rec);
            $rec['taxonID'] = self::get_worms_taxon_id($rec['taxonID']);
            /*Array(
                [identifier] => WoRMS:distribution:1000000
                [taxonID] => 850861
                [type] => http://purl.org/dc/dcmitype/Text
                [subtype] => 
                [format] => text/html
                [CVterm] => http://rs.tdwg.org/ontology/voc/SPMInfoItems#Distribution
                [title] => Distribution
                [description] => Pakistani Exclusive Economic Zone
                [accessURI] => https://www.marinespecies.org/aphia.php?p=distribution&id=1000000
                [thumbnailURL] => 
                [furtherInformationURL] => 
                [derivedFrom] => 
                [CreateDate] => 2017-10-04T19:05:55+01:00
                [modified] => 2023-02-08T14:36:18+01:00
                [language] => en
                [Rating] => 
                [audience] => 
                [UsageTerms] => http://creativecommons.org/licenses/by/4.0/
                [rights] => This work is licensed under a Creative Commons Attribution-Share Alike 4.0 License
                [Owner] => 
                [bibliographicCitation] => Boyer F. (2017). Révision des Marginellidae du Récifal supérieur de l'île de Masirah (Oman). <em>Xenophora Taxonomy.</em> 17: 3-31.
                [publisher] => 
                [contributor] => 
                [creator] => Bouchet, Philippe
                [agentID] => WoRMS:Person:15
                [LocationCreated] => 
                [spatial] => 
                [wgs84_pos#lat] => 
                [wgs84_pos#long] => 
                [wgs84_pos#alt] => 
                [referenceID] => WoRMS:sourceid:285374
                [establishmentMeans] => 
                [occurrenceStatus] => present
            )*/
            $rec = array_map('trim', $rec);
            $identifier = (string) $rec["identifier"];
            $type       = (string) $rec["type"];
            $rec["taxon_id"] = self::get_worms_taxon_id($rec["taxonID"]);
            $rec["catnum"] = "";
            
            if(strpos($identifier, "WoRMS:distribution:") !== false) {
                $rec["catnum"] = (string) $rec["identifier"]; //e.g. WoRMS:distribution:1000000
                /* self::process_distribution($rec); removed as per DATA-1522 */ 
                $rec["catnum"] = str_ireplace("WoRMS:distribution:", "_", $rec["catnum"]);
                self::process_establishmentMeans_occurrenceStatus($rec); //DATA-1522
                continue;
            }
            // echo "*[$identifier]*";
            // continue; //debug only --- not PofMO
            
            // /* start new ticket DATA-1767: https://eol-jira.bibalex.org/browse/DATA-1767?focusedCommentId=62884&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-62884
            $title       = $rec["title"];
            $description = $rec["description"];
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
                
                $rec["catnum"] = (string) $rec["identifier"];
                $rec["accessURI"] = $this->taxon_page.$rec['taxon_id']; //this becomes m->source
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
            $mr->subtype        = (string) $rec["subtype"];
            $mr->Rating         = (string) $rec["Rating"];
            $mr->audience       = (string) $rec["audience"];
            if($val = trim((string) $rec["language"])) $mr->language = $val;
            else                                                                $mr->language = "en";
            $mr->format         = (string) $rec["format"];
            $mr->title          = RemoveHTMLTagsAPI::remove_html_tags((string) $rec["title"]);
            $this->debug['WoRMS titles'][$mr->title] = '';
            $mr->CVterm         = (string) $rec["CVterm"];
            $mr->creator        = (string) $rec["creator"];
            $mr->CreateDate     = (string) $rec["CreateDate"];
            $mr->modified       = (string) $rec["modified"];
            $mr->Owner          = (string) $rec["Owner"];
            $mr->rights         = RemoveHTMLTagsAPI::remove_html_tags((string) $rec["rights"]);
            $mr->UsageTerms     = (string) $rec["UsageTerms"];
            $mr->description    = RemoveHTMLTagsAPI::remove_html_tags((string) $rec["description"]);

            if($mr->format == "text/html") {
                if(!$mr->description) continue;
            }

            /* removed bibCite Oct 18, 2023
            $mr->bibliographicCitation = (string) $rec["bibliographicCitation"];
            */
            $mr->derivedFrom     = (string) $rec["derivedFrom"];
            $mr->LocationCreated = RemoveHTMLTagsAPI::remove_html_tags((string) $rec["LocationCreated"]);
            $mr->spatial         = (string) $rec["spatial"];
            $mr->lat             = (string) $rec["wgs84_pos#lat"];
            $mr->long            = (string) $rec["wgs84_pos#long"];
            $mr->alt             = (string) $rec["wgs84_pos#alt"];
            $mr->publisher      = (string) $rec["publisher"];
            $mr->contributor    = (string) $rec["contributor"];
            
            if($agentID = (string) $rec["agentID"]) {
                $ids = explode(",", $agentID); // not sure yet what separator Worms used, comma or semicolon - or if there are any
                if(count($ids) == 1) $ids = explode("_", $agentID);
                $agent_ids = array();
                foreach($ids as $id) $agent_ids[] = $id;
                $mr->agentID = implode("; ", $agent_ids);
            }

            if($referenceID = self::prepare_reference((string) $rec["referenceID"])) $mr->referenceID = self::use_correct_separator($referenceID);
            
            if($mr->type != "http://purl.org/dc/dcmitype/Text") {
                $mr->accessURI      = self::complete_url((string) $rec["accessURI"]);
                $mr->thumbnailURL   = (string) $rec["thumbnailURL"];
                // below as of Oct 12, 2023
                if(!$mr->format) $mr->format = Functions::get_mimetype($mr->accessURI);
                if(!$mr->format) {
                    $this->debug['media with no mimetype, excluded'][$mr->accessURI] = '';
                    continue;
                }
            }
            
            if($source = (string) $rec["furtherInformationURL"]) $mr->furtherInformationURL = self::complete_url($source);
            else                                                 $mr->furtherInformationURL = $this->taxon_page . $mr->taxonID;
            
            if(!isset($this->object_ids[$mr->identifier])) {
                $this->object_ids[$mr->identifier] = '';
                $this->archive_builder->write_object_to_file($mr);
            }
        }
    }
    private function additional_traits_DATA_1767($rec, $mval, $mtype)
    {
        $rec["accessURI"] = $this->taxon_page.$rec['taxon_id']; //this becomes m->source
        $rec["catnum"] = (string) $rec["identifier"];
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
        if($val = $rec["description"])
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
    {   $location = $rec["description"]; //e.g. 'Pakistani Exclusive Economic Zone'
        if(!$location) return;
        $establishmentMeans = trim((string) @$rec["establishmentMeans"]);
        $occurrenceStatus = trim((string) @$rec["occurrenceStatus"]); //e.g. 'present'

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
        if(in_array($occurrenceStatus, array("present", "doubtful", "")) || $occurrenceStatus == "") { //echo("\ngoes here 3\n");
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

            if(isset($this->eol_terms_uri_value[$location_uri])) { //echo("\ngoes here 4\n");
                self::add_string_types($rec, "true", $location_uri, "http://eol.org/schema/terms/NativeRange");
                if($establishmentMeans == "Native - Endemic") self::add_string_types($rec, "metadata", "http://rs.tdwg.org/ontology/voc/OccurrenceStatusTerm#Endemic", "http://rs.tdwg.org/dwc/terms/measurementRemarks");
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

            if(isset($this->eol_terms_uri_value[$location_uri])) { //echo("\ngoes here 5\n");
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
        // /* 'Present' is now excluded. We leave GBIF as source of this type of info.
        if($measurementType == "http://eol.org/schema/terms/Present") return;
        // */

        // print_r($rec); echo "\ngot here 100\n[$label] [$value] [$measurementType]\n";

        // /* new by Eli: Nov 7, 2023 ---> value must be a URI if mType == Present
        if($measurementType == "http://eol.org/schema/terms/Present" && substr($value, 0, 4) != "http") { //value is not URI e.g. 'Hokkaido'
            if($value = self::get_uri_from_value($value, 'mValue', 'Present', true)) {} //4th param is $uriRequiredYN
            else return;
        }
        // */                  

        // if(!isset($this->taxon_ids[$rec["taxon_id"]])) return; //New: Jan 1, 2026 //PofMO
         
        $m = new \eol_schema\MeasurementOrFact_specific();
        $occurrence_id = $this->add_occurrence($rec["taxon_id"], $rec["catnum"]);
        $m->occurrenceID = $occurrence_id;
        if($label == "Distribution" || $label == "true") { // so that measurementRemarks (and source, contributor, etc.) appears only once in the [measurement_or_fact.tab]
            $m->measurementOfTaxon = 'true';
            $m->measurementRemarks = '';
            $m->source = (string) $rec["accessURI"]; // http://www.marinespecies.org/aphia.php?p=distribution&id=274241
            /* removed bibCite Oct 18, 2023
            $m->bibliographicCitation = (string) $rec["bibliographicCitation"];
            */
            $m->contributor = (string) $rec["contributor"];
            if($referenceID = self::prepare_reference((string) $rec["referenceID"])) {
                $m->referenceID = self::use_correct_separator($referenceID);
            }
            //additional fields per https://eol-jira.bibalex.org/browse/DATA-1767?focusedCommentId=62884&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-62884
            $m->measurementDeterminedDate = $rec['CreateDate'];
            
            // /* New: Jun 7, 2021
            if($val = trim(@$rec['creator'])) {
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
                if($rec['type'] == "http://purl.org/dc/dcmitype/StillImage") {
                    $m->source = $rec["furtherInformationURL"];
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
        // exit("\nMoF saved OK\n");
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
    private function stats_for_JenKatja_Feb2026($rec)
    {
        $parentMeasurementID = $rec['parentMeasurementID'];
        $measurementType = $rec['http://rs.tdwg.org/dwc/terms/measurementType'];
        $measurementValue = $rec['http://rs.tdwg.org/dwc/terms/measurementValue'];
        if($parentMeasurementID) $type = 'child';
        else                     $type = 'parent';
        if(!is_numeric($measurementValue)) {
            if($uri = self::get_uri_case_insensitive($measurementValue)) $measurementValue .= " = ".json_encode($uri);
            if($type == 'parent') {
                $mtype_uri = self::get_uri_case_insensitive($measurementType);
                if(is_array($mtype_uri)) $mtype_uri = json_encode($mtype_uri);
                $this->for_study[$measurementType]["(Parent MoF) *[$mtype_uri]"][$measurementValue] = '';
            }
            elseif($type == 'child') {
                $arr = explode(">", $measurementType);
                if(stripos($measurementType, "> Locality (MRGID)") !== false) { //found string
                    $this->localities[$measurementValue] = '';
                    $measurementValue = 'List of localities. See separate file. ['.$this->schema_uri['locality'].']';
                }
                elseif(stripos($measurementType, "> Life stage") !== false) { //found string
                    $this->lifestages[$measurementValue] = '';
                    $measurementValue = 'List of life stages. See separate file. ['.$this->schema_uri['lifeStage'].']';
                }
                if($measurementType == 'Ecological interactions > Host') $this->for_study[trim($arr[0])]["$measurementType (Child MoF)"]['List of taxa e.g. Saurida gracilis'] = '';
                elseif($measurementType == 'Feeding method > Food source') $this->for_study[trim($arr[0])]["$measurementType (Child MoF)"]['List of taxa e.g. Bucephaloides gracilescens'] = '';                    
                elseif($measurementType == 'Trophic level > Food source') $this->for_study[trim($arr[0])]["$measurementType (Child MoF)"]['List of taxa e.g. Acanthocyclus albatrossis'] = '';                    
                else $this->for_study[trim($arr[0])]["$measurementType (Child MoF)"][$measurementValue] = '';
            }
        }
        else {
            if($type == 'parent') {
                $mtype_uri = self::get_uri_case_insensitive($measurementType);
                if(is_array($mtype_uri)) $mtype_uri = json_encode($mtype_uri);
                $this->for_study[$measurementType]["(Parent MoF) **[$mtype_uri]"] = '';
            }
        }
        // more stats
        if($type == 'parent') {
            if($measurementType) $this->debug['Parent MoFs'][$measurementType] = '';
        }
    }
}
?>