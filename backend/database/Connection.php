<?php
// ============================================================================
// Database Connection Singleton (PDO)
// ============================================================================

require_once __DIR__ . '/../config/config.php';

class Database {
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO {
        if (self::$pdo === null) {
            $portPart = (defined('DB_PORT') && !empty(DB_PORT)) ? ";port=" . DB_PORT : "";
            $dsn = "mysql:host=" . DB_HOST . $portPart . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            // SSL/TLS connection for cloud databases like TiDB
            if (defined('DB_SSL_ENABLE') && DB_SSL_ENABLE) {
                if (defined('DB_SSL_CA') && !empty(DB_SSL_CA) && file_exists(DB_SSL_CA)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
                } else {
                    $systemCaPaths = [
                        __DIR__ . '/../config/cacert.pem',             // Bundled in project
                        'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt', // Windows XAMPP
                        '/etc/ssl/certs/ca-certificates.crt',          // Debian / Ubuntu / Railway
                        '/etc/pki/tls/certs/ca-bundle.crt',            // RedHat / CentOS
                        '/etc/ssl/cert.pem'
                    ];
                    $caFound = false;
                    foreach ($systemCaPaths as $caPath) {
                        if (file_exists($caPath)) {
                            $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
                            $caFound = true;
                            break;
                        }
                    }
                    if (!$caFound && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                    }
                }
            }

            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                die("Database Connection Failed: " . htmlspecialchars($e->getMessage()));
            }
        }
        return self::$pdo;
    }
}

// Global shorthand
function getDBConnection(): PDO {
    return Database::getConnection();
}
