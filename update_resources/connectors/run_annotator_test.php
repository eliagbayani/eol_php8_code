<?php
namespace php_active_record;
/* this is a utility to test Pensoft annotation */
include_once(dirname(__FILE__) . "/../../config/environment.php");
$GLOBALS['ENV_DEBUG'] = false; //true;
$timestart = time_elapsed();

/* was never used
require_library('connectors/Functions_Annotator');
require_library('connectors/Annotator2EOLAPI');
$param = array("task" => "generate_eol_tags_pensoft", "resource" => "all_BHL", "resource_id" => "TreatmentBank", "subjects" => "Uses", 
    "ontologies" => "behavioral circadian rhythm, eol-geonames, developmental mode, habitat, life cycle habit, mating system, reproduction, sexual system");
$func = new Annotator2EOLAPI($param);
*/

// $str = "C. alpina a procumbent, is a montane species, occurring through most alpine birch forest and elsewhere";
// echo("\n".$str."\n");
// $str = urlencode($str);
// echo("\n".$str."\n");
// $str = urldecode($str);
// exit("\n".$str."\n");


/* initial test: should pass this before it proceeds.
if(!$func->Pensoft_is_up()) exit("\nTest failed. Needed service is not available\n");
else echo "\nTest passed OK\n";
*/

/* independent test: Nov 27, 2023 --- separate sections of Treatment text
$str = file_get_contents(DOC_ROOT."/tmp2/sample_treatment.txt");
$ret = $func->format_TreatmentBank_desc($str);
echo "\n[$ret]\n";
exit("\n--end test--\n");
*/

/* option 1 works, but it skips a lot of steps that is needed in real-world connector run.
$json = $func->run_partial($desc);
$arr = json_decode($json); print_r($arr);
*/

/* option 2 --- didn't get to work
$basename = "ile_-_173"."ice";
$desc = strip_tags($desc);
$desc = trim(Functions::remove_whitespace($desc));
// $func->results = array();
$arr = $func->retrieve_annotation($basename, $desc); //it is in this routine where the pensoft annotator is called/run
// $arr = json_decode($json); 
print_r($arr);
*/

/*
$sciname = "Gadur morhuaspp.";
if(Functions::valid_sciname_for_traits($sciname)) exit("\n[$sciname] valid\n");
else                                              exit("\n[$sciname] invalid\n");
*/

// /* option 3 from AntWebAPI.php --- worked OK!
// /* This is used for accessing Pensoft annotator to get ENVO URI given habitat string.
$descs = array();
$descs[] = "b94. Gadus morhua & an 3 a < > ; ,   is  a montane species, x occurring through most alpine birch forest  and  of & an 3 a < > ; , the Atlantic"; //with & < >
$descs[] = "b94: materials_examined	mountain shrubland & Holotype. AMS I. 19426 - 001, 414 mm, female, off Maxlabar "; //regular capture
$descs[] = "b94: materials_examined é	mountain shrublandé Isaiah "; //with é exclude
$descs[] = "b94: materials_examined é	mountain shrubland é Holotype. AM I. 19426 - 001, 414 mm, female, off Maxlabar "; //with é include
$descs[] = "b94: materials_examined'	'mountain shrubland Holotype. ' AMS I. 19426 - 001,'	' 414 mm, female, off Maxlabar "; //regular capture with ' single quote
$descs[] = "b94 a Gadus', is a  , . ; testing...  < > procumbent 'species' ";
$descs[] = 'b94 conceals an approximately "55" mm 0.20 inlong, black water river hard spine or "spur" composed of dermal papillae. ';
/* start of the 7 predicates group */ 
$descs[] = "b94 {\displaystyle {\ce {2CO2 + H2S + 2H2O -> 2CH2O + H2SO4}}} Many species utilize thiosulfate nocturnal (S2O32-) {\displaystyle {\ce  \d "; //with backslash \ {behavioral circadian rhythm} {http://www.wikidata.org/entity/Q309179}
$descs[] = "b94 test ‛ ＂ c ´ ‟ ito:' /ˈliːtʃiː/ precocial test ´ ito:' /ˈliːtʃi.‟ ː/ k ‛ j＂kj "; //{developmental mode} {http://eol.org/schema/terms/precocial}
$descs[] = "Gadus morhua is in fast-flowing stream of the great outdoors."; //{habitat} {http://purl.obolibrary.org/obo/ENVO_01000253}
$descs[] = "Gadus morhua is biennial of the great outdoors."; //{life cycle habit} {http://purl.obolibrary.org/obo/TO_0002725}
$descs[] = "Gadus morhua is polyandrous of the great outdoors."; //{mating system} {http://purl.obolibrary.org/obo/ECOCORE_00000064}
$descs[] = "Gadus morhua is oviparous of the great outdoors."; //{reproduction} {http://www.marinespecies.org/traits/Oviparous}
$descs[] = "Gadus morhua is dioecious of the great outdoors."; //{sexual system} {https://www.wikidata.org/entity/Q148681}


/* un-comment this block to test 1 record
$descs = array();
// $descs[] = file_get_contents(DOC_ROOT."/tmp2/sample_treatment.txt");
$descs[] = "b94. Gadus morhua & an 3 a < > ; ,   is  a montane species, x occurring through most alpine birch forest  and  of & an 3 a < > ; , the Atlantic"; //with & < >
*/

/*
&amp; becomes & (ampersand)
&quot; becomes " (double quote)
&#039; becomes ' (single quote)
&lt; becomes < (less than)
&gt; becomes > (greater than)
*/


// Good idea. I think it's best if we use WikiData uris:
//     Malabar Coast (India) https://www.wikidata.org/entity/Q473181
//     Malabar (New South Wales, Australia) https://www.wikidata.org/entity/Q2915709
//     Malabar (Florida, USA) https://www.wikidata.org/wiki/Q1022772


$final = array();
$IDs = array('24', '617_ENV', 'TreatmentBank_ENV', '26_ENV'); //normal operation --- 617_ENV -> Wikipedia EN //24 -> AntWeb resource ID
// $IDs = array('24');                                      //dev only
// $IDs = array('TreatmentBank_ENV'); //or TreatmentBank    //dev only
$IDs = array('617_ENV'); //or Wikipedia EN               //dev only
// $IDs = array('26_ENV');                                  //dev only


$param = array("task" => "generate_eol_tags_pensoft", "resource" => "all_BHL", "subjects" => "Uses", 
    "ontologies" => "behavioral circadian rhythm, eol-geonames, developmental mode, habitat, life cycle habit, mating system, reproduction, sexual system");
foreach($IDs as $resource_id) {
    $param['resource_id'] = $resource_id;
    require_library('connectors/Functions_Annotator');
    require_library('connectors/Annotator2EOLAPI');
    $pensoft = new Annotator2EOLAPI($param);
    $pensoft->initialize_remaps_deletions_adjustments(); //copied template
    // /* to test if these 4 variables are populated.
    // echo "\n From Pensoft Annotator:";
    // echo("\n remapped_terms: "              .count($pensoft->remapped_terms)."");
    // echo("\n mRemarks: "                    .count($pensoft->mRemarks)."");
    // echo("\n delete_MoF_with_these_labels: ".count($pensoft->delete_MoF_with_these_labels)."");
    // echo("\n delete_MoF_with_these_uris: "  .count($pensoft->delete_MoF_with_these_uris)."\n");
    // ************************************
    $i = 0; $errors = 0;
    foreach($descs as $desc) { $i++;
        // $desc .= " ".date('Y-m-d H:i:s', time()); //dev only
        $ret = run_desc($desc, $pensoft);
        echo "\n[$resource_id $i] - "; echo("[$desc] [$ret]");
        // $i = 9; //force-assign
        if($resource_id == '24') {
            /* specific to this resource
            if($i == 1) {$s = "procumbent-PATO_0002389"; if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
            */
        }

        if(in_array($resource_id, array('TreatmentBank_ENV', '617_ENV'))) {
            $q = array();
            $q[1] = array('s' => "montane species-Q1141462->RO_0002303|alpine birch forest-ENVO_01000340->RO_0002303|alpine birch forest-ENVO_01000435->RO_0002303|forest-ENVO_01000174->RO_0002303");
            $q[2] = array('s' => "mountain shrubland-ENVO_01000216->RO_0002303|shrubland-ENVO_01000176->RO_0002303");
            $q[3] = array('s' => "");
            $q[4] = array('s' => "mountain shrubland-ENVO_01000216->RO_0002303|shrubland-ENVO_01000176->RO_0002303");
            $q[5] = array('s' => "mountain shrubland-ENVO_01000216->RO_0002303|shrubland-ENVO_01000176->RO_0002303");
            $q[6] = array('s' => "");
            $q[7] = array('s' => "black water river-Q100649->RO_0002303"); //with double quotes
            /* Samples for the 7 predicates group
            nocturnal {behavioral circadian rhythm} {http://www.wikidata.org/entity/Q309179}
            precocial {developmental mode} {http://eol.org/schema/terms/precocial}
            in fast-flowing stream {habitat} {http://purl.obolibrary.org/obo/ENVO_01000253}
            biennial {life cycle habit} {http://purl.obolibrary.org/obo/TO_0002725}
            polyandrous {mating system} {http://purl.obolibrary.org/obo/ECOCORE_00000064}
            oviparous {reproduction} {http://www.marinespecies.org/traits/Oviparous}
            dioecious {sexual system} {https://www.wikidata.org/entity/Q148681}
            */
            $q[8] = array('s' => "nocturnal-Q309179->VT_0001502"); //with backslash \
            $q[9] = array('s' => "precocial-precocial->DevelopmentalMode");
            $q[10] = array('s' => "in fast-flowing stream-ENVO_01000253->RO_0002303");
            $q[11] = array('s' => "biennial-TO_0002725->FLOPO_0980073");
            $q[12] = array('s' => "polyandrous-ECOCORE_00000064->MatingSystem");
            $q[13] = array('s' => "oviparous-Oviparous->GO_0000003");
            $q[14] = array('s' => "dioecious-Q148681->SexualSystem");

            // if($arr = @$q[$i]) {
            //     if($ret == $arr['s']) echo " -OK-"; else { echo " -ERROR- [$arr[s]]"; $errors++; }
            // }
        }
        // if($resource_id == '617_ENV') {
            // $q = array();
            // if($arr = @$q[$i]) {
            //     if($ret == $arr['s']) echo " -OK-"; else { echo " -ERROR- [$arr[s]]"; $errors++; }
            // }
        // }
        if($resource_id == 'TreatmentBank_ENV') {
            // $q = array();
            // if($arr = @$q[$i]) {
            //     if($ret == $arr['s']) echo " -OK-"; else { echo " -ERROR- [$arr[s]]"; $errors++; }
            // }
        }
        /* accross all resources */
        if($i == 1) {$s = $q[$i]['s'];   if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 2) {$s = $q[$i]['s'];   if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 3) {$s = $q[$i]['s'];   if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 4) {$s = $q[$i]['s'];   if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 5) {$s = $q[$i]['s'];   if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 6) {$s = $q[$i]['s'];   if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 7) {$s = $q[$i]['s'];   if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        /* the 7 predicates */
        if($i == 8) {$s = $q[$i]['s'];   if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 9) {$s = $q[$i]['s'];   if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 10) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 11) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 12) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 13) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 14) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }

    } //end foreach()
    echo "\nerrors: [$resource_id][$errors errors]";
    $final[] =     "[$resource_id][$errors errors]";
    // ************************************
} //end foreach()
echo "\n"; print_r($final);
echo "\n-end tests-\n";
// */
function run_desc($desc, $pensoft) {
    $basename = md5($desc);
    $pensoft->results = array();
    $final = array();
    if($arr = $pensoft->retrieve_annotation($basename, $desc)) {
        // echo "\n---[start]---\n";
        // print_r($arr); //--- search ***** in Annotator2EOLAPI.php
        // echo "\n---[end]---\n";
        foreach($arr as $uri => $rek) {
            $filename = pathinfo($uri, PATHINFO_FILENAME);
            $tmp = $rek['lbl']."-$filename";
            if($mtype = @$rek['mtype']) $tmp .= "->".pathinfo($mtype, PATHINFO_FILENAME);
            $final[] = $tmp;
        }
    }
    // else echo "\n[-No Results-]\n";
    return implode("|", $final);    
}
?>