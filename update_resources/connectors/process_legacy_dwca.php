<?php
namespace php_active_record;
/* Legacy DwCA process: maintenance of the legacy DwCA resources on Zenodo.
Library: lib/connectors/LegacyDwCAMaintenanceAPI.php -- downloads go to [CONTENT_RESOURCE_LOCAL_PATH]/Legacy_DwCA_files/
start Nov 18, 2026:
php process_legacy_dwca.php _ '{"task": "download_file_from_zenodo", "concept_id": "13318109", "supplied_name": "AmphibiaWeb"}'
php process_legacy_dwca.php _ '{"task": "download_file_from_zenodo", "concept_id": "13318109", "supplied_name": "AmphibiaWeb", "redownload_zip_file_YN": 1}'  //ask Zenodo for its latest version now
    - Downloads the files of Zenodo's latest version of the dataset, e.g. AmphibiaWeb ->
      Legacy_DwCA_files/13318109_AmphibiaWeb/input_files/Archive.zip (not extracted).
    - "supplied_name" names the folder: [concept_id]_[supplied_name] (letters, digits, _ and - only).
      Without it, the folder is [concept_id]_[abbreviated Zenodo title], e.g. 13318109_AmphibiaWeb_Amph_text_w_trai_base_on_Pens_Anno
      If Zenodo gives a zip archive of the record instead, it is saved as Legacy_DwCA_files/[concept_id].zip.
    - Downloads only if Zenodo's latest version differs from ours (Legacy_DwCA_files/[concept_id].record_id), or files are missing.
    - Zenodo's record info is cached 1 day; "redownload_zip_file_YN": 1 gets it fresh, so a new version is never missed.
php process_legacy_dwca.php _ '{"task": "download_file_from_zenodo", "concept_id": "13318109", "supplied_name": "AmphibiaWeb", "file_type": ".tar.gz"}'
    - Same, then saves the final version in CONTENT_RESOURCE_LOCAL_PATH as [concept_id]_[supplied_name][file_type], e.g. 13318109_AmphibiaWeb.tar.gz
    - "file_type": ".tar.gz" or ".zip". Zenodo's file is converted if it is the other type (e.g. Archive.zip -> .tar.gz; files at the top of the
      archive, Mac __MACOSX/ and ._ files left out), or copied as is if it is already that type.
    - Not redone if the final version is already newer than the downloaded file.

php process_legacy_dwca.php _ '{"task": "convert_dwca_to_new_input_files", "concept_id": "13318109", "supplied_name": "AmphibiaWeb"}'
php process_legacy_dwca.php _ '{"task": "convert_dwca_to_new_input_files", "source": "13318109_AmphibiaWeb_TB_1_0.tar.gz"}'
php process_legacy_dwca.php _ '{"task": "convert_dwca_to_new_input_files", "source": "FishBase_TraitBank_1_0.tar.gz"}'
    - The first two are the same: "source" is the input file's name in CONTENT_RESOURCE_LOCAL_PATH (no folder); the output folder and the report
      are named from it. If "source" and "concept_id"/"supplied_name" are both given, they must name the same file.
    - (Nov 28, 2026) "source" alone is enough, and can be any legacy DwCA [name].tar.gz (letters, digits, _ and -): "concept_id" and
      "supplied_name" are only used to name the input ([concept_id]_[supplied_name]_TB_1_0.tar.gz), the output folder and the report.
      The name used is the file name without .tar.gz and a trailing _TB_1_0 or _TraitBank_1_0, e.g. FishBase_TraitBank_1_0.tar.gz ->
      TB_files_from_DwCA/FishBase_TraitBank/input_files/ and TB_files_from_DwCA/FishBase_convert_debug_[date].txt.
    - Converts a legacy DwCA into TraitBank input files, the same kind as the Zenodo TraitBank datasets' input_files
      (e.g. TB_files/22943466_Sexual_Syst_and_Char_TraitBank/input_files/):
      input:  CONTENT_RESOURCE_LOCAL_PATH/[concept_id]_[supplied_name]_TB_1_0.tar.gz            e.g. 13318109_AmphibiaWeb_TB_1_0.tar.gz
      output: CONTENT_RESOURCE_LOCAL_PATH/TB_files_from_DwCA/[concept_id]_[supplied_name]_TraitBank/input_files/Taxon.tsv, Traits.tsv and Sources.tsv
      report: CONTENT_RESOURCE_LOCAL_PATH/TB_files_from_DwCA/[concept_id]_[supplied_name]_convert_debug_[date].txt
      The output folder is made fresh each run; nothing is left behind if the run terminates.
    - The report starts with the rows in each file of the legacy DwCA (MoF: traits and child rows) and what is done with each file.
    - Taxon.tsv = the taxon table:
      - EOLid is renamed taxonKey.
      - Required columns: taxonID, scientificName, taxonKey (or EOLid) and taxonMap. taxonID and scientificName may never be blank;
        taxonKey and taxonMap may. Terminates with the reason if a column is missing, a taxonID or scientificName is blank,
        or the table has both EOLid and taxonKey. A duplicate taxonID: only its first row is written (reported).
      - Columns: taxonID, canonicalName, scientificName, parentNameUsageID, taxonKey, taxonMap, taxonRank, kingdom, phylum, class, order,
        family, superfamily, genus, higherClassification, taxonRemarks, source (those present), then any other column.
      - furtherInformationURL goes into source: appended with "|" if source already has a value; pipe-separated values are unique (reported).
      - higherClassification: no "|" at its start or end ('Animalia|Chordata|...|Ranidae|' -> 'Animalia|Chordata|...|Ranidae').
      - taxonRemarks: match_taxa_2DH's notes are removed (e.g. 'Trait: [ IndexGroup:[Tetrapoda] - IndexHC:[...] ]', 'No canonical match');
        other remarks are kept. If no remark is left in any row, the column is left out (reported).
    - Traits.tsv = the MoF table (measurement_or_fact_specific.tab or measurement_or_fact.tab):
      - one row per MoF row without a parentMeasurementID (traits, and context rows, i.e. measurementOfTaxon not true);
        occurrenceID, measurementOfTaxon and parentMeasurementID aren't carried over.
      - taxonID, scientificName and taxonKey added, via occurrenceID -> the occurrence's taxonID -> Taxon. A MoF row whose occurrence or
        taxon isn't found is left out (reported).
      - child rows (with a parentMeasurementID) become columns of their parent trait: column = the measurementType's short name, value =
        measurementValue, e.g. measurementType http://purl.org/dc/terms/lifeStage -> column "lifeStage". A grandchild goes to the trait
        at the top of its chain. If the column already exists (e.g. MoF's own lifeStage column), the values are joined with "|", unique.
        Child records listed in $GLOBALS['excluded_metadata_as_columns'] (top of LegacyDwCAMaintenanceAPI.php) aren't made into columns.
      - occurrence columns other than occurrenceID and taxonID (e.g. sex, lifeStage) are added to each trait of that occurrence, the same way.
        An occurrence table with only occurrenceID and taxonID adds nothing (still used to find each trait's taxon).
      - referenceID: multiple IDs joined with ";" are joined with "|" instead, unique. The ";" of old-style DOIs stays (e.g. ...2.0.CO;2).
      - Contributors (Nov 27, 2026, the Data Managers' plan: contributors are Sources, like references): there is no contributor column any more.
        Each contributor ID (the MoF's contributor column and its child rows of type http://purl.org/dc/terms/contributor) is:
        - kept, if it is a real, unique person ID: an ORCID (https://orcid.org/0000-0001-7209-9271), a FishBase collaborator page
          (http://www.fishbase.us/collaborators/CollaboratorSummary.php?id=7) or a WoRMS person page
          (https://www.marinespecies.org/imis.php?module=person&persid=26517) -> added to referenceID ("|"-separated, unique);
          its name (the Terms master's name for that URI, else the DwCA agent table's term_name for that term_homepage) is its citation:
          written to Sources.tsv. (Nov 28, 2026) The master sources.tsv is NOT changed by this task: it is only compared, and the report
          lists the contributors new to it (to be added later, like any other new source). A kept ID with no name stays in referenceID only,
          so References Maintenance lists it as unmappable. Contributors are never merged: each ID gets its own row.
        - left out, not carried forward: anything else, e.g. a made-up EOL URI (http://eol.org/schema/terms/Amphibiaweb_Zheng_Oong) or a hash.
        Reported: values read, IDs kept and left out (by kind, with examples), already in the master, new to it, with no name.
      - Columns: measurementID, taxonID, scientificName, taxonKey, the other MoF columns, the new columns, then source and referenceID.
    - Sources.tsv (was References.tsv) = the reference table (reference.tab): identifier, citation, as the master sources
      (TB_files/22776838_References/input_files/sources.tsv), only the references used in Traits.tsv' referenceID; then the contributors kept.
      Reported: referenceIDs not in the reference table, and how many are already in the master sources.tsv (same or different citation).
      Also by kind (Nov 28, 2026): references with a DOI as identifier and the others, each split into already in the master and new.
    - New columns: columns of Taxon.tsv / Traits.tsv not in $GLOBALS['taxon_columns'] / ['trait_columns'] (top of LegacyDwCAMaintenanceAPI.php,
      the columns of the 21 Zenodo TraitBank datasets) are kept, and listed on screen and in the report.
    - Columns in a file's header row that aren't in meta.xml are read too (reported). The association table isn't used.
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
ini_set('memory_limit','8096M');
$timestart = time_elapsed();
// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode((string)@$argv[2], true); //print_r($param); exit;
if(!is_array($param) || empty($param['task'])) {
    echo "\nERROR: the parameters aren't valid JSON with a \"task\". Got: [" . @$argv[2] . "]";
    echo "\nPut the JSON in single quotes, e.g. php process_legacy_dwca.php _ '{\"task\": \"...\"}'";
    echo "\nTo use a shell variable, close the quotes around it: '{\"task\": \"...\", \"file\": \"'\"\$file\"'\"}'\n";
    exit(1);
}

require_library('connectors/ZenodoTraitBankAPI');
require_library('connectors/LegacyDwCAMaintenanceAPI');
$func = new LegacyDwCAMaintenanceAPI($param);
$func->start();
?>