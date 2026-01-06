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
 * GET request helper for Spotify API
 */
function spotifyGet(string $url, string $accessToken): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Spotify API error: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode($response, true)];
}

/**
 * Fetch the logged-in Spotify user's profile
 */
function fetchUserProfile(string $accessToken): ?array
{
    try {
        [$status, $data] = spotifyGet('https://api.spotify.com/v1/me', $accessToken);
        return ($status === 200) ? $data : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Fetch playlists for the logged-in user
 */
function fetchUserPlaylists(string $accessToken): ?array
{
    try {
        [$status, $data] = spotifyGet('https://api.spotify.com/v1/me/playlists', $accessToken);
        return ($status === 200) ? $data : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Refresh token helper
 */
function refreshSpotifyToken(string $refreshToken): array
{
    $url  = 'https://accounts.spotify.com/api/token';
    $auth = 'Authorization: Basic ' . base64_encode(getenv('CLIENT_ID') . ':' . getenv('CLIENT_SECRET'));

    $payload = [
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => http_build_query($payload),
        CURLOPT_HTTPHEADER      => [$auth, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 15,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException('Spotify token refresh failed: ' . curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
        throw new RuntimeException('Spotify token refresh failed (HTTP ' . $status . ')');
    }

    return $data;
}

// -----------------------------------------------------------------------------
// Check logged-in status & get access token
// -----------------------------------------------------------------------------
$loggedIn = false;
$accessToken = null;
$userData = null;

if (isset($_SESSION['spotify_id'])) {
    $spotifyId = $_SESSION['spotify_id'];

    // If .env exists, just use session token
    if (file_exists(__DIR__ . '/.env')) {
        $accessToken = $_SESSION['access_token'] ?? null;
        $loggedIn = !empty($accessToken);
    } else {
        // Connect to Render Postgres
        $pdo = new PDO(
            "pgsql:host=" . getenv('DB_HOSTNAME') . ";port=" . getenv('DB_PORT') . ";dbname=" . getenv('DB_NAME'),
            getenv('DB_USERNAME'),
            getenv('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Fetch user tokens
        $stmt = $pdo->prepare("SELECT * FROM spotify_users WHERE spotify_id = :spotify_id");
        $stmt->execute([':spotify_id' => $spotifyId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $accessToken = $user['access_token'];
            $loggedIn = true;

            // Refresh if expired
            if (time() > $user['token_expires']) {
                $newTokens = refreshSpotifyToken($user['refresh_token']);
                $accessToken = $newTokens['access_token'];
                $expiresIn = $newTokens['expires_in'] ?? 3600;

                // Update DB and session
                $update = $pdo->prepare("
                    UPDATE spotify_users
                    SET access_token = :access_token, token_expires = :token_expires
                    WHERE spotify_id = :spotify_id
                ");
                $update->execute([
                    ':access_token' => $accessToken,
                    ':token_expires' => time() + $expiresIn,
                    ':spotify_id' => $spotifyId
                ]);

                $_SESSION['access_token'] = $accessToken;
                $_SESSION['token_expires'] = time() + $expiresIn;
            }
        } else {
            $loggedIn = false;
        }
    }

    // Fetch user profile if token exists
    if ($loggedIn) {
        $userData = fetchUserProfile($accessToken);
    }
}

// -----------------------------------------------------------------------------
// Build login URL
// -----------------------------------------------------------------------------
$clientID    = getenv('CLIENT_ID');
$appUrl      = rtrim(getenv('APP_URL'), '/'); // ensures no double slashes
$redirectURL = $appUrl . '/callback.php';
$scopes      = 'user-read-private playlist-read-private playlist-read-collaborative playlist-modify-private playlist-modify-public';
$state       = bin2hex(random_bytes(16));

$authURL = 'https://accounts.spotify.com/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $clientID,
    'scope'         => $scopes,
    'redirect_uri'  => $redirectURL,
    'state'         => $state,
    'show_dialog'   => 'true'
]);
