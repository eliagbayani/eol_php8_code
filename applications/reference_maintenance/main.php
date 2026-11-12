<?php
namespace php_active_record;
/* References Maintenance: a web page for the References maintenance tasks (see update_resources/connectors/do_reference_mtce.php)
   http://localhost:81/eol_php8_code/applications/reference_maintenance/main.php
   The command line keeps working as before; this page runs the same tasks:
   - Status: our master references.tsv vs Zenodo's latest version (References, concept 22776838), the unmappable list
   - Reports (no password): compare_master_with_zenodo, sync_unmappable_identifiers_with_datasets (dry run),
     sync_zenodo_references (dry run), utility_report_duplicate_identifiers_from_master
   - Changes (the maintenance tools password, TERMS_MTCE_PASSWORD in config/environments/<env>.php, + a confirm box):
     sync_unmappable_identifiers_with_datasets, sync_zenodo_references, upload_file_toZenodo ("publish": 1)
   - Data Manager files ([Browse File]): preview, then apply:
     update_master_references_with_datamanager_supplied_file_of_unmappable_identifiers, fix_duplicate_issue_in_master_references
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
ini_set('error_reporting', false);
ini_set('display_errors', false);
$GLOBALS['ENV_DEBUG'] = false;
require_library('connectors/ZenodoTraitBankAPI');
require_library('connectors/GenerateTB_Files_Functions');
require_library('connectors/ReferenceMaintenanceAPI');

const REF_CONCEPT_ID = '22776838';
$res_dir      = CONTENT_RESOURCE_LOCAL_PATH;                                 // where the tasks write their reports (as on the command line)
$upload_dir   = CONTENT_RESOURCE_LOCAL_PATH . "Reference_maintenance/uploads/"; // the Data Manager files uploaded on this page
$zenodo_url   = "https://zenodo.org/records/" . REF_CONCEPT_ID;               // the concept: always resolves to the latest version
$github_url   = "https://github.com/EOL/unmappable_reference_identifiers";
$terms_page   = "../terms_maintenance/main.php";
$api_class    = defined('REFERENCES_MTCE_TEST_CLASS') ? REFERENCES_MTCE_TEST_CLASS : __NAMESPACE__ . '\ReferenceMaintenanceAPI'; // tests only: a stand-in
$check_class  = defined('DATASETS_CHECK_TEST_CLASS') ? DATASETS_CHECK_TEST_CLASS : __NAMESPACE__ . '\ZenodoTraitBankAPI'; // tests only: a stand-in
$has_password = defined('TERMS_MTCE_PASSWORD') && TERMS_MTCE_PASSWORD !== ''; // one "maintenance tools" password for the Terms and References pages
$download_re  = array( // the only files [Download] serves, all in the resources folder
    '/^references_22776838_compare_zenodo_record\d+_\d{8}_\d{6}\.tsv$/',
    '/^references_duplicate_identifiers_\d{8}\.tsv$/',
    '/^unmappable_identifiers_sync_\d{8}_\d{6}\.tsv$/',
    '/^references_22776838_(replaced_citations|removed_duplicates|zenodo_sync)_[\d_-]+\.tsv$/',
    '/^unmappable_identifiers\.tsv$/',
);
$dm_kinds = array( // Data Manager file kinds: task, accepted headers, options (param => label)
    'add'    => array('label' => 'Citations to add (the filled-in unmappable list, or references_add_template.tsv)',
                      'task' => 'update_master_references_with_datamanager_supplied_file_of_unmappable_identifiers',
                      'headers' => array(array('identifier', 'status', 'full_reference'), array('identifier', 'full_reference')),
                      'options' => array('skip_unmappable_update_YN' => 'new references, not on our unmappable list (leave the list as is)',
                                         'replace_existing_YN'       => 'replace citations that differ from the master',
                                         'no_upload_YN'              => "don't upload to Zenodo yet: another change follows")),
    'remove' => array('label' => 'Rows to delete (references_remove_template.tsv)',
                      'task' => 'fix_duplicate_issue_in_master_references',
                      'headers' => array(array('identifier', 'full_reference', 'master_row')),
                      'options' => array('no_upload_YN' => "don't upload to Zenodo yet: another change follows")),
);

function is_downloadable($name) { foreach($GLOBALS['download_re'] as $re) if(preg_match($re, $name)) return true; return false; }

// [Download]: only the report files above
if(isset($_GET['download'])) {
    $name = (string) $_GET['download'];
    if(!is_downloadable($name) || !is_file($res_dir . $name)) { http_response_code(404); exit("Not found"); }
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($res_dir . $name));
    readfile($res_dir . $name);
    exit;
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function run_task($param)
{   // runs a do_reference_mtce.php task in-process; returns array(result, the printed output as plain text)
    set_time_limit(0);
    $class = $GLOBALS['api_class'];
    ob_start();
    try { $func = new $class($param); $ret = $func->start(); }
    catch(\Throwable $e) { $ret = false; echo "\nERROR: " . $e->getMessage() . "\n"; }
    $log = ob_get_clean();
    $log = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", $log)));
    return array($ret, $log);
}
function command_boxes($text)
{   // each "php ..." command line in $text, in its own box with a [Copy] button (to paste on the command line,
    // in the container's update_resources/connectors folder)
    preg_match_all('/^\s*(php [^\n]+?)\s*$/m', $text, $m);
    if(!$m[1]) return '';
    $h = "<p><b>Commands to run</b> (click [Copy], then paste in the container, in <code>update_resources/connectors</code>):</p>";
    foreach(array_unique($m[1]) as $cmd)
        $h .= "<div class='cmd'><button type='button' onclick='copyCmd(this)'>Copy</button> <code>" . h($cmd) . "</code></div>";
    return $h;
}
function log_box($log, $open = false) { return "<details" . ($open ? " open" : "") . "><summary>Full log</summary><pre>" . h($log) . "</pre></details>"; }
function files_in_log($log)
{   // [Download] links for the report files the task wrote (the ones named in its output)
    preg_match_all('#[A-Za-z0-9_.-]+\.tsv#', $log, $m);
    $links = array();
    foreach(array_unique($m[0]) as $name) if(is_downloadable($name) && is_file($GLOBALS['res_dir'] . $name))
        $links[] = h($name) . " <a class='button' href='main.php?download=" . rawurlencode($name) . "'>Download</a>";
    return $links ? "<p>Files: <br>" . implode("<br>", $links) . "</p>" : '';
}
function password_ok(&$out)
{   // the maintenance tools password; adds the reason to $out if it's not OK
    if(!$GLOBALS['has_password']) { $out .= "<p class='bad'>Changes are disabled: the maintenance tools password (TERMS_MTCE_PASSWORD) isn't set in config/environments/" . h($GLOBALS['ENV_NAME'] ?? '') . ".php. Nothing was changed.</p>"; return false; }
    if(!hash_equals(TERMS_MTCE_PASSWORD, (string) ($_POST['password'] ?? ''))) { $out .= "<p class='bad'>Wrong password. Nothing was changed.</p>"; return false; }
    return true;
}
function change_form($action, $button, $confirm, $hidden = array(), $extra = '')
{   // a form for a change: password + confirm box
    $h = "<input type='hidden' name='action' value='" . h($action) . "'>";
    foreach($hidden as $k => $v) $h .= "<input type='hidden' name='" . h($k) . "' value='" . h($v) . "'>";
    return "<form method='post' action='main.php' class='change' onsubmit=\"if(!confirm(" . h(json_encode($confirm)) . ")) return false; busy(this);\">$h$extra"
         . "<input type='password' name='password' placeholder='maintenance tools password' required autocomplete='off'> "
         . "<input type='submit' value='" . h($button) . "'><span class='wait'> Working... please wait.</span></form>";
}
function status()
{   // our master vs Zenodo's latest version, and the unmappable list; reads files + one Zenodo look-up, changes nothing
    $s = array();
    $files = glob(CONTENT_RESOURCE_LOCAL_PATH . "TB_files/" . REF_CONCEPT_ID . "_*/input_files/references.tsv");
    $s['master'] = $files ? $files[0] : null;
    $s['rows'] = $s['master'] ? max(0, count(file($s['master'], FILE_SKIP_EMPTY_LINES)) - 1) : null;
    $rec = CONTENT_RESOURCE_LOCAL_PATH . "TB_files/" . REF_CONCEPT_ID . ".record_id";
    $s['local_record'] = is_file($rec) ? trim(file_get_contents($rec)) : '';
    $list = CONTENT_RESOURCE_LOCAL_PATH . "unmappable_identifiers.tsv";
    $s['unmappable'] = is_file($list) ? max(0, count(file($list, FILE_SKIP_EMPTY_LINES)) - 1) : null;
    $s['zenodo'] = null; $s['identical'] = null;
    ob_start();
    try {
        $api = new ReferenceMaintenanceAPI(array('task' => 'status'));
        if($latest = $api->get_zenodo_info_using_conceptID(REF_CONCEPT_ID, 0)) {
            $s['zenodo'] = array('id' => (string) $latest['id']);
            foreach($latest['files'] as $f) if(basename($f['key']) == 'references.tsv' && $s['master'])
                $s['identical'] = strtolower((string) $f['checksum']) === "md5:" . md5_file($s['master']);
        }
    } catch(\Throwable $e) {}
    ob_end_clean();
    return $s;
}

$action = $_POST['action'] ?? '';
$out = '';
// ---------- reports: no password ----------
if($action == 'processed') {
    // = php generate_csv_new_model.php _ '{"task": "check_processed_TraitBank_datasets"}'
    set_time_limit(0);
    ob_start();
    try { $func = new $check_class(); $func->param = array('task' => 'check_processed_TraitBank_datasets'); $ret = $func->check_processed_TraitBank_datasets(); }
    catch(\Throwable $e) { $ret = false; echo "\nERROR: " . $e->getMessage() . "\n"; }
    $log = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", ob_get_clean())));
    if(!$ret) $out .= "<p class='bad'><b>The check could not run.</b> See the log.</p>";
    else {
        $n_ok = count($ret['up to date']); $todo = array_filter($ret['details'], fn($x) => $x['status'] != 'up to date');
        if(!$todo) $out .= "<p class='good'><b>All " . number_format($n_ok) . " processed datasets are up to date:</b> each was built from the latest raw files from Zenodo.</p>";
        else {
            $out .= "<p class='bad'><b>" . count($todo) . " dataset(s) need attention</b> (" . number_format($n_ok) . " up to date):</p>"
                  . "<table border='1' cellpadding='4' cellspacing='0' style='font-size:13px'><tr><th>Dataset</th><th>Status</th><th>Raw copy</th><th>Processed</th><th>Why</th></tr>";
            $cmds = array();
            foreach($todo as $label => $x) {
                $out .= "<tr><td>" . h($label) . "</td><td><b>" . h($x['status']) . "</b></td><td>" . h($x['raw_record'] ? "record {$x['raw_record']}" : '-') . ($x['raw_date'] ? "<br>" . h($x['raw_date']) : '')
                      . "</td><td>" . h($x['processed_date'] ?: '-') . "</td><td>" . h($x['why']) . "</td></tr>";
                if($x['status'] == 'download first') {
                    $json = json_encode(array('concept_id' => $x['concept_id'], 'redownload_zip_file_YN' => 1, 'task' => 'download_only', 'what' => $x['title']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS);
                    $cmds[] = "php generate_csv_new_model.php _ '" . str_replace(array('":', ',"'), array('": ', ', "'), $json) . "'";
                }
            }
            $out .= "</table>";
            if(array_filter($todo, fn($x) => in_array($x['status'], array('re-process', 'not processed yet'))))
                $out .= "<p><b>re-process / not processed yet:</b> run the dataset's Jenkins job in <i>03 Generate TB Files</i> (it builds the <code>_TraitBank</code> folder).</p>";
            if($cmds) $out .= "<p><b>download first:</b> download the raw files again, then run its Jenkins job.</p>" . command_boxes(implode("\n", $cmds));
        }
        $out .= "<p><small>\"By file dates\": the processed files are compared with the raw files' download time. From the next run of each Jenkins job on,
                 the exact raw version used is recorded (<code>TB_files/&lt;concept_id&gt;.processed_record_id</code>) and used instead. Times are server time.</small></p>";
    }
    $out .= log_box($log);
}
elseif($action == 'datasets') {
    // = php generate_csv_new_model.php _ '{"task": "refresh_all_TraitBank_datasets", "dry_run_YN": 1, "include_Terms_YN": 1, "include_References_YN": 1}'
    set_time_limit(0);
    ob_start();
    try { $func = new $check_class(); $func->param = array('task' => 'refresh_all_TraitBank_datasets', 'dry_run_YN' => 1, 'include_Terms_YN' => 1, 'include_References_YN' => 1);
          $ret = $func->refresh_all_TraitBank_datasets(); }
    catch(\Throwable $e) { $ret = false; echo "\nERROR: " . $e->getMessage() . "\n"; }
    $log = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", ob_get_clean())));
    if(!$ret) $out .= "<p class='bad'><b>The check could not run.</b> See the log.</p>";
    else {
        $n_ok = count($ret['up to date'] ?? array()); $n_out = count($ret['outdated'] ?? array());
        if(!$n_out) $out .= "<p class='good'><b>All " . number_format($n_ok) . " datasets are up to date:</b> our copies are Zenodo's latest versions (the TraitBank datasets, plus Terms and References).</p>";
        else {
            $out .= "<p class='bad'><b>" . number_format($n_out) . " dataset(s) out of date or missing</b> (" . number_format($n_ok) . " up to date):</p><pre>";
            preg_match_all('/^Outdated:\s+\[(\d+) (.*)\] \((.*)\)$/m', $log, $m, PREG_SET_ORDER);
            $cmds = array();
            foreach($m as $x) {
                $out .= h("$x[1] $x[2]: $x[3]") . "\n";
                $json = json_encode(array('concept_id' => $x[1], 'redownload_zip_file_YN' => 1, 'task' => 'download_only', 'what' => $x[2]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS);
                $cmds[] = "php generate_csv_new_model.php _ '" . str_replace(array('{"', '":', ',"'), array('{"', '": ', ', "'), $json) . "'";
            }
            $out .= "</pre>";
            if(count($m) > 1) $cmds[] = "php generate_csv_new_model.php _ '{\"task\": \"refresh_all_TraitBank_datasets\", \"include_Terms_YN\": 1, \"include_References_YN\": 1}'";
            $out .= command_boxes(implode("\n", $cmds))
                  . (count($m) > 1 ? "<p><small>The last command downloads all the outdated ones in one go. Downloading References keeps our master; if Zenodo's copy has changes, use [Check Zenodo for changes].</small></p>"
                                   : "<p><small>Downloading References keeps our master; if Zenodo's copy has changes, use [Check Zenodo for changes].</small></p>");
        }
    }
    $out .= log_box($log);
}
elseif($action == 'compare') {
    [$ret, $log] = run_task(array('task' => 'compare_master_with_zenodo'));
    if(!$ret) $out .= "<p class='bad'><b>The comparison could not run.</b> See the log.</p>";
    elseif($ret['identical']) $out .= "<p class='good'><b>Identical:</b> our master is the same as Zenodo's latest version (record " . h($ret['zenodo_record']) . ").</p>";
    else $out .= "<p class='bad'><b>Different</b> from Zenodo's latest version (record " . h($ret['zenodo_record']) . "; ours: record " . h($ret['local_record'] ?: 'unknown') . ").</p>"
               . "<p>Only in our master: <b>" . number_format($ret['only_local']) . "</b> rows. Only on Zenodo: <b>" . number_format($ret['only_zenodo'])
               . "</b> rows. Different citation: <b>" . number_format($ret['different_citation']) . "</b> identifiers.</p>";
    $out .= files_in_log($log) . log_box($log);
}
elseif($action == 'missing') {
    [$ret, $log] = run_task(array('task' => 'sync_unmappable_identifiers_with_datasets', 'dry_run_YN' => 1));
    if($ret === false || $ret === null) {
        if(($i = strpos($log, "These local copies must be")) !== false) // our copy of a dataset is missing or older than Zenodo's latest
            $out .= "<p class='bad'><b>Not yet: some of our dataset copies are out of date.</b> Download them again first (from the command line), then run this again. Nothing was changed.</p>"
                  . "<pre>" . h(trim(substr($log, $i))) . "</pre>" . command_boxes(substr($log, $i));
        else $out .= "<p class='bad'><b>The preview could not run.</b> See the log.</p>";
    }
    else {
        $n = $ret['added'] + $ret['removed_in_master'] + $ret['removed_not_cited'];
        $out .= "<p><b>Preview</b> (nothing was changed): the unmappable list would get <b>" . number_format($ret['added']) . "</b> new ID(s) cited by the datasets;"
              . " <b>" . number_format($ret['removed_in_master']) . "</b> would be removed because they're now in the master, and <b>"
              . number_format($ret['removed_not_cited']) . "</b> because no dataset cites them any more.</p>";
        if(!$n) $out .= "<p class='good'><b>The unmappable list is already in step with the datasets and the master.</b> Nothing to update.</p>";
        else $out .= "<p>Do you want to update the unmappable list?</p>" . change_form('missing_apply', 'Update the unmappable list',
                     "Update the unmappable list now? (A backup is made first.)", array(),
                     "<label><input type='checkbox' name='crossref' value='1'> look up the new IDs in CrossRef first (those found go to the master)</label><br>");
    }
    $out .= log_box($log);
}
elseif($action == 'zenodo_changes') {
    [$ret, $log] = run_task(array('task' => 'sync_zenodo_references', 'dry_run_YN' => 1));
    if(!$ret) $out .= "<p>No changes to take in from Zenodo (see the log). If Zenodo has a newer version, download it first (the log or the status above says how).</p>";
    else {
        $out .= "<p><b>Preview</b> (nothing was changed): Zenodo's copy would add <b>" . number_format((int) ($ret['added'] ?? 0)) . "</b> and remove <b>"
              . number_format((int) ($ret['removed'] ?? 0)) . "</b> row(s) in our master.</p>"
              . change_form('zenodo_changes_apply', "Take in Zenodo's changes", "Apply Zenodo's changes to our master now? (A backup is made first.)");
    }
    $out .= log_box($log);
}
elseif($action == 'duplicates') {
    [$ret, $log] = run_task(array('task' => 'utility_report_duplicate_identifiers_from_master'));
    if(!$ret) $out .= "<p class='bad'><b>The report could not run.</b> See the log.</p>";
    else {
        $n = max(0, count(file($ret, FILE_SKIP_EMPTY_LINES)) - 1);
        $out .= "<p><b>" . number_format($n) . "</b> row(s) in the duplicates report" . ($n ? '' : ": no identifier appears more than once") . ".</p>";
    }
    $out .= files_in_log($log) . log_box($log);
}
// ---------- changes: password + confirm ----------
elseif($action == 'missing_apply') {
    if(password_ok($out)) {
        [$ret, $log] = run_task(array('task' => 'sync_unmappable_identifiers_with_datasets', 'crossref_YN' => empty($_POST['crossref']) ? 0 : 1));
        if($ret === false || $ret === null) $out .= "<p class='bad'><b>The update could not run.</b> See the log.</p>";
        else $out .= "<p class='good'><b>Done:</b> " . number_format($ret['added']) . " ID(s) added to the unmappable list, " . number_format($ret['to_master'])
                   . " added to the master (CrossRef), " . number_format($ret['removed_in_master'] + $ret['removed_not_cited']) . " removed.</p>";
        $out .= files_in_log($log) . log_box($log);
    }
}
elseif($action == 'zenodo_changes_apply') {
    if(password_ok($out)) {
        [$ret, $log] = run_task(array('task' => 'sync_zenodo_references'));
        $out .= $ret ? "<p class='good'><b>Done:</b> Zenodo's changes were applied to our master.</p>" : "<p class='bad'><b>Nothing was applied.</b> See the log.</p>";
        $out .= files_in_log($log) . log_box($log);
    }
}
elseif($action == 'publish') {
    if(password_ok($out)) {
        [$ret, $log] = run_task(array('task' => 'upload_file_toZenodo', 'concept_id' => REF_CONCEPT_ID, 'publish' => 1));
        if($ret && !empty($ret['published'])) $out .= "<p class='good'><b>Published: record " . h($ret['record_id']) . "</b> -- <a href='" . h($zenodo_url) . "' target='_blank'>see it on Zenodo</a></p>";
        else $out .= "<p class='bad'><b>Nothing was published.</b> See the log (e.g. Zenodo has changes we must take in first, or the file is identical).</p>";
        $out .= log_box($log, true);
    }
}
// ---------- Data Manager files ----------
elseif($action == 'dm_upload') {
    $kind = $_POST['kind'] ?? '';
    $f = $_FILES['dm_file'] ?? null;
    $err = '';
    if(!isset($dm_kinds[$kind])) $err = "Please choose what kind of file it is.";
    elseif(!$f || $f['error'] !== UPLOAD_ERR_OK) $err = "No file was uploaded" . ($f && $f['error'] !== UPLOAD_ERR_NO_FILE ? " (upload error " . (int) $f['error'] . ")" : '') . ".";
    elseif(!preg_match('/\.tsv$/i', $f['name'])) $err = "The file must be a .tsv file (tab-separated). Got: [" . h($f['name']) . "]";
    elseif($f['size'] > 20 * 1024 * 1024) $err = "The file is larger than 20 MB.";
    else {
        $first = (string) fgets(fopen($f['tmp_name'], 'r'));
        $header = array_map('trim', explode("\t", rtrim(preg_replace('/^\xEF\xBB\xBF/', '', $first), "\r\n")));
        if(!in_array($header, $dm_kinds[$kind]['headers'], true))
            $err = "The file's header is [" . h(implode(", ", $header)) . "]. For \"" . h($dm_kinds[$kind]['label']) . "\" it must be: "
                 . implode(" -- or: ", array_map(fn($x) => "[" . implode(", ", $x) . "]", $dm_kinds[$kind]['headers']));
    }
    if($err) $out .= "<p class='bad'><b>The file was not accepted.</b> $err</p>";
    else {
        if(!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
        $saved = $kind . "_" . date("Ymd_His") . ".tsv";
        if(!move_uploaded_file($f['tmp_name'], $upload_dir . $saved)) $out .= "<p class='bad'><b>The file could not be saved.</b></p>";
        else {
            $opts = array(); foreach($dm_kinds[$kind]['options'] as $k => $label) if(!empty($_POST[$k])) $opts[$k] = 1;
            [$ret, $log] = run_task(array_merge(array('task' => $dm_kinds[$kind]['task'], 'file' => $upload_dir . $saved, 'dry_run_YN' => 1), $opts));
            $out .= "<p>Received: [" . h($f['name']) . "], saved as [" . h($saved) . "]. Options: " . ($opts ? h(implode(", ", array_keys($opts))) : "none") . ".</p>";
            if(!$ret) $out .= "<p class='bad'><b>The preview could not run.</b> See the log.</p>";
            else {
                $out .= "<p><b>Preview</b> (nothing was changed): " . h(json_encode($ret)) . "</p>";
                $publish = empty($opts['no_upload_YN']);
                $out .= "<p>Do you want to apply this file" . ($publish ? " and publish the master on Zenodo" : " (no Zenodo upload yet)") . "?</p>"
                      . change_form('dm_apply', 'Apply' . ($publish ? ' and publish to Zenodo' : ''),
                          $publish ? "Apply this file to our master and PUBLISH the master on Zenodo?\n\nPublishing CANNOT be undone." : "Apply this file to our master now? (A backup is made first. No Zenodo upload.)",
                          array_merge(array('kind' => $kind, 'saved' => $saved), $opts));
            }
            $out .= log_box($log);
        }
    }
}
elseif($action == 'dm_apply') {
    $kind = $_POST['kind'] ?? ''; $saved = basename((string) ($_POST['saved'] ?? ''));
    if(!isset($dm_kinds[$kind]) || !preg_match('/^' . $kind . '_\d{8}_\d{6}\.tsv$/', $saved) || !is_file($upload_dir . $saved))
        $out .= "<p class='bad'>The uploaded file wasn't found. Please upload it again. Nothing was changed.</p>";
    elseif(password_ok($out)) {
        $opts = array(); foreach($dm_kinds[$kind]['options'] as $k => $label) if(!empty($_POST[$k])) $opts[$k] = 1;
        $param = array_merge(array('task' => $dm_kinds[$kind]['task'], 'file' => $upload_dir . $saved), $opts);
        if(empty($opts['no_upload_YN'])) $param['publish'] = 1;
        [$ret, $log] = run_task($param);
        $out .= "<p>File: [" . h($saved) . "]</p>";
        $out .= $ret ? "<p class='good'><b>Done:</b> " . h(json_encode($ret)) . "</p>" : "<p class='bad'><b>Nothing was applied.</b> See the log.</p>";
        $out .= files_in_log($log) . log_box($log, true);
    }
}

$st = status(); // after any change, so it shows the new state
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>References Maintenance</title>
<style>
    body { font-family: Arial, sans-serif; }
    pre { background: #f4f4f4; padding: 10px; white-space: pre-wrap; word-break: break-all; font-size: 13px; text-align: left; }
    .bad { color: #b00000; } .good { color: #006400; }
    a.button { padding: 2px 10px; border: 1px solid #888; border-radius: 3px; background: #eee; color: #000; text-decoration: none; }
    td { text-align: left; vertical-align: top; }
    .wait { display: none; color: #008000; font-weight: bold; } form { margin: 6px 0; }
    table.status td { border: none; padding: 2px 10px 2px 0; }
    .cmd { margin: 4px 0; padding: 6px; background: #f4f4f4; border: 1px solid #ddd; font-size: 13px; word-break: break-all; }
    .cmd button { margin-right: 6px; }
</style>
<script>
function copyCmd(btn) {
    var text = btn.parentNode.querySelector('code').textContent;
    var done = function() { btn.textContent = 'Copied!'; setTimeout(function(){ btn.textContent = 'Copy'; }, 1500); };
    if(navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
    var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); done(); } catch(e) {} document.body.removeChild(t);
}
</script>
<script>function busy(f) { f.querySelectorAll('input[type=submit]').forEach(function(b){ b.disabled = true; }); var w = f.querySelector('.wait'); if(w) w.style.display = 'inline'; return true; }</script>
</head>
<body>
<table border="1" cellpadding="15" cellspacing="1" align="center" width="75%">
    <tr><td style="text-align:center"><b>References Maintenance</b><br><br>
    </td></tr>

    <tr><td>
        <small>All TraitBank datasets share one master list of references, published on <a href="<?php echo h($zenodo_url) ?>" target="_blank">Zenodo</a> (References, concept <?php echo REF_CONCEPT_ID ?>).
        This page keeps it complete and in step with Zenodo. <br><br>
        Reports change nothing. Changes need the maintenance tools password.<br><br>
        Everything here can also be run from the command line (do_reference_mtce.php). See also: <a href="<?php echo h($terms_page) ?>">Terms Maintenance</a>.</small>
    </td></tr>

    <tr><td><b>Status</b>
        <table class="status">
            <tr><td>Our master (references.tsv):</td><td><?php echo $st['master'] ? "<b>" . number_format($st['rows']) . "</b> references, version record " . h($st['local_record'] ?: 'unknown') : "<span class='bad'>not found</span>" ?></td></tr>
            <tr><td>Zenodo's latest version:</td><td><?php echo $st['zenodo'] ? "record " . h($st['zenodo']['id']) : "<span class='bad'>could not be reached</span>" ?></td></tr>
            <tr><td>Our master vs Zenodo:</td><td><?php echo $st['identical'] === null ? '?' : ($st['identical'] ? "<span class='good'><b>identical</b></span>" : "<span class='bad'><b>different</b></span> (see [Compare our master with Zenodo])") ?></td></tr>
            <tr><td>Unmappable list:</td><td><?php echo $st['unmappable'] === null ? "<span class='bad'>not found</span>" : "<b>" . number_format($st['unmappable']) . "</b> ID(s) waiting for the Data Manager"
                . " <a class='button' href='main.php?download=unmappable_identifiers.tsv'>Download</a> (also on <a href='" . h($github_url) . "' target='_blank'>GitHub</a>)" ?></td></tr>
        </table>
    </td></tr>

    <tr><td><b>Reports</b> (change nothing)
        <form method="post" action="main.php" onsubmit="return busy(this);">
            <button type="submit" name="action" value="datasets">Check all datasets against Zenodo</button>
            <button type="submit" name="action" value="processed">Check all processed datasets</button>
            <button type="submit" name="action" value="compare">Compare our master with Zenodo</button>
            <button type="submit" name="action" value="missing">Find missing references (preview)</button>
            <button type="submit" name="action" value="zenodo_changes">Check Zenodo for changes (preview)</button>
            <button type="submit" name="action" value="duplicates">List duplicate references</button>
            <span class="wait"> Working... this can take a few minutes.</span>
        </form>
        <small>"Find missing references" and "Check Zenodo for changes" show a preview first; the button to apply it appears below the preview.</small>
    </td></tr>

    <tr><td><b>Publish our master to Zenodo</b> (can't be undone)<br>
        <?php if($st['identical']) echo "<p>The Zenodo file is already identical to our master. There's nothing to publish.</p><input type='submit' value='Publish our master to Zenodo' disabled title='The Zenodo file is already identical to our master'>";
              else echo change_form('publish', 'Publish our master to Zenodo', "Publish our master references.tsv on Zenodo as a new version?\n\nThis CANNOT be undone."); ?>
    </td></tr>

    <tr><td><b>Data Manager files</b>
        <form method="post" action="main.php" enctype="multipart/form-data" onsubmit="return busy(this);">
            <?php foreach($dm_kinds as $k => $kd) {
                echo "<label><input type='radio' name='kind' value='" . h($k) . "' required> <b>" . h($kd['label']) . "</b></label><br>";
                foreach($kd['options'] as $opt => $label) echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label><input type='checkbox' name='" . h($opt) . "' value='1' data-kind='" . h($k) . "'> " . h($label) . "</label><br>";
            } ?>
            <br>Browse File: <input type="file" name="dm_file" accept=".tsv" required>
            <input type="hidden" name="action" value="dm_upload">
            <input type="submit" value="Upload file and preview">
            <span class="wait"> Working... please wait.</span>
        </form>
        <small>The preview changes nothing. To fix duplicates and corrected citations with one Zenodo upload: first "Rows to delete" with
        "don't upload to Zenodo yet" ticked, then "Citations to add" (which publishes both changes).</small>
    </td></tr>

    <?php if($out) echo "<tr><td><b>Result</b><br>$out</td></tr>"; ?>
</table>
<script>
// options apply to their own kind: untick the other kind's options when a kind is chosen
document.querySelectorAll("input[name=kind]").forEach(function(r){ r.addEventListener('change', function(){
    document.querySelectorAll("input[data-kind]").forEach(function(c){ c.disabled = (c.dataset.kind !== r.value); if(c.disabled) c.checked = false; }); }); });
</script>
</body></html>
