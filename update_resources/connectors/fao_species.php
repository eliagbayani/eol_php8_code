<?php
namespace php_active_record;
/* DATA-1787
fao_species	Thursday 2018-12-27 09:46:18 AM	{"agent.tab":1,"media_resource.tab":2104,"reference.tab":1949,"taxon.tab":539,"vernacular_name.tab":1588}
fao_species	Thursday 2019-01-10 08:27:57 AM	{"agent.tab":1,"media_resource.tab":2479,"reference.tab":1949,"taxon.tab":539,"vernacular_name.tab":1907}
fao_species	Tuesday 2019-01-15 05:34:04 AM	{"agent.tab":1,"media_resource.tab":2479,"reference.tab":1949,"taxon.tab":539,"vernacular_name.tab":2081}
fao_species	Sunday 2019-02-03 08:48:02 PM	{"agent.tab":1,"media_resource.tab":2479,"reference.tab":1949,"taxon.tab":539,"vernacular_name.tab":1971} -- expected less vernaculars due to change: https://eol-jira.bibalex.org/browse/DATA-1787?focusedCommentId=63213&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-63213
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
/* THIS WAS ALL RECANTED: AND CONTINUED TO USE: fao_species.php
exit("\nIt seems an obsolete connector. Last refreshed was 24Feb2019 [orig DwCA: fao_species.tar.gz].\n
Legacy DwCA saved as [fao_species_24Feb2019.tar.gz].\n
Source service from partner no longer accessible.\n\n
The legacy DwCA was then adjusted to remove HTML tags.\n
Final DwCA to date as of Oct 23, 2023 is: [fao_species-adjusted.tar.gz]. \n
It was generated by:                                    \n
- php dwca_utility.php _ fao_species                    \n
- php dwca_utility.php jenkins fao_species              \n
-> this generates: fao_species-adjusted.tar.gz          \n
"); */

require_library('connectors/FAOSpeciesAPI');
require_library('connectors/RemoveHTMLTagsAPI');
ini_set('memory_limit','7096M'); //required for checking uniqueness of measurementID
$timestart = time_elapsed();
$resource_id = 'fao_species';
$func = new FAOSpeciesAPI($resource_id);
$func->start();
Functions::finalize_dwca_resource($resource_id, false, true);

$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n\n";
echo "\n elapsed time = " . $elapsed_time_sec . " seconds";
echo "\n elapsed time = " . $elapsed_time_sec/60 . " minutes";
echo "\n elapsed time = " . $elapsed_time_sec/60/60 . " hours";
echo "\n Done processing.\n";
?>