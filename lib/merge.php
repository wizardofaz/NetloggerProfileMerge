<?php
// -----------------------------------------------------------------------------
// FILE: lib/merge.php
// -----------------------------------------------------------------------------
function merge_profiles(array $cloud, array $local, array $opts): array {
    $assumeLocalNewer = !empty($opts['assume_local_newer']);
    $cloudMtime = $opts['cloud_mtime'] ?? null; // int|null
    $localMtime = $opts['local_mtime'] ?? null; // typically null

    // Canonical header: prefer cloud header (position-based mapping)
    $header = $cloud['header'];
    $hCount = count($header);

    // Union of callsigns (never delete)
    $keys = array_unique(array_merge(array_keys($cloud['calls']), array_keys($local['calls'])));
    natcasesort($keys);

    $mergedCalls = [];
    $changes = [];
    $counts = [
        'added' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'conflicts_newer' => 0,
        'conflicts_longer' => 0,
    ];

    foreach ($keys as $cs) {
        $cRow = $cloud['calls'][$cs] ?? null;
        $lRow = $local['calls'][$cs] ?? null;

        if (!$cRow && $lRow) {
            // New callsign entirely from local
            $mergedCalls[$cs] = normalize_row_to_header($lRow, $header);
            $counts['added']++;
            // For diff rows, include field-wise additions
            foreach ($header as $h) {
                $lv = $lRow[$h] ?? '';
                if ($lv !== '') {
                    $changes[] = [
                        'callsign' => $cs,
                        'field' => $h,
                        'from_cloud' => '',
                        'to' => $lv,
                        'reason' => 'added (local only)'
                    ];
                }
            }
            continue;
        }

        if ($cRow && !$lRow) {
            // Exists only in cloud — keep as is (cloud won't change),
            // but record local-only impact per field so ⓘ shows and counts are correct.
            $mergedRow = normalize_row_to_header($cRow, $header);
            $mergedCalls[$cs] = $mergedRow;

            foreach ($header as $h) {
                $cv = $mergedRow[$h] ?? '';
                if (trim($cv) === '') continue; // nothing to propagate
                $changes[] = [
                    'callsign'   => $cs,
                    'field'      => $h,
                    'from_cloud' => $cv,
                    'to'         => $cv,
                    'reason'     => 'present in cloud only (local will change)',
                    'cloud_val'  => $cv,
                    'local_val'  => '',
                    'merged_val' => $cv,
                ];
                // direction counters: cloud stays the same; local will change
                $counts['fields_local_change'] = ($counts['fields_local_change'] ?? 0) + 1;
            }

            // Keep row-level classification as unchanged (since cloud file doesn't change)
            $counts['unchanged']++;
            continue;
        }

        // Both present — field-wise merge
        $out = [];
        $rowChanged = false;
        foreach ($header as $idx => $h) {
            $cv = $cRow[$h] ?? '';
            $lv = $lRow[$h] ?? '';

            $cvTrim = trim($cv);
            $lvTrim = trim($lv);

            $mv = null;           // merged value
            $reasonText = null;   // human-readable explanation
            $conflictBucket = null; // 'newer' or 'longer'

            // DEBUG: list the actual field keys the merge loop sees for this callsign
            if (isset($_GET['diag']) && isset($_GET['check'])) {
                [$chkCs, $chkField] = array_pad(explode('|', $_GET['check'], 2), 2, '');
                if (strtoupper($cs) === strtoupper($chkCs)) {
                    // Print each field name once for this callsign
                    static $printedForCs = [];
                    $keyForList = strtoupper($cs) . '|' . $h;
                    if (empty($printedForCs[$keyForList])) {
                        $printedForCs[$keyForList] = true;
                        echo "[MERGE PROBE fields] $cs sees field key: >>>" . $h . "<<<\n";
                    }
                }
            }

            // DEBUG PROBE: single-cell trace when requested (no behavior change)
            if (isset($_GET['diag']) && isset($_GET['check'])) {
                [$chkCs, $chkField] = array_pad(explode('|', $_GET['check'], 2), 2, '');
                if (strtoupper($cs) === strtoupper($chkCs) && $h === $chkField) {
                    header('Content-Type: text/plain; charset=UTF-8');
                    echo "[MERGE PROBE before decision] $cs | $h\n";
                    echo "  cloud raw:  <<<" . $cv . ">>>\n";
                    echo "  local raw:  <<<" . $lv . ">>>\n";
                    echo "  cloud trim: <<<" . $cvTrim . ">>>\n";
                    echo "  local trim: <<<" . $lvTrim . ">>>\n";
                    echo "  assumeLocalNewer=" . ($assumeLocalNewer ? '1' : '0') . " cloudMtime=" . var_export($cloudMtime,true) . " localMtime=" . var_export($localMtime,true) . "\n";
                    // don't exit; let the loop proceed so we see what it chooses later in the function-level diag
                }
            }

            // 1) both empty -> no change
            if ($cvTrim === '' && $lvTrim === '') {
                $mv = '';
                $out[$h] = $mv;
                continue;
            }

            // 2) cloud empty, local non-empty -> local wins (affects cloud)
            if ($cvTrim === '' && $lvTrim !== '') {
                $mv = $lv;
                $reasonText = 'local non-empty over empty';
            }
            // 3) local empty, cloud non-empty -> keep cloud (affects local)
            elseif ($lvTrim === '' && $cvTrim !== '') {
                $mv = $cv;
                $reasonText = 'local empty → kept cloud (local will change)';
            }
            else {
                // 4) both non-empty
                if ($cvTrim === $lvTrim) {
                    $mv = $cv; // identical, no change
                } else {
                    // 4a) case-only -> prefer mixed case
                    if (strcasecmp($cvTrim, $lvTrim) === 0) {
                        $pick = prefers_mixed_case($cvTrim, $lvTrim)
                            ? $lv
                            : (prefers_mixed_case($lvTrim, $cvTrim) ? $cv : $lv);
                        $mv = $pick;
                        $reasonText = 'case-only difference → prefer mixed case';
                    } else {
                        // 4b) timestamps / assumption
                        $winner = null;
                        if ($assumeLocalNewer) {
                            $winner = 'local';
                        } elseif ($cloudMtime !== null && $localMtime !== null) {
                            $winner = ($localMtime > $cloudMtime) ? 'local' : 'cloud';
                        } elseif ($localMtime !== null && $cloudMtime === null) {
                            $winner = 'local';
                        }
                        if ($winner === 'local') {
                            $mv = $lv;
                            $reasonText = 'local won — newer';
                            $conflictBucket = 'newer';
                        } elseif ($winner === 'cloud') {
                            $mv = $cv;
                            $reasonText = 'cloud kept — newer (local will change)';
                            $conflictBucket = 'newer';
                        } else {
                            // 4c) tie-breaker: longer trimmed string wins
                            $mv = (mb_strlen($lvTrim) > mb_strlen($cvTrim)) ? $lv : $cv;
                            $reasonText = 'tie → longer string';
                            $conflictBucket = 'longer';
                        }
                    }
                }
            }

            // write merged value
            $out[$h] = $mv;

            // record change + counts if merged differs from either side
            $chgCloud = ($mv !== $cv);
            $chgLocal = ($mv !== $lv);

            if ($chgCloud || $chgLocal) {
                if ($chgCloud) {
                    $rowChanged = true; // cloud will change
                    $counts['fields_cloud_change'] = ($counts['fields_cloud_change'] ?? 0) + 1;
                }
                if ($chgLocal) {
                    $counts['fields_local_change'] = ($counts['fields_local_change'] ?? 0) + 1;
                }
                if ($conflictBucket === 'newer')  $counts['conflicts_newer']  = ($counts['conflicts_newer']  ?? 0) + 1;
                if ($conflictBucket === 'longer') $counts['conflicts_longer'] = ($counts['conflicts_longer'] ?? 0) + 1;

                $changes[] = [
                    'callsign'   => $cs,
                    'field'      => $h,
                    'from_cloud' => $cv,
                    'to'         => $mv,
                    'reason'     => $reasonText,
                    'cloud_val'  => $cv,
                    'local_val'  => $lv,
                    'merged_val' => $mv,
                ];
            }
        }

        $mergedCalls[$cs] = $out;
        if ($rowChanged) $counts['updated']++; else $counts['unchanged']++;
    }

    // General section merge
    $general = $cloud['general'];
    foreach ($local['general'] as $k => $v) {
        if (!array_key_exists($k, $general) || $general[$k] === '') {
            $general[$k] = $v;
        } elseif ($k === 'ProfileVersion') {
            // do not auto-resolve differences here; already handled upstream via confirm banner
        } elseif ($k === 'ProfileURL') {
            if ($general[$k] === '' && $v !== '') $general[$k] = $v; // prefer cloud else keep
        }
    }

    $merged = [
        'general' => $general,
        'header' => $header,
        'calls' => $mergedCalls,
    ];

    return [
        'merged' => $merged,
        'diff' => [
            'counts' => $counts,
            'changes' => $changes,
        ],
    ];
}

function normalize_row_to_header(array $row, array $header): array {
    $out = [];
    foreach ($header as $h) $out[$h] = $row[$h] ?? '';
    return $out;
}

function prefers_mixed_case(string $a, string $b): bool {
    // return true if $a is mixed-case (not all upper) and $b is all upper
    $isAllUpper = function ($s) {
        // treat non-letters as neutral; check if any letters exist and all are upper
        $letters = preg_replace('/[^A-Za-z]/', '', $s);
        if ($letters === '') return false; // no letters → not considered all-upper for our rule
        return strtoupper($letters) === $letters;
    };
    return !$isAllUpper($a) && $isAllUpper($b);
}

