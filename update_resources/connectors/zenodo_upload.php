<?php
namespace php_active_record;
/* Uploads any file to an existing Zenodo dataset as a new version. Not tied to Terms or References Maintenance.
Uses ZenodoTraitBankAPI::upload_new_version_to_zenodo():
    1. finds the dataset's latest published version (by its concept ID);
    2. stops if that version already has this exact file (same name and md5): nothing to upload;
    3. makes a new version draft (Zenodo copies the other files into it, so they are kept);
    4. replaces the file of the same name, or adds it if the dataset doesn't have it; checks the md5 after uploading;
    5. sets publication_date and the Dates entry "updated" to today (UTC) [+ a version label];
    6. stops at the draft, for review on Zenodo; publishes only with "publish": 1 -- a published version CANNOT be deleted.
    If something fails before publishing, the draft is discarded, so a leftover draft won't block the next run.

php zenodo_upload.php _ '{"concept_id": "12730071", "file": "/Volumes/Crucial_2TB/eol_resources/my_file.tsv", "dry_run": 1}'  //checks only: says what would be done
php zenodo_upload.php _ '{"concept_id": "12730071", "file": "/Volumes/Crucial_2TB/eol_resources/my_file.tsv"}'                //new version draft, NOT published
php zenodo_upload.php _ '{"concept_id": "12730071", "file": "/Volumes/Crucial_2TB/eol_resources/my_file.tsv", "publish": 1}'  //same, and publish now

Parameters:
    "concept_id"   required: the dataset's concept ID (the ID of all its versions), e.g. 12730071. A record ID of one of its versions works too.
    "file"         required: full path of the file to upload. Runs in Docker, so it must be a path the container sees,
                   e.g. /Volumes/Crucial_2TB/... or /var/www/html/eol_php8_code/... (not /Users/...).
    "publish"      optional: 1 = publish now (CANNOT be undone); default 0 = stop at a draft.
    "dry_run"      optional: 1 = only check and say what would be done; nothing is changed on Zenodo.
    "filename"     optional: the file's name on Zenodo; default the name of "file". The file with this name is replaced.
    "remove_files" optional: other file names to remove from the new version, e.g. ["my_file_v1.tsv"] when uploading my_file_v2.tsv
                   (a file with another name isn't replaced). Each must be in the dataset, or nothing is uploaded.
    "version"      optional: version label of the new version, e.g. "2026-11-23"; default: the label of the previous version.
    "tool"         optional: adds "-(auto uploaded by <tool> MM-DD-YYYY)-" at the bottom of the description, e.g. "Zenodo upload";
                   default: no line, the description stays as in the previous version.

Exit code: 0 for a draft, a published version, a dry run, or "Zenodo already has this exact file"; 1 for any error (Jenkins marks the build failed).
*/

/*
Use "remove_files" with the old file's name. For FishBase (12730071), whose only file is 42_meta_recoded.tar.gz:

php zenodo_upload.php _ '{"concept_id": "12730071", "file": "/Volumes/.../42_meta_recoded_v2.tar.gz", "remove_files": ["42_meta_recoded.tar.gz"], "dry_run": 1}'

The dry run lists what will happen: remove 42_meta_recoded.tar.gz, add 42_meta_recoded_v2.tar.gz, set today's date, and stop at a draft. 
If that looks right, run it again without "dry_run":

php zenodo_upload.php _ '{"concept_id": "12730071", "file": "/Volumes/.../42_meta_recoded_v2.tar.gz", "remove_files": ["42_meta_recoded.tar.gz"]}'

That makes the new version draft for you to check on Zenodo. To publish it straight away, add "publish": 1, which cannot be undone.
php zenodo_upload.php _ '{"concept_id": "12730071", "file": "/Volumes/.../42_meta_recoded_v2.tar.gz", "remove_files": ["42_meta_recoded.tar.gz"], "publish": 1}'
*/

include_once(dirname(__FILE__) . "/../../config/environment.php");
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
$timestart = time_elapsed();

$param = json_decode((string)@$argv[2], true);
$usage = "php zenodo_upload.php _ '{\"concept_id\": \"12730071\", \"file\": \"/Volumes/.../my_file.tsv\"}'   (add \"dry_run\": 1 to check first, \"publish\": 1 to publish)";
if(!is_array($param)) { echo "\nERROR: the parameters aren't valid JSON. Got: [" . @$argv[2] . "]\nPut the JSON in single quotes, e.g.\n$usage\n"; exit(1); }
$known = array('concept_id', 'file', 'publish', 'dry_run', 'filename', 'remove_files', 'version', 'tool');
if($unknown = array_diff(array_keys($param), $known)) { echo "\nERROR: unknown parameter(s): [" . implode('], [', $unknown) . "]. Known: " . implode(', ', $known) . "\nNothing was uploaded.\n"; exit(1); }

$concept_id = trim((string)($param['concept_id'] ?? ''));
$file       = trim((string)($param['file'] ?? ''));
if(!ctype_digit($concept_id)) { echo "\nERROR: \"concept_id\" is required and must be a number, e.g. 12730071. Got: [$concept_id]\n$usage\n"; exit(1); }
if($file === '')              { echo "\nERROR: \"file\" is required: the full path of the file to upload.\n$usage\n"; exit(1); }
if(!is_file($file)) {
    echo "\nERROR: No such file: [$file]\n";
    if(strpos($file, '/Users/') === 0 || $file[0] !== '/') echo "This runs in Docker, which doesn't see that path. Use a full path the container sees, e.g. /Volumes/Crucial_2TB/...\n";
    echo "Nothing was uploaded.\n"; exit(1);
}
if(filesize($file) == 0) { echo "\nERROR: The file is empty: [$file]\nNothing was uploaded.\n"; exit(1); }
if(isset($param['remove_files']) && !is_array($param['remove_files'])) $param['remove_files'] = array($param['remove_files']);

$options = array('publish' => !empty($param['publish']), 'dry_run' => !empty($param['dry_run']));
foreach(array('filename', 'remove_files', 'version', 'tool') as $k) if(isset($param[$k]) && $param[$k] !== '') $options[$k] = $param[$k];

echo "\nZenodo upload: dataset [$concept_id]\n";
echo "File: [$file] (" . number_format(filesize($file)) . " bytes)" . (isset($options['filename']) ? ", as [" . $options['filename'] . "] on Zenodo" : '') . "\n";
echo "Mode: " . ($options['dry_run'] ? "DRY RUN (checks only, nothing is changed on Zenodo)" : ($options['publish'] ? "new version, PUBLISHED at the end (cannot be undone)" : "new version draft, NOT published")) . "\n";
if(!$options['dry_run']) echo "Uploading can take a while for a large file; the next line comes when it's done.\n";
echo "\n";

require_library('connectors/ZenodoTraitBankAPI');
$func = new ZenodoTraitBankAPI();
$ret = $func->upload_new_version_to_zenodo($concept_id, $file, $options);
$status = $func->last_upload_status;

$elapsed = round(time_elapsed() - $timestart, 1);
echo "\nResult: $status ($elapsed seconds)\n";
if(in_array($status, array('draft', 'published', 'dry_run', 'unchanged'))) exit(0);
echo "Failed: see the ERROR above. Nothing was published.\n";
exit(1);
?>
