<?php
namespace php_active_record;

require_once __DIR__ . '/../../vendor/google_client_lib_2023/autoload.php';

/* sample connector: [google_client.php] */

use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class GoogleClientAPI
{
    function __construct()
    {
        if(Functions::is_production()) $this->cache_path = '/extra/other_files/wikidata_cache/';
        else                           $this->cache_path = '/Volumes/Crucial_2TB/wikidata_cache/';
        if(!is_dir($this->cache_path)) mkdir($this->cache_path);

        $this->credentials_json_path = __DIR__ . '/../../vendor/google_client_lib_2023/json/credentials.json';
    }
    private function evaluate_expire_param($params, $use_cache_YN)
    {
        if(!isset($params['expire_seconds'])) return $use_cache_YN;
        else {
            $options['expire_seconds'] = $params['expire_seconds'];
            $some_id = md5($params['spreadsheetID'].$params['range']);
            if(Functions::expire_YN($some_id, $options)) return false; //means redo, cache expired
            else return true; //means use cache
        }
    }
    function access_google_sheet($params, $use_cache_YN = true)
    {   /* IMPORTANT:
        if 1st param has $params['expire_seconds'], will follow value accordingly
        else
            2nd param if blank, will use cache
            2nd param if true, will use cache
            2nd param if false, will re-access remote
        */

        // /* new: add expire to caching
        $use_cache_YN = self::evaluate_expire_param($params, $use_cache_YN);
        // if($use_cache_YN) echo "\nwill use cache\n";
        // else              echo "\nwill not use cache\n";
        // */

        // /*
        require_library('connectors/CacheMngtAPI');
        $this->func = new CacheMngtAPI($this->cache_path);
        // */

        // /* New solution:
        $md5_id = md5(json_encode($params));
        if($use_cache_YN) {
            if($records = $this->func->retrieve_json_obj($md5_id, false)) echo " -> CACHE EXISTS."; //2nd param false means returned value is an array()
            else {
                echo " -> NO CACHE YET";
                $records = self::do_the_google_thing($params);
                $json = json_encode($records);
                $this->func->save_json($md5_id, $json);
            }
        }
        else {
            echo "\nCACHE FORCE-EXPIRE\n";
            $records = self::do_the_google_thing($params);
            $json = json_encode($records);
            $this->func->save_json($md5_id, $json);
        }
        // */
        return $records;   
    }
    private function do_the_google_thing($params)
    {
        //Reading data from spreadsheet.
        $client = new \Google_Client();
        $client->setApplicationName('Google Sheets and PHP');
        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
        $client->setAccessType('offline');
        $client->setAuthConfig($this->credentials_json_path);
        $service = new \Google_Service_Sheets($client);

        /*
        $spreadsheetId = "129IRvjoFLUs8kVzjdchT_ImlCGGXIdVKYkKwIv7ld0U"; //It is present in your URL
        $get_range = "measurementTypes!A1:B9";
        Note:  Sheet name is found in the bottom of your sheet and range can be an example
        "A2: B10" or “A2: C50" or “B1: B10" etc.
        */

        //Request to get data from spreadsheet.
        $response = $service->spreadsheets_values->get($params['spreadsheetID'], $params['range']);
        $values = $response->getValues();
        return $values;
    }
}
?>