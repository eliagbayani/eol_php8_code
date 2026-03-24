<?php
namespace php_active_record;
/* Library that aggregates CSV files to generate a single set of CSV files for import to a graph database.
*/
use \AllowDynamicProperties; //for PHP 8.2
#[AllowDynamicProperties] //for PHP 8.2
class CSV_Aggregator_For_Neo4j
{
    function __construct() {}
    function start()
    {

    }
    private function combine_csv_files() //PHP Script for Combining Multiple CSV Files
    {   /*
        Script to merge multiple CSV files into one master CSV file, removing the header line from individual files.
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
}
?>