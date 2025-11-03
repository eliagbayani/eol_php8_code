<?php
namespace php_active_record;
/* this is a utility to test Pensoft annotation */
include_once(dirname(__FILE__) . "/../../config/environment.php");
$GLOBALS['ENV_DEBUG'] = true;
$timestart = time_elapsed();

require_library('connectors/Functions_Pensoft');
require_library('connectors/Annotator2EOLAPI');

$param = array("task" => "generate_eol_tags_pensoft", "resource" => "all_BHL", "resource_id" => "TreatmentBank", "subjects" => "Uses", "ontologies" => "envo");

$func = new Annotator2EOLAPI($param);

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
$descs[] = "b4. Gadus morhua & an 3 a < > ; ,   is  a montane species, x occurring through most alpine birch forest  and  of the Atlantic"; //with & < >
$descs[] = "b4: materials_examined	mountain shrubland & Holotype. AMS I. 19426 - 001, 414 mm, female, off Maxlabar "; //regular capture
$descs[] = "b4: materials_examined	mountain shrublandé Isaiah "; //with é exclude
$descs[] = "b4: materials_examined	mountain shrubland é Holotype. AMS I. 19426 - 001, 414 mm, female, off Maxlabar "; //with é include
$descs[] = "b4: materials_examined	'mountain shrubland Holotype. ' AMS I. 19426 - 001, 414 mm, female, off Maxlabar "; //regular capture with ' single quote

// $descs[] = "a2 a Gadus, is a  , . ; testing...  < > procumbent species";

/* un-comment this block to test 1 record
$descs = array();
// $descs[] = file_get_contents(DOC_ROOT."/tmp2/sample_treatment.txt");
// $descs[] = "I went to 'Malabar Coast (India)'.";
// $descs[] = "I live in India, near the Malabar coast.";
// $descs[] = "He lives in the coast";
// $descs[] = "I went to Malabar in India";
// $descs[] = "Malabar (New South Wales, Australia)";
// $descs[] = "Malabar (Florida, USA)";
$descs[] = "12. Gadus & morhua' < >  an 3, a procumbent, - is ' a montane species, occurring through; most alpine birch forest ' and along the Red Sea coast of the Atlantic";
$descs[] = "Typex x19: materials_examined	mountain shrublandé Holotype. AMS I. 19426 - 001, 414 mm, female, off Maxlabar ";
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

foreach($IDs as $resource_id) {
    $param['resource_id'] = $resource_id;
    require_library('connectors/Functions_Pensoft');
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
            $q[1] = array('s' => "alpine birch forest-ENVO_01000340|alpine birch forest-ENVO_01000435|along the Red Sea coast-ENVO_01000687|procumbent-PATO_0002389");
            $q[2] = array('s' => "mountain shrubland-ENVO_01000216");
            $q[3] = array('s' => "");
            $q[4] = array('s' => "mountain shrubland-ENVO_01000216");
            $q[5] = array('s' => "mountain shrubland-ENVO_01000216");

            // $q[3] = array('s' => "procumbent-PATO_0002389");

            // if($arr = @$q[$i]) {
            //     if($ret == $arr['s']) echo " -OK-"; else { echo " -ERROR- [$arr[s]]"; $errors++; }
            // }
        }
        if($resource_id == '617_ENV') {
            // $q = array();
            // if($arr = @$q[$i]) {
            //     if($ret == $arr['s']) echo " -OK-"; else { echo " -ERROR- [$arr[s]]"; $errors++; }
            // }
        }
        if($resource_id == 'TreatmentBank_ENV') {
            // $q = array();
            // if($arr = @$q[$i]) {
            //     if($ret == $arr['s']) echo " -OK-"; else { echo " -ERROR- [$arr[s]]"; $errors++; }
            // }
        }
        /* accross all resources */
        if($i == 1) {$s = "alpine birch forest-ENVO_01000340|alpine birch forest-ENVO_01000435"; if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 2) {$s = "mountain shrubland-ENVO_01000216";       if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 3) {$s = "";                                       if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 4) {$s = "mountain shrubland-ENVO_01000216";       if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }
        if($i == 5) {$s = "mountain shrubland-ENVO_01000216";       if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }

        // if($i == 4) {$s = "procumbent-PATO_0002389";                if($ret == $s) echo " -OK-"; else {echo " -ERROR- [$s]"; $errors++;} }

    }
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
        // echo "\n---start---\n";
        // print_r($arr); //--- search ***** in Annotator2EOLAPI.php
        // echo "\n---end---\n";
        foreach($arr as $uri => $rek) {
            $filename = pathinfo($uri, PATHINFO_FILENAME);
            $tmp = $rek['lbl']."-$filename";
            if($mtype = @$rek['mtype']) $tmp .= "-".pathinfo($mtype, PATHINFO_FILENAME);
            $final[] = $tmp;
        }
    }
    else echo "\n-No Results-\n";
    return implode("|", $final);    
}
/*
@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@
Hi Jen,
Regarding this:
https://eol-jira.bibalex.org/browse/DATA-1896?focusedCommentId=67731&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-67731

1. The annotator correctly picks-up "planktonic material" and correctly assigns the URI "http://purl.obolibrary.org/obo/ENVO_01000063". And MoF captures this correctly.
The weird part is in our EOL Terms file, this URI is assigned to the name "marine upwelling". Please advise what adjustment to do.
2. This doesn't exist anymore in our DwCA MoF.
3. Weird that the annotator picks-up the string "Cueva de Altamira" and assigns the URI http://purl.obolibrary.org/obo/ENVO_00000102. I had to hard-code removal.

@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@
Hi Jen,
Regarding this one:
https://eol-jira.bibalex.org/browse/DATA-1896?focusedCommentId=67732&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-67732

forest, woodland: annotator picks it up correctly, and assigns the ontology to "eol-geonames".
http://purl.obolibrary.org/obo/ENVO_01000174
http://purl.obolibrary.org/obo/ENVO_01000175
With URIs respectively.
But we have a rule here that removes any terms from the geographic ontology that include the string /ENVO_
https://eol-jira.bibalex.org/browse/DATA-1877?focusedCommentId=65861&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-65861

grassland: has both ontologies (envo and eol-geonames).
http://purl.obolibrary.org/obo/ENVO_01001206
http://purl.obolibrary.org/obo/ENVO_01000177
URIs respectively. That's why also removed.

savanna: has both ontologies (envo and eol-geonames).
http://purl.obolibrary.org/obo/ENVO_00000261
http://purl.obolibrary.org/obo/ENVO_01000178
URIs respectively. That's why also removed.

rainforests, littoral, abyssal, bog: annotator didn't pick it up.

fen: we are getting it correctly in MoF
e.g. http://purl.obolibrary.org/obo/RO_0002303	http://purl.obolibrary.org/obo/ENVO_00000232	source text: "fen"	http://treatment.plazi.org/id/03DC9141FF89F95C520D57A5EC7AF82D
@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@
*/
?>