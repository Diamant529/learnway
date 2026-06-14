<?php
/**
 * Learn Way - Configuration de la base de données et sessions sécurisées
 */

// Sécurisation de la configuration de session
if (session_status() === PHP_SESSION_NONE) {
    // Interdire l'accès aux cookies de session via JS
    ini_set('session.cookie_httponly', 1);
    // Forcer l'utilisation des cookies pour les sessions
    ini_set('session.cookie_use_only_cookies', 1);
    // Empêcher le vol de session par fixation de session
    ini_set('session.use_strict_mode', 1);

    $secureCookie = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// Paramètres de connexion BDD (MySQL) - Utilise les variables d'environnement de Railway en priorité
$db_host = getenv('MYSQLHOST') ?: '127.0.0.1';
$db_port = getenv('MYSQLPORT') ?: '3306';
$db_name = getenv('MYSQLDATABASE') ?: 'learnway_db';
$db_user = getenv('MYSQLUSER') ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '';

// Essayer également de parser MYSQL_URL (spécifié sur Railway)
$dbUrl = getenv('MYSQL_URL');
if ($dbUrl) {
    $dbParts = parse_url($dbUrl);
    if ($dbParts) {
        $db_host = $dbParts['host'] ?? $db_host;
        $db_port = $dbParts['port'] ?? $db_port;
        $db_user = $dbParts['user'] ?? $db_user;
        $db_pass = $dbParts['pass'] ?? $db_pass;
        $db_name = isset($dbParts['path']) ? ltrim($dbParts['path'], '/') : $db_name;
    }
}

define('DB_HOST', $db_host);
define('DB_PORT', $db_port);
define('DB_NAME', $db_name);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // Auto-initialisation de la base de données (création des tables et seeds) si elle est vide
    $dbNeedsInit = false;
    try {
        $stmt = $pdo->query("SELECT 1 FROM users LIMIT 1");
    } catch (PDOException $e) {
        $dbNeedsInit = true;
    }

    if ($dbNeedsInit) {
        $sqlPath = __DIR__ . '/../database.sql';
        if (file_exists($sqlPath)) {
            $sql = file_get_contents($sqlPath);
            $pdo->exec($sql);
        }
    }
} catch (PDOException $e) {
    // En production, afficher un message d'erreur générique.
    // Pour le développement local, afficher le détail de l'erreur.
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// Initialisation du token CSRF si non existant
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
