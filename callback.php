<?php

require __DIR__ . '/vendor/autoload.php';

// Load environment variables only if .env exists (local development)
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

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
        getenv('CLIENT_ID') . ':' . getenv('CLIENT_SECRET')
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
    $appUrl = rtrim(getenv('APP_URL'), '/'); // ensures no double slashes

    // -------------------------------------------------------------------------
    // Exchange code for access token
    // -------------------------------------------------------------------------
    $payload = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $appUrl . '/callback.php',
    ];

    [$status, $response] = spotifyTokenRequest($payload);

    if ($status < 200 || $status >= 300 || empty($response['access_token'])) {
        throw new RuntimeException('Spotify token exchange failed. HTTP ' . $status);
    }

    $accessToken  = $response['access_token'];
    $refreshToken = $response['refresh_token'] ?? null;
    $expiresIn    = $response['expires_in'] ?? 3600;

    // -------------------------------------------------------------------------
    // Fetch Spotify user profile
    // -------------------------------------------------------------------------
    $ch = curl_init('https://api.spotify.com/v1/me');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $profileResp = curl_exec($ch);
    if ($profileResp === false) {
        throw new RuntimeException('Failed to fetch user profile: ' . curl_error($ch));
    }
    curl_close($ch);

    $profile = json_decode($profileResp, true);
    if (empty($profile['id'])) {
        throw new RuntimeException('Spotify user ID not found.');
    }

    $spotifyId   = $profile['id'];
    $displayName = $profile['display_name'] ?? '(Unknown)';

    // -------------------------------------------------------------------------
    // Save to database if .env doesn't exist (Render deployment)
    // -------------------------------------------------------------------------
    if (!file_exists(__DIR__ . '/.env')) {
        $dbHost = getenv('DB_HOSTNAME');
        $dbPort = getenv('DB_PORT');
        $dbName = getenv('DB_NAME');
        $dbUser = getenv('DB_USERNAME');
        $dbPass = getenv('DB_PASS');

        $dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // Create table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS spotify_users (
                id SERIAL PRIMARY KEY,
                spotify_id VARCHAR(50) UNIQUE NOT NULL,
                display_name VARCHAR(100),
                access_token TEXT NOT NULL,
                refresh_token TEXT NOT NULL,
                token_expires INT NOT NULL,
                last_login TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Upsert user token
        $stmt = $pdo->prepare("
            INSERT INTO spotify_users (spotify_id, display_name, access_token, refresh_token, token_expires, last_login)
            VALUES (:spotify_id, :display_name, :access_token, :refresh_token, :token_expires, NOW())
            ON CONFLICT (spotify_id)
            DO UPDATE SET
                display_name = EXCLUDED.display_name,
                access_token = EXCLUDED.access_token,
                refresh_token = EXCLUDED.refresh_token,
                token_expires = EXCLUDED.token_expires,
                last_login = NOW();
        ");

        $stmt->execute([
            ':spotify_id'   => $spotifyId,
            ':display_name' => $displayName,
            ':access_token' => $accessToken,
            ':refresh_token' => $refreshToken,
            ':token_expires' => time() + $expiresIn
        ]);
    }

    // -------------------------------------------------------------------------
    // Save session for app
    // -------------------------------------------------------------------------
    $_SESSION['spotify_id']   = $spotifyId;
    $_SESSION['access_token'] = $accessToken;
    $_SESSION['token_expires'] = time() + $expiresIn;

    // -------------------------------------------------------------------------
    // Redirect back to app
    // -------------------------------------------------------------------------
    header('Location: ' . $appUrl . '/index.php');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}
