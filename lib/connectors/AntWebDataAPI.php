<?php
namespace php_active_record;
/*  connector: [dwca_utility.php _ 24 | first run took: 4 hrs 57 mins] 
This is run after 24.php
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class AntWebDataAPI
{
    function __construct($taxon_ids, $archive_builder, $resource_id)
    {
        $this->resource_id = $resource_id;
        $this->taxon_ids = $taxon_ids;
        $this->archive_builder = $archive_builder;
        
        // $this->path_to_archive_directory = CONTENT_RESOURCE_LOCAL_PATH . '/' . $folder . '_working/';
        // $this->archive_builder = new \eol_schema\ContentArchiveBuilder(array('directory_path' => $this->path_to_archive_directory));
        // $this->taxon_ids = array();
        // $this->occurrence_ids = array();
        // $this->media_ids = array();
        // $this->agent_ids = array();
        $this->debug = array();
        $this->api['genus_list'] = 'http://www.antweb.org/api/v2/?rank=genus&limit=100&offset=';
        $this->api['specimens'] = 'http://www.antweb.org/api/v2/?limit=100&offset='; //&genus=Acanthognathus
        
        $this->limit = 100;
        $this->download_options = array("timeout" => 60*60, "expire_seconds" => 60*60*24*25, 'download_attempts' => 2, 'delay_in_minutes' => 0.5);
        // $this->download_options['expire_seconds'] = false; //comment in normal operation
        $this->ant_habitat_mapping_file = "https://github.com/eliagbayani/EOL-connector-data-files/blob/master/AntWeb/ant habitats mapping.xlsx?raw=true";
        $this->investigate = array("http://purl.obolibrary.org/obo/ENVO_01000680", "http://purl.obolibrary.org/obo/ENVO_01000477");
    }
    public function initialize_mapping()
    {
        $mappings = Functions::get_eol_defined_uris(false, true); //1st param: false means will use 1day cache | 2nd param: opposite direction is true
        echo "\n".count($mappings). " - default URIs from EOL registry.";
        $this->uri_values = Functions::additional_mappings($mappings, 60*60*24); //add more mappings used in the past
        // echo("\n Philippines: ".$this->uri_values['Philippines']."\n");
        // echo("\n Brazil: ".$this->uri_values['Brazil']."\n"); exit;
        return $this->uri_values;
    }
    function start($harvester, $row_type)
    {
        // /* New: strict use of URIs only from EOL terms file
        require_library('connectors/Functions_Pensoft');
        $func = new Functions_Pensoft();
        $this->allowed_terms_URIs = $func->get_allowed_value_type_URIs_from_EOL_terms_file($this->download_options);
        echo ("\nallowed_terms_URIs from EOL terms file: [".count($this->allowed_terms_URIs)."]\n");
        // */
        
        self::initialize_mapping();
        // print_r($this->taxon_ids); exit;
        $genus_list = self::get_all_genus($harvester->process_row_type($row_type));
        echo "\n total genus: ".count($genus_list);
        /* $genus_list = self::get_all_genus_using_api(); //working but instead of genus; family values are given by API */
        self::process_genus($genus_list);
        print_r($this->debug);
    }
    private function process_genus($genus_list)
    {
        $habitat_map = self::initialize_habitat_mapping();
        $i = 0; $total = count($genus_list);
        foreach($genus_list as $genus) {
            $i++; echo "\n processing $genus... $i of $total";
            $specimens = self::get_specimens_per_genus($genus);
            if(!$specimens) continue;
            // print_r($specimens);
            // echo("\nNo. of specimens: ".count($specimens)."\n"); //good debug
            foreach($specimens as $rec)
            {   /* Array(
                       [url] => http://antweb.org/api/v2/?occurrenceId=CAS:ANTWEB:jtl725991
                       [catalogNumber] => jtl725991
                       [family] => formicidae
                       [subfamily] => myrmicinae
                       [genus] => Acanthomyrmex
                       [specificEpithet] => indet
                       [scientific_name] => acanthomyrmex indet
                       [typeStatus] => 
                       [stateProvince] => Sabah
                       [country] => Malaysia
                       [dateIdentified] => 2014-10-01
                       [dateCollected] => 2014-08-02
                       [habitat] => mature wet forest ex sifted leaf litter
                       [minimumElevationInMeters] => 350
                       [biogeographicregion] => Indomalaya
                       [geojson] => Array(
                               [type] => point
                               [coord] => Array(
                                       [0] => 4.64045
                                       [1] => 116.61342)
                           )
                   )*/
                
                // if($rec['country'] == 'Malaysia') print_r($rec); //good debug
                // print_r($rec);
                
                // $this->debug['typeStatus'][$rec['typeStatus']] = '';
                
                //start fix/select scientific_name | per https://eol-jira.bibalex.org/browse/DATA-1713?focusedCommentId=61541&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-61541
                $rec['scientific_name'] = self::fix_scientific_name($rec);
                //end 
                
                $rec['taxon_id'] = strtolower($rec['scientific_name']);
                $rec['catnum'] = $rec['url'];
                if(!$rec['url']) {
                    print_r($rec);
                    exit("\ninvestigate no url\n");
                }
                $rec['url'] = self::compute_furtherInformationURL($rec['scientific_name']);
                
                if($country = @$rec['country']) {
                    if($country_uri = self::get_country_uri($country)) {
                        if(!isset($this->taxon_ids[$rec['taxon_id']])) self::add_taxon($rec);
                        if(in_array($country_uri, $this->investigate)) exit("\nhuli ka A\n");
                        self::add_string_types($rec, $country_uri, "http://eol.org/schema/terms/Present", "true");
                    }
                    else $this->debug['undefined country'][$country] = '';
                }
                
                if($habitat = @$rec['habitat']) {
                    if($habitat_uri = @$this->uri_values[$habitat]) {
                        if(!isset($this->taxon_ids[$rec['taxon_id']])) self::add_taxon($rec);
                        if(in_array($habitat_uri, $this->investigate)) exit("\nhuli ka B\n");
                        self::add_string_types($rec, $habitat_uri, "http://purl.obolibrary.org/obo/RO_0002303", "true");
                    }
                    elseif($val = @$habitat_map[$habitat]) { # use Pensoft here if still problematic
                        // echo "\nmapping OK [$val][$habitat]\n"; //good debug info
                        $habitat_uris = explode(";", $val);
                        $habitat_uris = array_map('trim', $habitat_uris);
                        foreach($habitat_uris as $habitat_uri) {
                            if(!$habitat_uri) continue;
                            if(!isset($this->taxon_ids[$rec['taxon_id']])) self::add_taxon($rec);
                            $rec['measurementRemarks'] = $habitat;
                            if(in_array($habitat_uri, $this->investigate)) exit("\nhuli ka C\n");
                            
                            if(isset($this->allowed_terms_URIs[$habitat_uri])) {
                                self::add_string_types($rec, $habitat_uri, "http://purl.obolibrary.org/obo/RO_0002303", "true");
                            }
                            else echo "\nNot in EOL terms file [$habitat_uri]\n";
                        }
                    }
                    // else $this->debug['undefined habitat'][$habitat] = ''; //commented so that build text will not be too long.
                }
                
            }
            // break; //debug - get only first genus
        }
    }
    public function initialize_habitat_mapping()
    {
        $final = array();
        if($local_xls = Functions::save_remote_file_to_local($this->ant_habitat_mapping_file, array('cache' => 1, 'download_wait_time' => 1000000, 'timeout' => 600, 'download_attempts' => 1, 'file_extension' => 'xlsx', 'expire_seconds' => false)))
        {
            // $local_xls = DOC_ROOT."/tmp/tmp_82784.file.xlsx";
            require_library('XLSParser');
            $parser = new XLSParser();
            debug("\n reading: " . $local_xls . "\n");
            $temp = $parser->convert_sheet_to_array($local_xls);
            
            $choices = array_keys($temp);
            array_shift($choices);
            // print_r($choices); exit;
            $i = -1;
            foreach($temp['string'] as $s) {
                $i++;
                if(!$s) continue;
                if(preg_match("/\[(.*?)\]/ims", $s, $arr)) $s = trim($arr[1]); //removes the brackets
                
                $val = "";
                foreach($choices as $choice) {
                    /* Original, good for the longest time. But it doesn't include those habitat e.g. 'port of entry' with blank URIs. n = 17,861
                    if($val = trim(@$temp[$choice][$i])) $final[$s] = $val;
                    */
                    // /* New, this one now includes the likes of 'port of entry'. That is even with blank URI. n = 29,349
                    if(@$final[$s]) {}
                    else {
                        if($val = trim(@$temp[$choice][$i])) $final[$s] = $val;
                        else $final[$s] = ''; //initialize
                    }
                    // */
                }
            }
        }
        unlink($local_xls);
        // print_r($final); exit("\n".count($final)."\nJust checking the spreadsheet\n");
        return $final;
    }
    private function fix_scientific_name($rec)
    {
        /*
        tetramorium kgac-afr02		Tetramorium kgac-afr02				Formicidae
        tetraponera psw105		Tetraponera psw105				Formicidae
        tetraponera continua_nr		Tetraponera continua_nr				Formicidae
        tetraponera indet		Tetraponera indet				Formicidae
        */
        $arr = explode(" ", $rec['scientific_name']);
        $genus = trim($arr[0]);
        if($val = @$arr[1]) $species = trim($val);
        else return $genus;
        
        if(strlen($species) <= 1) { //e.g. "Tapinoma a"
            // echo "\ntypeStatus: ".$rec['typeStatus'];
            return $genus;            
        }
        $chars = "- _ 0 1 2 3 4 5 6 7 8 9 cf. sp. indet.";
        $chars = explode(" ", $chars);
        foreach($chars as $char) {
            if(stripos($species, $char) !== false) { //string is found
                // echo "\ntypeStatus: ".$rec['typeStatus'];
                return $genus;
            }
        }

        $strings = "cf sp indet"; //exact match
        $strings = explode(" ", $strings);
        foreach($strings as $string) {
            if($string == $species) {
                // echo "\ntypeStatus: ".$rec['typeStatus'];
                return $genus;
            }
        }
        
        return $rec['scientific_name'];
    }
    private function add_taxon($rec)
    {
        $taxon = new \eol_schema\Taxon();
        $taxon->taxonID         = $rec['taxon_id'];
        $taxon->scientificName  = ucfirst($rec['scientific_name']);
        if($family = @$rec['family']) $taxon->family = ucfirst($family);
        if($taxon->family == "Formicidae") {
            $taxon->phylum  = 'Arthropoda';
            $taxon->class   = 'Insecta';
            $taxon->order   = 'Hymenoptera';
        }
        $taxon->furtherInformationURL = self::compute_furtherInformationURL($taxon->scientificName);
        /*
        $taxon->kingdom         = $t['dwc_Kingdom'];
        $taxon->genus           = $t['dwc_Genus'];
        $taxon->furtherInformationURL = $t['dc_source'];
        if($reference_ids = @$this->taxa_reference_ids[$t['int_id']]) $taxon->referenceID = implode("; ", $reference_ids);
        */
        $this->taxon_ids[$taxon->taxonID] = '';
        $this->archive_builder->write_object_to_file($taxon);
    }
    /* There is a special case for AntWeb. Multiple records with diff catnum have the same country information for a taxon.
    We need to exclude those redundant records. So we put here an additional script 'special' to handle that. */
    private function add_string_types($rec, $value, $measurementType, $measurementOfTaxon = "")
    {
        $taxon_id = $rec["taxon_id"];
        $catnum   = $rec["catnum"].$measurementType; //because one catalog no. can have 2 MeasurementOrFact entries. Each for country and habitat.
        
        /* didn't use per Jen's instruction since, Antweb's multiple records look legitimate - with differing metadata
        //start special -------------------------------------------------------------
        $var = md5($measurementType . $value . $rec['taxon_id']);
        if(isset($this->unique_measurements[$var])) return;
        //end special -------------------------------------------------------------
        */
        
        $occurrence_id = $this->add_occurrence($taxon_id, $catnum, $rec);

        $m = new \eol_schema\MeasurementOrFact();
        $m->occurrenceID       = $occurrence_id;
        $m->measurementOfTaxon = $measurementOfTaxon;
        if($measurementOfTaxon == "true") {
            $m->source      = @$rec["url"];
            $m->contributor = @$rec["contributor"];
            if($referenceID = @$rec["referenceID"]) $m->referenceID = $referenceID;
        }
        $m->measurementType  = $measurementType;
        $m->measurementValue = $value;
        // $m->bibliographicCitation = $this->bibliographic_citation;
        if($val = @$rec['measurementUnit'])     $m->measurementUnit = $val;
        if($val = @$rec['measurementMethod'])   $m->measurementMethod = $val;
        if($val = @$rec['statisticalMethod'])   $m->statisticalMethod = $val;
        if($val = @$rec['measurementRemarks'])  $m->measurementRemarks = $val;
        // $m->measurementID = Functions::generate_measurementID($m, $this->resource_id, 'measurement', array('occurrenceID', 'measurementType', 'measurementValue')); //3rd param is optional. If blank then it will consider all properties of the extension
        $m->measurementID = Functions::generate_measurementID($m, $this->resource_id); //3rd param is optional. If blank then it will consider all properties of the extension
        
        if(!isset($this->measurement_ids[$m->measurementID])) {
            $this->archive_builder->write_object_to_file($m);
            $this->measurement_ids[$m->measurementID] = '';
        }
        /*
        //start of special -------------------------------------------------------------
        $var = md5($m->measurementType . $m->measurementValue . $taxon_id);
        $this->unique_measurements[$var] = '';
        //end special -------------------------------------------------------------
        */
    }
    private function add_occurrence($taxon_id, $catnum, $rec)
    {
        $occurrence_id = md5($taxon_id . '_' . $catnum);
        
        $o = new \eol_schema\Occurrence();
        $o->occurrenceID = $occurrence_id;
        $o->taxonID = $taxon_id;
        /* Array(
                   [url] => http://antweb.org/api/v2/?occurrenceId=CAS:ANTWEB:jtl725991
                   *[catalogNumber] => jtl725991
                   [family] => formicidae
                   [subfamily] => myrmicinae
                   [genus] => Acanthomyrmex
                   [specificEpithet] => indet
                   [scientific_name] => acanthomyrmex indet
                   [typeStatus] => 
                   *[stateProvince] => Sabah
                   *[country] => Malaysia
                   *[dateIdentified] => 2014-10-01
                   *[dateCollected] => 2014-08-02
                   [habitat] => mature wet forest ex sifted leaf litter
                   [minimumElevationInMeters] => 350
                   *[biogeographicregion] => Indomalaya
                   [geojson] => Array(
                           [type] => point
                           [coord] => Array(
                                   [0] => 4.64045
                                   [1] => 116.61342)
                       )
               )*/
        $o->catalogNumber = @$rec['catalogNumber'];
        $o->dateIdentified = @$rec['dateIdentified'];
        $o->eventDate = @$rec['dateCollected'];
        $o->locality = '';
        if($val = @$rec['stateProvince']) {
            if($o->locality) $o->locality .= " stateProvince: $val.";
            else             $o->locality  = " stateProvince: $val.";
        }
        if($val = @$rec['country']) {
            if($o->locality) $o->locality .= " country: $val.";
            else             $o->locality  = " country: $val.";
        }
        if($val = @$rec['biogeographicregion']) {
            if($o->locality) $o->locality .= " biogeographicregion: $val.";
            else             $o->locality  = " biogeographicregion: $val.";
        }
        // $o->decimalLatitude
        // $o->decimalLongitude
        
        $o->occurrenceID = Functions::generate_measurementID($o, $this->resource_id, 'occurrence');
        if(isset($this->occurrence_ids[$o->occurrenceID])) return $o->occurrenceID;
        $this->archive_builder->write_object_to_file($o);
        $this->occurrence_ids[$o->occurrenceID] = '';
        return $o->occurrenceID;

        /* old ways
        $this->occurrence_ids[$unique_id] = '';
        return true;
        */
    }
    private function get_specimens_per_genus($genus)
    {
        $final = array();
        $offset = 0;
        while(true) {
            $url = $this->api['specimens'].$offset."&genus=$genus";
            // echo "\n[$url]";
            if($json = Functions::lookup_with_cache($url, $this->download_options)) {
                $arr = json_decode($json, true);
                // print_r($arr); //comment in real operation
                if(isset($arr['specimens']['empty_set'])) break;
                else $final = array_merge($final, $arr['specimens']);
                if(count($arr['specimens']) < $this->limit) break;
            }
            else {
                echo "\nCannot access: [$url]. Will go to next genus.";
                break;
            }
            $offset += $this->limit;
        }
        return $final;
    }
    private function get_all_genus_using_api()
    {
        $final = array();
        $offset = 0;
        while(true) {
            $url = $this->api['genus_list'].$offset;
            if($json = Functions::lookup_with_cache($url, $this->download_options)) {
                $arr = json_decode($json, true);
                foreach($arr['specimens'] as $specimen) $final[] = $specimen['genus'];
                if(count($arr['specimens']) < $this->limit) break;
            }
            $offset += $this->limit;
        }
        return array_unique($final);
    }
    // /* working well but not used. Used API instead
    private function get_all_genus($records)
    {
        $genus_list = array();
        foreach($records as $rec) {
            // $keys = array_keys($rec); print_r($keys);
            $sciname = $rec['http://rs.tdwg.org/dwc/terms/scientificName'];
            $arr = explode(" ", $sciname);
            $genus = $arr[0];
            $genus_list[$genus] = '';
        }
        return array_keys($genus_list);
    }
    // */
    private function get_country_uri($country)
    {
        if($country_uri = @$this->uri_values[$country]) return $country_uri;
        else {
            /*
            switch ($country) { //put here customized mapping
                case "Port of Entry":   return false; //"DO NOT USE";
                // just examples below. Real entries here were already added to /cp_new/GISD/mapped_location_strings.txt
                // case "United States of America":        return "http://www.wikidata.org/entity/Q30";
                // case "Dutch West Indies":               return "http://www.wikidata.org/entity/Q25227";
            }
            */
        }
    }
    private function compute_furtherInformationURL($sciname)
    {
        $rank = '';
        $sciname = trim($sciname);
        $parts = explode(" ", $sciname);
        if($val = $parts[0]) {
            $genus = $val;
            $rank = "genus";
        }
        if($val = @$parts[1]) {
            $species = $val;
            $rank = "species";
        }
        if($rank == "genus") return "https://www.antweb.org/description.do?genus=".$genus."&rank=genus";
        if($rank == "species") return "https://www.antweb.org/description.do?genus=".$genus."&name=".$species."&rank=species";
        // https://www.antweb.org/description.do?genus=Zatania&rank=genus
        // https://www.antweb.org/description.do?genus=tetramorium&name=krishnani&rank=species
        return "";
    }
}
?>
