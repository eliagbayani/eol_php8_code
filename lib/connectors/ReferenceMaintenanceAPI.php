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
    function start()
    {
        // $paths = self::get_all_UndefinedRefIDs_files();
        // $paths = self::delete_and_leave_only_the_latest_dates($paths);
        // if($paths) {
        //     print_r($paths);
        //     $tsv = self::save_unique_referenceIDs_2tsv($paths);
        //     echo "\nTSV saved: $tsv\n";
        // }
        // else echo "\nNo undefined referenceIDs recorded on files at the moment.\n";

        $doi = "https://doi.org/10.1111%2Fj.1756-1051.2011.01092.x";
        $doi = "https://doi.org/10.1353/psc.2001.0024";
        $ret = self::get_citation_from_CrossRef($doi);
        echo "\n$ret\n";
    }
    function get_citation_from_CrossRef($doi)
    {   // e.g. "https://doi.org/10.1126/science.233.4770.1314" (also "10.1126/..." or "doi:10.1126/...") ->
        // "Dacey, J. W. H., & Wakeham, S. G. (1986). Oceanic Dimethylsulfide: Production During Zooplankton Grazing on Phytoplankton. Science, 233(4770), 1314-1316. https://doi.org/10.1126/science.233.4770.1314"
        // APA-like; parts missing in CrossRef are left out. Returns false if the DOI is not found in CrossRef.
        $doi = trim((string) $doi);
        $doi = preg_replace('#^(https?://(dx\.)?doi\.org/|doi:\s*)#i', '', $doi);
        if(!preg_match('#^10\.\d{4,}/\S+$#', $doi)) { echo "\nNot a DOI: [$doi]\n"; return false; }

        $options = array('resource_id' => 'crossref', 'expire_seconds' => false, // a published record rarely changes; cache never expires
                         'timeout' => 60, 'download_attempts' => 2, 'download_wait_time' => 1000000,
                         'user_agent' => 'EOL-connector/1.0',
                         'validation_regex' => '"status":"ok"'); // only use cache if it is a real CrossRef record
        $url = "https://api.crossref.org/works/" . rawurlencode($doi);
        if(!($json = Functions::lookup_with_cache($url, $options))) { echo "\nCrossRef: no record for [$doi]\n"; return false; }
        $m = @json_decode($json, true)['message'];
        if(!$m) return false;
        $clean = fn($str) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $str), ENT_QUOTES | ENT_HTML5, 'UTF-8'))); // titles may have <i>, <sub>, &amp;

        // Authors: "Family, G. N." -- or the organization name
        $authors = array();
        foreach(@$m['author'] ?? array() as $a) {
            if($family = $clean(@$a['family'])) {
                $initials = array();
                foreach(preg_split('/\s+/', $clean(@$a['given']), -1, PREG_SPLIT_NO_EMPTY) as $name) { // "John W. H." -> "J. W. H."; "Jean-Paul" -> "J.-P."
                    $initials[] = implode('-', array_map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)) . '.', explode('-', $name)));
                }
                $authors[] = $initials ? "$family, " . implode(' ', $initials) : $family;
            }
            elseif($name = $clean(@$a['name'])) $authors[] = $name;
        }
        if(count($authors) > 1) $authors_str = implode(', ', array_slice($authors, 0, -1)) . ', & ' . end($authors);
        else                    $authors_str = (string) @$authors[0];

        $year      = @$m['issued']['date-parts'][0][0] ?: @$m['published']['date-parts'][0][0];
        $title     = $clean(@$m['title'][0]);
        $container = $clean(@$m['container-title'][0]); // journal, or the book title for a chapter
        $volume    = $clean(@$m['volume']);
        $issue     = $clean(@$m['issue']);
        $pages     = $clean(@$m['page']);
        $publisher = $clean(@$m['publisher']);
        $type      = (string) @$m['type'];

        // Assemble: Authors (Year). Title. Container, Volume(Issue), Pages. [Publisher.] https://doi.org/DOI
        $parts = array();
        if($authors_str) $parts[] = $authors_str . ($year ? " ($year)." : (str_ends_with($authors_str, '.') ? '' : '.')); // keep the last initial's '.', e.g. "S. G. (1986)."
        elseif($year)    $parts[] = "($year).";
        if($title) $parts[] = rtrim($title, '.') . '.';
        $source = $container;
        if($volume) $source .= ($source ? ', ' : '') . $volume . ($issue ? "($issue)" : '');
        elseif($issue) $source .= ($source ? ', ' : '') . "($issue)";
        if($pages) $source .= ($source ? ', ' : '') . $pages;
        if($source) $parts[] = "$source.";
        if($publisher && in_array($type, array('book', 'book-chapter', 'edited-book', 'monograph', 'reference-book', 'report'))) $parts[] = rtrim($publisher, '.') . '.';
        $parts[] = "https://doi.org/" . (@$m['DOI'] ?: $doi);
        return implode(' ', $parts);
    }

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
    private function save_unique_referenceIDs_2tsv($paths)
    {   // save unique (referenceID, concept_id) pairs to TSV, then prefix processed files with "x_"
        $tsv = CONTENT_RESOURCE_LOCAL_PATH . "undefined_referenceIDs_" . date("Ymd") . ".tsv";
        $unique = array();
        foreach($paths as $path) {
            if(!preg_match("/^(\d+)_UndefinedRefIDs_debug_/", basename($path), $arr)) continue;
            $concept_id = $arr[1];
            $section = ''; $prev = '';
            foreach(file($path, FILE_IGNORE_NEW_LINES) as $line) {
                if(substr($prev, 0, 5) == '=====') $section = trim($line);
                $prev = $line;
                if($section != 'undefined referenceID') continue;
                if(!preg_match("/^----- (.+?)\s+total: \d+/", $line, $arr)) continue;
                $refID = trim($arr[1]);
                if($refID == '' || $refID == '#N/A') continue;
                $unique["$refID\t$concept_id"] = '';
            }
        }
        $fhandle = fopen($tsv, "w");
        fwrite($fhandle, "referenceID\tconcept_id\n");
        foreach(array_keys($unique) as $row) fwrite($fhandle, $row . "\n");
        fclose($fhandle);
        foreach($paths as $path) rename($path, dirname($path) . "/x_" . basename($path));
        return $tsv;
    }
}
?>