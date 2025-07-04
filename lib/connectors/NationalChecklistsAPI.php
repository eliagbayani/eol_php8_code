<?php
namespace php_active_record;
/* connector: [national_checklists_2024.php] 

//for local consumption:
wget https://editors.eol.org/other_files/GBIF_occurrence/Country_checklists/Country_checklists_DwCA.zip
wget https://editors.eol.org/other_files/GBIF_occurrence/WaterBody_checklists/WaterBody_checklists_DwCA.zip
wget https://editors.eol.org/other_files/GBIF_occurrence/Continent_checklists/Continent_checklists_DwCA.zip
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class NationalChecklistsAPI
{
    public function __construct($what) //typically param $folder is passed here.
    {
        /* copied template
        $this->resource_id = $folder;
        $this->path_to_archive_directory = CONTENT_RESOURCE_LOCAL_PATH . '/' . $folder . '_working/';
        $this->archive_builder = new \eol_schema\ContentArchiveBuilder(array('directory_path' => $this->path_to_archive_directory));
        */

        $this->download_options = array('resource_id' => "gbif_ctry_checklists", 'expire_seconds' => 60*60*24*30*3, 'download_wait_time' => 1000000/2, 
        'timeout' => 10800*2, 'download_attempts' => 3, 'delay_in_minutes' => 5); //3 months to expire
        $this->download_options['expire_seconds'] = false; //doesn't expire

        $this->debug = array();
        $this->bibliographicCitation = "GBIF.org (23 January 2025) GBIF Occurrence Download https://doi.org/10.15468/dl.vd2ajk";
            // "GBIF.org (23 January 2025) GBIF Occurrence Download https://doi.org/10.15468/dl.fy2p2b"; //filtered datasetKey
            // "GBIF.org (26 December 2024) GBIF Occurrence Download https://doi.org/10.15468/dl.uf735k";
            // https://www.gbif.org/occurrence/download/0049350-241126133413365
            // https://api.gbif.org/v1/occurrence/download/0049350-241126133413365
            
            // "GBIF.org (16 December 2024) GBIF Occurrence Download https://doi.org/10.15468/dl.h62wur"; //"Accessed ".date("d F Y").".";

        if(Functions::is_production())  $this->destination = "/extra/other_files/GBIF_occurrence/".$what."/";
        else                            $this->destination = "/Volumes/Crucial_4TB/other_files/GBIF_occurrence/".$what."/";
        
        $this->report_1 = $this->destination . "countries.tsv";
        $this->report_2 = $this->destination . "run_countries.tsv";
        $this->report_3 = $this->destination . "countries_unique_taxa.tsv";


        if(!is_dir($this->destination)) mkdir($this->destination);
        $this->country_path = $this->destination.'countries';
        if(!is_dir($this->country_path)) mkdir($this->country_path);
        $this->zip_file    = $this->destination.$what."_DwCA.zip";  //for development it was manually put here, it was copied from editors.eol.org
                                                                    //for production it was downloaded from GBIF during "step: 03 Initialize and download dumps"

        $this->service['country'] = "https://api.gbif.org/v1/node/country/"; //'https://api.gbif.org/v1/node/country/JP';
        $this->service['species'] = "https://api.gbif.org/v1/species/"; //https://api.gbif.org/v1/species/1000148
        $this->service['country_codes'] = "https://raw.githubusercontent.com/eliagbayani/EOL-connector-data-files/refs/heads/master/ISO_3166-1/country_codes_2letter.tsv";

        $this->AnneT_natl_checklists = array("Turkmenistan", "Niue", "Mexico", "Cocos Islands", "Dominica", "Timor-Leste", "Iceland", "Nepal", "Philippines", "Cameroon", "Niger", "Mongolia", "Spain", "Italy", "Egypt", "Kenya", "Uganda", "Barbados", "Oceania", "Honduras", "Palestine", "Laos", "Sweden", "Kiribati", "Croatia", "Cyprus", "Slovenia", "Qatar", "Bulgaria", "Macedonia", "Nicaragua", "Cuba", "Guam", "Grenada", "Iran", "Martinique", "Guinea", "Djibouti", "Curacao", "Kazakhstan", "Eswatini", "China", "Maldives", "Myanmar", "Bahrain", "Guernsey", "Ukraine", "Kuwait", "Bermuda", "Nigeria", "Tokelau", "France", "Panama", "Armenia", "Russia", "Slovakia", "Asia", "Eritrea", "Fiji", "Malaysia", "Andorra", "Togo", "Tunisia", "Anguilla", "Vanuatu", "Georgia", "Vietnam", "Albania", "Zambia", "Europe", "Denmark", "Germany", "Sudan", "Samoa", "Burundi", "Indonesia", "Seychelles", "Ethiopia", "Syria", "Mozambique", "Ghana", "Malta", "Tajikistan", "Pakistan", "Tanzania", "Colombia", "Singapore", "Austria", "Paraguay", "Angola", "Guyana", "Kosovo", "Aruba", "Chile", "Uzbekistan", "Finland", "Hungary", "Poland", "Africa", "Suriname", "Israel", "Morocco", "Palau", "Bhutan", "Liberia", "Somalia", "Cambodia", "Moldova", "Botswana", "Mauritius", "Comoros", "Belgium", "Afghanistan", "Romania", "India", "Kyrgyzstan", "Jordan", "Greece", "Tuvalu", "Australia", "Canada", "Mali", "Gabon", "Norway", "Lesotho", "Mauritania", "Japan", "Uruguay", "Chad", "Ecuador", "Yemen", "Portugal", "Serbia", "Tonga", "Guadeloupe", "Montserrat", "Bangladesh", "Gibraltar", "Thailand", "Lithuania", "Montenegro", "Namibia", "Mayotte", "Azerbaijan", "Taiwan", "Lebanon", "Macau", "Estonia", "Zimbabwe", "Switzerland", "Algeria", "Belarus", "Turkey", "Oman", "Luxembourg", "Rwanda", "Bolivia", "Brunei", "Peru", "Monaco", "Nauru", "Libya", "Benin", "Madagascar", "Senegal", "Belize", "Ireland", "Jamaica", "Tibet", "Brazil", "Liechtenstein", "Argentina", "Iraq", "Haiti", "Greenland", "Réunion", "Latvia", "Guatemala", "Malawi", "Venezuela", "Czech Republic", "Costa Rica", "Solomon Islands", "New Zealand", "South Sudan", "Saudi Arabia", "Bouvet Island", "North Korea", "US Minor Outlying Islands", "South Korea", "Saint Martin", "Christmas Island", "Saint Barthelemy", "French Polynesia", "The Gambia", "Dominican Republic", "North America", "Sri Lanka", "New Caledonia", "Cape Verde", "Guinea Bissau", "The Netherlands", "Marshall Islands", "Sint Maarten", "Saint Lucia", "Republic of the Congo", "Equatorial Guinea", "The Bahamas", "San Marino", "South Africa", "Mariana Islands", "Ivory Coast", "Puerto Rico", "Sierra Leone", "French Guiana", "Cayman Islands", "Falkland Islands", "Norfolk Island", "South America", "United Kingdom", "Hong Kong", "El Salvador", "Vatican City", "Faroe Islands", "United States", "Burkina Faso", "Saint-Pierre et Miquelon", "Antigua and Barbuda", "United Arab Emirates", "Central African Republic", "Isle of Man", "US Virgin Islands", "British Virgin Islands", "Bosnia and Herzegovina", "Papua New Guinea", "Wallis et Futuna", "Bailiwick of Jersey", "Trinidad and Tobago", "Federated States of Micronesia", "São Tomé and Príncipe", "Turks and Caicos Islands", "Saint Kitts and Nevis", "Democratic Republic of the Congo", "Bonaire, Saint Eustatius, and Saba", "Saint Vincent and the Grenadines", "Pitcairn, Henderson, Ducie, and, Oeno Islands", "Territory of Heard Island and McDonald Islands", "South Georgia and the South Sandwich Islands", "Saint Helena Ascension and Tristan da Cunha", "Territory of the French Southern and Antarctic Lands");
        $this->AnneT_natl_checklists[] = 'Sao Tome and Principe';
        $this->AnneT_natl_checklists[] = 'São Tomé and Príncipe';

        $this->ctry_map['Pitcairn'] = "Pitcairn, Henderson, Ducie, and, Oeno Islands";
        $this->ctry_map['Palestine, State of'] = "Palestine";
        $this->ctry_map['Russian Federation'] = "Russia";
        $this->ctry_map['Saint Helena, Ascension and Tristan da Cunha'] = "Saint Helena Ascension and Tristan da Cunha";
        // $this->ctry_map['Svalbard and Jan Mayen'] = "yyy";

        // Sao Tome and Principe	ST
        // $this->ctry_map['Sao Tome and Principe'] = "São Tomé and Príncipe";

        $this->ctry_map['Brunei Darussalam'] = "Brunei";
        $this->ctry_map['Bolivia, Plurinational State of'] = "Bolivia";
        $this->ctry_map['Bonaire, Sint Eustatius and Saba'] = "Bonaire, Saint Eustatius, and Saba";
        $this->ctry_map['Bahamas'] = "The Bahamas"; //SC_bahamas.tar.gz
        $this->ctry_map['Cocos (Keeling) Islands'] = "Cocos Islands"; //SC_cocosislands.tar.gz
        $this->ctry_map['Congo'] = "Republic of the Congo"; //https://editors.eol.org/eol_php_code/applications/content_server/resources/SC_repubcongo.tar.gz | https://www.geonames.org/2260494
        $this->ctry_map['Congo, the Democratic Republic of the'] = "Democratic Republic of the Congo"; //http://www.geonames.org/203312
        $this->ctry_map['Curaçao'] = "Curacao";
        $this->ctry_map['Falkland Islands (Malvinas)'] = "Falkland Islands";
        $this->ctry_map['Micronesia, Federated States of'] = "Federated States of Micronesia";
        $this->ctry_map['Gambia'] = "The Gambia";
        $this->ctry_map['Guinea-Bissau'] = "Guinea Bissau";
        $this->ctry_map['Heard Island and McDonald Islands'] = "Territory of Heard Island and McDonald Islands"; //SC_territoryofheardislandandmcdonaldislands.tar.gz
        $this->ctry_map['Iran, Islamic Republic of'] = "Iran";
        $this->ctry_map['Jersey'] = "Bailiwick of Jersey"; //SC_jersey.tar.gz
        $this->ctry_map["Korea, Democratic People's Republic of"] = "North Korea"; //SC_northkorea.tar.gz
        $this->ctry_map['Korea, Republic of'] = "South Korea";
        $this->ctry_map["Lao People's Democratic Republic"] = "Laos";
        $this->ctry_map['Moldova, Republic of'] = "Moldova";
        $this->ctry_map['Saint Martin (French part)'] = "Saint Martin";
        $this->ctry_map['Macedonia, the Former Yugoslav Republic of'] = "Macedonia";
        $this->ctry_map['Macao'] = "Macau";
        $this->ctry_map['Northern Mariana Islands'] = "Mariana Islands";
        $this->ctry_map['Netherlands'] = "The Netherlands"; //SC_netherlands.tar.gz
        $this->ctry_map['Saint Pierre and Miquelon'] = "Saint-Pierre et Miquelon";
        $this->ctry_map['Sint Maarten (Dutch part)'] = "Sint Maarten";
        $this->ctry_map['Syrian Arab Republic'] = "Syria";
        $this->ctry_map['French Southern Territories'] = "Territory of the French Southern and Antarctic Lands";
        $this->ctry_map['Taiwan, Province of China'] = "Taiwan";
        $this->ctry_map['Tanzania, United Republic of'] = "Tanzania";
        $this->ctry_map['United States Minor Outlying Islands'] = "US Minor Outlying Islands";
        $this->ctry_map['Holy See (Vatican City State)'] = "Vatican City";
        $this->ctry_map['Venezuela, Bolivarian Republic of'] = "Venezuela";
        $this->ctry_map['Virgin Islands, U.S.'] = "US Virgin Islands";
        $this->ctry_map['Virgin Islands, British'] = "British Virgin Islands";
        $this->ctry_map['Viet Nam'] = "Vietnam";
        $this->ctry_map['Wallis and Futuna'] = "Wallis et Futuna";
        // ---------------------
        // Saint Barthélemy	BL
        $this->ctry_map['Saint Barthélemy'] = "Saint Barthelemy";
        // Côte d'Ivoire	CI
        $this->ctry_map["Côte d'Ivoire"] = "Ivory Coast";

        $tmp = CONTENT_RESOURCE_LOCAL_PATH.'/metadata';
        if(!is_dir($tmp)) mkdir($tmp);

        /*
        found in waterbody
        $this->ctry_map['SOUTH AMERICA {LakeID}'] = "South America";
        */
        $this->country_continue = false;
    }
    private function initialize()
    {   
        // /* init 01
        $this->country_code_name_info = self::initialize_countries_from_csv(); //print_r($this->country_code_name_info); exit;
        self::assemble_terms_yml(); //generates $this->value_uris
        if(self::get_country_uri('Trinidad And Tobago') == 'http://www.geonames.org/3573591') echo "\nTrinidad And Tobago: OK";     else exit("\nERROR: Investigate country URI.\n");
        if(self::get_country_uri('Germany')             == 'http://www.geonames.org/2921044') echo "\nGermany: OK";                 else exit("\nERROR: Investigate country URI.\n");
        if(self::get_country_uri('Philippines')         == 'http://www.geonames.org/1694008') echo "\nPhilippines: OK";             else exit("\nERROR: Investigate country URI.\n");
        if(self::get_country_uri('Australia')           == 'http://www.geonames.org/2077456') echo "\nAustralia: OK";               else exit("\nERROR: Investigate country URI.\n");
        if(self::get_country_uri('United States')       == 'http://www.geonames.org/6252001') echo "\nUnited States: OK\n";         else exit("\nERROR: Investigate country URI.\n");
        // */

        // /* init 02
        require_library('connectors/ZenodoFunctions');
        require_library('connectors/ZenodoConnectorAPI');
        require_library('connectors/ZenodoAPI');
        $this->zenodo = new ZenodoAPI();
        // */

        // /* init 03
        require_library('connectors/GBIFTaxonomyAPI');
        $this->GBIFTaxonomy = new GBIFTaxonomyAPI('Country_checklists');
        // print_r($this->GBIFTaxonomy->country_filters); exit("\nxxx\n"); //from GBIFTaxonomyAPI.php country_filters() func
        // Array(
        //     [0] => Array(
        //             [Country] => Canada
        //             [uri] => http://www.geonames.org/6251999
        //             [remove taxa] => Ambystoma mexicanum
        //             [GBIF ID] => 2431950
        //         )
        //     [1] => Array(
        //             [Country] => Canada
        //             [uri] => http://www.geonames.org/6251999
        //             [remove taxa] => Python regius
        //             [GBIF ID] => 2465380
        //         )
        // )
        foreach($this->GBIFTaxonomy->country_filters as $r) {
            $this->exclude_country_taxon_pair[$r['Country']][$r['GBIF ID']] = ''; //main operation
        }
        // print_r($this->exclude_country_taxon_pair); exit("\n-stop muna-\n"); //debug only
        // Array(
        //     [Canada] => Array(
        //             [2431950] => 
        //             [2465380] => 
        //             [5716] => 
        //             [552] => 
        //             [2481102] => 
        //         )
        // )
        // */

        /* ---------- Proposed country-taxon pair for manual (removal) curation. --- manual assignment works OK
        $tmp = array();
        // $tmp[] = array("1780705", 'Philippines');
        // $tmp[] = array("12171927", 'Philippines');
        // $tmp[] = array("1780705", 'Indonesia');
        foreach($tmp as $t) $this->exclude_country_taxon_pair[$t[1]][$t[0]] = '';
        // print_r($this->exclude_country_taxon_pair); exit;
        // Array(
        //     [Philippines] => Array(
        //             [1780705] => 
        //             [12171927] => 
        //         )
        // )
        $this->check_species_exclusion_for_this_ctry = false;
        ---------- end */
    }
    function start($fields) //start($counter = false, $task, $sought_ctry = false) //$counter is only for caching
    {   //exit("\n[$counter]\n");
        /* may not need this anymore...
        require_library('connectors/GBIFdownloadRequestAPI');
        $func = new GBIFdownloadRequestAPI('Country_checklists');
        $key = $func->retrieve_key_for_taxon('Country_checklists');
        echo "\nkey is: [$key]\n";
        */

        $counter     = @$fields['counter'];
        $task        = @$fields['task'];
        $sought_ctry = @$fields['sought_ctry'];

        self::initialize();

        // /* main operation
        $tsv_path = self::download_extract_gbif_zip_file();
        echo "\ncsv_path: [$tsv_path]\n";
        // self::parse_tsv_file_caching($tsv_path, $counter); //during caching only; not part of main operation
        if($task == 'divide_into_country_files') {
            // /* remove current /countries/ folder
            recursive_rmdir($this->country_path); echo ("\nFolder removed: " . $this->country_path);
            if(!is_dir($this->country_path)) mkdir($this->country_path);
            // */
            self::parse_tsv_file($tsv_path, $task);            
        }
        elseif($task == 'generate_country_checklists')  self::create_individual_country_checklist_resource($counter, $task, $sought_ctry);
        elseif($task == 'major_deletion')               self::create_individual_country_checklist_resource($counter, $task);

        else exit("\nNo task to do. Will terminate.\n");
        // */
        
        if(file_exists($tsv_path)) unlink($tsv_path);
        print_r($this->debug);

        if($task == 'generate_country_checklists' & !$sought_ctry) self::write_unique_taxa_report();
    }
    private function write_unique_taxa_report()
    {
        // struct is: $this->accross_the_board_taxa[$taxon->taxonID] = $taxon->canonicalName;
        $f = Functions::file_open($this->report_3, "w");
        fwrite($f, implode("\t", array('taxonID', 'scientificName'))."\n");
        foreach($this->accross_the_board_taxa as $taxonID => $taxonName) fwrite($f, implode("\t", array($taxonID, $taxonName))."\n");
        fclose($f);
    }
    function show_countries_metadata() //utility
    {   $cont = false; //debug only

        if(file_exists($this->report_1)) unlink($this->report_1);
        if(file_exists($this->report_2)) unlink($this->report_2);

        self::initialize();
        $files = $this->country_path . "/*.tsv"; echo "\n[$files]\n"; $i = 0;
        foreach(glob($files) as $file) { //echo "\n$file\n"; exit;
            if($ret = self::evaluate_country_file($file)) { $i++;
                print_r($ret);
                /*Array(
                    [lower_case] => andorra
                    [orig] => Andorra
                    [abbrev] => AD
                )*/

                // if($ret['orig'] == 'Syria') $cont = true; //debug only // dev only

                // if($cont) {
                    if($val = $ret['orig']) {
                        if($val == 'United States') $dwca_filename = 'SC_unitedstates';
                        else {
                            if($dwca_filename = self::get_dwca_filename($val)) echo "\ndwca_filename: [$dwca_filename]\n"; //SC_andorra.tar.gz
                            else {
                                exit("\nTerminated: should not go here 01.\n"); //part of main operation
                                // break; //debug only
                            }
                        }
                        $ret['dwca'] = $dwca_filename;
                    }    
                // }
                // else continue;
                if(!file_exists($this->report_1)) {
                    $f = Functions::file_open($this->report_1, "w");
                    fwrite($f, implode("\t", array_keys($ret))."\n");
                }
                else {
                    $f = Functions::file_open($this->report_1, "a");
                    fwrite($f, implode("\t", $ret)."\n");    
                }

                $f2 = Functions::file_open($this->report_2, "a");
                fwrite($f2, "php fill_up_undefined_parents_real_GBIFChecklists.php _ '{\"resource_id\": \"$dwca_filename\", \"source_dwca\": \"$dwca_filename\", \"resource\": \"fillup_missing_parents_GBIFChecklists\"}'"."\n");
            }
            else continue;
            // break; //debug only | process just 1 record
            // if($i > 5) break; //debug only
        } //end foreach()
        // /* added the if(isset()) during dev and working with limited no. of records. May not need it in production.
        if(isset($f)) fclose($f);
        if(isset($f2)) fclose($f2);
        // */
        print_r($this->debug);
    }

    private function evaluate_country_file($file)
    {
        /* force assign dev only
        $file = '/Volumes/Crucial_4TB/other_files/GBIF_occurrence/Country_checklists/countries/CH.tsv'; //Switzerland force assign dev only
        */
        $ret = self::get_country_name_from_file($file); //e.g. $file "/Volumes/Crucial_4TB/other_files/GBIF_occurrence/Country_checklists/countries/AD.tsv"
        $country_name_lower = @$ret['lower_case'] ? $ret['lower_case']: "";
        $this->country_name = @$ret['orig'] ? $ret['orig']: "";
        // print_r($ret); exit;
        if(!in_array($this->country_name, $this->AnneT_natl_checklists)) {
            if($val = @$this->ctry_map[$this->country_name]) {
                $this->country_name = $val;
                if(!in_array($this->country_name, $this->AnneT_natl_checklists)) {
                    echo "\nNot mapped* [$this->country_name]";
                    $this->debug['Not mapped*'][$this->country_name] = '';
                    return false; //not mapped to Anne's checklists    
                }
            }
            else {
                echo "\nNot mapped** [$this->country_name]";
                $this->debug['Not mapped**'][$this->country_name] = '';
                return false; //not mapped to Anne's checklists
            }
        }
        $ret['orig'] = $this->country_name;
        return $ret;
    }
    private function create_individual_country_checklist_resource($counter = false, $task = "cannot be blank", $sought_ctry = false)
    {
        // /* caching
        $m = 252/6; //252 countries
        $i = 0;
        // */

        require_library('connectors/GBIFTaxonomyAPI');
        
        $files = $this->country_path . "/*.tsv"; echo "\n[$files]\n"; //e.g. AD.tsv
        foreach(glob($files) as $file) { $i++; sleep(1); //echo "\n$file\n"; exit;

            $this->check_species_exclusion_for_this_ctry = false; //IMPORTANT: initialize to false for every country

            // /* breakdown when caching
            if($counter) {
                $cont = false;
                if($counter == 1)       {if($i >= 1    && $i < $m)    $cont = true;}
                elseif($counter == 2)   {if($i >= $m   && $i < $m*2)  $cont = true;}
                elseif($counter == 3)   {if($i >= $m*2 && $i < $m*3)  $cont = true;}
                elseif($counter == 4)   {if($i >= $m*3 && $i < $m*4)  $cont = true;}
                elseif($counter == 5)   {if($i >= $m*4 && $i < $m*5)  $cont = true;}
                elseif($counter == 6)   {if($i >= $m*5 && $i < $m*6)  $cont = true;}
                else exit("\ncounter not defined...\n");                
                if(!$cont) continue;    
            }
            // */

            if($ret = self::evaluate_country_file($file)) { //print_r($ret);
                // echo "\n[this is $i]\n";
                $country_name_lower = $ret['lower_case'];
                $this->country_name = $ret['orig'];

                /* manual filter, dev only
                if(in_array($this->country_name, array('Philippines', 'Australia', 'Germany', 'Trinidad and Tobago', 'Canada'))) continue; //'United States'
                */

                /* ----- special occassion: dev only
                if($this->country_name == 'South Korea') $this->country_continue = true;
                if($this->country_continue) {}
                else continue;
                ----- */

                if($sought_ctry) {
                    if(!in_array($this->country_name, array($sought_ctry))) continue;
                }

                // /* country taxon exclusions
                if($val = @$this->exclude_country_taxon_pair[$this->country_name]) { // means it has species for removal for this country - manual curation from spreadsheet
                    $this->check_species_exclusion_for_this_ctry = true;
                    // require_library('connectors/GBIFTaxonomyAPI'); //moved up seems better
                    $this->GBIFTaxonomy = new GBIFTaxonomyAPI();        
                    $this->GBIFTaxonomy->load_taxon_keys_for_removal(array_keys($val));
                }
                // */

                // /*
                if($val = $ret['orig']) {
                    if($val == 'United States') $dwca_filename = 'SC_unitedstates';
                    else {
                        if($dwca_filename = self::get_dwca_filename($val)) {}
                        else {
                            exit("\nTerminated: should not go here 02.\n"); //part of main operation
                            // break; //debug only
                        }
                    }
                    echo "\ndwca_filename: [$dwca_filename]\n"; //SC_andorra
                    // /* ---------- major file deletion
                    if($task == 'major_deletion') {
                        $delete_file = CONTENT_RESOURCE_LOCAL_PATH . $dwca_filename . ".tar.gz";
                        if(file_exists($delete_file)) {
                            if(unlink($delete_file)) echo "\nFile deleted OK [$delete_file]\n";
                            else                     echo "\nFile NOT deleted [$delete_file]\n";
                        }    
                    }
                    // ---------- */                    
                }    
                // */
            }
            else {
                // print_r($ret); echo "\nfailed file: [$file]\n"; exit; //debug only
                $this->debug['Fail: Unrecognized country file'][$file] = '';
                continue;
            }
            
            // /* during major file deletion
            if($task == 'major_deletion') {
                // if($i >= 2) break; //debug only
                continue;
            }
            // */

            // /* ----------- initialize country archive ----------- e.g. DwCA "SC_philippines.tar.gz"
            if(substr($country_name_lower,0,4) == "the ")                                               $country_name_lower = str_ireplace("the ", "", $country_name_lower); //The Bahamas => SC_bahamas.tar.gz
            elseif(strtolower($this->country_name) == strtolower("Democratic Republic of the Congo"))   $country_name_lower = "congo";
            elseif(strtolower($this->country_name) == strtolower("Republic of the Congo"))              $country_name_lower = "repubcongo";

            // $folder = "SC_".$country_name_lower; //obsolete
            $folder = $dwca_filename;            //latest

            // /* main operation | uncomment in real operation
            if($sought_ctry) {}
            else {
                if(!self::is_this_DwCA_old_YN($folder.".tar.gz")) { echo "\nAlready recently generated ($folder)\n"; continue; }
                else                                                echo "\nHas not been generated in 2 months ($folder). Will proceed.\n";    
            }
            // */

            if(!$folder) exit("\nfolder not defined [$folder]\n");
            self::proc_country($folder, $file);
            // break; //debug only | process just 1 country
            // if($i >= 2) break; //debug only
        }
    }
    private function proc_country($folder, $file)
    {
        $this->taxon_ids = array(); //very important
        $resource_id = $folder;
        $this->path_to_archive_directory = CONTENT_RESOURCE_LOCAL_PATH . '/' . $folder . '_working/';
        $this->archive_builder = new \eol_schema\ContentArchiveBuilder(array('directory_path' => $this->path_to_archive_directory));                
        // */ // ----------- end -----------

        // /*
        require_library('connectors/TraitGeneric');
        $this->func = new TraitGeneric($resource_id, $this->archive_builder);
        // */

        self::parse_tsv_file($file, "process_country_file");
        $this->archive_builder->finalize(TRUE);
        Functions::finalize_dwca_resource($resource_id, false, true, "", CONTENT_RESOURCE_LOCAL_PATH, array('go_zenodo' => false)); //designed not to go to Zenodo at this point.
    }
    private function parse_tsv_file($file, $task)
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
                $rec = array_map('trim', $rec); //print_r($rec); //exit("\nstop muna\n");
                // ---------------------------------------start
                if($task == "divide_into_country_files") {
                    self::save_to_different_country_files($rec);
                }
                // ---------------------------------------end
                if($task == "process_country_file") { //print_r($rec); exit("\nelix 1\n");
                    self::process_country_file($rec);
                    // break; //debug only | process just 1 species
                }
            }
            // if($i > 20) break; //debug only
        }
    }
    private function process_country_file($rec)
    {   /*Array(
            [specieskey] => 1710962
            [SampleSize] => 16
            [countrycode] => AD
        )*/
        if($species_info = self::assemble_species($rec)) { //print_r($species_info); //exit;
            if(!in_array($species_info['taxonomicStatus'], array('doubtful'))) {
                $taxonID = self::write_taxon($species_info);
                $species_info['SampleSize'] = $rec['SampleSize'];
                if(@$rec['countrycode']) self::write_traits($species_info, $taxonID);    
            }
        }
    }
    function assemble_species($rec)
    {
        $options = $this->download_options;
        $options['expire_seconds'] = false;
        if($json = Functions::lookup_with_cache($this->service['species'].$rec['specieskey'], $options)) {
            $rek = json_decode($json, true); //print_r($rek); exit;

            if(!@$rek['key']) return false;
            if(@$this->check_species_exclusion_for_this_ctry) {
                if(!$this->GBIFTaxonomy->is_id_valid_waterbody_taxon($rec['specieskey'])) return false;
            }

            $save = array();
            $save['taxonID']                    = $rek['key']; //same as $rec['specieskey']
            $save['scientificName']             = $rek['scientificName'];
            $save['canonicalName']              = @$rek['canonicalName'];
            $save['scientificNameAuthorship']   = $rek['authorship'];
            $save['taxonRank']                  = strtolower($rek['rank']);
            $save['parentNameUsageID']          = @$rek['parentKey'];
            $save['taxonomicStatus']            = strtolower($rek['taxonomicStatus']);
            $save['furtherInformationURL']      = "https://www.gbif.org/species/".$rek['key'];
            // /* stats only
            @$this->debug['ranks'][$save['taxonRank']]++;
            @$this->debug['statuses'][$save['taxonomicStatus']]++;
            // */
            return $save;
        }
        exit("\nSpecies Key not found: [".$rec['specieskey']."]\n");
}
    private function save_to_different_country_files($rec)
    {   /*Array(
            [specieskey] => 2508277
            [countrycode] => FR
        )*/
        $country_code = $rec['countrycode'];
        $file = $this->country_path.'/'.$country_code.'.tsv';
        if(!isset($this->country['encountered'][$country_code])) {
            $this->country['encountered'][$country_code] = '';
            $f = Functions::file_open($file, "w");
            $headers = array_keys($rec);
            $headers = self::use_label_SampleSize_forCount($headers);
            $headers[] = date("d F Y");
            fwrite($f, implode("\t", $headers)."\n");
            fclose($f);
        }
        $f = Functions::file_open($file, "a");
        fwrite($f, implode("\t", $rec)."\n");
        fclose($f);
    }
    private function parse_tsv_file_caching($file, $counter = false)
    {   echo "\nReading file: [$file]\n";
        $i = 0; $final = array();

        // /* caching
        $m = 2634653/6;
        // */

        foreach(new FileIterator($file) as $line => $row) { $i++; // $row = Functions::conv_to_utf8($row);
            // if(($i % 1000) == 0) sleep(30);
            // if(($i % 1000) == 0) echo "\n $i ";
            // echo " [$i $counter]";
            if($i == 1) $fields = explode("\t", $row);
            else {

                // /* breakdown when caching
                $cont = false;
                if($counter == 1)       {if($i >= 1    && $i < $m)    $cont = true;}
                elseif($counter == 2)   {if($i >= $m   && $i < $m*2)  $cont = true;}
                elseif($counter == 3)   {if($i >= $m*2 && $i < $m*3)  $cont = true;}
                elseif($counter == 4)   {if($i >= $m*3 && $i < $m*4)  $cont = true;}
                elseif($counter == 5)   {if($i >= $m*4 && $i < $m*5)  $cont = true;}
                elseif($counter == 6)   {if($i >= $m*5 && $i < $m*6)  $cont = true;}
                else exit("\ncounter not defined...\n");                
                if(!$cont) continue;
                // */

                if(!$row) continue;
                echo " [$i $counter]";
                if(($i % 2000) == 0) sleep(5);

                $tmp = explode("\t", $row);
                $rec = array(); $k = 0;
                foreach($fields as $field) { $rec[$field] = @$tmp[$k]; $k++; }
                $rec = array_map('trim', $rec); //print_r($rec); //exit("\nstop muna\n");
                /*Array(
                    [specieskey] => 1000148
                    [countrycode] => JP
                )*/
                $options = $this->download_options;
                $options['expire_seconds'] = false;
                // if($json = Functions::lookup_with_cache($this->service['country'].$rec['countrycode'], $options)) {
                    // print_r(json_decode($json, true));
                // }
                if($json = Functions::lookup_with_cache($this->service['species'].$rec['specieskey'], $options)) {
                    // print_r(json_decode($json, true));
                }
                // break; //debug only
            }
            // if($i >= 25) break; //debug only
        }
    }
    private function download_extract_gbif_zip_file()
    {
        echo "\ndownload_extract_gbif_zip_file...\n";
        // /* main operation - works OK
        require_library('connectors/INBioAPI');
        $func = new INBioAPI();
        $ret = $func->download_extract_zip_file($this->zip_file, $this->destination); // echo "\n[$ret]\n";
        if(preg_match("/inflating:(.*?)elix/ims", $ret.'elix', $arr)) {
            $csv_path = trim($arr[1]); echo "\n[$csv_path]\n";
            // [/Volumes/AKiTiO4/other_files/GBIF_occurrence/0036064-241126133413365.csv]
            return $csv_path;
        }
        return false;
        // */

        /* during dev only
        return "/Volumes/AKiTiO4/other_files/GBIF_occurrence/Country_checklists/0036064-241126133413365.csv";
        */
    }
    private function initialize_countries_from_csv()
    {
        $final = array();
        $options = $this->download_options;
        $options['expire_seconds'] = 60*60*24; //1 day
        $options['cache'] = 1;
        if($filename = Functions::save_remote_file_to_local($this->service['country_codes'], $options)) {
            $i = 0;
            foreach(new FileIterator($filename) as $line_number => $row) { $i++;
                if($i == 1) $fields = explode("\t", $row);
                else {
                    if(!$row) continue;
                    $tmp = explode("\t", $row);
                    $rec = array(); $k = 0;
                    foreach($fields as $field) { $rec[$field] = @$tmp[$k]; $k++; }
                    $rec = array_map('trim', $rec); //print_r($rec); exit("\nstopx\n");
                    /*Array(
                        [Name] => Afghanistan
                        [Code] => AF
                    )*/
                    $final[$rec['Code']] = str_replace('"', '', $rec['Name']);
                }    
            } //end foreach()
            unlink($filename);
        }
        return $final;
    }
    private function get_country_name_from_file($file) //e.g. $file "/Volumes/Crucial_4TB/other_files/GBIF_occurrence/Country_checklists/countries/AD.tsv"
    {
        $abbrev = pathinfo($file, PATHINFO_FILENAME); //e.g. "PH"
        if($country_name = @$this->country_code_name_info[$abbrev]) {
            $lower = strtolower(str_replace(" ", "", $country_name));
            echo "\nCountry: [$abbrev] [$country_name] [$lower]\n";
            return array('lower_case' => $lower, 'orig' => $country_name, 'abbrev' => $abbrev);
        }
        echo("\nCountry abbrev. not found [$abbrev]\n");
        $this->debug['Country abbrev. not found'][$abbrev] = '';
    }
    // ======================================= below copied template    
    private function write_taxon($rek)
    {   
        $taxon = new \eol_schema\Taxon();
        $taxon->taxonID                    = $rek['taxonID'];
        $taxon->scientificName             = $rek['scientificName'];
        $taxon->canonicalName              = $rek['canonicalName'];
        $taxon->scientificNameAuthorship   = $rek['scientificNameAuthorship'];
        $taxon->taxonRank                  = $rek['taxonRank'];
        $taxon->parentNameUsageID          = $rek['parentNameUsageID'];
        $taxon->taxonomicStatus            = $rek['taxonomicStatus'];
        $taxon->furtherInformationURL      = $rek['furtherInformationURL'];
        if(!isset($this->taxon_ids[$taxon->taxonID])) {
            $this->taxon_ids[$taxon->taxonID] = '';
            $this->archive_builder->write_object_to_file($taxon);
            $this->accross_the_board_taxa[$taxon->taxonID] = $taxon->canonicalName ? $taxon->canonicalName : $taxon->scientificName;
            // (Condition) ? (Statement1) : (Statement2); --- ternary operator
        }
        return $taxon->taxonID;
    }
    private function write_traits($rek, $taxonID)
    {
        $save = array();
        $save['taxon_id'] = $taxonID;
        $save['source'] = $rek['furtherInformationURL'];
        $save['bibliographicCitation'] = $this->bibliographicCitation;        

        $mType = 'http://eol.org/schema/terms/Present';

        if($mValue = self::get_country_uri($this->country_name)) {
            $save['measurementRemarks'] = $this->country_name;
            $save["catnum"] = $taxonID.'_'.$mType.$mValue; //making it unique. no standard way of doing it.
            // if(in_array($mValue, $this->investigate)) exit("\nhuli ka 2\n");
            $ret = $this->func->add_string_types($save, $mValue, $mType, "true");
        }
        // ---------------- write child record in MoF: SampleSize
        /*
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
        if($measurementID = $ret['measurementID']) {
            if($measurementValue = @$rek['SampleSize']) {
                $measurementType = "http://eol.org/schema/terms/SampleSize";
                $parentMeasurementID = $measurementID;
                self::write_child($measurementType, $measurementValue, $parentMeasurementID);
            }    
        }
    }
    private function write_child($measurementType, $measurementValue, $parentMeasurementID) //func was copied from: Move_col_inMoF_2child_inMoF_API.php
    {
        $m2 = new \eol_schema\MeasurementOrFact_specific();
        $rek = array();
        $rek['http://rs.tdwg.org/dwc/terms/measurementID'] = md5("$measurementType|$measurementValue|$parentMeasurementID");
        $rek['http://rs.tdwg.org/dwc/terms/measurementType'] = $measurementType;
        $rek['http://rs.tdwg.org/dwc/terms/measurementValue'] = $measurementValue;
        $rek['http://eol.org/schema/parentMeasurementID'] = $parentMeasurementID;
        $uris = array_keys($rek);
        foreach($uris as $uri) {
            $field = pathinfo($uri, PATHINFO_BASENAME);
            $m2->$field = $rek[$uri];
        }
        if(!isset($this->measurementIDs[$m2->measurementID])) {
            $this->measurementIDs[$m2->measurementID] = '';
            $this->archive_builder->write_object_to_file($m2);
        }
    }
    private function get_country_uri($country)
    {   //Antigua and Barbuda; what is saved in EOL terms file is: "Antigua And Barbuda"
        $country = str_replace(" and ", " And ", $country);
        $country = str_replace(" of ", " Of ", $country);
        $country = str_replace(" the ", " The ", $country);

        // /* manual mapping
        if($country == 'Bonaire, Saint Eustatius, And Saba') $country = 'Bonaire, Saint Eustatius And Saba';
        if($country == 'Cocos Islands') $country = 'Cocos [Keeling] Islands';
        if($country == 'Federated States Of Micronesia') $country = 'Micronesia';
        if($country == 'South Georgia And The South Sandwich Islands') $country = 'South Georgia And South Sandwich Islands';
        if($country == 'Guinea Bissau') $country = 'Guinea-Bissau';
        if($country == 'Bailiwick Of Jersey') return 'http://www.geonames.org/3042142';
        if($country == 'Mariana Islands') $country = 'Northern Mariana Islands'; //uri: http://www.geonames.org/4041468
        if($country == 'Saint-Pierre et Miquelon') $country = 'Saint-Pierre Et Miquelon';
        if($country == 'Saint Helena Ascension And Tristan da Cunha') $country = 'Saint Helena'; //'http://www.geonames.org/3370751';
        if($country == 'Territory Of The French Southern And Antarctic Lands') $country = 'French Southern Territories';
        if($country == 'Timor-Leste') $country = 'East Timor';
        if($country == 'US Virgin Islands') $country = 'U.S. Virgin Islands';
        if($country == 'Wallis et Futuna') $country = 'Wallis Et Futuna Islands';
        if($country == 'Eswatini') return 'http://www.geonames.org/934841';
        if($country == 'Bosnia And Herzegovina') return 'http://www.geonames.org/3277605';
        if($country == 'Myanmar') return 'http://www.geonames.org/1327865';
        // */

        if($uris = @$this->value_uris[$country]) {
            if(count($uris) == 1) return $uris[0];
            else {
                foreach($uris as $uri) {                    
                    if(stripos($uri, "geonames.org") !== false) return $uri; //string is found
                }
                return $uris[0];
            }
        }
        else {
            /*
            [No URI for country] => Array(
                    [The Gambia] => 
                    [Territory Of Heard Island And McDonald Islands] => Territory Of Heard Island And Mcdonald Islands
                    [The Netherlands] => 


                    */
            // /*
            switch ($country) { //put here customized mapping
                case "Saint Barthélemy":                                return "http://www.geonames.org/3578475";
                case "Saint Barthelemy":                                return "http://www.geonames.org/3578475";
                case "Republic Of The Congo":                           return "https://www.geonames.org/2260494";
                case "Territory Of Heard Island And McDonald Islands":  return "http://www.geonames.org/1547314";

                /* copied template
                name: Bonaire, Saint Eustatius And Saba
                type: value
                uri: http://www.geonames.org/7626844             
                
                name: Saint Barthelemy
                type: value
                uri: http://www.geonames.org/3578475                
                */
            }
            // */
        }

        // /* next iteration e.g. "The Bahamas"
        if(substr($country, 0, 4) == 'The ') {
            $country = trim(substr($country, 3, strlen($country)));
            // echo "\n----------------------------try again ($country)\n";
            if($uri = self::get_country_uri($country)) return $uri;
        }
        // */


        // print_r($this->values_uri); //debug only
        echo ("\nNo URI for [$country]"); //print_r($this->value_uris); print_r($this->value_uris[$country]);  exit("\nstop munax\n");
        $this->debug['No URI for country'][$country] = '';
        return false;
    }
    private function assemble_terms_yml()
    {
        require_library('connectors/EOLterms_ymlAPI');
        $func = new EOLterms_ymlAPI(false, false);

        /* doesn't work well, it gets the http://marineregions.org/xxx
        $ret = $func->get_terms_yml('value'); //sought_type is 'value' --- REMINDER: labels can have the same value but different uri
        foreach($ret as $label => $uri) $this->uri_values[$label] = $uri;
        echo("\nEOL Terms: ".count($this->uri_values)."\n"); //debug only
        */

        // /* ideal for country nanes
        $this->value_uris = $func->get_terms_yml('ONE_TO_MANY'); // $ret[name][] = uri
        // */
    }
    function is_this_DwCA_old_YN($filename) //SC_andorra.tar.gz
    {
        $filename_date = self::get_date_of_this_DwCA($filename);
        echo "\ndate of $filename: $filename_date\n";
        // get date today minus 2 months
        $date = date("Y-m-d");
        $today = date_create($date);
        echo "\n-------new...\ntoday: ".date_format($today, 'Y-m-d')."\n";
        date_sub($today, date_interval_create_from_date_string('2 month')); //previously '2 months'
        $minus_2_months = date_format($today, 'Y-m-d');
        // compare
        echo "minus 1 month: " .$minus_2_months. "\n";
        echo "\n$filename_date < $minus_2_months \n";
        if($filename_date < $minus_2_months) return true;
        else return false;
    }
    private function get_date_of_this_DwCA($filename)
    {
        // /* NEW:
        $file = CONTENT_RESOURCE_LOCAL_PATH . $filename;
        if(file_exists($file)) return date("Y-m-d", filemtime($file));
        else                   return date("Y-m-d", false);
        // */
        /* OLD:
        $file = CONTENT_RESOURCE_LOCAL_PATH.'wikipedia-'.$filename.'.tar.gz';
        if(file_exists($file)) return date("Y-m-d", filemtime($file));
        else                   return date("Y-m-d", false);
        */
    }
    private function get_dwca_filename($str)
    {
        // /* manual adjustment
        if($str == "North Korea") $str = "North Korean";
        // */
        $q = '+title:"'.$str.'" +title:2019 +title:National +title:Checklists'; //obsolete
        $q = '+title:"'.$str.'" -title:2019 -title:2017 +title:National +title:Checklists'; //latest
        if($obj = $this->zenodo->get_depositions_by_part_title($q)) { //print_r($obj[0]); 

            // /* added during dev, when moving to PHP 8.2 and limited no. of records being processed.
            if($val = @$obj[0]) {}
            return false;
            // */

            $f1 = $obj[0]['files'][0]['filename'];
            $path = $obj[0]['metadata']['related_identifiers'][0]['identifier'];
            $f2 = pathinfo($path, PATHINFO_BASENAME);
            
            // if(file_exists(CONTENT_RESOURCE_LOCAL_PATH.$f1)) echo "\nDwCA exists.\n";
            // else                                             exit("\nERROR: DwCA does not exist\n[$str]\n[$f1]\n[$f2]\n[$path]\n");

            if($f1 == $f2 && $f1) return str_ireplace(".tar.gz", "", $f1);
            elseif(substr($f1,0,3) == 'SC_' && substr($f1, -7) == '.tar.gz') return str_ireplace(".tar.gz", "", $f1);
            elseif(substr($f2,0,3) == 'SC_' && substr($f2, -7) == '.tar.gz') return str_ireplace(".tar.gz", "", $f2);
            else {
                exit("\nERROR 1: Cannot find DwCA\n[$str]\n[$f1]\n[$f2]\n[$path]\n");
            }
        }
        else exit("\nERROR 2: Cannot find DwCA\n[$str]\n[$f1]\n[$f2]\n[$path]\n");
    }
    private function use_label_SampleSize_forCount($headers)
    {
        $final = array();
        foreach($headers as $h) {
            if(substr($h,0,5) == 'COUNT') $h = 'SampleSize';
            $final[] = $h;
        }
        return $final;
    }
}
?>