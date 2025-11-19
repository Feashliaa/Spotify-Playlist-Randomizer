<?php

require __DIR__ . '/vendor/autoload.php';

// load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

/**
 * Send POST request to Spotify’s token endpoint using cURL.
 */
function spotifyTokenRequest(array $payload): array
{
    $url  = 'https://accounts.spotify.com/api/token';

    // Build Basic Auth header
    $authHeader = 'Authorization: Basic ' . base64_encode(
        $_ENV['CLIENT_ID'] . ':' . $_ENV['CLIENT_SECRET']
    );

    $headers = [
        $authHeader,
        'Content-Type: application/x-www-form-urlencoded'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => http_build_query($payload),
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 15,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode($response, true)];
}

// -----------------------------------------------------------------------------
// Validate OAuth "code"
// -----------------------------------------------------------------------------
if (!isset($_GET['code']) || empty($_GET['code'])) {
    http_response_code(400);
    echo 'Missing authorization code.';
    exit;
}

$code = $_GET['code'];

try {
    // -------------------------------------------------------------------------
    // Exchange code for access token
    // -------------------------------------------------------------------------
    $payload = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $_ENV['APP_URL'] . 'callback.php',
    ];

    [$status, $response] = spotifyTokenRequest($payload);

    if ($status < 200 || $status >= 300 || empty($response['access_token'])) {
        throw new RuntimeException('Spotify token exchange failed. HTTP ' . $status);
    }

    // -------------------------------------------------------------------------
    // Save tokens into session
    // -------------------------------------------------------------------------
    $_SESSION['access_token']  = $response['access_token'];
    $_SESSION['refresh_token'] = $response['refresh_token'] ?? null;
    $_SESSION['token_expires'] = time() + ($response['expires_in'] ?? 3600);

    // -------------------------------------------------------------------------
    // Redirect back to app
    // -------------------------------------------------------------------------
    header('Location: ' . $_ENV['APP_URL'] . 'index.php');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}
