<?php
namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from run_gnparser_dwca.php] */
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class DwCA_RunGNParser
{
    function __construct($archive_builder, $resource_id, $archive_path)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        $this->archive_path = $archive_path;
        $this->download_options = array('cache' => 1, 'resource_id' => $resource_id, 'expire_seconds' => 60*60*24*1, 'download_wait_time' => 500000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        // $this->paths['wikidata_hierarchy'] = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/master/wikidata/wikidataEOLidMappings.txt';
        $this->service['GNParser'] = 'https://parser.globalnames.org/api/v1/';
        /*
        --------------------------------------------------- Used latest gnparser (as of 18Jul2025) from:
        https://github.com/gnames/gnparser/releases/tag/v1.11.6
        https://github.com/gnames/gnparser/releases/download/v1.11.6/gnparser-v1.11.6-linux-arm.tar.gz
        https://github.com/gnames/gnparser/releases/download/v1.11.6/gnparser-v1.11.6-linux-arm.tar.gz
        ---------------------------------------------------
        Install gnparser in command line: https://github.com/gnames/gnparser/blob/master/README.md#installation

        gnparser file -f json-compact --input step3_scinames.txt --output step3_gnparsed.txt
        gnparser name -f simple 'Tricornina (Bicornina) jordan, 1964'
        gnparser name -f simple 'Ceroputo pilosellae Šulc, 1898'
        gnparser name -f simple 'The Myxobacteria'
        */
        /* Name matching process using rank information https://github.com/EOL/ContentImport/issues/33 */
        /* It's best to use the simple canonicals for all taxa except for those of rank subgenera, sections, and subsections. 
        For taxa with these ranks, we want to use the full canonicals.
        */
        $this->ranks_to_use_full_canonicals = array("subgenera", "subgenus", "sections", "section", "subsections", "subsection");
        $this->debug = array();
    }
    /*================================================================= STARTS HERE ======================================================================*/
    function start($info)
    {
        $tables = $info['harvester']->tables; // print_r($tables); exit;
        $extensions = array_keys($tables); //print_r($extensions); exit;
        $tbl = "http://rs.tdwg.org/dwc/terms/taxon";
        $meta = $tables[$tbl][0];

        // /* ---------- Initialize so ancestry look-up is possible
        require_library('connectors/DHConnLib');
        $this->func = new DHConnLib(1, $meta->file_uri);
        $this->func->initialize_get_ancestry_func();
        echo "\nmeta file uri: [$meta->file_uri]\n";
        // for testing... worked OK
        // if($ancestry = $this->func->get_ancestry_of_taxID("urn:lsid:marinespecies.org:taxname:420831")) {
        //     print_r($ancestry); //worked OK
        //     foreach($ancestry as $id) print_r(@$this->func->taxID_info[$id]);
        // }
        // else echo "\nNo ancestry\n";
        // exit("\nstop muna...\n");
        // ---------- */

        /*Array(
            [0] => http://rs.tdwg.org/dwc/terms/taxon
        )*/
        self::process_table($meta, 'write_archive');
        if($this->debug) Functions::start_print_debug($this->debug, $this->resource_id);
    }
    private function process_table($meta, $what)
    {   //print_r($meta);
        echo "\nprocess_table: [$what] [$meta->file_uri]...\n"; $i = 0;
        foreach(new FileIterator($meta->file_uri) as $line => $row) { $i++;
            if(($i % 10000) == 0) echo "\n".number_format($i)." - ";
            if($meta->ignore_header_lines && $i == 1) continue;
            if(!$row) continue;
            // $row = Functions::conv_to_utf8($row); //possibly to fix special chars. but from copied template
            $tmp = explode("\t", $row);
            $rec = array(); $k = 0;
            foreach($meta->fields as $field) {
                if(!$field['term']) continue;
                $rec[$field['term']] = $tmp[$k];
                $k++;
            }
            // print_r($rec); exit;
            /*Array(
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
            )*/

            // /* Not recognized fields e.g. WoRMS2EoL.zip
            if(isset($rec['http://purl.org/dc/terms/rights'])) unset($rec['http://purl.org/dc/terms/rights']);
            if(isset($rec['http://purl.org/dc/terms/rightsHolder'])) unset($rec['http://purl.org/dc/terms/rightsHolder']);
            // */

            if($what == 'write_archive') {
                // /* assign canonical name
                $taxonID = $rec['http://rs.tdwg.org/dwc/terms/taxonID'];
                $scientificName = $rec['http://rs.tdwg.org/dwc/terms/scientificName'];
                $taxonRank = @$rec['http://rs.tdwg.org/dwc/terms/taxonRank'];
                
                // $rec['http://rs.tdwg.org/dwc/terms/canonicalName'] = self::lookup_canonical_name($scientificName, 'simple'); //working but too many calls
                
                $rec['http://rs.tdwg.org/dwc/terms/canonicalName'] = self::evaluate_name_and_rank($scientificName, $taxonRank, $rec);
                // */

                // print_r($rec); exit;
                $o = new \eol_schema\Taxon();
                $uris = array_keys($rec); // print_r($uris); //exit;
                foreach($uris as $uri) {
                    $field = self::get_field_from_uri($uri);
                    $o->$field = $rec[$uri];
                }
                $this->archive_builder->write_object_to_file($o);
            }
            // if($i >= 5) break;
        }
    }
    private function evaluate_name_and_rank($scientificName, $taxonRank, $rec)
    {
        $gnparser_type = "simple";
        if(in_array($taxonRank, $this->ranks_to_use_full_canonicals)) $gnparser_type = "full";
        else {  
            /* names like these should get the full canonicals
                "Oscillatoria sect. Prolificae"
                "Heuchera flabellifolia var. subsecta Rosend., Butters & Lakela"
                "Acetobacter (subgen. Acetobacter) aceti"
                "Ochthebius (Subgenus) queenslandicus Hansen, M., 1998"
            */
            // ("subgenera", "subgenus", "sections", "section", "subsections", "subsection");
            $words = array("sect.", " section ", "subsect", "subgen.", "(subgenus)", " subgenus ");
            foreach($words as $word) {
                if(stripos($scientificName, $word) !== false) $gnparser_type = "full"; //string is found
            }
        }

        $canonical = self::run_gnparser($scientificName, $gnparser_type);
        if(!$canonical) {
            $this->debug["investigate 2: blank canonical"]["sn:[$scientificName] r:[$taxonRank] cn:[$canonical]"] = '';
            return "";
        }
        $canonical = trim($canonical);
        /* 2. If the full canonical for a subgenus, section (aka section botany or section zoology), 
        or subsection (aka subsection botany or subsection zoology) is of the form (A subgen. B | A sect. B | A subsect. B) respectively, 
        we have the right canonical for the taxon, and we can stop there.
        */
        if(in_array($taxonRank, array("subgenus", "section", "subsection"))) {
            if(stripos($canonical, " ") !== false) {} //not 1 word //string is found
            else {
                /* option 1:
                However, if the full canonical is just a simple one-part name, we are missing information. 
                If possible, we should get this information from the parent taxon. 
                For our purposes, the proper canonical for a subgenus, section, or subsection 
                is usually of the form: Canonical of the parent taxa subgen.| sect. |subsect. simple canonical of the subgenus/section/subsection.
                */
                if($taxonRank == 'subgenus') {
                    if($genus = @$rec['http://rs.tdwg.org/dwc/terms/genus']) {
                        $canonical_of_parent = self::run_gnparser($genus, 'simple');
                        $canonical_of_subgenus = self::run_gnparser($scientificName, 'simple');
                        $canonical = "$canonical_of_parent subgen. $canonical_of_subgenus";
                        $this->debug["trio_1: got parent genus; rank = subgenus"]["[$scientificName][$taxonRank][$canonical]"] = '';
                        return $canonical;
                    }
                }
                if($canonical_of_parent = self::get_canonical_of_parent($scientificName, $taxonRank, $rec)) {
                    $canonical_of_subgenus_or_section_or_subsection = self::run_gnparser($scientificName, 'simple');
                    $middle = self::get_middle_part($taxonRank); //either "subgen." or "sect." or "subsect."
                    $canonical = "$canonical_of_parent $middle $canonical_of_subgenus_or_section_or_subsection";
                    $this->debug["trio_2"]["[$scientificName][$taxonRank][$canonical]"] = '';
                    return $canonical;
                }
                else {
                    $this->debug["investigate 1: wrong canonical for trio. Cannot get its parent."]["sn:[$scientificName] r:[$taxonRank] cn:[$canonical]"] = '';
                }
            }
        }
        return $canonical;
    }
    private function get_canonical_of_parent($scientificName, $taxonRank, $rec)
    {
        if($taxonID = $rec['http://rs.tdwg.org/dwc/terms/taxonID']) {
            if($parent_id = self::get_parent_id_given_taxonID($taxonID)) {
                if($rek = @$this->func->taxID_info[$parent_id]) { // print_r($rek);
                    /*Array(
                        [pID] => urn:lsid:marinespecies.org:taxname:156852
                        [r] => species
                        [n] => Ensitellops protextus (Conrad, 1841)
                    )*/
                    if($parent_sciname = @$rek['n']) {
                        $canonical_of_parent = self::run_gnparser($parent_sciname, 'simple');
                        return $canonical_of_parent;
                    }
                    echo("\n-=-=-=-=-=-=-=-=-=-=\nCheck muna, at this point:[$scientificName] [$taxonRank]\n");
                    print_r($rec); exit("\n");
                }
            }
        }
    }
    private function get_parent_id_given_taxonID($taxonID)
    {
        if($ancestry = $this->func->get_ancestry_of_taxID($taxonID)) {
            if($parent_id = @$ancestry[1]) return $parent_id; //gets the 2nd record, index 1. The first record index 0 is the taxon in question.
        }
        return false;
    }
    private function get_middle_part($rank)
    {
        if($rank == 'subgenus')     return "subgen.";
        if($rank == 'section')      return "sect.";
        if($rank == 'subsection')   return "subsect.";
    }
    private function is_one_word($str)
    {
        if(strpos($str, " ") !== false) return false; //not 1 word //string is found
        return true;
    }
    private function format_sciname($str)
    {
        $str = str_replace('“', '"\""', $str);
        $str = str_replace('”', '"\""', $str);
        $str = str_replace('"', '"\""', $str);
        // $str = str_replace("'", "'\''", $str); //better to exclude this bec. of the use of apostrophe: e.g. "Stylosanthes bahiensis 't Mannetje & G.P.Lewis"
        $str = str_replace("`", "'\''", $str);
        $str = str_replace("\n", " ", $str);
        $str = Functions::remove_whitespace($str);
        return $str;
        // echo 'This is how it'\''s done'.
    }
    function run_gnparser($sciname, $type)
    {   // e.g. gnparser -f pretty "Quadrella steyermarkii (Standl.) Iltis &amp; Cornejo"
        $sciname = self::format_sciname($sciname);
        if($sciname = trim($sciname)) {
            $cmd = 'gnparser -f pretty "'.$sciname.'"'; // echo "\n[$cmd]\n";
            if($json = shell_exec($cmd)) { //echo "\n$json\n"; //good debug
                if($obj = json_decode($json)) { //print_r($obj); //exit("\nstop muna\n"); //good debug
                    if(@$obj->canonical) {
                        if($type == 'simple') return $obj->canonical->simple;
                        elseif($type == 'full') return $obj->canonical->full;
                        else exit("\nUndefined type. Will exit.\n");    
                    }
                }
            }    
        }
    }
    function lookup_canonical_name($sciname, $type)
    {
        $obj = self::call_gnparser_service($sciname);
        if(!$obj) return;
        // print_r($obj); exit;
        /*Array(
        [0] => stdClass Object(
                [parsed] => 1
                [quality] => 1
                [verbatim] => Agaricales
                [normalized] => Agaricales
                [canonical] => stdClass Object(
                        [stemmed] => Agaricales
                        [simple] => Agaricales
                        [full] => Agaricales
                    )
                [cardinality] => 1
                [id] => e7410ae0-31ac-584b-a362-12cccbd99527
                [parserVersion] => v1.7.1
            )
        )*/
        $obj = $obj[0];
        if($type == 'simple') return $obj->canonical->simple;
        elseif($type == 'full') return $obj->canonical->full;
        else exit("\nUndefined type. Will exit.\n");
    }
    private function call_gnparser_service($sciname)
    {
        $sciname = str_replace(" ", "+", $sciname);
        $sciname = str_replace("&", "%26", $sciname);
        $url = $this->service['GNParser'].$sciname;
        $options = $this->download_options;
        $options['expire_seconds'] = false;
        $options['resource_id'] = 'gnparser';
        if($json = Functions::lookup_with_cache($url, $options)) {
            $obj = json_decode($json); // print_r($obj); //exit;
            return $obj;
        }
    }
    private function get_field_from_uri($uri)
    {
        $field = pathinfo($uri, PATHINFO_BASENAME);
        $parts = explode("#", $field);
        if($parts[0]) $field = $parts[0];
        if(@$parts[1]) $field = $parts[1];
        return $field;
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
    }
    */
}
?>