<?php
namespace php_active_record;
/* Library that aggregates CSV files to generate a single set of CSV files for import to a graph database.
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class AggregateCSV_4Neo4j
{
    function __construct() {
        $this->path['main'] = CONTENT_RESOURCE_LOCAL_PATH . 'neo4j_imports';
        $this->path['combined_dir'] = $this->path['main'].'/combined_CSVs';
    }
    function start()
    {
        self::initialize();
        $folders = self::get_folders($this->path['main'], "TraitBank_1_0");
        print_r($folders);
        foreach($folders as $folder) {
            $subfolders = self::get_folders($folder);
            print_r($subfolders);
            foreach($subfolders as $subfolder) {
                self::process_a_subfolder($subfolder); // /edges or /nodes
            }
        }
    }
    private function process_a_subfolder($subfolder)
    {
        $files = self::get_files($subfolder, '*.csv');
        $subfolder_name = basename($subfolder);
        echo "\nCSV files [$subfolder_name]:\n";
        print_r($files);
        foreach($files as $source) {
            $destination = $this->path['combined_dir']."/$subfolder_name/".basename($source);
            echo "\n".$destination;
        }
    }
    private function get_files($folder, $pattern = false)
    {
        if($pattern) $path = $folder . '/'.$pattern;
        else         $path = $folder . '/*';
        $files = glob($folder . '/*.csv');
        return $files;
    }
    private function get_folders($path, $pattern = false)
    {
        if($pattern) $path .= '/*'.$pattern.'*'; // The wildcard '*' is necessary for glob
        else         $path .= "/*";
        $directories = glob($path, GLOB_ONLYDIR); // Get only directories
        return $directories;
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
    private function initialize()
    {
        $combined_dir = $this->path['combined_dir'];
        if(is_dir($combined_dir)) recursive_rmdir($combined_dir);
        mkdir($combined_dir);
        mkdir($combined_dir."/nodes");
        mkdir($combined_dir."/edges");
    }
}
?>