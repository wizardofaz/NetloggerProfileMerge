<?php
// -----------------------------------------------------------------------------
// FILE: index.php
// Single-entry web page: form UI + review + commit flow
// -----------------------------------------------------------------------------
// Minimal front controller
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/utils.php';
require_once __DIR__ . '/lib/NLProfile.php';
require_once __DIR__ . '/lib/merge.php';
require_once __DIR__ . '/lib/ftp_client.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/render_grouped.php';

ensure_directories([
    $config['backup_dir_cloud'],
    $config['backup_dir_local'],
    dirname($config['audit_db_path']),
]);

function load_context_from_session(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    return $_SESSION['NL_merge_context'] ?? null;
}

// Initialize audit log DB
$audit = new Audit($config['audit_db_path']);

$stage = $_POST['stage'] ?? $_GET['stage'] ?? '';
$errors = [];
$warnings = [];
$diff = null;
$mergedProfile = null;
$cloudInfo = null;
$localInfo = null;
$profileVersionDecisionRequired = false;

if (($stage ?? '') === 'download' && isset($_GET['token'])) {
    start_session_once();
    $tok = $_GET['token'];
    $slot = $_SESSION['NL_downloads'][$tok] ?? null;
    if (!$slot) {
        header('HTTP/1.1 404 Not Found');
        echo 'Download expired or not found.'; exit;
    }
    // Optional: expire after 10 minutes
    if (($slot['ts'] ?? 0) < time() - 600) {
        unset($_SESSION['NL_downloads'][$tok]);
        header('HTTP/1.1 410 Gone');
        echo 'Download expired.'; exit;
    }
    $name = $slot['name'] ?: 'merged.prf';
    $data = $slot['data'] ?? '';
    // Stream the file
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');    
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($data));
    echo $data;
    // one-shot: clear token
    unset($_SESSION['NL_downloads'][$tok]);
    exit;
}

if ($stage === 'analyze') {
    // Validate inputs
    $who = strtoupper(trim($_POST['who'] ?? ''));
    if ($who === '' || !preg_match('/^[A-Z0-9\/]+$/', $who)) {
        $errors[] = 'Please enter your callsign (A–Z, 0–9, optional "/").';
    }

    $ftpHost = trim($_POST['ftp_host'] ?? '');
    $ftpUser = trim($_POST['ftp_user'] ?? '');
    $ftpPass = $_POST['ftp_pass'] ?? '';
    $ftpPath = trim($_POST['ftp_path'] ?? '');

    if ($ftpHost === '' || $ftpUser === '' || $ftpPath === '') {
        $errors[] = 'FTP host, user, and path are required.';
    }
    if (!isset($_FILES['local_profile']) || $_FILES['local_profile']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a local profile file to upload.';
    }

    $assumeLocalNewer = isset($_POST['assume_local_newer']);

    if (!$errors) {
        // Fetch cloud file via FTP
        $ftpClient = new FTPClient($ftpHost, $ftpUser, $ftpPass);
        try {
            $ftpClient->connect();
            $cloudRaw = $ftpClient->getFileContents($ftpPath);
            $cloudMDTM = $ftpClient->getMDTM($ftpPath); // int|false
            $cloudSize = $ftpClient->getSize($ftpPath); // int|false
            $cloudInfo = [
                'path' => $ftpPath,
                'size' => $cloudSize,
                'mdtm' => $cloudMDTM,
            ];
        } catch (Exception $e) {
            $errors[] = 'FTP error fetching cloud profile: ' . htmlspecialchars($e->getMessage());
        }

        // Read local file
        $localTmp = $_FILES['local_profile']['tmp_name'] ?? null;
        $localName = $_FILES['local_profile']['name'] ?? 'local.prf';
        $localRaw = $localTmp ? file_get_contents($localTmp) : '';
        $localInfo = [
            'name' => $localName,
            'size' => $localTmp ? filesize($localTmp) : null,
            // Browsers don’t give us mtime; leave null
            'mdtm' => null,
        ];

        if (!$errors) {
            try {
                $cloudParsed = NLProfile::parse($cloudRaw);
                $localParsed = NLProfile::parse($localRaw);

                // Version check
                if ($cloudParsed['general']['ProfileVersion'] !== $localParsed['general']['ProfileVersion']) {
                    $profileVersionDecisionRequired = true;
                    $warnings[] = 'ProfileVersion differs (cloud=' . htmlspecialchars($cloudParsed['general']['ProfileVersion']) . ', local=' . htmlspecialchars($localParsed['general']['ProfileVersion']) . '). Proceed only if you are sure both NetLogger versions are compatible.';
                }

                // Merge
                $mergeOpts = [
                    'assume_local_newer' => $assumeLocalNewer,
                    'cloud_mtime' => $cloudMDTM ?: null,
                    'local_mtime' => null, // unknown
                ];
                $mergeResult = merge_profiles($cloudParsed, $localParsed, $mergeOpts);
                $mergedProfile = $mergeResult['merged'];
                $diff = $mergeResult['diff'];

                // Build a reason map: "CALLSIGN|Field" => reason text
                $reasonMap = [];
                foreach (($diff['changes'] ?? []) as $row) {
                    if (empty($row['callsign']) || empty($row['field'])) continue;
                    $key = strtoupper($row['callsign']).'|'.$row['field'];

                    // Always record the reason if present
                    if (!empty($row['reason'])) {
                        $reasonMap[$key] = $row['reason'];
                    } else {
                        // Fallbacks so both sides can still show an ⓘ even if a row forgot to set one
                        $cloudVal  = $row['cloud_val']  ?? '';
                        $localVal  = $row['local_val']  ?? '';
                        $mergedVal = $row['merged_val'] ?? '';
                        if ($mergedVal !== $cloudVal && $mergedVal !== $localVal) {
                            $reasonMap[$key] = 'merged differs from both sources';
                        } elseif ($mergedVal !== $cloudVal) {
                            $reasonMap[$key] = 'merged differs from cloud (reason not recorded)';
                        } elseif ($mergedVal !== $localVal) {
                            $reasonMap[$key] = 'merged differs from local (reason not recorded)';
                        }
                    }
                }

                // Stash in session for the commit stage 
                start_session_once();
                $_SESSION['NL_merge_context'] = [
                    'who' => $who,
                    'ftp_host' => $ftpHost,
                    'ftp_user' => $ftpUser,
                    'ftp_pass' => $ftpPass,
                    'ftp_path' => $ftpPath,
                    'cloud_raw' => $cloudRaw,
                    'cloud_info' => $cloudInfo,
                    'local_raw' => $localRaw,
                    'local_info' => $localInfo,
                    'merged_raw' => NLProfile::emit($mergedProfile),
                    'diff' => $diff,
                    'assume_local_newer' => $assumeLocalNewer,
                    'cloud_mtime' => $cloudMDTM ?: null,
                    'reason_map' => $reasonMap, 
                ];
                
            } catch (Exception $e) {
                $errors[] = 'Parse/Merge error: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    // Render review
    render_header('NetLogger Profile Merge — Review');
    if ($errors) {
        render_errors($errors);
        echo '<p><a href="index.php">Back</a></p>';
        render_footer();
        exit;
    }

    if ($warnings) render_warnings($warnings);

    // Counts/metadata header (copied from your render_summary top section)
    echo '<div class="summary">';

    // --- Cloud & Local row-level counts + field-level Impact ---

    // 1) Build callsign sets
    $cloudCalls = array_keys($cloudParsed['calls'] ?? []);
    $localCalls = array_keys($localParsed['calls'] ?? []);
    $cloudSet   = array_fill_keys($cloudCalls, true);
    $localSet   = array_fill_keys($localCalls, true);
    $allCalls   = array_values(array_unique(array_merge($cloudCalls, $localCalls)));

    // 2) Per-callsign update flags from the diff rows
    $per = []; // $per[CS] = ['cloud_update'=>bool, 'local_update'=>bool]
    foreach (($diff['changes'] ?? []) as $row) {
        $cs = strtoupper($row['callsign'] ?? '');
        if ($cs === '') continue;
        $cv = $row['cloud_val']  ?? ($row['from_cloud'] ?? '');
        $lv = array_key_exists('local_val', $row) ? $row['local_val'] : null;
        $mv = $row['merged_val'] ?? ($row['to'] ?? '');
        if ($mv !== $cv) $per[$cs]['cloud_update'] = true;
        if ($lv !== null && $mv !== $lv) $per[$cs]['local_update'] = true;
    }

    // 3) Cloud counts (row perspective from cloud side)
    $cc_added = $cc_updated = $cc_unchanged = 0;
    foreach ($allCalls as $cs) {
        $inCloud = isset($cloudSet[$cs]);
        $inLocal = isset($localSet[$cs]);
        $cu = $per[strtoupper($cs)]['cloud_update'] ?? false;

        if (!$inCloud && $inLocal)        $cc_added++;
        elseif ($inCloud && $cu)          $cc_updated++;
        elseif ($inCloud)                 $cc_unchanged++;
    }

    // 4) Local counts (row perspective from local side)
    $lc_added = $lc_updated = $lc_unchanged = 0;
    foreach ($allCalls as $cs) {
        $inCloud = isset($cloudSet[$cs]);
        $inLocal = isset($localSet[$cs]);
        $lu = $per[strtoupper($cs)]['local_update'] ?? false;

        if ($inCloud && !$inLocal)        $lc_added++;
        elseif ($inLocal && $lu)          $lc_updated++;
        elseif ($inLocal)                 $lc_unchanged++;
    }

    // 5) Field-level Impact (unchanged from your earlier logic)
    $impactCloud = 0; $impactLocal = 0;
    foreach (($diff['changes'] ?? []) as $row) {
        $cv = $row['cloud_val']  ?? ($row['from_cloud'] ?? '');
        $mv = $row['merged_val'] ?? ($row['to'] ?? '');
        $lv = array_key_exists('local_val', $row) ? $row['local_val'] : null;
        if ($mv !== $cv) $impactCloud++;
        if ($lv !== null && $mv !== $lv) $impactLocal++;
    }

    // 6) Output (Counts renamed + Local counts added; Impact moved below)
    echo '<strong>Cloud counts</strong>: added='.(int)$cc_added
    . ', updated='.(int)$cc_updated
    . ', unchanged='.(int)$cc_unchanged;

    echo '<br><strong>Local counts</strong>: added='.(int)$lc_added
    . ', updated='.(int)$lc_updated
    . ', unchanged='.(int)$lc_unchanged;

    // Optional: keep conflict summary if you like (from your merge counts)
    if (!empty($diff['counts'])) {
        $cn = (int)($diff['counts']['conflicts_newer']  ?? 0);
        $cl = (int)($diff['counts']['conflicts_longer'] ?? 0);
        echo '<br><strong>Conflicts</strong>: newer='.$cn.', longer='.$cl;
    }

    // Impact below the two counts lines
    echo '<br><strong>Impact</strong>: cloud fields='.(int)$impactCloud
    . ', local fields='.(int)$impactLocal;
    echo '</div>';

    // --- Filter chips (post to review stage; no re-upload required) ---
    $filter = $_POST['filter'] ?? 'all';
    echo '<div style="margin:.5rem 0">';
    echo '<form method="post" style="display:inline;margin-right:.5rem">';
    echo '<input type="hidden" name="stage" value="review">';

    // define the helper (DO NOT echo here)
    $chip = function($val, $label, $cur) {
        $style = ($val === $cur) ? ' style="font-weight:bold;"' : '';
        return '<button type="submit" name="filter" value="' . htmlspecialchars($val) . '"' . $style . '>'
            . htmlspecialchars($label) . '</button> ';
    };

    // now render the buttons
    echo $chip('all',   'All',              $filter);
    echo $chip('cloud', 'Cloud will change',$filter);
    echo $chip('local', 'Local will change',$filter);
    echo '</form>';
    echo '</div>';

    echo '<div class="legend">'
    . '<span class="dot cloud"></span>Cloud will change&nbsp; '
    . '<span class="dot local"></span>Local will change&nbsp; '
    . 'ⓘ reason&nbsp; '
    . '<span class="badge-aa">Aa</span> case-only'
    . '</div>';

    // --- Grouped view (default) ---
    // Use the fresh reason map just built above
    echo '<div id="grouped-view">';
    render_grouped_view($cloudParsed, $localParsed, $mergedProfile, $reasonMap, $filter);

    // Still stash context for commit stage
    start_session_once();
    $_SESSION['NL_merge_context'] = [
        'who' => $who,
        'ftp_host' => $ftpHost,
        'ftp_user' => $ftpUser,
        'ftp_pass' => $ftpPass,
        'ftp_path' => $ftpPath,
        'cloud_raw' => $cloudRaw,
        'cloud_info' => $cloudInfo,
        'local_raw' => $localRaw,
        'local_info' => $localInfo,
        'merged_raw' => NLProfile::emit($mergedProfile),
        'diff' => $diff,
        'assume_local_newer' => $assumeLocalNewer,
        'cloud_mtime' => $cloudMDTM ?: null,
        'reason_map' => $reasonMap,
    ];

    // View toggle buttons
    echo '<div style="margin:.5rem 0">';
    echo '<button type="button" id="btnGrouped">Grouped view</button> ';
    echo '<button type="button" id="btnRow">Legacy table view</button>';
    echo '</div>';

    // Legacy row view (hidden by default): reuse your existing summary+table renderer
    //echo '<div id="row-view" style="display:none">';
    echo '<div id="row-view">';
    render_summary($cloudInfo, $localInfo, $diff);
    echo '</div>';

    // tiny JS toggler
    echo '<script>
    (function(){
    const g=document.getElementById("grouped-view");
    const r=document.getElementById("row-view");
    document.getElementById("btnGrouped").onclick=function(){g.style.display="block";r.style.display="none";};
    document.getElementById("btnRow").onclick=function(){g.style.display="none";r.style.display="block";};
    })();
    </script>';

    // (continue with your existing commit form)
    //echo '<form method="post">';

    render_footer();
    exit;
}


if ($stage === 'review') {
    // Re-render review from session (no re-upload)
    $ctx = load_context_from_session();
    render_header('NetLogger Profile Merge — Review');

    if (!$ctx) {
        render_errors(['Session expired. Please start again.']);
        echo '<p><a href="index.php">Back</a></p>';
        render_footer();
        exit;
    }

    // Recreate parsed structures from session
    try {
        $cloudParsed   = NLProfile::parse($ctx['cloud_raw']);
        $localParsed   = NLProfile::parse($ctx['local_raw']);
        $mergedProfile = NLProfile::parse($ctx['merged_raw']); // already emitted, but parse for header/calls
    } catch (Exception $e) {
        render_errors(['Parse error in stored context: ' . htmlspecialchars($e->getMessage())]);
        echo '<p><a href="index.php">Back</a></p>';
        render_footer();
        exit;
    }

    $cloudInfo = $ctx['cloud_info'] ?? null;
    $localInfo = $ctx['local_info'] ?? null;
    $diff      = $ctx['diff'] ?? ['counts'=>[], 'changes'=>[]];

    // Filter selection from chips
    $filter = $_POST['filter'] ?? 'all';

    // Header summary (reuse your existing summary line)
    echo '<div class="summary">';

    // --- Cloud & Local row-level counts + field-level Impact ---

    // 1) Build callsign sets
    $cloudCalls = array_keys($cloudParsed['calls'] ?? []);
    $localCalls = array_keys($localParsed['calls'] ?? []);
    $cloudSet   = array_fill_keys($cloudCalls, true);
    $localSet   = array_fill_keys($localCalls, true);
    $allCalls   = array_values(array_unique(array_merge($cloudCalls, $localCalls)));

    // 2) Per-callsign update flags from the diff rows
    $per = []; // $per[CS] = ['cloud_update'=>bool, 'local_update'=>bool]
    foreach (($diff['changes'] ?? []) as $row) {
        $cs = strtoupper($row['callsign'] ?? '');
        if ($cs === '') continue;
        $cv = $row['cloud_val']  ?? ($row['from_cloud'] ?? '');
        $lv = array_key_exists('local_val', $row) ? $row['local_val'] : null;
        $mv = $row['merged_val'] ?? ($row['to'] ?? '');
        if ($mv !== $cv) $per[$cs]['cloud_update'] = true;
        if ($lv !== null && $mv !== $lv) $per[$cs]['local_update'] = true;
    }

    // 3) Cloud counts (row perspective from cloud side)
    $cc_added = $cc_updated = $cc_unchanged = 0;
    foreach ($allCalls as $cs) {
        $inCloud = isset($cloudSet[$cs]);
        $inLocal = isset($localSet[$cs]);
        $cu = $per[strtoupper($cs)]['cloud_update'] ?? false;

        if (!$inCloud && $inLocal)        $cc_added++;
        elseif ($inCloud && $cu)          $cc_updated++;
        elseif ($inCloud)                 $cc_unchanged++;
    }

    // 4) Local counts (row perspective from local side)
    $lc_added = $lc_updated = $lc_unchanged = 0;
    foreach ($allCalls as $cs) {
        $inCloud = isset($cloudSet[$cs]);
        $inLocal = isset($localSet[$cs]);
        $lu = $per[strtoupper($cs)]['local_update'] ?? false;

        if ($inCloud && !$inLocal)        $lc_added++;
        elseif ($inLocal && $lu)          $lc_updated++;
        elseif ($inLocal)                 $lc_unchanged++;
    }

    // 5) Field-level Impact (unchanged from your earlier logic)
    $impactCloud = 0; $impactLocal = 0;
    foreach (($diff['changes'] ?? []) as $row) {
        $cv = $row['cloud_val']  ?? ($row['from_cloud'] ?? '');
        $mv = $row['merged_val'] ?? ($row['to'] ?? '');
        $lv = array_key_exists('local_val', $row) ? $row['local_val'] : null;
        if ($mv !== $cv) $impactCloud++;
        if ($lv !== null && $mv !== $lv) $impactLocal++;
    }

    // 6) Output (Counts renamed + Local counts added; Impact moved below)
    echo '<strong>Cloud counts</strong>: added='.(int)$cc_added
    . ', updated='.(int)$cc_updated
    . ', unchanged='.(int)$cc_unchanged;

    echo '<br><strong>Local counts</strong>: added='.(int)$lc_added
    . ', updated='.(int)$lc_updated
    . ', unchanged='.(int)$lc_unchanged;

    // Optional: keep conflict summary if you like (from your merge counts)
    if (!empty($diff['counts'])) {
        $cn = (int)($diff['counts']['conflicts_newer']  ?? 0);
        $cl = (int)($diff['counts']['conflicts_longer'] ?? 0);
        echo '<br><strong>Conflicts</strong>: newer='.$cn.', longer='.$cl;
    }

    // Impact below the two counts lines
    echo '<br><strong>Impact</strong>: cloud fields='.(int)$impactCloud
    . ', local fields='.(int)$impactLocal;
    echo '</div>';

    // Filter chips now post to stage=review (no upload needed)
    echo '<div style="margin:.5rem 0">';
    echo '<form method="post" style="display:inline;margin-right:.5rem">';
    echo '<input type="hidden" name="stage" value="review">';
    function chip_btn($val, $label, $cur) {
        $style = ($val === $cur) ? ' style="font-weight:bold;"' : '';
        return '<button type="submit" name="filter" value="'.htmlspecialchars($val).'"'.$style.'>'
            . htmlspecialchars($label) . '</button> ';
    }
    echo chip_btn('all','All',$filter);
    echo chip_btn('cloud','Cloud will change',$filter);
    echo chip_btn('local','Local will change',$filter);
    echo '</form>';
    echo '</div>';
    
    echo '<div class="legend">'
    . '<span class="dot cloud"></span>Cloud will change&nbsp; '
    . '<span class="dot local"></span>Local will change&nbsp; '
    . 'ⓘ reason&nbsp; '
    . '<span class="badge-aa">Aa</span> case-only'
    . '</div>';

    // Use reason map from session for review clicks
    $reasonMap = $ctx['reason_map'] ?? [];
    echo '<div id="grouped-view">';
    render_grouped_view($cloudParsed, $localParsed, $mergedProfile, $reasonMap, $filter);
    echo '</div>';

    // Legacy table (optional)
    //echo '<div id="row-view" style="display:none">';
    echo '<div id="row-view">';
    render_summary($cloudInfo, $localInfo, $diff);
    echo '</div>';

    // Commit controls
    echo '<form method="post">';
    echo '<input type="hidden" name="stage" value="commit">';
    echo '<label><input type="checkbox" name="write_cloud" checked> Write merged file to cloud</label><br>';
    echo '<label><input type="checkbox" name="download_merged" checked> Download merged file</label><br>';
    if (!empty($ctx['version_mismatch'])) {
        echo '<label style="color:#b00;"><input type="checkbox" name="confirm_version" required> I understand ProfileVersion differs and want to proceed anyway.</label><br>';
    }
    echo '<button type="submit">Apply changes</button> ';
    echo '<a href="index.php">Cancel</a>';
    echo '</form>';

    render_footer();
    exit;
}

// ========================= COMMIT STAGE =========================
if ($stage === 'commit') {
    start_session_once();
    $ctx = $_SESSION['NL_merge_context'] ?? null;
    if (!$ctx) {
        render_header('NetLogger Profile Merge — Error');
        render_errors(['Session expired. Please start again.']);
        echo '<p><a href="index.php">Back</a></p>';
        render_footer();
        exit;
    }

    $writeCloud     = isset($_POST['write_cloud']);
    $downloadMerged = isset($_POST['download_merged']); // default checked in your form

    // If a version mismatch was present during analyze, require confirmation
    $needsConfirm = false;
    try {
        $cloudParsed  = NLProfile::parse($ctx['cloud_raw']);
        $mergedParsed = NLProfile::parse($ctx['merged_raw']);
        if ($cloudParsed['general']['ProfileVersion'] !== $mergedParsed['general']['ProfileVersion']) {
            $needsConfirm = true;
        }
    } catch (Exception $e) { /* ignore */ }

    if ($needsConfirm && empty($_POST['confirm_version'])) {
        render_header('NetLogger Profile Merge — Confirmation needed');
        render_errors(['ProfileVersion differs; please confirm to proceed.']);
        echo '<form method="post">';
        echo '<input type="hidden" name="stage" value="commit">';
        echo '<label style="color:#b00;"><input type="checkbox" name="confirm_version" required> I understand ProfileVersion differs and want to proceed anyway.</label><br>';
        echo '<label><input type="checkbox" name="write_cloud" ' . ($writeCloud ? 'checked' : '') . '> Write merged file to cloud</label><br>';
        echo '<label><input type="checkbox" name="download_merged" ' . ($downloadMerged ? 'checked' : '') . '> Download merged file</label><br>';
        echo '<button type="submit">Apply changes</button> ';
        echo '<a href="index.php">Cancel</a>';
        echo '</form>';
        render_footer();
        exit;
    }

    $who     = $ctx['who'];
    $ftpHost = $ctx['ftp_host'];
    $ftpUser = $ctx['ftp_user'];
    $ftpPass = $ctx['ftp_pass'];
    $ftpPath = $ctx['ftp_path'];

    $config = require __DIR__ . '/config.php';
    $backupCloud = save_with_rotation($config['backup_dir_cloud'], 'cloud', $ctx['cloud_raw'], $config['max_backups']);
    $backupLocal = save_with_rotation($config['backup_dir_local'], 'local', $ctx['local_raw'], $config['max_backups']);

    $result = ['wrote_cloud' => false, 'cloud_after' => null];
    $error  = null;

    if ($writeCloud) {
        try {
            require_once __DIR__ . '/lib/ftp_client.php';
            $ftpClient = new FTPClient($ftpHost, $ftpUser, $ftpPass);
            $ftpClient->connect();

            // split path
            [$dir, $base] = ftp_dirname_basename($ftpPath);
            if ($dir === '' || $dir === '/') $dir = '.';
            $finalPath = rtrim($dir, '/').'/'.$base;

            // 1) Acquire lock (5 min TTL)
            $steal = !empty($_POST['steal_lock']);
            $lock = $ftpClient->lockAcquire($dir, '.nlmerge.lock', $who, $steal, 300);
            if (($lock['status'] ?? 'busy') !== 'ok') {
                $holder = isset($lock['holder']) ? (' Holder: ' . htmlspecialchars($lock['holder'])) : '';
                throw new Exception('Could not acquire lock. ' . ($lock['message'] ?? '') . $holder);
            }

            // 2) Freshness check (size + mtime if available), unless force
            $force = !empty($_POST['force_overwrite']);
            $beforeSize = $ftpClient->sizeSafe($finalPath);
            $exists = ($beforeSize !== null);
            $shownSize = $ctx['cloud_info']['size'] ?? null;   // may be int|false|null from analyze
            $shownMdtm = $ctx['cloud_info']['mdtm'] ?? null;   // may be int|false|null

            if ($exists && !$force) {
                $beforeMdtmIso = $ftpClient->mdtmSafe($finalPath); // ISO or null
                $mismatch = false;
                if ($shownSize !== null && $beforeSize !== null && (int)$beforeSize !== (int)$shownSize) $mismatch = true;
                // if both timestamps available, compare seconds
                if ($shownMdtm && is_numeric($shownMdtm) && $beforeMdtmIso) {
                    $beforeTs = strtotime($beforeMdtmIso);
                    if ($beforeTs !== false && (int)$beforeTs !== (int)$shownMdtm) $mismatch = true;
                }
                if ($mismatch) {
                    $ftpClient->lockRelease($dir, '.nlmerge.lock');
                    $ftpClient->close();
                    render_header('NetLogger Profile Merge — Cloud changed');
                    render_warnings(['Cloud file changed since Review. Re-run Analyze, or Force overwrite to proceed.']);
                    echo '<form method="post">';
                    echo '<input type="hidden" name="stage" value="review">';
                    echo '<label><input type="checkbox" name="force_overwrite"> Force overwrite anyway</label><br>';
                    echo '<button type="submit">Back to Review</button>';
                    echo '</form>';
                    render_footer();
                    exit;
                }
            }

            // 3) Ensure backup dir on FTP: <file>.bak
            $backupDir = $finalPath.".bak";
            $ftpClient->mkdIfNeeded($backupDir);

            // 4) Move current file to backup (if it exists)
            if ($exists) {
                $stamp = (new DateTime('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');
                $whoTag = preg_replace('/[^A-Z0-9]+/i', '', $who) ?: 'op';
                $backupName = "$backupDir/$stamp" . '_' . $whoTag . '.prf';
                if (!$ftpClient->renamePath($finalPath, $backupName)) {
                    throw new Exception('Failed to move cloud file to backup.');
                }
                // rotate backups (config max)
                $ftpClient->rotateBackups($backupDir, (int)($config['max_backups'] ?? 10));
            } else {
                $backupName = null; // nothing to roll back to
            }

            // 5) Atomic upload merged
            // Upload+atomic rename (let the class generate the temp name internally)
            $errMsg = null;
            if (!$ftpClient->atomicUploadPath($finalPath, $ctx['merged_raw'], $errMsg)) {
                // roll back if possible
                if ($backupName) { $ftpClient->renamePath($backupName, $finalPath); }
                // release lock before showing diagnostics
                $ftpClient->lockRelease($dir, '.nlmerge.lock');

                // helpful context while we debug
                $pwd   = method_exists($ftpClient,'pwd') ? $ftpClient->pwd() : '';
                $names = method_exists($ftpClient,'listNames') ? $ftpClient->listNames($dir) : [];

                render_header('NetLogger Profile Merge — FTP Upload Error');
                echo '<p class="err"><strong>Commit failed</strong> for <code>' . htmlspecialchars($finalPath) . '</code></p>';
                echo '<p><strong>FTP PWD:</strong> <code>' . htmlspecialchars($pwd) . '</code></p>';
                if ($dir) {
                    echo '<p><strong>Listing of</strong> <code>' . htmlspecialchars($dir) . '</code>:</p>';
                    echo '<pre style="background:#f7f7f7;border:1px solid #ddd;padding:.5rem;">' .
                        htmlspecialchars(implode("\n", $names)) . '</pre>';
                }
                echo '<p><strong>FTP error hint:</strong> ' . htmlspecialchars($errMsg ?: ($ftpClient->lastError ?? 'unknown')) . '</p>';
                echo '<form method="post"><input type="hidden" name="stage" value="review"><button type="submit">Back to Review</button></form>';
                render_footer();
                $ftpClient->close();
                exit;
            }

            // 6) Read new cloud stats & unlock
            $afterSize = $ftpClient->sizeSafe($finalPath);
            $afterMdtmIso = $ftpClient->mdtmSafe($finalPath);
            $ftpClient->lockRelease($dir, '.nlmerge.lock');
            $ftpClient->close();

            $result['wrote_cloud'] = true;
            $result['cloud_after'] = [
                'size' => $afterSize,
                'mdtm' => $afterMdtmIso ? strtotime($afterMdtmIso) : null, // keep your original int usage
            ];
        } catch (Exception $e) {
            $error = 'FTP commit failed: ' . htmlspecialchars($e->getMessage());
        }
    }

    // Render result page
    render_header('NetLogger Profile Merge — Result');
    if ($error) {
        render_errors([$error]);
    } else {
        echo '<p><strong>Success.</strong></p>';
        if ($result['wrote_cloud']) {
            echo '<p>Cloud profile updated. Size=' . htmlspecialchars((string)($result['cloud_after']['size'] ?? '')) .
                 ' bytes; MDTM=' . htmlspecialchars($result['cloud_after']['mdtm'] ? gmdate('c', $result['cloud_after']['mdtm']) : 'N/A') . '.</p>';
        }
    }

    if ($downloadMerged) {
        $dlToken = bin2hex(random_bytes(8));
        start_session_once();
        $_SESSION['NL_downloads'][$dlToken] = [
            'name' => basename($ftpPath),      // suggested filename
            'data' => $ctx['merged_raw'],      // file contents
            'ts'   => time(),
        ];
        echo '<p><a class="button" href="index.php?stage=download&token=' . htmlspecialchars($dlToken) . '">Download merged file</a></p>';
    }

    echo '<p><a href="index.php">Back</a></p>';
    render_footer();
    exit;
}

// Default: input form
render_header('NetLogger Profile Merge');
?>
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="stage" value="analyze">
  <fieldset>
    <legend>Operator</legend>
    <label>Who (callsign): <input name="who" required pattern="[A-Za-z0-9/]+" style="text-transform:uppercase"></label>
  </fieldset>
  <fieldset>
    <legend>FTP (cloud profile)</legend>
    <label>Host: <input name="ftp_host" value="<?=htmlspecialchars($config['ftp_host'])?>" required></label>
    <label>User: <input name="ftp_user" value="<?=htmlspecialchars($config['ftp_user'])?>" required></label>
    <label>Password: <input name="ftp_pass" type="password" required></label>
    <label>Path: <input name="ftp_path" value="<?=htmlspecialchars($config['ftp_profile_path'])?>" required></label>
  </fieldset>
  <fieldset>
    <legend>Local profile</legend>
    <input type="file" name="local_profile" accept=".prf,.txt" required>
  </fieldset>
  <fieldset>
    <legend>Conflict preference</legend>
    <label><input type="checkbox" name="assume_local_newer"> Assume local file is newer than cloud if timestamps are equal/unknown</label>
  </fieldset>
  <button type="submit">Analyze & Merge</button>
</form>
<?php render_footer(); ?>

