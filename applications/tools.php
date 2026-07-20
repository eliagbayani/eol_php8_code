<?php
namespace php_active_record;
include_once(dirname(__FILE__) . "/../config/environment.php");
$GLOBALS['ENV_DEBUG'] = false; //false orig

echo "<pre>--Footer--</pre>";
echo "<hr>";
echo '<a href="../dwc_validator/main.php">Archive and Spreadsheet Validator (orig)</a> | <br>';
echo '<a href="../dwc_validator_jenkins/main.php">Archive and Spreadsheet Validator (Jenkins)</a> | <br>';
// echo '<a href="../validator/main.php">XML File Validator</a> | <br>';
if($GLOBALS['ENV_NAME'] == 'development') echo '<a href="../xls2dwca/main.php">Excel to EOL Archive Converter</a> | ';
echo '<a href="../xls2dwca_jenkins/main.php">Excel to EOL Archive Converter (Jenkins)</a> | <br>';

if($GLOBALS['ENV_NAME'] == 'development') echo '<a href="../genHigherClass/main.php">Generate highClassification Tool</a> | ';
echo '<a href="../genHigherClass_jenkins/main.php">Generate highClassification Tool (Jenkins)</a> | <br>';

echo '<a href="../DwC_branch_extractor/main.php">Darwin Core Branch Extractor</a> | ';
echo "{".$GLOBALS['ENV_NAME']."}";
?>