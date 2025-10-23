<?php
// -----------------------------------------------------------------------------
// FILE: lib/audit.php
// -----------------------------------------------------------------------------
class Audit {
    private $pdo;
    public function __construct(string $sqlitePath) {
        $this->pdo = new PDO('sqlite:' . $sqlitePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->init();
    }
    private function init(): void {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS merges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts TEXT,
            who TEXT,
            client_ip TEXT,
            cloud_path TEXT,
            cloud_size_before INTEGER,
            cloud_mdtm_before INTEGER,
            cloud_size_after INTEGER,
            cloud_mdtm_after INTEGER,
            local_name TEXT,
            added INTEGER,
            updated INTEGER,
            unchanged INTEGER,
            conflicts_newer INTEGER,
            conflicts_longer INTEGER,
            backup_cloud TEXT,
            backup_local TEXT,
            rechecked INTEGER,
            result TEXT,
            error_msg TEXT
        )');
    }
    public function append(array $row): void {
        $stmt = $this->pdo->prepare('INSERT INTO merges (ts,who,client_ip,cloud_path,cloud_size_before,cloud_mdtm_before,cloud_size_after,cloud_mdtm_after,local_name,added,updated,unchanged,conflicts_newer,conflicts_longer,backup_cloud,backup_local,rechecked,result,error_msg) VALUES (:ts,:who,:client_ip,:cloud_path,:cloud_size_before,:cloud_mdtm_before,:cloud_size_after,:cloud_mdtm_after,:local_name,:added,:updated,:unchanged,:conflicts_newer,:conflicts_longer,:backup_cloud,:backup_local,:rechecked,:result,:error_msg)');
        $stmt->execute([
            ':ts' => $row['ts'] ?? null,
            ':who' => $row['who'] ?? null,
            ':client_ip' => $row['client_ip'] ?? null,
            ':cloud_path' => $row['cloud_path'] ?? null,
            ':cloud_size_before' => $row['cloud_size_before'] ?? null,
            ':cloud_mdtm_before' => $row['cloud_mdtm_before'] ?? null,
            ':cloud_size_after' => $row['cloud_size_after'] ?? null,
            ':cloud_mdtm_after' => $row['cloud_mdtm_after'] ?? null,
            ':local_name' => $row['local_name'] ?? null,
            ':added' => $row['added'] ?? 0,
            ':updated' => $row['updated'] ?? 0,
            ':unchanged' => $row['unchanged'] ?? 0,
            ':conflicts_newer' => $row['conflicts_newer'] ?? 0,
            ':conflicts_longer' => $row['conflicts_longer'] ?? 0,
            ':backup_cloud' => $row['backup_cloud'] ?? null,
            ':backup_local' => $row['backup_local'] ?? null,
            ':rechecked' => $row['rechecked'] ?? 0,
            ':result' => $row['result'] ?? null,
            ':error_msg' => $row['error_msg'] ?? null,
        ]);
    }
}
