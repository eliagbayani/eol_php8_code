<?php
namespace php_active_record;
/* Zootaxa via Plazi
Partner provides an archive file. This connector fixes their media.txt, removing the two doublequotes in the description
estimated execution time: 1.5 minutes
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
require_library('connectors/PlaziArchiveAPI');
$timestart = time_elapsed();

$resource_id = 687;
$resource_path = "http://plazi.cs.umb.edu/GgServer/eol/zootaxa.zip";
if(!Functions::ping($resource_path)) $resource_path = LOCAL_HOST."/cp/Plazi/zootaxa.zip";

/*
// get resource path from database
$result = $GLOBALS['db_connection']->select("SELECT accesspoint_url FROM resources WHERE id=$resource_id");
$row = $result->fetch_row();
$new_resource_path = @$row[0];
if($resource_path != $new_resource_path && $new_resource_path) $resource_path = $new_resource_path;
*/
echo "\n processing resource:\n $resource_path \n\n";

$func = new PlaziArchiveAPI();
$func->clean_media_extension($resource_id, $resource_path);

// /* new: 2025: this removes the folder that is left hanging...: /resources/687/
if(is_dir(CONTENT_RESOURCE_LOCAL_PATH . $resource_id)) recursive_rmdir(CONTENT_RESOURCE_LOCAL_PATH . $resource_id);
// */

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n\n";
echo "\n elapsed time = " . $elapsed_time_sec/60 . " minutes";
echo "\n elapsed time = " . $elapsed_time_sec/60/60 . " hours";
echo "\n Done processing.\n";
?>