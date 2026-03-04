<?php
session_start();

header('Content-Type: application/json');

function spotifyRequest(string $method, string $url, string $accessToken, ?array $payload = null): array
{
    $ch = curl_init($url);

    $headers = [
        'Authorization: Bearer ' . $accessToken,
    ];

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

    $retryAfter = 5; // default retry after 5 seconds
    if (preg_match('/Retry-After:\s*(\d+)/i', $headerBlock, $m)) {
        $retryAfter = (int)$m[1];
    }


    $decoded = json_decode($response, true);
    return [$statusCode, $decoded, $retryAfter];
}
function getValidAccessToken(): string
{
    if (!isset($_SESSION['access_token'])) {
        http_response_code(401);
        exit('Not authenticated.');
    }

    // Refresh 60 seconds early to avoid edge cases
    if (time() >= ($_SESSION['token_expires'] - 60)) {
        $refreshToken = $_SESSION['refresh_token'] ?? null;
        if (!$refreshToken) {
            http_response_code(401);
            exit('Session expired. Please log in again.');
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
            exit('Token refresh failed. Please log in again.');
        }

        $_SESSION['access_token']  = $data['access_token'];
        $_SESSION['token_expires'] = time() + ($data['expires_in'] ?? 3600);

        // Spotify only returns a new refresh_token sometimes
        if (!empty($data['refresh_token'])) {
            $_SESSION['refresh_token'] = $data['refresh_token'];
        }
    }

    return $_SESSION['access_token'];
}

function addTracksInChunks(string $playlistId, array $uris, string $accessToken): void
{
    if (empty($uris)) {
        return;
    }

    $apiUrl    = "https://api.spotify.com/v1/playlists/{$playlistId}/tracks";
    $uriChunks = array_chunk($uris, 100);

    foreach ($uriChunks as $chunk) {
        [$status, $body] = spotifyRequest('POST', $apiUrl, $accessToken, ['uris' => $chunk]);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                'Failed to add tracks to playlist (HTTP ' . $status . ')'
            );
        }
    }
}

// -----------------------------------------------------------------------------
// Main script
// -----------------------------------------------------------------------------
$accessToken = getValidAccessToken();

// Basic validation for playlist_id
$rawPlaylistId = $_GET['playlist_id'] ?? '';
$playlistId    = preg_replace('/[^A-Za-z0-9]/', '', $rawPlaylistId);
$offset = max(0, intval($_GET['offset'] ?? 0));

if ($playlistId === '') {
    http_response_code(400);
    echo 'Missing or invalid playlist_id.';
    exit;
}

try {
    // -------------------------------------------------------------------------
    // 1. Fetch all tracks in the playlist (handle pagination)
    // -------------------------------------------------------------------------
    $nextTracksUrl = "https://api.spotify.com/v1/playlists/{$playlistId}/tracks?limit=100&offset={$offset}";


    // Retry once on 429
    for ($attempt = 0; $attempt < 5; $attempt++) {
        [$status, $tracks, $retryAfter] = spotifyRequest('GET', $nextTracksUrl, $accessToken);

        if ($status === 429) {
            sleep($retryAfter + 1);
            continue;
        }

        break;
    }

    if ($status !== 200 || !is_array($tracks)) {
        throw new RuntimeException('Failed to fetch tracks (HTTP ' . $status . '). Response: ' . json_encode($tracks));
    }

    $uris = [];
    $skipped = [];
    $items = $tracks['items'] ?? [];

    foreach ($items as $item) {
        // Skip items without a proper track object
        if (!isset($item['track'])) {
            continue;
        }

        $track = $item['track'];

        // Non-local tracks (have external Spotify URL)
        if (isset($track['external_urls']['spotify'])) {
            $uris[] = $track['uri'];
        } else {
            $artists = array_column($track['artists'] ?? [], 'name');
            $skipped[] = ($track['name'] ?? 'Unknown Track') . ' by ' . implode(', ', $artists);
        }
    }

    echo json_encode([
        'uris'        => $uris,
        'skipped'     => $skipped,
        'total'       => $tracks['total'],
        'next_offset' => ($tracks['next'] !== null) ? $offset + 100 : null,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
