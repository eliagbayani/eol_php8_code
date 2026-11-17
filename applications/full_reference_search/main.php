<?php
namespace php_active_record;
/* Full Reference Search: a web page for update_resources/connectors/do_full_reference_search.php (lib/connectors/FullReferenceSearchAPI.php)
   http://localhost:81/eol_php8_code/applications/full_reference_search/main.php
   Finds the DOI of references that have only a full_reference text, using CrossRef. The command line keeps working as before.
   - [Browse File] + limit + start = the command line's "file", "limit" and "start". [Upload file] checks and saves the file and shows
     its rows; then [Proceed] starts the search, or [Cancel] removes the uploaded file.
   - A search can take hours (about 1 second per new reference), so it runs in the background, as the same command-line script;
     this page shows its progress, can stop it, and lists the reports with [Download].
   - One search at a time (the script's own lock). It changes nothing: it only writes a report and a guide for the Data Managers.
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
ini_set('error_reporting', false);
ini_set('display_errors', false);
$GLOBALS['ENV_DEBUG'] = false;

$work_dir    = CONTENT_RESOURCE_LOCAL_PATH . "Reference_maintenance/";        // where the script writes its reports (as on the command line)
$upload_dir  = $work_dir . "full_reference_search_uploads/";                  // the files uploaded on this page, one folder per search
$state_file  = $work_dir . "full_reference_search_last_run.json";             // the search started from this page: its log, process, rows
$pending_file = $work_dir . "full_reference_search_pending.json";             // an uploaded file waiting for [Proceed] or [Cancel]
$lock_file   = $work_dir . ".full_reference_search.lock";                     // held by the script while it runs
$connectors  = realpath(dirname(__FILE__) . "/../../update_resources/connectors");
$refs_page   = "../reference_maintenance/main.php";
$terms_page  = "../terms_maintenance/main.php";
$has_password = defined('TERMS_MTCE_PASSWORD') && TERMS_MTCE_PASSWORD !== ''; // [Stop the search] needs the maintenance tools password, as on the Terms and References pages
$report_re   = '/^[A-Za-z0-9._-]+_DOIs_\d{8}_\d{6}(\.tsv|_guide\.txt)$/';      // the only files [Download] serves
const MAX_UPLOAD_MB = 20;
const PROGRESS_EVERY = 20; // = FullReferenceSearchAPI::PROGRESS_EVERY: the script logs a progress line every this many rows
const SECONDS_PER_NEW = 3; // about 3 seconds per new reference: CrossRef's answer + FullReferenceSearchAPI::PAUSE_SECONDS (1 s)
require_library('connectors/ZenodoTraitBankAPI');
require_library('connectors/GenerateTB_Files_Functions');
require_library('connectors/FullReferenceSearchAPI'); // only for the estimate (count_rows_to_search); the search itself runs as the command-line script
function duration_text($s)
{   // 45 -> "1 minute", 600 -> "10 minutes", 9000 -> "2.5 hours", 165000 -> "1.9 days"
    if($s < 60)     return "less than a minute";
    if($s < 90)     return "1 minute";
    if($s < 5400)   return round($s / 60) . " minutes";
    if($s < 172800) return rtrim(rtrim(number_format($s / 3600, 1), '0'), '.') . " hours";
    return rtrim(rtrim(number_format($s / 86400, 1), '0'), '.') . " days";
}
const KEEP_REPORTS = 20;  // = FullReferenceSearchAPI::KEEP_REPORTS: the script deletes older reports when a search starts
if(!is_dir($work_dir)) @mkdir($work_dir, 0777, true);

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function v($s) { return "<span class='val'>" . h($s) . "</span>"; } // a value that changes (green), next to text that doesn't
function report_counts($path)
{   // a report's rows and the count of each status, read from the report itself (exact, also for a stopped search)
    if(!($fh = @fopen($path, 'r'))) return false;
    $header = explode("\t", rtrim((string) fgets($fh), "\r\n")); $col = array_search('status', $header);
    if($col === false) { fclose($fh); return false; }
    $c = array('matched' => 0, 'possible' => 0, 'not found' => 0, 'skipped' => 0, 'error' => 0); $rows = 0;
    while(($l = fgets($fh)) !== false) { if(trim($l) === '') continue; $rows++; $s = explode("\t", $l)[$col] ?? ''; if(isset($c[$s])) $c[$s]++; }
    fclose($fh);
    return array('rows' => $rows, 'counts' => implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($c), $c)));
}
function counts_html($counts)
{   // "matched 11, possible 7, ..." -> the numbers in green, the words as they are
    return preg_replace('/(\d+)/', "<span class='val'>$1</span>", h($counts));
}

// [Download a sample input file]: our sample on GitHub, sent as a download (GitHub shows raw files as plain text, so the browser would open it instead)
const SAMPLE_URL = "https://github.com/eliagbayani/EOL-connector-data-files/raw/refs/heads/master/EOL/sample_reference_4FullReferenceSearch/sample_reference.tab";
if(isset($_GET['sample'])) {
    $body = @file_get_contents(SAMPLE_URL, false, stream_context_create(array('http' => array('timeout' => 30, 'follow_location' => 1, 'user_agent' => 'EOL-connector/1.0'))));
    if($body === false || $body === '') { header("Location: " . SAMPLE_URL); exit; } // GitHub not reachable from here: let the browser get it
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_reference.tab"');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

// [Download]: only the reports and guides
if(isset($_GET['download'])) {
    $name = (string) $_GET['download'];
    if(!preg_match($report_re, $name) || !is_file($work_dir . $name)) { http_response_code(404); exit("Not found"); }
    header('Content-Type: ' . (str_ends_with($name, '.txt') ? 'text/plain' : 'text/tab-separated-values') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($work_dir . $name));
    readfile($work_dir . $name);
    exit;
}

function is_running()
{   // true while the script holds its lock (from this page or the command line)
    if(!($fp = @fopen($GLOBALS['lock_file'], 'c'))) return false;
    $free = flock($fp, LOCK_EX | LOCK_NB);
    if($free) flock($fp, LOCK_UN);
    fclose($fp);
    return !$free;
}
function process_alive($state)
{   // true while the search started from this page is still a live do_full_reference_search.php process -- also in its first seconds,
    // before the script takes its lock and writes its first log line
    $pid = (int) ($state['pid'] ?? 0);
    return $pid > 0 && str_contains((string) @file_get_contents("/proc/$pid/cmdline"), 'do_full_reference_search.php');
}
function running_jobs()
{   // every live do_full_reference_search.php process, whether started from this page or the command line: from /proc, read only
    $jobs = array();
    $btime = preg_match('/^btime (\d+)/m', (string) @file_get_contents('/proc/stat'), $m) ? (int) $m[1] : 0;
    foreach(glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: array() as $dir) {
        $argv = explode("\0", rtrim((string) @file_get_contents("$dir/cmdline"), "\0"));
        if(count($argv) < 2 || !preg_match('#(^|/)php[\d.]*$#', $argv[0])) continue;                 // the php process itself, not its sh wrapper
        if(!in_array('do_full_reference_search.php', array_map('basename', $argv))) continue;
        $pid = (int) basename($dir);
        $param = json_decode((string) end($argv), true);
        $started = 0; // /proc/<pid>/stat field 22: start time in clock ticks (100 a second) after boot
        if($btime && preg_match('/\)\s+(.*)$/', (string) @file_get_contents("$dir/stat"), $m)) $started = $btime + intdiv((int) (explode(' ', $m[1])[19] ?? 0), 100);
        $jobs[] = array('pid' => $pid, 'param' => is_array($param) ? $param : array(), 'started' => $started);
    }
    usort($jobs, fn($a, $b) => $a['started'] <=> $b['started']);
    return $jobs;
}
function read_state() { $s = @json_decode((string) @file_get_contents($GLOBALS['state_file']), true); return is_array($s) ? $s : null; }
function read_pending() { $s = @json_decode((string) @file_get_contents($GLOBALS['pending_file']), true); return is_array($s) ? $s : null; }
function discard_pending()
{   // removes an uploaded file that is waiting for [Proceed]: only its own file and folder in full_reference_search_uploads/<YYYYmmdd_HHMMSS>/
    $p = read_pending();
    @unlink($GLOBALS['pending_file']);
    if(!$p || !preg_match('/^\d{8}_\d{6}$/', (string) $p['ts'])) return;
    $dir = $GLOBALS['upload_dir'] . $p['ts'] . "/";
    if(dirname((string) $p['saved']) . "/" === $dir && is_file($p['saved'])) @unlink($p['saved']);
    if(is_dir($dir) && count(scandir($dir)) == 2) @rmdir($dir); // only when empty
}
function progress($state)
{   // from the log: rows done so far, the latest counts, the report and guide names, whether it finished
    $p = array('done' => 0, 'counts' => '', 'report' => '', 'guide' => '', 'finished' => false, 'error' => '');
    $log = (string) @file_get_contents($GLOBALS['work_dir'] . $state['log']);
    if(preg_match_all('/^\s*(\d+) rows done \(row \d+\): (.+)$/m', $log, $m)) { $p['done'] = (int) end($m[1]); $p['counts'] = end($m[2]); }
    if(preg_match('/^Done: (\d+) rows in ([\d:]+) -- (.+)$/m', $log, $m)) { $p['done'] = (int) $m[1]; $p['counts'] = $m[3]; $p['time'] = $m[2]; $p['finished'] = true; }
    if(preg_match('#Output: \[[^\]]*/([^/\]]+\.tsv)\]#', $log, $m)) $p['report'] = $m[1];
    if(preg_match('#Guide for the Data Managers: \[[^\]]*/([^/\]]+)\]#', $log, $m)) $p['guide'] = $m[1];
    if(preg_match('/^ERROR: .+$/m', $log, $m)) $p['error'] = $m[0];
    $p['tail'] = implode("\n", array_slice(preg_split('/\R/', trim(strip_tags(str_replace('<br>', "\n", $log)))), -15));
    return $p;
}

$out = '';
$upload_msg = ''; // why an uploaded file wasn't accepted: shown next to the upload form, not at the bottom
$action = $_POST['action'] ?? '';
if($action == 'search') {
    $f = $_FILES['ref_file'] ?? null; $err = '';
    $limit = trim((string) ($_POST['limit'] ?? '')); $start = trim((string) ($_POST['start'] ?? ''));
    if(is_running() || (($st = read_state()) && process_alive($st))) $err = "A search is already running. Wait for it to finish, or stop it, then start again.";
    elseif(!$f || $f['error'] !== UPLOAD_ERR_OK) $err = "No file was uploaded" . ($f && $f['error'] !== UPLOAD_ERR_NO_FILE ? " (upload error " . (int) $f['error'] . ")" : '') . ".";
    elseif(!preg_match('/\.(tsv|tab|txt)$/i', $f['name'])) $err = "The file must be tab-separated: .tsv, .tab or .txt. Got: [" . h($f['name']) . "]";
    elseif($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) $err = "The file is larger than " . MAX_UPLOAD_MB . " MB.";
    elseif($limit !== '' && !ctype_digit($limit)) $err = "limit must be a whole number, or blank for all rows.";
    elseif($start !== '' && (!ctype_digit($start) || (int) $start < 1)) $err = "start must be a whole number from 1, or blank to begin at the first row.";
    else {
        $header = array_map('trim', explode("\t", rtrim(preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets(fopen($f['tmp_name'], 'r'))), "\r\n")));
        if(!in_array('identifier', $header) || !in_array('full_reference', $header))
            $err = "The file's header is [" . h(implode(", ", $header)) . "]. It must have the columns <b>identifier</b> and <b>full_reference</b> (others are ignored).";
    }
    if($err) $upload_msg = "<b>The file" . ($f && ($f['name'] ?? '') !== '' ? " [" . h($f['name']) . "]" : '') . " was not accepted, so nothing was searched.</b><br>$err";
    else {
        discard_pending(); // a newer upload replaces one that was never started
        $ts = date("Ymd_His");
        $dir = $upload_dir . "$ts/"; @mkdir($dir, 0777, true);
        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($f['name'], PATHINFO_FILENAME)) ?: 'references'; // the report is named after it
        $saved = $dir . $stem . "." . strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if(!move_uploaded_file($f['tmp_name'], $saved)) $upload_msg = "<b>The file could not be saved, so nothing was searched.</b> Please try again.";
        else {
            $rows = 0; $fh = fopen($saved, 'r'); fgets($fh); while(($l = fgets($fh)) !== false) if(trim($l) !== '') $rows++; fclose($fh);
            $first = $start !== '' ? (int) $start : 1;
            $to_do = max(0, min($limit !== '' ? (int) $limit : PHP_INT_MAX, $rows - $first + 1));
            $pend = array('ts' => $ts, 'name' => $f['name'], 'saved' => $saved, 'rows' => $rows, 'limit' => $limit, 'start' => $start, 'to_do' => $to_do);
            // the estimate: how many of these rows are new (sent to CrossRef) and how many were searched before (cached). No call to CrossRef.
            try { ob_start(); $api = new FullReferenceSearchAPI(array()); ob_end_clean();
                  if($e = $api->count_rows_to_search($saved, $first, $limit !== '' ? (int) $limit : 0)) $pend['estimate'] = $e; }
            catch(\Throwable $ex) { @ob_end_clean(); }
            file_put_contents($pending_file, json_encode($pend));
            header("Location: main.php"); exit; // shows the file with [Proceed] [Cancel]; a reload doesn't upload again
        }
    }
}
elseif($action == 'proceed') {
    // start the search of the uploaded file: the same command as on the command line, in the background; $! = its process id, to stop it
    $p = read_pending();
    if(!$p || ($_POST['ts'] ?? '') !== $p['ts'] || !is_file($p['saved'])) $out .= "<p class='bad'>The uploaded file wasn't found. Please upload it again.</p>";
    elseif(is_running() || (($st = read_state()) && process_alive($st))) $out .= "<p class='bad'>A search is already running. Wait for it to finish, or stop it, then proceed.</p>";
    else {
        $param = array('task' => 'search_DOIs_for_full_references', 'file' => $p['saved']);
        if($p['limit'] !== '') $param['limit'] = (int) $p['limit'];
        if($p['start'] !== '') $param['start'] = (int) $p['start'];
        $log = "full_reference_search_{$p['ts']}.log";
        $cmd = "cd " . escapeshellarg($connectors) . " && nohup " . escapeshellarg(PHP_BINDIR . "/php") . " do_full_reference_search.php _ "
             . escapeshellarg(json_encode($param, JSON_UNESCAPED_SLASHES)) . " > " . escapeshellarg($work_dir . $log) . " 2>&1 & echo $!";
        $pid = (int) trim((string) shell_exec($cmd));
        if($pid) {
            file_put_contents($state_file, json_encode(array_merge($p, array('log' => $log, 'pid' => $pid, 'started' => date("Y-m-d H:i:s")))));
            @unlink($pending_file); // the upload is kept (it's the search's input); only "waiting for Proceed" ends
            header("Location: main.php?started=1"); exit;
        }
        $out .= "<p class='bad'><b>The search could not be started.</b></p>";
    }
}
elseif($action == 'cancel') {
    $p = read_pending();
    if($p && ($_POST['ts'] ?? '') === $p['ts']) { discard_pending(); $out .= "<p>Cancelled: [" . h($p['name']) . "] was removed. Nothing was searched.</p>"; }
    else $out .= "<p>There is no uploaded file waiting.</p>";
}
elseif($action == 'stop') {
    $st = read_state();
    if(!$has_password) $out .= "<p class='bad'>Stopping is disabled: the maintenance tools password (TERMS_MTCE_PASSWORD) isn't set in config/environments/" . h($GLOBALS['ENV_NAME'] ?? '') . ".php. Nothing was stopped.</p>";
    elseif(!hash_equals(TERMS_MTCE_PASSWORD, (string) ($_POST['password'] ?? ''))) $out .= "<p class='bad'>Wrong password. The search was not stopped.</p>";
    elseif($st && (is_running() || process_alive($st))) {
        // only our own search: the process must still be do_full_reference_search.php
        if(process_alive($st) && posix_kill((int) $st['pid'], 15)) {
            $st['stopped'] = date("Y-m-d H:i:s"); file_put_contents($state_file, json_encode($st));
            $out .= "<p><b>The search was stopped.</b> The report keeps the rows done so far. To go on, start again with the same file and <b>start</b> = the next row: the rows already searched come back instantly anyway.</p>";
        }
        else $out .= "<p class='bad'>The running search wasn't started from this page, so it can't be stopped here. Stop it on the command line.</p>";
    }
    else $out .= "<p>No search is running.</p>";
}
$state = read_state();
$jobs = running_jobs();
$running = is_running() || ($state && process_alive($state));
// "Search started" only while it runs: a short search (e.g. all rows cached) can end before this page loads, and its banner says so
if(isset($_GET['started']) && $running) $out .= "<p class='good'><b>Search started.</b> Its progress is shown below; this page refreshes itself while it runs.</p>";
$pending = read_pending();
$prog = $state ? progress($state) : null;
// the banner at the top: how the last search ended (finished / stopped / ended early), until a new file is uploaded or it's dismissed
$banner = null;
if($state && !$running && !$pending) {
    $at = @filemtime($work_dir . $state['log']);
    $banner = array('kind' => $prog['finished'] ? 'done' : (!empty($state['stopped']) ? 'stopped' : 'ended'), 'at' => $at ? date("Y-m-d H:i:s", $at) : '');
}
$tab_title = $running && $state && $state['to_do'] ? "(" . min(100, round(100 * $prog['done'] / $state['to_do'])) . "%) Running: Full Reference Search"
           : ($banner ? array('done' => "✔ Finished", 'stopped' => "■ Stopped", 'ended' => "⚠ Ended early")[$banner['kind']] . ": Full Reference Search" : "Full Reference Search");
// the reports: newest first
$reports = array_values(array_filter(scandir($work_dir) ?: array(), fn($n) => preg_match('/_DOIs_\d{8}_\d{6}\.tsv$/', $n) && preg_match($report_re, $n)));
usort($reports, fn($a, $b) => filemtime($work_dir . $b) <=> filemtime($work_dir . $a));
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title><?php echo h($tab_title) ?></title>
<?php if($running || $jobs) echo '<noscript><meta http-equiv="refresh" content="15;url=main.php"></noscript>'; // with JS: the refresh in the script at the end, which waits while the password is being typed ?>
<style>
    /* text links: one fixed color, visited or not (buttons keep their own colors) */
    a:not(.button), a:not(.button):visited { color: #0645ad; }
    body { font-family: Arial, sans-serif; }
    pre { background: #f4f4f4; padding: 10px; white-space: pre-wrap; word-break: break-all; font-size: 13px; text-align: left; }
    .bad { color: #b00000; } .good { color: #006400; }
    /* "Search running..." : the three dots hop one after the other, like running steps */
    .dots { display: inline-block; font-weight: bold; }
    .dots span { display: inline-block; animation: hop 1.2s ease-in-out infinite; }
    .dots span:nth-child(2) { animation-delay: 0.2s; }
    .dots span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes hop { 0%, 60%, 100% { transform: translateY(0); opacity: 0.4; } 30% { transform: translateY(-5px); opacity: 1; } }
    /* the "Search running..." title: the busy blue of the Running jobs box, dots included (they hop without fading to gray) */
    #last-search.running > summary { color: #0047c7; }
    @media (prefers-reduced-motion: no-preference) { #last-search.running > summary .dots span { animation-name: hop-solid; } } /* they stay still when motion is reduced */
    @keyframes hop-solid { 0%, 60%, 100% { transform: translateY(0); } 30% { transform: translateY(-5px); } }
    @media (prefers-reduced-motion: reduce) { .dots span { animation: none; opacity: 1; } }
    .notice { border: 1px solid #e0a0a0; background: #fff3f3; padding: 8px 10px; margin: 8px 0; }
    .todo { color: #c05000; } /* something waiting for the user, e.g. "Not started yet: click [Proceed]" */
    .val { color: #008000; font-weight: bold; } /* values that change (file, rows, progress, counts, dates); labels stay black */
    /* every button the same size: [Upload file], [Proceed], [Cancel], [Stop the search], [Download], [Guide] */
    a.button, button, input[type=submit] { display: inline-block; box-sizing: border-box; width: 125px; height: 28px; line-height: 26px; padding: 0 6px;
        font: 14px Arial, sans-serif; text-align: center; vertical-align: middle; border: 1px solid #888; border-radius: 3px;
        background: #eee; color: #000; text-decoration: none; cursor: pointer; }
    a.button { display: inline-flex; align-items: center; justify-content: center; line-height: normal; } /* links: text centered up and down, like the real buttons */
    a.button:hover, button:hover, input[type=submit]:hover { background: #ddd; }
    button:disabled, input[type=submit]:disabled { color: #888; cursor: default; }
    /* form submissions ([Upload file], [Proceed], [Cancel], [Stop the search]): blue, so "this runs something" stands apart from [Download] / [Guide] (gray) */
    input[type=submit], button[type=submit] { background: #2f6db5; color: #fff; border-color: #25578f; font-weight: bold; }
    input[type=submit]:hover, button[type=submit]:hover { background: #25578f; }
    input[type=submit]:disabled, button[type=submit]:disabled { background: #e4e4e4; color: #888; border-color: #bbb; }
    td { text-align: left; vertical-align: top; }
    .wait { display: none; color: #008000; font-weight: bold; } form { margin: 6px 0; }
    table.list td, table.list th { border: none; border-bottom: 1px solid #ddd; padding: 3px 12px 3px 0; text-align: left; font-size: 14px; }
    table.params td { border: none; padding: 3px 10px 3px 0; }
    table.params.last td { font-size: 14px; } /* the Last search details: the same size as the reports list and the Running jobs box */
    progress { width: 300px; }
    /* "Running jobs": its own font and color, so it stands apart from the rest of the page */
    .jobs { font-family: "Courier New", Courier, monospace; font-size: 14px; color: #1a3d8f; background: #eef3fc; border: 1px solid #b8c8ea; padding: 8px 10px; }
    .jobs b { color: #0d2766; } .jobs small { font-size: 12px; }
    /* busy (a search is running): bright blue, thicker border; idle (none, or the last one is over): gray */
    .jobs.busy { color: #0047c7; background: #e3edff; border: 2px solid #0047c7; }
    .jobs.busy b { color: #00308a; }
    .jobs.idle { color: #6b6b6b; background: #f3f3f3; border-color: #cfcfcf; }
    .jobs.idle b { color: #555; }
    /* the banner when a search is over: big, colored, at the very top */
    .banner { display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-radius: 6px; border: 2px solid; font-size: 15px; }
    .banner .icon { font-size: 30px; line-height: 1; }
    .banner .msg { flex: 1; } .banner .msg big { font-size: 19px; font-weight: bold; }
    .banner.done    { background: #e6f6e6; border-color: #2e8b2e; color: #145214; }
    .banner.stopped { background: #fff4e0; border-color: #d08a00; color: #6b4500; }
    .banner.ended   { background: #fdeaea; border-color: #c03030; color: #7a1414; }
    .banner .x { width: auto; padding: 0 10px; }
    .banner .btns { display: flex; flex-direction: column; gap: 6px; } /* [Download] with [Guide] below it */
    details.reports > summary { cursor: pointer; } /* "Available reports": hidden until its title is clicked; a click again hides it */
</style>
<script>function busy(f) { f.querySelectorAll('input[type=submit]').forEach(function(b){ b.disabled = true; }); var w = f.querySelector('.wait'); if(w) w.style.display = 'inline'; return true; }</script>
</head>
<body>
<table border="1" cellpadding="15" cellspacing="1" align="center" width="75%">
    <tr><td style="text-align:center"><b>Full Reference Search</b></td></tr>

    <?php if($banner):
        $icon = array('done' => '&#10004;', 'stopped' => '&#9632;', 'ended' => '&#9888;')[$banner['kind']];
        $head = array('done' => 'The search has finished.', 'stopped' => 'The search was stopped.', 'ended' => 'The search ended before the last row.')[$banner['kind']]; ?>
    <tr id="banner-row" data-log="<?php echo h($state['log']) ?>"><td>
        <div class="banner <?php echo $banner['kind'] ?>" role="status">
            <span class="icon" aria-hidden="true"><?php echo $icon ?></span>
            <span class="msg"><big><?php echo $head ?></big><br>
                <?php echo h($state['name']) ?>: <?php echo number_format($prog['done']) ?> of <?php echo number_format($state['to_do']) ?> rows<?php
                if($prog['finished']) echo " in " . h($prog['time']); if($banner['at']) echo ", " . ($prog['finished'] ? "finished" : "last written") . " " . h($banner['at']); ?>.
                <?php if($prog['counts']) echo "<br>" . h($prog['counts']) . "."; ?>
                <?php if($banner['kind'] == 'done') echo "<br>Download the report and its guide below, and send them to the Data Managers for review.";
                      elseif($banner['kind'] == 'stopped') echo "<br>The report keeps the rows done. To go on, upload the same file with <b>start</b> = the next row.";
                      else echo "<br>" . ($prog['error'] ? h($prog['error']) : "See the log below.") . " The rows done are in the report; run again with <b>start</b> to go on."; ?></span>
            <?php // [Download] the report, and [Guide] below it (the guide is written only when a search runs to the end)
            $has_report = $prog['report'] && is_file($work_dir . $prog['report']); $has_guide = $prog['guide'] && is_file($work_dir . $prog['guide']);
            if($has_report || $has_guide) echo "<span class='btns'>"
                . ($has_report ? "<a class='button' href='main.php?download=" . rawurlencode($prog['report']) . "' title='The report (TSV)'>Download</a>" : '')
                . ($has_guide ? "<a class='button' href='main.php?download=" . rawurlencode($prog['guide']) . "' title='The guide for the Data Managers (text)'>Guide</a>" : '')
                . "</span>"; ?>
            <button type="button" class="x" title="Hide this message" onclick="dismissBanner()">&times;</button>
        </div>
    </td></tr>
    <?php endif; ?>

    <tr><td>
        <small>Finds the <b>DOI</b> of references that have only a <b>full_reference</b> text, using CrossRef
        (the same search as CrossRef's <a href="https://apps.crossref.org/SimpleTextQuery" target="_blank">Simple Text Query</a>).
        Each of CrossRef's top 5 candidates is checked against the year, journal, volume, issue and page read from the citation.<br><br>
        It changes nothing: it writes a report (<b>matched</b> / <b>possible</b> / <b>not found</b>, with a review note for each row)
        and a guide, for the Data Managers' final check.<br><br>
        <span style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap">
            <span>Also on the command line (do_full_reference_search.php).</span>
            <span>See also: <?php if(getenv('MY_ENV') === 'development'): // the References page runs only on the Mac for now ?><a href="<?php echo h($refs_page) ?>">References Maintenance</a> | <?php endif; ?><a href="<?php echo h($terms_page) ?>">Terms Maintenance</a>.</span></span></small>
    </td></tr>

    <tr><td><div class="jobs <?php echo $jobs ? 'busy' : 'idle' ?>"><b>Running jobs</b><br>
        <?php if(!$jobs) echo "None: no search is running now (from this page or the command line)."
                             . ($banner ? " The last search " . array('done' => "has finished", 'stopped' => "was stopped", 'ended' => "ended early")[$banner['kind']] . ($banner['at'] ? " (" . h($banner['at']) . ")" : '') . "." : '');
        else foreach($jobs as $j) {
            $p = $j['param'];
            $from_page = $state && (int) ($state['pid'] ?? 0) === $j['pid'];
            $what = $from_page ? $state['name'] : basename((string) ($p['file'] ?? '?'));
            echo "&bull; " . h($p['task'] ?? 'do_full_reference_search.php') . ": " . h($what)
               . (isset($p['limit']) ? ", limit " . h($p['limit']) : '') . (isset($p['start']) ? ", start " . h($p['start']) : '')
               . "<br>&nbsp;&nbsp;process " . $j['pid'] . ", started from " . ($from_page ? "this page" : "the command line")
               . ($j['started'] ? ", " . date("Y-m-d H:i:s", $j['started']) . ", running for " . h(duration_text(time() - $j['started'])) . "<span class='dots' aria-hidden='true'><span>.</span><span>.</span><span>.</span></span>" : '')
               . ($from_page ? '' : "<br>&nbsp;&nbsp;<small>(not from this page: its progress isn't shown below and it can only be stopped on the command line)</small>") . "<br>";
        } ?>
    </div></td></tr>

    <tr><td><b>Search a file</b>
        <?php if($running): ?>
            <?php if($upload_msg) echo "<div class='notice bad'>$upload_msg</div>"; ?>
            <p>A search is running. A new one can start when it's done, or after you stop it.</p>
        <?php elseif($pending): ?>
            <table class="params">
                <tr><td>File:</td><td><?php echo v($pending['name']) ?> (<?php echo v(number_format($pending['rows'])) ?> rows; limit <?php echo v($pending['limit'] !== '' ? $pending['limit'] : 'none') ?>, start <?php echo v($pending['start'] !== '' ? $pending['start'] : '1') ?>)</td></tr>
                <tr><td></td><td><span class="todo"><b>Not started yet:</b> click [Proceed] to start the search.</span></td></tr>
                <tr><td></td><td>
                    <form method="post" action="main.php" style="display:inline" onsubmit="return busy(this);">
                        <input type="hidden" name="ts" value="<?php echo h($pending['ts']) ?>">
                        <button type="submit" name="action" value="proceed">Proceed</button>
                        <button type="submit" name="action" value="cancel" formnovalidate>Cancel</button>
                        <span class="wait"> Starting...</span>
                    </form>
                    <br><small>[Proceed] searches <?php echo v(number_format($pending['to_do'])) ?> row(s) in CrossRef. [Cancel] removes the uploaded file.</small>
                    <?php if(isset($pending['estimate'])): $e = $pending['estimate']; ?>
                    <br><small>Estimate: <?php echo ($e['new'] * SECONDS_PER_NEW < 60 ? '' : 'about ') . v(duration_text($e['new'] * SECONDS_PER_NEW)) ?>.
                        <?php echo v(number_format($e['new'])) ?> new reference(s) to send to CrossRef, at about <?php echo SECONDS_PER_NEW ?> seconds each;
                        <?php echo v(number_format($e['cached'])) ?> searched before (instant, from the cache)<?php
                        if($e['skipped']) echo "; " . v(number_format($e['skipped'])) . " too short to search" ?>.
                        <?php if($e['new'] * SECONDS_PER_NEW > 3600) echo "It can be stopped at any time and resumed later with <b>start</b>." ?></small>
                    <?php endif; ?>
                </td></tr>
            </table>
        <?php else: ?>
        <?php if($upload_msg) echo "<div class='notice bad'>$upload_msg<br>Please choose the right file and click [Upload file] again.</div>"; ?>
        <form method="post" action="main.php" enctype="multipart/form-data" onsubmit="return busy(this);">
            <table class="params">
                <tr><td>file:</td><td><input type="file" name="ref_file" accept=".tsv,.tab,.txt" required>
                    <small>tab-separated, with the columns <b>identifier</b> and <b>full_reference</b> (at most <?php echo MAX_UPLOAD_MB ?> MB)</small>
                    <a class="button" href="main.php?sample=1" style="width:auto; margin-left:6em" title="sample_reference.tab: an example of the input file">Download a sample input file</a></td></tr>
                <tr><td>limit:</td><td><input type="number" name="limit" min="0" step="1" style="width:100px" value="<?php echo $upload_msg ? h($_POST['limit'] ?? '') : '' ?>"> <small>search only this many rows; blank = all rows (e.g. 200 for a test)</small></td></tr>
                <tr><td>start:</td><td><input type="number" name="start" min="1" step="1" style="width:100px" value="<?php echo $upload_msg ? h($_POST['start'] ?? '') : '' ?>"> <small>begin at this row (1 = the first row after the header); blank = 1</small></td></tr>
            </table>
            <input type="hidden" name="action" value="search">
            <input type="submit" value="Upload file">
            <span class="wait"> Uploading...</span>
        </form>
        <small>New references take about 3 seconds each: CrossRef is asked politely, one at a time, with a 1-second pause after each call
        (e.g. 55,000 rows: about 2 days). References already searched are never sent to CrossRef again: they come back instantly.
        If CrossRef asks us to slow down, the search waits; if it keeps asking, the search stops and can be resumed with <b>start</b>.</small>
        <?php endif; ?>
    </td></tr>

    <?php if($state):
        $stopped = !$running && !$prog['finished'] && !empty($state['stopped']);
        $ended   = !$running && !$prog['finished'];                 // stopped, or ended before the last row
        if($ended && $prog['report'] && ($rc = report_counts($work_dir . $prog['report']))) { $prog['done'] = $rc['rows']; $prog['counts'] = $rc['counts']; } // the real rows done, not the last progress line
        $pct = $state['to_do'] ? min(100, round(100 * $prog['done'] / $state['to_do'])) : 0;
        $title = $running ? "Search running" : ($pending ? "Previous search" : "Last search");
        if($stopped) $title .= " (stopped)"; elseif($ended) $title .= " (ended early)"; ?>
    <?php // hidden by default; open while a search runs, and when one has just ended (its banner is showing: closed again in the script below once the banner is dismissed) ?>
    <tr><td><details class="reports<?php if($running) echo ' running' ?>" id="last-search"<?php if($running || $banner) echo " open" ?>><summary><b><?php echo $title ?></b><?php if($running) echo "<span class='dots' aria-hidden='true'><span>.</span><span>.</span><span>.</span></span>" ?></summary>
        <table class="params last">
            <tr><td>File:</td><td colspan="2"><?php echo v($state['name']) ?> (<?php echo v(number_format($state['rows'])) ?> rows; limit <?php echo v($state['limit'] !== '' ? $state['limit'] : 'none') ?>, start <?php echo v($state['start'] !== '' ? $state['start'] : '1') ?>)</td></tr>
            <tr><td>Started:</td><td colspan="2"><?php echo v($state['started']) ?><?php if(!empty($state['stopped'])) echo " &mdash; <span class='bad'>stopped " . h($state['stopped']) . "</span>" ?></td></tr>
            <tr><td><?php echo $ended ? "Rows done:" : "Progress:" ?></td><td colspan="2">
                <?php if($ended) // no progress bar for a search that isn't going on
                          echo v(number_format($prog['done'])) . " of " . v(number_format($state['to_do'])) . " rows done before it " . ($stopped ? "was stopped" : "ended") . ".";
                      else echo "<progress value='" . $prog['done'] . "' max='" . max(1, $state['to_do']) . "'></progress> "
                              . v(number_format($prog['done'])) . " of " . v(number_format($state['to_do'])) . " rows (" . v("$pct%") . ")"; ?>
                <?php if($prog['done'] < PROGRESS_EVERY && !$prog['finished'] && $running) echo "<br><small>Progress is counted every " . PROGRESS_EVERY . " rows.</small>" ?></td></tr>
            <?php if($prog['counts']) echo "<tr><td>" . ($running ? "So far:" : "Results:") . "</td><td colspan='2'>" . counts_html($prog['counts']) . "</td></tr>"; ?>
            <?php if($prog['finished']) echo "<tr><td>Finished:</td><td colspan='2'>" . v("done") . " in " . v($prog['time']) . "</td></tr>";
                  elseif(!$running && empty($state['stopped'])) echo "<tr><td>Finished:</td><td colspan='2' class='bad'>it ended before the last row" . ($prog['error'] ? ": " . h($prog['error']) : '; see the log') . "</td></tr>"; ?>
            <?php if($prog['report'] && is_file($work_dir . $prog['report'])) echo "<tr><td>Report:</td><td>" . v($prog['report']) . "</td><td><a class='button' href='main.php?download=" . rawurlencode($prog['report']) . "'>Download</a>"
                  . ($prog['finished'] ? '' : " <small>(the rows done so far)</small>") . "</td></tr>"; ?>
            <?php if($prog['guide'] && is_file($work_dir . $prog['guide'])) echo "<tr><td>Guide:</td><td>" . v($prog['guide']) . "</td><td><a class='button' href='main.php?download=" . rawurlencode($prog['guide']) . "'>Download</a></td></tr>"; ?>
        </table>
        <?php if($running): ?>
            <form method="post" action="main.php" onsubmit="if(!confirm('Stop the search? The report keeps the rows done so far.')) return false; return busy(this);">
                <input type="hidden" name="action" value="stop">
                <input type="password" name="password" id="stop-pw" placeholder="maintenance tools password" required autocomplete="off" style="height:28px; box-sizing:border-box; vertical-align:middle">
                <input type="submit" value="Stop the search">
                <button type="button" id="notify-btn" onclick="askNotify()">Notify me</button>
                <small>This page refreshes itself every 15 seconds. <span id="notify-note">[Notify me]: a desktop notice when the search ends, while this page stays open (any tab).</span></small>
            </form>
        <?php endif; ?>
        <details><summary>Log (the last lines)</summary><pre><?php echo h($prog['tail']) ?></pre></details>
    </details></td></tr>
    <?php endif; ?>

    <?php if($out) echo "<tr><td><b>Result</b><br>$out</td></tr>"; ?>

    <tr><td><details class="reports"><summary><b>Available reports</b> (newest first) <small>(<?php echo count($reports) ?>)</small></summary>
        <small>Only the newest <?php echo KEEP_REPORTS ?> reports are kept: older ones, with their guides, are deleted when a new search starts.</small>
        <?php if(!$reports) echo "<p>There are no reports yet.</p>";
        else {
            echo "<table class='list'><tr><th>Report</th><th>Date</th><th>Rows</th><th>Size</th><th></th></tr>";
            foreach($reports as $r) {
                $path = $work_dir . $r; $guide = preg_replace('/\.tsv$/', '_guide.txt', $r);
                $n = max(0, count(file($path, FILE_SKIP_EMPTY_LINES)) - 1);
                echo "<tr><td>" . v($r) . "</td><td>" . v(date("Y-m-d H:i", filemtime($path))) . "</td><td>" . v(number_format($n)) . "</td><td>" . v(number_format(filesize($path) / 1024, 0)) . " KB</td>"
                   . "<td><a class='button' href='main.php?download=" . rawurlencode($r) . "'>Download</a>"
                   . (is_file($work_dir . $guide) ? " <a class='button' href='main.php?download=" . rawurlencode($guide) . "'>Guide</a>" : " <small>(no guide: the search was stopped before the end)</small>") . "</td></tr>";
            }
            echo "</table>";
        } ?>
    </details></td></tr>
</table>
<script>
// The banner and the desktop notice. Browser storage only remembers this viewer's choices; the page works without it.
var RUNNING_LOG = <?php echo json_encode($running && $state ? $state['log'] : null) ?>;
var TAB_TITLE = <?php echo json_encode($tab_title) ?>;
<?php if($running || $jobs): ?>
setInterval(function() {   // refresh every 15 seconds, but not while the password for [Stop the search] is being typed or the form is being sent
    var p = document.getElementById('stop-pw');
    if(p && (p.value !== '' || document.activeElement === p || p.form.querySelector('input[type=submit]').disabled)) return;
    location.href = 'main.php';
}, 15000);
<?php endif; ?>
function store(k, v) { try { if(v === undefined) return localStorage.getItem(k); if(v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch(e) { return null; } }
function dismissBanner() { var r = document.getElementById('banner-row'); if(r) { store('frs_dismissed', r.dataset.log); r.style.display = 'none'; } }
function askNotify() {
    if(!('Notification' in window)) { document.getElementById('notify-note').textContent = 'This browser cannot show desktop notices.'; return; }
    Notification.requestPermission().then(function(p) {
        if(p === 'granted') { store('frs_notify', RUNNING_LOG); document.getElementById('notify-btn').disabled = true; document.getElementById('notify-note').textContent = 'You will get a desktop notice when it ends.'; }
        else document.getElementById('notify-note').textContent = 'Desktop notices are blocked for this page in the browser settings.';
    });
}
try { if(location.search.indexOf('started=') >= 0) history.replaceState(null, '', 'main.php'); } catch(e) {} // a reload doesn't show "Search started" again
(function() {
    var r = document.getElementById('banner-row');
    if(r && store('frs_dismissed') === r.dataset.log) {                                   // hidden before, for this same search
        r.style.display = 'none';
        var ls = document.getElementById('last-search'); if(ls) ls.open = false;           // ... so the Last search section starts hidden too
    }
    if(RUNNING_LOG && store('frs_notify') === RUNNING_LOG && document.getElementById('notify-btn')) {   // already asked for this search
        document.getElementById('notify-btn').disabled = true; document.getElementById('notify-note').textContent = 'You will get a desktop notice when it ends.';
    }
    if(r && store('frs_notify') === r.dataset.log) {                                      // the search we were waiting for has ended: tell once
        store('frs_notify', null);
        try { if('Notification' in window && Notification.permission === 'granted') new Notification(TAB_TITLE, { body: r.querySelector('.msg').innerText.split('\n').slice(0, 3).join('\n') }); } catch(e) {}
    }
})();
</script>
</body></html>
