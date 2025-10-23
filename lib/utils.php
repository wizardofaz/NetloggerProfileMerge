<?php
// -----------------------------------------------------------------------------
// FILE: lib/utils.php
// -----------------------------------------------------------------------------
function start_session_once(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
}

function ensure_directories(array $dirs): void {
    foreach ($dirs as $d) {
        if (!is_dir($d)) @mkdir($d, 0775, true);
    }
}

function save_with_rotation(string $dir, string $prefix, string $data, int $max): string {
    ensure_directories([$dir]);
    $name = $prefix . '_' . gmdate('Ymd-His') . '.prf';
    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $data);

    // Rotate: keep newest $max files
    $files = glob($dir . DIRECTORY_SEPARATOR . $prefix . '_*.prf');
    if ($files) {
        usort($files, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
        $excess = array_slice($files, $max);
        foreach ($excess as $f) @unlink($f);
    }
    return $name;
}

function client_ip(): string {
    foreach ([
        'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'
    ] as $k) {
        if (!empty($_SERVER[$k])) return explode(',', $_SERVER[$k])[0];
    }
    return '';
}

function ftp_dirname_basename(string $path): array {
    $path = str_replace('\\', '/', $path);
    $dir = preg_replace('#/[^/]*$#', '', $path);
    $base = basename($path);
    return [$dir === $path ? '/' : $dir, $base];
}

function render_header(string $title): void {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>';
    echo '<link rel="icon" href="favicon.ico">';
    echo '<style>body{font:14px system-ui,Segoe UI,Arial;margin:2rem;} fieldset{margin:1rem 0;padding:1rem;} label{display:block;margin:.4rem 0;} table{border-collapse:collapse;margin:1rem 0;} th,td{border:1px solid #ccc;padding:.4rem .6rem;} .ok{color:#060}.warn{color:#b60}.err{color:#b00} .summary{padding:.5rem 0;border-top:1px solid #ddd;border-bottom:1px solid #ddd;margin:.5rem 0;} .badge{padding:.1rem .3rem;border-radius:.3rem;background:#eee;border:1px solid #ccc;margin-left:.4rem;font-size:12px}</style>';
    echo '</head><body><h1>' . htmlspecialchars($title) . '</h1>';
}

function render_footer(): void { echo '</body></html>'; }

function render_errors(array $errs): void {
    echo '<div class="err"><ul>';
    foreach ($errs as $e) echo '<li>' . $e . '</li>';
    echo '</ul></div>';
}
function render_warnings(array $msgs): void {
    echo '<div class="warn"><ul>';
    foreach ($msgs as $m) echo '<li>' . $m . '</li>';
    echo '</ul></div>';
}

function render_summary(?array $cloudInfo, ?array $localInfo, array $diff): void {
    echo '<div class="summary">';
    echo '<strong>Cloud</strong>: path=' . htmlspecialchars($cloudInfo['path'] ?? '') . ', size=' . htmlspecialchars((string)($cloudInfo['size'] ?? '')) . ' bytes, MDTM=' . htmlspecialchars(isset($cloudInfo['mdtm']) && $cloudInfo['mdtm'] ? gmdate('c', $cloudInfo['mdtm']) : 'N/A');
    echo '<br><strong>Local</strong>: name=' . htmlspecialchars($localInfo['name'] ?? '') . ', size=' . htmlspecialchars((string)($localInfo['size'] ?? '')) . ' bytes';
    echo '<br><strong>Counts</strong>: added=' . (int)($diff['counts']['added'] ?? 0) . ', updated=' . (int)($diff['counts']['updated'] ?? 0) . ', unchanged=' . (int)($diff['counts']['unchanged'] ?? 0) . ', conflicts(newer)=' . (int)($diff['counts']['conflicts_newer'] ?? 0) . ', conflicts(longer)=' . (int)($diff['counts']['conflicts_longer'] ?? 0);
    echo '</div>';

    // Table of changes
    echo '<table><thead><tr><th>Callsign</th><th>Field</th><th>From (cloud)</th><th>To (merged)</th><th>Resolution</th></tr></thead><tbody>';
    foreach ($diff['changes'] as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['callsign']) . '</td>';
        echo '<td>' . htmlspecialchars($row['field']) . '</td>';
        echo '<td>' . htmlspecialchars($row['from_cloud']) . '</td>';
        echo '<td>' . htmlspecialchars($row['to']) . '</td>';
        echo '<td>' . htmlspecialchars($row['reason']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

