<?php

namespace php_active_record;
/* connector: [called from DwCA_Utility.php, which is called from match_taxa_2DH.php] */

use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class DwCA_MatchTaxa2DH
{
    function __construct($archive_builder, $resource_id, $archive_path)
    {
        $this->resource_id = $resource_id;
        $this->archive_builder = $archive_builder;
        $this->archive_path = $archive_path;
        $this->download_options = array('cache' => 1, 'resource_id' => $resource_id, 'expire_seconds' => 60 * 60 * 24 * 1, 'download_wait_time' => 500000, 'timeout' => 10800, 'download_attempts' => 1, 'delay_in_minutes' => 1);
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
    

    }
    /*================================================================= STARTS HERE ======================================================================*/
    function start($info)
    {
        // /* step 1: read info from DH
        require_library('connectors/DHConnLib');
        $this->DH = new DHConnLib(1);
        $this->DH->build_up_taxa_info(); //generates 4 info lookups
        echo "\naaa2:".count($this->DH->DHCanonical_info)."";
        echo "\nxxx2:".count($this->DH->DH)."";
        echo "\nyyy2:".count($this->DH->DH_synonyms)."";
        echo "\nzzz2:".count($this->DH->DH_acceptedNames)."\n"; //exit("\n");
        // */

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

        self::process_table($meta, 'generate_synonyms_info');
        self::process_table($meta, 'match_canonical');
        // self::process_table($meta, 'write_archive'); // COPIED TEMPLATE


        echo "\n--STATS--\nHas canonical match: [" . number_format(@$this->debug['Has canonical match'] ?? 0) . "]";
        echo "\nWith eolID assignments: [" . number_format(@$this->debug['With eolID assignments'] ?? 0) . "]\n";

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

        print_r(@$this->debug['taxonomicStatus']);
        echo "\ntotal acceptedNameUsageID: [" . number_format(@$this->debug['total acceptedNameUsageID'] ?? 0) . "]\n";

        echo "\nmatched ancestry: [" . number_format(@$this->debug['matched ancestry'] ?? 0) . "]";
        echo "\nmatched higherClassification: [" . number_format(@$this->debug['matched higherClassification'] ?? 0) . "]";

        echo "\nmatched just 1 record: [" . number_format(@$this->debug['matched just 1 record'] ?? 0) . "]";
        echo "\nmatched same rank and status accepted: [" . number_format(@$this->debug['matched same rank and status accepted'] ?? 0) . "]";
        echo "\nmatched same rank: [" . number_format(@$this->debug['matched same rank'] ?? 0) . "]";
        echo "\nmatched group rank: [" . number_format(@$this->debug['matched group rank'] ?? 0) . "]";
        echo "\naccepted only: [" . number_format(@$this->debug['accepted only'] ?? 0) . "]";
        echo "\nmatched 1st rek: [" . number_format(@$this->debug['matched 1st rek'] ?? 0) . "]";
        echo "\nmatched blank eolID: [" . number_format(@$this->debug['matched blank eolID'] ?? 0) . "]";



        $total = @$this->debug['matched ancestry'] + @$this->debug['matched higherClassification'] 
                + @$this->debug['matched just 1 record']
                + @$this->debug['matched same rank and status accepted']
                + @$this->debug['matched same rank'] 
                + @$this->debug['matched group rank']
                + @$this->debug['accepted only']
                + @$this->debug['matched 1st rek']
                + @$this->debug['matched blank eolID'];
        $diff = $total - @$this->debug['Has canonical match'];
        echo "\nTotal 3 matches: [" . number_format($total) . "] -> should be equal to: [Has canonical match] [$diff]\n";

        asort($this->debug['counts of reks at this point']);
        echo "\n[# of rek in reks][total count]";
        $sum = 0;
        foreach($this->debug['counts of reks at this point'] as $totals => $count) {
            echo "\n[$totals][$count]";
            $sum += $count;
        } 
        $diff = $sum - @$this->debug['matched just 1 record'] - @$this->debug['matched same rank and status accepted'] 
                     - @$this->debug['matched same rank'] - @$this->debug['matched 1st rek'];
        echo "\nSum: [$sum] -> should be equal to: [sum of last 4 matches] [$diff]\n";

        if(@$this->debug['eli']) print_r($this->debug['eli']);

        // if ($this->debug) Functions::start_print_debug($this->debug, $this->resource_id); //works OK
        // exit("\nstop muna\n"); //dev only
    }
    private function process_table($meta, $what)
    {   //print_r($meta);
        echo "\nprocess_table: [$what] [$meta->file_uri]...\n";
        $i = 0;
        foreach (new FileIterator($meta->file_uri) as $line => $row) {
            $i++;
            if (($i % 100000) == 0) echo "\n" . number_format($i) . " - "; //10k orig
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
            } // print_r($rec); exit;
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
            $rec = self::not_recongized_fields($rec);
            // */
            $rec['http://eol.org/schema/EOLid'] = '';

            $taxonID = $rec['http://rs.tdwg.org/dwc/terms/taxonID'];
            $taxonRank = $rec['http://rs.tdwg.org/dwc/terms/taxonRank'];
            $taxonomicStatus = $rec['http://rs.tdwg.org/dwc/terms/taxonomicStatus'];
            $acceptedNameUsageID = @$rec['http://rs.tdwg.org/dwc/terms/acceptedNameUsageID'];
            $canonicalName = self::format_canonical($rec['http://rs.gbif.org/terms/1.0/canonicalName']);

            if ($what == 'match_canonical') {
                if (!$canonicalName) continue;
                if ($reks = @$this->DH->DHCanonical_info[$canonicalName]) {
                    if ($taxonRank) $rec = self::main_matching_routine($rec, $reks, $taxonRank);
                } else {
                    $this->debug['No canonical match'][$canonicalName] = '';
                }
                // /* start writing:
                $o = new \eol_schema\Taxon();
                $uris = array_keys($rec); // print_r($uris); //exit;
                foreach ($uris as $uri) {
                    $field = self::get_field_from_uri($uri);
                    $o->$field = $rec[$uri];
                }
                $this->archive_builder->write_object_to_file($o);
                // */
            }
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
            // if($i >= 100) break; //dev only
        }
    }
    private function main_matching_routine($rec, $reks, $taxonRank)
    {
        // print_r($rec); //DwCA in question
        // print_r($reks); exit("\nfrom DH\n"); //DH
        /*Array(
            [EOL-000000020456] => Array(
                    [r] => genus
                    [e] => 47182486
                    [h] => Life|Cellular Organisms|Bacteria|Proteobacteria|Gammaproteobacteria|Enterobacterales|Hafniaceae
                    [c] => Edwardsiella
                    [t] => 001 {taxonID}
                )
            [EOL-000000547422] => Array(
                    [r] => genus
                    [e] => 54411
                    [h] => Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Cnidaria|Anthozoa|Hexacorallia|Actiniaria|Anenthemonae|Edwardsioidea|Edwardsiidae
                    [c] => Edwardsiella
                    [t] => 002 {taxonID}
                )
        )
        Matching taxa across ranks. Sometimes taxa have different ranks but they share the same canonical name.
        1, 2, 
        3. When you get to family or below, taxon matching across ranks becomes increasingly iffy.
        */
        $rek = self::which_rek_to_use($rec, $reks, $taxonRank); //important step!
        // print_r($rek); exit("\nstopx\n");
        $DH_rank = $rek['r'];
        $DH_canonical = $rek['c'];
        $DH_taxonID = $rek['t'];
        if ($taxonRank == $DH_rank) $rec['http://eol.org/schema/EOLid'] = $rek['e']; //eolID
        // 1. In general it's ok to match taxa with different ranks if the taxa have higher ranks like phyla, classes, and orders.
        if (in_array($taxonRank, $this->ok_match_higher_ranks) && in_array($DH_rank, $this->ok_match_higher_ranks)) $rec['http://eol.org/schema/EOLid'] = $rek['e']; //eolID
        // 2. It's also ok to match taxa with different ranks if both taxa have a subspecific rank, e.g., subspecies | variety | form | forma | infraspecies | infraspecific name | infrasubspecific name | subvariety | subform | proles | lusus | forma specialis
        if (in_array($taxonRank, $this->ok_match_subspecific_ranks) && in_array($DH_rank, $this->ok_match_subspecific_ranks)) $rec['http://eol.org/schema/EOLid'] = $rek['e']; //eolID
        
        
        $taxonID = $rec['http://rs.tdwg.org/dwc/terms/taxonID'];
        // $taxonRank = $rec['http://rs.tdwg.org/dwc/terms/taxonRank'];
        // $taxonomicStatus = $rec['http://rs.tdwg.org/dwc/terms/taxonomicStatus'];
        // $acceptedNameUsageID = @$rec['http://rs.tdwg.org/dwc/terms/acceptedNameUsageID'];
        // $canonicalName = self::format_canonical($rec['http://rs.gbif.org/terms/1.0/canonicalName']);

        // /*
        // 4. In particular, we never want to match a genus with a taxon of any other rank except a subgenus. 
        // However, we only want to do that if we have an explicit synonym relationship from a source hierarchy for the genus and subgenus.
        if ($taxonRank == 'genus' && $DH_rank == 'subgenus') {
            @$this->debug['canonical match: genus - subgenus']++;
            if(self::are_these_synonyms_in_DwCA($taxonID, $DH_canonical, 1)) {
                $rec['http://eol.org/schema/EOLid'] = $rek['e']; //eolID
                @$this->debug['canonical match: genus - subgenus OK']++;
            }
            elseif(self::are_these_synonyms_in_DH($DH_taxonID, $DH_canonical, 1)) {
                $rec['http://eol.org/schema/EOLid'] = $rek['e']; //eolID
                @$this->debug['canonical match: genus - subgenus OK DH']++;
            }
        } elseif ($taxonRank == 'subgenus' && $DH_rank == 'genus') {
            @$this->debug['canonical match: subgenus - genus']++;
            if(self::are_these_synonyms_in_DwCA($taxonID, $DH_canonical, 1)) {
                $rec['http://eol.org/schema/EOLid'] = $rek['e']; //eolID
                @$this->debug['canonical match: subgenus - genus OK']++;
            }
            elseif(self::are_these_synonyms_in_DH($DH_taxonID, $DH_canonical, 1)) {
                $rec['http://eol.org/schema/EOLid'] = $rek['e']; //eolID
                @$this->debug['canonical match: subgenus - genus OK DH']++;
            }
        }

        // 5. Similarly, we never want to match a species with a taxon of any other rank except one of the subspecific ranks (see above). 
        // However, we only want to do that if we have an explicit synonym relationship from a source hierarchy for the species and the subspecific name.
        if ($taxonRank == 'species' && in_array($DH_rank, $this->ok_match_subspecific_ranks)) {
            @$this->debug['canonical match: species - any subspecific ranks']++;
            // $this->debug['eli']['canonical match: species - any subspecific ranks'][] = array('DH' => $rek, 'DwCA' => $rec); //good debug
            if(self::are_these_synonyms_in_DwCA($taxonID, $DH_canonical, 2)) { //print_r($rek); echo("\n111\n");
                $rec['http://eol.org/schema/EOLid'] = $rek['e']; //eolID
                @$this->debug['canonical match: species - any subspecific ranks OK']++;
            }
            elseif(self::are_these_synonyms_in_DH($DH_taxonID, $DH_canonical, 2)) {
                $rec['http://eol.org/schema/EOLid'] = $rek['e']; //eolID
                @$this->debug['canonical match: species - any subspecific ranks OK DH']++;
            }
        } elseif ($DH_rank == 'species' && in_array($taxonRank, $this->ok_match_subspecific_ranks)) {
            @$this->debug['canonical match: any subspecific ranks - species']++;
            // print_r($rec); print_r($rek); exit("\nFound a hit!\n");

            if(self::are_these_synonyms_in_DwCA($taxonID, $DH_canonical, 2)) { //print_r($rek); echo("\n222\n");
                $rec['http://eol.org/schema/EOLid'] = $rek['e']; //eolID
                @$this->debug['canonical match: any subspecific ranks - species OK']++;
            }
            elseif(self::are_these_synonyms_in_DH($DH_taxonID, $DH_canonical, 2)) {
                $rec['http://eol.org/schema/EOLid'] = $rek['e']; //eolID
                @$this->debug['canonical match: any subspecific ranks - species OK DH']++;
            }
        }
        // */

        @$this->debug['Has canonical match']++;
        if ($rec['http://eol.org/schema/EOLid']) @$this->debug['With eolID assignments']++;
        return $rec;
    }

    private function which_rek_to_use($rec, $reks, $taxonRank)
    {   /*e.g. $reks Array(
            [EOL-000000020456] => Array(
                    [r] => genus
                    [e] => 47182486
                    [h] => Life|Cellular Organisms|Bacteria|Proteobacteria|Gammaproteobacteria|Enterobacterales|Hafniaceae
                )
            [EOL-000000547422] => Array(
                    [r] => genus
                    [e] => 54411
                    [h] => Life|Cellular Organisms|Eukaryota|Opisthokonta|Metazoa|Cnidaria|Anthozoa|Hexacorallia|Actiniaria|Anenthemonae|Edwardsioidea|Edwardsiidae
                )
        )
        Array( e.g. Brazilian Flora
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
            [http://eol.org/schema/EOLid] => 
        )*/

        // OPTION 1: DwCA ancestry
        // step 1: get ancestry scinames to search from DwCA taxa
        $ranks = array('kingdom', 'phylum', 'class', 'order', 'family', 'genus');
        $DwCA_names_2search = array();
        foreach($ranks as $rank) {
            if($val = @$rec['http://rs.tdwg.org/dwc/terms/'.$rank]) $DwCA_names_2search[] = $val;       //all the ancestry scinames
        }
        if($val = @$rec['http://rs.gbif.org/terms/1.0/canonicalName']) $DwCA_names_2search[] = $val;    //the canonical name

        // step 2: search in DH reks which higherClassification matches with any of the DwCA_names_2search
        if($rek = self::get_rek_from_reks($reks, $DwCA_names_2search)) {
            @$this->debug['matched ancestry']++;
            return $rek;
        }

        // OPTION 2: DwCA higherClassification
        $DwCA_names_2search = array();
        if($hc = @$rec['http://rs.tdwg.org/dwc/terms/higherClassification']) {
            if($separator = self::get_separator_in_higherClassification($hc)) {
                if($separator == 'is_1_word') $DwCA_names_2search = array($hc);
                else {
                    $DwCA_names_2search = explode($separator, $hc);
                }
            }
        }
        if($val = @$rec['http://rs.gbif.org/terms/1.0/canonicalName']) $DwCA_names_2search[] = $val;    //the canonical name

        // step 2: search in DH reks which higherClassification matches with any of the DwCA_names_2search
        if($rek = self::get_rek_from_reks($reks, $DwCA_names_2search)) {
            @$this->debug['matched higherClassification']++;
            return $rek;
        }

        // // /* for stats only
        // @$this->debug['counts of reks at this point'][count($reks)]++;
        // // */
        
        // OPTION 3: choose rek from multiple reks
        if($rek = self::choose_rek_from_multiple_reks($reks, $rec)) {
            return $rek;
        }

        if(count($reks) > 2) {
            print_r($reks);
            print_r($rec);
            exit("\nInvestigate muna\n");
        }

        exit("\nShould not go here\n");
    }
    private function get_rek_from_reks($reks, $DwCA_names_2search)
    {
        $DwCA_names_2search = array_map('trim', $DwCA_names_2search);
        if(!$DwCA_names_2search) return false;
        foreach($reks as $DH_taxonIDx => $rek) {
            if($temp = @$rek['h']) {
                $DH_higherClassification = explode("|", $temp);
                $DH_higherClassification = array_map('trim', $DH_higherClassification);
                foreach($DwCA_names_2search as $name) {
                    if(in_array($name, $DH_higherClassification)) return $rek;
                }
            }
        }
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

        /* reference only
        $this->DH->DH_synonyms[$taxonID] = $acceptedNameUsageID;
        $this->DH->DH_acceptedNames[$acceptedNameUsageID] = $taxonID;
        */
        if($accepted_id = @$this->DH->DH_synonyms[$taxonID]) {
            /* reference only            
            $this->DH->DH[$taxonID] = array("c" => $canonicalName);
            */
            if($rec = $this->DH->DH[$accepted_id]) {
                if($rec['c'] == $DH_canonical && in_array($rec['r'], $choices)) return true;
            }
        }

// SYN-000000207590	EOL-000000462763		Cassia pendula E.Agbayani	Senna pendula	E.Agbayani	variety	not accepted	COL-15	COL:a423c550b4fd0b0feefa2477637935ff	http://www.catalogueoflife.org/annual-checklist/2019/details/species/id/19f057e06cfc7dbd915c90b6bb2e5f70/synonym/a423c550b4fd0b0feefa2477637935ff			
        if($SYN_ids = @$this->DH->DH_acceptedNames[$taxonID]) { //exit("\nhere 01\n");
            /* reference only            
            $this->DH->DH[$taxonID] = array("c" => $canonicalName);
            */
            foreach(array_keys($SYN_ids) as $SYN_id) {
                if($syn_rec = $this->DH->DH[$SYN_id]) {
                    // print_r($syn_rec); print_r($choices); exit("\n[$DH_canonical]\nhere 02\n");
                    if($syn_rec['c'] == $DH_canonical && in_array($syn_rec['r'], $choices)) return true;
                }
            }

        }
        return false;
    }
    private function choose_rek_from_multiple_reks($reks, $rec)
    {
        /*
        [EOL-000000090932] => Array(
                    [r] => genus
                    [e] => 47081311
                    [h] => Life|Cellular Organisms|Eukaryota|SAR (Stramenopiles, Alveolates, Rhizaria)|Stramenopiles|Ochrophyta|Bacillariophyta|Fragilariophyceae|Fragilariophycidae|Licmophorales|Ulnariaceae
                    [c] => Ctenophora
                    [t] => EOL-000000090932
                )
        Array(
            [http://rs.tdwg.org/dwc/terms/taxonID] => urn:lsid:marinespecies.org:taxname:1248
            [http://rs.tdwg.org/ac/terms/furtherInformationURL] => https://www.marinespecies.org/aphia.php?p=taxdetails&id=1248
            [http://eol.org/schema/reference/referenceID] => WoRMS:citation:1248
            [http://rs.tdwg.org/dwc/terms/acceptedNameUsageID] => urn:lsid:marinespecies.org:taxname:1248
            [http://rs.tdwg.org/dwc/terms/parentNameUsageID] => urn:lsid:marinespecies.org:taxname:2
            [http://rs.tdwg.org/dwc/terms/scientificName] => Ctenophora Eschscholtz, 1829
            [http://rs.tdwg.org/dwc/terms/namePublishedIn] => Eschscholtz, F. (1829). System der Acalephen. Eine ausführliche Beschreibung aller medusenartigen Strahltiere. Ferdinand Dümmler, Berlin, pp. 1-190, 116 pls.
            [http://rs.tdwg.org/dwc/terms/kingdom] => Animalia
            [http://rs.tdwg.org/dwc/terms/phylum] => Ctenophora
            [http://rs.tdwg.org/dwc/terms/class] => 
            [http://rs.tdwg.org/dwc/terms/order] => 
            [http://rs.tdwg.org/dwc/terms/family] => 
            [http://rs.tdwg.org/dwc/terms/genus] => 
            [http://rs.tdwg.org/dwc/terms/taxonRank] => phylum
            [http://rs.tdwg.org/dwc/terms/taxonomicStatus] => accepted
            [http://rs.tdwg.org/dwc/terms/taxonRemarks] => 
            [http://rs.tdwg.org/dwc/terms/datasetName] => 
            [http://rs.gbif.org/terms/1.0/canonicalName] => Ctenophora
            [http://eol.org/schema/EOLid] => 
        )*/
        $taxonRank = $rec['http://rs.tdwg.org/dwc/terms/taxonRank'];

        // if reks is just 1 record then no choice use it
        if(count($reks) == 1) {
            foreach($reks as $DH_taxonIDx => $rek) {
                @$this->debug['matched just 1 record']++;
                return $rek;
            }
        }
        // 1st loop
        foreach($reks as $DH_taxonIDx => $rek) {
            if($rek['s'] == 'a' && $taxonRank == $rek['r'] && $rek['e']) {
                @$this->debug['matched same rank and status accepted']++;
                return $rek;
            }
        }
        foreach($reks as $DH_taxonIDx => $rek) {
            if($taxonRank == $rek['r'] && $rek['e']) {
                @$this->debug['matched same rank']++;
                return $rek;
            }
        }

        foreach($reks as $DH_taxonIDx => $rek) {
            if(in_array($taxonRank, $this->g_kingdom_domain) && in_array($rek['r'], $this->g_kingdom_domain) && $rek['e']) { @$this->debug['matched group rank']++; return $rek; }
            if(in_array($taxonRank, $this->g_phylum) && in_array($rek['r'], $this->g_phylum) && $rek['e']) { @$this->debug['matched group rank']++; return $rek; }
            if(in_array($taxonRank, $this->g_class) && in_array($rek['r'], $this->g_class) && $rek['e']) { @$this->debug['matched group rank']++; return $rek; }
            if(in_array($taxonRank, $this->g_order) && in_array($rek['r'], $this->g_order) && $rek['e']) { @$this->debug['matched group rank']++; return $rek; }
            if(in_array($taxonRank, $this->g_family) && in_array($rek['r'], $this->g_family) && $rek['e']) { @$this->debug['matched group rank']++; return $rek; }
            if(in_array($taxonRank, $this->g_tribe) && in_array($rek['r'], $this->g_tribe) && $rek['e']) { @$this->debug['matched group rank']++; return $rek; }
            if(in_array($taxonRank, $this->g_genus) && in_array($rek['r'], $this->g_genus) && $rek['e']) { @$this->debug['matched group rank']++; return $rek; }
            if(in_array($taxonRank, $this->g_section) && in_array($rek['r'], $this->g_section) && $rek['e']) { @$this->debug['matched group rank']++; return $rek; }
            if(in_array($taxonRank, $this->g_species) && in_array($rek['r'], $this->g_species) && $rek['e']) { @$this->debug['matched group rank']++; return $rek; }
        }
        $family_tribe = array_merge($this->g_family, $this->g_tribe);
        foreach($reks as $DH_taxonIDx => $rek) {
            if(in_array($taxonRank, $family_tribe) && in_array($rek['r'], $family_tribe) && $rek['e']) { @$this->debug['matched group rank']++; return $rek; }
        }
        $genus_section = array_merge($this->g_genus, $this->g_section);
        foreach($reks as $DH_taxonIDx => $rek) {
            if(in_array($taxonRank, $genus_section) && in_array($rek['r'], $genus_section) && $rek['e']) { @$this->debug['matched group rank']++; return $rek; }
        }

        // 'accepted' vs 'not accepted'
        foreach($reks as $DH_taxonIDx => $rek) {
            if($rek['s'] == 'a' && $rek['e']) {
                @$this->debug['accepted only']++;
                return $rek;
            }
        }

        // last loop: get the 1st rek from reks
        foreach($reks as $DH_taxonIDx => $rek) {
            if($rek['e']) {
                @$this->debug['matched 1st rek']++;
                return $rek;
            }
        }

        // /* for stats only
        @$this->debug['counts of reks at this point'][count($reks)]++;
        // */


        // this has blank eolID
        foreach($reks as $DH_taxonIDx => $rek) {
            @$this->debug['matched blank eolID']++;
            return $rek;
        }

        print_r($reks); print_r($rec); exit("\nInvestigate muna\n"); //good debug
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
        if (isset($rec['http://purl.org/dc/terms/rights']))       unset($rec['http://purl.org/dc/terms/rights']);
        if (isset($rec['http://purl.org/dc/terms/rightsHolder'])) unset($rec['http://purl.org/dc/terms/rightsHolder']);
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
