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

        $this->download_options = array('resource_id' => 'neo4j', 'expire_seconds' => 60*60*24, 'download_wait_time' => 1000000, 'timeout' => 10800, 'download_attempts' => 1);
        $this->ancestry_index_file = "https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/Ancestry_Index.tsv";
        // downloaded as .tsv from: https://docs.google.com/spreadsheets/d/1hImI6u9XXScSxKt7T6hYKoq1tAxj43znrusJA8XMNQc/edit?gid=0#gid=0
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

        $this->ancestry_index = self::retrieve_ancestry_index(); //new from Katja
        foreach($this->ancestry_index as $hc => $indexes) { //checking integrity
            if(count($indexes) > 1) { //maybe it doesn't go here at all.
                print_r($indexes);
                exit("\n[$hc] Non-unique higherClassification in AncestryIndex.\n");
            }
        }

        self::process_table($meta, 'generate_synonyms_info');
        self::process_table($meta, 'match_canonical');
        // self::process_table($meta, 'write_archive'); // COPIED TEMPLATE

        echo $tbl ?? '';
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

        echo "\ntaxonomicStatus breakdown: "; print_r(@$this->debug['taxonomicStatus']);
        echo "total acceptedNameUsageID: [" . number_format(@$this->debug['total acceptedNameUsageID'] ?? 0) . "]\n";

        echo "\na. matched ancestry on AncestryIndex: [" . number_format(@$this->debug['matched ancestry on AncestryIndex'] ?? 0) . "]";
        echo "\nb. matched HC on AncestryIndex: [" . number_format(@$this->debug['matched HC on AncestryIndex'] ?? 0) . "]";

        echo "\n ----- AncestryIndex Katja: [" . number_format(@$this->debug['AncestryIndex Katja'] ?? 0) . "]";
        echo "\n ----- AncestryIndex Eli: [" . number_format(@$this->debug['AncestryIndex Eli'] ?? 0) . "]";


        echo "\n1. matched ancestry: [" . number_format(@$this->debug['matched ancestry*'] ?? 0) . "]";
        echo "\n2. matched higherClassification: [" . number_format(@$this->debug['matched higherClassification*'] ?? 0) . "]";
        echo "\n3. matched just 1 record: [" . number_format(@$this->debug['matched just 1 record'] ?? 0) . "]";
        echo "\n4. matched same rank and status accepted: [" . number_format(@$this->debug['matched same rank and status accepted'] ?? 0) . "]";
        echo "\n5. matched same rank: [" . number_format(@$this->debug['matched same rank'] ?? 0) . "]";
        echo "\n6. matched group rank: [" . number_format(@$this->debug['matched group rank'] ?? 0) . "]";
        echo "\n7. accepted only: [" . number_format(@$this->debug['accepted only'] ?? 0) . "]";
        echo "\n8. matched 1st rek: [" . number_format(@$this->debug['matched 1st rek'] ?? 0) . "]";
        echo "\n9. matched blank eolID: [" . number_format(@$this->debug['matched blank eolID'] ?? 0) . "]";
        $total = @$this->debug['matched ancestry on AncestryIndex'] + @$this->debug['matched HC on AncestryIndex']
                + @$this->debug['matched ancestry*'] + @$this->debug['matched higherClassification*'] 
                + @$this->debug['matched just 1 record']
                + @$this->debug['matched same rank and status accepted']
                + @$this->debug['matched same rank'] 
                + @$this->debug['matched group rank']
                + @$this->debug['accepted only']
                + @$this->debug['matched 1st rek']
                + @$this->debug['matched blank eolID'];
        $diff = $total - @$this->debug['Has canonical match'];
        echo "\nTotal 9 matches: [" . number_format($total) . "] -> should be equal to: [Has canonical match] [$diff]\n";

        if($counts_of_reks = @$this->debug['counts of reks at this point']) {
            asort($counts_of_reks);
            echo "\n[# of rek in reks][total count]";
            $sum = 0;
            foreach($counts_of_reks as $totals => $count) {
                echo "\n[$totals][$count]";
                $sum += $count;
            } 
            // $diff = $sum - @$this->debug['matched just 1 record'] - @$this->debug['matched same rank and status accepted'] 
            //              - @$this->debug['matched same rank'] - @$this->debug['matched 1st rek'];
            $diff = $sum - @$this->debug['matched blank eolID'];
            echo "\nSum: [$sum] -> should be equal to: [matched blank eolID] [$diff]\n";
        }

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
            $this->rec = $rec;
            // */

            $taxonID = $rec['http://rs.tdwg.org/dwc/terms/taxonID'];
            $taxonRank = @$rec['http://rs.tdwg.org/dwc/terms/taxonRank'];
            $taxonomicStatus = @$rec['http://rs.tdwg.org/dwc/terms/taxonomicStatus'];
            $acceptedNameUsageID = @$rec['http://rs.tdwg.org/dwc/terms/acceptedNameUsageID'];
            $canonicalName = self::format_canonical($rec['http://rs.gbif.org/terms/1.0/canonicalName']);

            if ($what == 'match_canonical') {
                if (!$canonicalName)                        {self::write_2archive($rec); continue;}
                if (@$rec['http://eol.org/schema/EOLid'])   {self::write_2archive($rec); continue;}
                $rec['http://eol.org/schema/EOLid'] = '';
                if ($reks = @$this->DH->DHCanonical_info[$canonicalName]) {
                    // $rec = self::matching_routine_using_HC($rec, $reks);
                    // if(@$rec['http://eol.org/schema/EOLid']) {}
                    // else {
                        if($taxonRank) $rec = self::matching_routine_using_rank($rec, $reks, $taxonRank);
                    // }
                } 
                else $this->debug['No canonical match'][$canonicalName] = '';
                self::write_2archive($rec); continue;
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
    private function matching_routine_using_HC($rec, $reks)
    {
        return false;
    }
    private function matching_routine_using_rank($rec, $reks, $taxonRank)
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
        
        // print_r($rec);
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
        // exit("\nhere 3\n");

        $canonicalName = @$rec['http://rs.gbif.org/terms/1.0/canonicalName'];
        // echo "\n[".$canonicalName."] in question\n";

        // OPTION 1: DwCA ancestry
        // step 1: get ancestry scinames to search from DwCA taxa
        $ranks = array('kingdom', 'phylum', 'class', 'order', 'family', 'genus');
        $DwCA_names_2search = array();
        $hc_from_ancestry = array();
        foreach($ranks as $rank) {
            if($val = @$rec['http://rs.tdwg.org/dwc/terms/'.$rank]) {       //all the ancestry scinames
                $DwCA_names_2search[] = $val;
                if($val != $canonicalName) $hc_from_ancestry[] = $val;
            }
        }
        if($val = @$rec['http://rs.gbif.org/terms/1.0/canonicalName']) $DwCA_names_2search[] = $val;    //the canonical name

        if($hc_from_ancestry) { //exit("\nhere 1\n");
            $hc_from_ancestry = implode("|", $hc_from_ancestry)."|";
            // print_r($rec); echo "\ndito eli\n";
            if($rek = self::get_rek_from_reks_byKatja($reks, $hc_from_ancestry, 'ancestry')) {
                @$this->debug['matched ancestry on AncestryIndex']++;
                return $rek;
            }
        }

        // step 2: search in DH reks which higherClassification matches with ALL of the DwCA_names_2search
        if($rek = self::get_rek_from_reks_byEli($reks, $DwCA_names_2search)) {
            // print_r($DwCA_names_2search); echo " - matched ancestry Eli\n";
            @$this->debug['matched ancestry*']++;
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
            // /*
            // exit("\nhere 2\n");
            if($rek = self::get_rek_from_reks_byKatja($reks, $hc, 'higherClassification')) {
                @$this->debug['matched HC on AncestryIndex']++;
                return $rek;
            }
            // */
        }
        if($val = @$rec['http://rs.gbif.org/terms/1.0/canonicalName']) $DwCA_names_2search[] = $val;    //the canonical name

        // step 2: search in DH reks which higherClassification matches with ALL of the DwCA_names_2search
        if($rek = self::get_rek_from_reks_byEli($reks, $DwCA_names_2search)) {
            @$this->debug['matched higherClassification*']++;
            return $rek;
        }
        
        // where OPTION2 1 and 2 fail...
        // OPTION 3: choose rek from multiple reks --- this is Eli-initiated step
        if($rek = self::choose_rek_from_multiple_reks($reks, $rec)) {
            return $rek;
        }

        if(count($reks) > 2) {
            print_r($reks);
            print_r($rec);
            exit("\nInvestigate first.\n");
        }
        exit("\nShould not go here\n");
    }
    private function get_rek_from_reks_byEli($reks, $DwCA_names_2search) //Eli's initiative; kinda permissive. Not strict as Katja's.
    { //all ancestry|higherClassification names from DwCA should exist in the DH higherClassification AND rank matches
        $hits = array();
        $DwCA_names_2search = array_map('trim', $DwCA_names_2search);
        if(!$DwCA_names_2search) return false;
        foreach($reks as $DH_taxonIDx => $rek) {
            if($temp = @$rek['h']) {
                $DH_higherClassification = explode("|", $temp);
                $DH_higherClassification = array_map('trim', $DH_higherClassification);
                // echo "\nxxxxxxxxxxxx\n"; print_r($DH_higherClassification); exit("\nstop muna\n");
                $matches = 0;
                foreach($DwCA_names_2search as $name) {
                    if(in_array($name, $DH_higherClassification)) {
                        if(($this->rec['http://rs.tdwg.org/dwc/terms/taxonRank'] == $rek['r']) && $rek['r']) $matches++;
                    }
                }
                if($matches == count($DwCA_names_2search)) $hits[] = $rek; //all ancestry names exist in DH higherClassification
            }
        }
        if(count($hits) == 1) return $hits[0];
        elseif(count($hits) > 1) {
            print_r($hits); exit("\nSo multiple hits is possible. Need to accomodate this scenario.\n");
        }
        else return false;
    }
    private function get_rek_from_reks_byKatja($reks, $hc, $type)
    {   /*Array(
            [EOL-000002278575] => Array(
                    [r] => order
                    [e] => 5676
                    [h] => Life|Cellular Organisms|Eukaryota|Opisthokonta|Nucletmycea|Fungi|Dikarya|Basidiomycota|Agaricomycetes
                    [c] => Agaricales
                    [t] => EOL-000002278575
                    [s] => a
                )
        )
        Fungi|Basidiomycota| -> from DwCA [ancestry] */
        if($rek = self::matching_byKatja($reks, $hc)) { //Katja's main higherClassification guidelines
            // print_r($rek); exit("\nFound here.\n");
            @$this->debug['AncestryIndex Katja']++;
            return $rek;
        }
        elseif($rek = self::more_strict_matching_byEli($reks, $hc)) { //Eli's initiative, permissive.
            @$this->debug['AncestryIndex Eli']++;
            return $rek;
        }
        // print_r($reks); print_r($hc); exit("\n[$type]\nhere 10\n");
    }
    private function matching_byKatja($reks, $hc) //https://github.com/EOL/ContentImport/issues/33#issuecomment-3115034620
    {
        $dwca_hc = explode("|", $hc);
        $dwca_hc = self::normalize_array($dwca_hc);
        $dwca_hc_string = implode("|", $dwca_hc)."|"; // "Plantae|" //exit("\n[$dwca_hc_string]\nstop muna 1\n");
        foreach($reks as $id => $rek) {
            $DH_hc_string = self::prepare_hc_string($rek['h']); //makes "Fungi|Ascomycota" to "Fungi|Ascomycota|"

            $found1 = false; $found2 = false; $index_hc1 = ''; $index_hc2 = '';
            if($ret = self::search_hc_string_from_AncestryIndex($dwca_hc_string)) {
                $found1 = $ret[0];
                $index_hc1 = $ret[1]; //stats only
            }
            if($ret = self::search_hc_string_from_AncestryIndex($DH_hc_string)) {
                $found2 = $ret[0];
                $index_hc2 = $ret[1]; //stats only
            }
            if(($found1 == $found2) && $found1 && $found2) {
                /* good debug works OK
                echo "\n------------may na huli-----------\n";
                print_r($rek); echo " - rek ";
                echo "\nDwCA: [$found1] - [$dwca_hc_string] - [$index_hc1]\n";
                echo "\n  DH: [$found2] - [$DH_hc_string] - [$index_hc2]\n";
                echo "\n------------END may na huli-----------\n"; //exit;
                */
                return $rek;
            }
        }
    }
    private function search_hc_string_from_AncestryIndex($hc_str)
    {
        // echo "\nneedle: [$hc_str]\n"; 
        foreach($this->ancestry_index as $index_hc => $indexes) {            
            if(self::is_ending_in_asterisk($index_hc)) {
                // /* strict implementation
                $len = strlen($index_hc) - 1;
                if(substr($hc_str,0,$len) == self::remove_last_char($index_hc)) {
                    // echo "\n-----------------start\n";
                    // echo "\n".substr($hc_str,0,$len);
                    // echo "\n".self::remove_last_char($index_hc);
                    // echo "\n-----------------end\n";
                    // echo("\nactually goes here\n"); 
                    return array($indexes[0], $index_hc);
                }
                // */
            }
            else {
                if($index_hc == $hc_str) return array($indexes[0], $index_hc);
            }
        }
        return false;
    }
    private function is_ending_in_asterisk($str)
    {
        $last_char = substr($str, -1);
        if($last_char == "*") return true;
        return false;
    }
    private function prepare_hc_string($pipe_delimited) //makes "Fungi|Ascomycota" to "Fungi|Ascomycota|"
    {
        $arr = explode("|", $pipe_delimited);
        $arr = self::normalize_array($arr);
        return implode("|", $arr)."|";
    }
    private function more_strict_matching_byEli($reks, $hc)
    { //loop to all reks and check each higherClassification. If ALL scinames from DwCA hc is found in DH hc then that rek is returned.
        $hc = explode("|", $hc);
        $hc = self::normalize_array($hc); //print_r($hc); exit("\ncha 01\n");
        /*Array(
            [0] => Fungi
            [1] => Basidiomycota
        )*/
        $hits = array();
        foreach($reks as $id => $rek) {
            if($DH_hc = $rek['h']) {
                $DH_hc = explode("|", $DH_hc);
                $DH_hc = self::normalize_array($DH_hc); // print_r($DH_hc); exit("\ncha 02\n");
                /*Array(
                    [0] => Life
                    [1] => Cellular Organisms
                    [2] => Eukaryota
                    [3] => Opisthokonta
                    [4] => Nucletmycea
                    [5] => Fungi
                    [6] => Dikarya
                    [7] => Basidiomycota
                    [8] => Agaricomycetes
                )*/
                foreach($hc as $sciname) {
                    if(!in_array($sciname, $DH_hc)) return false;
                }
                if(($this->rec['http://rs.tdwg.org/dwc/terms/taxonRank'] == $rek['r']) && $rek['r']) $hits[] = $rek;
            }
        }
        if(count($hits) > 1) {
            echo "\n--------------------------------- investigate here...\n";
            print_r($this->rec); print_r($hc); print_r($reks); print_r($hits); exit("\n\nSo this is possible hmmm.\n");
        }
        if($hits) return $hits[0];
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

        /* for reference only
        $this->DH->DH_synonyms[$taxonID] = $acceptedNameUsageID;
        $this->DH->DH_acceptedNames[$acceptedNameUsageID] = $taxonID;
        */
        if($accepted_id = @$this->DH->DH_synonyms[$taxonID]) {
            /* for reference only            
            $this->DH->DH[$taxonID] = array("c" => $canonicalName); */
            if($rec = $this->DH->DH[$accepted_id]) {
                if($rec['c'] == $DH_canonical && in_array($rec['r'], $choices)) return true;
            }
        }
        // SYN-000000207590	EOL-000000462763		Cassia pendula E.Agbayani	Senna pendula	E.Agbayani	variety	not accepted	COL-15	COL:a423c550b4fd0b0feefa2477637935ff	http://www.catalogueoflife.org/annual-checklist/2019/details/species/id/19f057e06cfc7dbd915c90b6bb2e5f70/synonym/a423c550b4fd0b0feefa2477637935ff			
        if($SYN_ids = @$this->DH->DH_acceptedNames[$taxonID]) { //exit("\nhere 01\n");
            /* reference only            
            $this->DH->DH[$taxonID] = array("c" => $canonicalName); */
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
    {   /* [EOL-000000090932] => Array(
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
        $taxonRank = @$rec['http://rs.tdwg.org/dwc/terms/taxonRank'];

        // if reks is just 1 record then no choice use it
        if(count($reks) == 1) {
            foreach($reks as $DH_taxonIDx => $rek) {
                if($rek['e']) {
                    @$this->debug['matched just 1 record']++;
                    return $rek;
                }
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
    private function write_2archive($rec)
    {
        $o = new \eol_schema\Taxon();
        $uris = array_keys($rec);
        foreach ($uris as $uri) {
            $field = self::get_field_from_uri($uri);
            $o->$field = $rec[$uri];
        }
        $this->archive_builder->write_object_to_file($o);
    }
    private function retrieve_ancestry_index()
    {
        if($local = Functions::save_remote_file_to_local($this->ancestry_index_file, $this->download_options)) {
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
                    // print_r($rec); exit;
                    /*Array(
                        [Index] => Actinopterygii
                        [higherClassification] => Animalia|Chordata|Actinopterygii|*
                    )*/
                    if($rec['Index'] && $rec['higherClassification']) {
                        $ret[$rec['higherClassification']][] = $rec['Index'];
                    }
                }
            }
        }      
        unlink($local); // exit("\n".count($ret)."\n");
        return $ret;
    }
    private function normalize_array($arr)
    {
        $arr = array_filter($arr); //remove null arrays
        $arr = array_unique($arr); //make unique
        $arr = array_values($arr); //reindex key
        return $arr;
    }
    private function remove_last_char($str)
    {
        return substr($str, 0, -1);
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