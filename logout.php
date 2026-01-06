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

// Regenerate session ID (prevents session fixation)
session_regenerate_id(true);

// Determine base URL from environment variable
$appUrl = rtrim(getenv('APP_URL'), '/'); // removes any trailing slash

// Redirect back to UI
header('Location: ' . $appUrl . '/index.php');
exit;
