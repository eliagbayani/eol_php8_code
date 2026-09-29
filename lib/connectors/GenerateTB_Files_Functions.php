<?php
namespace php_active_record;
/* */
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class GenerateTB_Files_Functions
{
    public $compatibleAncestors_file = "https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/neo4j_tasks/AncestryIndex_compatibleAncestors.tsv";

    function __construct() {}
    function write_Traits_input_file($taxon_main, $source_taxonID)
    {
        print_r($taxon_main); //exit("\n[$source_taxonID]\nsample chain x\n");

        $taxon_info = $taxon_main['taxon'];
        $occur_info = $taxon_main['occurrences']; // print_r($occur_info);

        /*Array(
            [taxonID] => 47138010
            [scientificName] => Plumeria rubra L
            [higherClassification] => Plumeria|
            [genus] => Plumeria
            [taxonRank] => species
            [taxonRemarks] => Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Plumeria\|.*?] ] || source_taxonID: [d6b158fbfeaa7914ce528b3c4df341a7]
            [canonicalName] => Plumeria rubra
            [EOLid] => 47138010
        )*/
        $taxonKey = $taxon_info['taxonID'];

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
            $occurrenceID = $m['occurrenceID'];
            $occur = $occur_info[$occurrenceID]; //print_r($occur); exit("\nelix 3\n");

            $save = array();
            $save['measurementID'] = $m['measurementID'];
            $save['occurrenceID'] = $occurrenceID; //optional
            $save['taxonID'] = $source_taxonID;
            $save['taxonKey'] = $taxonKey;
            $save['scientificName'] = $taxon_info['scientificName'];
            // tb:infer if true, paint the branch: taxa descending from the tbTaxonMapping should inherit this trait
            // tb:exclude stop branchpainting: descendant taxa should not inherit this trait            
            $save['measurementType'] = $m['measurementType'];
            $save['measurementValue'] = $m['measurementValue'];
            $save['measurementRemarks'] = $m['measurementRemarks'];
            $save['measurementUnit'] = @$m['measurementUnit'];
            $save['lifeStage'] = @$occur['lifeStage'];
            $save['sex'] = @$occur['sex'];
            $save['statisticalMethod'] = @$m['statisticalMethod'];
            $save['source'] = @$m['source'];
            $save['referenceID'] = @$m['referenceID'];
            print_r($save);
            exit("\n--stop--\n");
        }

    }
    function get_source_taxonID($taxonRemarks)
    {   //Trait: [ IndexGroup:[Angiosperms] - IndexHC:[.*?\|Asclepias\|.*?] ] || source_taxonID: [bb345e46c7900f99efefd82ecf42a8fd]
        if(preg_match("/source_taxonID\: \[(.*?)\]/ims", $taxonRemarks, $a)) return $a[1];
    }
}
?>