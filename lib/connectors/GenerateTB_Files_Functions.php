<?php
namespace php_active_record;
/* */
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class GenerateTB_Files_Functions extends ZenodoTraitBankAPI
{
    public $compatibleAncestors_file = "https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/AncestryIndex_compatibleAncestors.tsv";

    function __construct() {}
    function write_Taxon_input_file($taxon_info)
    {   //print_r($taxon_info); exit("\nelix 1\n");
        /*Array(
            [taxonID] => 1133516
            [scientificName] => Acanthophoenix crinita
            [higherClassification] => Arecales|Acanthophoenix|
            [order] => Arecales
            [family] => 
            [genus] => Acanthophoenix
            [taxonRank] => species
            [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Arecales\|.*?] ] || source_taxonID: [Acanthophoenix crinita]
            [canonicalName] => Acanthophoenix crinita
            [EOLid] => 1133516
            [taxonMap] => auto
            [superfamily] => 
            [source_taxonID] => Acanthophoenix crinita
        )*/
        $headers = self::get_headers($this->concept_id, 'taxon');
        // /* New
        if(!in_array('taxonKey', $headers)) $headers = $this->insert_array($headers, 'taxonKey', 'scientificName');
        // */
        if(!in_array('taxonMap', $headers)) $headers[] = 'taxonMap';
        $headers = $this->insert_array($headers, 'taxonMap', 'taxonKey');

        if($this->with_old_taxonKey_valuesYN) $headers = $this->insert_array($headers, 'old_taxonKey', 'taxonKey');

        /*Array( $headers
            [0] => taxonID
            [1] => scientificName
            [2] => taxonKey
            [3] => taxonRank
            [4] => genus
            [5] => family
            [6] => superfamily
            [7] => order
        )*/
        $save = array();
        // /* version 2
        foreach($headers as $field) {
            if($field == 'taxonID')         $save['taxonID']      = $taxon_info['source_taxonID'];
            if($field == 'old_taxonKey')    $save['old_taxonKey'] = $taxon_info['old_taxonKey'];
            else {
                if($field) $save[$field] = $taxon_info[$field]; 
            }
        }
        // */
        /* version 1
        $save['taxonID'] = $taxon_info['source_taxonID'];
        $save['taxonKey'] = $taxon_info['taxonKey'];
        $save['taxonMap'] = $taxon_info['taxonMap'];
        $save['infer'] = @$taxon_info['infer'];
        $save['exclude'] = @$taxon_info['exclude'];
        $save['canonicalName'] = $taxon_info['canonicalName'];
        $save['scientificName'] = $taxon_info['scientificName'];
        $save['parentNameUsageID'] = @$taxon_info['parentNameUsageID'];
        $save['higherClassification'] = $taxon_info['higherClassification'];
        $save['kingdom'] = @$taxon_info['kingdom'];
        $save['phylum'] = @$taxon_info['phylum'];
        $save['class'] = @$taxon_info['class'];
        $save['order'] = @$taxon_info['order'];
        $save['family'] = @$taxon_info['family'];
        $save['genus'] = @$taxon_info['genus'];
        $save['species'] = @$taxon_info['species'];
        $save['taxonRemarks'] = ''; //@$taxon_info['taxonRemarks']; works but per Katja should be blank
        $save['source'] = @$taxon_info['source'];
        */
        if(isset($save['old_taxonKey'])) unset($save['old_taxonKey']); //one place to exclude old_taxonKey from Taxon.tsv
        self::write_to_text($save, 'Taxon');
    }
    function write_Occurrence_input_file_FROM_DwCA($occur_info) //not used atm
    {   //print_r($occur_info); exit("\nelix 1\n");
        /*Array(
            [0602fb1aabecdfa65eb898018a7fef2e_10088_6943_ENV] => Array(
                    [occurrenceID] => 0602fb1aabecdfa65eb898018a7fef2e_10088_6943_ENV
                    [taxonID] => 47138010
                )
            [49a463f78f2f1d31877d4bdb1b4e72a1_10088_6943_ENV] => Array(
                    [occurrenceID] => 49a463f78f2f1d31877d4bdb1b4e72a1_10088_6943_ENV
                    [taxonID] => 47138010
                )
        */
        foreach($occur_info as $occurrenceID => $rek) {
            $save = array();
            $save['occurrenceID'] = $rek['occurrenceID'];
            $save['institutionCode']    = @$rek['institutionCode'];
            $save['collectionCode']     = @$rek['collectionCode'];
            $save['catalogNumber']      = @$rek['catalogNumber'];
            $save['establishmentMeans'] = @$rek['establishmentMeans'];
            $save['occurrenceRemarks']  = @$rek['occurrenceRemarks'];
            $save['samplingProtocol']   = @$rek['samplingProtocol'];
            $save['individualCount']    = @$rek['individualCount'];
            $save['locality']           = @$rek['locality'];
            $save['decimalLatitude']    = @$rek['decimalLatitude'];
            $save['decimalLongitude']   = @$rek['decimalLongitude'];
            // and more: https://raw.githubusercontent.com/eliagbayani/EOL-connector-data-files/master/ontology/occurrence_extension_specific.xml
            // print_r($save);
            self::write_to_text($save, 'Occurrences');
            // exit("\n--stop--\n");
        }
    }
    function write_Occurrence_input_file($occur_info)
    {   //print_r($occur_info); exit("\nelix 1\n");
        /*Array(
            [Platanus orientalis] => Array(
                    [occurrenceID] => geng_1
                    [taxonID] => Platanus orientalis
                    [bodyPart] => http://purl.obolibrary.org/obo/PO_0025034
                    [Concentration] => 0.42
                    [measurementUnit] => https://eol.org/schema/terms/picomolpermetersquaredpersecond
                    [measurementRemarks] => production measured per single leaf area
                    [SampleSize] => 
                )
            [Alpinia zerumbet] => Array(
                    [occurrenceID] => Jardine_1
                    [taxonID] => Alpinia zerumbet
                    [bodyPart] => http://purl.obolibrary.org/obo/PO_0025034
                    [Concentration] => 6
                    [measurementUnit] => https://eol.org/schema/terms/picomolpermetersquaredpersecond
                    [measurementRemarks] => production measured per single leaf area
                    [SampleSize] => 
                )*/
        foreach($occur_info as $key => $rek) {
            /* copied template
            $save = array();
            $fields = array_keys($rek);
            foreach($fields as $field) $save[$field] = $rek[$field];
            self::write_to_text($save, 'Occurrences'); */
            self::write_to_text($rek, 'Occurrences');
        }
    }

    function write_Traits_input_file($taxon_main)
    {   /*Array(
            [taxon] => Array(
                    [taxonID] => 1133516
                    [scientificName] => Acanthophoenix crinita
                    [higherClassification] => Arecales|Acanthophoenix|
                    [order] => Arecales
                    [family] => 
                    [genus] => Acanthophoenix
                    [taxonRank] => species
                    [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Arecales\|.*?] ] || source_taxonID: [Acanthophoenix crinita]
                    [canonicalName] => Acanthophoenix crinita
                    [EOLid] => 1133516
                    [taxonMap] => auto
                    [superfamily] => 
                    [old_taxonKey] => 
                    [source_taxonID] => Acanthophoenix crinita
                )
            [occurrences] => Array(
                    [0c90b41e692935f1c2df66ee36c3dfe4] => Array(
                            [occurrenceID] => 0c90b41e692935f1c2df66ee36c3dfe4
                            [taxonID] => 1133516
                            [bodyPart] => 
                        )
                    [476aca6590f19d742ec011fe537fce4a] => Array(
                            [occurrenceID] => 476aca6590f19d742ec011fe537fce4a
                            [taxonID] => 1133516
                            [bodyPart] => http://purl.obolibrary.org/obo/PO_0025034
                        )
            [mof] => Array(
                    [0] => Array(
                            [measurementID] => Kissling_Daniel_738
                            [occurrenceID] => 0c90b41e692935f1c2df66ee36c3dfe4
                            [measurementType] => http://purl.obolibrary.org/obo/FLOPO_0900032
                            [measurementValue] => http://purl.obolibrary.org/obo/FLOPO_0900033
                            [measurementUnit] => 
                            [statisticalMethod] => 
                            [measurementRemarks] => 
                            [source] => https://doi.org/10.5061/dryad.ts45225
                            [referenceID] => https://doi.org/10.1111/1365-2745.12698|https://palmweb.org/cdm_dataportal/reference/b83403f1-0316-4152-bc3b-22cf4f70ce86|https://doi.org/10.1038/s41559-017-0348-7
                        )
                    [1] => Array(
                            [measurementID] => Kissling_Daniel_4301
                            [occurrenceID] => 476aca6590f19d742ec011fe537fce4a
                            [measurementType] => https://www.wikidata.org/entity/Q29688905
                            [measurementValue] => http://purl.obolibrary.org/obo/UBERON_0008260
                            [measurementUnit] => 
                            [statisticalMethod] => 
                            [measurementRemarks] => 
                            [source] => https://doi.org/10.5061/dryad.ts45225
                            [referenceID] => https://doi.org/10.1111/1365-2745.12698|https://palmweb.org/cdm_dataportal/reference/b83403f1-0316-4152-bc3b-22cf4f70ce86|https://doi.org/10.1038/s41559-017-0348-7
                        )
                    )
        */
        /*[taxon] => Array(
                [taxonID] => 1133516
                [scientificName] => Acanthophoenix crinita
                [higherClassification] => Arecales|Acanthophoenix|
                [order] => Arecales
                [family] => 
                [genus] => Acanthophoenix
                [taxonRank] => species
                [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Arecales\|.*?] ] || source_taxonID: [Acanthophoenix crinita]
                [canonicalName] => Acanthophoenix crinita
                [EOLid] => 1133516
                [taxonMap] => auto
                [superfamily] => 
                [old_taxonKey] => 
                [source_taxonID] => Acanthophoenix crinita
            )*/
        $taxon_info = $taxon_main['taxon'];
        $occur_info = $taxon_main['occurrences']; // print_r($occur_info);
        $taxonKey = $taxon_info['taxonKey'];

        $measurements = $taxon_main['mof'];
        foreach($measurements as $m) { //print_r($m); exit;
            /*Array(
                [measurementID] => 36ddcab4209404ea8e04ab43387d04b8_10088_6943_ENV
                [occurrenceID] => 0602fb1aabecdfa65eb898018a7fef2e_10088_6943_ENV
                [measurementOfTaxon] => true
                [measurementType] => http://eol.org/schema/terms/Present
                [measurementValue] => http://www.geonames.org/7729901
                [measurementRemarks] => source text: "but erroneously reported from _Polynesia_ has narrow 0.5–1.5 cm"
                [source] => http://dx.doi.org/10.5479/si.0081024X.17
                [bibliographicCitation] => Grant, Martin Lawrence, Fosberg, F. Raymond, and Smith, Howard M. 1974. "Partial Flora of the Society Islands: Ericaceae to Apocynaceae." Smithsonian Contributions to Botany. 1-85. https://doi.org/10.5479/si.0081024X.17
            )*/
            if(!@$m['measurementID']) continue;
            $occurrenceID = $m['occurrenceID'];
            $occur = $occur_info[$occurrenceID]; //print_r($occur); exit("\nelix 3\n");

            $save = array();

            // /* version 2
            $headers = self::get_headers($this->concept_id, 'traits'); //print_r($headers); exit("\nelix 2\n");

            // /* working OK but decided not to show old_taxonKey in Traits.tsv
            if($this->with_old_taxonKey_valuesYN) {
                if(!in_array('old_taxonKey', $headers)) $headers[] = 'old_taxonKey';         //copied template
                $headers = $this->insert_array($headers, 'old_taxonKey', 'taxonKey');
            }
            // */

            /*Array(
                [0] => measurementID
                [1] => taxonID
                [2] => scientificName
                [3] => taxonKey
                [4] => infer
                [5] => measurementType
                [6] => measurementValue
                [7] => bodyPart
                [8] => statisticalMethod
                [9] => measurementUnit
                [10] => measurementRemarks
                [11] => source
                [12] => referenceID
            )*/
            foreach($headers as $field) {
                if($field == 'taxonID')             $save['taxonID']        = $taxon_info['source_taxonID'];
                elseif($field == 'taxonKey')        $save['taxonKey']       = $taxon_info['taxonKey'];
                elseif($field == 'scientificName')  $save['scientificName'] = $taxon_info['scientificName'];
                elseif($field == 'old_taxonKey')    $save['old_taxonKey']   = $taxon_info['old_taxonKey'];

                // elseif($field == 'lifeStage')   $save['lifeStage']  = @$occur['lifeStage'];
                // elseif($field == 'sex')         $save['sex']        = @$occur['sex'];
                // elseif($field == 'bodyPart')    $save['bodyPart']   = @$occur['bodyPart'];

                else                            $save[$field] = @$m[$field];
            }
            // */

            /* version 1
            $save['measurementID']      = $m['measurementID'];
            // $save['occurrenceID']    = $occurrenceID; //optional
            $save['measurementType']    = $m['measurementType'];
            $save['measurementValue']   = $m['measurementValue'];
            $save['measurementRemarks'] = @$m['measurementRemarks'];
            $save['measurementUnit']    = @$m['measurementUnit'];
            $save['statisticalMethod']  = @$m['statisticalMethod'];
            $save['source']             = @$m['source'];
            $save['referenceID']        = @$m['referenceID'];

            $save['taxonID']            = $taxon_info['source_taxonID'];
            $save['taxonKey']           = $taxon_info['taxonKey'];
            $save['scientificName']     = $taxon_info['scientificName'];

            $save['infer'] = '';    // tb:infer if true, paint the branch: taxa descending from the tbTaxonMapping should inherit this trait
            $save['exclude'] = '';  // tb:exclude stop branchpainting: descendant taxa should not inherit this trait            

            $save['lifeStage']  = @$occur['lifeStage'];
            $save['sex']        = @$occur['sex'];
            $save['bodyPart']   = @$occur['bodyPart'];
            */
            if(isset($save['old_taxonKey'])) unset($save['old_taxonKey']); //one place to exclude old_taxonKey from Traits.tsv
            self::write_to_text($save, 'Traits');
        }
    }
    private function write_to_text($rec, $kind)
    {
        $file = $this->TB_folder . "/$this->resource_id/input_files/$kind.tsv"; 
        if(!file_exists($file)) {
            $f = Functions::file_open($file, "w");
            $headers = array_keys($rec);
            fwrite($f, implode("\t", $headers)."\n");
            fwrite($f, implode("\t", $rec)."\n");
        }
        else {
            $f = Functions::file_open($file, "a");
            fwrite($f, implode("\t", $rec)."\n");
        }
        fclose($f);
        /* debug
        if($kind == 'Taxon') { print_r($headers); print_r($rec); exit("\n[$file]\nelix 4\n"); }
        */
    }
    function get_source_taxonID($taxonRemarks)
    {   //Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Asclepias\|.*?] ] || source_taxonID: [bb345e46c7900f99efefd82ecf42a8fd]
        if(preg_match("/source_taxonID\: \[(.*?)\]/ims", $taxonRemarks, $a)) return $a[1];
    }
    function get_taxonKey_infer_exclude($str)
    {   //e.g. 'dark period only || {"taxonKey":","infer":"TRUE","exclude":"} || blablabla'
        //  -> array('taxonKey' => '', 'infer' => 'TRUE', 'exclude' => '')
        //the {...} part is not always valid JSON: an empty value can be written as a single quote, e.g. "taxonKey":"
        $final = array('taxonKey' => '', 'infer' => '', 'exclude' => '');
        foreach(explode("||", $str) as $part) {
            $part = trim($part);
            if(!preg_match("/^\{(.*)\}$/s", $part, $a)) continue; //only the {...} part
            if(is_array($json = json_decode($part, true))) $pairs = $json; //valid JSON
            else { //split on the commas that start a new "key":
                $pairs = array();
                foreach(preg_split('/,(?="\w+":)/', $a[1]) as $pair) {
                    if(preg_match('/^\s*"(\w+)"\s*:\s*(.*?)\s*$/s', $pair, $b)) $pairs[$b[1]] = preg_replace('/^"|"$/', '', $b[2]); //strip enclosing quotes; a lone " becomes ''
                }
            }
            foreach(array_keys($final) as $key) {
                if(isset($pairs[$key])) $final[$key] = trim((string) $pairs[$key]);
            }
        }
        return $final;
    }
    function check_file_integrity($concept_id, $basename)
    {   //e.g. (23067562, 'taxon')  -> duplicate taxonID values in      TB_files/23067562_Bioc_and_Natu_Prod/input_files/taxon.txt
        //     (23067562, 'traits') -> duplicate measurementID values in TB_files/23067562_Bioc_and_Natu_Prod/input_files/traits.tsv
        //     (23067562, 'occurrence') -> duplicate occurrenceID values in TB_files/23067562_Bioc_and_Natu_Prod/input_files/occurrences.txt
        //reported on screen and in $this->debug (written to the debug file at the end of GenerateTB_FilesAPI::start())
        //returns array(id => array(line numbers)) of the duplicates, empty array if none, false if file/column not found
        $columns = array('taxon' => 'taxonID', 'traits' => 'measurementID', 'occurrence' => 'occurrenceID', 'occurrences' => 'occurrenceID');
        if(!($column = @$columns[$basename])) { echo "\ncheck_file_integrity(): no check defined for [$basename]\n"; return false; }
        if(!($file = $this->get_generic_file_path($concept_id, $basename))) return false;
        if(!($headers = $this->get_headers($concept_id, $basename))) return false; //short names e.g. 'taxonID'
        if(($index = array_search($column, $headers, true)) === false) { echo "\nColumn [$column] not found in [".basename($file)."]\n"; return false; }

        //for detecting stray header rows inside the data, e.g. occurrences.txt line 158: "measurementID  taxonID  BodyPart  Concentration  Unit  Remarks"
        $header_names = array_map('strtolower', $headers);
        $id_names = array('taxonid', 'measurementid', 'occurrenceid', 'scientificname');

        $lines = array(); $header_rows = array(); $line_no = 0; $header_done = false;
        $fh = fopen($file, "r");
        while(($line = fgets($fh)) !== false) {
            $line_no++;
            $line = rtrim($line, "\r\n");
            if($line === '') continue;
            if(!$header_done) { $header_done = true; continue; } //1st non-blank line is the header
            $values = explode("\t", $line);
            $id = trim((string) @$values[$index]);
            // a header-like row: its ID value is a column name, or 2+ of its values are names of this file's columns
            $names = array_map(fn($v) => strtolower($this->small_field(trim($v))), array_filter($values, fn($v) => trim($v) !== ''));
            if(in_array(strtolower($id), $id_names) || count(array_intersect($names, $header_names)) >= 2) {
                $header_rows[$line_no] = $line;
                continue; //not counted as a duplicate ID
            }
            if($id === '') continue;
            $lines[$id][] = $line_no;
        }
        fclose($fh);

        if($header_rows) {
            $warning = "ERROR: ".count($header_rows)." header-like row(s) found inside the data of [".basename($file)."]";
            echo "\n$warning\n";
            $topic = "$warning -- concept_id $concept_id";
            foreach($header_rows as $no => $row) $this->debug[$topic]["line $no"][str_replace("\t", " | ", $row)] = '';
        }

        $dups = array_filter($lines, fn($a) => count($a) > 1);
        if($dups) {
            $warning = "WARNING: ".count($dups)." duplicate $column value(s) in [".basename($file)."]";
            echo "\n$warning\n";
            $topic = "$warning -- concept_id $concept_id"; //same text as the screen warning, so it can be searched in the debug file
            foreach($dups as $id => $nos) $this->debug[$topic][$id]["lines: ".implode(", ", $nos)] = '';
        }
        else echo "\nNo duplicate $column in [".basename($file)."]\n";
        return $dups;
    }
    function get_concept_id_from_resource_id($resource_id)
    {   //resource_id e.g. "23067562_Bioc_and_Natu_Prod_TraitBank"
        $arr = explode("_", $resource_id);
        $concept_id = $arr[0];
        if(is_numeric($concept_id)) return $concept_id;
        return false;
    }
    function zip_TB_folder($resource_id)
    {   //resource_id e.g. "22943003_Palm_TraitBank"
        //zips [TB_files/22943003_Palm_TraitBank/] into [TB_files/22943003_Palm_TraitBank.zip], excluding its temp/ folder
        $TB_folder = CONTENT_RESOURCE_LOCAL_PATH . 'TB_files';
        $source = "$TB_folder/$resource_id";
        $destination = "$TB_folder/$resource_id.zip";
        if(!is_dir($source)) { echo "\nERROR: folder not found [$source]\n"; return false; }
        if(file_exists($destination)) unlink($destination);
        $cmd = "cd " . escapeshellarg($TB_folder) . " && zip -rq " . escapeshellarg("$resource_id.zip") . " " . escapeshellarg($resource_id) . " -x " . escapeshellarg("$resource_id/temp/*");
        shell_exec($cmd);
        if(file_exists($destination)) { echo "\nZip created: [$destination]\n"; return $destination; }
        echo "\nERROR: zip not created [$destination]\n";
        return false;
    }
    function get_orig_input_file($concept_id, $what) //$what is 'taxon' 'occurrence'
    {
        require_library('connectors/ConvertNewModel_2DwCA');
        $func = new ConvertNewModel_2DwCA(false, array());
        $final = array();

        if($what == 'taxon') {
            if (!($local = $this->get_generic_file_path($concept_id, 'taxon', false))) exit("\nERROR: No taxon.tsv\n"); //3rd param is: $processedYN
            else $final = $func->process_table($local, 'build_taxon_info');
            echo "\norig_taxon_input_file: ".count($final)."\n";
        }
        elseif($what == 'occurrence') {
            if (!($local = $this->get_generic_file_path($concept_id, 'occurrence', false))) return array(); //exit("\nERROR: No occurrence.tsv\n"); //3rd param is: $processedYN
            else $final = $func->process_table($local, 'build_occurrence_info');
            echo "\norig_occurrence_input_file: ".count($final)."\n";
        }
        else exit("\nERROR: input file not defined.\n");

        return $final;
    }
    function xxxget_citation_from_CrossRef($doi)
    {   // e.g. "https://doi.org/10.1126/science.233.4770.1314" (also "10.1126/..." or "doi:10.1126/...") ->
        // "Dacey, J. W. H., & Wakeham, S. G. (1986). Oceanic Dimethylsulfide: Production During Zooplankton Grazing on Phytoplankton. Science, 233(4770), 1314-1316. https://doi.org/10.1126/science.233.4770.1314"
        // APA-like; parts missing in CrossRef are left out. Returns false if the DOI is not found in CrossRef.
        $doi = trim((string) $doi);
        $doi = preg_replace('#^(https?://(dx\.)?doi\.org/|doi:\s*)#i', '', $doi);
        if(!preg_match('#^10\.\d{4,}/\S+$#', $doi)) { echo "\nNot a DOI: [$doi]\n"; return false; }

        $options = array('resource_id' => 'crossref', 'expire_seconds' => false, // a published record rarely changes; cache never expires
                         'timeout' => 60, 'download_attempts' => 2, 'download_wait_time' => 1000000,
                         'user_agent' => 'EOL-connector/1.0',
                         'validation_regex' => '"status":"ok"'); // only use cache if it is a real CrossRef record
        $url = "https://api.crossref.org/works/" . rawurlencode($doi);
        if(!($json = Functions::lookup_with_cache($url, $options))) { echo "\nCrossRef: no record for [$doi]\n"; return false; }
        $m = @json_decode($json, true)['message'];
        if(!$m) return false;
        $clean = fn($str) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $str), ENT_QUOTES | ENT_HTML5, 'UTF-8'))); // titles may have <i>, <sub>, &amp;

        // Authors: "Family, G. N." -- or the organization name
        $authors = array();
        foreach(@$m['author'] ?? array() as $a) {
            if($family = $clean(@$a['family'])) {
                $initials = array();
                foreach(preg_split('/\s+/', $clean(@$a['given']), -1, PREG_SPLIT_NO_EMPTY) as $name) { // "John W. H." -> "J. W. H."; "Jean-Paul" -> "J.-P."
                    $initials[] = implode('-', array_map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)) . '.', explode('-', $name)));
                }
                $authors[] = $initials ? "$family, " . implode(' ', $initials) : $family;
            }
            elseif($name = $clean(@$a['name'])) $authors[] = $name;
        }
        if(count($authors) > 1) $authors_str = implode(', ', array_slice($authors, 0, -1)) . ', & ' . end($authors);
        else                    $authors_str = (string) @$authors[0];

        $year      = @$m['issued']['date-parts'][0][0] ?: @$m['published']['date-parts'][0][0];
        $title     = $clean(@$m['title'][0]);
        $container = $clean(@$m['container-title'][0]); // journal, or the book title for a chapter
        $volume    = $clean(@$m['volume']);
        $issue     = $clean(@$m['issue']);
        $pages     = $clean(@$m['page']);
        $publisher = $clean(@$m['publisher']);
        $type      = (string) @$m['type'];

        // Assemble: Authors (Year). Title. Container, Volume(Issue), Pages. [Publisher.] https://doi.org/DOI
        $parts = array();
        if($authors_str) $parts[] = $authors_str . ($year ? " ($year)." : (str_ends_with($authors_str, '.') ? '' : '.')); // keep the last initial's '.', e.g. "S. G. (1986)."
        elseif($year)    $parts[] = "($year).";
        if($title) $parts[] = rtrim($title, '.') . '.';
        $source = $container;
        if($volume) $source .= ($source ? ', ' : '') . $volume . ($issue ? "($issue)" : '');
        elseif($issue) $source .= ($source ? ', ' : '') . "($issue)";
        if($pages) $source .= ($source ? ', ' : '') . $pages;
        if($source) $parts[] = "$source.";
        if($publisher && in_array($type, array('book', 'book-chapter', 'edited-book', 'monograph', 'reference-book', 'report'))) $parts[] = rtrim($publisher, '.') . '.';
        $parts[] = "https://doi.org/" . (@$m['DOI'] ?: $doi);
        return implode(' ', $parts);
    }
}
?>