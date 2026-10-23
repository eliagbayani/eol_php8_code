<?php
namespace php_active_record;
/* Library that processes the Zenodo TraitBank datasets and converts it to DwCA
These ff. workspaces work together:
- generate_higherClassification_8.code-workspace
- DHConnLib_8.code-workspace
- GNParserAPI_8.code-workspace
- DwCA_MatchTaxa2DH.code-workspace
- UseEOLidInTaxon.code-workspace
- GenerateCSV_4EOLNeo4j.code-workspace (replaced by below)
- GenerateCSV_NewModel-workspace (new)
- GenerateTB_FilesAPI.code-workspace

----------------------------------------------------
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class ReferenceMaintenanceAPI extends GenerateTB_Files_Functions
{
    function __construct($param) {
        $this->param = $param;
        $this->download_options = array('resource_id' => 'neo4j_tb', 'cache' => 1, 'download_wait_time' => 1000000, 'expire_seconds' => 60*60*24*1, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 1);
        $this->debug = array();
        // $dir = DOC_ROOT . $GLOBALS['MAIN_CACHE_PATH'] . 'zenodo/';
        // if(!is_dir($dir)) mkdir($dir);
    }
    private function initialize($concept_id)
    {}
    private function get_all_UndefinedRefIDs_files()
    {   // e.g. 23073097_UndefinedRefIDs_debug_2026-10-19.txt
        $paths = array();
        foreach(glob(CONTENT_RESOURCE_LOCAL_PATH . "*_UndefinedRefIDs_debug_*.txt") as $path) {
            if(preg_match("/^\d+_UndefinedRefIDs_debug_.*\.txt$/", basename($path))) $paths[] = $path;
        }
        return $paths;
    }
    private function delete_and_leave_only_the_latest_dates($paths)
    {   // per concept_id, keep only the file with the latest date; unlink the rest
        $latest = array(); // concept_id => array('date' => ..., 'path' => ...)
        foreach($paths as $path) {
            if(!preg_match("/^(\d+)_UndefinedRefIDs_debug_(\d{4}-\d{2}-\d{2})\.txt$/", basename($path), $arr)) continue;
            $concept_id = $arr[1]; $date = $arr[2];
            if(!isset($latest[$concept_id]) || $date > $latest[$concept_id]['date']) $latest[$concept_id] = array('date' => $date, 'path' => $path);
        }
        $remaining = array();
        foreach($latest as $rec) $remaining[] = $rec['path'];
        foreach($paths as $path) {
            if(!in_array($path, $remaining) && preg_match("/^\d+_UndefinedRefIDs_debug_\d{4}-\d{2}-\d{2}\.txt$/", basename($path))) unlink($path);
        }
        return $remaining;
    }
    function start()
    {
        $paths = self::get_all_UndefinedRefIDs_files();
        $paths = self::delete_and_leave_only_the_latest_dates($paths);
        print_r($paths);
    }
}
?>