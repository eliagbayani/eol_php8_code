<?php
namespace php_active_record;
/*
https://zenodo.org/api/records/23067563
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class ZenodoTraitBankAPI
{
    // Permanent column headers of TB_files/TraitBank_datasets.csv. Only change deliberately: rows are written in this order.
    const TRAITBANK_DATASETS_CSV_HEADERS = array(
        'concept_id', 'record_id', 'title', 'version', 'publication_date', 'created', 'updated',
        'doi', 'concept_doi', 'resource_type', 'access_right', 'license', 'creators',
        'related_identifiers', 'file_count', 'file_names', 'total_size_bytes',
        'record_url', 'concept_url', 'archive_url',
        'views', 'unique_views', 'downloads', 'unique_downloads', 'description');

    // Files in input_files/ that we update locally (e.g. ReferenceMaintenanceAPI adds references to references.tsv).
    // A fresh Zenodo extraction/download keeps our local copy and saves Zenodo's copy beside the folder instead.
    // To take Zenodo's copy on purpose, run with param "overwrite_protected_files_YN": 1
    const PROTECTED_INPUT_FILES = array('22776838' => array('references.tsv')); // 22776838 = References

    // Local working copy of the GitHub repo EOL/unmappable_reference_identifiers. Each time our unmappable_identifiers.tsv or our
    // master references.tsv changes, it is copied here by copy_to_github_folder().
    const GITHUB_COPY_FOLDER = '/Volumes/Crucial_2TB/Desktop_Main/GitHub/unmappable_reference_identifiers/';

    function __construct($folder = null, $query = null)
    {
        $this->download_options = array(
            'resource_id'        => 'zenodo',  //resource_id here is just a folder name in cache
            'expire_seconds'     => 60*60*24*1, //maybe 1 day to expire
            'download_wait_time' => 1000000, 'timeout' => 60*3, 'download_attempts' => 1, 'delay_in_minutes' => 0.5);
        $this->api['domain'] = 'https://zenodo.org';
    }
    function do_zenodo_stuff($concept_id)
    {
        // print_r($this->param); //exit("\nelix 3\n");
        // Step 1: get latest Zenodo record info (cached) using the concept ID
        if(!($arr = $this->get_zenodo_info_using_conceptID($concept_id))) exit("\nERROR: Cannot get Zenodo info.\n");
        print_r($arr);
        $folder_name = $this->get_dataset_folder_name($concept_id, $arr['title']);
        // Step 2, 1st option: download the latest version's zip archive to TB_files/[concept_id].zip
        if($arr['archive_url'] && ($zip_file = $this->download_zenodo_zip_file($arr['archive_url'], $concept_id))) {
            // Step 3: extract to TB_files/[concept_id]_[abbreviated title]/input_files/ e.g. 23067562_Bioc_and_Natu_Prod/input_files/
            //         -- only after a fresh download, or if input_files/ is missing or empty (e.g. it was deleted manually)
            $input_dir = dirname($zip_file) . "/$folder_name/input_files/";
            if($this->zip_freshly_downloaded || !is_dir($input_dir) || count(scandir($input_dir)) <= 2) {
                if(!($this->input_dir = $this->unzip_zenodo_zip_file($zip_file, $folder_name))) exit("\nERROR: Cannot extract Zenodo zip file.\n");
                $this->add_missing_header_rows($concept_id, $this->input_dir);
            }
            else {
                echo "No fresh download, will use existing extracted files.\n";
                $this->input_dir = $input_dir;
            }
        }
        // Step 2, 2nd option: no archive_url (or its download failed), download the record's files individually
        //         straight to TB_files/[concept_id]_[abbreviated title]/input_files/
        elseif($arr['files']) {
            echo "No usable Zenodo archive URL, will download the files individually.\n";
            if(!($this->input_dir = $this->download_zenodo_files_individually($arr, $concept_id, $folder_name))) exit("\nERROR: Cannot download Zenodo files individually.\n");
            if($this->files_freshly_downloaded) $this->add_missing_header_rows($concept_id, $this->input_dir);
        }
        else exit("\nERROR: Zenodo record has no archive URL and no files.\n");
        echo "\nInput files folder: $this->input_dir\n";
    }
    function get_zenodo_info_using_conceptID($conceptId, $expire_seconds = null)
    {   // $expire_seconds: null = use download_options, int = cache lifetime in seconds (0 = always re-fetch), false = cache never expires
        // fallbacks: child classes (e.g. GenerateCSV_NewModel) may not call this class' constructor
        // print_r($this->param); exit("\nthis param\n");
        $domain = $this->api['domain'] ?? 'https://zenodo.org';
        if ($expire_seconds === null) {
            // redownload_zip_file_YN = 1 or true: get fresh record info from Zenodo (expire_seconds = 0), once per concept ID per run;
            // later calls in the same run (e.g. from get_generic_file_path()) use the just-refreshed cache
            if (!empty($this->param['redownload_zip_file_YN']) && !isset($this->zenodo_info_refreshed[$conceptId])) {
                $expire_seconds = 0;
                $this->zenodo_info_refreshed[$conceptId] = true;
            }
            else $expire_seconds = $this->download_options['expire_seconds'] ?? 60*60*24*1;
        }

        $options = array(
            'resource_id'       => 'zenodo', // cache goes to: DOC_ROOT . $GLOBALS['MAIN_CACHE_PATH'] . 'zenodo/'
            'expire_seconds'    => $expire_seconds,
            'timeout'           => $this->download_options['timeout'] ?? 60*3,
            'download_attempts' => 1,
            'download_wait_time' => 5000000, //5 seconds
            'user_agent'        => 'EOL-Zenodo-connector/1.0', // Zenodo returns HTTP 403 when User-Agent is empty
            'validation_regex'  => '"conceptrecid"'); // only use cache if it is a real Zenodo record JSON
        $body = Functions::lookup_with_cache("{$domain}/api/records/{$conceptId}/versions/latest", $options);

        if (!$body) {
            echo "Request failed for conceptID [$conceptId]\n";
            return false;
        }

        $rec = json_decode($body, true);
        if (!is_array($rec)) {
            echo "Invalid JSON response for conceptID [$conceptId]\n";
            return false;
        }

        // A. Zip of all files in the record (generated by Zenodo)
        $archiveUrl = $rec["links"]["archive"] ?? null;

        // B. Any uploaded .zip files (e.g. GitHub release archives)
        $zipFiles = [];
        foreach ($rec["files"] ?? [] as $f) {
            if (str_ends_with($f["key"], ".zip")) {
                $zipFiles[] = $f["links"]["self"];
            }
        }

        // C. All files of the record, for downloading individually when there is no archive URL
        $files = [];
        foreach ($rec["files"] ?? [] as $f) {
            if (empty($f["key"]) || empty($f["links"]["self"])) continue;
            $files[] = array(
                'key'      => $f["key"],                 // e.g. traits.tsv
                'url'      => $f["links"]["self"],       // e.g. https://zenodo.org/api/records/23067563/files/traits.tsv/content
                'size'     => $f["size"] ?? null,
                'checksum' => $f["checksum"] ?? null);   // e.g. md5:0f1e...
        }

        return array(
            'id'          => $rec['id'],
            'title'       => $rec['metadata']['title'] ?? '',
            'version'     => $rec['metadata']['version'] ?? null,
            'archive_url' => $archiveUrl,
            'zip_files'   => $zipFiles,
            'files'       => $files);
    }
    function get_zenodo_title_using_conceptID($conceptId, $expire_seconds = null)
    {   // returns the title of the latest version of the Zenodo record, or false on failure
        // $expire_seconds: same as in get_zenodo_info_using_conceptID(); shares its cached API response
        if (!($arr = $this->get_zenodo_info_using_conceptID($conceptId, $expire_seconds))) return false;
        return $arr['title'];
    }
    function download_zenodo_zip_file($archive_url, $concept_id)
    {   // e.g. https://zenodo.org/api/records/23067563/files-archive -> TB_files/23067562.zip (named by concept ID)
        // TB_files/23067562.record_id stores the record ID (version) of the downloaded zip, to detect new versions
        if (!preg_match('#/records/(\d+)/files-archive#', (string) $archive_url, $m)) {
            echo "Invalid Zenodo archive URL [$archive_url]\n";
            return false;
        }
        $record_id   = $m[1];
        $filename    = "{$concept_id}.zip";
        $dir         = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files/";
        $dest        = $dir . $filename;
        $record_file = $dir . "{$concept_id}.record_id";

        $this->zip_freshly_downloaded = false; // set to true only after a successful download below
        $redownload = !empty($this->param['redownload_zip_file_YN']); // missing param = 0
        if (is_file($dest) && filesize($dest) > 0) {
            $local_record_id = is_file($record_file) ? trim(file_get_contents($record_file)) : '';
            if ($local_record_id !== $record_id) {
                echo "New version on Zenodo [record $record_id, local was " . ($local_record_id ?: 'unknown') . "], re-downloading [$filename]\n";
            }
            elseif (!$redownload) {
                echo "Zip file already exists [$filename] (record $record_id)\n";
                return $dest;
            }
            else echo "Zip file already exists, re-downloading [$filename]\n";
        }
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            echo "Cannot create folder [$dir]\n";
            return false;
        }

        if (!$this->download_url_to_file($archive_url, $dest)) return false;
        file_put_contents($record_file, $record_id);
        $this->zip_freshly_downloaded = true;
        echo "Downloaded zip file [$filename] (record $record_id)\n";
        return $dest;
    }
    function download_zenodo_files_individually($arr, $concept_id, $folder_name)
    {   // 2nd option, when Zenodo gives no archive_url: download each file of the record ($arr from get_zenodo_info_using_conceptID())
        // e.g. https://zenodo.org/api/records/23067563/files/traits.tsv/content -> TB_files/23067562_Bioc_and_Natu_Prod/input_files/traits.tsv
        // TB_files/23067562.record_id stores the record ID (version) of the downloaded files, to detect new versions
        $record_id   = (string) $arr['id'];
        $dir         = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files/";
        $dest_dir    = $dir . "$folder_name/input_files/";
        $record_file = $dir . "{$concept_id}.record_id";

        // skip downloading if same version and all files are already there (non-empty)
        // -- not compared to Zenodo's size: add_missing_header_rows() may have added a header row since;
        //    partial downloads never get the real name anyway (.part file in download_url_to_file())
        $this->files_freshly_downloaded = false; // set to true only after a successful download below
        $redownload      = !empty($this->param['redownload_zip_file_YN']); // missing param = 0
        $local_record_id = is_file($record_file) ? trim(file_get_contents($record_file)) : '';
        $complete = true;
        foreach ($arr['files'] as $f) {
            $file = $dest_dir . basename($f['key']);
            if (!is_file($file) || filesize($file) == 0) { $complete = false; break; }
        }
        if ($local_record_id !== $record_id) {
            echo "New version on Zenodo [record $record_id, local was " . ($local_record_id ?: 'unknown') . "], downloading files individually\n";
        }
        elseif ($complete && !$redownload) {
            echo "Files already exist in [$folder_name/input_files/] (record $record_id)\n";
            return $dest_dir;
        }
        $saved = $this->set_aside_protected_files($concept_id, $dest_dir);
        $ret = $this->download_zenodo_files_fresh($arr, $folder_name, $dest_dir, $record_file, $record_id);
        $this->restore_protected_files($saved, $dest_dir, $concept_id); // also after a failed download
        return $ret;
    }
    private function download_zenodo_files_fresh($arr, $folder_name, $dest_dir, $record_file, $record_id)
    {   // part of download_zenodo_files_individually()
        // always start fresh: delete the destination folder and its contents, then download
        if (is_dir($dest_dir)) recursive_rmdir($dest_dir);
        if (!mkdir($dest_dir, 0775, true)) {
            echo "Cannot create folder [$dest_dir]\n";
            return false;
        }
        foreach ($arr['files'] as $f) {
            $filename = basename($f['key']); // basename(): never write outside input_files/
            $dest     = $dest_dir . $filename;
            if (!$this->download_url_to_file($f['url'], $dest)) return false;
            if (preg_match('/^md5:([0-9a-f]{32})$/i', (string) $f['checksum'], $m) && md5_file($dest) !== strtolower($m[1])) {
                unlink($dest);
                echo "Checksum mismatch, deleted [$filename]\n";
                return false;
            }
            chmod($dest, 0644);
            echo "Downloaded [$filename]\n";
        }
        file_put_contents($record_file, $record_id);
        $this->files_freshly_downloaded = true;
        echo "Downloaded " . count($arr['files']) . " file(s) to [$folder_name/input_files/] (record $record_id)\n";
        return $dest_dir;
    }
    function add_missing_header_rows($concept_id, $input_dir)
    {   // some Zenodo datasets have files without a header row; add it right after a fresh download
        // e.g. 22776838 (References): references.tsv starts with data, needs "identifier<TAB>full_reference" as 1st row
        $required = array('22776838' => array('references.tsv' => "identifier\tfull_reference"));
        foreach ($required[(string) $concept_id] ?? array() as $filename => $header) {
            $file = rtrim($input_dir, '/') . "/$filename";
            if (!is_file($file)) continue;
            if (!($fp = fopen($file, "r"))) { echo "Cannot read [$file]\n"; continue; }
            $first_line = rtrim((string) fgets($fp), "\r\n");
            fclose($fp);
            if ($first_line === $header) continue;
            // write header + original contents to a .part file, then replace the original
            $part = $file . ".part";
            if (!($out = fopen($part, "w")) || !($in = fopen($file, "r"))) { echo "Cannot add header row to [$file]\n"; continue; }
            fwrite($out, $header . "\n");
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            rename($part, $file);
            chmod($file, 0644);
            echo "Added header row to [$filename]\n";
        }
    }
    private function set_aside_protected_files($concept_id, $dest_dir)
    {   // before input_files/ is deleted: move our locally updated files (PROTECTED_INPUT_FILES) out of it
        // returns array(filename => path of the set-aside copy)
        $saved = array();
        if (!empty($this->param['overwrite_protected_files_YN'])) { // missing param = 0
            echo "overwrite_protected_files_YN = 1: protected files will be replaced by Zenodo's copy\n";
            return $saved;
        }
        foreach (self::PROTECTED_INPUT_FILES[(string) $concept_id] ?? array() as $filename) {
            $file = $dest_dir . $filename;
            if (!is_file($file) || filesize($file) == 0) continue; // nothing local yet, e.g. first download
            $aside = dirname(rtrim($dest_dir, '/')) . "/$filename.local_protected"; // outside input_files/, so it survives
            if (!rename($file, $aside)) exit("\nERROR: Cannot protect [$file], stopped so it isn't overwritten.\n");
            $saved[$filename] = $aside;
        }
        return $saved;
    }
    private function restore_protected_files($saved, $dest_dir, $concept_id)
    {   // after extraction/download: move Zenodo's new copy (if any) beside the folder, then put our local file back
        // e.g. TB_files/22776838_References/references_from_zenodo_record23100000.tsv -- outside input_files/, so get_generic_file_path() never uses it
        if (!empty($this->param['overwrite_protected_files_YN'])) { // our local file was replaced by Zenodo's copy on purpose
            foreach (self::PROTECTED_INPUT_FILES[(string) $concept_id] ?? array() as $filename) $this->copy_to_github_folder($dest_dir . $filename);
        }
        foreach ($saved as $filename => $aside) {
            if (!is_dir($dest_dir)) mkdir($dest_dir, 0775, true); // e.g. the download failed after the folder was deleted
            $file = $dest_dir . $filename;
            if (is_file($file)) {
                $record_file = dirname(dirname(rtrim($dest_dir, '/'))) . "/$concept_id.record_id";
                $label       = is_file($record_file) ? "record" . trim(file_get_contents($record_file)) : date("Ymd_His");
                $zenodo_copy = dirname(rtrim($dest_dir, '/')) . "/" . pathinfo($filename, PATHINFO_FILENAME) . "_from_zenodo_$label." . pathinfo($filename, PATHINFO_EXTENSION);
                if (!rename($file, $zenodo_copy)) { echo "ERROR: Cannot move Zenodo's [$filename] to [$zenodo_copy]\n"; $zenodo_copy = false; }
                else echo "Zenodo's [$filename] saved as [" . basename($zenodo_copy) . "]\n";
            }
            else $zenodo_copy = false;
            if (!rename($aside, $file)) { echo "ERROR: Cannot restore our local [$filename], it is at [$aside]\n"; continue; }
            echo "Kept our local [$filename] (protected, has local updates)\n";
            if ($zenodo_copy) $this->suggest_zenodo_merge($concept_id, $filename, $file, $zenodo_copy);
        }
    }
    /* ---------- Sync merge (3-way): applies Zenodo's additions, changes AND deletions to our local protected file ----------
       base   = the Zenodo version we last synced with, kept beside the dataset folder, e.g.
                TB_files/22776838_References/references_zenodo_base_record23682595.tsv
       Zenodo = Zenodo's new copy (references_from_zenodo_record<N>.tsv); local = our master in input_files/
       Rows are compared as (identifier, rest of the row) pairs:
         in base, not on Zenodo  -> deleted on Zenodo  -> removed from local too
         on Zenodo, not in base  -> added on Zenodo    -> added to local (if not there yet)
         in local, not in base   -> our own addition   -> kept
       A changed citation is a deletion + an addition, so Zenodo's new citation replaces the old one. */
    function find_zenodo_base($local_file, $filename)
    {   // newest base copy beside the dataset folder, or false
        $folder = dirname(dirname($local_file));
        $stem = pathinfo($filename, PATHINFO_FILENAME); $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $bases = glob("$folder/{$stem}_zenodo_base_*.$ext") ?: array();
        usort($bases, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $bases[0] ?? false;
    }
    function set_zenodo_base($local_file, $filename, $source, $label)
    {   // saves $source as the new base (e.g. label 'record23682595'); older bases are deleted. Returns the base path, or false.
        $folder = dirname(dirname($local_file));
        $stem = pathinfo($filename, PATHINFO_FILENAME); $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = "$folder/{$stem}_zenodo_base_$label.$ext";
        if (realpath($source) !== realpath($base)) {
            if (!copy($source, "$base.tmp") || !rename("$base.tmp", $base)) { @unlink("$base.tmp"); echo "ERROR: Cannot save the base [$base]\n"; return false; }
        }
        foreach (glob("$folder/{$stem}_zenodo_base_*.$ext") ?: array() as $old) if ($old !== $base) unlink($old);
        echo "Sync base is now: [" . basename($base) . "]\n";
        return $base;
    }
    private function read_tsv_pairs($file)
    {   // array('header' => header line or null, 'header_eol' => its line ending, 'pairs' => array("identifier\tvalue" => original line)),
        // in file order; blank lines skipped
        $header = null; $header_eol = null; $pairs = array();
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
            $cr = str_ends_with($line, "\r");
            $line = rtrim($line, "\r");
            if (trim($line) === '') continue;
            $tmp = explode("\t", $line, 2);
            if ($line_no == 0 && trim($tmp[0]) === 'identifier') { $header = $line; $header_eol = $cr ? "\r\n" : "\n"; continue; }
            $pairs[trim($tmp[0]) . "\t" . trim($tmp[1] ?? '')] = $line;
        }
        return array('header' => $header, 'header_eol' => $header_eol, 'pairs' => $pairs);
    }
    private function three_way_diff($local_file, $base_file, $zenodo_copy)
    {   // returns array('added' => pairs to add, 'removed' => pairs to remove, 'conflicts' => identifiers changed on both sides, 'local', 'zenodo')
        $L = $this->read_tsv_pairs($local_file); $B = $this->read_tsv_pairs($base_file); $Z = $this->read_tsv_pairs($zenodo_copy);
        $z_added   = array_diff_key($Z['pairs'], $B['pairs']); // added/changed on Zenodo since the base
        $z_removed = array_diff_key($B['pairs'], $Z['pairs']); // deleted/changed on Zenodo since the base
        $added     = array_diff_key($z_added, $L['pairs']);   // not in local yet
        $removed   = array_intersect_key($L['pairs'], $z_removed); // still in local
        // identifiers we changed locally since the base AND Zenodo changed too -> review
        $id = fn($key) => explode("\t", $key, 2)[0];
        $z_ids = array_flip(array_map($id, array_keys($z_added + $z_removed)));
        $l_ids = array_flip(array_map($id, array_keys(array_diff_key($L['pairs'], $B['pairs']) + array_diff_key($B['pairs'], $L['pairs']))));
        $conflicts = array_keys(array_intersect_key($z_ids, $l_ids));
        // for those identifiers Zenodo's rows win (the data managers' version), so no identifier ends up with both versions; logged as usual
        foreach ($conflicts as $cid) {
            foreach ($Z['pairs'] as $key => $line) if ($id($key) === $cid && !isset($L['pairs'][$key])) $added[$key] = $line;
            foreach ($L['pairs'] as $key => $line) if ($id($key) === $cid && !isset($Z['pairs'][$key])) $removed[$key] = $line;
        }
        return array('added' => $added, 'removed' => $removed, 'conflicts' => $conflicts, 'local' => $L, 'zenodo' => $Z);
    }
    function sync_zenodo_copy_into_local($concept_id, $filename = 'references.tsv', $zenodo_copy = null, $dry_run = false)
    {   /* Sync merge: applies Zenodo's additions, changes and deletions since the base to our local file; keeps our own additions.
        $zenodo_copy: default the newest unmerged copy beside the dataset folder. $dry_run: only report, change nothing.
        Our local file is backed up first and rewritten safely (tmp file, then rename). A log of every added/removed row is saved.
        Afterwards Zenodo's copy becomes the new base, and is renamed ..._merged so it isn't suggested again.
        Returns array('added', 'removed', 'conflicts', 'log_file'), or false. */
        $this->unmerged_checked["$concept_id/$filename"] = true; // no merge suggestion from get_generic_file_path() below
        if (!($local_file = $this->get_generic_file_path($concept_id, $filename, false))) { echo "ERROR: No local [$filename] for [$concept_id]\n"; return false; }
        if (!$zenodo_copy) {
            if (!($copies = $this->find_unmerged_zenodo_copies($local_file, $filename))) { echo "No unmerged Zenodo copy of [$filename] found. Nothing to sync.\n"; return false; }
            $zenodo_copy = $copies[0];
        }
        if (!is_file($zenodo_copy)) { echo "ERROR: No file [$zenodo_copy]\n"; return false; }
        if (!($base = $this->find_zenodo_base($local_file, $filename))) {
            echo "ERROR: No sync base yet for [$filename], so deletions on Zenodo can't be told apart from our own additions. Nothing was changed.\n"
               . "Set the base to the Zenodo version our local file was last in step with, e.g. if Zenodo's copy has nothing we don't have:\n"
               . "    php do_reference_mtce.php _ '{\"task\": \"set_zenodo_base_references\"}'\n";
            return false;
        }
        echo "Sync: Zenodo's [" . basename($zenodo_copy) . "] into our local [$local_file]\n      base: [" . basename($base) . "]" . ($dry_run ? " (dry run, nothing is changed)" : '') . "\n";
        $d = $this->three_way_diff($local_file, $base, $zenodo_copy);
        $orig = count($d['local']['pairs']); $final = $orig + count($d['added']) - count($d['removed']);
        $id_of = fn($line) => trim(explode("\t", $line, 2)[0]);
        foreach (array('removed', 'added') as $what) {
            if (!$d[$what]) continue;
            echo ucfirst($what) . " on Zenodo since the base (" . count($d[$what]) . " rows), e.g.:\n";
            foreach (array_slice($d[$what], 0, 10) as $line) echo "    " . mb_substr($line, 0, 150) . "\n";
        }
        if ($d['conflicts']) echo "NOTE: " . count($d['conflicts']) . " identifier(s) were changed both by us and on Zenodo since the base; Zenodo's version replaces ours (see the log), please review: [" . implode("], [", array_slice($d['conflicts'], 0, 20)) . "]\n";

        $ret = array('added' => count($d['added']), 'removed' => count($d['removed']), 'conflicts' => count($d['conflicts']), 'log_file' => null);
        if ($dry_run || (!$d['added'] && !$d['removed'])) {
            if (!$d['added'] && !$d['removed']) echo "Nothing to change: Zenodo's changes since the base are already in our local file.\n";
            echo "orig total rows: " . number_format($orig) . "\nadded rows: " . number_format($ret['added']) . "\nremoved rows: " . number_format($ret['removed']) . "\nfinal total rows: " . number_format($dry_run ? $final : $orig) . ($dry_run ? " (after the sync)" : '') . "\n";
            if ($dry_run) return $ret;
        }
        else {
            $stem = pathinfo($filename, PATHINFO_FILENAME); $ext = pathinfo($filename, PATHINFO_EXTENSION); $date = date("Ymd_His");
            $backup = CONTENT_RESOURCE_LOCAL_PATH . "{$stem}_{$concept_id}_backup_$date.$ext";
            if (!copy($local_file, $backup)) { echo "ERROR: Cannot backup [$local_file]\n"; return false; }
            echo "Backup: [$backup]\n";
            // local rows in their order, minus the removed ones, then the added ones in Zenodo's order; same line endings as our file
            $eol = (strpos(file_get_contents($local_file, false, null, 0, 100000), "\r\n") !== false) ? "\r\n" : "\n";
            $lines = array();
            foreach ($d['local']['pairs'] as $key => $line) if (!isset($d['removed'][$key])) $lines[] = $line;
            foreach ($d['added'] as $line) $lines[] = $line;
            $header = ($d['local']['header'] !== null) ? $d['local']['header'] . $d['local']['header_eol'] : ''; // header keeps its own line ending
            $tmp = "$local_file.tmp";
            if (file_put_contents($tmp, $header . implode($eol, $lines) . $eol) === false || !rename($tmp, $local_file)) { @unlink($tmp); echo "ERROR: Cannot update [$local_file]\n"; return false; }
            $log = CONTENT_RESOURCE_LOCAL_PATH . "{$stem}_{$concept_id}_zenodo_sync_$date.tsv";
            $rows = array("action\tidentifier\trow");
            foreach ($d['removed'] as $line) $rows[] = "removed\t" . $id_of($line) . "\t" . str_replace("\t", " | ", $line);
            foreach ($d['added'] as $line)   $rows[] = "added\t"   . $id_of($line) . "\t" . str_replace("\t", " | ", $line);
            file_put_contents($log, implode("\n", $rows) . "\n");
            $ret['log_file'] = $log;
            $now = count($this->read_tsv_pairs($local_file)['pairs']);
            echo "Updated: [$local_file]\nLog of the changes: [$log]\n";
            echo "orig total rows: " . number_format($orig) . "\nadded rows: " . number_format($ret['added']) . "\nremoved rows: " . number_format($ret['removed']) . "\nfinal total rows: " . number_format($now) . "\n";
            if ($now != $final) echo "WARNING: orig + added - removed != final\n";
            $this->copy_to_github_folder($local_file);
        }
        // Zenodo's copy is now the base for the next sync, and is marked as merged
        $label = preg_match('/_from_zenodo_(record\d+)/', basename($zenodo_copy), $m) ? $m[1] : date("Ymd_His");
        if (!$this->set_zenodo_base($local_file, $filename, $zenodo_copy, $label)) return false;
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (str_contains(basename($zenodo_copy), '_from_zenodo_') && !str_ends_with($zenodo_copy, "_merged.$ext")) {
            $merged = preg_replace('/\.' . preg_quote($ext, '/') . '$/', "_merged.$ext", $zenodo_copy);
            if (rename($zenodo_copy, $merged)) echo "Zenodo's copy marked as merged: [" . basename($merged) . "]\n";
        }
        // identifiers still listed more than once in our local file
        $ids = array(); foreach ($this->read_tsv_pairs($local_file)['pairs'] as $key => $line) { $i = explode("\t", $key, 2)[0]; $ids[$i] = ($ids[$i] ?? 0) + 1; }
        echo "Identifiers listed more than once in our local file now: " . count(array_filter($ids, fn($n) => $n > 1)) . "\n";
        return $ret;
    }
    function copy_to_github_folder($file)
    {   // copies $file to GITHUB_COPY_FOLDER (same file name), but only if it is one of the 2 files kept there:
        //   CONTENT_RESOURCE_LOCAL_PATH/unmappable_identifiers.tsv
        //   our master references: CONTENT_RESOURCE_LOCAL_PATH/TB_files/22776838_*/input_files/references.tsv
        // other files (e.g. test copies) are ignored. Returns the path of the copy, or false.
        $real = realpath($file);
        $base = realpath(CONTENT_RESOURCE_LOCAL_PATH);
        if (!$real || !$base) return false;
        $tracked = ($real === "$base/unmappable_identifiers.tsv")
                || preg_match('#^' . preg_quote("$base/TB_files/", '#') . '22776838_[^/]+/input_files/references\.tsv$#', $real);
        if (!$tracked) return false;
        $dest = static::GITHUB_COPY_FOLDER . basename($real);
        if (!is_dir(static::GITHUB_COPY_FOLDER)) { echo "NOTE: GitHub folder not found, [" . basename($real) . "] not copied: [" . static::GITHUB_COPY_FOLDER . "]\n"; return false; }
        if (is_file($dest) && md5_file($dest) === md5_file($real)) { echo "GitHub folder copy already up to date: [$dest]\n"; return $dest; }
        $tmp = "$dest.tmp"; // copy to a temp file first, so a failed copy can't leave a half-written file
        if (!copy($real, $tmp) || !rename($tmp, $dest)) { @unlink($tmp); echo "ERROR: Cannot copy [$real] to [$dest]\n"; return false; }
        echo "Copied to GitHub folder: [$dest]\n";
        return $dest;
    }
    protected function compare_with_zenodo_copy($local_file, $zenodo_copy)
    {   // for protected files whose 1st column is the identifier (e.g. references.tsv: identifier, full_reference)
        // compares (identifier, rest of the row) pairs; a header row (1st field 'identifier') and blank lines are skipped
        // returns array('new_rows' => rows of identifiers we don't have, 'new_ids' => count,
        //               'conflicts' => array(identifier => array('local' => values, 'zenodo' => values we don't have for it)))
        $read = function ($file) {
            $pairs = array();
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $line_no => $line) {
                $line = rtrim($line, "\r");
                if (trim($line) === '') continue;
                $tmp = explode("\t", $line, 2);
                $id = trim($tmp[0]);
                if ($line_no == 0 && $id === 'identifier') continue; // header
                $pairs[$id][trim($tmp[1] ?? '')] = $line;
            }
            return $pairs;
        };
        $local = $read($local_file); $zenodo = $read($zenodo_copy);
        $new_rows = array(); $new_ids = 0; $conflicts = array();
        foreach ($zenodo as $id => $values) {
            if (!isset($local[$id])) { $new_ids++; foreach ($values as $line) $new_rows[] = $line; continue; }
            $missing = array_diff_key($values, $local[$id]); // Zenodo has a value we don't have for this identifier
            if ($missing) $conflicts[$id] = array('local' => array_keys($local[$id]), 'zenodo' => array_keys($missing));
        }
        return array('new_rows' => $new_rows, 'new_ids' => $new_ids, 'conflicts' => $conflicts);
    }
    private function suggest_zenodo_merge($concept_id, $filename, $local_file, $zenodo_copy)
    {   // shows whether Zenodo's copy has changes our local protected file doesn't, and how to merge them
        if ($base = $this->find_zenodo_base($local_file, $filename)) { // sync merge: also sees deletions and changed citations
            $d = $this->three_way_diff($local_file, $base, $zenodo_copy);
            if (!$d['added'] && !$d['removed']) {
                echo "Zenodo's copy [" . basename($zenodo_copy) . "]: its changes since the base are already in our local [$filename]. No sync needed.\n";
                return false;
            }
            echo "\nSUGGESTION: Zenodo's copy [" . basename($zenodo_copy) . "] has changes since the base [" . basename($base) . "]:\n"
               . "  - rows to add: " . number_format(count($d['added'])) . "\n  - rows to remove (deleted or changed on Zenodo): " . number_format(count($d['removed'])) . "\n"
               . ($d['conflicts'] ? "  - identifiers changed both by us and on Zenodo: " . count($d['conflicts']) . "\n" : '')
               . "  To preview, then apply them to our local file, run:\n";
            if ($filename == 'references.tsv') echo "    php do_reference_mtce.php _ '{\"task\": \"sync_zenodo_references\", \"dry_run_YN\": 1}'\n    php do_reference_mtce.php _ '{\"task\": \"sync_zenodo_references\"}'\n  or call:\n";
            echo "    \$this->sync_zenodo_copy_into_local($concept_id, '$filename');\n\n";
            return true;
        }
        $diff = $this->compare_with_zenodo_copy($local_file, $zenodo_copy);
        if (!$diff['new_ids'] && !$diff['conflicts']) {
            echo "Zenodo's copy [" . basename($zenodo_copy) . "] has nothing that our local [$filename] doesn't have. No merge needed.\n";
            return false;
        }
        echo "\nSUGGESTION: Zenodo's copy [" . basename($zenodo_copy) . "] has changes not in our local [$filename]:\n"
           . "  - new identifiers: " . number_format($diff['new_ids']) . " (" . number_format(count($diff['new_rows'])) . " rows)\n"
           . "  - identifiers with a different value on Zenodo: " . number_format(count($diff['conflicts'])) . "\n"
           . "  To merge them into our local file, run:\n";
        if ($filename == 'references.tsv') echo "    php do_reference_mtce.php _ '{\"task\": \"merge_zenodo_references\"}'\n  or call:\n";
        echo "    \$this->merge_zenodo_copy_into_local($concept_id, '$filename');\n\n";
        return true;
    }
    function check_unmerged_zenodo_copies($concept_id, $filename, $local_file)
    {   // once per run per file: if a Zenodo copy saved beside the folder hasn't been merged yet, show the merge suggestion
        if (isset($this->unmerged_checked["$concept_id/$filename"])) return;
        $this->unmerged_checked["$concept_id/$filename"] = true;
        if (!($copies = $this->find_unmerged_zenodo_copies($local_file, $filename))) return;
        if (count($copies) > 1) echo "NOTE: " . count($copies) . " unmerged Zenodo copies of [$filename]; the newest is used: [" . basename($copies[0]) . "]\n";
        $this->suggest_zenodo_merge($concept_id, $filename, $local_file, $copies[0]);
    }
    protected function find_unmerged_zenodo_copies($local_file, $filename)
    {   // Zenodo copies saved by restore_protected_files() beside the dataset folder, newest first; merged ones end in _merged
        // e.g. TB_files/22776838_References/references_from_zenodo_record23100000.tsv
        $folder = dirname(dirname($local_file));
        $stem = pathinfo($filename, PATHINFO_FILENAME); $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $copies = array_filter(glob("$folder/{$stem}_from_zenodo_*.$ext") ?: array(), fn($f) => !str_ends_with($f, "_merged.$ext"));
        usort($copies, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return array_values($copies);
    }
    function merge_zenodo_copy_into_local($concept_id, $filename = 'references.tsv', $zenodo_copy = null)
    {   /* Merges Zenodo's copy of a protected file into our local file, e.g. (22776838, 'references.tsv'):
            - identifiers only on Zenodo: their rows are added to our local file
            - identifiers in both, but Zenodo has a different value: NOT changed; listed in a conflicts TSV for review
            - identifiers only in our local file (our additions): kept
        $zenodo_copy: default the newest unmerged copy beside the dataset folder.
        The local file is backed up first; the Zenodo copy is then renamed to ..._merged.tsv, so it isn't suggested again.
        Returns array('added' => rows added, 'new_ids' => n, 'conflicts' => n, 'conflicts_file' => path or null), or false. */
        $this->unmerged_checked["$concept_id/$filename"] = true; // no merge suggestion from get_generic_file_path() below
        if (!($local_file = $this->get_generic_file_path($concept_id, $filename, false))) { echo "ERROR: No local [$filename] for [$concept_id]\n"; return false; }
        if (!$zenodo_copy) {
            if (!($copies = $this->find_unmerged_zenodo_copies($local_file, $filename))) { echo "No unmerged Zenodo copy of [$filename] found. Nothing to merge.\n"; return false; }
            $zenodo_copy = $copies[0];
        }
        if (!is_file($zenodo_copy)) { echo "ERROR: No file [$zenodo_copy]\n"; return false; }
        echo "Merging Zenodo's [" . basename($zenodo_copy) . "] into our local [$local_file]\n";
        $diff = $this->compare_with_zenodo_copy($local_file, $zenodo_copy);
        $stem = pathinfo($filename, PATHINFO_FILENAME); $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $count_rows = function ($file) { // non-blank rows, without the header
            $n = 0;
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $i => $line) if (trim($line) !== '' && !($i == 0 && trim(explode("\t", $line)[0]) === 'identifier')) $n++;
            return $n;
        };
        $orig = $count_rows($local_file);

        if ($diff['new_rows']) {
            $backup = CONTENT_RESOURCE_LOCAL_PATH . "{$stem}_{$concept_id}_backup_" . date("Ymd_His") . ".$ext";
            if (!copy($local_file, $backup)) { echo "ERROR: Cannot backup [$local_file]\n"; return false; }
            echo "Backup: [$backup]\n";
            $contents = file_get_contents($local_file);
            $eol = (strpos($contents, "\r\n") !== false) ? "\r\n" : "\n"; // same line endings as our file (references.tsv uses CRLF)
            $fhandle = fopen($local_file, "a");
            if ($contents !== '' && substr($contents, -1) !== "\n") fwrite($fhandle, $eol); // last row has no line ending
            foreach ($diff['new_rows'] as $row) fwrite($fhandle, $row . $eol);
            fclose($fhandle);
            $this->copy_to_github_folder($local_file);
        }
        $final = $count_rows($local_file);
        echo "orig total rows: " . number_format($orig) . "\nadded rows: " . number_format(count($diff['new_rows'])) . " (" . number_format($diff['new_ids']) . " new identifiers)\nfinal total rows: " . number_format($final) . "\n";
        if ($orig + count($diff['new_rows']) != $final) echo "WARNING: orig + added != final\n";

        $conflicts_file = null;
        if ($diff['conflicts']) {
            $conflicts_file = CONTENT_RESOURCE_LOCAL_PATH . "{$stem}_{$concept_id}_zenodo_conflicts_" . date("Ymd_His") . ".tsv";
            $rows = array("identifier\tlocal_value\tzenodo_value");
            foreach ($diff['conflicts'] as $id => $c) foreach ($c['zenodo'] as $value) $rows[] = "$id\t" . implode(" || ", $c['local']) . "\t$value";
            file_put_contents($conflicts_file, implode("\n", $rows) . "\n");
            echo "NOTE: " . count($diff['conflicts']) . " identifier(s) have a different value on Zenodo; our local values were kept. Review: [$conflicts_file]\n";
        }
        $merged = preg_replace('/\.' . preg_quote($ext, '/') . '$/', "_merged.$ext", $zenodo_copy);
        if (rename($zenodo_copy, $merged)) echo "Zenodo's copy marked as merged: [" . basename($merged) . "]\n";
        else echo "WARNING: Cannot rename [$zenodo_copy] to [$merged]; it may be suggested again\n";
        return array('added' => count($diff['new_rows']), 'new_ids' => $diff['new_ids'], 'conflicts' => count($diff['conflicts']), 'conflicts_file' => $conflicts_file);
    }
    function upload_new_version_to_zenodo($concept_id, $local_file, $options = array())
    {   /* Uploads $local_file to a Zenodo dataset as a new version, e.g.
            upload_new_version_to_zenodo(22776838, '.../22776838_References/input_files/references.tsv')
        Steps: new-version draft of the latest version -> replace the file of the same name (other files are kept, as Zenodo
               copies them into the draft) -> publication_date = today [+ version label] -> publish only if asked.
        $options:
            'publish'  => false (default): stop at the draft, so it can be reviewed on Zenodo first. true: publish now -- CANNOT be undone.
            'version'  => version label of the new version, e.g. '2026-10-27'; default: keep the label copied from the previous version
            'filename' => file name on Zenodo; default basename($local_file)
            'token'    => Zenodo token; default the ZENODO_TOKEN constant. Sent only in the Authorization header.
            'domain'   => default $this->api['domain'] or https://zenodo.org; e.g. https://sandbox.zenodo.org (needs a sandbox token)
        Returns array('draft_id', 'draft_url', 'published', 'record_id', 'doi'), or false.
        If something fails before publishing, the new draft is discarded, so a leftover draft won't block the next run. */
        $publish  = !empty($options['publish']);
        $filename = $options['filename'] ?? basename($local_file);
        $token    = $options['token'] ?? (defined('ZENODO_TOKEN') ? ZENODO_TOKEN : '');
        if (isset($options['domain'])) $this->api['domain'] = $options['domain'];
        $domain   = $this->api['domain'] ?? 'https://zenodo.org';
        if (!is_file($local_file) || filesize($local_file) == 0) { echo "ERROR: No file, or empty file [$local_file]\n"; return false; }
        if (!$token) { echo "ERROR: No Zenodo token (ZENODO_TOKEN)\n"; return false; }

        // 1. latest published version of the dataset (fresh, not cached)
        if (!($latest = $this->get_zenodo_info_using_conceptID($concept_id, 0))) { echo "ERROR: Cannot get the latest version of [$concept_id]\n"; return false; }
        $record_id = $latest['id'];
        echo "Dataset [$concept_id] \"{$latest['title']}\", latest version: record $record_id" . ($latest['version'] ? ", version {$latest['version']}" : '') . "\n";
        // nothing to upload if the latest version already has this exact file (same md5): a published version can't be deleted
        $md5 = md5_file($local_file);
        foreach ($latest['files'] as $f) {
            if ($f['key'] === $filename && strtolower((string) $f['checksum']) === "md5:$md5") {
                echo "Zenodo's latest version (record $record_id) already has this exact file [$filename] (md5 $md5). Nothing was uploaded.\n";
                return false;
            }
        }

        // 2. new-version draft. Zenodo returns the original record, with the draft at links.latest_draft
        [$code, $res] = $this->zenodo_api_request('POST', "$domain/api/deposit/depositions/$record_id/actions/newversion", $token);
        if ($code != 201) { echo "ERROR: Cannot create a new version (HTTP $code): " . $this->zenodo_error_message($res) . "\n"; return false; }
        $draft_url = $res['links']['latest_draft'] ?? null;
        if (!$draft_url && @$res['id'] && $res['id'] != $record_id) $draft_url = "$domain/api/deposit/depositions/" . $res['id']; // in case the draft itself is returned
        if (!$draft_url) { echo "ERROR: Zenodo did not return the new draft\n"; return false; }
        [$code, $draft] = $this->zenodo_api_request('GET', $draft_url, $token);
        if ($code != 200 || empty($draft['id']) || $draft['id'] == $record_id) { echo "ERROR: Cannot get the new draft (HTTP $code): " . $this->zenodo_error_message($draft) . "\n"; return false; }
        $draft_id = $draft['id'];
        $html     = $draft['links']['html'] ?? "$domain/deposit/$draft_id";
        echo "New version draft: $draft_id\n";
        $discard = function ($why) use ($domain, $draft_id, $token, $html) { // remove the unpublished draft after a failure
            echo "ERROR: $why\n";
            [$code] = $this->zenodo_api_request('POST', "$domain/api/deposit/depositions/$draft_id/actions/discard", $token);
            echo ($code == 201 || $code == 204) ? "Draft $draft_id discarded, nothing was published.\n"
                                                : "Could not discard draft $draft_id (HTTP $code); discard it by hand: $html\n";
            return false;
        };

        // 3. replace the file: delete the copy that came from the previous version, then upload ours
        foreach ($draft['files'] ?? array() as $f) {
            if (($f['filename'] ?? '') !== $filename) continue;
            [$code, $res] = $this->zenodo_api_request('DELETE', "$domain/api/deposit/depositions/$draft_id/files/" . $f['id'], $token);
            if ($code != 204) return $discard("Cannot remove the previous [$filename] from the draft (HTTP $code): " . $this->zenodo_error_message($res));
            echo "Removed the previous [$filename] from the draft\n";
        }
        if (!($bucket = $draft['links']['bucket'] ?? null)) return $discard("The draft has no upload (bucket) link");
        [$code, $res] = $this->zenodo_api_request('PUT', "$bucket/" . rawurlencode($filename), $token, null, $local_file);
        if ($code != 200 && $code != 201) return $discard("Upload of [$filename] failed (HTTP $code): " . $this->zenodo_error_message($res));
        if (strtolower((string) ($res['checksum'] ?? '')) !== "md5:$md5") return $discard("Uploaded [$filename] has the wrong checksum [" . ($res['checksum'] ?? 'none') . "], expected [md5:$md5]");
        echo "Uploaded [$filename] (" . number_format(filesize($local_file)) . " bytes, md5 verified)\n";

        // 4. metadata: the draft keeps the previous version's metadata; set today's publication date (required to publish) [+ version]
        $metadata = $draft['metadata'] ?? array();
        unset($metadata['prereserve_doi']); // read-only
        $metadata['publication_date'] = date("Y-m-d");
        if (isset($options['version'])) $metadata['version'] = (string) $options['version'];
        [$code, $res] = $this->zenodo_api_request('PUT', "$domain/api/deposit/depositions/$draft_id", $token, array('metadata' => $metadata));
        if ($code != 200) return $discard("Cannot update the draft's metadata (HTTP $code): " . $this->zenodo_error_message($res));
        echo "Draft metadata: publication_date " . $metadata['publication_date'] . ", version " . ($metadata['version'] ?? '(none)') . "\n";

        $ret = array('draft_id' => $draft_id, 'draft_url' => $html, 'published' => false, 'record_id' => null, 'doi' => null);
        if (!$publish) {
            echo "Draft ready for review, NOT published: $html\n";
            echo "Publish it there, or run again with 'publish' => true. Until it is published or discarded, no other new version can be started.\n";
            return $ret;
        }

        // 5. publish -- cannot be undone. On failure the draft is kept, so the error can be checked on Zenodo.
        [$code, $res] = $this->zenodo_api_request('POST', "$domain/api/deposit/depositions/$draft_id/actions/publish", $token);
        if ($code != 202) { echo "ERROR: Publish failed (HTTP $code): " . $this->zenodo_error_message($res) . "\nThe draft was kept: $html\n"; return false; }
        $ret['published'] = true;
        $ret['record_id'] = $res['record_id'] ?? $res['id'] ?? $draft_id;
        $ret['doi']       = $res['doi'] ?? null;
        echo "Published new version: record " . $ret['record_id'] . ($ret['doi'] ? ", DOI " . $ret['doi'] : '') . "\n";
        return $ret;
    }
    private function zenodo_api_request($method, $url, $token, $json = null, $upload_file = null)
    {   // Zenodo REST call; the token goes in the Authorization header only (never in the URL). Returns array(HTTP code, decoded JSON body).
        $headers = array("Authorization: Bearer $token", "Accept: application/json");
        $ch = curl_init($url);
        $opts = array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                      CURLOPT_USERAGENT => "EOL-Zenodo-connector/1.0", // Zenodo returns HTTP 403 when User-Agent is empty
                      CURLOPT_CONNECTTIMEOUT => 60, CURLOPT_TIMEOUT => 0,  // no limit, uploads can be large
                      CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2);
        if ($json !== null) {
            $headers[] = "Content-Type: application/json";
            $opts[CURLOPT_POSTFIELDS] = json_encode($json);
        }
        if ($upload_file !== null) { // stream the file, so large files aren't loaded into memory
            $fp = fopen($upload_file, "r");
            $headers[] = "Content-Type: application/octet-stream";
            $opts[CURLOPT_UPLOAD] = true;
            $opts[CURLOPT_INFILE] = $fp;
            $opts[CURLOPT_INFILESIZE] = filesize($upload_file);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (isset($fp)) fclose($fp);
        if ($body === false) return array(0, array('message' => "connection failed: $error"));
        $data = json_decode($body, true);
        return array($code, is_array($data) ? $data : array('message' => substr(trim($body), 0, 300)));
    }
    private function zenodo_error_message($res)
    {   // e.g. {"status": 400, "message": "...", "errors": [{"field": "metadata.title", "message": "..."}]}
        $msg = (string) ($res['message'] ?? 'no message');
        foreach ($res['errors'] ?? array() as $e) $msg .= " | " . (isset($e['field']) ? $e['field'] . ": " : '') . (is_array($e['messages'] ?? null) ? implode('; ', $e['messages']) : ($e['message'] ?? ''));
        return $msg;
    }
    private function download_url_to_file($url, $dest)
    {   // download to a .part file first, so an interrupted download is never mistaken for a complete file
        $part = $dest . ".part";
        if (!($fp = fopen($part, "w"))) {
            echo "Cannot write to [$part]\n";
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_USERAGENT      => "EOL-Zenodo-connector/1.0", // Zenodo returns HTTP 403 when User-Agent is empty
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_TIMEOUT        => 0, // no limit, archives can be large
        ]);
        $ok     = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $status !== 200) {
            if (is_file($part)) unlink($part);
            echo "Download failed (HTTP $status) [$url]: $error\n";
            return false;
        }
        return rename($part, $dest);
    }
    function unzip_zenodo_zip_file($zip_file, $folder_name = null)
    {   // e.g. TB_files/23067562.zip -> TB_files/23067562_Bioc_and_Natu_Prod/input_files/
        // $folder_name: defaults to the zip's name without .zip, e.g. TB_files/23067562/input_files/
        if (!is_file((string) $zip_file)) {
            echo "Zip file not found [$zip_file]\n";
            return false;
        }
        $folder_name = $folder_name ?? pathinfo($zip_file, PATHINFO_FILENAME);
        $dest_dir    = dirname($zip_file) . "/$folder_name/input_files/";
        $concept_id  = pathinfo($zip_file, PATHINFO_FILENAME); // zip is named by concept ID, e.g. 22776838.zip

        $saved = $this->set_aside_protected_files($concept_id, $dest_dir);
        $ret = $this->extract_zenodo_zip_fresh($zip_file, $folder_name, $dest_dir);
        $this->restore_protected_files($saved, $dest_dir, $concept_id); // also after a failed extraction
        return $ret;
    }
    private function extract_zenodo_zip_fresh($zip_file, $folder_name, $dest_dir)
    {   // part of unzip_zenodo_zip_file()
        // always start fresh: delete the destination folder and its contents, then extract
        if (is_dir($dest_dir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dest_dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($dest_dir);
        }
        if (!mkdir($dest_dir, 0775, true)) {
            echo "Cannot create folder [$dest_dir]\n";
            return false;
        }

        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if (($res = $zip->open($zip_file)) !== true) {
                echo "Cannot open zip file [$zip_file], ZipArchive error code: $res\n";
                return false;
            }
            $ok = $zip->extractTo($dest_dir);
            $count = $zip->numFiles;
            $zip->close();
        }
        else { // PHP zip extension not installed (e.g. our Docker container), use the unzip command instead
            exec("unzip -o -q " . escapeshellarg($zip_file) . " -d " . escapeshellarg($dest_dir) . " 2>&1", $output, $exit_code);
            $ok = ($exit_code === 0);
            if (!$ok) echo implode("\n", $output) . "\n";
            $count = iterator_count(new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dest_dir, \FilesystemIterator::SKIP_DOTS)));
        }

        if (!$ok) {
            echo "Failed to extract [$zip_file] to [$dest_dir]\n";
            return false;
        }

        // make extracted files readable by everyone (zip may store them as rw-------)
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dest_dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            if ($item->isLink()) continue;
            chmod($item->getPathname(), $item->isDir() ? 0755 : 0644);
        }
        echo "Extracted $count file(s) to [$folder_name/input_files/]\n";
        return $dest_dir;
    }
    function generate_Zenodo_TraitBank_datasets_inCSV()
    {   // saves all datasets (latest versions) of the Zenodo 'traitbank' community to TB_files/TraitBank_datasets.csv
        $domain  = $this->api['domain'] ?? 'https://zenodo.org';
        $url     = "{$domain}/api/communities/traitbank/records?q=&sort=newest&size=25&page=1"; // 25 = max page size without an access token
        $options = array('user_agent' => 'EOL-Zenodo-connector/1.0', // Zenodo returns HTTP 403 when User-Agent is empty
                         'timeout' => 60*3, 'download_attempts' => 2, 'download_wait_time' => 1000000);

        // Step 1: fetch all pages, following links.next
        $hits = array();
        while ($url) {
            $data = json_decode((string) Functions::get_remote_file($url, $options), true);
            if (!isset($data['hits']['hits'])) {
                echo "ERROR: Cannot get TraitBank community records [$url]\n";
                return false; // existing CSV is left untouched
            }
            $hits = array_merge($hits, $data['hits']['hits']);
            $url  = $data['links']['next'] ?? null;
        }

        // Step 2: one row per dataset, keyed by the permanent headers
        $rows = array();
        foreach ($hits as $h) {
            $m = $h['metadata'] ?? array();
            $creators = array();
            foreach ($m['creators'] ?? array() as $c) {
                $creators[] = $c['name'] . (!empty($c['affiliation']) ? " ({$c['affiliation']})" : '');
            }
            $related = array();
            foreach ($m['related_identifiers'] ?? array() as $r) {
                $related[] = ($r['relation'] ?? '') . ':' . ($r['scheme'] ?? '') . ':' . ($r['identifier'] ?? '');
            }
            $files = $h['files'] ?? array();
            $rows[] = array(
                'concept_id'          => $h['conceptrecid'] ?? '',
                'record_id'           => $h['id'] ?? '',
                'title'               => $m['title'] ?? '',
                'version'             => $m['version'] ?? '',
                'publication_date'    => $m['publication_date'] ?? '',
                'created'             => $h['created'] ?? '',
                'updated'             => $h['updated'] ?? '',
                'doi'                 => $h['doi'] ?? '',
                'concept_doi'         => $h['conceptdoi'] ?? '',
                'resource_type'       => $m['resource_type']['type'] ?? '',
                'access_right'        => $m['access_right'] ?? '',
                'license'             => $m['license']['id'] ?? '',
                'creators'            => implode('; ', $creators),
                'related_identifiers' => implode('; ', $related),
                'file_count'          => count($files),
                'file_names'          => implode('; ', array_column($files, 'key')),
                'total_size_bytes'    => array_sum(array_column($files, 'size')),
                'record_url'          => $h['links']['self_html'] ?? '',
                'concept_url'         => $h['links']['parent_html'] ?? '',
                'archive_url'         => $h['links']['archive'] ?? '',
                'views'               => $h['stats']['views'] ?? '',
                'unique_views'        => $h['stats']['unique_views'] ?? '',
                'downloads'           => $h['stats']['downloads'] ?? '',
                'unique_downloads'    => $h['stats']['unique_downloads'] ?? '',
                'description'         => html_entity_decode(strip_tags($m['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Step 3: write to a .tmp file, then replace the real CSV
        $dir  = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files/";
        $file = $dir . "TraitBank_datasets.csv";
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            echo "Cannot create folder [$dir]\n";
            return false;
        }
        if (!($fh = fopen($file . ".tmp", "w"))) {
            echo "Cannot write to [$file.tmp]\n";
            return false;
        }
        // fputcsv quotes fields containing commas/quotes; escape '' = standard CSV (RFC 4180) double-quote escaping
        fputcsv($fh, self::TRAITBANK_DATASETS_CSV_HEADERS, ',', '"', '');
        foreach ($rows as $row) {
            $values = array();
            foreach (self::TRAITBANK_DATASETS_CSV_HEADERS as $col) { // column order always follows the headers
                $values[] = trim(preg_replace('/\s+/u', ' ', (string) ($row[$col] ?? ''))); // no newlines inside a field: one dataset per line
            }
            fputcsv($fh, $values, ',', '"', '');
        }
        fclose($fh);
        rename($file . ".tmp", $file);
        chmod($file, 0644);
        echo "Saved " . count($rows) . " datasets to [TraitBank_datasets.csv]\n";
        return $file;
    }
    function refresh_all_TraitBank_datasets()
    {   /* Refreshes our local copies (TB_files/[concept_id]_[title]/input_files/) of all datasets of the Zenodo 'traitbank' community,
           i.e. the files found by get_generic_file_path($concept_id, 'traits|taxon|occurrences|...').
        A dataset is downloaded only if our copy is older than Zenodo's latest version, or missing; otherwise it is skipped.
        Default: community datasets only. $this->param options:
            'include_Terms_YN'      => 1: also Terms (22776577)
            'include_References_YN' => 1: also References (22776838). Our master references.tsv is kept; Zenodo's copy is saved beside it
                                          and a merge is suggested if it has changes we don't (see merge_zenodo_references)
            'dry_run_YN'            => 1: only report which datasets are up to date / outdated / missing; download nothing
            'only'                  => refresh only these, e.g. "Terms", "References", "23066982", or several: "Terms,References"
                                       (names: Terms, References, DH; or any concept_id of the community). The include_ options are then ignored.
        DH (22776209) is never included, unless named in 'only'.
        Returns array('up to date' => [...], 'refreshed' => [...], 'failed' => [...], 'outdated' => [...] (dry run only)), or false. */
        $main = array('Terms' => 22776577, 'References' => 22776838, 'DH' => 22776209);
        $skip = array($main['DH']);
        if (empty($this->param['include_Terms_YN']))      $skip[] = $main['Terms'];
        if (empty($this->param['include_References_YN'])) $skip[] = $main['References'];
        $dry_run = !empty($this->param['dry_run_YN']);
        $only = array(); // concept_ids; empty = all, except $skip
        if (!empty($this->param['only'])) {
            $names = array_change_key_case($main, CASE_LOWER);
            foreach (preg_split('/\s*,\s*/', trim(implode(',', (array) $this->param['only']))) as $item) {
                if ($item === '') continue;
                if (isset($names[strtolower($item)])) $only[] = $names[strtolower($item)];
                elseif (ctype_digit($item))          $only[] = (int) $item;
                else { echo "ERROR: Unknown dataset in 'only' [$item]; use Terms, References, DH or a concept_id\n"; return false; }
            }
        }

        // 1. dataset list, always fresh from Zenodo
        if (!($csv = $this->generate_Zenodo_TraitBank_datasets_inCSV())) { echo "ERROR: Cannot get the TraitBank datasets list from Zenodo\n"; return false; }
        $datasets = array(); // concept_id => array(record_id, title)
        $fh = fopen($csv, "r");
        $fields = fgetcsv($fh, 0, ',', '"', '');
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $rec = array_combine($fields, array_pad($row, count($fields), ''));
            if ($only ? !in_array((int) $rec['concept_id'], $only) : in_array((int) $rec['concept_id'], $skip)) continue;
            $datasets[$rec['concept_id']] = array('record_id' => (string) $rec['record_id'], 'title' => $rec['title']);
        }
        fclose($fh);
        if ($missing = array_diff($only, array_map('intval', array_keys($datasets)))) {
            echo "ERROR: Not in the TraitBank community on Zenodo: [" . implode("], [", $missing) . "]. Nothing was downloaded.\n";
            return false;
        }
        echo "\nTraitBank datasets to refresh: " . count($datasets) . ($dry_run ? " (dry run, nothing is downloaded)" : '') . "\n";

        // 2. compare each local copy with Zenodo's latest version; download only the outdated or missing ones
        $ret = array('up to date' => array(), 'refreshed' => array(), 'failed' => array());
        if ($dry_run) $ret['outdated'] = array();
        $dir = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files/";
        foreach ($datasets as $concept_id => $d) {
            $label       = "$concept_id {$d['title']}";
            $record_file = $dir . "$concept_id.record_id";
            $local       = is_file($record_file) ? trim(file_get_contents($record_file)) : '';
            $input_dir   = $dir . $this->get_dataset_folder_name($concept_id, $d['title']) . "/input_files/";
            $has_files   = is_dir($input_dir) && count(scandir($input_dir)) > 2;
            if ($local === $d['record_id'] && $has_files) {
                echo "Up to date: [$label] (record $local)\n";
                $ret['up to date'][] = $label;
                continue;
            }
            $why = !$local ? "not downloaded yet" : ($local !== $d['record_id'] ? "local record $local, latest {$d['record_id']}" : "no files in input_files/");
            if ($dry_run) { echo "Outdated:   [$label] ($why)\n"; $ret['outdated'][] = $label; continue; }

            echo "\n--- Refreshing [$label] ($why) ---\n";
            $this->input_dir = null;
            $this->get_zenodo_info_using_conceptID($concept_id, 0); // fresh record info, so do_zenodo_stuff() below doesn't use a cached older version
            $this->do_zenodo_stuff($concept_id);                   // note: exits on a fatal download error
            $now = is_file($record_file) ? trim(file_get_contents($record_file)) : '';
            if ($now === $d['record_id'] && $this->input_dir && count(scandir($this->input_dir)) > 2) $ret['refreshed'][] = $label;
            else { echo "ERROR: [$label] is still not at the latest version ({$d['record_id']})\n"; $ret['failed'][] = $label; }
        }

        // 3. summary
        echo "\n=== Summary ===\n";
        foreach ($ret as $status => $labels) echo str_pad($status . ':', 12) . count($labels) . ($labels ? "\n    " . implode("\n    ", $labels) : '') . "\n";
        return $ret;
    }
    function abbreviate_title($str)
    {   // e.g. "Biochemistry and Natural Products" -> "Biochemistry_and_Natu_Prod" (1st word complete, first 4 chars of every other word, joined by "_")
        //      "Fungal ecology, trait records and taxonomic metadata" -> "Fungal_ecol_trai_reco_and_taxo_meta"
        // accents removed, letter kept: "Écologie Végétale" -> "Ecologie_Vege"
        if (class_exists('Transliterator')) $str = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')->transliterate($str);
        else                                $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str); // PHP intl extension not installed (e.g. our Docker container)
        // characters that break folder paths act as word separators, so they become "_": "Plants/Fungi" -> "Plants_Fung"
        $str = preg_replace('#[/\\\\:*?"<>|\x00-\x1F]#u', ' ', $str);
        $str = preg_replace('/^\.+/', '', trim($str)); // no leading dots: avoids hidden folders and "." / ".."
        $words = preg_split('/\s+/u', trim($str), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) return '';
        $first = rtrim(array_shift($words), ',;.'); // 1st word complete, minus trailing punctuation e.g. "Fungi," -> "Fungi"
        return implode('_', array_merge(array($first), array_map(fn($w) => mb_substr($w, 0, 4), $words)));
    }
    function get_dataset_folder_name($concept_id, $title)
    {   // e.g. (23067562, "Biochemistry and Natural Products") -> "23067562_Bioc_and_Natu_Prod"
        $abbrev = $this->abbreviate_title((string) $title);
        return $concept_id . ($abbrev !== '' ? "_$abbrev" : '');
    }
    function get_generic_file_path($concept_id, $basename, $processedYN = false)
    {   // e.g. (23067562, 'traits') -> [CONTENT_RESOURCE_LOCAL_PATH]/TB_files/23067562_Bioc_and_Natu_Prod/input_files/traits.tsv
        // $basename: file name without extension, e.g. 'traits', 'taxon', 'occurrences' -- finds [basename].tsv or [basename].txt
        //            (extension varies per dataset: taxon.tsv vs taxon.txt); if both exist, .tsv is used
        //            a name with extension, e.g. 'occurrences.txt', is also accepted and matched exactly
        //            if neither exists, a versioned name is also a valid hit, e.g. 'terms' -> terms_0.9.tsv (highest version wins)
        //            and so is a numbered copy, e.g. 'taxon' -> 'taxon (2).tsv' (name given by a browser when the file was downloaded twice)
        //            'occurrence' and 'occurrences' are interchangeable: either one finds occurrence.tsv|.txt or occurrences.tsv|.txt
        // folder name uses the current title (cached lookup), the same way do_zenodo_stuff() names it when extracting
        if (!($arr = $this->get_zenodo_info_using_conceptID($concept_id))) return false;
        $folder_name = $this->get_dataset_folder_name($concept_id, $arr['title']);  //e.g. '23067562_Bioc_and_Natu_Prod'               -> raw files from Zenodo
        if($processedYN) $folder_name .= '_TraitBank';                          //e.g. '23067562_Bioc_and_Natu_Prod_TraitBank' -> prod of name-matching already
        $dir = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files/$folder_name/input_files/";
        if (is_file($dir . $basename)) return $dir . $basename; // exact name given, e.g. 'occurrences.txt'
        $aliases = array('occurrence' => array('occurrence', 'occurrences'), 'occurrences' => array('occurrences', 'occurrence'));
        $candidates = array();
        foreach ($aliases[$basename] ?? array($basename) as $name) { $candidates[] = "$name.tsv"; $candidates[] = "$name.txt"; } // the given name first, .tsv first = preferred
        $found = array_values(array_filter($candidates, fn($f) => is_file($dir . $f)));
        if ($found && basename($found[0], '.' . pathinfo($found[0], PATHINFO_EXTENSION)) !== $basename) echo "Using [$found[0]] for [$basename]\n";
        if (!$found && ($versioned = self::find_versioned_file($dir, $aliases[$basename] ?? array($basename)))) {
            if (!preg_match('/ \(\d+\)\.(tsv|txt)$/', $versioned)) echo "Using versioned file [$versioned] for [$basename]\n"; // shown for e.g. terms_0.9.tsv; not for numbered copies e.g. 'taxon (2).tsv'
            return $dir . $versioned;
        }
        if (!$found) {
            echo "[" . implode("] or [", $candidates) . "] (or e.g. [{$basename}_0.9.tsv] or [$basename (2).tsv]) not found in [$folder_name/input_files/], run do_zenodo_stuff($concept_id) first\n";
            return false;
        }
        if (count($found) > 1) echo "Several of [" . implode("], [", $found) . "] exist in [$folder_name/input_files/], using [$found[0]]\n";
        if (!$processedYN && in_array($found[0], self::PROTECTED_INPUT_FILES[(string) $concept_id] ?? array())) {
            $this->check_unmerged_zenodo_copies($concept_id, $found[0], $dir . $found[0]); // suggests a merge if Zenodo's copy has changes we don't
        }
        return $dir . $found[0];
    }
    private function find_versioned_file($dir, $basenames)
    {   // e.g. ($dir, array('terms')) -> 'terms_0.9.tsv'; ($dir, array('taxon')) -> 'taxon (2).tsv'
        // matches [basename]_[version].tsv|.txt where version is like 1, 0.9, 1.2.3
        //     and [basename] ([n]).tsv|.txt -- a numbered copy, e.g. a browser's name for a file downloaded twice
        // $basenames: the name and its aliases, e.g. array('occurrence', 'occurrences')
        // if several match: versioned names before numbered copies, then the highest version/number, then .tsv before .txt
        $names = implode('|', array_map(fn($b) => preg_quote($b, '/'), (array) $basenames));
        $hits = array();
        foreach (@scandir($dir) ?: array() as $f) { // @: folder may not exist yet
            if (!preg_match('/^(?:' . $names . ')(?:_(\d+(?:\.\d+)*)| \((\d+)\))\.(tsv|txt)$/', $f, $m)) continue;
            $copyYN = ($m[1] === '');
            $hits[] = array('file' => $f, 'kind' => $copyYN ? 1 : 0, 'version' => $copyYN ? $m[2] : $m[1], 'ext' => $m[3]);
        }
        if (!$hits) return false;
        usort($hits, fn($a, $b) => ($a['kind'] <=> $b['kind']) ?: version_compare($b['version'], $a['version']) ?: strcmp($a['ext'], $b['ext']));
        if (count($hits) > 1) echo "Several versions/copies of [" . implode('|', (array) $basenames) . "] in [$dir]: " . implode(', ', array_column($hits, 'file')) . "\n";
        return $hits[0]['file'];
    }
    function get_records_from_input_file($folder_name, $filename = null)
    {   // e.g. ('22776578_Term', 'terms_0.9.tsv') -> $output[0] = array('attribution' => ..., 'definition' => ..., 'name' => ..., ...)
        // $filename: optional, if null and input_files/ has exactly one .tsv file, that file is used
        $dir = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files/$folder_name/input_files/";
        if (!is_dir($dir)) {
            echo "Folder not found [$folder_name/input_files/]\n";
            return false;
        }
        if ($filename === null) {
            $tsv_files = array_map('basename', glob($dir . "*.tsv"));
            if (count($tsv_files) !== 1) {
                echo "Please specify a filename, found " . count($tsv_files) . " .tsv files in [$folder_name/input_files/]: " . implode(", ", $tsv_files) . "\n";
                return false;
            }
            $filename = $tsv_files[0];
        }
        if (!($fh = @fopen($dir . $filename, "r"))) {
            echo "Cannot open [$folder_name/input_files/$filename]\n";
            return false;
        }

        $output = array(); $headers = null; $line_no = 0; $mismatch_reported = false;
        while (($line = fgets($fh)) !== false) {
            $line_no++;
            $line = rtrim($line, "\r\n"); // files may have Windows (CRLF) line endings
            if ($line === '') continue;
            if ($headers === null) { // first line: headers
                $line    = preg_replace('/^\xEF\xBB\xBF/', '', $line); // strip UTF-8 BOM
                $headers = array_map('trim', explode("\t", $line));
                continue;
            }
            $values = explode("\t", $line);
            if (count($values) !== count($headers)) {
                if (!$mismatch_reported) {
                    echo "Warning: line $line_no of [$filename] has " . count($values) . " fields, expected " . count($headers) . " (padded/truncated; further mismatches not reported)\n";
                    $mismatch_reported = true;
                }
                $values = array_slice(array_pad($values, count($headers), ''), 0, count($headers));
            }
            $output[] = array_combine($headers, $values);
        }
        fclose($fh);
        return $output;
    }
    function get_headers($concept_id, $basename, $processedYN = false, $shortNamesYN = true)
    {   // e.g. (22943003, 'taxon')  -> headers of TB_files/22943003_Palm/input_files/taxon.txt
        //      (22943003, 'traits') -> headers of TB_files/22943003_Palm/input_files/traits.tsv
        // $processedYN: true reads from the [folder]_TraitBank/input_files/ folder instead, same as get_generic_file_path()
        // $shortNamesYN: true returns short names via small_field(), e.g. 'http://rs.tdwg.org/dwc/terms/TaxonID' -> 'taxonID'
        if (!($file = $this->get_generic_file_path($concept_id, $basename, $processedYN))) return false;
        if (!($fh = @fopen($file, "r"))) {
            echo "Cannot open [$file]\n";
            return false;
        }
        $headers = false;
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;
            $line    = preg_replace('/^\xEF\xBB\xBF/', '', $line); // strip UTF-8 BOM
            $headers = array_map('trim', explode("\t", $line));
            break;
        }
        fclose($fh);
        if ($headers && $shortNamesYN) $headers = array_map(fn($h) => $this->small_field($h), $headers);
        if ($headers) $headers = array_map(fn($h) => $h === 'tb:taxonKey' ? 'taxonKey' : $h, $headers); // also when $shortNamesYN is false
        return $headers;
    }
    function insert_array($arr, $value, $after)
    {   // e.g. (array('a', 'b', 'd'), 'c', 'b') -> array('a', 'b', 'c', 'd')
        // $value: what to add; $after: the existing value to add it after (first match)
        // if $after is not in $arr, $value is appended at the end
        $pos = array_search($after, $arr, true);
        if ($pos === false) {
            echo "[$after] not found, [$value] appended at the end\n";
            $arr[] = $value;
            return $arr;
        }
        $index = array_search($pos, array_keys($arr), true); // position in the list, works for any keys
        array_splice($arr, $index + 1, 0, array($value));
        return $arr;
    }
    function list_files_row_counts($concept_id)
    {   // e.g. 23067562 -> lists files and row counts (excluding header line) in:
        //   TB_files/23067562_Bioc_and_Natu_Prod/input_files/
        //   TB_files/23067562_Bioc_and_Natu_Prod_TraitBank/input_files/   (only folders that exist)
        // returns array('23067562_Bioc_and_Natu_Prod' => array('taxon.tsv' => 512, ...), ...) or false
        if (!($title = $this->get_zenodo_title_using_conceptID($concept_id))) return false;
        $base   = $concept_id . "_" . $this->abbreviate_title($title);
        $tb_dir = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/TB_files/";
        $output = array();
        foreach (array($base, "{$base}_TraitBank") as $folder_name) {
            $dir = $tb_dir . "$folder_name/input_files/";
            if (!is_dir($dir)) {
                echo "Folder not found, skipped [$folder_name/input_files/]\n";
                continue;
            }
            echo "\n[$folder_name/input_files/]\n";
            foreach (glob($dir . "*") as $file) { // glob skips hidden files e.g. .DS_Store
                if (!is_file($file)) continue;
                if (!($fh = @fopen($file, "r"))) {
                    echo "Cannot open [$folder_name/input_files/" . basename($file) . "]\n";
                    continue;
                }
                $rows = 0;
                while (($line = fgets($fh)) !== false) {
                    if (rtrim($line, "\r\n") !== '') $rows++; // blank lines not counted, same as get_records_from_input_file()
                }
                fclose($fh);
                $rows = max(0, $rows - 1); // exclude header line
                $output[$folder_name][basename($file)] = $rows;
                echo "  " . str_pad(basename($file), 30) . number_format($rows) . "\n";
            }
        }
        return $output;
    }
    function small_field($uri)
    {
        $uri = str_replace("TaxonID", "taxonID", $uri);
        $uri = str_replace("tb:taxonKey", "taxonKey", $uri);
        $uri = str_replace("tb:tbKey", "taxonKey", $uri);
        $uri = str_replace("tb:taxonMap", "taxonMap", $uri);
        // URI with a #fragment: the name is after the '#', e.g. 'http://ncicb.nci.nih.gov/xml/owl/EVS/Thesaurus.owl#Standard_Deviation' -> 'Standard_Deviation' (not 'Thesaurus')
        if (($pos = strrpos($uri, '#')) !== false && $pos < strlen($uri) - 1) return trim(substr($uri, $pos + 1));
        return trim(pathinfo($uri, PATHINFO_FILENAME));
    }
}
?>