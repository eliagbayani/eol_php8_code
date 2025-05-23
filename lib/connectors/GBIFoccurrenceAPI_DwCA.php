<?php
namespace php_active_record;
/* connector: [gbif_georeference_dwca.php]
This script parses the GBIF DwCA occurrence downloads, then creates the individual CSV file per taxon, then generates the map data (.json) using the taxonKey 
that is mapped to EOL's (sciname, taxonConceptID)

1. process the big GBIF occurrence file (DwCA)
1.1. loop through the verbatim.txt -> this has media/image information
1.2. save individual CSV file for each taxon (to be used in 2.3)

2. use taxa list (2-column text file - taxon_concept_id & scientific name) from EoL
2.1. loop through the taxa list
2.2. get taxonkey using scientific name
2.3. use taxonkey to get the occurrence in CSV file (CSV created in 4.2)

curl -X 'GET' \
  'https://api.gbif.org/v1/occurrence/search?acceptedTaxonKey=8084280&hasCoordinate=true&hasGeospatialIssue=false&occurrenceStatus=PRESENT

  // ' \
  -H 'accept: application/json'

curl -X 'GET' \
  'https://api.gbif.org/v1/occurrence/search?acceptedTaxonKey=8084280&associatedSequences=http%3A%2F%2Fwww.ncbi.nlm.nih.gov%2Fnuccore%2FU34853.1&basisOfRecord=PRESERVED_SPECIMEN&bed=Harlem%20coal&catalogNumber=K001275042&classKey=212&collectionCode=F&collectionKey=dceb8d52-094c-4c2c-8960-75e0097c6861&continent=EUROPE&country=AF&crawlId=1&datasetId=https%3A%2F%2Fdoi.org%2F10.1594%2FPANGAEA.315492&datasetKey=13b70480-bd69-11dd-b15f-b8a03c50a862&degreeOfEstablishment=Invasive&dwcaExtension=http%3A%2F%2Frs.tdwg.org%2Fac%2Fterms%2FMultimedia&earliestEonOrLowestEonothem=Mesozoic&earliestEraOrLowestErathem=Proterozoic&earliestPeriodOrLowestSystem=Neogene&earliestEpochOrLowestSeries=Holocene&earliestAgeOrLowestStage=Skullrockian&endDayOfYear=6&establishmentMeans=Native&eventDate=2000%2C2001-06-30&eventId=A%20123&familyKey=2405&fieldNumber=RV%20Sol%2087-03-08&formation=Notch%20Peak%20Formation&gadmGid=AGO.1_1&gadmLevel0Gid=AGO&gadmLevel1Gid=AGO.1_1&gadmLevel2Gid=AFG.1.1_1&gadmLevel3Gid=AFG.1.1.1_1&gbifId=2005380410&gbifRegion=AFRICA&genusKey=2877951&georeferencedBy=Brad%20Millen&geometry=POLYGON%20%28%2830.1%2010.1%2C%2040%2040%2C%2020%2040%2C%2010%2020%2C%2030.1%2010.1%29%29&group=Bathurst&hasCoordinate=true&higherGeography=Argentina&highestBiostratigraphicZone=Blancan&hasGeospatialIssue=false&hostingOrganizationKey=fbca90e3-8aed-48b1-84e3-369afbd000ce&identifiedBy=Allison&identifiedByID=https%3A%2F%2Forcid.org%2F0000-0001-6492-4016&installationKey=17a83780-3060-4851-9d6f-029d5fcb81c9&institutionCode=K&institutionKey=fa252605-26f6-426c-9892-94d071c2c77f&issue=COUNTRY_COORDINATE_MISMATCH&island=Zanzibar&islandGroup=Seychelles&iucnRedListCategory=EX&kingdomKey=5&lastInterpreted=2023-02&latestEonOrHighestEonothem=Proterozoic&latestEraOrHighestErathem=Cenozoic&latestPeriodOrHighestSystem=Neogene&latestEpochOrHighestSeries=Pleistocene&latestAgeOrHighestStage=Boreal&license=CC0_1_0&lifeStage=Juvenile&lowestBiostratigraphicZone=Maastrichtian&member=Lava%20Dam%20Member&modified=2023-02-20&month=5&networkKey=2b7c7b4f-4d4f-40d3-94de-c28b6fa054a6&occurrenceId=URN%3Acatalog%3AUWBM%3ABird%3A126493
  // &occurrenceStatus=PRESENT&orderKey=1448&organismQuantity=1&organismQuantityType=individuals&parentEventId=A%20123&pathway=Agriculture&phylumKey=44&preparations=pinned&previousIdentifications=Chalepidae&programme=BID&projectId=bid-af2020-039-reg&protocol=DWC_ARCHIVE&publishedByGbifRegion=AFRICA&publishingOrg=e2e717bf-551a-4917-bdc9-4fa0f342c530&recordedBy=MiljoStyrelsen&recordedByID=https%3A%2F%2Forcid.org%2F0000-0003-0623-6682&recordNumber=1&sampleSizeUnit=hectares&sampleSizeValue=50.5&samplingProtocol=malaise%20trap&sex=MALE&scientificName=Quercus%20robur&speciesKey=2476674&startDayOfYear=5&stateProvince=Leicestershire&taxonConceptId=8fa58e08-08de-4ac1-b69c-1235340b7001&taxonKey=2476674&taxonId=urn%3Alsid%3Adyntaxa.se%3ATaxon%3A103026&taxonomicStatus=SYNONYM&typeStatus=HOLOTYPE&verbatimScientificName=Quercus%20robur%20L.&waterBody=Lake%20Michigan&year=1998' \
  -H 'accept: application/json'
*/
/* Workspaces for GBIF map tasks:
- GBIFMapDataAPI
- GBIF_map_harvest
- GBIF_SQL_DownloadsAPI
- GBIFTaxonomy */
class GBIFoccurrenceAPI_DwCA //this makes use of the GBIF DwCA occurrence downloads
{
    function __construct()
    {
        /* add: 'resource_id' => "gbif" ;if you want to add cache inside a folder [gbif] inside [eol_cache_gbif] */
        $this->download_options = array(
            'expire_seconds'     => false, //60*60*24*30*3, //ideally 3 months to expire
            'download_wait_time' => 1000000, 
            'timeout' => 60*8, // 8 mins before it timesout
            'download_attempts' => 2, 
            'delay_in_minutes' => 1 //1 minute before it tries again to download after a failed attempt.
        );
        // $this->download_options['expire_seconds'] = false; //debug | true -- expires now

        if(Functions::is_production()) $this->download_options['cache_path'] = "/extra/eol_cache_gbif/";
        else                           $this->download_options['cache_path'] = "/Volumes/Crucial_4TB/eol_cache_gbif/";

        //GBIF services
        $this->gbif_taxon_info      = "http://api.gbif.org/v1/species/match?name="; //http://api.gbif.org/v1/species/match?name=felidae&kingdom=Animalia
        $this->gbif_record_count    = "http://api.gbif.org/v1/occurrence/count?taxonKey=";
        $this->gbif_occurrence_data = "http://api.gbif.org/v1/occurrence/search?taxonKey=";

        $this->gbif_record_count    = "http://api.gbif.org/v1/occurrence/count?hasCoordinate=true&hasGeospatialIssue=false&occurrenceStatus=PRESENT&taxonKey=";
        $this->gbif_occurrence_data = "http://api.gbif.org/v1/occurrence/search?hasCoordinate=true&hasGeospatialIssue=false&occurrenceStatus=PRESENT&taxonKey=";
                                
        /*
        https://api.gbif.org/v1/occurrence/search?acceptedTaxonKey=8084280&hasCoordinate=true&hasGeospatialIssue=false&occurrenceStatus=PRESENT
        from: https://techdocs.gbif.org/en/openapi/v1/occurrence#/Searching%20occurrences/searchOccurrence
        */
        
        $this->html['publisher']    = "http://www.gbif.org/publisher/";
        $this->html['dataset']      = "http://www.gbif.org/dataset/";
        
        if(Functions::is_production()) {
            $this->save_path['taxa_csv_path']     = "/extra/other_files/GBIF_occurrence/GBIF_taxa_csv_dwca/";
            $this->save_path['multimedia_gbifID'] = "/extra/other_files/GBIF_occurrence/multimedia_gbifID/";
            $this->save_path['map_data']          = "/extra/map_data_dwca/";
            // $this->eol_taxon_concept_names_tab    = "/extra/eol_php_code_public_tmp/google_maps/taxon_concept_names.tab"; obsolete
            // $this->eol_taxon_concept_names_tab    = "/extra/other_files/DWH/from_OpenData/EOL_dynamic_hierarchyV1Revised/taxa.txt"; //working but old DH ver.
            $this->eol_taxon_concept_names_tab    = "/extra/other_files/DWH/TRAM-809/DH_v1_1/taxon.tab";    //latest active DH ver.
            
            $this->occurrence_txt_path['Animalia']     = "/extra/other_files/GBIF_occurrence/DwCA_Animalia/occurrence.txt";
            $this->occurrence_txt_path['Plantae']      = "/extra/other_files/GBIF_occurrence/DwCA_Plantae/occurrence.txt";
            $this->occurrence_txt_path['Other7Groups'] = "/extra/other_files/GBIF_occurrence/DwCA_Other7Groups/occurrence.txt";
        }
        else {
            $this->save_path['taxa_csv_path']     = "/Volumes/Crucial_4TB/google_maps/GBIF_taxa_csv_dwca/";
            $this->save_path['multimedia_gbifID'] = "/Volumes/Crucial_4TB/google_maps/multimedia_gbifID/";
            $this->save_path['map_data']          = "/Volumes/Crucial_4TB/google_maps/map_data_dwca/";
            // $this->eol_taxon_concept_names_tab    = "/Volumes/AKiTiO4/eol_pub_tmp/google_maps/JRice_tc_ids/taxon_concept_names.tab"; obsolete
            // $this->eol_taxon_concept_names_tab    = "/Volumes/AKiTiO4/other_files/from_OpenData/EOL_dynamic_hierarchyV1Revised/taxa.txt"; //working but old DH ver.

            // $this->eol_taxon_concept_names_tab = "/Volumes/AKiTiO4/d_w_h/EOL Dynamic Hierarchy Active Version/DH_v1_1/taxon.tab"; //latest active DH ver. --- moved to a faster ssd: Crucial_4TB
            $this->eol_taxon_concept_names_tab = "/Volumes/Crucial_4TB/d_w_h_subset/EOL Dynamic Hierarchy Active Version/DH_v1_1/taxon.tab"; //latest active DH ver.

            $this->occurrence_txt_path['Gadus morhua'] = "/Volumes/AKiTiO4/eol_pub_tmp/google_maps/occurrence_downloads/DwCA/Gadus morhua/occurrence.txt";
            $this->occurrence_txt_path['Lates niloticus'] = "/Volumes/AKiTiO4/eol_pub_tmp/google_maps/occurrence_downloads/DwCA/Lates niloticus/occurrence.txt";
        }
        $this->csv_paths = array();
        $this->csv_paths[] = $this->save_path['taxa_csv_path'];
        
        $this->rec_limit     = 100000; // 100000 ideal for csv downloads --- for early clustering...
        $this->rec_limit_api = 50000; //new 2025: 50000 ideal for API
        $this->limit_20k = 20000; //20000; --- map points limit
        $this->limit_1m = 1000000; //new 2025 for those big big csv records e.g. [Agelaius phoeniceus][45511155] OK GBIF key [9409198] --- 18 million csv records

        $this->api['dataset']      = "http://api.gbif.org/v1/dataset/";      //http://api.gbif.org/v1/dataset/4fa7b334-ce0d-4e88-aaae-2e0c138d049e
        $this->api['organization'] = "http://api.gbif.org/v1/organization/"; //http://api.gbif.org/v1/organization/645eec4e-8d79-4291-80b4-0402b74ba92c
        $this->debug = array();
        
        // For DATA-1818
        $this->listOf_taxa['order']  = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_order_4maps.txt';
        $this->listOf_taxa['family'] = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_family_4maps.txt';
        $this->listOf_taxa['genus']  = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_genus_4maps.txt';
        $this->listOf_taxa['all']    = CONTENT_RESOURCE_LOCAL_PATH . '/listOf_all_4maps.txt';
        $this->auto_refresh_mapYN = false;
        // New 2020 Jun 20
        $this->use_API_YN_old = true;
    }
    function jenkins_call($group, $batches, $connector_task, $filter_rank = '') //4th param $filter_rank is for gen_map_data_forTaxa_with_children() only
    {
        echo "\nCACHE_PATH 01 is ".CACHE_PATH."\n";
        require_once(DOC_ROOT."../LiteratureEditor/Custom/lib/Functions.php");
        require_once(DOC_ROOT."../FreshData/controllers/other.php");
        require_once(DOC_ROOT."../FreshData/controllers/freshdata.php");
        echo "\nCACHE_PATH 02 is ".CACHE_PATH."\n";

        $ctrler = new \freshdata_controller(array());
        ini_set('memory_limit','10096M'); //15096M
        $postfix = "_map_data";

        /* was never used here
        $server_http_host = $_SERVER['HTTP_HOST'];
        $server_script_name = $_SERVER['SCRIPT_NAME'];
        $server_script_name = str_replace("form_result.php", "generate_jenkins.php", $server_script_name);
        $params['uuid'] = pathinfo($newfile, PATHINFO_FILENAME);
        //always use DOC_ROOT so u can switch from jenkins to cmdline. BUT DOC_ROOT won't work here either since /config/boot.php is not called here. So use $for_DOC_ROOT instead.
        */
        
        // echo "<pre>"; print_r($parameters); echo "</pre>"; exit;
        $ctr = 0;
        foreach($batches as $batch) {
            $ctr++;
            print_r($batch);
            $param = array();
            $param['group'] = $group;
            $param['range'] = $batch;
            $param['ctr'] = $ctr;
            $param['rank'] = $filter_rank;
            
            if($connector_task == "breakdown_GBIF_DwCA_file")                   $job_str = "map_data_break_".substr($group,0,3)."_job"; // 'Ani' 'Pla' 'Oth'
            elseif($connector_task == "generate_map_data_using_GBIF_csv_files") $job_str = "map_data_job";
            elseif($connector_task == "gen_map_data_forTaxa_with_children")     $job_str = "map_data_ch_".substr($param['rank'],0,3)."_job"; //map_data_ch_gen_job_4
            
            $task = $ctrler->get_available_job($job_str);
            $json = json_encode($param, true);
            $params['uuid'] = time();

            if    ($connector_task == "breakdown_GBIF_DwCA_file")               $cmd = PHP_PATH.' breakdown_GBIF_DwCA_file.php jenkins ' . "'" . $json . "'";
            elseif($connector_task == "generate_map_data_using_GBIF_csv_files") $cmd = PHP_PATH.' generate_map_data_using_GBIF_csv_files.php jenkins ' . "'" . $json . "'";
            elseif($connector_task == "gen_map_data_forTaxa_with_children")     $cmd = PHP_PATH.' gen_map_data_forTaxa_with_children.php jenkins ' . "'" . $json . "'";
            // echo "\n$cmd\n";
            
            // /* works well locally but bit problematic in eol-archive, will abandon for a while. Works OK now, as of Apr 25, 2019.
            $cmd .= " 2>&1";
            $ctrler->write_to_sh($params['uuid'].$postfix, $cmd);
            $cmd = $ctrler->generate_exec_command($params['uuid'].$postfix); //pass the desired basename of the .sh filename (e.g. xxx.sh then pass "xxx")
            $c = $ctrler->build_curl_cmd_for_jenkins($cmd, $task);
            $shell_debug = shell_exec($c);
            // for more debugging...
            // echo "\ncmd: $cmd
            //       \nc: $c";
            // echo "\nshell_debug: [$shell_debug]";

            // break; //debug only -- just run 1 batch
            echo "\nCACHE_PATH 03 is ".CACHE_PATH."\n";
            sleep(20); //this is important so Jenkins will detect that the first job is already taken and will use the next available job. Effective works OK
            // */
        }
    }
    private function total_occurrence_rows_per_group($group)
    {
        /* source: https://stackoverflow.com/questions/3137094/how-to-count-lines-in-a-document */
        $total = shell_exec("wc -l < ".escapeshellarg($this->occurrence_txt_path[$group]));
        $total = trim($total);  echo "\n[$total]\n";
        // $total = 50; //debug force assign
        $total = $total + 10;   echo "\n$group occurrence: [$total]\n"; //just a buffer of +10
        return $total;
    }
    function get_range_batches($group, $divisor, $total = false)
    {
        if(!$total) $total = self::total_occurrence_rows_per_group($group);
        $batch = $total/$divisor;
        $batch = ceil($batch);
        for ($x = 1; $x <= $total; $x=$x+$batch) $final[] = array($x, $x+$batch);
        return $final;
    }
    function start() //this start() function will not be used in eol-archive Jenkins run
    {
        // /* tests
        self::process_all_eol_taxa_using_DH(false, true); return;
        exit("\nend test\n");
        // */
        
        /* tests
        $datasetKey = "0e7bd6f7-7fc6-4150-a531-2209f7156a91";
        $datasetKey = "492d63a8-4978-4bc7-acd8-7d0e3ac0e744";
        $str = self::get_dataset_field($datasetKey, 'title');
        echo "\ndataset: [$str]\n";
        $orgKey = self::get_dataset_field($datasetKey, 'publishingOrganizationKey');
        $dataset_name = self::get_dataset_field($datasetKey, 'title');
        echo "\norg key: [$orgKey]\n";
        echo "\ndataset name: [$dataset_name]\n";
        exit("\n-end tests-\n");
        */
        
        /* Steps (August 2018) using the DwCA occurrence downloads from GBIF */
        // Step 1. Delete all 3 groups of files: csv, txt, json files. Recursive all directories.
            /* Working OK - will put in Jenkins shell script:
            Remove all CSV files (.csv):
            rm -rf /extra/other_files/GBIF_occurrence/GBIF_taxa_csv_dwca/*
            Remove multimedia assignments to taxon (.txt):
            rm -rf /extra/other_files/GBIF_occurrence/multimedia_gbifID/*
            Remove all map data (.json)
            rm -rf /extra/map_data_dwca/*
            */
        // Step 2. 
            self::breakdown_GBIF_DwCA_file(); echo "\nDONE: breakdown_GBIF_DwCA_file()\n";                              //return; //IMPORTANT: this can only be run once every harvest
        // Step 3. 
            self::breakdown_multimedia_to_gbifID_files();   echo "\nDONE: breakdown_multimedia_to_gbifID_files()\n";    //return; //took 18 mins in eol-archive
        // Step 4. 
            self::generate_map_data_using_GBIF_csv_files(); echo "\nDONE: generate_map_data_using_GBIF_csv_files()\n";
            if($this->debug) Functions::start_print_debug($this->debug, "gen_map_data_via_gbif_csv");                   //return;
            /*This step includes using API if DwCA (csv file) for taxon is not available */
        // Step 5. Finalize list of taxon:
            self::save_ids_to_text_from_many_folders();
        
        //---------------------------------------------------------------------------------------------------------------------------------------------
        /* obsolete since the JRice text file is very old, old tc_ids
        self::process_all_eol_taxa(false, false); return;   //make use of tab-delimited text file from JRice
        */
        //---------------------------------------------------------------------------------------------------------------------------------------------

        /* testing...
        $scinames = array(); //make use of manual taxon list
        $scinames["Gadus morhua"] = 206692;
        $scinames["Gadidae"] = 5503;
        $scinames["Animalia"] = 1;
        foreach($scinames as $sciname => $taxon_concept_id) self::main_loop($sciname, $taxon_concept_id);
        */
    }
    //##################################### start DwCA process ###########################################################################################################################
    function breakdown_multimedia_to_gbifID_files()
    {   return; //obsolete
        $path2 = $this->save_path['multimedia_gbifID'];
        if(Functions::is_production()) {
            $paths[] = "/extra/other_files/GBIF_occurrence/DwCA_Other7Groups/multimedia.txt";
            $paths[] = "/extra/other_files/GBIF_occurrence/DwCA_Plantae/multimedia.txt";
            $paths[] = "/extra/other_files/GBIF_occurrence/DwCA_Animalia/multimedia.txt";
        }
        else $paths[] = "/Volumes/AKiTiO4/eol_pub_tmp/google_maps/occurrence_downloads/DwCA/Gadus morhua/multimedia.txt";
        foreach($paths as $path) {
            $i = 0;
            foreach(new FileIterator($path) as $line_number => $line) { // 'true' will auto delete temp_filepath
                $i++; if(($i % 100000) == 0) echo "\n [$path] ".number_format($i) . " ";
                if($i == 1) $line = strtolower($line);
                $row = explode("\t", $line);
                if($i == 1) {
                    $fields = $row;
                    continue;
                }
                else {
                    if(!@$row[0]) continue; //$row[0] is gbifID
                    $k = 0; $rec = array();
                    foreach($fields as $fld) {
                        $rec[$fld] = $row[$k];
                        $k++;
                    }
                }
                // print_r($rec); exit("\nstopx\n");
                /* Array(
                    [gbifid] => 1883941229
                    [type] => StillImage
                    [format] => image/jpeg
                    [identifier] => https://static.inaturalist.org/photos/21812110/original.jpeg?1532308417
                    [references] => https://www.inaturalist.org/photos/21812110
                    [title] => 
                    [description] => 
                    [created] => 2018-07-21T20:30Z
                    [creator] => mkkennedy
                    [contributor] => 
                    [publisher] => iNaturalist
                    [audience] => 
                    [source] => 
                    [license] => http://creativecommons.org/licenses/by-nc/4.0/
                    [rightsholder] => mkkennedy
                )*/
                $gbifid = $rec['gbifid'];
                if($rec['type'] == "StillImage" && $rec['format'] != "image/tiff" && $rec['identifier']) {
                    $path3 = self::get_md5_path($path2, $gbifid);
                    $txt_file = $path3 . $gbifid . ".txt";
                    if(!file_exists($txt_file)) { //take note: only one image is saved per gbifID
                        $fhandle = Functions::file_open($txt_file, "w");
                        fwrite($fhandle, $rec['identifier'] . "\n"); fclose($fhandle);
                    }
                }
            }//end loop text file
        }//end foreach($paths)
    }
    /* commented Feb 27, 2025
    function breakdown_GBIF_DwCA_file($group = false, $range_from = false, $range_to = false) //e.g. $group = 'Animalia'
    {
        // exit("\nFinished running Aug 23, 2018\n");
        // exit("\nFinished running Jun 08-13, 2020\n"); //IMPORTANT: run only once every harvest
        $path2 = $this->save_path['taxa_csv_path'];
        if(Functions::is_production()) {
            if($group) {
                $paths[] = $this->occurrence_txt_path[$group];
            }
            else { //this means a long run, several days. Not distributed.
                $paths[] = $this->occurrence_txt_path['Animalia'];        //~717 million - Took 3 days 15 hr (when API calls are not yet cached)
                $paths[] = $this->occurrence_txt_path['Plantae'];         //~183 million - Took 1 day 19 hr (when API calls are not yet cached)
                $paths[] = $this->occurrence_txt_path['Other7Groups'];    //~25 million - Took 5 hr 10 min (when API calls are not yet cached)
            }
        }
        else $paths[] = $this->occurrence_txt_path[$group];
        foreach($paths as $path) {
            $i = 0;
            foreach(new FileIterator($path) as $line_number => $line) { // 'true' will auto delete temp_filepath
                $i++;
                // if(($i % 500000) == 0) echo "\n".number_format($i) . "[$path]"; //good debug, but transferred below
                if($i == 1) $line = strtolower($line);
                $row = explode("\t", $line);
                if($i == 1) {
                    $fields = $row;
                    continue;
                }
                else {
                    // [0] => 1        [1] => 47416
                    // [0] => 47416    [1] => 94831
                    // new ranges ----------------------------------------------------
                    if($range_from && $range_to) {
                        $cont = false;
                        if($i >= $range_from && $i < $range_to) $cont = true;
                        if(!$cont) continue;
                        
                        //newly added:
                        if($i >= $range_to) {
                            echo "\nHave now reached upper limit [$range_to]. Will end loop\n";
                            break;
                        }
                    }
                    // ----------------------------------------------------
                    if(!@$row[0]) continue; //$row[0] is gbifID
                    $k = 0; $rec = array();
                    foreach($fields as $fld) {
                        $rec[$fld] = $row[$k];
                        $k++;
                    }
                }
                if(($i % 500000) == 0) echo "\n".number_format($i) . "[$path]";
                // print_r($rec); exit("\nstopx\n");
                if(!@$rec['taxonkey']) continue;
                $taxonkey = $rec['taxonkey'];
                // echo "\n".$rec['datasetkey']."\n";
                $rec['publishingorgkey'] = self::get_dataset_field($rec['datasetkey'], 'publishingOrganizationKey');

                $rek = array($rec['gbifid'], $rec['datasetkey'], $rec['scientificname'], $rec['publishingorgkey'], $rec['decimallatitude'], $rec['decimallongitude'], $rec['eventdate'], 
                $rec['institutioncode'], $rec['catalognumber'], $rec['identifiedby'], $rec['recordedby']);
                if($rec['decimallatitude'] && $rec['decimallongitude']) {
                    $path3 = self::get_md5_path($path2, $taxonkey);
                    $csv_file = $path3 . $taxonkey . ".csv";
                    if(!file_exists($csv_file)) {
                        //order of fields here is IMPORTANT: will use it when accessing these generated individual taxon csv files
                        $str = 'gbifid,datasetkey,scientificname,publishingorgkey,decimallatitude,decimallongitude,eventdate,institutioncode,catalognumber,identifiedby,recordedby';
                        $fhandle = Functions::file_open($csv_file, "a");
                        fwrite($fhandle, implode("\t", explode(",", $str)) . "\n");
                        fclose($fhandle);
                    }
                    $fhandle = Functions::file_open($csv_file, "a");
                    fwrite($fhandle, implode("\t", $rek) . "\n");
                    fclose($fhandle);
                }
            } //end foreach()
        } //end loop paths
    } */
    function get_dataset_field($datasetKey, $return_field)
    {
        $options = $this->download_options;
        $options['expire_seconds'] = false; //should always be false, unless dataset info changes alot. e.g. http://api.gbif.org/v1/dataset/e9b63688-ed8d-4be8-aa35-89646d887a5e
        if($datasetKey && $json = Functions::lookup_with_cache($this->api['dataset'].$datasetKey, $options)) {
            $obj = json_decode($json);
            if(!isset($obj->$return_field)) { //debug only
                print_r($obj);
                exit("\nInvestigate 1: [$datasetKey]: ".$this->api['dataset'].$datasetKey."\n");
            }
            return $obj->$return_field;
        }
    }
    function get_organization_field($organizationKey, $return_field)
    {
        $options = $this->download_options;
        $options['expire_seconds'] = false; //should always be false, unless organization info changes alot
        if($organizationKey && $json = Functions::lookup_with_cache($this->api['organization'].$organizationKey, $options)) {
            $obj = json_decode($json);
            if(!isset($obj->$return_field)) { //debug only
                print_r($obj);
                exit("\nInvestigate 1: [$organizationKey]: ".$this->api['organization'].$organizationKey."\n");
            }
            return $obj->$return_field;
        }
    }

    //##################################### end DwCA process #############################################################################################################################
    //==========================
    // start GBIF methods
    //==========================
    function get_md5_path($path, $taxonkey)
    {
        $md5 = md5($taxonkey);
        $cache1 = substr($md5, 0, 2);
        $cache2 = substr($md5, 2, 2);
        if(!file_exists($path . $cache1)) mkdir($path . $cache1);
        if(!file_exists($path . "$cache1/$cache2")) mkdir($path . "$cache1/$cache2");
        return $path . "$cache1/$cache2/";
    }
    function x_gen_map_data_forTaxa_with_children($sciname = false, $tc_id = false, $range_from = false, $range_to = false, $filter_rank = '')
    {
        $this->use_API_YN_old = false; //no more API calls at this point.
        require_library('connectors/DHConnLib'); $func = new DHConnLib('');
        $paths = $this->csv_paths; 
        
        if($sciname && $tc_id) {
            $eol_taxon_id_list[$sciname] = $tc_id; print_r($eol_taxon_id_list); 
            self::create_map_data_include_descendants($sciname, $tc_id, $paths, $func); //result of refactoring
            return;
        }
        
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
        $local = Functions::save_remote_file_to_local($this->listOf_taxa[$filter_rank], $options);
        $i = 0; $found = 0;
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
            
            //  new ranges ---------------------------------------------
            if($range_from && $range_to) {
                $cont = false;
                if($i >= $range_from && $i < $range_to) $cont = true;
                if(!$cont) continue;
            }
            //  --------------------------------------------------------
            echo "\n$i of $range_to. [".$rec['canonicalName']."][".$rec['EOLid']."]";
            self::create_map_data_include_descendants($rec['canonicalName'], $rec['EOLid'], $paths, $func); //result of refactoring
        }
        unlink($local);
    }
    private function get_json_map_data($basename)
    {
        $filename = self::get_map_data_path($basename).$basename.".json"; // echo "\n$filename\n";
        // if($GLOBALS['ENV_DEBUG']) echo "\nmap file: [$filename]\n";
        if(file_exists($filename)) {
            if(filesize($filename) > 0) {
                // if($GLOBALS['ENV_DEBUG']) echo "[$basename] has map data (.json) [$filename]";
                $json = file_get_contents($filename);
                $json = str_replace('var data = ', "", $json);
                return $json;
            }
        }
        return false;
    }
    function create_map_data_include_descendants($sciname, $taxon_concept_id, $paths, $func)
    {
        /* step 1: get children of taxon_concept_id */
        $json = $func->get_children_from_json_cache($taxon_concept_id, array(), false); //3rd param false means it will not generate children if it doesn't exist. Generation happens in DHConnLib.php
        $children = json_decode($json, true);
        echo "\nChildren: "; print_r($children);
        debug("\nNo. of children: ".count($children)."\n"); //exit("\nstop 3\n");
        
        /* step 2: refresh map data of $taxon_concept_id. Important: since the current ver. is the cumulated-from-children version. */
        $this->auto_refresh_mapYN = true;
        self::generate_map_data_using_GBIF_csv_files($sciname, $taxon_concept_id); //goes to local version
        // exit("\nexit muna\n");
        $this->auto_refresh_mapYN = false;
        
        /* step 3: loop to all children (include taxon in question), consolidate map data. Then save to json file. */
        $children[] = $taxon_concept_id; $total_children = count($children); $i = 0; $modulo = self::get_proper_modulo($total_children);
        $final = array(); $sep = "\n -";
        foreach($children as $child) { $i++; if(($i % $modulo) == 0) echo "$sep $child $i of $total_children ";
            $sep = " |";
            if($json = self::get_json_map_data($child)) {
                $arr = json_decode($json, true); // print_r($arr);
                echo "\n[$child] - ".count(@$arr['records']); //good debug
                if($val = @$arr['records']) {
                    $final = array_merge($final, $val);
                    if(count($final) > $this->rec_limit) $final = self::process_revised_cluster(array('count' => count($final), 'records' => $final), $taxon_concept_id, true, 'a'); //3rd param true means 'early cluster'
                }
            }
            // else echo "\n[$child] - no map data";
        }
        if($final) {
            $final2 = array();
            $final2['records'] = $final;
            $final2['count'] = count($final);
            $final2['actual'] = count($final);
            debug("\nFinal [$taxon_concept_id] - ".count(@$final2['records'])."\n");
            self::if_needed_2cluster_orSave($final2, $taxon_concept_id);
        }
        return;
    }
    private function generate_map_data_using_GBIF_csv_files($sciname = false, $tc_id = false, $range_from = false, $range_to = false, $autoRefreshYN = false)
    {   
        echo "\nsciname: [$sciname]";
        echo "\ntc_id: [$tc_id]";
        echo "\nautoRefreshYN: [$autoRefreshYN]";
        // exit("\n--- So this is still being used ---\n");

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
        // $eol_taxon_id_list["Gadidae"] = 5503;
        $eol_taxon_id_list["Hyperiidae"] = 1180;
        // $eol_taxon_id_list["Decapoda"] = 1183;
        // $eol_taxon_id_list["Proterebia keymaea"] = 137680; //csv map data not available from DwCA download
        // $eol_taxon_id_list["Aichi virus"] = 540501;
        */
        
        $paths = $this->csv_paths; //print_r($paths);
        if($sciname && $tc_id) {
            $eol_taxon_id_list[$sciname] = $tc_id; //print_r($eol_taxon_id_list);
            self::create_map_data($sciname, $tc_id, $paths); //result of refactoring
            return;
        }

        /* used FileIterator below instead, to save on memory
        $i = 0;
        foreach($eol_taxon_id_list as $sciname => $taxon_concept_id) {
            $i++;
            // new ranges ---------------------------------------------
            if($range_from && $range_to) {
                $cont = false;
                if($i >= $range_from && $i < $range_to) $cont = true;
                if(!$cont) continue;
            }
            // --------------------------------------------------------
            echo "\n$i. [$sciname][$taxon_concept_id]";
            self::create_map_data($sciname, $taxon_concept_id, $paths); //result of refactoring
        }
        */
        $options = $this->download_options;
        $options['expire_seconds'] = 60*60*24*30; //1 month expires
        $local = Functions::save_remote_file_to_local($this->listOf_taxa['all'], $options);
        $i = 0;
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
            //  new ranges ---------------------------------------------
            if($range_from && $range_to) {
                $cont = false;
                if($i >= $range_from && $i < $range_to) $cont = true;
                if(!$cont) continue;
            }
            //  --------------------------------------------------------
            echo "\n$i of $range_to. [".$rec['canonicalName']."][".$rec['EOLid']."]";
            self::create_map_data($rec['canonicalName'], $rec['EOLid'], $paths); //result of refactoring
        }
        unlink($local);
    }
    private function if_needed_2cluster_orSave($final, $taxon_concept_id)
    {
        $final['tc_id'] = $taxon_concept_id; //for debug only
        if($final['count'] > $this->limit_20k) {
            debug(" --- ".$final['count']." > 20K\n");
            self::process_revised_cluster($final, $taxon_concept_id, false, 'b'); //done after main demo using screenshots
        }
        elseif($final['count'] <= $this->limit_20k) {
            debug(" --- ".$final['count']." <= 20K\n");
            $final['actual'] = $final['count'];
            self::save_json_file($taxon_concept_id, $final);
        }
    }
    function create_map_data($sciname, $taxon_concept_id, $paths)
    {
        echo "\nuse_API_YN: [$this->use_API_YN]\n"; //value came from GBIFMapDataAPI.php

        // /* normal operation - use CSV first then API if no map data yet
        if($this->run_species_level) {
            $this->auto_refresh_map_CSV_YN = false;
            $this->auto_refresh_map_API_YN = false;    
        }
        else { // run higher-level taxa
            $filename = self::get_map_data_path($taxon_concept_id).$taxon_concept_id.".json";
            if(file_exists($filename)) {
                if(unlink($filename)) echo "\nDeleted/initialized coz we will be adding children records as well [$filename]\n";
            }
            $this->auto_refresh_map_CSV_YN = true;
            $this->auto_refresh_map_API_YN = false;    
        }
        // */

        if($usageKey = self::get_usage_key($sciname)) { debug("\nOK GBIF key [$usageKey]\n");

            // /* normal operation
            if(!$this->auto_refresh_map_CSV_YN) {
                if(self::map_data_file_already_been_generated($taxon_concept_id)) return; //continue; //before 'continue' was used since it is inside the loop above
            }
            // */

            if($final = self::prepare_csv_data($usageKey, $paths)) {
                // echo "\n=======================\n";
                // print_r($final); 
                // print_r(array_keys($final));
                // echo "\n=======================\n";
                debug("\nUsed records from CSV: [$sciname][$taxon_concept_id][$usageKey] " . $final['count'] . "");
                self::if_needed_2cluster_orSave($final, $taxon_concept_id);
            }
            else {
                /* new: May 30, 2022 - don't use API if csv doesn't provide map data.
                debug("\nCSV map data not available [$sciname][$taxon_concept_id]...");
                $this->debug['CSV map data not available']["[$sciname][$taxon_concept_id]"] = '';
                self::gen_map_data_using_api($sciname, $taxon_concept_id);
                */
                // $this->debug['no CSV data']["[$sciname][$taxon_concept_id][$usageKey]"] = ''; //not needed
                echo "\nNo CSV data: [$sciname][$taxon_concept_id][$usageKey]\n";

                if(!$this->use_API_YN) {
                    echo "\nNo more API calls at this point.\n"; //for higher level taxa
                    return;
                }
                else echo "\nWill try to use API...\n";

                // /* ---------- 2025 block
                // IMPORTANT: If u disable this block and no map data from CSV then no map data will be generated. 
                // After that if u decide to enable this block then that's the time it will call the API calls for this taxon, and generate map data using API results.
                echo "\nWill use API for: [$sciname][$taxon_concept_id][$usageKey]\n";
                if(!$this->auto_refresh_map_API_YN) {
                    if(self::map_data_file_already_been_generated($taxon_concept_id)) return;
                }    
                $num = self::get_georeference_data_via_api($usageKey, $taxon_concept_id);
                if($num) {
                    $this->debug['no CSV data but with API']['genus'][self::get_genus($sciname)] = '';
                    $this->debug['no CSV data but with API']['usageKey'][$usageKey] = '';
                    $this->debug['no CSV data but with API']['sciname'][$sciname] = '';
                    // print_r($this->debug); exit("\nhuli ka\n"); //debug only
                }
                // ---------- */
            }
        }
        else {
            echo "\n usageKey not found! [$sciname][$taxon_concept_id]\n";
            $this->debug['usageKey not found']["[$sciname][$taxon_concept_id]"] = '';
        }
    }
    /* not used anymore, since May 30, 2022
    private function gen_map_data_using_api($sciname, $taxon_concept_id) //NEW Aug 24, 2018
    {
        if($this->use_API_YN_old) {
            debug("\nWill try to use API...");
            if($rec = self::get_initial_data($sciname)) { // print_r($rec);
                echo " -- usageKey: ".$rec['usageKey']." | count: ". $rec["count"];
                self::get_georeference_data_via_api($rec['usageKey'], $taxon_concept_id);
            }
        }
    } */
    function get_georeference_data_via_api($taxonKey, $taxon_concept_id) //updated from original version
    {   echo "\nUsing the API...\n";
        $options = $this->download_options;
        $options['download_wait_time'] = 500000; //never use bigger than 1 sec here.
        $offset = 0; $limit = 300; $continue = true; $final = array(); echo "\n";
        $final['records'] = array();
        while($continue) {
            if($offset > $this->rec_limit_api) break; //working... uncomment if u want to limit to 100,000
            
            // /* new: Mar 5, 2025
            if(($offset + $limit) > 100001) $limit = 100001 - $offset;
            // */

            // if($offset > 50000) break; //debug only --- during development only - COMMENT IN REAL OPERATION
            $url = $this->gbif_occurrence_data . $taxonKey . "&limit=$limit";
            if($offset) $url .= "&offset=$offset";
            if($json = Functions::lookup_with_cache($url, $options)) {
                $j = json_decode($json);
                if(!is_object($j)) {
                    $offset += $limit;
                    continue;
                }
                $recs = self::write_to_file($j); //ngayon lang commented
                $final['records'] = array_merge($final['records'], $recs);
                debug(" increments: " . count($recs) . "");
                if($j->endOfRecords)                                $continue = false;
                if(count($final['records']) > $this->rec_limit_api) $continue = false; //limit no. of markers in Google maps is 100K //working... uncomment if u want to limit to 100,000
            }
            else break; //just try again next time...
            $offset += $limit;
        }
        $final['count']  = count($final['records']);
        $final['actual'] = count($final['records']);
        if($final['count'] > 0) debug("\n: Found in API: " . $final['count'] . " -- ");
        else                    debug("\n: Nothing found in API -- ");
        self::if_needed_2cluster_orSave($final, $taxon_concept_id);
        return $final['count']; //only for stats report
    }
    private function process_revised_cluster($final, $basename, $early_cluster = false, $whoCalled) //4th param $whoCalled is just for debug.
    {
        // study running 'a'
        // Used records from CSV: [Ammodramus savannarum][45511206][2491123] 30898
        // why seems a duplicate clustering routine...

        $usage_ki = @$final['usageKey']; //just for debug
        $tc_aydi = @$final['tc_id']; //just for debug

        // echo "\nAAA count: ".@$final['count']."";
        // echo "\nAAA total: ".@$final['total']."\n";

        if($early_cluster) debug("\nStart of early cluster [$whoCalled]...");
        else               debug("\nStart with revised cluster [$whoCalled]");
        echo "\nInitial total: [$usage_ki][$tc_aydi] ".count($final['records']);
        $to_be_saved = array();
        $to_be_saved['records'] = array();
        $unique = array();
        $decimal_places = 6;
        while(true) {
            foreach($final['records'] as $r) {
                if(is_numeric($r['h']) && is_numeric($r['i'])) {
                    $lat = number_format($r['h'], $decimal_places);
                    $lon = number_format($r['i'], $decimal_places);
                    // /* orig: change this block if u want to prioritize recs with rec['l'] meaning with media stillimage movingimage or audio
                    if(isset($unique["$lat,$lon"])) continue;
                    else $unique["$lat,$lon"] = '';
                    // */
                    $to_be_saved['records'][] = $r;
                }
            }
            echo "\n New total [$decimal_places]: " . count($unique) . "";
            $limit_to_break = $this->limit_20k;
            if($basename == 281) $limit_to_break = 35000; //Plantae 34131
            // /* NEW: Feb 26, 2025
            if($decimal_places == 1) break; //this is the least decimal places value
            // */
            if(count($to_be_saved['records']) < $limit_to_break || $decimal_places == 0) break; //orig value is 0, not 1
            else {   //initialize vars
                $decimal_places--;
                $to_be_saved = array();
                $to_be_saved['records'] = array();
                $unique = array();
            }
        } //end while()
        
        //flag if after revised cluster is still unsuccessful
        if(count($unique) > $limit_to_break) {
            debug("\ntaxon_concept_ID/gbifID [$basename] revised cluster unsuccessful [$early_cluster YN] [".count($unique)."]\n"); //gbifID is only for early clustering
            echo "\nearly_cluster = [".self::format_YN($early_cluster)."]\n";
            $fhandle = Functions::file_open(CONTENT_RESOURCE_LOCAL_PATH . "/revised_cluster_unsuccessful.txt", "a");
            fwrite($fhandle, "$basename" . "\t" . count($unique) ."\t". date('Y-m-d') . "\n"); fclose($fhandle);
            
            //start force-get only the first 20k records
            $to_be_saved = self::force_reduce_records($to_be_saved);
            debug("\n Final total after force_reduce_records() [$decimal_places]: " . count($to_be_saved['records']) . "\n");

            $to_be_saved['count'] = count($to_be_saved['records']); //the smaller value; the bigger one is $to_be_saved['actual']
            $to_be_saved['actual'] = $final['count'];
            echo "\nearly_cluster A: [".self::format_YN($early_cluster)."]\n";
            if(!$early_cluster) self::save_json_file($basename, $to_be_saved);
            else return $to_be_saved['records'];
        }
        else {
            $to_be_saved_records_count = count($to_be_saved['records']);
            debug("\n Final total [$decimal_places]: " . count($unique) . "");
            debug(" to_be_saved_records: ".$to_be_saved_records_count."\n");
            $to_be_saved['count'] = $to_be_saved_records_count; //the smaller value; the bigger one is $to_be_saved['actual']
            $to_be_saved['actual'] = $final['count'];
            echo "\nearly_cluster B: [".self::format_YN($early_cluster)."]\n";
            if(!$early_cluster) {
                $to_be_saved = self::add_recs_from_original_if_needed($to_be_saved, $final['records']);
                self::save_json_file($basename, $to_be_saved);
            }
            else return $to_be_saved['records'];
        }
    }
    private function save_json_file($tc_id, $rec)
    {
        if(@$rec['records']) $rec = self::run_lookups_now($rec, 2); //for API and CSV --- should be the only place for lookups! or does it?

        if($rec['count'] > 0) {
            $filename = self::get_map_data_path($tc_id).$tc_id.".json";
            debug(" -> saving json... recs: ".$rec['count']. " [$filename]");
            $json = json_encode($rec, JSON_UNESCAPED_SLASHES);
            if(!($file = Functions::file_open($filename, "w"))) return;
            /* used for the longest time
            fwrite($file, "var data = ".$json);
            */
            fwrite($file, $json); //now real json-value
            fclose($file);
            echo " - map data saved. [$tc_id] n = ".$rec['count']."\n";
        }
    }
    private function get_map_data_path($taxon_concept_id)
    {
        $folder = $taxon_concept_id % 100;
        $path = $this->save_path['map_data']."/".$folder."/";
        if(!is_dir($path)) mkdir($path);
        return $path;
    }
    private function get_map_data_path_v2($taxon_concept_id) //to be implemented after last harvest
    {
        $taxon_concept_id = Functions::format_number_with_leading_zeros($taxon_concept_id, 4); //1 becomes 0001 | 13 becomes 0013 | 236 becomes 0236
        $md5 = $taxon_concept_id;       //from original copied template -> $md5 = md5($taxon_concept_id)
        $cache1 = substr($md5, 0, 2);
        $cache2 = substr($md5, 2, 2);
        $cache_path = $this->save_path['map_data']."/";
        if(!file_exists($cache_path . $cache1))           mkdir($cache_path . $cache1);
        if(!file_exists($cache_path . "$cache1/$cache2")) mkdir($cache_path . "$cache1/$cache2");
        return $cache_path . "$cache1/$cache2/";
    }
    private function prepare_csv_data($usageKey, $paths)
    {
        $final = array(); $elix = 0;
        $main_total = 0; //new 2025
        foreach($paths as $path) {
            $final_path = self::get_md5_path($path, $usageKey);
            $csv = $final_path . $usageKey . ".csv";
            if(file_exists($csv)) {                
                debug("\nusageKey = [$usageKey] found in [$csv]");
                // $file_array = file($csv);
                $gbif_ids = array(); $i = 0;
                foreach(new FileIterator($csv) as $line => $rowx) { $line = $rowx;
                // foreach($file_array as $line) {
                    $i++;
                    $row = explode("\t", $line);
                    if($i == 1) {
                        $fields = $row;
                        continue;
                    }
                    else {
                        if(!@$row[1]) continue;
                        $k = 0; $rek = array();
                        foreach($fields as $fld) {
                            $rek[$fld] = $row[$k];
                            $k++;
                        }
                        $rek = array_map('trim', $rek);
                        // print_r($rek); exit;
                    }
                    
                    //make record unique
                    $gbifid = $rek['gbifid'];
                    if(isset($gbif_ids[$gbifid])) continue;
                    else $gbif_ids[$gbifid] = '';
                    
                    if($rek['decimallatitude'] && $rek['decimallongitude']) {}
                    else continue;
                    
                    $rec = array();
                    $rec['a']   = $rek['catalognumber'];
                    $rec['b']   = $rek['scientificname'];

                    /* new 2025: these can be postponed
                    $rec['c']   = self::get_org_name(@$rek['publishingorgkey']);
                    $rec['d']   = @$rek['publishingorgkey'];
                    if($val = @$rek['institutioncode']) $rec['c'] .= " ($val)";
                    */
                    $rec['c'] = 'nyc';
                    $rec['d'] = 'nyc';

                    // /* ----- new 2025: postpone assignment of letter e since not all records that pass here will be used.
                    // $rec['e']   = self::get_dataset_field(@$rek['datasetkey'], 'title');
                    $rec['e'] = 'nyc';
                    // ----- */    
                    $rec['f']   = @$rek['datasetkey'];
                    $rec['g']   = $rek['gbifid'];
                    $rec['h']   = $rek['decimallatitude'];
                    $rec['i']   = $rek['decimallongitude'];
                    $rec['j']   = @$rek['recordedby'];
                    $rec['k']   = @$rek['identifiedby'];

                    /* orig but obsolete
                    $rec['l']   = self::get_media_by_gbifid($gbifid);
                    */
                    // /* 2025
                    $rec['l']   = self::format_media_2025(@$rec['v_associatedmedia']);
                    // */

                    $rec['m']   = @$rek['eventdate'];
                    /* to have map data with only those with media
                    if($rec['l']) $final['records'][] = $rec;
                    */
                    $final['records'][] = $rec;
                    $main_total++;
                    
                    /* new - WORKS BUT DOES NOT USE early clustering
                    if(count($final['records']) > $this->rec_limit) {
                        $final['count'] = count($final['records']);
                        return $final;
                    }
                    */
                    
                    /* As of 31Mar2025 doing an early cluster here is beneficial. Should remain as is. */
                    // /* PROBABLY WE CAN TRY early cluster here. Early clustering may provide a better spread of coordinates.
                    if(count($final['records']) > $this->rec_limit) { //for early clustering, the taxon_concept_id or gbifID is irrelevant bec. you're not saving json file yet.
                        $final['records'] = self::process_revised_cluster(array('count' => count($final['records']), 'records' => $final['records'], 'usageKey' => $usageKey), $gbifid."_gbifID", true, 'c'); //3rd param true means 'early cluster'
                        $elix = 0;                        
                        echo "\nmain_total: [$main_total]\n";
                    }
                    // */
                    @$elix++;

                    if($main_total >= $this->limit_1m) break; //new 2025 for those big big csv records e.g. [Agelaius phoeniceus][45511155] OK GBIF key [9409198] --- 18 million records

                } //inner foreach()
                $final['count'] = count($final['records']);
            }
            else debug("\n[$usageKey] NOT found in [$path]");
        } //outer foreach()

        echo "\nLast records to add: [".@$elix."]\n";
        // echo "\nCCC count: ".@$final['count']."";
        // echo "\nCCC total: ".@$final['total']."\n";

        /* correct to comment this since lookups are done in 1 place only: save_json_file()
        if(@$final['records']) $final = self::run_lookups_now($final, 1); //for csv download
        */

        // print_r($final); exit("\nelix 2025\n");
        // echo "\nBBB count: ".@$final['count']."";
        // echo "\nBBB total: ".@$final['total']."\n";
        return $final;
    }
    /*
    375977 of . [Gersemia rubiformis][46547909]
    OK GBIF key [2263181]
    :: [update_resources/connectors/gbif_map_data.php [29]]<br>
    map file: [/Volumes/Crucial_4TB/google_maps/map_data_dwca//9/46547909.json]
    */
    private function format_media_2025($pipe_delimited)
    {
        if($pipe_delimited) {
            $arr = explode("|", $pipe_delimited);
            return @$arr[0]; //just get 1 image
        }
        else return "";
    }
    private function run_lookups_now($arr, $what)
    { echo "\nrun_lookups_now...$what\n";
        /*Array(
            [records] => Array(
                    [0] => Array(
                            [a] => 
                            [b] => Stichastrella rosea (O.F.Müller, 1776)
                            [c] => nyc
                            [d] => nyc
                            [e] => 
                            [f] => 139a966c-22d5-486b-bff2-cfcbccd6fdfc
                            [g] => 2933760949
                            [h] => 53.296003
                            [i] => -4.059275
                            [j] => 
                            [k] => 
                            [l] => 
                            [m] => 1992-06-02/1992-08-02
                        )
                    [1] => Array(
                            [a] => 
                            [b] => Stichastrella rosea (O.F.Müller, 1776)
                            [c] => nyc
                            [d] => nyc
                            [e] => 
                            [f] => 139a966c-22d5-486b-bff2-cfcbccd6fdfc
                            [g] => 2933243777
                            [h] => 58.418566
                            [i] => -5.127211
                            [j] => Connor, D. David
                            [k] => 
                            [l] => 
                            [m] => 1991-05-16
                        )
        */
        $final = array();
        foreach($arr['records'] as $r) {
            $r['c'] = ''; $r['d'] = ''; $r['e'] = ''; //remove the 'nyc' not yet computed reminder.
            if($datasetkey = $r['f']) {
                if($publishingorgkey = self::get_dataset_field($datasetkey, 'publishingOrganizationKey')) { //this was postponed before
                    $r['c']   = self::get_org_name($publishingorgkey);
                    $r['d']   = $publishingorgkey;    
                }
                $r['e']   = self::get_dataset_field($datasetkey, 'title');
            }
            $final[] = $r;
            /* debug only
            if($r['c']) { //organization name or title
                print_r($r); exit("\nhuli ka\n");
            }
            */
        }
        if($final) return array('records' => $final, 'count' => count($final));
        return $arr;
    }
    function get_media_by_gbifid($gbifid)
    {
        $path = $this->save_path['multimedia_gbifID'];
        $final_path = self::get_md5_path($path, $gbifid);
        $txt_file = $final_path . $gbifid . ".txt";
        if(file_exists($txt_file)) {
            // echo "\nmedia found [$gbifid]\n";
            $str = file_get_contents($txt_file);
            return str_ireplace("\n", "", $str); //remove line separator
        }
        return '';
    }
    //==========================
    // end GBIF methods
    //==========================
    private function process_all_eol_taxa_using_DH($path = false, $listOnly = false) //total rows = 2,724,672 | rows where EOLid is not blank = 2,237,550
    {
        if(!$path) $path = $this->eol_taxon_concept_names_tab;
        if($listOnly) $list = array();
        $i = 0;
        foreach(new FileIterator($path) as $line => $row) {
            if(!$row) continue;
            $i++;
            if($i == 1) $fields = explode("\t", $row);
            else {
                $rec = explode("\t", $row);
                $k = -1; $rek = array();
                foreach($fields as $field) {
                    $k++;
                    $rek[$field] = $rec[$k];
                }
                if($rek['EOLid']) {
                    // $debug[$rek['EOLid']] = ''; //debug just for counting
                    // print_r($rek); //exit;
                    $taxon_concept_id = $rek['EOLid'];
                    $sciname = false;
                    if($val = $rek['canonicalName']) $sciname = $val;
                    else                             $sciname = Functions::canonical_form($rek['scientificName']);
                    if(!$sciname) continue;
                    
                    if($listOnly) {
                        if($taxon_concept_id) $list[$sciname] = $taxon_concept_id;
                        continue;
                    }
                    /* never went here bec. listonly is true ever since
                    if($taxon_concept_id == 1) continue;
                    // if($rek['taxonRank'] == 'species') { //only species-level taxa
                    if(true) { //all taxa
                        echo "\n$i. [$sciname][tc_id = $taxon_concept_id]";
                        self::main_loop($sciname, $taxon_concept_id); //uncomment in real operation...
                        if($usageKey = self::get_usage_key($sciname)) echo " - OK [$usageKey]"; //used to cache all usageKey requests...
                        else                                          echo " - usageKey not found!";
                        exit("\n--stopx--\n"); //doesn't go here if it is $listOnly boolean true
                    }
                    */
                }
            }
        }
        // exit("\n".count($debug)."\n"); //debug just for counting
        if($listOnly) return $list;
    }
    function map_data_file_already_been_generated($basename)
    {
        // return false; //debug
        $filename = self::get_map_data_path($basename).$basename.".json";
        if($GLOBALS['ENV_DEBUG']) echo "\nmap file: [$filename]\n";
        if(file_exists($filename)) {
            if(filesize($filename) > 0) {
                if($GLOBALS['ENV_DEBUG']) echo "[$basename][$filename] map data (.json) already generated OK";
                // echo " - already generated\n";
                return true;
            }
            else {
                $msg = 'json exists but zero length, will delete file';
                $this->debug[$msg][$filename] = '';
                echo "\n$msg [$filename]\n";
                unlink($filename);
            }
        }
        else return false;
    }
    function force_reduce_records($to_be_saved)
    {
        $divisor = count($to_be_saved['records'])/$this->limit_20k;
        $divisor = intval($divisor);
        $final = array(); $i = 0;
        foreach($to_be_saved['records'] as $r) {
            $i++;
            if(($i % $divisor) == 0) $final[] = $r; //skip method e.g. 40,000 recs. only recs 2,4,6,8,etc. will be saved to get only 20K recs.
            if(count($final) >= $this->limit_20k) break;
        }
        $to_be_saved['records'] = $final;
        return $to_be_saved;
    }
    function add_recs_from_original_if_needed($to_be_saved, $original_recs)
    {
        echo "\norig recs: [".count($original_recs)."]";
        echo "\ncurrent 1: [".count($to_be_saved['records'])."]";
        // step 1: get all gbif IDs from current
        foreach($to_be_saved['records'] as $r) {
            $gbif_ids[$r['g']] = '';
        }

        /* seems not needed for now, but this works OK
        // step 1-a: remove from original those records from current
        $i = -1;
        foreach($original_recs as $o) { $i++;
            if(isset($gbif_ids[$o['g']])) unset($original_recs[$i]);
        }
        echo "\norig recs 2: [".count($original_recs)."]";
        // step 1-b: compute how many records to add to current
        $to_add = $this->limit_20k - count($to_be_saved['records']);
        echo "\n# of records to be added to current: [$to_add]\n";
        */

        // step 2: add to current the recs from original but not existing gbif id in current
        foreach($original_recs as $o) {
            if(!isset($gbif_ids[$o['g']])) $to_be_saved['records'][] = $o;
            if(count($to_be_saved['records']) >= $this->limit_20k) break;
        }
        $to_be_saved['count'] = count($to_be_saved['records']);
        echo "\ncurrent 2: [".count($to_be_saved['records'])."] (final)\n"; //exit("\nstop muna\n");
        return $to_be_saved;
    }
    function save_ids_to_text_from_many_folders() //a utility
    {
        // /* new: to limit the taxa to those: Order, Family, Genus, Species
        require_library('connectors/DHConnLib');
        $func = new DHConnLib('');
        $taxID_rank_info = $func->get_taxID_nodes_info(false, 'list of taxa', 'all', true); //4th param true means has return value. first param false means use current DH.
        // print_r($func->all_ranks_['all']); exit;
        // print_r($taxID_rank_info); exit("\n-end muna-\n");
        // */
        
        $dir_to_process = $this->save_path['map_data'];
        $text_file = $this->save_path['map_data']."final_taxon_concept_IDS.txt";
        $i = 0;
        if($fhandle = Functions::file_open($text_file, "w")) {
            if($dir = opendir($dir_to_process)) {
                while(false !== ($subdir = readdir($dir))) {
                    if(!in_array($subdir, array(".",".."))) {
                        echo "\n[$subdir]";
                        $files = $dir_to_process.$subdir."/*.json";
                        foreach (glob($files) as $filename) {
                            if(filesize($filename)) {
                                $taxID = pathinfo($filename, PATHINFO_FILENAME);
                                if(in_array(@$taxID_rank_info[$taxID]['r'], $func->all_ranks_['all'])) {
                                    // echo "\n[$filename] - " . $taxID; //good debug
                                    fwrite($fhandle, $taxID . "\n");
                                }
                                $i++;
                            }
                        }
                    }
                }
            }
            fclose($fhandle);
            //file backup
            $date = date('Y-m-d'); //e.g. 1972-07-13
            $destination = str_replace('final_taxon_concept_IDS', "final_taxon_concept_IDS_".$date, $text_file);
            copy($text_file, $destination);
        }
        else echo "\nFile access error: [$text_file]\n";
        echo "\n--end taxon_concept_IDs total with map data: [$i]--\n";
    }
    function remove_var_data_equals() //a one-time-utility "var data = "
    {
        echo "\nwent here...\n";
        $dir_to_process = $this->save_path['map_data'];
        if($dir = opendir($dir_to_process)) {
            while(false !== ($subdir = readdir($dir))) {
                if(!in_array($subdir, array(".",".."))) {
                    echo "\n[$subdir]";
                    $files = $dir_to_process.$subdir."/*.json";
                    foreach (glob($files) as $filename) {
                        if(filesize($filename)) {
                            // echo "\n[$filename] - " . pathinfo($filename, PATHINFO_FILENAME); //good debug
                            $str = file_get_contents($filename);
                            $str = str_replace("var data = ", "", $str);
                            if($fhandle = Functions::file_open($filename, "w")) {
                                fwrite($fhandle, $str);
                                fclose($fhandle);
                                // echo " -- saved OK\n";
                            }
                            else echo " -- could not open file...\n";
                        }
                    }
                }
            }
        }
    }
    private function prepare_data($taxon_concept_id)
    {
        $txtFile = DOC_ROOT . "/public/tmp/google_maps/fusion/" . $taxon_concept_id . ".txt";
        $file_array = file($txtFile);
        unset($file_array[0]); //remove first line, the headers
        return $file_array;
    }
    private function get_center_latlon_using_taxonID($taxon_concept_id)
    {
        $rows = self::prepare_data($taxon_concept_id);
        echo "\n" . count($rows) . "\n";
        $minlat = false; $minlng = false; $maxlat = false; $maxlng = false;
        foreach($rows as $row) { //$row is String not array
            $cols = explode("\t", $row);
            // print_r($cols);
            /*
            if(count($cols) != 11) continue; //exclude row if total no. of cols is not 11, just to be sure that the col 10 is the "lat,long" column.
            $temp = explode(",", $cols[10]); //col 10 is the latlon column.
            $lat = $temp[0];
            $lon = $temp[1];
            */
            $lat = $cols[7];
            $lon = $cols[8];
            if ($lat && $lon) {
                if ($minlat === false) { $minlat = $lat; } else { $minlat = ($lat < $minlat) ? $lat : $minlat; }
                if ($maxlat === false) { $maxlat = $lat; } else { $maxlat = ($lat > $maxlat) ? $lat : $maxlat; }
                if ($minlng === false) { $minlng = $lon; } else { $minlng = ($lon < $minlng) ? $lon : $minlng; }
                if ($maxlng === false) { $maxlng = $lon; } else { $maxlng = ($lon > $maxlng) ? $lon : $maxlng; }
            }
            $lat_center = $maxlat - (($maxlat - $minlat) / 2);
            $lon_center = $maxlng - (($maxlng - $minlng) / 2);
            // echo "\n[$lat_center][$lon_center]\n";
            echo "\n$lat_center".","."$lon_center\n";
            return $lat_center.','.$lon_center;
        }
        /* computation based on: http://stackoverflow.com/questions/6671183/calculate-the-center-point-of-multiple-latitude-longitude-coordinate-pairs */
    }
    private function get_center_latlon_using_coordinates($records)
    {
        $minlat = false; $minlng = false; $maxlat = false; $maxlng = false;
        foreach($records as $r) {
            $lat = $r['h'];
            $lon = $r['i'];
            if ($lat && $lon) {
                if ($minlat === false) { $minlat = $lat; } else { $minlat = ($lat < $minlat) ? $lat : $minlat; }
                if ($maxlat === false) { $maxlat = $lat; } else { $maxlat = ($lat > $maxlat) ? $lat : $maxlat; }
                if ($minlng === false) { $minlng = $lon; } else { $minlng = ($lon < $minlng) ? $lon : $minlng; }
                if ($maxlng === false) { $maxlng = $lon; } else { $maxlng = ($lon > $maxlng) ? $lon : $maxlng; }
            }
            $lat_center = $maxlat - (($maxlat - $minlat) / 2);
            $lon_center = $maxlng - (($maxlng - $minlng) / 2);
            return array('center_lat' => $lat_center, 'center_lon' => $lon_center);
        }
        /* computation based on: http://stackoverflow.com/questions/6671183/calculate-the-center-point-of-multiple-latitude-longitude-coordinate-pairs */
    }
    private function write_to_supplementary_fusion_text($final)
    {
        //get publishers:
        $publishers = array();
        foreach($final['records'] as $r) {
            if($r['h'] && $r['i']) $publishers[$r['c']] = '';
        }
        $publishers = array_keys($publishers);
        sort($publishers);
        
        //get center lat lon:
        $temp = self::get_center_latlon_using_coordinates($final['records']);
        $center_lat = $temp['center_lat'];
        $center_lon = $temp['center_lon'];
        
        if($center_lat && $center_lon && $publishers) {
            $arr = array("tableID" => "", "total" => count($final['records']), "center_lat" => $center_lat, "center_lon" => $center_lon, "publishers" => $publishers);
            echo "\n" . json_encode($arr) . "\n";
            fwrite($this->file3, "var xdata = ".json_encode($arr));
        }
        
        /*
        var xdata = {"center_lat": 33.83253, "center_lon": -118.4745, "tableID": "1TspfLoWk5Vee6PHP78g09vwYtmNoeMIBgvt6Keiq", 
        "publishers" : ["Cornell Lab of Ornithology (CLO)", "Museum of Comparative Zoology, Harvard University (MCZ)"] };

        [count] => 619
        [records] => Array (
                    [0] => Array (
                            [catalogNumber] => 1272385
                            [sciname] => Chanos chanos (Forsskål, 1775)
                            [publisher] => iNaturalist.org (iNaturalist)
                            [publisher_id] => 28eb1a3f-1c15-4a95-931a-4af90ecb574d
                            [dataset] => iNaturalist research-grade observations
                            [dataset_id] => 50c9509d-22c7-4a22-a47d-8c48425ef4a7
                            [gbifID] => 1088910889
                            [lat] => 1.87214
                            [lon] => -157.42781
                            [recordedBy] => David R
                            [identifiedBy] => 
                            [pic_url] => http://static.inaturalist.org/photos/1596294/original.jpg?1444769372
                        )
                    [1] => Array (
                            [catalogNumber] => 2014-0501
                            [sciname] => Chanos chanos (Forsskål, 1775)
                            [publisher] => MNHN - Museum national d'Histoire naturelle (MNHN)
                            [publisher_id] => 2cd829bb-b713-433d-99cf-64bef11e5b3e
                            [dataset] => Fishes collection (IC) of the Muséum national d'Histoire naturelle (MNHN - Paris)
                            [dataset_id] => f58922e2-93ed-4703-ba22-12a0674d1b54
                            [gbifID] => 1019730375
                            [lat] => -12.8983
                            [lon] => 45.19877
                            [recordedBy] => 
                            [identifiedBy] => 
                            [pic_url] => 
                        )
        */
    }
    private function write_to_file($j) //for cluster map | when using API to get georeference data
    {
        $recs = array();
        $i = 0;
        foreach($j->results as $r) {
            // if($i > 2) break; //debug
            $i++;
            if(@$r->decimalLongitude && @$r->decimalLatitude) {
                $rec = array();
                $rec['a']   = (string) @$r->catalogNumber;
                $rec['b']   = self::get_sciname($r);

                /* postpone
                $rec['c']   = self::get_org_name(@$r->publishingOrgKey);
                $rec['d']   = @$r->publishingOrgKey;
                if($val = @$r->institutionCode) $rec['c'] .= " ($val)";
                $rec['e']   = self::get_dataset_field(@$rek['datasetkey'], 'title');
                */
                $rec['c']   = 'nyc';
                $rec['d']   = 'nyc';
                $rec['e']   = 'nyc';

                $rec['f']   = @$r->datasetKey;
                $rec['g']   = $r->gbifID;
                $rec['h']   = $r->decimalLatitude;
                $rec['i']   = $r->decimalLongitude;
                $rec['j']   = @$r->recordedBy;
                $rec['k']   = @$r->identifiedBy;
                $rec['l']   = @$r->media[0]->identifier;
                $rec['m']   = @$r->eventDate;
                /*
                $header['a'] = "catalogNumber";
                $header['b'] = "sciname";
                $header['c'] = "publisher";
                $header['d'] = "publisher_id";
                $header['e'] = "dataset";
                $header['f'] = "dataset_id";
                $header['g'] = "gbifID";
                $header['h'] = "lat";
                $header['i'] = "lon";
                $header['j'] = "recordedBy";
                $header['k'] = "identifiedBy";
                $header['l'] = "pic_url";
                $header['m'] = "eventDate";
                
                fields from the CSV downloaded from GBIF download service:
                gbifid    datasetkey    occurrenceid    kingdom    phylum    class    order    family    genus    species    infraspecificepithet    taxonrank    scientificname    countrycode    locality    
                publishingorgkey    decimallatitude    decimallongitude    elevation    elevationaccuracy    depth    depthaccuracy    eventdate    day    month    year    taxonkey    specieskey    
                basisofrecord    institutioncode    collectioncode    catalognumber    recordnumber    identifiedby    rights    rightsholder    recordedby    typestatus    
                establishmentmeans    lastinterpreted    mediatype    issue
                */
                
                /* self::write_to_fusion_table($rec); */
                $recs[] = $rec;
                
                /*
                Catalogue number: 3043
                Uncinocythere stubbsi
                Institution: Unidad de Ecología (Ostrácodos), Dpto. Microbiología y Ecología, Universidad de Valencia
                Collection: Entocytheridae (Ostracoda) World Database
                */
            }
        }
        return $recs;
    }
    private function write_to_fusion_table($rec)
    {   /*
        [catalogNumber] => 1272385
        [sciname] => Chanos chanos (Forsskål, 1775)
        [publisher] => iNaturalist.org (iNaturalist)
        [publisher_id] => 28eb1a3f-1c15-4a95-931a-4af90ecb574d
        [dataset] => iNaturalist research-grade observations
        [dataset_id] => 50c9509d-22c7-4a22-a47d-8c48425ef4a7
        [gbifID] => 1088910889
        [lat] => 1.87214
        [lon] => -157.42781
        [recordedBy] => David R
        [pic_url] => http://static.inaturalist.org/photos/1596294/original.jpg?1444769372
        */
        // fwrite($this->file2, implode("\t", $rec) . "\n"); //works OK but it has 2 fields for lat and lon
        
        $rek = $rec;
        $rek['location'] = $rec['h'] . "," . $rec['i'];
        unset($rek['lat']);
        unset($rek['lon']);
        fwrite($this->file2, implode("\t", $rek) . "\n");
        
        /* un-scalable, not an option
        //start kml 
        $kml_string = "<Placemark><name>" . $rec['a'] . "</name><description><![CDATA[" . $rec['a'] . "]]></description><Point><coordinates>" . $rek['location'] . ",0</coordinates></Point></Placemark>";
        fwrite($this->file4, $kml_string . "\n");
        //end kml
        */
    }
    private function get_sciname($r)
    {
        // if($r->taxonRank == "SPECIES") return $r->species;
        return $r->scientificName;
    }
    function get_org_name($organizationKey)
    {
        return self::get_organization_field($organizationKey, 'title');
        /* obsolete
        $id = trim($id);
        if(!$id) return "";
        $options = $this->download_options;
        $options['delay_in_minutes'] = 0;
        $options['expire_seconds'] = false;
        if($html = Functions::lookup_with_cache($this->html[$org] . $id, $options)) {
            if(preg_match("/Full title<\/h3>(.*?)<\/p>/ims", $html, $arr)) return strip_tags(trim($arr[1]));
        }
        return '';
        */
    }
    private function get_initial_data($sciname)
    {
        if($usageKey = self::get_usage_key($sciname)) {
            $count = Functions::lookup_with_cache($this->gbif_record_count . $usageKey, $this->download_options);
            if($count > 0) {
                debug("\nTotal:[$count]"); //total records; with or without lat long
                $rec['usageKey'] = $usageKey;
                $rec["count"] = $count;
                return $rec;
            }
            else debug("\nNo occurrence. ");
        }
        else {
            exit("\nCannot get usage_key for ($sciname)\n");
        }
    }
    function get_usage_key($sciname)
    {
        $options = $this->download_options;
        $options['expire_seconds'] = false;
        if($json = Functions::lookup_with_cache($this->gbif_taxon_info . $sciname, $options)) {
            $json = json_decode($json);
            $usageKey = false;
            if(!isset($json->usageKey)) {
                if(isset($json->note)) $usageKey = self::get_usage_key_again($sciname);
                else {} // e.g. Fervidicoccaceae
            }
            else { // echo "\nGoes here 100\n"; // print_r($json);
                /*stdClass Object(
                    [usageKey] => 9594474
                    [scientificName] => Gonyaulax Diesing, 1866
                    [canonicalName] => Gonyaulax
                    [rank] => GENUS
                    [status] => ACCEPTED
                    [confidence] => 94
                    [matchType] => HIGHERRANK
                    [kingdom] => Chromista
                    [phylum] => Myzozoa
                    [order] => Gonyaulacales
                    [family] => Gonyaulacaceae
                    [genus] => Gonyaulax
                    [kingdomKey] => 4
                    [phylumKey] => 8770992
                    [classKey] => 9049014
                    [orderKey] => 8775728
                    [familyKey] => 7665054
                    [genusKey] => 9594474
                    [synonym] => 
                    [class] => Dinophyceae
                )*/
                if($sciname == $json->canonicalName) {
                    $usageKey = trim((string) $json->usageKey);
                }
            }
            if($val = $usageKey) return $val;
        }
        return false;
    }
    private function get_usage_key_again($sciname)
    {
        $options = $this->download_options;
        $options['expire_seconds'] = false;
        echo "\n2nd try to get usageKey ($sciname)\n";
        if($json = Functions::lookup_with_cache($this->gbif_taxon_info . $sciname . "&verbose=true", $options)) {
            $usagekeys = array();
            $options = array();
            $json = json_decode($json);
            if(!isset($json->alternatives)) return false;
            foreach($json->alternatives as $rec) { //print_r($rec);
                /*{
                "usageKey": 3212024,
                "scientificName": "Globigerina d'Orbigny, 1826",
                "canonicalName": "Globigerina",
                "rank": "GENUS",
                "status": "ACCEPTED",
                "confidence": 99,
                "note": "Similarity: name=100; authorship=0; classification=-2; rank=0; status=1; score=99",
                "matchType": "EXACT",
                "kingdom": "Chromista",
                "phylum": "Foraminifera",
                "order": "Rotaliida",
                "family": "Globigerinidae",
                "genus": "Globigerina",
                "kingdomKey": 4,
                "phylumKey": 8376456,
                "classKey": 7434778,
                "orderKey": 7692889,
                "familyKey": 8273781,
                "genusKey": 3212024,
                "synonym": false,
                "class": "Globothalamea"
                },*/
                if($rec->canonicalName == $sciname && $rec->status == "ACCEPTED" && $rec->confidence >= 99) {
                    $options[$rec->rank][] = $rec->usageKey;
                    $usagekeys[] = $rec->usageKey;
                }
            }
            if($options) {
                /* from NCBIGGIqueryAPI.php connector
                if(isset($options["FAMILY"])) return min($options["FAMILY"]);
                else return min($usagekeys);
                */
                return min($usagekeys);
            }
        }
        return false;
    }
    //========================================================
    // start of Clustering code: (http://www.appelsiini.net/2008/introduction-to-marker-clustering-with-google-maps)
    //========================================================
    function start_clustering()
    {
        define('OFFSET', 268435456);
        define('RADIUS', 85445659.4471); /* $offset / pi() */
        $markers   = array();
        $markers[] = array('id' => 'marker_1',                            'lat' => 59.441193, 'lon' => 24.729494);
        $markers[] = array('id' => 'marker_2',                            'lat' => 59.432365, 'lon' => 24.742992);
        $markers[] = array('id' => 'marker_3',                            'lat' => 59.431602, 'lon' => 24.757563);
        $markers[] = array('id' => 'marker_4',                            'lat' => 59.437843, 'lon' => 24.765759);
        $markers[] = array('id' => 'marker_5',                            'lat' => 59.439644, 'lon' => 24.779041);
        $markers[] = array('id' => 'marker_6',                            'lat' => 59.434776, 'lon' => 24.756681);
        $clustered = self::cluster($markers, 50, 11); //middel orig 20
        print_r($clustered);
    }
    function cluster($markers, $distance, $zoom) {
        $clustered = array();
        /* Loop until all markers have been compared. */
        while (count($markers)) {
            $marker  = array_pop($markers);
            $cluster = array();
            /* Compare against all markers which are left. */
            foreach ($markers as $key => $target) {
                $pixels = self::pixelDistance($marker['lat'], $marker['lon'],
                                        $target['lat'], $target['lon'],
                                        $zoom);
                /* If two markers are closer than given distance remove */
                /* target marker from array and add it to cluster.      */
                if ($distance > $pixels) {
                    printf("Distance between %s,%s and %s,%s is %d pixels.\n", 
                        $marker['lat'], $marker['lon'],
                        $target['lat'], $target['lon'],
                        $pixels);
                    unset($markers[$key]);
                    $cluster[] = $target;
                }
            }

            /* If a marker has been added to cluster, add also the one  */
            /* we were comparing to and remove the original from array. */
            if (count($cluster) > 0) {
                $cluster[] = $marker;
                $clustered[] = $cluster;
            } else {
                $clustered[] = $marker;
            }
        }
        return $clustered;
    }
    function lonToX($lon) {
        return round(OFFSET + RADIUS * $lon * pi() / 180);        
    }
    function latToY($lat) {
        return round(OFFSET - RADIUS * 
                    log((1 + sin($lat * pi() / 180)) / 
                    (1 - sin($lat * pi() / 180))) / 2);
    }
    function pixelDistance($lat1, $lon1, $lat2, $lon2, $zoom) {
        $x1 = self::lonToX($lon1);
        $y1 = self::latToY($lat1);
        $x2 = self::lonToX($lon2);
        $y2 = self::latToY($lat2);
        return sqrt(pow(($x1-$x2),2) + pow(($y1-$y2),2)) >> (21 - $zoom);
    }
    //========================================================
    // end of Clustering code: (http://www.appelsiini.net/2008/introduction-to-marker-clustering-with-google-maps)
    //========================================================
    private function get_proper_modulo($total)
    {
        if($total > 0 && $total <= 50) return 10;
        if($total > 50 && $total <= 100) return 25;
        if($total > 100 && $total <= 200) return 50;
        if($total > 200 && $total <= 400) return 100;
        if($total > 400 && $total <= 800) return 200;
        if($total > 800 && $total <= 1600) return 400;
        if($total > 1600 && $total <= 3200) return 800;
        if($total > 3200 && $total <= 6400) return 1000;
        if($total > 6400 && $total <= 12800) return 3000;
        if($total > 12800 && $total <= 30000) return 6000;
        if($total > 30000 && $total <= 60000) return 10000;
        if($total > 60000 && $total <= 120000) return 30000;
        if($total > 120000 && $total <= 240000) return 60000;
        if($total > 240000 && $total <= 300000) return 100000;
        if($total > 300000) return 200000;
    }
    private function format_YN($bool)
    {
        if($bool) return 'Yes';
        else return "No";
    }
    private function get_genus($sciname)
    {
        $arr = explode(" ", trim($sciname));
        return @$arr[0];
    }
    /*
    private function main_loop($sciname, $taxon_concept_id = false)
    {   $sciname = Functions::canonical_form($sciname); echo "\n[$sciname]\n";
        $basename = $sciname;
        if($val = $taxon_concept_id) $basename = $val;
        if(self::map_data_file_already_been_generated($basename)) return;
        $final_count = false;
        if($rec = self::get_initial_data($sciname)) {
            print_r($rec);
            // first is check the csv front ------------------------------------------------------------------------------------------
            if($final = self::prepare_csv_data($rec['usageKey'], $this->csv_paths)) {
                // print_r($final);
                if($final['count'] > $this->rec_limit) {
                    echo "\n -- will just use CSV source instead -- " . $final['count'] . " > " . $this->rec_limit . " \n"; //exit;
                    return; //if count > from csv then use csv later instead using - generate_map_data_using_GBIF_csv_files()
                }
                else echo "\n -- will use API as source 01 -- Records from CSV: " . $final['count'] . " < " . $this->rec_limit . " \n";
            }
            else echo "\n -- will use API as source 02 -- No CSV data \n"; //exit;
            // end ------------------------------------------------------------------------------------------
            
            self::get_georeference_data_via_api($rec['usageKey'], $basename);
        }
        if(!$final_count) {
            $filename = self::get_map_data_path($basename).$basename.".json";
            if(file_exists($filename)) unlink($filename); //delete cluster map data
        }
        else { //delete respective file
            if($final_count < $this->limit_20k) {}
            else {
                echo "\nfinal_count is [$final_count]\n";
                $filename = self::get_map_data_path($basename).$basename.".json";
                if(file_exists($filename)) {
                    unlink($filename); //delete cluster map data
                    exit("\nInvestigate: file deleted ($filename)\n");
                }
            }
        }
    }
    obsolete since the JRice text file is very old, old tc_ids
    private function process_all_eol_taxa($path = false, $listOnly = false)
    {   if(!$path) $path = $this->eol_taxon_concept_names_tab;
        if($listOnly) $list = array();
        $i = 0;
        foreach(new FileIterator($path) as $line_number => $line) { // 'true' will auto delete temp_filepath
            $line = explode("\t", $line);
            $taxon_concept_id = $line[0];
            $sciname          = Functions::canonical_form(@$line[1]);
            if($listOnly) {
                if($taxon_concept_id) $list[$sciname] = $taxon_concept_id;
                continue;
            }
            $i++;
            if($taxon_concept_id == 1) continue;
            // if(stripos($sciname, " ") !== false) //only species-level taxa
            if(true) { //all taxa
                echo "\n$i. [$sciname][tc_id = $taxon_concept_id]";
                //==================
                // 285. [Geraniaceae][tc_id = 285]
                // [Geraniaceae]
                // Total:[1212423]
                // [4676] NOT found in [/opt/homebrew/var/www/eol_php_code//public/tmp/google_maps/GBIF_taxa_csv_animalia/]
                // [4676] NOT found in [/opt/homebrew/var/www/eol_php_code//public/tmp/google_maps/GBIF_taxa_csv_incertae/]
                // [4676] found in [/opt/homebrew/var/www/eol_php_code//public/tmp/google_maps/GBIF_taxa_csv_others/]
                //  -- will use API as source 01 -- 411 > 50000 

                // $m = 100000;
                // $cont = false;
                // // if($i >=  1    && $i < $m)    $cont = true;
                // // if($i >=  $m   && $i < $m*2)  $cont = true;
                // // if($i >=  $m*2 && $i < $m*3)  $cont = true;
                // // if($i >=  $m*3 && $i < $m*4)  $cont = true;
                // // if($i >=  $m*4 && $i < $m*5)  $cont = true;
                // // if($i >=  $m*5 && $i < $m*6)  $cont = true;
                // if($i >=  $m*4 && $i < $m*6)  $cont = true;
                // if(!$cont) continue;
                //==================
                self::main_loop($sciname, $taxon_concept_id); //uncomment in real operation...
                if($usageKey = self::get_usage_key($sciname)) echo " - OK [$usageKey]"; //used to cache all usageKey requests...
                else                                          echo " - usageKey not found!";
                exit("\n--stopx--\n"); //doesn't go here if it is $listOnly boolean true
            }
            // else echo "\n[$sciname] will pass higher-level taxa at this time...\n";
        }//end loop
        if($listOnly) return $list;
    }
    private function process_current_hotlist_spreadsheet() //if we want to use the API for species-level taxa.
    {   require_library('connectors/GoogleClientAPI');
        $func = new GoogleClientAPI(); //get_declared_classes(); will give you how to access all available classes
        $params['spreadsheetID'] = '1124WNU1r1-X1lGrtg8aFLg72IoMUlHpDoNK5QS_mb9E';
        $params['range']         = 'Sheet 1!A1:B73054'; //where "A" is the starting column, "C" is the ending column, and "1" is the starting row.
        $arr = $func->access_google_sheet($params);
        // print_r($arr); exit("\n");
        // [73052] => Array(
        //         [0] => Clostridium
        //         [1] => 83389
        //     )
        // [73053] => Array(
        //         [0] => Banasa dimiata
        //         [1] => 609110
        //     )
        $species_level = 0; $m = 72311/3; $i = 0;
        foreach($arr as $rec) { $i++;
            $sciname = $rec[0];
            $taxon_concept_id = $rec[1];
            $sciname = trim(Functions::canonical_form($sciname));
            echo "\n$i. [$sciname][$taxon_concept_id]";
            if(stripos($sciname, " ") !== false) { //process only species-level taxa
                $species_level++;
                echo " [$sciname]";
                // breakdown
                // $cont = false;
                // if($i >=  1    && $i < $m)    $cont = true;
                // // if($i >=  $m   && $i < $m*2)  $cont = true;
                // // if($i >=  $m*2 && $i < $m*3)  $cont = true;
                // if(!$cont) continue;
                self::main_loop($sciname, $taxon_concept_id);
                exit("\n\ntemporary exit...\n");
            }
        }
        echo "\nspecies-level taxa count: $species_level\n";
    }
    private function process_hotlist_spreadsheet()
    {   require_library('XLSParser');
        $parser = new XLSParser();
        $families = array();
        $doc = WEB_ROOT."/public/tmp/spreadsheets/SPG Hotlist Official Version.xlsx";
        // $doc = LOCAL_HOST."/eli/eol_php_code/public/tmp/spreadsheets/SPG Hotlist Official Version.xlsx"; //for MacBook
        echo "\n processing [$doc]...\n";
        if($path = Functions::save_remote_file_to_local($doc, array("timeout" => 3600, "file_extension" => "xlsx", 'download_attempts' => 2, 'delay_in_minutes' => 2))) {
            $arr = $parser->convert_sheet_to_array($path);
            $i = -1;
            foreach($arr['Animals'] as $sciname) {
                $i++;
                $sciname = trim(Functions::canonical_form($sciname));
                // if(stripos($sciname, " ") !== false) //process only species-level taxa
                if(true) {
                    $taxon_concept_id = $arr['1'][$i];
                    echo "\n$i. [$sciname][$taxon_concept_id]";
                    //==================
                    // breakdown
                    // $m = 10000;
                    // $cont = false;
                    // // if($i >=  1    && $i < $m)    $cont = true;
                    // // if($i >=  $m   && $i < $m*2)  $cont = true;
                    // // if($i >=  $m*2 && $i < $m*3)  $cont = true;
                    // // if($i >=  $m*3 && $i < $m*4)  $cont = true;
                    // // if($i >=  $m*4 && $i < $m*5)  $cont = true;
                    // // if($i >=  $m*5 && $i < $m*6)  $cont = true;
                    // // if($i >=  $m*6 && $i < $m*7)  $cont = true;
                    // if(!$cont) continue;
                    self::main_loop($sciname, $taxon_concept_id);
                    //==================
                    // break; //debug - process only 1
                }
            }
            unlink($path);
        }
        else echo "\n [$doc] unavailable! \n";
    }
    private function process_DL_taxon_list()
    {   $temp_filepath = Functions::save_remote_file_to_local(self::DL_MAP_SPECIES_LIST, array('timeout' => 4800, 'download_attempts' => 5));
        if(!$temp_filepath) {
            echo "\n\nExternal file not available. Program will terminate.\n";
            return;
        }
        $i = 0;
        foreach(new FileIterator($temp_filepath, true) as $line_number => $line) { // 'true' will auto delete temp_filepath
            $i++;
            if($line) {
                $m = 10000;
                $cont = false;
                if($i >=  1    && $i < $m)    $cont = true;
                // if($i >=  $m   && $i < $m*2)  $cont = true;
                // if($i >=  $m*2 && $i < $m*3)  $cont = true;
                // if($i >=  $m*3 && $i < $m*4)  $cont = true;
                // if($i >=  $m*4 && $i < $m*5)  $cont = true;
                if(!$cont) continue;
                $arr = explode("\t", $line);
                $sciname = trim($arr[0]);
                echo "\n[$sciname]\n";
                self::main_loop($sciname);
            }
            // if($i >= 5) break; //debug
        }
    }
    */
}
?>