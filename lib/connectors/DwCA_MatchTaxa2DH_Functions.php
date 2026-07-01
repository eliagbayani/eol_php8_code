<?php
namespace php_active_record;
/* */
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class DwCA_MatchTaxa2DH_Functions
{
    /* the first version used
    public $compatibleAncestors_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/compatibleAncestors.txt';
    this file comes from Katja's Jupyter Notebook
    */
    public $compatibleAncestors_file = 'https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/Ancestry Index - compatibleAncestors.tsv';

    function __construct() {}
    function get_compatibleAncestors()
    {
        if($local = Functions::save_remote_file_to_local($this->compatibleAncestors_file, $this->download_options)) {
            $i = 0;
            foreach(new FileIterator($local) as $line_number => $line) {
                $line = trim($line); $i++; 
                if(!$line) break; // Animals; Annelida
                // $arr = explode(";", $line); --- old version
                $arr = explode("\t", $line);
                $new_line = trim("$arr[1]; $arr[0]");
                $ret[$line] = '';
                $ret[$new_line] = '';
            }
        }
        else exit("\nERROR: compatibleAncestors_file can't be accessed.\n"."\nWill terminate.\n");
        unlink($local);
        return $ret;
    }
    // function have_compatibleAncestors($indexGroup1, $indexGroup2) //not used anymore...
    function are_the_IndexValues_compatible($index_values)
    {   
        $index_values = array_unique($index_values); //make unique
        $index_values = array_values($index_values); //reindex key
        if(count($index_values) == 1) return true;
        if(count($index_values) > 2) exit("\nWill terminate: more than 2 index_values!\n");
        $indexGroup1 = $index_values[0];
        $indexGroup2 = $index_values[1];
        if($indexGroup1 == $indexGroup2) return true;
        /*Array(
            [Animals; Annelida] => 
            [Annelida; Animals] => 
            [Animals; Arthropoda] => 
            [Arthropoda; Animals] => */
        $needle = "$indexGroup1; $indexGroup2";
        if(isset($this->compatibleAncestors[$needle])) return true;
        $needle = "$indexGroup2; $indexGroup1";
        if(isset($this->compatibleAncestors[$needle])) return true;
        return false;
    }
    function get_rightmost($pattern)
    {
        // e.g. .*?\|Hexapoda\|(.*?\|)?Pterygota\|.*?
        // e.g. .*?\|Odonata\|.*?
        $pattern = trim(str_replace(".*?", "", $pattern));
        $arr = explode("|", $pattern);
        $arr = array_filter($arr); //remove null arrays
        $lastItem = end($arr);
        return str_replace(array("|", ")", "?", "\\"), "", $lastItem);
    }
    function get_inner_array_with_greatest_posOfLastItem($nestedArray)
    {   /* Given this nexted array:
        Array(
            [0] => Array(
                    [IndexGroup] => Insecta
                    [IndexHC] => .*?\|Hexapoda\|(.*?\|)?Pterygota\|.*?
                    [lastItem_in_IndexHC] => Pterygota
                    [posOfLastItem] => 4
                )
            [1] => Array(
                    [IndexGroup] => Odonata
                    [IndexHC] => .*?\|Odonata\|.*?
                    [lastItem_in_IndexHC] => Odonata
                    [posOfLastItem] => 5
                )
        )
        I need to get the array with the greatest [posOfLastItem]
        Array(
            [IndexGroup] => Odonata
            [IndexHC] => .*?\|Odonata\|.*?
            [lastItem_in_IndexHC] => Odonata
            [posOfLastItem] => 5
        ) */
        // 1. Extract just the 'posOfLastItem' values into a flat array
        $scores = array_column($nestedArray, 'posOfLastItem');
        // 2. Find the key corresponding to the maximum score
        $maxKey = array_search(max($scores), $scores);
        // 3. Retrieve the full inner array
        $highestArray = $nestedArray[$maxKey];
        return $highestArray;
    }
    function debug_reports()
    {   echo '' ?? '';
        $cannot_be_matched_at_all = count($this->debug['Cannot be matched at all'] ?? array());
        $With_eolID_assignments = count(@$this->debug['With DH EOLid assignments (accepted name)'] ?? array());
        $With_EOLid_but_not_matched = count(@$this->debug['With EOLid but not matched'] ?? array());
        $matches_made_without_ancestry_info = count(@$this->debug['Matches made without_OR_lacking ancestry info'] ?? array());
        $matched_thru_a_synonym = count(@$this->debug['With DH EOLid assignments (synonym)'] ?? array()); //'Matched thru a synonym'

        echo "\n\n----------STATS----------";
        echo "\nA. No canonical match: [" . number_format(count(@$this->debug['No canonical match'] ?? array())) . "]";
        echo "\nB. Has canonical match: [" . number_format(@$this->debug['Has canonical match'] ?? 0) . "]";
        echo "\n -> B1. With DH EOLid assignments (accepted name): [" . number_format($With_eolID_assignments) . "]";
        echo "\n -> B2. With DH EOLid assignments (synonym): [" . number_format($matched_thru_a_synonym) . "]";
        $sum = $cannot_be_matched_at_all + $With_eolID_assignments + $matched_thru_a_synonym; // + $With_EOLid_but_not_matched;
        $diff = @$this->debug['Has canonical match'] - $sum;
        echo "\n -> B3. Cannot be matched at all: [" . number_format($cannot_be_matched_at_all) . "]";
        echo "\n -> Total = [".number_format($sum)."]";
        if($diff != 0) echo "\nDIFF SHOULD BE ZERO [".number_format($diff)."]\n";

        $Synonym_matched_but_no_DH_EOLid = count(@$this->debug['Synonym matched but no DH EOLid'] ?? array());
        $Failed_synonym_match            = count(@$this->debug['Failed synonym match'] ?? array());
        echo "\n\n -> Synonym_matched_but_no_DH_EOLid: [" . number_format($Synonym_matched_but_no_DH_EOLid) . "]";
        echo "\n -> Failed_synonym_match: [" . number_format($Failed_synonym_match) . "]";

        echo "\n\nC. Matches made without_OR_lacking ancestry info: [" . number_format($matches_made_without_ancestry_info) . "]";
        $rems = array_keys(@$this->debug['without_OR_lacking'] ?? array());
        $sum = 0; $i = 0;
        foreach($rems as $rem) { $i++;
            $val = count(@$this->debug['without_OR_lacking'][$rem] ?? array());
            $sum += $val;
            echo "\n -> C$i. [$rem]: ".number_format($val);
        }
        $diff = $matches_made_without_ancestry_info - $sum;
        echo "\n -> Total = [".number_format($sum)."]";
        if($diff != 0) echo "\nDIFF SHOULD BE ZERO [".number_format($diff)."]\n";

        /* commented for now
        $no_hc = count(@$this->debug['M-m-w-a-i']['No hC'] ?? array());
        $with_hc = count(@$this->debug['M-m-w-a-i']['With hC but cannot be mapped to any index group'] ?? array());
        echo "\n -> C1. No higherClassification: [".number_format($no_hc)."]";
        echo "\n -> C2. With higherClassification but cannot be mapped to any index group: [".number_format($with_hc)."]\n -> C = C1 + C2";
        $sum = $no_hc + $with_hc;
        $diff = $matches_made_without_ancestry_info - $sum;
        echo "\nsum [".number_format($sum)."] should be equal to [Matches made without_OR_lacking ancestry info]. DIFF SHOULD BE ZERO [".number_format($diff)."].\n";
        */

        echo "\n\nTotal taxa from taxon.tab: "      . number_format(@$this->debug['total taxa'] ?? 0);
        echo "\nBreakdown:";
        echo "\n -> excluded: invalid taxa: "       . number_format(@$this->debug['excluded: invalid taxa'] ?? 0);
        echo "\n -> excluded: no canonicalName: "   . self::number_format_eli(@$this->debug['excluded: no canonicalName']);
        echo "\n -> excluded: already has EOLid: "  . self::number_format_eli(@$this->debug['excluded: already has EOLid']);
        echo "\n -> A. No canonical match: [" . number_format(count(@$this->debug['No canonical match'] ?? array())) . "]";
        echo "\n -> B. Has canonical match: [" . number_format(@$this->debug['Has canonical match'] ?? 0) . "]";
        $sum = @$this->debug['excluded: invalid taxa'] + @$this->debug['excluded: no canonicalName'] + @$this->debug['excluded: already has EOLid']
               + count(@$this->debug['No canonical match'] ?? array())
               + @$this->debug['Has canonical match'];
        $diff = $sum - @$this->debug['total taxa'];
        echo "\nTotal = [".number_format($sum)."]";
        if($diff != 0) echo "\nDIFF SHOULD BE ZERO [".number_format($diff)."]\n";

        // /*
        if($this->run_debug2_YN) {
            $this->debug['total EOL IDs'] = count(@$this->debug2['total EOLids'] ?? array());
            $this->debug['EOL ID assignments'] = @$this->debug2['EOLid assignments'];
            echo "\n\nAncestryIndexVer: [".$this->AncestryIndexVer."]";
            echo "\ntotal EOL IDs (unique): [".@$this->debug['total EOL IDs']."]";
            echo "\nEOL ID assignments (multiple taxa can be assigned with same EOLid): [".@$this->debug['EOL ID assignments']."]\n";
        }
        // */
        echo "\n----------STATS end----------\n";

        /*
        echo "\n*With EOLid but not matched: [" . number_format($With_EOLid_but_not_matched) . "] (a subset of B2)";
        echo "\nsyn opt 1: ". @$this->debug['synonym option 1'];
        echo "\nsyn opt 2: ". @$this->debug['synonym option 2'];
        echo "\nsyn OK: ". @$this->debug['synonyms OK'];
        */
        self::print_logs_for_Katja();
        /*
        echo "\nNo canonical match: [" . number_format(count(@$this->debug['No canonical match']) ?? array()) . "]";
        echo "\ncanonical match: genus - subgenus: [" . number_format(@$this->debug['canonical match: genus - subgenus'] ?? 0) . "]";
        echo "\nOK DwCA:                           [" . number_format(@$this->debug['canonical match: genus - subgenus OK'] ?? 0) . "]";
        echo "\nOK DH:                             [" . number_format(@$this->debug['canonical match: genus - subgenus OK DH'] ?? 0) . "]";
        echo "\ncanonical match: subgenus - genus: [" . number_format(@$this->debug['canonical match: subgenus - genus'] ?? 0) . "]";
        echo "\nOK DwCA:                           [" . number_format(@$this->debug['canonical match: subgenus - genus OK'] ?? 0) . "]";
        echo "\nOK DH:                             [" . number_format(@$this->debug['canonical match: subgenus - genus OK DH'] ?? 0) . "]\n";

        echo "\ncanonical match: species - any subspecific ranks: [" . number_format(@$this->debug['canonical match: species - any subspecific ranks'] ?? 0) . "]";
        echo "\nOK DwCA: [" . number_format(@$this->debug['canonical match: species - any subspecific ranks OK'] ?? 0) . "]";
        echo "\nOK DH:   [" . number_format(@$this->debug['canonical match: species - any subspecific ranks OK DH'] ?? 0) . "]";
        echo "\ncanonical match: any subspecific ranks - species: [" . number_format(@$this->debug['canonical match: any subspecific ranks - species'] ?? 0) . "]";
        echo "\nOK DwCA: [" . number_format(@$this->debug['canonical match: any subspecific ranks - species OK'] ?? 0) . "]";
        echo "\nOK DH:   [" . number_format(@$this->debug['canonical match: any subspecific ranks - species OK DH'] ?? 0) . "]\n";

        echo "\ntaxonomicStatus breakdown: "; print_r(@$this->debug['taxonomicStatus']);
        echo "total acceptedNameUsageID: [" . number_format(@$this->debug['total acceptedNameUsageID'] ?? 0) . "]\n";

        echo "\na. matched ancestry on AncestryIndex: [" . number_format(count(@$this->debug['matched ancestry on AncestryIndex'] ?? array())) . "]";
        echo "\nb. matched HC on AncestryIndex: [" . number_format(count(@$this->debug['matched HC on AncestryIndex'] ?? array())) . "]";

        echo "\n ----- AncestryIndex Katja: [" . number_format(@$this->debug['AncestryIndex Katja'] ?? 0) . "]";
        echo "\n ----- AncestryIndex Eli: [" . number_format(@$this->debug['AncestryIndex Eli'] ?? 0) . "]";

        echo "\n1. matched ancestry*: [" . number_format(@$this->debug['matched ancestry*'] ?? 0) . "]";
        echo "\n2. matched higherClassification*: [" . number_format(@$this->debug['matched higherClassification*'] ?? 0) . "]";
        echo "\n3. matched just 1 record, same rank: [" . number_format(count(@$this->debug['matched just 1 record, same rank'] ?? array())) . "]";
        echo "\n4. matched same rank and status accepted: [" . number_format(count(@$this->debug['matched same rank and status accepted'] ?? array())) . "]";
        echo "\n5. matched same rank: [" . number_format(count(@$this->debug['matched same rank'] ?? array())) . "]";
        echo "\n6. matched group rank old: [" . number_format(count(@$this->debug['matched group rank old'] ?? array())) . "]";
        echo "\n6. matched group rank Katja: [" . number_format(@$this->debug['matched group rank Katja'] ?? 0) . "]";
        echo "\n7. accepted only [X]: [" . number_format(@$this->debug['accepted only [X]'] ?? 0) . "]";
        echo "\n8. matched 1st [X] rek: [" . number_format(@$this->debug['matched 1st [X] rek'] ?? 0) . "]";
        echo "\n9. matched blank eolID: [" . number_format(@$this->debug['matched blank eolID'] ?? 0) . "]";
        $total = count(@$this->debug['matched ancestry on AncestryIndex'] ?? array()) + count(@$this->debug['matched HC on AncestryIndex'] ?? array())
                + @$this->debug['matched ancestry*'] + @$this->debug['matched higherClassification*'] 
                + count(@$this->debug['matched just 1 record, same rank'] ?? array())
                + count(@$this->debug['matched same rank and status accepted'] ?? array())
                + count(@$this->debug['matched same rank'] ?? array()) 
                + count(@$this->debug['matched group rank old'] ?? array())
                + @$this->debug['matched group rank Katja']
                + @$this->debug['accepted only [X]']
                + @$this->debug['matched 1st [X] rek']
                + @$this->debug['matched blank eolID'];
        $diff = $total - @$this->debug['Has canonical match'];
        echo "\nTotal 9 matches: [" . number_format($total) . "] -> should be equal to: [Has canonical match] [$diff]\n";
        */

        if($this->run_debug3_YN) {
            if($this->debug3) Functions::start_print_debug($this->debug3, $this->resource_id."_".$this->AncestryIndexVer, $this->neo4j_debug_folder);
        }
        if($this->run_debug4_YN) {
            if(@$this->debug4) Functions::start_print_debug($this->debug4, $this->resource_id."_".$this->AncestryIndexVer."_attempts", $this->neo4j_debug_folder);
        }

        if($val = @$this->debug['eli']) print_r($val);
        // if($this->debug) Functions::start_print_debug($this->debug, $this->resource_id); //works OK but not needed atm.
        unset($this->debug);
    }
    private function print_logs_for_Katja()
    {   echo "\nPrinting logs...";
        $indexes = array('No canonical match', 'Cannot be matched at all', 'With DH EOLid assignments (accepted name)', 
                         'Matches made without_OR_lacking ancestry info', 'With DH EOLid assignments (synonym)', 
                         'incompatible_multimatches_v2', 'No_hits_in_AncestryIndex', 'compatible_multimatches_v2'); //compatible_multimatches_v1
        // excluded: 'With EOLid but not matched'
        foreach($indexes as $index) { echo "\n-> $index ...";
            $file = $this->stats_path ."/". str_replace(" ", "_", $index).".tsv"; echo "\nfile: [$file]";
            $WRITE = fopen($file, 'w');
            $i = 0;
            if($loop_arr = @$this->debug[$index]) echo "\nHas records for: [$index] [n=".count($loop_arr)."]\n";
            else {
                echo "\nNo records for: [$index]\n";
                continue;
            }
            foreach($loop_arr as $taxonID => $rec) { $i++; // print_r($rec); exit("\n$taxonID\n");
                /*Array(
                    [http://rs.tdwg.org/dwc/terms/taxonID] => 130
                    [http://rs.tdwg.org/ac/terms/furtherInformationURL] => http://reflora.jbrj.gov.br/reflora/listaBrasil/FichaPublicaTaxonUC/FichaPublicaTaxonUC.do?id=FB130
                    [http://rs.tdwg.org/dwc/terms/acceptedNameUsageID] => 
                    [http://rs.tdwg.org/dwc/terms/parentNameUsageID] => 
                    [http://rs.tdwg.org/dwc/terms/scientificName] => Hydnoraceae C. Agardh
                    [http://rs.tdwg.org/dwc/terms/namePublishedIn] => 
                    [http://rs.tdwg.org/dwc/terms/higherClassification] => 
                    [http://rs.tdwg.org/dwc/terms/kingdom] => Plantae
                    [http://rs.tdwg.org/dwc/terms/phylum] => 
                    [http://rs.tdwg.org/dwc/terms/class] => 
                    [http://rs.tdwg.org/dwc/terms/order] => 
                    [http://rs.tdwg.org/dwc/terms/family] => Hydnoraceae
                    [http://rs.tdwg.org/dwc/terms/genus] => 
                    [http://rs.tdwg.org/dwc/terms/taxonRank] => family
                    [http://rs.tdwg.org/dwc/terms/scientificNameAuthorship] => C. Agardh
                    [http://rs.tdwg.org/dwc/terms/taxonomicStatus] => accepted
                    [http://purl.org/dc/terms/modified] => 2019-09-24 16:40:37.148
                    [http://rs.gbif.org/terms/1.0/canonicalName] => Hydnoraceae
                    [http://eol.org/schema/EOLid] => 
                )*/
                if($rec && is_array($rec)) {
                    $uris = array_keys($rec);
                    $headers = array();
                    foreach($uris as $uri) $headers[] = self::small_field($uri);
                    if($i == 1) {
                        fwrite($WRITE, implode("\t", $headers)."\n");
                        // print_r($headers); //good debug
                    }
                    fwrite($WRITE, implode("\t", $rec)."\n");
                }
                if(is_string($rec)) {
                    if($rec == 'report') fwrite($WRITE, implode("\t", array($taxonID))."\n");
                    else exit("\nError: [$index] is string\n");
                }
            } 
            fclose($WRITE);
        }
        echo "\nLogs printed.\n";
    }
    function number_format_eli($num)
    {
        if($num) return number_format($num);
        else return 0;
    }
    function shorten_record($rec)
    {
        $new = array();
        foreach($rec as $key => $val) $new[self::small_field($key)] = $val;
        return $new;
    }
    function small_field($uri)
    {
        return pathinfo($uri, PATHINFO_FILENAME);
    }
    function matching_routine_using_rank_v2($rec, $reks)
    {   // if(count($reks) > 1) { print_r($rec); print_r($reks); exit("\neli 1\n".count($reks)."\n"); }
        /*Array(
            [taxonID] => 556
            [furtherInformationURL] => http://reflora.jbrj.gov.br/reflora/listaBrasil/FichaPublicaTaxonUC/FichaPublicaTaxonUC.do?id=FB556
            [acceptedNameUsageID] => 
            [parentNameUsageID] => 212
            [scientificName] => Erythrochiton Nees & Mart.
            [namePublishedIn] => 
            [higherClassification] => Rutaceae|
            [kingdom] => Plantae
            [phylum] => 
            [class] => 
            [order] => 
            [family] => Rutaceae
            [genus] => Erythrochiton
            [taxonRank] => genus
            [scientificNameAuthorship] => Nees & Mart.
            [taxonomicStatus] => accepted
            [modified] => 2016-12-23 15:52:32.622
            [canonicalName] => Erythrochiton
            [EOLid] => 
            [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Rutaceae\|.*?] ]
        )
        Array( it can be multiple reks like the one below, OR just a single rek.
            [EOL-000000458933] => Array(
                    [r] => genus
                    [e] => 47126261
                    [h] => Life|Cellular Organisms|Eukaryota|Archaeplastida|Chloroplastida|Streptophyta|Embryophytes|Tracheophyta|Spermatophytes|Angiosperms|Eudicots|Superrosids|Rosids|Sapindales|Rutaceae
                    [c] => Erythrochiton
                    [t] => EOL-000000458933
                    [s] => a
                )
            [EOL-000001554251] => Array(
                    [r] => genus
                    [e] => 9372
                    [h] => Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Bilateria|Protostomia|Ecdysozoa|Arthropoda|Pancrustacea|Hexapoda|Insecta|Pterygota|Neoptera|Endopterygota|Coleoptera|Polyphaga|Cucujiformia|Chrysomeloidea|Cerambycidae
                    [c] => Erythrochiton
                    [t] => EOL-000001554251
                    [s] => a
                )
        )*/
        $taxonRank = $rec['taxonRank'];
        /*--- If the rank values are the same, keep the match and go to Step 4 ---*/
        $pairs = array(); //a pair consists of 1 rec and 1 rek
        foreach($reks as $rek) {
            if($taxonRank == $rek['r']) $pairs[] = array($rec, $rek);
        }
        if($pairs) return $pairs;
        /*--- If one or both of the rank values are empty, keep the match and go to step Step 4
                - Add note "-no rank-" in the taxon remarks of the relevant log file ---*/
        $pairs = array();
        foreach($reks as $rek) {
            if(!$taxonRank || !$rek['r']) {
                if($val = @$rec['taxonRemarks']) $rec['taxonRemarks'] .= " -no rank-";
                else                             $rec['taxonRemarks'] = '-no rank-';
                $pairs[] = array($rec, $rek);
            }
        }
        if($pairs) return $pairs;
        /*---
        If the rank values are different:
            If both rank values are subspecific, keep the match and go to step Step 4
                subspecific ranks are: subspecies|variety|form|forma|infraspecies|infraspecific name|infrasubspecific name|subvariety|subform|proles|lusus|forma specialis ---*/
        $pairs = array();
        foreach($reks as $rek) {
            if($taxonRank != $rek['r']) {
                if(self::rank_is_subspecific_YN($taxonRank) && self::rank_is_subspecific_YN($rek['r'])) $pairs[] = array($rec, $rek);
            }
        }
        if($pairs) return $pairs;
        /*---
            If both taxa have ranks that are not subspecific|species|genus|subgenus|section|subsection|section botany|subsection botany, keep the match and go to step 4 ---*/
        $pairs = array();
        foreach($reks as $rek) {
            $rankz = array('subspecific', 'species', 'genus', 'subgenus', 'section', 'subsection', 'section botany', 'subsection botany');
            if($taxonRank != $rek['r']) {
                if(!in_array($taxonRank, $rankz) && !in_array($rek['r'], $rankz)) $pairs[] = array($rec, $rek);
            }
        }
        if($pairs) return $pairs;
        /*--- Below here are all DISCARD cases:
            If one rank value is subspecific and the other is something else (but not empty), discard the match
            If one rank value is species and the other is something else (but not empty), discard the match
            If one rank is genus and the other is something else (but not empty), discard the match
            If one rank is subgenus and the other is something else (but not empty), discard the match
            If one rank is section or section botany and the other is something else (but not empty), discard the match
            If one rank is subsection or subsection botany and the other is something else (but not empty), discard the match ---*/
    }
    function name_matching_ancestry_compatibility($pairs, $fromSynonymsYN) //Step 4: Name matching - ancestry compatibility
    {   /*Array(
            [0] => Array(
                    [0] => Array(
                            [taxonID] => 556
                            [furtherInformationURL] => http://reflora.jbrj.gov.br/reflora/listaBrasil/FichaPublicaTaxonUC/FichaPublicaTaxonUC.do?id=FB556
                            [acceptedNameUsageID] => 
                            [parentNameUsageID] => 212
                            [scientificName] => Erythrochiton Nees & Mart.
                            [higherClassification] => Rutaceae|
                            [kingdom] => Plantae
                            [phylum] => 
                            [class] => 
                            [order] => 
                            [family] => Rutaceae
                            [genus] => Erythrochiton
                            [taxonRank] => genus
                            [scientificNameAuthorship] => Nees & Mart.
                            [taxonomicStatus] => accepted
                            [canonicalName] => Erythrochiton
                            [EOLid] => 
                            [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Rutaceae\|.*?] ]
                        )
                    [1] => Array(
                            [r] => genus
                            [e] => 47126261
                            [h] => Life|Cellular Organisms|Eukaryota|Archaeplastida|Chloroplastida|Streptophyta|Embryophytes|Tracheophyta|Spermatophytes|Angiosperms|Eudicots|Superrosids|Rosids|Sapindales|Rutaceae
                            [c] => Erythrochiton
                            [t] => EOL-000000458933
                            [s] => a
                        )
                )
            [1] => Array(
                    [0] => Array(
                            [taxonID] => 556
                            [furtherInformationURL] => http://reflora.jbrj.gov.br/reflora/listaBrasil/FichaPublicaTaxonUC/FichaPublicaTaxonUC.do?id=FB556
                            [acceptedNameUsageID] => 
                            [parentNameUsageID] => 212
                            [scientificName] => Erythrochiton Nees & Mart.
                            [higherClassification] => Rutaceae|
                            [kingdom] => Plantae
                            [phylum] => 
                            [class] => 
                            [order] => 
                            [family] => Rutaceae
                            [genus] => Erythrochiton
                            [taxonRank] => genus
                            [scientificNameAuthorship] => Nees & Mart.
                            [taxonomicStatus] => accepted
                            [canonicalName] => Erythrochiton
                            [EOLid] => 
                            [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Rutaceae\|.*?] ]
                        )
                    [1] => Array(
                            [r] => genus
                            [e] => 9372
                            [h] => Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Bilateria|Protostomia|Ecdysozoa|Arthropoda|Pancrustacea|Hexapoda|Insecta|Pterygota|Neoptera|Endopterygota|Coleoptera|Polyphaga|Cucujiformia|Chrysomeloidea|Cerambycidae
                            [c] => Erythrochiton
                            [t] => EOL-000001554251
                            [s] => a
                        )
                )
        )        
        Step 4: Name matching - ancestry compatibility
        Compare the ancestries for each pair of matched canonicals that passed the rank compatibility check

        If Ancestry Index values are the same, keep the match and go to Step 6
        If Ancestry Index values are different:
            Use the compatibleAncestors.txt file to determine if the Index values are compatible, i.e., if the file has a line that has both of the values, they are compatible.
                If the ancestors are compatible, keep the match and go to Step 6
                If the ancestors are not compatible, go to step 5.
        If one or both Ancestry Index values are empty, keep the match and go to Step 6 */
        
        /* First step is to assign the AI for each rec and rek */
        $i = -1;
        foreach($pairs as $pair) { $i++;
            $rec = $pair[0];
            $pairs[$i][0]['AI'] = self::parse_AI_from_str($rec['taxonRemarks']);
            // /* Just for confirmation.
            if($may_AI_na = @$rec['AI']) {
                if($may_AI_na != $pairs[$i][0]['AI']) exit("\nInvestigate: AI should be the same\n");
            }
            // */

            $rek = $pair[1];
            // /* ---------- for the synonym Step 5
            if(substr($rek['t'],0,4) == "SYN-") $rek = self::fill_in_accepted_data_for_this_syn($rek);
            /*Array( from GloBI
                [r] => genus
                [e] => 
                [h] => 
                [c] => Trichodina
                [t] => SYN-100000473021
                [s] => n
                [e2] => 46988866
                [h2] => Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Bilateria|Protostomia|Spiralia|Mollusca|Gastropoda|Heterobranchia|Euthyneura|Tectipleura|Eupulmonata|Stylommatophora|Achatinina|Achatinoidea|Achatinidae|Petriolinae
            )*/
            $rek_h = $rek['h'] ? $rek['h'] : @$rek['h2'];
            if(@$rek['h2']) echo "\nrek_h to use: [$rek_h]";
            // ---------- */
            if($arr = $this->search_hc_string_from_AncestryIndex_regex($rek_h)) { // get AI for $rek['h']
                if($fromSynonymsYN) echo "\n Success: search_hc_string_from_AncestryIndex_regex()";
                /*Array(
                    [IndexGroup] => Fungi
                    [IndexHC] => .*?\|Basidiomycota\|.*?
                    [lastItem_in_IndexHC] => Basidiomycota
                    [posOfLastItem] => 8
                )*/
                $pairs[$i][1]['tR'] = "DH: [ IndexGroup:[".$arr['IndexGroup']."] - IndexHC:[".$arr['IndexHC']."] ]";
                $pairs[$i][1]['AI'] = $arr['IndexGroup']; 
                if($fromSynonymsYN) {
                    $pairs[$i][1]['c2'] = $rek['c2'];
                    $pairs[$i][1]['e2'] = $rek['e2'];
                    $pairs[$i][1]['h2'] = $rek['h2'];
                }
            }
            else { if($fromSynonymsYN) echo "\n Failed: search_hc_string_from_AncestryIndex_regex()";
                $pairs[$i][1]['tR'] = '';
                $pairs[$i][1]['AI'] = '';
                if($fromSynonymsYN) {
                    $pairs[$i][1]['c2'] = '';
                    $pairs[$i][1]['e2'] = '';
                    $pairs[$i][1]['h2'] = '';
                }
            }
        } //foreach()
        if($fromSynonymsYN) { echo "\nSynonym run: "; print_r($pairs); }
        // print_r($pairs); exit("\nelix 5\n"); //good debug to see what do we exactly have here.

        /* If Ancestry Index values are the same, keep the match and go to Step 6 */
        $pairz = array();
        foreach($pairs as $pair) {
            $rec = $pair[0];
            $rek = $pair[1];
            if($rec['AI'] && $rek['AI']) {
                if($rec['AI'] == $rek['AI']) $pairz[] = array($rec, $rek);
            }
        }
        if($pairz) return $pairz;

        /*  If Ancestry Index values are different:
                Use the compatibleAncestors.txt file to determine if the Index values are compatible, i.e., if the file has a line that has both of the values, they are compatible.
                    If the ancestors are compatible, keep the match and go to Step 6
                    If the ancestors are not compatible, go to step 5. */
        $pairz = array();
        foreach($pairs as $pair) {
            $rec = $pair[0];
            $rek = $pair[1];
            if($rec['AI'] && $rek['AI']) {
                if($rec['AI'] != $rek['AI']) {
                    if(self::are_the_IndexValues_compatible(array($rec['AI'], $rek['AI']))) $pairz[] = array($rec, $rek);
                    else { 
                        if(!$fromSynonymsYN) {
                            echo "\n---> Going to Step 5...\n";
                            if($syn_pair = self::name_matching_through_synonyms($rec)) $pairz[] = $syn_pair; //go step 5
                        }
                    }
                }
            }
        }
        if($pairz) return $pairz;

        /* If one or both Ancestry Index values are empty, keep the match and go to Step 6 */
        $pairz = array();
        foreach($pairs as $pair) {
            $rec = $pair[0];
            $rek = $pair[1];
            if(!$rec['AI'] || !$rek['AI']) $pairz[] = array($rec, $rek);
        }
        if($pairz) return $pairz;
    }
    private function fill_in_accepted_data_for_this_syn($rek)
    {
        echo "\n --->Starting syn rek: "; print_r($rek);
        /*Array(
            [r] => genus
            [e] => 
            [h] => 
            [c] => Rotula
            [t] => SYN-100000458295
            [s] => n
        )*/
        $syn_id = $rek['t'];
        echo "\nThis is the synonym ID: [$syn_id]";
        if($acceptedNameUsageID = $this->DH->DH_synonyms[$syn_id]) {
            echo "\nThis is the acceptedNameUsageID: [$acceptedNameUsageID]";
            if($accepted_rec = $this->DH->DH[$acceptedNameUsageID]) {
                echo "\nThis is the accepted record: "; print_r($accepted_rec);
                /*Array(
                    [c] => Harmogenanina
                    [r] => genus
                )*/
                if($new_rek = $this->DH->DHCanonical_info[$accepted_rec['c']][$acceptedNameUsageID]) {
                    echo "This is a more complete accepted record: "; print_r($new_rek);
                    /*Array(
                        [r] => genus
                        [e] => 48886174
                        [h] => Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Bilateria|Protostomia|Spiralia|Mollusca|Gastropoda|Heterobranchia|Euthyneura|Tectipleura|Eupulmonata|Stylommatophora|Helicina|Limacoidei|Helicarionoidea|Helicarionidae|Helicarioninae
                        [c] => Harmogenanina
                        [t] => EOL-000000768620
                        [s] => a
                    )*/
                    /* Now let us to the assignment */
                    $rek['c2'] = $new_rek['c']; //canonicalName
                    $rek['e2'] = $new_rek['e']; //EOLid
                    $rek['h2'] = $new_rek['h']; //higherClassification
                }
                else exit("\nERROR: There should be new_rek\n");
            }
            else exit("\nERROR: There should be accepted_rec.\n");
        }
        else exit("\nERROR: There should be acceptedNameUsageID.\n");
        // exit("\n-stop test 2-\n");
        echo "\n ---> Ending syn rek: "; print_r($rek);        
        /*Array( from GloBI
            [r] => genus
            [e] => 
            [h] => 
            [c] => Trichodina
            [t] => SYN-100000473021
            [s] => n
            [e2] => 46988866
            [h2] => Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Bilateria|Protostomia|Spiralia|Mollusca|Gastropoda|Heterobranchia|Euthyneura|Tectipleura|Eupulmonata|Stylommatophora|Achatinina|Achatinoidea|Achatinidae|Petriolinae
        )*/
        return $rek;
    }
    private function name_matching_through_synonyms($rec) //Step 5: Name matching through synonyms
    {   /* For reference only
        $this->DHCanonical_info[$canonicalName][$taxonID] = array('r' => $rec['taxonRank'], 'e' => $rec['eolID'], 'h' => $rec['higherClassification']
            , 'c' => $rec['canonicalName'] //canonicalName will be used for Katja's #2 - #4 & #5 here: https://github.com/EOL/ContentImport/issues/33#issue-3234665155
            , 't' => $rec['taxonID']       //canonicalName will be used for Katja's #2 - #4 & #5 here: https://github.com/EOL/ContentImport/issues/33#issue-3234665155
            , 's' => substr($rec['taxonomicStatus'],0,1)); // 'a' accepted | 'n' not accepted */
        /* 1st step: Check if canonical that remain unmatched after Step 4 can be matched to canonical name strings of DH synonyms (taxonomic status = "not accepted"). */

        // 1. Check if any of the canonicals that remain unmatched after Step 4 can be matched to canonical name strings of DH synonyms (taxonomic status = "not accepted").
        if($synonym_reks = self::get_synonym_reks_from_DH_for_this_canonical($rec['canonicalName'])) {
            echo "\nMay synonym_reks: "; print_r($synonym_reks);
            /*Array(
                [0] => Array(
                        [r] => genus
                        [e] => 
                        [h] => 
                        [c] => Rotula
                        [t] => SYN-100000458295
                        [s] => n
                    )
            )*/
            // 2. For each matched pair, check for rank compatibility as above
            if($ret = self::matching_routine_using_rank_v2($rec, $synonym_reks)) { //Step 3: Name matching - rank compatibility
                echo "\nIt is rank compatible: "; print_r($ret); //exit;
                /*Array(
                    [0] => Array(
                            [0] => Array(
                                    [taxonID] => 16555
                                    [furtherInformationURL] => http://reflora.jbrj.gov.br/reflora/listaBrasil/FichaPublicaTaxonUC/FichaPublicaTaxonUC.do?id=FB16555
                                    [acceptedNameUsageID] => 
                                    [parentNameUsageID] => 64
                                    [scientificName] => Rotula Lour.
                                    [namePublishedIn] => 
                                    [higherClassification] => Boraginaceae|
                                    [kingdom] => Plantae
                                    [phylum] => 
                                    [class] => 
                                    [order] => 
                                    [family] => Boraginaceae
                                    [genus] => Rotula
                                    [taxonRank] => genus
                                    [scientificNameAuthorship] => Lour.
                                    [taxonomicStatus] => accepted
                                    [modified] => 2018-02-18 22:20:44.229
                                    [canonicalName] => Rotula
                                    [EOLid] => 
                                    [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Boraginaceae\|.*?] ]
                                    [AI] => Angiosperms
                                )
                            [1] => Array(
                                    [r] => genus
                                    [e] => 
                                    [h] => 
                                    [c] => Rotula
                                    [t] => SYN-100000458295
                                    [s] => n
                                )
                        )
                )*/

                // 3. For each pair that passed the rank compatibility check, check for ancestry compatibility as above, using the ancestry string of the synonym's accepted name.
                $fromSynonyms = true;
                if($ret2 = self::name_matching_ancestry_compatibility($ret, $fromSynonyms)) { //Step 4: Name matching - ancestry compatibility
                    print_r($ret2); echo("\nSYNONYMS: Reached this point.\n");
                    /*Array(
                        [0] => Array(
                                [0] => Array(
                                        [taxonID] => IRMNG:1444425
                                        [furtherInformationURL] => https://www.irmng.org/aphia.php?p=taxdetails&id=1444425
                                        [referenceID] => 
                                        [parentNameUsageID] => 
                                        [scientificName] => Trichodina
                                        [namePublishedIn] => 
                                        [higherClassification] => Animalia|Mollusca|Gastropoda|Stylommatophora|Subulinidae|
                                        [kingdom] => Animalia
                                        [phylum] => Mollusca
                                        [class] => Gastropoda
                                        [order] => Stylommatophora
                                        [family] => Subulinidae
                                        [genus] => Trichodina
                                        [taxonRank] => genus
                                        [taxonomicStatus] => 
                                        [taxonRemarks] => Trait: [ IndexGroup:[Gastropoda] - IndexHC:[.*?\|Gastropoda\|.*?] ]
                                        [canonicalName] => Trichodina
                                        [EOLid] => 
                                        [AI] => Gastropoda
                                    )
                                [1] => Array(
                                        [r] => genus
                                        [e] => 
                                        [h] => 
                                        [c] => Trichodina
                                        [t] => SYN-100000473021
                                        [s] => n
                                        [tR] => DH: [ IndexGroup:[Gastropoda] - IndexHC:[.*?\|Gastropoda\|.*?] ]
                                        [AI] => Gastropoda
                                        [c2] => Petriola
                                        [e2] => 46988866
                                        [h2] => Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Bilateria|Protostomia|Spiralia|Mollusca|Gastropoda|Heterobranchia|Euthyneura|Tectipleura|Eupulmonata|Stylommatophora|Achatinina|Achatinoidea|Achatinidae|Petriolinae
                                    )
                            )
                    )*/
                    /*  4. If there are multiple synonym matches that pass both compatibility checks, keep the best one, using the following criteria:
                        4.1. rank values are the same is better than rank values are different but compatible
                        4.2. Ancestry Index values are the same is better than Ancestry Index values are different but compatible
                        5. If there are multiple synonym matches that cannot be resolved using the criteria above, randomly pick one for the resource file EOLid assignment, 
                           but put all of the best matches in the With_DH_EOLid_assignments_(synonym).tsv report.
                        6. For successful synonym matches, assign the EOLid of the synonym's accepted name to the taxon in the resource file. */
                    if(count($ret2) > 1) {
                        print_r($ret2); exit("\nSo it can happen: multiple synonym matches that pass both compatibility checks.\n");
                    }
                    $pair = self::choose_one_from_multiple_pairs($ret2, 'synonym');
                    return $pair;
                }
                else echo " -- not ancestry compatible\n";
            }
            else echo " -- not rank compatible\n";
        }
        else echo " No synonym_reks\n";
    }
    function choose_one_from_multiple_pairs($ret2, $what)
    {
        // For reporting:
        if($what == 'synonym') {
            foreach($ret2 as $pair) {
                $rec = $pair[0]; $rek = $pair[1];
                $rec['EOLid'] = $rek['e'] ? $rek['e'] : @$rek['e2'];
                if($rec['EOLid']) $this->debug['With DH EOLid assignments (synonym)'][$rec['taxonID']] = $rec;
            }                       
        }

        // For both accepted and synonym
        foreach($ret2 as $pair) {
            $rec = $pair[0]; $rek = $pair[1];
            if( ($rec['taxonRank'] == $rek['r']) && ($rec['AI'] == $rek['AI']) ) return array($rec, $rek);  //rank values and AI are the same
        }
        foreach($ret2 as $pair) {
            $rec = $pair[0]; $rek = $pair[1];
            if($rec['taxonRank'] == $rek['r']) return array($rec, $rek);                                    //rank values are the same
        }
        foreach($ret2 as $pair) {
            $rec = $pair[0]; $rek = $pair[1];
            if($rec['AI'] == $rek['AI']) return array($rec, $rek);                                          //AI values are the same
        }

        if(count($ret2) > 1) {
            print_r($ret2); exit("\nReady to pick one, just curious why there are >1 pairs here at this point.\n");
        }
        foreach($ret2 as $pair) { //exit("\nHmmm... just curious so it can go here. [$what]\n");            //just pick one
            print_r($pair); echo " -> picked 1\n";
            $rec = $pair[0]; $rek = $pair[1];
            return array($rec, $rek);
        }                            
    }
    private function get_synonym_reks_from_DH_for_this_canonical($canonicalName)
    {
        if($reks = @$this->DH->DHCanonical_info[$canonicalName]) {
            if($synonym_reks = self::filter_reks_by_what($reks, 'synonym')) return $synonym_reks;
        }
    }
    function filter_reks_by_what($reks, $tax_status) //possible tax_status values: 'accepted' OR 'synonym'
    {   /* print_r($this->DH->DHCanonical_info['Aa brevis']);
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
        /*Array( it can be multiple reks like the one below, OR just a single rek.
            [EOL-000000458933] => Array(
                    [r] => genus
                    [e] => 47126261
                    [h] => Life|Cellular Organisms|Eukaryota|Archaeplastida|Chloroplastida|Streptophyta|Embryophytes|Tracheophyta|Spermatophytes|Angiosperms|Eudicots|Superrosids|Rosids|Sapindales|Rutaceae
                    [c] => Erythrochiton
                    [t] => EOL-000000458933
                    [s] => a
                )
            [EOL-000001554251] => Array(
                    [r] => genus
                    [e] => 9372
                    [h] => Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Bilateria|Protostomia|Ecdysozoa|Arthropoda|Pancrustacea|Hexapoda|Insecta|Pterygota|Neoptera|Endopterygota|Coleoptera|Polyphaga|Cucujiformia|Chrysomeloidea|Cerambycidae
                    [c] => Erythrochiton
                    [t] => EOL-000001554251
                    [s] => a
                )
        )*/
            if($tax_status == 'accepted') $sought = 'a';
        elseif($tax_status == 'synonym')  $sought = 'n';
        else exit("\nERROR: tax_status not set.\n");
        $final = array();
        foreach($reks as $rek) {
            if($rek['s'] == $sought) {
                // if($rek['e']) $final[] = $rek;  //Eli's initiative: exclude reks with blank eolID's
                $final[] = $rek;
            }
        }
        return $final;
    }
    private function parse_AI_from_str($str) //e.g. $str "Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Rutaceae\|.*?] ]"
    {
        if(preg_match("/IndexGroup\:\[(.*?)\]/ims", $str, $a)) return $a[1];
    }
    private function rank_is_subspecific_YN($rank)
    {
        if(isset($this->subspecific_ranks[$rank])) return true;
        else return false;
    }
}
?>