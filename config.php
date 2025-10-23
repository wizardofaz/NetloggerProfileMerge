<?php
// FILE: config.php
// Basic configuration for the NetLogger Profile Merge Web Tool
return [
    // FTP connection defaults (host/user/path); password is never stored
    'ftp_host' => 'ftp.n7dz.net',
    'ftp_user' => 'u419577197.nltest',
    // Absolute or relative path to the cloud profile file on the FTP server
    'ftp_profile_path' => '/RST-K7RST.prf',

    // Server-side backup directories (outside webroot recommended)
    'backup_dir_cloud' => __DIR__ . '/_backups/cloud',      // backups of pre-merge CLOUD file
    'backup_dir_local' => __DIR__ . '/_backups/local',      // backups of uploaded LOCAL file ("locally also")

    // Maximum backups to retain in each pool
    'max_backups' => 10,

    // Audit log database (SQLite). Will be created if not present.
    'audit_db_path' => __DIR__ . '/_data/audit.sqlite',

    // Allow pushing an extra .bak copy to FTP alongside the target profile
    'push_backup_to_ftp' => false,
];

