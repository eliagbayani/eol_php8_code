<?php
namespace php_active_record;
/* connector 2025: [gbif_map_data.php] 
https://editors.eol.org/map_data2/1/4501.json
https://editors.eol.org/map_data2/final_taxon_concept_IDS.txt
*/
/* Workspaces for GBIF map tasks:
- GBIFMapDataAPI
- GBIF_map_harvest
- GBIF_SQL_DownloadsAPI
- GBIFTaxonomy */
class GBIFMapDataAPI
{
    public function __construct($what, $ctr) //eg. map_kingdom_not_animalia_nor_plantae
    {
        $this->ctr = $ctr;
        $this->download_options = array('resource_id' => "gbif_ctry_checklists", 'expire_seconds' => 60*60*24*30*3, 'download_wait_time' => 1000000/2, 'timeout' => 10800*2, 'download_attempts' => 3, 'delay_in_minutes' => 5); //3 months to expire
        $this->download_options['expire_seconds'] = false; //doesn't expire
        $this->debug = array();
        // $this->bibliographicCitation = "GBIF.org (23 January 2025) GBIF Occurrence Download https://doi.org/10.15468/dl.3vk32d";

        if($this->taxonGroup = $what) {
            if(Functions::is_production())  $this->work_dir = "/extra/other_files/GBIF_occurrence/".$what."/";
            else                            $this->work_dir = "/Volumes/Crucial_4TB/other_files/GBIF_occurrence/".$what."/";    
        }
        
        $this->service['species'] = "https://api.gbif.org/v1/species/"; //https://api.gbif.org/v1/species/1000148
        $this->service['children'] = "https://api.gbif.org/v1/species/TAXON_KEY/childrenAll"; //https://api.gbif.org/v1/species/44/childrenAll
        $this->service['occurrence_count'] = "https://api.gbif.org/v1/occurrence/count?taxonKey="; //https://api.gbif.org/v1/occurrence/count?taxonKey=44            

        if(Functions::is_production()) {
            $this->save_path['taxa_csv_path']     = "/extra/other_files/GBIF_occurrence/GBIF_taxa_csv_dwca/";
            $this->save_path['multimedia_gbifID'] = "/extra/other_files/GBIF_occurrence/multimedia_gbifID/";
            $this->save_path['map_data']          = "/extra/map_data_dwca/";
            // $this->eol_taxon_concept_names_tab    = "/extra/eol_php_code_public_tmp/google_maps/taxon_concept_names.tab"; obsolete
            // $this->eol_taxon_concept_names_tab    = "/extra/other_files/DWH/from_OpenData/EOL_dynamic_hierarchyV1Revised/taxa.txt"; //working but old DH ver.
            $this->eol_taxon_concept_names_tab    = "/extra/other_files/DWH/TRAM-809/DH_v1_1/taxon.tab";    //latest active DH ver.
            // to be updated to: https://editors.eol.org/uploaded_resources/1c3/b5f/dhv21.zip
    
            
            // $this->occurrence_txt_path['Animalia']     = "/extra/other_files/GBIF_occurrence/DwCA_Animalia/occurrence.txt";
            // $this->occurrence_txt_path['Plantae']      = "/extra/other_files/GBIF_occurrence/DwCA_Plantae/occurrence.txt";
            // $this->occurrence_txt_path['Other7Groups'] = "/extra/other_files/GBIF_occurrence/DwCA_Other7Groups/occurrence.txt";
        }
        else {
            $this->save_path['taxa_csv_path']     = "/Volumes/Crucial_4TB/google_maps/GBIF_taxa_csv_dwca/";
            $this->save_path['multimedia_gbifID'] = "/Volumes/Crucial_4TB/google_maps/multimedia_gbifID/";
            $this->save_path['map_data']          = "/Volumes/Crucial_4TB/google_maps/map_data_dwca/";
            /* seems not used here but in: GBIFoccurrenceAPI_DwCA.php
            // $this->eol_taxon_concept_names_tab    = "/Volumes/AKiTiO4/eol_pub_tmp/google_maps/JRice_tc_ids/taxon_concept_names.tab"; obsolete
            // $this->eol_taxon_concept_names_tab    = "/Volumes/AKiTiO4/other_files/from_OpenData/EOL_dynamic_hierarchyV1Revised/taxa.txt"; //working but old DH ver.
            $this->eol_taxon_concept_names_tab = "/Volumes/AKiTiO4/d_w_h/EOL Dynamic Hierarchy Active Version/DH_v1_1/taxon.tab"; //used for the longest time
            $this->eol_taxon_concept_names_tab = "/Volumes/AKiTiO4/d_w_h/history/dhv21/taxon.tab";
            */

            // $this->occurrence_txt_path['Gadus morhua'] = "/Volumes/AKiTiO4/eol_pub_tmp/google_maps/occurrence_downloads/DwCA/Gadus morhua/occurrence.txt";
            // $this->occurrence_txt_path['Lates niloticus'] = "/Volumes/AKiTiO4/eol_pub_tmp/google_maps/occurrence_downloads/DwCA/Lates niloticus/occurrence.txt";
        }

        $this->csv_paths = array();
        $this->csv_paths[] = $this->save_path['taxa_csv_path'];
        
        $folders = array($this->save_path['taxa_csv_path'], $this->save_path['multimedia_gbifID'], $this->save_path['map_data']);
        foreach($folders as $folder) {
            if(!is_dir($folder)) mkdir($folder);
        }
        
        $this->listOf_taxa['order']  = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_order_4maps.txt';
        $this->listOf_taxa['family'] = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_family_4maps.txt';
        $this->listOf_taxa['genus']  = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_genus_4maps.txt';
        $this->listOf_taxa['all']    = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_all_4maps.txt';

        $this->auto_refresh_mapYN = false;  //use false for normal operation
        $this->use_API_not_CSV_YN_2025 = false;     //use false for normal operation
        $this->use_API_YN = false;     //use false for normal operation
        $this->run_species_level = true;
    }
    private function initialize()
    {
        /*
        $local = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_all_plantae_4maps.txt';  $exclude_1 = self::process_generic_tsv($local, 'get Plantae EOLids');
        // $local = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_all_chordata_4maps.txt'; $exclude_2 = self::process_generic_tsv($local, 'get Chordata EOLids');
        $local = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_all_arthropoda_4maps.txt'; $exclude_2 = self::process_generic_tsv($local, 'get Arthropoda EOLids');
        $local = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_all_passeriformes_4maps.txt'; $exclude_3 = self::process_generic_tsv($local, 'get Passeriformes EOLids');

        $exclude_1 = array_keys($exclude_1);
        $exclude_2 = array_keys($exclude_2);
        $exclude_3 = array_keys($exclude_3);

        // $exclude_2 = array();
        $exclude = array_merge($exclude_1, $exclude_2, $exclude_3);
        foreach($exclude as $id) $this->exclude_eolids[$id] = '';
        echo "\nExcluded EOLids: ".count($this->exclude_eolids)."\n";
        // exit("\nelix 100\n");
        */
        $this->exclude_eolids = array();

        require_library('connectors/GBIFoccurrenceAPI_DwCA');
        $this->func = new GBIFoccurrenceAPI_DwCA();
    }
    function start($params)
    {   // print_r($params);
        self::initialize();
        $source = $this->work_dir . $this->taxonGroup ."_DwCA.zip";
        $tsv_path = self::download_extract_gbif_zip_file($source, $this->work_dir); echo "\n$this->taxonGroup: $tsv_path\n";
        // self::process_big_csv_file($tsv_path, "");
    }
    function breakdown_GBIF_DwCA_file($taxonGroup)
    {   //IMPORTANT: run only once every harvest
        self::initialize();
        $source = $this->work_dir . $this->taxonGroup ."_DwCA.zip";
        $tsv_path = self::download_extract_gbif_zip_file($source, $this->work_dir); echo "\nRun once: $this->taxonGroup: $tsv_path\n";

        $path2 = $this->save_path['taxa_csv_path'];
        $paths[] = $tsv_path;
        /* copied template
        if(Functions::is_production()) {
            if($group) $paths[] = $this->occurrence_txt_path[$group];
            else { //this means a long run, several days. Not distributed.
                $paths[] = $this->occurrence_txt_path['Animalia'];        //~717 million - Took 3 days 15 hr (when API calls are not yet cached)
                $paths[] = $this->occurrence_txt_path['Plantae'];         //~183 million - Took 1 day 19 hr (when API calls are not yet cached)
                $paths[] = $this->occurrence_txt_path['Other7Groups'];    //~25 million - Took 5 hr 10 min (when API calls are not yet cached)
            }
        }
        else $paths[] = $this->occurrence_txt_path[$group];
        */
        foreach($paths as $path) { $i = 0;
            foreach(new FileIterator($path) as $line_number => $row) { $i++; // 'true' will auto delete temp_filepath
                if($i == 1) { $fields = explode("\t", $row); continue; }
                else {
                    if(!$row) continue;
                    $tmp = explode("\t", $row);
                    $rec = array(); $k = 0;
                    foreach($fields as $field) { $rec[$field] = @$tmp[$k]; $k++; }
                    $rec = array_map('trim', $rec); //print_r($rec); exit("\nstop muna\n");
                }
                /*Array(
                    [catalognumber] => 
                    [scientificname] => Ciliata mustela (Linnaeus, 1758)
                    [publishingorgkey] => 1928bdf0-f5d2-11dc-8c12-b8a03c50a862
                    [institutioncode] => 
                    [datasetkey] => baa3340c-1c8b-46bf-9fc9-50554cb1cd01
                    [gbifid] => 4576498311
                    [decimallatitude] => 46.15224
                    [decimallongitude] => -1.35597
                    [recordedby] => JULUX (INDÉPENDANT)
                    [identifiedby] => 
                    [eventdate] => 2021-09-22
                    [kingdomkey] => 1
                    [phylumkey] => 44
                    [classkey] => 
                    [orderkey] => 549
                    [familykey] => 9639
                    [genuskey] => 9577782
                    [subgenuskey] => 
                    [specieskey] => 2415526
                )*/
                if(($i % 500000) == 0) echo "\n".number_format($i) . "[$path]\n";
                $taxonkey = $rec['specieskey'];
                // /* ----- can be postponed since not all records will eventually be used
                // $rec['publishingorgkey'] = $this->func->get_dataset_field($rec['datasetkey'], 'publishingOrganizationKey'); //orig but can be postponed
                $rec['publishingorgkey'] = 'nyc'; //not yet computed by Eli
                // ----- */
                $rek = array($rec['gbifid'], $rec['datasetkey'], $rec['scientificname'], $rec['publishingorgkey'], $rec['decimallatitude'], $rec['decimallongitude'], $rec['eventdate'], 
                $rec['institutioncode'], $rec['catalognumber'], $rec['identifiedby'], $rec['recordedby']);
                if($rec['decimallatitude'] && $rec['decimallongitude']) {
                    $path3 = $this->func->get_md5_path($path2, $taxonkey);
                    $csv_file = $path3 . $taxonkey . ".csv";
                    if(!file_exists($csv_file)) {
                        //order of fields here is IMPORTANT: will use it when accessing these generated individual taxon csv files
                        $str = 'gbifid,datasetkey,scientificname,publishingorgkey,decimallatitude,decimallongitude,eventdate,institutioncode,catalognumber,identifiedby,recordedby';
                        $fhandle = Functions::file_open($csv_file, "w");
                        fwrite($fhandle, implode("\t", explode(",", $str)) . "\n");
                        fclose($fhandle);
                    }
                    $fhandle = Functions::file_open($csv_file, "a");
                    fwrite($fhandle, implode("\t", $rek) . "\n");
                    fclose($fhandle);
                }
                // break; //debug only
            } //end foreach()
        } //end loop paths
        if(file_exists($tsv_path)) unlink($tsv_path); //delete big csv file
    }
    function generate_map_data_using_GBIF_csv_files($sciname = false, $tc_id = false, $range_from = false, $range_to = false, $autoRefreshYN = false)
    {
        self::initialize();
        $this->func->use_API_YN = true; //will be used in GBIFoccurrenceAPI_DwCA.php
        $this->func->run_species_level = true;
        $paths = $this->csv_paths;
        // $eol_taxon_id_list["Gadus morhua"] = 206692;
        // $eol_taxon_id_list["Achillea millefolium L."] = 45850244;
        // $eol_taxon_id_list["Francolinus levaillantoides"] = 1; //5227890
        // $eol_taxon_id_list["Phylloscopus trochilus"] = 2; //2493052
        // $eol_taxon_id_list["Anthriscus sylvestris (L.) Hoffm."] = 584996; //from Plantae group
        // $eol_taxon_id_list["Xenidae"] = 8965;
        // $eol_taxon_id_list["Soleidae"] = 5169;
        // $eol_taxon_id_list["Plantae"] = 281;
        // $eol_taxon_id_list["Chaetoceros"] = 12010;
        // $eol_taxon_id_list["Chenonetta"] = 104248;

        /* for testing 1 taxon
        $eol_taxon_id_list = array();
        $eol_taxon_id_list["Gadus morhua"] = 206692;
        $eol_taxon_id_list["Gadidae"] = 5503;
        $eol_taxon_id_list["Gadiformes"] = 1180;
        // $eol_taxon_id_list["Decapoda"] = 1183;
        // $eol_taxon_id_list["Proterebia keymaea"] = 137680; //csv map data not available from DwCA download
        // $eol_taxon_id_list["Aichi virus"] = 540501;
        */

        // $sciname = 'Gadella imberbis';  $tc_id = '46564969';
        // $sciname = 'Gadiformes';        $tc_id = '5496';
        // $sciname = 'Gadus morhua';      $tc_id = '46564415'; $taxonKey = '8084280';
        // $sciname = "Gadus chalcogrammus"; $tc_id = 216657;
        // $sciname = "Gadus macrocephalus"; $tc_id = 46564417;
        // $sciname = 'Stichastrella rosea'; $tc_id = '598446';

        // $sciname = "Amphistegina alabamensis"; $tc_id = '47098734';
        // if($taxonKey = $this->func->get_usage_key($sciname)) { debug("\nOK GBIF key [$taxonKey]\n"); }

        // $sciname = 'Eranno lagunae'; $tc_id = '459567'; $taxonKey = '2322769'; // 25 recs from CSV but 47 from API

        /*
        // Used records from CSV: [][][] 30898
        // $sciname = 'Ammodramus savannarum'; $tc_id = '45511206'; $taxonKey = '2491123';         //e.g. big csv value
        $sciname = 'Chlorospingus semifuscus'; $tc_id = '45513538'; $taxonKey = '2488735';      //e.g. small csv value
        $sciname = 'Agelaius phoeniceus'; $tc_id = '45511155'; $taxonKey = '9409198';      //18+ million csv records!
        $sciname = 'Agrostis capillaris'; $tc_id = '1114012'; $taxonKey = '2706490';      //no csv data
        */
    
        /* just a test of the func
            $test_sciname = "Ammodramus savannarum";
            if($usageKey = $this->func->get_usage_key($test_sciname)) { debug("\nOK GBIF key [$usageKey]\n"); }
            else echo "\n usageKey not found! [".$test_sciname."]\n";
            exit("\n-end test-\n");
        */

        if($sciname && $tc_id) { //exit("\nshould not go here...\n");
            if($this->use_API_not_CSV_YN_2025) { // using API
                $this->func->get_georeference_data_via_api($taxonKey, $tc_id);
            }
            else { // using dumps
                $this->func->create_map_data($sciname, $tc_id, $paths); //result of refactoring
            }
            return;
        }

        $options = $this->download_options;
        $options['expire_seconds'] = 60*60*24*30; //1 month expires
        $local = Functions::save_remote_file_to_local($this->listOf_taxa['all'], $options);
        $i = 0;
        $ctr = $this->ctr;
        foreach(new FileIterator($local) as $line_number => $line) {
            $i++; if(($i % 500000) == 0) echo "\n".number_format($i)." ";
            $row = explode("\t", $line); // print_r($row);
            if($i == 1) {
                $fields = $row;
                $fields = array_filter($fields); //print_r($fields);
                continue;
            }
            else {
                if(!@$row[0]) continue;
                $k = 0; $rec = array();
                foreach($fields as $fld) {
                    $rec[$fld] = @$row[$k];
                    $k++;
                }
            }
            $rec = array_map('trim', $rec);
            // /* ------------------------- dev only 
            // if($this->use_API_not_CSV_YN_2025) {
            if(true) {  //TO DO: DISTRIBUTE $ctr 3x, 13y, 14z to: 9x, 10x, 19y, 20z or 4
                $first_char = substr($rec['canonicalName'],0,1);
                $first_2chars = substr($rec['canonicalName'],0,2);

                // $this->auto_refresh_mapYN = true;
                if($ctr == 1) {
                    if(in_array(strtolower($first_2chars), array('aa', 'ab', 'ac', 'ad', 'ae', 'af', 'ag', 'ah', 'ai', 'aj', 'ak', 'al', 'am'))) {} else continue;  //1
                }
                if($ctr == 2) {
                    if(in_array(strtolower($first_2chars), array('an', 'ao', 'ap', 'aq', 'ar', 'as', 'at', 'au', 'av', 'aw', 'ax', 'ay', 'az'))) {} else continue;  //2
                }
                if($ctr == 3) {
                    if(in_array(strtolower($first_2chars), array('ca', 'cb', 'cc', 'cd', 'ce'))
                        || in_array(strtolower($first_2chars), array('ba', 'bb', 'bc', 'bd', 'be'))
                    ) {} else continue;  //3                
                }
                if($ctr == 4) { //long
                    if(in_array(strtolower($first_2chars), array('cf', 'cg', 'ch', 'ci', 'cj', 'ck', 'cl', 'cm'))) {} else continue;  //4
                }
                if($ctr == 5) {
                    if(in_array(strtolower($first_2chars), array('cn', 'co', 'cp', 'cq', 'cr', 'cs', 'ct', 'cu', 'cv', 'cw', 'cx', 'cy', 'cz'))) {} else continue;  //5
                }
                if($ctr == 6) {
                    if(in_array(strtolower($first_char), array('d'))) {} else continue;             //6    
                }
                if($ctr == 7) {
                    if(in_array(strtolower($first_char), array('e'))) {} else continue;             //7    
                }
                if($ctr == 8) {
                    if(in_array(strtolower($first_char), array('f','g'))) {} else continue;         //8    
                }
                if($ctr == 9) {
                    if(in_array(strtolower($first_2chars), array('ha', 'hb', 'hc', 'hd', 'he', 'hf', 'hg', 'hh', 'hi', 'hj', 'hk', 'hl', 'hm'))) {} 
                    elseif(in_array(strtolower($first_2chars), array('bn', 'bo', 'bp', 'bq', 'br', 'bs', 'bt', 'bu', 'bv', 'bw', 'bx', 'by', 'bz'))) {} 
                    else continue;  //9
                }
                if($ctr == 10) {
                    if(in_array(strtolower($first_2chars), array('hn', 'ho', 'hp', 'hq', 'hr', 'hs', 'ht', 'hu', 'hv', 'hw', 'hx', 'hy', 'hz'))
                        || in_array(strtolower($first_char), array('i','j','k'))
                        || in_array(strtolower($first_2chars), array('bf', 'bg', 'bh', 'bi', 'bj', 'bk', 'bl', 'bm'))
                    ) {} else continue;  //10
                }
                if($ctr == 11) {
                    if(in_array(strtolower($first_char), array('l'))) {} else continue;             //11   
                }
                if($ctr == 12) {
                    if(in_array(strtolower($first_2chars), array('ma', 'mb', 'mc', 'md', 'me', 'mf', 'mg', 'mh', 'mi', 'mj', 'mk', 'ml', 'mm'))) {} else continue;  //12
                }
                if($ctr == 13) {
                    if(in_array(strtolower($first_2chars), array('mn', 'mo', 'mp', 'mq', 'mr', 'ms', 'mt', 'mu', 'mv', 'mw', 'mx', 'my', 'mz', 'pa', 'pb', 'pc', 'pd', 'pe'))
                        || in_array(strtolower($first_2chars), array('na', 'nb', 'nc', 'nd', 'ne', 'nf', 'ng', 'nh', 'ni', 'nj', 'nk', 'nl', 'nm'))
                    ) {} else continue;  //13
                }
                if($ctr == 14) {
                    if(in_array(strtolower($first_2chars), array('sn', 'so', 'sp', 'sq', 'sr', 'ss', 'st', 'su', 'sv', 'sw', 'sx', 'sy', 'sz'))
                        || in_array(strtolower($first_2chars), array('oa', 'ob', 'oc', 'od', 'oe', 'of', 'og', 'oh', 'oi', 'oj', 'ok', 'ol', 'om'))
                    ) {} else continue;  //14
                }
                if($ctr == 15) { //long
                    if(in_array(strtolower($first_2chars), array('pf', 'pg', 'ph', 'pi', 'pj', 'pk', 'pl', 'pm'))) {} else continue;  //15
                }
                if($ctr == 16) {
                    if(in_array(strtolower($first_2chars), array('pn', 'po', 'pp', 'pq', 'pr', 'ps', 'pt', 'pu', 'pv', 'pw', 'px', 'py', 'pz'))) {} else continue;  //16
                }
                if($ctr == 17) {
                    if(in_array(strtolower($first_2chars), array('tn', 'to', 'tp', 'tq', 'tr', 'ts', 'tt', 'tu', 'tv', 'tw', 'tx', 'ty', 'tz'))
                        || in_array(strtolower($first_char), array('q', 'r'))
                    ) {} else continue;  //17
                }
                if($ctr == 18) { //species-level
                    if(in_array(strtolower($first_2chars), array('sa', 'sb', 'sc', 'sd', 'se', 'sf', 'sg', 'sh', 'si', 'sj', 'sk', 'sl', 'sm'))) {} else continue;  //18
                }
                if($ctr == 19) {
                    if(in_array(strtolower($first_2chars), array('ta', 'tb', 'tc', 'td', 'te', 'tf', 'tg', 'th', 'ti', 'tj', 'tk', 'tl', 'tm'))) {} 
                    elseif(in_array(strtolower($first_2chars), array('nn', 'no', 'np', 'nq', 'nr', 'ns', 'nt', 'nu', 'nv', 'nw', 'nx', 'ny', 'nz'))) {}
                    else continue;  //19
                }
                if($ctr == 20) {
                    if(in_array(strtolower($first_char), array('u','v','w','x','y','z'))) {} 
                    elseif(in_array(strtolower($first_2chars), array('on', 'oo', 'op', 'oq', 'or', 'os', 'ot', 'ou', 'ov', 'ow', 'ox', 'oy', 'oz'))) {}
                    else continue;                                                         //20
                }
            }
            // ------------------------- */

            if(in_array($rec['taxonRank'], array('species', 'subspecies'))) {} //run only species-level and subspecies-level taxa. subspecies exclusively from API only.
            // if($rec['taxonRank'] != 'species') {} //run only higher-level taxa at this point //Was NEVER used. And DO NO use it.
            else continue;

            /* working way to filter records but not used atm.
            if(isset($this->exclude_eolids[$rec['EOLid']])) { echo " under Plantae, will ignore. "; continue; }
            */

            print_r($rec); //exit("\nstopx\n");
            /*Array(
                [canonicalName] => Oscillatoriales
                [EOLid] => 3255
                [taxonRank] => order
                [taxonomicStatus] => accepted
            )*/

            /* caching usageKey only. Not part of main operation
            if($usageKey = $this->func->get_usage_key($rec['canonicalName'])) debug("\nOK GBIF key [$usageKey]\n");
            continue;
            */

            //  new ranges ---------------------------------------------
            if($range_from && $range_to) {
                $cont = false;
                if($i >= $range_from && $i < $range_to) $cont = true;
                if(!$cont) continue;
            }
            //  --------------------------------------------------------
            echo "\n$i of $range_to. [".$rec['canonicalName']."][".$rec['EOLid']."]";

            if($this->use_API_not_CSV_YN_2025) { //normal operation this is false. Priority is CSV data.
                /* new: using api --- works OK
                if($usageKey = $this->func->get_usage_key($rec['canonicalName'])) { debug("\nOK GBIF key [$usageKey]\n");
                    if(!$this->auto_refresh_mapYN) {
                        if($this->func->map_data_file_already_been_generated($rec['EOLid'])) continue;
                    }    
                    $this->func->get_georeference_data_via_api($usageKey, $rec['EOLid']);
                }
                else {
                    echo "\n usageKey not found! [".$rec['canonicalName']."][".$rec['EOLid']."]\n";
                    $this->debug['usageKey not found']["[".$rec['canonicalName']."][".$rec['EOLid']."]"] = '';
                }
                */
            }
            else {
                // /* orig using downloaded csv 2025
                $this->func->create_map_data($rec['canonicalName'], $rec['EOLid'], $paths); //result of refactoring
                // */
            }

            // break; //debug only
        } //end foreach()
        unlink($local);
        print_r($this->debug);
        print_r($this->func->debug);
        if($this->func->debug) Functions::start_print_debug($this->func->debug, "gen_map_data_via_gbif_csv");
    }
    function gen_map_data_forTaxa_with_children($p) //($sciname = false, $tc_id = false, $range_from = false, $range_to = false, $filter_rank = '')
    {
        self::initialize();
        $this->func->use_API_YN = false; //no more API calls at this point. Since this is higher-level taxa now
        $this->func->run_species_level = false;
        require_library('connectors/DHConnLib'); $func = new DHConnLib('');
        $paths = $this->csv_paths; 
        
        /* ----- for testing only - works OK
        // $sciname = "Gadus";         $tc_id = "46564414";    //genus
        // $sciname = "Gadidae";       $tc_id = "5503";        //family
        $sciname = "Gadiformes";    $tc_id = "5496";         //order
        // $sciname = 'Adlafia'; $tc_id = '12093';
        if($sciname && $tc_id) {
            $eol_taxon_id_list[$sciname] = $tc_id; print_r($eol_taxon_id_list); 
            $this->func->create_map_data_include_descendants($sciname, $tc_id, $paths, $func); //result of refactoring
            return;
        }
        ----- */
        
        /* used FileIterator below instead, to save on memory
        $i = 0;
        foreach($eol_taxon_id_list as $sciname => $taxon_concept_id) {
            $i++;
            //  new ranges ---------------------------------------------
            if($range_from && $range_to) {
                $cont = false;
                if($i >= $range_from && $i < $range_to) $cont = true;
                if(!$cont) continue;
            }
            //  --------------------------------------------------------
            echo "\n$i. [$sciname][$taxon_concept_id]";
            self::create_map_data_include_descendants($sciname, $taxon_concept_id, $paths, $func); //result of refactoring
        } //end main foreach()
        */
        
        $options = $this->download_options;
        $options['expire_seconds'] = 60*60*24*30; //1 month expires
        $local = Functions::save_remote_file_to_local($this->listOf_taxa[$p['filter_rank']], $options);
        $i = 0; $found = 0;
        $ctr = $this->ctr;
        foreach(new FileIterator($local) as $line_number => $line) {
            $i++; if(($i % 500000) == 0) echo "\n".number_format($i)." ";
            $row = explode("\t", $line); // print_r($row);
            if($i == 1) {
                $fields = $row;
                $fields = array_filter($fields); //print_r($fields);
                continue;
            }
            else {
                if(!@$row[0]) continue;
                $k = 0; $rec = array();
                foreach($fields as $fld) {
                    $rec[$fld] = @$row[$k];
                    $k++;
                }
            }
            $rec = array_map('trim', $rec);
            // print_r($rec); exit("\nstopx\n");
            /*Array(
                [canonicalName] => Oscillatoriales
                [EOLid] => 3255
                [taxonRank] => order
                [taxonomicStatus] => accepted
            )*/
            
            /* //  new ranges --------------------------------------------- not used anymore
            if($range_from && $range_to) {
                $cont = false;
                if($i >= $range_from && $i < $range_to) $cont = true;
                if(!$cont) continue;
            }
            //  -------------------------------------------------------- */
            $first_char = substr($rec['canonicalName'],0,1);
            $first_2chars = substr($rec['canonicalName'],0,2);
            if($ctr == 1) { //higher-level
                if(in_array(strtolower($first_2chars), array('aa', 'ab', 'ac', 'ad', 'ae', 'af', 'ag', 'ah', 'ai', 'aj', 'ak', 'al', 'am'))) {} 
                elseif(in_array(strtolower($first_2chars), array('sa'))) {}
                else continue;
            }
            if($ctr == 2) { 
                if(in_array(strtolower($first_2chars), array('an', 'ao', 'ap', 'aq', 'ar', 'as', 'at', 'au', 'av', 'aw', 'ax', 'ay', 'az'))) {} else continue;
            }
            if($ctr == 3) { 
                if(in_array(strtolower($first_char), array('b'))) {} 
                elseif(in_array(strtolower($first_2chars), array('ca'))) {} 
                else continue; 
            }
            if($ctr == 4) { 
                if(in_array(strtolower($first_2chars), array('cb', 'cc', 'cd', 'ce', 'cf', 'cg', 'ch', 'ci', 'cj', 'ck', 'cl', 'cm'))) {} else continue;
            }
            if($ctr == 5) { 
                if(in_array(strtolower($first_2chars), array('cn', 'co', 'cp', 'cq', 'cr', 'cs', 'ct', 'cu', 'cv', 'cw', 'cx', 'cy', 'cz'))) {} else continue;
            }
            if($ctr == 6) { if(in_array(strtolower($first_char), array('d'))) {} else continue; }
            if($ctr == 7) { if(in_array(strtolower($first_char), array('e'))) {} else continue; }
            if($ctr == 8) { if(in_array(strtolower($first_char), array('f', 'g'))) {} else continue; }
            if($ctr == 9) { if(in_array(strtolower($first_char), array('h', 'i', 'j'))) {} else continue; }
            if($ctr == 10) { if(in_array(strtolower($first_char), array('k', 'l'))) {} else continue; }
            if($ctr == 11) { if(in_array(strtolower($first_char), array('m'))) {} else continue; }
            if($ctr == 12) { if(in_array(strtolower($first_char), array('n', 'o'))) {} else continue; }
            if($ctr == 13) { 
                if(in_array(strtolower($first_2chars), array('pa', 'pb', 'pc', 'pd', 'pe', 'pf', 'pg', 'ph', 'pi', 'pj', 'pk', 'pl', 'pm'))) {} else continue;
            }
            if($ctr == 14) { 
                if(in_array(strtolower($first_2chars), array('pn', 'po', 'pp', 'pq', 'pr', 'ps', 'pt', 'pu', 'pv', 'pw', 'px', 'py', 'pz'))) {} else continue;
            }
            if($ctr == 15) { if(in_array(strtolower($first_char), array('q'))) {} else continue; }            
            if($ctr == 16) { if(in_array(strtolower($first_char), array('r'))) {} else continue; }
            if($ctr == 17) { //higher-level
                if(in_array(strtolower($first_2chars), array('sb', 'sc', 'sd', 'se', 'sf', 'sg', 'sh', 'si', 'sj', 'sk', 'sl', 'sm'))) {}
                elseif(in_array(strtolower($first_2chars), array('sn', 'so', 'sp', 'sq', 'sr', 'ss', 'st', 'su', 'sv', 'sw', 'sx', 'sy', 'sz'))) {}
                else continue;
            }
            if($ctr == 18) { if(in_array(strtolower($first_char), array('t'))) {} else continue; }
            if($ctr == 19) { if(in_array(strtolower($first_char), array('u', 'v', 'w'))) {} else continue; }
            if($ctr == 20) { if(in_array(strtolower($first_char), array('x', 'y', 'z'))) {} else continue; }
            echo "\n$i of . [".$rec['canonicalName']."][".$rec['EOLid']."]";
            $this->func->create_map_data_include_descendants($rec['canonicalName'], $rec['EOLid'], $paths, $func); //result of refactoring
            // break; //debug only
        } //end foreach()
        unlink($local);
    }
    /*
    private function process_big_csv_file($file, $task)
    {   echo "\nTask: [$task] [$file]\n";
        $i = 0; $final = array();
        if($task == "divide_into_country_files") $mod = 100000;
        elseif($task == "process_country_file")  $mod = 10000;
        else                                     $mod = 10000;
        foreach(new FileIterator($file) as $line => $row) { $i++; // $row = Functions::conv_to_utf8($row);
            if(($i % $mod) == 0) echo "\n $i ";
            if($i == 1) $fields = explode("\t", $row);
            else {
                if(!$row) continue;
                $tmp = explode("\t", $row);
                $rec = array(); $k = 0;
                foreach($fields as $field) { $rec[$field] = @$tmp[$k]; $k++; }
                $rec = array_map('trim', $rec); print_r($rec); //exit("\nstop muna\n");
                Array(
                    [catalognumber] => 
                    [scientificname] => Ciliata mustela (Linnaeus, 1758)
                    [publishingorgkey] => 1928bdf0-f5d2-11dc-8c12-b8a03c50a862
                    [institutioncode] => 
                    [datasetkey] => baa3340c-1c8b-46bf-9fc9-50554cb1cd01
                    [gbifid] => 4576498311
                    [decimallatitude] => 46.15224
                    [decimallongitude] => -1.35597
                    [recordedby] => JULUX (INDÉPENDANT)
                    [identifiedby] => 
                    [eventdate] => 2021-09-22
                    [kingdomkey] => 1
                    [phylumkey] => 44
                    [classkey] => 
                    [orderkey] => 549
                    [familykey] => 9639
                    [genuskey] => 9577782
                    [subgenuskey] => 
                    [specieskey] => 2415526
                )
                self::save_to_json($rec);
                // break;
            }
        }
    } */
    private function write_taxon_csv()
    {
        // gbifid	datasetkey	scientificname	publishingorgkey	decimallatitude	decimallongitude	eventdate	institutioncode	catalognumber	identifiedby	recordedby
    }
    /* not used atm
    private function save_to_json($rek)
    {   
        $rec = array();
        $rec['a']   = $rek['catalognumber'];
        $rec['b']   = $rek['scientificname'];
        $rec['c']   = $this->func->get_org_name('publisher', @$rek['publishingorgkey']);
        $rec['d']   = @$rek['publishingorgkey'];
        if($val = @$rek['institutioncode']) $rec['c'] .= " ($val)";
        $rec['e']   = $this->func->get_dataset_field(@$rek['datasetkey'], 'title'); //self::get_org_name('dataset', @$rek['datasetkey']);
        $rec['f']   = @$rek['datasetkey'];
        $rec['g']   = $rek['gbifid'];
        $rec['h']   = $rek['decimallatitude'];
        $rec['i']   = $rek['decimallongitude'];
        $rec['j']   = @$rek['recordedby'];
        $rec['k']   = @$rek['identifiedby'];
        $rec['l']   = $this->func->get_media_by_gbifid($rek['gbifid']);
        $rec['m']   = @$rek['eventdate'];
        print_r($rec); exit("\nstop 1\n");
    } */
    function prepare_taxa($key) //a utility
    {
        $final['occurrences'] = 0; $batch_sum = 0;
        $sum = 0; $batch_total = 250000000; //250 million
        $taxon_key_batches = array(); $current_keys = array();
        $options = $this->download_options;
        $options['expire_seconds'] = false; //should not expire; false is the right value.
        $url = str_replace("TAXON_KEY", $key, $this->service['children']);
        if($json = Functions::lookup_with_cache($url, $options)) {
            $reks = json_decode($json, true); //print_r($reks);
            $i = -1;
            foreach($reks as $rek) { $i++;
                /*Array(
                    [key] => 131
                    [name] => Amphibia
                    [rank] => CLASS
                    [size] => 16476
                )*/
                $taxon_key = $rek['key'];
                $taxon_rank = $rek['rank'];
                $count = Functions::lookup_with_cache($this->service['occurrence_count'].$rek['key'], $options);
                if($count <= 0) continue;
                $final['occurrences'] = $final['occurrences'] + $count;
                $batch_sum += $count;
                $current_keys[] = array('key' => $taxon_key, 'rank' => $taxon_rank);
                if($batch_sum > $batch_total) {
                    $taxon_key_batches[] = array('batch_sum' => $batch_sum, 'current_keys' => $current_keys);
                    $batch_sum = 0;
                    $current_keys = array();
                }
                // print_r($rek); //exit;
                // break; //debug only get only 1 rec
            }
            // last batch
            $taxon_key_batches[] = array('batch_sum' => $batch_sum, 'current_keys' => $current_keys);
            print_r($final);
            echo "\n". number_format($final['occurrences']) ."\n";
            print_r($taxon_key_batches);
        }
        // $url = "https://www.gbif.org/species/44";
        // if($html = Functions::lookup_with_cache($url, $options)) {
        //     echo "\n$html\n";
        // }
    }
    private function download_extract_gbif_zip_file($source, $destination)
    {
        echo "\ndownload_extract_gbif_zip_file...\n";
        // /* main operation - works OK
        require_library('connectors/INBioAPI');
        $func = new INBioAPI();
        $ret = $func->download_extract_zip_file($source, $destination); // echo "\n[$ret]\n";
        if(preg_match("/inflating:(.*?)elix/ims", $ret.'elix', $arr)) {
            $csv_path = trim($arr[1]); echo "\n[$csv_path]\n";
            return $csv_path;
        }
        return false;
        // */
        /* during dev only
        return "/Volumes/Crucial_4TB/other_files/GBIF_occurrence/map_Gadiformes/0000896-250225085111116.csv";
        */
    }
    private function process_generic_tsv($file, $task)
    {   echo "\nTask: [$task] [$file]\n";
        $i = 0; $final = array();
        if($task == "get Plantae EOLids") $mod = 500000;
        elseif($task == "yyy")            $mod = 100000;
        else                              $mod = 500000;
        foreach(new FileIterator($file) as $line => $row) { $i++; // $row = Functions::conv_to_utf8($row);
            if(($i % $mod) == 0) echo "\n $i ";
            if($i == 1) { 
                $fields = explode("\t", $row);
                continue;
            }
            else {
                if(!$row) continue;
                $tmp = explode("\t", $row);
                $rec = array(); $k = 0;
                foreach($fields as $field) { $rec[$field] = @$tmp[$k]; $k++; }
                $rec = array_map('trim', $rec); //print_r($rec); exit("\nstop muna\n");
            }
            if(in_array($task, array('get Plantae EOLids', 'get Chordata EOLids', 'get Arthropoda EOLids', 'get Passeriformes EOLids'))) {
                /*Array(
                    [canonicalName] => Glaucophyceae
                    [EOLid] => 4082
                    [taxonRank] => class
                    [taxonomicStatus] => accepted
                )*/
                $final[$rec['EOLid']] = '';
            }
        }
        if(in_array($task, array('get Plantae EOLids', 'get Chordata EOLids', 'get Arthropoda EOLids', 'get Passeriformes EOLids'))) return $final;
    }
}
?>