<?php
namespace php_active_record;
/* this is a utility to test Pensoft annotation */
include_once(dirname(__FILE__) . "/../../config/environment.php");
$GLOBALS['ENV_DEBUG'] = false; //true;
$timestart = time_elapsed();

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


// /* option 3 from AntWebAPI.php --- worked OK!
// /* This is used for accessing EOL annotator to get ENVO URI given habitat string.
$descs = array();
$descs[] = "ser.001. Gadus morhua & an 3 a < > ; ,   is  a montane species, x occurring through most alpine birch forest  and  of & an 3 a < > ; , the Atlantic"; //with & < >
$descs[] = "ser.001: materials_examined	mountain shrubland & Holotype. AMS I. 19426 - 001, 414 mm, female, off Maxlabar "; //regular capture
$descs[] = "ser.001: materials_examined é	mountain shrublandé Isaiah "; //with é exclude
$descs[] = "ser.001: materials_examined é	mountain shrubland é Holotype. AM I. 19426 - 001, 414 mm, female, off Maxlabar "; //with é include
$descs[] = "ser.001: materials_examined'	'mountain shrubland Holotype. ' AMS I. 19426 - 001,'	' 414 mm, female, off Maxlabar "; //regular capture with ' single quote
$descs[] = "ser.001 a Gadus', is a  , . ; testing...  < > procumbent 'species' ";
$descs[] = 'ser.001 conceals an approximately "55" mm 0.20 inlong, black water river hard spine or "spur" composed of dermal papillae. ';
/* start of the 7 predicates group */ 
$descs[] = "ser.001 {\displaystyle {\ce {2CO2 + H2S + 2H2O -> 2CH2O + H2SO4}}} Many species utilize thiosulfate nocturnal (S2O32-) {\displaystyle {\ce  \d "; //with backslash \ {behavioral circadian rhythm} {http://www.wikidata.org/entity/Q309179}
$descs[] = "ser.001 test ‛ ＂ c ´ ‟ ito:' /ˈliːtʃiː/ precocial test ´ ito:' /ˈliːtʃi.‟ ː/ k ‛ j＂kj "; //{developmental mode} {http://eol.org/schema/terms/precocial}
$descs[] = "ser.001 Gadus morhua is in fast-flowing stream of the great outdoors."; //{habitat} {http://purl.obolibrary.org/obo/ENVO_01000253}
$descs[] = "ser.001 Gadus morhua is biennial of the great outdoors."; //{life cycle habit} {http://purl.obolibrary.org/obo/TO_0002725}
$descs[] = "ser.001 Gadus morhua is polyandrous of the great outdoors."; //{mating system} {http://purl.obolibrary.org/obo/ECOCORE_00000064}
$descs[] = "ser.001 Gadus morhua is oviparous of the great outdoors."; //{reproduction} {http://www.marinespecies.org/traits/Oviparous}
$descs[] = "ser.001 Gadus morhua is dioecious of the great outdoors."; //{sexual system} {https://www.wikidata.org/entity/Q148681}
// word boundaries
$descs[] = "ser.001 alpine forestry ; alpine forest0 ";
$descs[] = "ser.001 &alpine forest; ";
$descs[] = "ser.001 Zalpine forest ; 6alpine forest ";
$descs[] = "ser.001 tropical ; subtropical , subalpine forest ";
$descs[] = "ser.001 subalpine forest; ";
$descs[] = 'ser.001 Retrieved from "<a dir="ltr" href="https://en.wikipedia.org/w/index.php?title=Lesser_prairie-chicken&oldid=1273157853">https://en.wikipedia.org/w/index.php?title=Lesser_prairie-chicken&oldid=1273157853</a>"</div></div> </div> </main> </div> <div class="';
$descs[] = 'ser.001 Retrieved from "<a dir="ltr" href="https://en.wikipedia.org/w/index.php?title=Asian_swamp_eel&oldid=1271391020">https://en.wikipedia.org/w/index.php?title=Asian_swamp_eel&oldid=1271391020</a>"</div></div> </div> </main> </div> <div class="';
$descs[] = 'ser.001 Retrieved from "<a dir="ltr" href="https://en.wikipedia.org/w/index.php?title=Black_pond_turtle&oldid=1246771832">A forest https://en.wikipedia.org/w/index.php?title=Black_pond_turtle&oldid=1246771832</a>"</div></div> </div> </main> </div> <div class="';
$descs[] = "ser.001 do not usually enter brackish water and mostly montane .[12][13] The favored temperature";

/* un-comment this block to test 1 record
$descs = array();
// $descs[] = file_get_contents(DOC_ROOT."/tmp2/sample_treatment.txt");
$time = date('Y-m-d H:i:s', time());
$descs[] = "[$time] do not usually enter brackish water and mostly montane .[12][13] The favored temperature";
*/

/*
&amp; becomes & (ampersand)
&quot; becomes " (double quote)
&#039; becomes ' (single quote)
&lt; becomes < (less than)
&gt; becomes > (greater than)
*/

$final = array();
$IDs = array('24', '617_ENV', 'TreatmentBank_ENV', '26_ENV'); //normal operation --- 617_ENV -> Wikipedia EN //24 -> AntWeb resource ID
// $IDs = array('24');                                      //dev only
// $IDs = array('TreatmentBank_ENV'); //or TreatmentBank    //dev only
$IDs = array('617_ENV'); //or Wikipedia EN                  //dev only
// $IDs = array('26_ENV');                                  //dev only


$param = array("task" => "generate_eol_tags_pensoft", "resource" => "all_BHL", "subjects" => "Uses", 
    // "ontologies" => "behavioral circadian rhythm, developmental mode, habitat, life cycle habit, mating system, reproduction, sexual system, xyz"
    "ontologies" => "ALL"
    // "ontologies" => "habitat, mating system, life cycle habit" //with 6 errors
    // "ontologies" => "mating system"
    );
foreach($IDs as $resource_id) {
    $param['resource_id'] = $resource_id;
    require_library('connectors/Functions_Annotator');
    require_library('connectors/Annotator2EOLAPI');
    $pensoft = new Annotator2EOLAPI($param);
    $pensoft->initialize_remaps_deletions_adjustments(); //copied template
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
            $q[11] = array('s' => "biennial-Q189774->TO_0002725");
            $q[12] = array('s' => "polyandrous-ECOCORE_00000064->MatingSystem");
            $q[13] = array('s' => "oviparous-Oviparous->GO_0000003");
            $q[14] = array('s' => "dioecious-Q148681->SexualSystem");
            // word boundary
            $q[15] = array('s' => "");
            $q[16] = array('s' => "alpine forest-ENVO_01000340->RO_0002303|alpine forest-ENVO_01000435->RO_0002303|forest-ENVO_01000174->RO_0002303");
            $q[17] = array('s' => "forest-ENVO_01000174->RO_0002303");
            $q[18] = array('s' => "tropical-ENVO_01000204->RO_0002303|forest-ENVO_01000174->RO_0002303|subalpine forest-ENVO_01000435->RO_0002303|subtropical-ENVO_01000205->RO_0002303");
            $q[19] = array('s' => "forest-ENVO_01000174->RO_0002303|subalpine forest-ENVO_01000435->RO_0002303");
            // URL source
            $q[20] = array('s' => "");
            $q[21] = array('s' => "");
            $q[22] = array('s' => "forest-ENVO_01000174->RO_0002303");
            // remnants of old mapping
            $q[23] = array('s' => "brackish water-ENVO_00002019->RO_0002303|mostly montane-Q1141462->RO_0002303");


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
        // word boundaries
        if($i == 15) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 16) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 17) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 18) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 19) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        // URL source
        if($i == 20) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 21) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 22) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        // remnants of old mapping
        if($i == 23) {$s = $q[$i]['s'];  if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }

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