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
            echo "Using versioned file [$versioned] for [$basename]\n";
            return $dir . $versioned;
        }
        if (!$found) {
            echo "[" . implode("] or [", $candidates) . "] (or e.g. [{$basename}_0.9.tsv] or [$basename (2).tsv]) not found in [$folder_name/input_files/], run do_zenodo_stuff($concept_id) first\n";
            return false;
        }
        if (count($found) > 1) echo "Several of [" . implode("], [", $found) . "] exist in [$folder_name/input_files/], using [$found[0]]\n";
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