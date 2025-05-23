<?php
namespace php_active_record;
/* GBIF dwc-a resources: country nodes
SPG provides mappings for values and URI's. The DWC-A file is requested from GBIF's web service.
This connector assembles the data and generates the EOL archive for ingestion.
estimated execution time: this will vary depending on how big the archive file is.

DATA-1577 GBIF national node type records- France
                                                2March
measurement_or_fact         [27223] 638236      837638
occurrence                  [9075]  212746      212746
taxon                       [4291]  95625       95625

886	Monday 2018-03-12 03:21:31 AM	{"measurement_or_fact.tab":837637,  "occurrence.tab":212745, "taxon.tab":95622}
after automating API download request-then-refresh:
886	Thu 2021-05-20 01:28:57 PM	    {"measurement_or_fact.tab":1887119, "occurrence.tab":271039, "taxon.tab":137903, "time_elapsed":{"sec":1423.21, "min":23.72, "hr":0.4}}
886	Sat 2022-06-18 01:44:05 AM	    {"measurement_or_fact.tab":1941403, "occurrence.tab":278803, "taxon.tab":129331, "time_elapsed":{"sec":1572.56, "min":26.21, "hr":0.44}}
886	Sun 2022-07-24 01:12:36 PM	    {"measurement_or_fact.tab":1941403, "occurrence.tab":278803, "taxon.tab":129331, "time_elapsed":{"sec":1577.22, "min":26.29, "hr":0.44}} consistent inc. OK

classification resource:
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
require_library('connectors/GBIFCountryTypeRecordAPI');
$timestart = time_elapsed();

/* local
$params["citation_file"] = LOCAL_HOST."/cp_new/GBIF_dwca/countries/France/Citation Mapping France.xlsx";
$params["dwca_file"]     = LOCAL_HOST."/cp_new/GBIF_dwca/countries/France/France.zip";
$params["uri_file"]      = LOCAL_HOST."/cp_new/GBIF_dwca/countries/France/french GBIF mapping.xlsx";
*/

// remote
$params["citation_file"] = "https://github.com/eliagbayani/EOL-connector-data-files/raw/master/GBIF_dwca/countries/France/Citation Mapping France.xlsx";
$params["dwca_file"]    = "https://editors.eol.org/other_files/GBIF_DwCA/France_0010150-190918142434337.zip"; //old, constant zip file
$params["dwca_file"]    = "https://editors.eol.org/other_files/GBIF_occurrence/GBIF_France/GBIF_France_DwCA.zip"; //new, changing zip file
$params["uri_file"]      = "https://github.com/eliagbayani/EOL-connector-data-files/raw/master/GBIF_dwca/countries/France/french GBIF mapping.xlsx";

$params["dataset"]      = "GBIF";
$params["country"]      = "France";
$params["type"]         = "structured data";
$params["resource_id"]  = 886;

// $params["type"]         = "classification resource";
// $params["resource_id"]  = 1;

$resource_id = $params["resource_id"];
$func = new GBIFCountryTypeRecordAPI($resource_id);
$func->export_gbif_to_eol($params);
Functions::finalize_dwca_resource($resource_id, false, true, $timestart);
?>