<?php
namespace php_active_record;
/* task: Revision for textmining keyword mappings #36
https://github.com/EOL/ContentImport/issues/36
*/
class TextmineKeywordMapAPI
{
    public $params; // Declare the property
    public $func;
    public $uri_in_question;
    public $new_keywords_string_uri, $uris_with_new_kwords;
    function __construct()
    {   /* Please remove all keywords that currently map to these uris: */
        $this->uris_with_new_kwords = array("http://purl.obolibrary.org/obo/ENVO_00000081", "http://purl.obolibrary.org/obo/ENVO_01000342", "http://purl.obolibrary.org/obo/ENVO_01000340", "http://purl.obolibrary.org/obo/ENVO_00000080", "http://purl.obolibrary.org/obo/ENVO_00000381", "http://purl.obolibrary.org/obo/ENVO_01000333", "http://purl.obolibrary.org/obo/ENVO_00000497", "http://purl.obolibrary.org/obo/ENVO_00000287", "http://purl.obolibrary.org/obo/ENVO_01000253", "http://purl.obolibrary.org/obo/ENVO_01000252", "http://purl.obolibrary.org/obo/ENVO_01000687", "http://purl.obolibrary.org/obo/ENVO_00000100", "http://purl.obolibrary.org/obo/ENVO_01000143", "http://purl.obolibrary.org/obo/ENVO_00000091", "http://purl.obolibrary.org/obo/ENVO_00000475");
        /* Then add the strings that are mapped to terms on the following worksheets:
        - strings related to river/stream [river]
            https://docs.google.com/spreadsheets/d/1G3vCRvoJsYijqvJXwOooKdGj-jEiBop2EOl3lUxEny0/edit?gid=653002583#gid=653002583
        - strings related to lake [lake]
            https://docs.google.com/spreadsheets/d/1G3vCRvoJsYijqvJXwOooKdGj-jEiBop2EOl3lUxEny0/edit?gid=974642361#gid=974642361
        - strings related to mountain [mountain etc.]
            https://docs.google.com/spreadsheets/d/1G3vCRvoJsYijqvJXwOooKdGj-jEiBop2EOl3lUxEny0/edit?gid=1677871921#gid=1677871921
        - strings related to coastal [coastal]
            https://docs.google.com/spreadsheets/d/1G3vCRvoJsYijqvJXwOooKdGj-jEiBop2EOl3lUxEny0/edit?gid=1687183186#gid=1687183186 */
        $params['spreadsheetID'] = '1G3vCRvoJsYijqvJXwOooKdGj-jEiBop2EOl3lUxEny0';
        $params['expire_seconds'] = 60*60*24*1; //1 day cache is ideal OK

        $params['range'] = 'river!A1:D430'; //where "A" is the starting column, "D" is the ending column, and "1" is the starting row.
        $this->params['river'] = $params;

        $params['range'] = 'lake!A1:D30';
        $this->params['lake'] = $params;

        $params['range'] = 'mountain etc.!A1:C340';
        $this->params['mountain'] = $params;

        $params['range'] = 'coastal!A1:D100';
        $this->params['coastal'] = $params;

        require_library('connectors/GoogleClientAPI');
        $this->func = new GoogleClientAPI();
    }
    function get_keyword_mappings($item = false)
    {
        if(!$item) $items = array('river', 'lake', 'mountain', 'coastal');
        else       $items = array($item);
        foreach($items as $what) {
            $params = $this->params[$what]; //$what e.g. 'coastal'
            $arr = $this->func->access_google_sheet($params);
            self::massage_result($arr);
        }
        // print_r($this->uri_in_question); print_r($this->new_keywords_string_uri);
        echo "\nuri_in_question 1: ".count($this->uri_in_question);
        echo "\nnew_keywords_string_uri 1: ".count($this->new_keywords_string_uri);
    }
    private function massage_result($arr)
    {
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
                $rek = array_map('trim', $rek); // print_r($rek); exit("\nEli 100\n");
                /*Array(
                    [match string] => at forest stream
                    [value] => riparian zone
                    [uri] => https://www.wikidata.org/entity/Q13360049
                    [notes] => Other resources
                    [new_uid] => NEW_1
                )*/
                $uri = @$rek['uri'];
                $match_string = @$rek['match string'];
                if($uri && $match_string) {
                    $this->uri_in_question[$uri] = '';
                    $this->new_keywords_string_uri[$match_string] = $uri; 
                }
            }
        }
        // end massage array
    }
    function xxx($params) 
    {   
        require_library('connectors/GoogleClientAPI');
        $func = new GoogleClientAPI(); //get_declared_classes(); will give you how to access all available classes
        $params = $this->params['coastal'];
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