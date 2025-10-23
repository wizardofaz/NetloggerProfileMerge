<?php
// -----------------------------------------------------------------------------
// FILE: lib/ftp_client.php
// -----------------------------------------------------------------------------
class FTPClient {
    private string $host;
    private string $user;
    private string $pass;
    private $conn = null;

    public function __construct(string $host, string $user, string $pass) {
        $this->host = $host; $this->user = $user; $this->pass = $pass;
    }

    public function connect(): void {
        $this->conn = @ftp_connect($this->host);
        if (!$this->conn) throw new Exception('Unable to connect to FTP host.');
        if (!@ftp_login($this->conn, $this->user, $this->pass)) throw new Exception('FTP login failed.');
        ftp_pasv($this->conn, true);
    }

    public function getFileContents(string $path): string {
        $temp = fopen('php://temp', 'r+');
        if (!@ftp_fget($this->conn, $temp, $path, FTP_BINARY)) throw new Exception('FTP download failed for ' . $path);
        rewind($temp);
        $data = stream_get_contents($temp);
        fclose($temp);
        return (string)$data;
    }

    public function rename(string $fromBase, string $toBase): void {
        if (!@ftp_rename($this->conn, $fromBase, $toBase)) throw new Exception('FTP rename failed.');
    }

    public function getMDTM(string $path) {
        $t = @ftp_mdtm($this->conn, $path);
        return ($t === -1) ? false : $t; // returns Unix timestamp or false
    }

    public function getSize(string $path) {
        $s = @ftp_size($this->conn, $path);
        return ($s === -1) ? false : $s;
    }

    // In FTPClient:
    public function renamePath(string $fromAbs, string $toAbs): bool {
        return @ftp_rename($this->conn, $fromAbs, $toAbs);
    }

    // --- Safe wrappers (return null on unsupported) ---
    public function mdtmSafe(string $path): ?string {
        $t = @ftp_mdtm($this->conn, $path);
        if ($t === -1 || $t === false) return null;
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }
    public function sizeSafe(string $path): ?int {
        $s = @ftp_size($this->conn, $path);
        if ($s === -1 || $s === false) return null;
        return (int)$s;
    }

    // --- FS helpers ---
    public function mkdIfNeeded(string $dir): void {
        @ftp_mkdir($this->conn, $dir); // ignore "exists" errors
    }
    public function listNames(string $dir): array {
        $list = @ftp_nlist($this->conn, $dir);
        if (!is_array($list)) return [];
        return array_map(static fn($p) => basename($p), $list);
    }

    // --- Backup rotation: keep newest $max (by timestamp prefix) ---
    public function rotateBackups(string $backupDir, int $max): void {
        $files = $this->listNames($backupDir);
        if (!$files) return;
        $candidates = array_values(array_filter($files, static function ($f) {
            return (bool)preg_match('/^\d{8}T\d{6}Z_.*\.prf$/', $f);
        }));
        rsort($candidates, SORT_STRING); // newest first
        if (count($candidates) <= $max) return;
        foreach (array_slice($candidates, $max) as $name) {
            @ftp_delete($this->conn, rtrim($backupDir, '/') . '/' . $name);
        }
    }

    // --- Simple lock file in the same dir (TTL default 5 min) ---
    // return ['status'=>'ok'] OR ['status'=>'busy','message'=>'...','holder'=>'...']
    public function lockAcquire(string $dir, string $lockName, string $who, bool $steal=false, int $ttlSeconds=300): array {
        $lockPath = rtrim($dir, '/') . '/' . $lockName;

        // present?
        $exists = in_array($lockName, $this->listNames($dir), true);
        if ($exists) {
            $mtimeIso = $this->mdtmSafe($lockPath);
            $holder = '(unknown)';

            // best-effort read of lock holder
            $tmp = fopen('php://temp', 'w+');
            if (@ftp_fget($this->conn, $tmp, $lockPath, FTP_ASCII)) {
                rewind($tmp);
                $holder = trim((string)stream_get_contents($tmp));
            }
            if (is_resource($tmp)) fclose($tmp);

            $fresh = false;
            if ($mtimeIso) {
                $t = strtotime($mtimeIso);
                if ($t !== false && (time() - $t) < $ttlSeconds) $fresh = true;
            }
            if ($fresh && !$steal) {
                return ['status' => 'busy', 'message' => 'Active lock present', 'holder' => $holder];
            }
            // steal or stale → overwrite
            $blob = "stolen by $who at " . gmdate('Y-m-d\TH:i:s\Z');
            $s = fopen('php://temp', 'w+'); fwrite($s, $blob); rewind($s);
            @ftp_fput($this->conn, $lockPath, $s, FTP_ASCII);
            if (is_resource($s)) fclose($s);
            return ['status' => 'ok', 'message' => 'Lock stolen'];
        }

        // create new lock
        $blob = "$who at " . gmdate('Y-m-d\TH:i:s\Z');
        $s = fopen('php://temp', 'w+'); fwrite($s, $blob); rewind($s);
        $ok = @ftp_fput($this->conn, $lockPath, $s, FTP_ASCII);
        if (is_resource($s)) fclose($s);
        if (!$ok) return ['status' => 'busy', 'message' => 'Failed to create lock'];
        return ['status' => 'ok'];
    }
    public function lockRelease(string $dir, string $lockName): void {
        @ftp_delete($this->conn, rtrim($dir, '/') . '/' . $lockName);
    }

    // 1) stash last error
    public ?string $lastError = null;

    // 2) atomic upload to a FINAL PATH (class generates temp name internally)
    public function atomicUploadPath(string $remoteFinalPath, string $data, ?string &$errMsg): bool {
        $this->lastError = null;
        $errMsg = null;

        // split remote path into dir + base
        $dir  = rtrim(dirname($remoteFinalPath), '/');
        $base = basename($remoteFinalPath);
        if ($dir === '' || $dir === '/') $dir = '.';

        // temp path is in the same directory (required for RNTO)
        $temp = '.' . $base . '.uploading.' . bin2hex(random_bytes(4));
        $tempPath  = $dir . '/' . $temp;
        $finalPath = $dir . '/' . $base;

        // upload with error trapping (binary)
        $s = fopen('php://temp', 'w+');
        fwrite($s, $data);
        rewind($s);

        // trap warnings so we get a useful message if the server rejects STOR
        set_error_handler(function($sev, $msg) { $this->lastError ??= $msg; return false; });
        $ok = @ftp_fput($this->conn, $tempPath, $s, FTP_BINARY);
        restore_error_handler();
        fclose($s);

        if (!$ok) {
            $errMsg = $this->lastError ?: 'ftp_fput failed';
            return false;
        }

        // atomic rename to the final path
        if (!@ftp_rename($this->conn, $tempPath, $finalPath)) {
            // best-effort cleanup
            @ftp_delete($this->conn, $tempPath);
            $errMsg = 'atomic rename failed';
            return false;
        }
        return true;
    }

    // 3) optional diagnostics (handy while we debug)
    public function pwd(): string {
        $p = @ftp_pwd($this->conn);
        return $p !== false ? $p : '';
    }

    // --- Atomic upload: STOR temp then RNTO final ---
    public function atomicUpload(string $dir, string $file, string $data, ?string &$errMsg): bool {
        $errMsg = '';
        $temp = '.' . $file . '.uploading.' . bin2hex(random_bytes(4));
        $tempPath  = rtrim($dir, '/') . '/' . $temp;
        $finalPath = rtrim($dir, '/') . '/' . $file;

        $s = fopen('php://temp', 'w+');
        fwrite($s, $data); rewind($s);
        $ok = @ftp_fput($this->conn, $tempPath, $s, FTP_BINARY);
        if (is_resource($s)) fclose($s);
        if (!$ok) { $errMsg = 'STOR to temp failed'; return false; }

        if (!@ftp_rename($this->conn, $tempPath, $finalPath)) {
            @ftp_delete($this->conn, $tempPath);
            $errMsg = 'atomic rename failed';
            return false;
        }
        return true;
    }

    // Close explicitly (optional convenience)
    public function close(): void {
        if ($this->conn) { @ftp_close($this->conn); $this->conn = null; }
    }

    // Helper to trap PHP warnings around an FTP call
    private function trap(callable $fn, ?string &$msg): bool {
        $msg = null;
        set_error_handler(function ($severity, $message) use (&$msg) {
            // record first warning from the FTP layer
            if ($msg === null) $msg = $message;
            // continue normal flow
            return false;
        });
        try {
            $ok = $fn();
        } finally {
            restore_error_handler();
        }
        return $ok;
    }

    // Safer putString that reports errors and lets us choose ASCII/BINARY
    // UNUSED
    public function putString(string $remotePath, string $data, int $mode = FTP_BINARY): bool {
        $this->lastError = null;
        $s = fopen('php://temp', 'w+');
        fwrite($s, $data);
        rewind($s);
        $msg = null;
        $ok = $this->trap(fn() => @ftp_fput($this->conn, $remotePath, $s, $mode), $msg);
        fclose($s);
        if (!$ok) $this->lastError = $msg ?: 'ftp_fput failed';
        return $ok;
    }

}
