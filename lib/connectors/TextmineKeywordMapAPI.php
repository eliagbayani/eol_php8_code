<?php
namespace php_active_record;
/* task: Revision for textmining keyword mappings #36
https://github.com/EOL/ContentImport/issues/36
*/
class TextmineKeywordMapAPI
{
    function __construct()
    {
    }
    function xxx($params) 
    {   //https://docs.google.com/spreadsheets/d/1G3vCRvoJsYijqvJXwOooKdGj-jEiBop2EOl3lUxEny0/edit?gid=653002583#gid=653002583
        require_library('connectors/GoogleClientAPI');
        $func = new GoogleClientAPI(); //get_declared_classes(); will give you how to access all available classes
        $arr = $func->access_google_sheet($params); 
        /* IMPORTANT:
        if 1st param has $params['expire_seconds'], will follow value accordingly
        else
            2nd param if blank, will use cache
            2nd param if true, will use cache
            2nd param if false, will re-access remote
        */

        //start massage array
        $i = 0;
        foreach($arr as $item) { $i++;
            if($i == 1) $fields = $item;
            else {
                $rek = array(); $k = 0;
                foreach($fields as $fld) {
                    if($fld) $rek[$fld] = @$item[$k];
                    $k++;
                }
                @$ctr++;
                $rek['new_uid'] = "NEW_".$ctr;
                $rek = array_map('trim', $rek);
                print_r($rek); exit;
            }
        }
        // end massage array


        /* copied template
        $source = "/Volumes/AKiTiO4/d_w_h/last_smasher/test/taxonomy.tsv"; //results_2021_02_24.zip
        $destination = "/Volumes/AKiTiO4/d_w_h/last_smasher/test/final_taxonomy.tsv";
        $WRITE = Functions::file_open($destination, "w");
        $i = 0;
        foreach(new FileIterator($source) as $line => $row) { $i++;
            $rek = array_map('trim', $rek);
        }
        */
    }
}
?>