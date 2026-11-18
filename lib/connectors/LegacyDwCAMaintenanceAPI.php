<?php
namespace php_active_record;
/* Library for the Legacy DwCA process: maintenance of the legacy DwCA resources on Zenodo (e.g. AmphibiaWeb, concept ID 13318109).
Called from: update_resources/connectors/process_legacy_dwca.php
Downloads go to [CONTENT_RESOURCE_LOCAL_PATH]/Legacy_DwCA_files/ (the References/TraitBank tasks use TB_files/).
Re-uses the Zenodo download code of ZenodoTraitBankAPI.
----------------------------------------------------
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class LegacyDwCAMaintenanceAPI extends ZenodoTraitBankAPI
{
    function __construct($param) {
        parent::__construct();
        $this->param = $param; print_r($param);
        $this->zenodo_folder = 'Legacy_DwCA_files'; // used by zenodo_local_dir() in ZenodoTraitBankAPI
    }
    function start()
    {   // Runs the task; also returns its result.
        $task = $this->param['task'];
        // one run at a time: a second run waits here until the first one is done. Released when this function returns.
        $lock_dir = $this->zenodo_local_dir();
        if(!is_dir($lock_dir)) @mkdir($lock_dir, 0777, true);
        $lock = @fopen($lock_dir . ".legacy_dwca_mtce.lock", 'c');
        if($lock) { @chmod($lock_dir . ".legacy_dwca_mtce.lock", 0666); flock($lock, LOCK_EX); }
        $result = null;

        if($task == 'download_file_from_zenodo') {
            $result = self::download_file_from_zenodo();
        }
        else echo "\nERROR: Unknown task [$task].\n";

        if($lock) { flock($lock, LOCK_UN); fclose($lock); }
        return $result;
    }
    function download_file_from_zenodo()
    {   // e.g. concept_id 13318109 (AmphibiaWeb): Zenodo's latest version -> Legacy_DwCA_files/13318109_AmphibiaWeb_Amph_text_w_trai_base_on_Pens_Anno/input_files/Archive.zip
        // Downloads only if Zenodo's latest version differs from ours (Legacy_DwCA_files/[concept_id].record_id). Not extracted.
        // Optional "redownload_zip_file_YN": 1 -> asks Zenodo for its latest version now (not from the 1-day cache).
        $concept_id = trim((string) @$this->param['concept_id']);
        if(!preg_match('/^\d+$/', $concept_id)) exit("\nERROR: Missing or invalid \"concept_id\", e.g. \"concept_id\": \"13318109\".\n");

        // Step 1: get latest Zenodo record info using the concept ID
        if(!($arr = $this->get_zenodo_info_using_conceptID($concept_id))) exit("\nERROR: Cannot get Zenodo info for concept ID [$concept_id].\n");
        echo "\nZenodo: [{$arr['title']}] concept ID $concept_id, latest record {$arr['id']}" . ($arr['version'] ? ", version {$arr['version']}" : '') . "\n";
        // folder name: [concept_id]_[supplied_name] e.g. "supplied_name": "AmphibiaWeb" -> 13318109_AmphibiaWeb
        // without supplied_name: [concept_id]_[abbreviated title] e.g. 13318109_AmphibiaWeb_Amph_text_w_trai_base_on_Pens_Anno
        $supplied_name = trim((string) @$this->param['supplied_name']);
        if($supplied_name !== '') {
            if(!preg_match('/^[A-Za-z0-9_-]+$/', $supplied_name)) exit("\nERROR: \"supplied_name\" [$supplied_name] may only have letters, digits, _ and -, e.g. \"supplied_name\": \"AmphibiaWeb\".\n");
            $folder_name = $concept_id . "_" . $supplied_name;
        }
        else $folder_name = $this->get_dataset_folder_name($concept_id, $arr['title']);

        // Step 2, 1st option: the latest version's zip archive -> Legacy_DwCA_files/[concept_id].zip (not extracted)
        if($arr['archive_url'] && ($zip_file = $this->download_zenodo_zip_file($arr['archive_url'], $concept_id))) {
            $paths = array($zip_file);
        }
        // Step 2, 2nd option: no archive_url (e.g. AmphibiaWeb), download the record's files individually
        //         -> Legacy_DwCA_files/[concept_id]_[abbreviated title]/input_files/
        elseif($arr['files']) {
            echo "No usable Zenodo archive URL, will download the files individually.\n";
            if(!($input_dir = $this->download_zenodo_files_individually($arr, $concept_id, $folder_name))) exit("\nERROR: Cannot download Zenodo files individually.\n");
            $paths = array();
            foreach($arr['files'] as $f) $paths[] = $input_dir . basename($f['key']);
        }
        else exit("\nERROR: Zenodo record has no archive URL and no files.\n");

        echo "\nLocal file(s):\n";
        foreach($paths as $path) echo " - $path (" . (is_file($path) ? number_format(filesize($path)) . " bytes" : "missing") . ")\n";

        // Step 3 (optional "file_type", e.g. ".tar.gz"): the final version -> CONTENT_RESOURCE_LOCAL_PATH/[concept_id]_[supplied_name][file_type]
        // e.g. 13318109_AmphibiaWeb.tar.gz. Converted if Zenodo's file is another type (e.g. .zip), else copied as is.
        if(isset($this->param['file_type'])) {
            $file_type = strtolower(trim((string) $this->param['file_type']));
            $final = rtrim(CONTENT_RESOURCE_LOCAL_PATH, '/') . "/" . $folder_name . $file_type;
            if(!($source = self::pick_archive_file($paths))) exit("\nERROR: Cannot tell which downloaded file to save as [$file_type].\n");
            if(!self::save_final_version($source, $final, $file_type, dirname($source, 2) . "/temp_convert/")) exit("\nERROR: Cannot save final version [$final].\n");
            $paths['final'] = $final;
        }
        return $paths;
    }
    const ARCHIVE_TYPES = array('.tar.gz', '.tgz', '.zip'); // supported Zenodo file types; ".tar.gz" and ".zip" as "file_type"
    private function archive_type($file)
    {   // e.g. Archive.zip -> ".zip", x.TAR.GZ -> ".tar.gz", x.tgz -> ".tar.gz"; false if not an archive
        foreach(self::ARCHIVE_TYPES as $type) {
            if(str_ends_with(strtolower($file), $type)) return $type == '.tgz' ? '.tar.gz' : $type;
        }
        return false;
    }
    private function pick_archive_file($paths)
    {   // the downloaded file to save as the final version: the only file, or else the only archive (.zip, .tar.gz, .tgz)
        if(count($paths) == 1) return reset($paths);
        $archives = array_values(array_filter($paths, fn($p) => self::archive_type($p)));
        if(count($archives) == 1) return $archives[0];
        echo "\nThe Zenodo record has " . count($archives) . " archive files among its " . count($paths) . " files:\n - " . implode("\n - ", $paths) . "\n";
        return false;
    }
    private function save_final_version($source, $final, $file_type, $temp_dir)
    {   // $source (e.g. .zip) -> $final (e.g. .tar.gz): copied if same type, else converted (files at the top of the archive, as in our other DwCA .tar.gz files)
        if(!in_array($file_type, array('.tar.gz', '.zip'))) { echo "\n\"file_type\" [$file_type] not supported: use \".tar.gz\" or \".zip\".\n"; return false; }
        if(!($source_type = self::archive_type($source))) { echo "\nDownloaded file [" . basename($source) . "] isn't a .zip or .tar.gz, cannot convert it to [$file_type].\n"; return false; }
        // nothing to do if the final version was made from this same download (a new download is newer)
        if(is_file($final) && filesize($final) > 0 && filemtime($final) >= filemtime($source)) {
            echo "\nFinal version already up to date, no change: [$final]\n";
            return true;
        }
        $part = $final . ".part"; // never leave a half-written final version
        if($source_type == $file_type) {
            $ok = copy($source, $part);
            echo "\nSame type as Zenodo's file [" . basename($source) . "]: copied, no conversion.\n";
        }
        else {
            // extract to a fresh temp folder, without Mac junk (__MACOSX/, ._ files), then pack as $file_type
            if(is_dir($temp_dir)) recursive_rmdir($temp_dir);
            if(!mkdir($temp_dir, 0775, true)) { echo "\nCannot create folder [$temp_dir]\n"; return false; }
            if($source_type == '.zip') $cmd = "unzip -q -o " . escapeshellarg($source) . " -x '__MACOSX/*' '*/.DS_Store' '.DS_Store' -d " . escapeshellarg($temp_dir);
            else                       $cmd = "tar -xzf " . escapeshellarg($source) . " -C " . escapeshellarg($temp_dir) . " --exclude='__MACOSX' --exclude='._*' --exclude='.DS_Store'";
            exec("$cmd 2>&1", $output, $exit_code);
            if($exit_code !== 0) { echo "\nCannot extract [$source]:\n" . implode("\n", $output) . "\n"; recursive_rmdir($temp_dir); return false; }
            if(is_file($part)) unlink($part);
            if($file_type == '.tar.gz') $cmd = "tar -czf " . escapeshellarg($part) . " -C " . escapeshellarg($temp_dir) . " .";
            else                        $cmd = "cd " . escapeshellarg($temp_dir) . " && zip -q -r -X " . escapeshellarg($part) . " .";
            $output = array();
            exec("$cmd 2>&1", $output, $exit_code);
            $ok = ($exit_code === 0);
            if(!$ok) echo "\nCannot pack [$part]:\n" . implode("\n", $output) . "\n";
            else echo "\nConverted [" . basename($source) . "] ($source_type) to $file_type.\n";
            recursive_rmdir($temp_dir);
        }
        if(!$ok || !rename($part, $final)) { if(is_file($part)) unlink($part); return false; }
        chmod($final, 0644);
        echo "Final version saved: [$final] (" . number_format(filesize($final)) . " bytes)\n";
        return true;
    }
}
?>