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
    if($err) $out .= "<p class='bad'><b>The file was not accepted.</b> $err</p>";
    else {
        discard_pending(); // a newer upload replaces one that was never started
        $ts = date("Ymd_His");
        $dir = $upload_dir . "$ts/"; @mkdir($dir, 0777, true);
        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($f['name'], PATHINFO_FILENAME)) ?: 'references'; // the report is named after it
        $saved = $dir . $stem . "." . strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if(!move_uploaded_file($f['tmp_name'], $saved)) $out .= "<p class='bad'><b>The file could not be saved.</b></p>";
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
    if($st && (is_running() || process_alive($st))) {
        // only our own search: the process must still be do_full_reference_search.php
        if(process_alive($st) && posix_kill((int) $st['pid'], 15)) {
            $st['stopped'] = date("Y-m-d H:i:s"); file_put_contents($state_file, json_encode($st));
            $out .= "<p><b>The search was stopped.</b> The report keeps the rows done so far. To go on, start again with the same file and <b>start</b> = the next row: the rows already searched come back instantly anyway.</p>";
        }
        else $out .= "<p class='bad'>The running search wasn't started from this page, so it can't be stopped here. Stop it on the command line.</p>";
    }
    else $out .= "<p>No search is running.</p>";
}
if(isset($_GET['started'])) $out .= "<p class='good'><b>Search started.</b> Its progress is shown below; this page refreshes itself while it runs.</p>";

$state = read_state();
$running = is_running() || ($state && process_alive($state));
$pending = read_pending();
$prog = $state ? progress($state) : null;
// the reports: newest first
$reports = array_values(array_filter(scandir($work_dir) ?: array(), fn($n) => preg_match('/_DOIs_\d{8}_\d{6}\.tsv$/', $n) && preg_match($report_re, $n)));
usort($reports, fn($a, $b) => filemtime($work_dir . $b) <=> filemtime($work_dir . $a));
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Full Reference Search</title>
<?php if($running) echo '<meta http-equiv="refresh" content="15;url=main.php">'; ?>
<style>
    body { font-family: Arial, sans-serif; }
    pre { background: #f4f4f4; padding: 10px; white-space: pre-wrap; word-break: break-all; font-size: 13px; text-align: left; }
    .bad { color: #b00000; } .good { color: #006400; }
    /* "Search running..." : the three dots hop one after the other, like running steps */
    .dots { display: inline-block; font-weight: bold; }
    .dots span { display: inline-block; animation: hop 1.2s ease-in-out infinite; }
    .dots span:nth-child(2) { animation-delay: 0.2s; }
    .dots span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes hop { 0%, 60%, 100% { transform: translateY(0); opacity: 0.4; } 30% { transform: translateY(-5px); opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { .dots span { animation: none; opacity: 1; } }
    .todo { color: #c05000; } /* something waiting for the user, e.g. "Not started yet: click [Proceed]" */
    .val { color: #008000; font-weight: bold; } /* values that change (file, rows, progress, counts, dates); labels stay black */
    /* every button the same size: [Upload file], [Proceed], [Cancel], [Stop the search], [Download], [Guide] */
    a.button, button, input[type=submit] { display: inline-block; box-sizing: border-box; width: 125px; height: 28px; line-height: 26px; padding: 0 6px;
        font: 14px Arial, sans-serif; text-align: center; vertical-align: middle; border: 1px solid #888; border-radius: 3px;
        background: #eee; color: #000; text-decoration: none; cursor: pointer; }
    a.button { display: inline-flex; align-items: center; justify-content: center; line-height: normal; } /* links: text centered up and down, like the real buttons */
    a.button:hover, button:hover, input[type=submit]:hover { background: #ddd; }
    button:disabled, input[type=submit]:disabled { color: #888; cursor: default; }
    td { text-align: left; vertical-align: top; }
    .wait { display: none; color: #008000; font-weight: bold; } form { margin: 6px 0; }
    table.list td, table.list th { border: none; border-bottom: 1px solid #ddd; padding: 3px 12px 3px 0; text-align: left; font-size: 14px; }
    table.params td { border: none; padding: 3px 10px 3px 0; }
    progress { width: 300px; }
</style>
<script>function busy(f) { f.querySelectorAll('input[type=submit]').forEach(function(b){ b.disabled = true; }); var w = f.querySelector('.wait'); if(w) w.style.display = 'inline'; return true; }</script>
</head>
<body>
<table border="1" cellpadding="15" cellspacing="1" align="center" width="75%">
    <tr><td style="text-align:center"><b>Full Reference Search</b><br><br></td></tr>

    <tr><td>
        <small>Finds the <b>DOI</b> of references that have only a <b>full_reference</b> text, using CrossRef
        (the same search as CrossRef's <a href="https://apps.crossref.org/SimpleTextQuery" target="_blank">Simple Text Query</a>).
        Each of CrossRef's top 5 candidates is checked against the year, journal, volume, issue and page read from the citation.<br><br>
        It changes nothing: it writes a report (<b>matched</b> / <b>possible</b> / <b>not found</b>, with a review note for each row)
        and a guide, for the Data Managers' final check.<br><br>
        Also on the command line (do_full_reference_search.php).
        &nbsp;&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;&nbsp;See also: <a href="<?php echo h($refs_page) ?>">References Maintenance</a>.</small>
    </td></tr>

    <tr><td><b>Search a file</b>
        <?php if($running): ?>
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
        <form method="post" action="main.php" enctype="multipart/form-data" onsubmit="return busy(this);">
            <table class="params">
                <tr><td>file:</td><td><input type="file" name="ref_file" accept=".tsv,.tab,.txt" required>
                    <small>tab-separated, with the columns <b>identifier</b> and <b>full_reference</b> (at most <?php echo MAX_UPLOAD_MB ?> MB)</small></td></tr>
                <tr><td>limit:</td><td><input type="number" name="limit" min="0" step="1" style="width:100px"> <small>search only this many rows; blank = all rows (e.g. 200 for a test)</small></td></tr>
                <tr><td>start:</td><td><input type="number" name="start" min="1" step="1" style="width:100px"> <small>begin at this row (1 = the first row after the header); blank = 1</small></td></tr>
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
    <tr><td><b><?php echo $title ?></b><?php if($running) echo "<span class='dots' aria-hidden='true'><span>.</span><span>.</span><span>.</span></span>" ?>
        <table class="params">
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
                <input type="hidden" name="action" value="stop"><input type="submit" value="Stop the search">
                <small>This page refreshes itself every 15 seconds.</small>
            </form>
        <?php endif; ?>
        <details><summary>Log (the last lines)</summary><pre><?php echo h($prog['tail']) ?></pre></details>
    </td></tr>
    <?php endif; ?>

    <?php if($out) echo "<tr><td><b>Result</b><br>$out</td></tr>"; ?>

    <tr><td><b>Available reports</b> (newest first)<br>
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
    </td></tr>
</table>
</body></html>
