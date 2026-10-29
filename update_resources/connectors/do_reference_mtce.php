<?php 
namespace php_active_record;
/* Maintenance of the TB References: 
start Oct 23, 2026:
php do_reference_mtce.php _ '{"task": "prepare_undefined_referenceIDs_list"}'               //steps 1 & 2
    - Step 1: collects the undefined referenceIDs from the latest numbers_UndefinedRefIDs_debug_*.txt file of each dataset (older dates are deleted)
      into undefined_referenceIDs_YYYYMMDD.tsv (referenceID, concept_id). The debug files are then renamed with an x_ prefix.
    - Step 2: looks up each referenceID in CrossRef -> undefined_referenceIDs_YYYYMMDD_processed.tsv (referenceID, citation, status).
php do_reference_mtce.php _ '{"task": "update_References_from_UndefinedRefIDs_files"}'      //steps 3 & 4
    - Step 3: adds the referenceIDs that got a citation (status OK) to our master references.tsv (identifier, full_reference). Backup made first.
    - Step 4: adds the ones with no citation to unmappable_identifiers.tsv (identifier, status, full_reference), for the Data Manager to fill in by hand.
php do_reference_mtce.php _ '{"task": "refresh_unmappable_identifiers_usingMaster"}'        //step 5
    - Removes from unmappable_identifiers.tsv the identifiers that now have a citation in our master references.tsv.
php do_reference_mtce.php _ '{"task": "refresh_unmappable_identifiers_usingItself"}'        //step 5.1
    - Downloads unmappable_identifiers.tsv from the private GitHub repo EOL/unmappable_reference_identifiers (needs GITHUB_TOKEN).
      Stops, and explains the difference, if it isn't identical to our local copy. Otherwise the citations the Data Manager typed in
      (full_reference column) are added to our master references.tsv, and those identifiers are removed from unmappable_identifiers.tsv.
php do_reference_mtce.php _ '{"task": "refresh_unmappable_identifiers_usingDatasets"}'      //step 5.2
    - Removes from unmappable_identifiers.tsv the identifiers no longer cited in the traits file of any TraitBank dataset on Zenodo
      (except Terms, References, DH). Uses our downloaded copies; stops and removes nothing if any copy is missing or older than
      Zenodo's latest version (prints the re-download commands). Backup and a record of the removed rows are saved.
php do_reference_mtce.php _ '{"task": "utility_report_duplicate_identifiers_from_master"}'  //Utility : step 6
    - Report only, changes nothing: lists the identifiers that appear more than once in our master references.tsv
      -> references_duplicate_identifiers_YYYYMMDD.tsv, sorted by identifier.
php do_reference_mtce.php _ '{"task": "merge_zenodo_references"}'                           //merge Zenodo's new copy of references.tsv into our local master
    - A Zenodo download keeps our master and saves Zenodo's copy beside the folder (references_from_zenodo_record<N>.tsv).
      This adds the identifiers only Zenodo has to our master (backup made first). Different citations for the same identifier are not
      changed, but listed in a conflicts TSV for review. Zenodo's copy is then renamed ..._merged so it isn't suggested again.
php do_reference_mtce.php _ '{"task": "sync_zenodo_references", "dry_run_YN": 1}'           //sync merge, preview only: changes nothing
php do_reference_mtce.php _ '{"task": "sync_zenodo_references"}'                            //sync merge: use this when the data managers edit or delete references on Zenodo
    - Like merge_zenodo_references, but compares 3 copies: the Zenodo version we last synced with (the base, kept beside the folder as
      references_zenodo_base_record<N>.tsv), Zenodo's new copy, and our master. So it also applies Zenodo's DELETIONS and CHANGED citations:
      rows deleted on Zenodo are removed from our master, changed citations replace ours, new rows are added, and our own additions are kept.
      Backup made first; a log of every added/removed row is saved (references_22776838_zenodo_sync_<date>.tsv). Zenodo's copy then becomes the new base.
php do_reference_mtce.php _ '{"task": "set_zenodo_base_references"}'                        //one-time setup of the sync merge
    - Makes the newest downloaded Zenodo copy the base, only if it has nothing our master doesn't have. After a publish by upload_file_toZenodo,
      the published file becomes the base automatically.
php do_reference_mtce.php _ '{"task": "upload_file_toZenodo", "concept_id": "22776838"}'                  //upload our local master as a new version on Zenodo: draft only, for review
    - Creates a new version of the dataset on Zenodo with our local file (default: references.tsv for References); other files are kept.
      Stops at a draft, to be reviewed and published on Zenodo. Optional: "file": "traits", "version": "2026-10-28".
      Stops and uploads nothing if Zenodo has changes we don't have yet (an unmerged Zenodo copy, or a newer version than our download).
php do_reference_mtce.php _ '{"task": "upload_file_toZenodo", "concept_id": "22776838", "publish": 1}'    //same, but publish now -- CANNOT be undone
    - Same as above, but publishes the new version right away. A published Zenodo version can never be deleted.

php do_reference_mtce.php _ '{"task": "update_master_references_with_datamanager_supplied_file_of_unmappable_identifiers", "file": "/Volumes/Crucial_2TB/Desktop_Main/GitHub/unmappable_reference_identifiers/unmappable_identifiers_oct28_2026.tsv", "dry_run_YN": 1}'  //preview only: changes nothing
php do_reference_mtce.php _ '{"task": "update_master_references_with_datamanager_supplied_file_of_unmappable_identifiers", "file": "/Volumes/Crucial_2TB/Desktop_Main/GitHub/unmappable_reference_identifiers/unmappable_identifiers_oct28_2026.tsv"}'                   //uploads a draft to Zenodo
php do_reference_mtce.php _ '{"task": "update_master_references_with_datamanager_supplied_file_of_unmappable_identifiers", "file": "/Volumes/Crucial_2TB/Desktop_Main/GitHub/unmappable_reference_identifiers/unmappable_identifiers_oct28_2026.tsv", "publish": 1}'     //publishes on Zenodo -- CANNOT be undone
    - "file" (required): the Data Manager's copy of unmappable_identifiers.tsv (identifier, status, full_reference) with the citations typed in.
      A 2-column file (identifier, full_reference) is also accepted, e.g. from_Jen/references_add_template.tsv (use it with "skip_unmappable_update_YN": 1).
    - Step 1: rows with a full_reference are added to our master references.tsv (backup made first); identifiers already in the master are ignored (listed).
    - Step 2: the master is copied to the GitHub folder. 
    - Step 3: the master is uploaded to Zenodo (same as upload_file_toZenodo, with its safety checks).
      Optional "no_upload_YN": 1 -> step 3 is not done (no Zenodo upload), e.g. when another change follows and one upload should carry both.
    - Step 4: the added identifiers are removed from our unmappable_identifiers.tsv (backup made first; a trailing '/' is ignored when matching).
      Done even if the upload stopped. 
    - Step 5: unmappable_identifiers.tsv is copied to the GitHub folder.
    - Optional "skip_unmappable_update_YN": 1 -> stops after step 3: steps 4 & 5 are not done, our unmappable_identifiers.tsv is left as is.
      e.g. for a file of new references that were never on our unmappable list. Works with "dry_run_YN" and "publish" too.
    - Optional "replace_existing_YN": 1 -> an identifier already in the master with a different full_reference gets the new one, in place (same row),
      only if it is on exactly one master row. Backup made first; the old and new citations are saved (references_22776838_replaced_citations_<date>.tsv).
      e.g. from_Jen/references_add_template.tsv: "skip_unmappable_update_YN": 1, "replace_existing_YN": 1 (run fix_duplicate_issue_in_master_references first,
      with "no_upload_YN": 1, so that one upload carries both changes).

php do_reference_mtce.php _ '{"task": "fix_duplicate_issue_in_master_references", "file": "/Volumes/Crucial_2TB/Desktop_Main/GitHub/unmappable_reference_identifiers/from_Jen/references_remove_template.tsv", "dry_run_YN": 1}'  //preview only: changes nothing
php do_reference_mtce.php _ '{"task": "fix_duplicate_issue_in_master_references", "file": "/Volumes/Crucial_2TB/Desktop_Main/GitHub/unmappable_reference_identifiers/from_Jen/references_remove_template.tsv"}'                   //uploads a draft to Zenodo
php do_reference_mtce.php _ '{"task": "fix_duplicate_issue_in_master_references", "file": "/Volumes/Crucial_2TB/Desktop_Main/GitHub/unmappable_reference_identifiers/from_Jen/references_remove_template.tsv", "publish": 1}'     //publishes on Zenodo -- CANNOT be undone
    - "file" (required): the Data Manager's list of master rows to delete (identifier, full_reference, master_row), e.g. picked from the duplicates report.
    - Step 1: a master row is deleted only if its identifier and full_reference match exactly, at row master_row (header = row 1). If master_row is blank,
      the identifier + full_reference match is enough. Rows that don't match are skipped and listed. Backup made first; the deleted rows are saved
      (references_22776838_removed_duplicates_<date>.tsv), and the identifiers still on more than one row are listed.
    - Step 2: the master is copied to the GitHub folder. Step 3: the master is uploaded to Zenodo (same as upload_file_toZenodo, with its safety checks).
      Optional "no_upload_YN": 1 -> step 3 is not done (no Zenodo upload), e.g. when another change follows and one upload should carry both.

Note: every time unmappable_identifiers.tsv or our master references.tsv changes, it is also copied to
/Volumes/Crucial_2TB/Desktop_Main/GitHub/unmappable_reference_identifiers/
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
// /* during development
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$GLOBALS['ENV_DEBUG'] = true; //set to true during development
// */
ini_set('memory_limit','8096M'); //required for GloBI
$timestart = time_elapsed();
// print_r($argv);
$params['jenkins_or_cron'] = @$argv[1]; //not needed here
$param                     = json_decode(@$argv[2], true); //print_r($param); exit;

require_library('connectors/ZenodoTraitBankAPI');
require_library('connectors/GenerateTB_Files_Functions');    
require_library('connectors/ReferenceMaintenanceAPI');
$func = new ReferenceMaintenanceAPI($param);
$func->start(); //main operation - update_References_from_UndefinedRefIDs_files
?>