<?php
namespace php_active_record;
include_once(dirname(__FILE__) . "/../config/environment.php");
$GLOBALS['ENV_DEBUG'] = false; //false orig

echo '<a href="' . WEB_ROOT_OUT .'/applications/dwc_validator/main.php">Archive and Spreadsheet Validator (orig)</a> | <br>';
echo '<a href="' . WEB_ROOT_OUT .'/applications/dwc_validator_jenkins/main.php">Archive and Spreadsheet Validator (Jenkins)</a> | <br>';
echo '<a href="' . WEB_ROOT_OUT .'/applications/validator/main.php">XML File Validator</a> | <br>';

if($GLOBALS['ENV_NAME'] == 'development') echo '<a href="' . WEB_ROOT_OUT .'/applications/xls2dwca/main.php">Excel to EOL Archive Converter</a> | ';
echo '<a href="' . WEB_ROOT_OUT .'/applications/xls2dwca_jenkins/main.php">Excel to EOL Archive Converter (Jenkins)</a> | <br>';

if($GLOBALS['ENV_NAME'] == 'development') echo '<a href="' . WEB_ROOT_OUT .'/applications/genHigherClass/main.php">Generate highClassification Tool</a> | ';
echo '<a href="' . WEB_ROOT_OUT .'/applications/genHigherClass_jenkins/main.php">Generate highClassification Tool (Jenkins)</a> | <br>';

echo '<a href="' . WEB_ROOT_OUT .'/applications/DwC_branch_extractor/main.php">Darwin Core Branch Extractor</a> | ';
echo "{".$GLOBALS['ENV_NAME']."}";
?>