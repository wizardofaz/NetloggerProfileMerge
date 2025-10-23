<?php
// lib/render_grouped.php
//
// Grouped diff renderer (per callsign) with bidirectional filters + tooltips.
// Filters:
//   $filter === 'all'   → show fields where (merged≠cloud OR merged≠local)
//   $filter === 'cloud' → show fields where (merged≠cloud)
//   $filter === 'local' → show fields where (merged≠local)
//
// $reasonMap keys are "CALLSIGN|Field" → human reason text (e.g., "local won — newer",
// "tie → longer string", "case-only difference → prefer mixed case").

function render_grouped_view(
    array $cloud,
    array $local,
    array $merged,
    array $reasonMap = [],
    string $filter = 'all'
): void {
    $header = $merged['header'] ?? [];
    $allCs = array_unique(array_merge(
        array_keys($cloud['calls'] ?? []),
        array_keys($local['calls'] ?? []),
        array_keys($merged['calls'] ?? [])
    ));
    natcasesort($allCs);

    // Inline CSS for cards, icons, and markers
    echo '<style>
.card{border:1px solid #ddd;border-radius:8px;padding:10px;margin:10px 0;}
.card h3{margin:.2rem 0;display:flex;align-items:center;gap:.5rem}
.fields{font-size:12px;color:#555;margin-bottom:.4rem}
.legend{font-size:12px;color:#444;margin:.5rem 0}
.dot{
  display:inline-block;
  width:10px; height:10px;
  margin-right:.3rem;
  vertical-align:middle;
  border:1px solid;
}

/* Cloud → blue circle */
.dot.cloud{
  border-radius:50%;
  background:#7fb3ff;        /* brighter blue */
  border-color:#1f5fbf;      /* darker blue border */
}

/* Local → orange square */
.dot.local{
  border-radius:2px;         /* square-ish (visually distinct) */
  background:#ffb366;        /* orange fill */
  border-color:#a04c00;      /* darker orange border */
}
.i{display:inline-block;margin-left:.25rem;border:1px solid #bbb;border-radius:50%;
  width:14px;height:14px;line-height:14px;text-align:center;font-size:10px;color:#444;background:#f3f3f3}
.badge-aa{display:inline-block;margin-left:.25rem;font-size:10px;border:1px solid #bbb;border-radius:3px;padding:0 3px;background:#f7f7ff}
.merged{font-weight:bold;background:#f7faff}
.empty{color:#888}
.table-wrap{overflow:auto}
table.g{border-collapse:collapse;width:100%;}
table.g th,table.g td{border:1px solid #e0e0e0;padding:.35rem .5rem;vertical-align:top;word-break:break-word}
</style>';

    foreach ($allCs as $cs) {
        $c = $cloud['calls'][$cs] ?? [];
        $l = $local['calls'][$cs] ?? [];
        $m = $merged['calls'][$cs] ?? [];

        // Decide which columns to show based on filter
        $showCols = [];
        $hasAny = false;
        foreach ($header as $h) {
            $cv = trim($c[$h] ?? '');
            $lv = trim($l[$h] ?? '');
            $mv = trim($m[$h] ?? '');

            if ($cv === '' && $lv === '' && $mv === '') continue;

            $chgCloud = ($mv !== $cv);
            $chgLocal = ($mv !== $lv);

            $include =
                ($filter === 'all'   && ($chgCloud || $chgLocal)) ||
                ($filter === 'cloud' &&  $chgCloud)               ||
                ($filter === 'local' &&  $chgLocal);

            if ($include) {
                $showCols[] = $h;
                $hasAny = true;
            }
        }
        if (!$hasAny) continue;

        echo '<div class="card">';
        echo '<h3>' . htmlspecialchars($cs) . '</h3>';
        echo '<div class="fields">Fields: ' . htmlspecialchars(implode(' | ', $showCols)) . '</div>';
        echo '<div class="table-wrap"><table class="g">';
        echo '<thead><tr><th></th>';
        foreach ($showCols as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead><tbody>';

        // cloud row
        echo '<tr><td><strong>cloud</strong></td>';
        foreach ($showCols as $h) {
            $v = $c[$h] ?? '';
            echo '<td>' . ($v === '' ? '<span class="empty">(empty)</span>' : htmlspecialchars($v)) . '</td>';
        }
        echo '</tr>';

        // local row
        echo '<tr><td><strong>local</strong></td>';
        foreach ($showCols as $h) {
            $v = $l[$h] ?? '';
            echo '<td>' . ($v === '' ? '<span class="empty">(empty)</span>' : htmlspecialchars($v)) . '</td>';
        }
        echo '</tr>';

        // merged row, with ⓘ tooltip and direction markers
        echo '<tr class="merged"><td><strong>merged</strong></td>';
        foreach ($showCols as $h) {
            $cv = $c[$h] ?? '';
            $lv = $l[$h] ?? '';
            $mv = $m[$h] ?? '';
            $cell = ($mv === '' ? '<span class="empty">(empty)</span>' : htmlspecialchars($mv));

            // Markers: blue dot → cloud will change; green dot → local will change
            $markers = '';
            if ($mv !== $cv) $markers .= ' <span class="dot cloud" title="Cloud will change"></span>';
            if ($mv !== $lv) $markers .= ' <span class="dot local" title="Local will change"></span>';

            // Tooltip reason and Aa badge for case-only
            $key = strtoupper($cs) . '|' . $h;
            if (isset($reasonMap[$key]) && $reasonMap[$key] !== '') {
                $reason = htmlspecialchars($reasonMap[$key]);
                $markers .= ' <span class="i" title="'.$reason.'">ⓘ</span>';
                if (stripos($reasonMap[$key], 'case-only') !== false) {
                    $markers .= ' <span class="badge-aa">Aa</span>';
                }
            }

            echo '<td>' . $cell . $markers . '</td>';
        }
        echo '</tr>';

        echo '</tbody></table></div>';
        echo '</div>';
    }
}
