<?php

require __DIR__ . '/vendor/autoload.php';

// Load environment variables only if .env exists (local development)
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

session_start();

// Clear only Spotify-related session values
unset($_SESSION['access_token'], $_SESSION['refresh_token'], $_SESSION['token_expires']);

// Optionally clear the spotify_id from session
$spotifyId = $_SESSION['spotify_id'] ?? null;
unset($_SESSION['spotify_id']);

// Regenerate session ID (prevents session fixation)
session_regenerate_id(true);

if (!file_exists(__DIR__ . '/.env') && $spotifyId) {
    $pdo = new PDO(
        "pgsql:host=" . getenv('DB_HOSTNAME') . ";port=" . getenv('DB_PORT') . ";dbname=" . getenv('DB_NAME'),
        getenv('DB_USERNAME'),
        getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("UPDATE spotify_users SET last_logout = NOW() WHERE spotify_id = :spotify_id");
    $stmt->execute([':spotify_id' => $spotifyId]);
}

// Determine base URL from environment variable
$appUrl = rtrim(getenv('APP_URL'), '/'); // removes any trailing slash

// Redirect back to UI
header('Location: ' . $appUrl . '/index.php');
exit;
