<?php
namespace php_active_record;
/* Library that aggregates CSV files to generate a single set of CSV files for import to a graph database.
cypher quere: 
MATCH p=()-[:SUPPLIER]->(r: Resource {}) RETURN DISTINCT r.name
MATCH p=(t: Trait)-[:SUPPLIER]->(r: Resource {}) RETURN DISTINCT r.name as `Resource`,  COUNT(t.eol_pk) as total_traits ORDER BY total_traits DESC
habitat values:
MATCH (:Trait)-[:OBJECT_TERM]->(t:Term) WHERE t.uri =~ ".*\\/ENVO.*" WITH t, count(*) AS count RETURN t.name, t.uri, count ORDER BY count desc
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class AggregateCSV_4Neo4j
{
    function __construct() {
        $this->path['main'] = CONTENT_RESOURCE_LOCAL_PATH . 'neo4j_imports';
        $this->path['stats'] = CONTENT_RESOURCE_LOCAL_PATH . 'neo4j_stats'; //old path
        $this->path['stats'] = DOC_ROOT . 'applications/content_server/neo4j_stats';

        $this->path['combined_dir'] = $this->path['main'].'/combined_CSVs';
        $this->files_with_single_write = array('Resource.csv', 'Page.csv', 'Term.csv', 'PARENT.csv', 'PARENT_TERM.csv', 'SYNONYM_OF.csv');
    }
    function start()
    {
        self::initialize();
        $folders = Functions::get_folders($this->path['main'], "TraitBank_1_0");
        print_r($folders);
        foreach($folders as $folder) {
            $subfolders = Functions::get_folders($folder);
            print_r($subfolders);
            foreach($subfolders as $subfolder) {
                self::process_a_subfolder($subfolder, $folder); // /edges or /nodes ;  2nd param $folder is just for stats
            }
        }
        self::get_totals_for_combined_CSVs();
        Functions::start_print_debug($this->report_write, 'CSV_report', $this->path['main']);
        self::write_csv_logs();
    }
    private function process_a_subfolder($subfolder, $folder) //2nd param $folder is just for stats
    {
        $files = Functions::get_files($subfolder, '*.csv');
        $subfolder_name = basename($subfolder); //e.g. 'edges'
        $resource_name = basename($folder);
        echo "\n[".$resource_name."] CSV files [$subfolder_name]:\n"; //e.g. [GloBI_TraitBank_1_0_csv] CSV files [edges]:
        $this->report = array();
        $this->report['resource_name'] = $resource_name;
        $this->report['what'] = $subfolder_name;
        print_r($files);
        foreach($files as $source) {
            $destination = $this->path['combined_dir']."/$subfolder_name/".basename($source); // /var/www/html/eol_php8_code/applications/content_server/resources_3/neo4j_imports/combined_CSVs/edges/CONTRIBUTOR.csv
            
            // /* ---------- for stats report
            $main = basename($this->path['combined_dir']); //'combined_CSVs'
            $filename = basename($source); //'CONTRIBUTOR.csv'
            $this->report_write[$main][$subfolder_name][$filename] = $destination; //e.g. ['combined_CSVs']['edges']['CONTRIBUTOR.csv'] = $destination
            // ---------- */

            echo "\ndestination: ".$destination;
            self::write_file($source, $destination);
        }
    }
    private function write_file($source, $destination)
    {
        if(!is_file($destination))  self::append_file($source, $destination, true); //3rd param $newFile_YN
        else {
            $basename = basename($destination);
            if(self::is_file_tobe_excluded_YN($basename)) {}
            else self::append_file($source, $destination, false); //3rd param $newFile_YN
        }

        // /* ---------- for stats report
        $this->report['filename'] = basename($source); //print_r($this->report);
        // Array(
        //     [resource_name] => WoRMS_TraitBank_1_0_csv
        //     [what] => edges
        //     [filename] => STATISTICAL_METHOD_TERM.csv
        // )
        $resource_name = $this->report['resource_name'];
        $what = $this->report['what'];
        $filename = $this->report['filename'];
        if(!isset($this->report_write[$resource_name][$what][$filename])) {
            $total = Functions::show_totals($source) - 1;
            $this->report_write[$resource_name][$what][$filename] = $total;
            echo "\nsource: [$source]";
            echo "\nTotal rows: [$resource_name][$what][$filename] = $total\n";
        }
        // ---------- */

    }
    private function is_file_tobe_excluded_YN($haystack)
    {
        $needles = $this->files_with_single_write; //array('Resource.csv', 'Page.csv', 'Term.csv');
        foreach($needles as $needle) {
            // Case-sensitive check
            // echo "\nneedle: [$needle] | haystack: [$haystack]\n";
            if(str_contains($haystack, $needle)) return true; //echo "Found case-sensitive.";
        }
        return false;
    }
    private function append_file($source, $destination, $newFile_YN)
    {
        $masterCSVFile = fopen($destination, "a"); // Open and write the master CSV file
        if (($handle = fopen($source, 'r')) !== false) {
            if(!$newFile_YN) fgetcsv($handle); // Skip the first row (header)
            // Collect CSV each row records and write to master file
            while (($dataValue = fgetcsv($handle)) !== false) {
                fputcsv($masterCSVFile, $dataValue); // Write the row to the master file
            }
            fclose($handle); // Close individual CSV file
        }
        fclose($masterCSVFile); // Close master CSV file
        echo "\nSuccessfully merged ".basename($source)." into ".basename($destination); //e.g. Successfully merged CONTRIBUTOR.csv into CONTRIBUTOR.csv

        /* ---------- for stats report
        $this->report['filename'] = basename($source); //print_r($this->report);
        // Array(
        //     [resource_name] => WoRMS_TraitBank_1_0_csv
        //     [what] => edges
        //     [filename] => STATISTICAL_METHOD_TERM.csv
        // )
        $resource_name = $this->report['resource_name'];
        $what = $this->report['what'];
        $filename = $this->report['filename'];
        if(!isset($this->report_write[$resource_name][$what][$filename])) {
            $total = Functions::show_totals($source) - 1;
            $this->report_write[$resource_name][$what][$filename] = $total;
            echo "\nsource: [$source]";
            echo "\nTotal rows: [$resource_name][$what][$filename] = $total\n";
        }
        ---------- */
    }
    private function combine_csv_files() //PHP Script for Combining Multiple CSV Files
    {   /* Script to merge multiple CSV files into one master CSV file, removing the header line from individual files.
        Key Functions Used
            -glob($pattern): Finds all the file paths matching the pattern (e.g., all .csv files in a directory).
            -fopen($filename, $mode): Opens a file stream. The "w+" mode creates a new file for reading and writing, or truncates it if it already exists.
            -fgetcsv($handle): Reads a line from the file pointer and parses it into an indexed array. The first call is used to simply skip the header line.
            -fputcsv($handle, $array): Formats an array as a CSV line and writes it to the file pointer.
        Important Considerations
            -Memory Usage: The provided script is efficient as it processes files line by line, keeping memory usage low, which is vital for large CSV files.
            -Header Handling: The script assumes all source files have an identical header in the first line and explicitly skips it. The destination file will contain only the data rows appended sequentially.
            -File Naming: Ensure your destination file name (master-record.csv) is not included in the glob() pattern, or the script may enter an infinite loop trying to append to itself. 
        */
        $directory = "csv_files/*.csv"; // Directory path to the source CSV files
        $masterCSVFile = fopen('master-record.csv', "w+"); // Open and write the master CSV file
        // Process each CSV file inside the specified directory
        foreach (glob($directory) as $file) { // Open and Read individual CSV file
            if (($handle = fopen($file, 'r')) !== false) {
                fgetcsv($handle); // Skip the first row (header)
                // Collect CSV each row records and write to master file
                while (($dataValue = fgetcsv($handle)) !== false) {
                    fputcsv($masterCSVFile, $dataValue); // Write the row to the master file
                }
                fclose($handle); // Close individual CSV file
            }
        }
        fclose($masterCSVFile); // Close master CSV file
        echo "Successfully merged all CSV files into master-record.csv";
    }
    private function get_totals_for_combined_CSVs()
    {   /* These are the variables to be used:
        $this->report_write[$main][$subfolder_name][$filename] = $destination;
        e.g. ['combined_CSVs']['edges']['CONTRIBUTOR.csv'] = $destination */
        echo "\nStart get_totals_for_combined_CSVs()...\n";
        $arr = $this->report_write['combined_CSVs'];
        foreach($arr as $path => $filenames) {
            //echo "\n-----\npath: $path\n"; print_r($filenames); //good debug
            foreach($filenames as $filename => $destination) { //echo "\n[$filename] [$destination]"; //good debug
                $this->report_write['combined_CSVs'][$path][$filename] = Functions::show_totals($destination) - 1;
            }
        }
    }
    private function write_csv_logs()
    {
        $arr = $this->report_write;
        $resource_names = array_keys($arr);
        $resource_names = array_unique($resource_names); //make unique
        $resource_names = array_values($resource_names); //reindex key
        foreach($resource_names as $resource_name) { echo "\n-----Resource: [$resource_name]";
            $save_path = $this->path['stats'].'/'.$resource_name.'.tsv';
            $a = $arr[$resource_name];
            $values = array(); $headers = array();
            $headers[] = 'Date';
            $values[] = date('Y-m-d H:i:s A');
            foreach($a as $path => $filenames) {
                ksort($filenames); //important
                echo "\npath: $path";
                foreach($filenames as $fname => $total) {
                    echo "\n[$fname] [total = $total]";
                    $headers[] = $fname;
                    $values[] = $total;
                }
            }
            if(!file_exists($save_path)) {
                $WRITE = Functions::file_open($save_path, 'a');
                array_unshift($filenames, "Date"); //add an element on the start of an array
                fwrite($WRITE, implode("\t", $headers)."\n");
            }
            $WRITE = Functions::file_open($save_path, 'a');
            fwrite($WRITE, implode("\t", $values)."\n");
            fclose($WRITE);        
        }
    }
    private function initialize()
    {
        $stats_dir = $this->path['stats'];              if(!is_dir($stats_dir)) mkdir($stats_dir);
        $combined_dir = $this->path['combined_dir'];    if(is_dir($combined_dir)) recursive_rmdir($combined_dir);
        mkdir($combined_dir);
        mkdir($combined_dir."/nodes");
        mkdir($combined_dir."/edges");
    }
}
?>