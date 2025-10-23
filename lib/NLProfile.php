<?php
// -----------------------------------------------------------------------------
// FILE: lib/NLProfile.php
// -----------------------------------------------------------------------------
class NLProfile {
    public static function parse(string $raw): array {
        if ($raw === '') throw new Exception('Empty profile file.');
        $lines = preg_split("/\r?\n/", $raw);
        $section = null;
        $general = [];
        $callsigns = [];
        $headerCols = [];
        $sawHeader = false;
        foreach ($lines as $lineno => $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === ';' || $t[0] === '#') continue;
            if ($t[0] === '[' && substr($t, -1) === ']') {
                $section = strtoupper(trim($t, '[]'));
                continue;
            }
            if (!$section) continue;
            $eq = strpos($t, '=');
            if ($eq === false) continue;
            $key = rtrim(substr($t, 0, $eq));
            $val = ltrim(substr($t, $eq + 1));
            $val = trim($val);
            $val = self::stripQuotes($val);

            if ($section === 'GENERAL') {
                $general[$key] = $val;
            } elseif ($section === 'CALLSIGNS') {
                if (strcasecmp($key, 'Callsign') === 0) {
                    // Header line with column names inside pipes
                    $cols = self::splitPipes($val);
                    if (count($cols) < 1) throw new Exception('Malformed Callsign header.');
                    $headerCols = $cols; // expect 13
                    $sawHeader = true;
                } else {
                    if (!$sawHeader) throw new Exception('Callsign header missing before entries.');
                    $cs = strtoupper(trim($key));
                    if (!preg_match('/^[A-Z0-9\/]+$/', $cs)) throw new Exception("Invalid callsign '$cs' at line " . ($lineno + 1));
                    $cells = self::splitPipes($val);
                    // Pad/truncate to header count
                    $n = count($headerCols);
                    if (count($cells) < $n) $cells = array_pad($cells, $n, '');
                    elseif (count($cells) > $n) $cells = array_slice($cells, 0, $n);
                    $row = [];
                    for ($i = 0; $i < $n; $i++) $row[$headerCols[$i]] = $cells[$i];
                    $callsigns[$cs] = $row;
                }
            }
        }
        if (!$sawHeader) throw new Exception('No [Callsigns] header found.');
        if (count($headerCols) !== 13) {
            // Not fatal, but warn by throwing up to caller to surface; here we keep parsing
        }
        return [
            'general' => $general,
            'header' => $headerCols,
            'calls' => $callsigns,
        ];
    }

    public static function emit(array $profile): string {
        $general = $profile['general'] ?? [];
        $header = $profile['header'] ?? [];
        $calls = $profile['calls'] ?? [];

        $out = [];
        $out[] = '[General]';
        foreach ($general as $k => $v) {
            $out[] = $k . '="' . self::escapeQuotes($v) . '"';
        }
        $out[] = '[Callsigns]';
        $out[] = 'Callsign = "|' . implode('|', $header) . '"';
        // Sort callsigns alphabetically (case-insensitive)
        $keys = array_keys($calls);
        natcasesort($keys);
        foreach ($keys as $cs) {
            $row = $calls[$cs];
            $cells = [];
            foreach ($header as $h) $cells[] = $row[$h] ?? '';
            $out[] = $cs . ' = "|' . implode('|', $cells) . '|"';
        }
        return implode("\r\n", $out) . "\r\n";
    }

    private static function stripQuotes(string $s): string {
        // strip matching leading/trailing quotes if present
        if (strlen($s) >= 2 && $s[0] === '"' && substr($s, -1) === '"') {
            $s = substr($s, 1, -1);
        }
        // normalize CRLF
        return str_replace(["\r\n", "\n"], ["\n", "\n"], $s);
    }

    private static function escapeQuotes(string $s): string {
        return str_replace('"', '\\"', $s);
    }

    private static function splitPipes(string $s): array {
        // Expect a leading pipe in the data string, but be tolerant
        if ($s !== '' && $s[0] === '|') $s = substr($s, 1);
        if ($s !== '' && substr($s, -1) === '|') $s = substr($s, 0, -1);
        $parts = explode('|', $s);
        // Trim outer whitespace but preserve internal spaces
        return array_map(function ($x) { return rtrim(ltrim($x)); }, $parts);
    }
}

