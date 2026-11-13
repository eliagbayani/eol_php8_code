<?php
namespace php_active_record;
/* Terms Maintenance: a web page for the Terms maintenance task (see update_resources/connectors/do_terms_mtce.php)
   http://localhost:81/eol_php8_code/applications/terms_maintenance/main.php
   - [Check Terms from Google Sheets] = php do_terms_mtce.php _ '{"task": "update_terms_from_googlesheet", "dry_run_YN": 1}'
       QC failed: the stats + [Download] the error report (Terms_file_errors_YYYY-MM-DD-HH-MM.tsv)
       QC passed: the stats + [Upload to Zenodo] (needs the password TERMS_MTCE_PASSWORD, defined in config/environments/<env>.php)
   - Available error reports: the reports kept in Terms_maintenance/ (newest first), each with [Download]
   - [Upload to Zenodo] = php do_terms_mtce.php _ '{"task": "update_terms_from_googlesheet", "publish": 1, "file": "<the checked sheet download>"}'
       i.e. exactly the sheet download that was checked is QC'd again and published -- CANNOT be undone.
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
ini_set('error_reporting', false);
ini_set('display_errors', false);
$GLOBALS['ENV_DEBUG'] = false;
require_library('connectors/ZenodoTraitBankAPI');
require_library('connectors/GenerateTB_Files_Functions');
require_library('connectors/TermsMaintenanceAPI');

$work_dir    = CONTENT_RESOURCE_LOCAL_PATH . "Terms_maintenance/";
$sheet_url   = "https://docs.google.com/spreadsheets/d/" . TermsMaintenanceAPI::GOOGLE_SHEET_ID;
$zenodo_url  = "https://zenodo.org/records/22776577"; // the Terms concept: always resolves to the latest version
$report_re   = '/^Terms_file_errors_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}\.tsv$/';
$sheet_re    = '/^terms_googlesheet_\d{8}_\d{6}\.tsv$/';
$api_class   = defined('TERMS_MTCE_TEST_CLASS') ? TERMS_MTCE_TEST_CLASS : __NAMESPACE__ . '\TermsMaintenanceAPI'; // tests only: a stand-in that never uploads
$has_password = defined('TERMS_MTCE_PASSWORD') && TERMS_MTCE_PASSWORD !== '';

// [Download]: only an error report in Terms_maintenance/, nothing else
if(isset($_GET['download'])) {
    $name = (string) $_GET['download'];
    if(!preg_match($report_re, $name) || !is_file($work_dir . $name)) { http_response_code(404); exit("Not found"); }
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($work_dir . $name));
    readfile($work_dir . $name);
    exit;
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function run_task($api_class, $param)
{   // runs the task in-process; returns array(result array or false, the printed output as plain text)
    set_time_limit(0);
    ob_start();
    try { $func = new $api_class($param); $ret = $func->start(); }
    catch(\Throwable $e) { $ret = false; echo "\nERROR: " . $e->getMessage() . "\n"; }
    $log = ob_get_clean();
    $log = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", $log))); // the library's few HTML line breaks
    return array($ret, $log);
}
function part($log, $from, $to = null)
{   // the text of $log from the line starting with $from, up to (not including) the line starting with $to
    $i = strpos("\n$log", "\n$from"); if($i === false) return '';
    $s = substr($log, $i);
    if($to !== null && ($j = strpos($s, "\n$to")) !== false) $s = substr($s, 0, $j);
    return trim($s);
}

$action = $_POST['action'] ?? '';
$out = ''; // the result HTML
if($action == 'check') {
    [$ret, $log] = run_task($api_class, array('task' => 'update_terms_from_googlesheet', 'dry_run_YN' => 1));
    if(!$ret) $out .= "<p class='bad'><b>The check could not run.</b> See the log below.</p>";
    else {
        $out .= "<pre>" . h(part($log, "QC:", "QC ")) . "</pre>";
        if($ret['errors']) {
            $name = basename((string) $ret['report']);
            $out .= "<p>Once you've fixed the error(s) in Google Sheets, you can click button [Check Terms from Google Sheets] again.</p>";
            $out .= "<p class='bad'><b>QC failed: " . number_format($ret['errors']) . " error(s).</b> Report: [" . h($name) . "] "
                  . "<a class='button' href='main.php?download=" . rawurlencode($name) . "'>Download</a><br>Nothing was uploaded.</p>";
        }
        else {
            $out .= "<p class='good'><b>There are no errors</b>" . ($ret['warnings'] ? " (" . number_format($ret['warnings']) . " warning(s): name+type duplicates already on Zenodo)" : "") . ".</p>";
            if(!empty($ret['identical_to_zenodo'])) {
                $out .= "<p><b>The Zenodo file is already identical to the Google Sheets file.</b> There's nothing to upload. "
                      . "<a href='" . h($zenodo_url) . "' target='_blank'>See it on Zenodo</a></p>"
                      . "<input type='submit' value='Upload to Zenodo' disabled title='The Zenodo file is already identical to the Google Sheets file'>";
            }
            elseif(!$has_password) $out .= "<p class='bad'>Uploading is disabled: TERMS_MTCE_PASSWORD isn't set in config/environments/" . h($GLOBALS['ENV_NAME'] ?? '') . ".php</p>";
            else {
                $out .= "<form method='post' action='main.php' onsubmit=\"return confirm('Publish this Terms file to Zenodo?\\n\\nThis creates a new published version and CANNOT be undone.');\">"
                      . "Do you want to proceed uploading this Terms file to Zenodo: "
                      . "<input type='hidden' name='action' value='upload'>"
                      . "<input type='hidden' name='source' value='" . h(basename((string) $ret['source'])) . "'>"
                      . "<input type='password' name='password' placeholder='password' required autocomplete='off'> "
                      . "<input type='submit' value='Upload to Zenodo'></form>";
            }
        }
    }
    $out .= "<details><summary>Full log</summary><pre>" . h($log) . "</pre></details>";
}
elseif($action == 'upload') {
    $source = basename((string) ($_POST['source'] ?? ''));
    if(!$has_password)                                                 $out .= "<p class='bad'>Uploading is disabled: TERMS_MTCE_PASSWORD isn't set. Nothing was uploaded.</p>";
    elseif(!hash_equals(TERMS_MTCE_PASSWORD, (string) ($_POST['password'] ?? ''))) $out .= "<p class='bad'>Wrong password. Nothing was uploaded.</p>";
    elseif(!preg_match($sheet_re, $source) || !is_file($work_dir . $source)) $out .= "<p class='bad'>The checked file [" . h($source) . "] wasn't found. Please run the check again. Nothing was uploaded.</p>";
    else {
        [$ret, $log] = run_task($api_class, array('task' => 'update_terms_from_googlesheet', 'publish' => 1, 'file' => $work_dir . $source));
        $out .= "<p>Uploaded file: the Google Sheets as checked, [" . h($source) . "]</p>";
        if(!$ret) $out .= "<p class='bad'><b>The upload could not run.</b> See the log below.</p>";
        elseif($ret['errors']) $out .= "<p class='bad'><b>QC failed: " . number_format($ret['errors']) . " error(s).</b> Nothing was uploaded. Please run the check again.</p>";
        elseif($ret['uploaded']) $out .= "<p class='good'><b>Done: " . h($ret['uploaded']) . "</b> -- <a href='" . h($zenodo_url) . "' target='_blank'>see it on Zenodo</a></p>";
        else $out .= "<p class='bad'><b>Nothing was uploaded.</b></p>";
        $out .= "<pre>" . h(part($log, "QC passed", "Result:") ?: part($log, "QC:", "Result:")) . "</pre>";
        $out .= "<details><summary>Full log</summary><pre>" . h($log) . "</pre></details>";
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Terms Maintenance</title>
<style>
    body { font-family: Arial, sans-serif; }
    pre { background: #f4f4f4; padding: 10px; white-space: pre-wrap; word-break: break-all; font-size: 13px; text-align: left; }
    .bad { color: #b00000; } .good { color: #006400; }
    a.button { padding: 2px 10px; border: 1px solid #888; border-radius: 3px; background: #eee; color: #000; text-decoration: none; }
    td { text-align: left; }
</style></head>
<body>
<table border="1" cellpadding="15" cellspacing="1" align="center" width="70%">
    <tr align="center"><td style="text-align:center"><b>Terms Maintenance</td></tr>
<tr align="center"><td>
        <small>The master TraitBank Terms file is in <a href="<?php echo h($sheet_url) ?>" target="_blank">Google Sheets</a>, edited by the data managers.<br><br>
        The button below <b>[Check Terms from Google Sheets]</b>, downloads the file from Google Sheets, builds the Terms file and runs the quality checks (QC). It never uploads anything.<br><br>
        If there are errors, download the report: each problem has its row number in the Google Sheets.<br><br>
        If there are none, you can publish the file on <a href="<?php echo h($zenodo_url) ?>" target="_blank">Zenodo</a> (Terms, concept 22776577). 
        <!--- &nbsp;&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;&nbsp;See also: <a href="../reference_maintenance/main.php">References Maintenance</a>. --->
    </small>
    </td></tr>
    <tr><td style="text-align:center">
        <form method="post" action="main.php" onsubmit="this.querySelector('input[type=submit]').disabled = true; this.querySelector('span').style.display = 'inline';">
            <input type="hidden" name="action" value="check">
            <input type="submit" value="Check Terms from Google Sheets">
            <span style="display:none; color:#008000; font-weight:bold"> Checking... this takes about a minute.</span>
        </form>
    </td></tr>
    <?php if($out) echo "<tr><td>$out</td></tr>"; ?>
    <tr><td>
        <b>Available error reports</b> <small>(newest first; only the newest <?php echo TermsMaintenanceAPI::KEEP_REPORTS ?> are kept)</small>
        <?php
        $reports = is_dir($work_dir) ? array_values(array_filter(scandir($work_dir) ?: array(), fn($f) => preg_match($report_re, $f) && is_file($work_dir . $f))) : array();
        rsort($reports, SORT_STRING); // the timestamp in the name: newest first
        if(!$reports) echo "<p>There are no error reports.</p>";
        else {
            echo "<table cellpadding='4' style='margin-top:8px'>";
            foreach($reports as $f) {
                preg_match('/(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})/', $f, $m);
                echo "<tr><td>" . h($f) . "</td><td>$m[1]-$m[2]-$m[3] $m[4]:$m[5]</td><td>" . number_format(filesize($work_dir . $f) / 1024) . " KB</td>"
                   . "<td><a class='button' href='main.php?download=" . rawurlencode($f) . "'>Download</a></td></tr>";
            }
            echo "</table>";
        }
        ?>
    </td></tr>
</table>
</body></html>
