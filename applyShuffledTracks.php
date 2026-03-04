<?php
session_start();

header('Content-Type: application/json');

function spotifyRequest(string $method, string $url, string $accessToken, ?array $payload = null): array
{
    $ch = curl_init($url);

    $headers = ['Authorization: Bearer ' . $accessToken];

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HEADER         => true,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerBlock = substr($response, 0, $headerSize);
    $body       = substr($response, $headerSize);
    $decoded    = json_decode($body, true);

    $retryAfter = 5; // default retry after 5 seconds
    if (preg_match('/Retry-After:\s*(\d+)/i', $headerBlock, $m)) {
        $retryAfter = (int)$m[1];
    }

    return [$statusCode, $decoded, $retryAfter];
}

function getValidAccessToken(): string
{
    if (!isset($_SESSION['access_token'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated.']);
        exit;
    }

    if (time() >= ($_SESSION['token_expires'] - 60)) {
        $refreshToken = $_SESSION['refresh_token'] ?? null;
        if (!$refreshToken) {
            http_response_code(401);
            echo json_encode(['error' => 'Session expired. Please log in again.']);
            exit;
        }

        $ch = curl_init('https://accounts.spotify.com/api/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode(getenv('CLIENT_ID') . ':' . getenv('CLIENT_SECRET')),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]),
        ]);

        $data = json_decode(curl_exec($ch), true);

        if (empty($data['access_token'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Token refresh failed. Please log in again.']);
            exit;
        }

        $_SESSION['access_token']  = $data['access_token'];
        $_SESSION['token_expires'] = time() + ($data['expires_in'] ?? 3600);

        if (!empty($data['refresh_token'])) {
            $_SESSION['refresh_token'] = $data['refresh_token'];
        }
    }

    return $_SESSION['access_token'];
}

function addTracksInChunks(string $playlistId, array $uris, string $accessToken): void
{
    if (empty($uris)) return;

    $apiUrl = "https://api.spotify.com/v1/playlists/{$playlistId}/tracks";

    foreach (array_chunk($uris, 100) as $chunk) {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            [$status, $body, $retryAfter] = spotifyRequest('POST', $apiUrl, $accessToken, ['uris' => $chunk]);

            if ($status === 429) {
                sleep($retryAfter + 1);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('Failed to add tracks (HTTP ' . $status . ').');
            }

            break;
        }
    }
}

// -----------------------------------------------------------------------------
// Main
// -----------------------------------------------------------------------------

$accessToken = getValidAccessToken();

$body = json_decode(file_get_contents('php://input'), true);

$rawPlaylistId = $body['playlist_id'] ?? '';
$playlistId    = preg_replace('/[^A-Za-z0-9]/', '', $rawPlaylistId);
$uris          = $body['uris'] ?? [];

if ($playlistId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid playlist_id.']);
    exit;
}

if (empty($uris) || !is_array($uris)) {
    http_response_code(400);
    echo json_encode(['error' => 'No URIs provided.']);
    exit;
}

try {
    // Clear the playlist
    [$status,] = spotifyRequest(
        'PUT',
        "https://api.spotify.com/v1/playlists/{$playlistId}/tracks",
        $accessToken,
        ['uris' => []]
    );

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Failed to clear playlist (HTTP ' . $status . ').');
    }

    // Add shuffled tracks back
    addTracksInChunks($playlistId, $uris, $accessToken);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
