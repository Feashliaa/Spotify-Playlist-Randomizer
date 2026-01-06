<?php
session_start();

/**
 * Spotify API request helper using cURL.
 */
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
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$statusCode, json_decode($response, true)];
}

/**
 * Add tracks to a playlist in chunks of 100 URIs.
 */
function addTracksInChunks(string $playlistId, array $uris, string $accessToken): void
{
    if (empty($uris)) return;

    $apiUrl = "https://api.spotify.com/v1/playlists/{$playlistId}/tracks";
    $chunks = array_chunk($uris, 100);

    foreach ($chunks as $chunk) {
        [$status,] = spotifyRequest('POST', $apiUrl, $accessToken, ['uris' => $chunk]);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Failed to add tracks (HTTP ' . $status . ')');
        }
    }
}

/**
 * Refresh token helper
 */
function refreshSpotifyToken(string $refreshToken): array
{
    $url  = 'https://accounts.spotify.com/api/token';
    $auth = 'Authorization: Basic ' . base64_encode(getenv('CLIENT_ID') . ':' . getenv('CLIENT_SECRET'));

    $payload = ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => [$auth, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    if ($response === false) throw new RuntimeException('Spotify token refresh failed: ' . curl_error($ch));

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
        throw new RuntimeException('Spotify token refresh failed (HTTP ' . $status . ')');
    }

    return $data;
}

// -----------------------------------------------------------------------------
// Get access token
// -----------------------------------------------------------------------------
$accessToken = null;

if (!isset($_SESSION['spotify_id'])) {
    http_response_code(401);
    echo 'Not authenticated.';
    exit;
}

$spotifyId = $_SESSION['spotify_id'];

// Local dev uses session only
if (file_exists(__DIR__ . '/.env')) {
    $accessToken = $_SESSION['access_token'] ?? null;
} else {
    $pdo = new PDO(
        "pgsql:host=" . getenv('DB_HOSTNAME') . ";port=" . getenv('DB_PORT') . ";dbname=" . getenv('DB_NAME'),
        getenv('DB_USERNAME'),
        getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("SELECT * FROM spotify_users WHERE spotify_id = :spotify_id");
    $stmt->execute([':spotify_id' => $spotifyId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo 'User not found in database.';
        exit;
    }

    $accessToken = $user['access_token'];

    if (time() > $user['token_expires']) {
        $newTokens = refreshSpotifyToken($user['refresh_token']);
        $accessToken = $newTokens['access_token'];
        $expiresIn = $newTokens['expires_in'] ?? 3600;

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
}

// -----------------------------------------------------------------------------
// Proceed with original shuffle logic
// -----------------------------------------------------------------------------
$rawPlaylistId = $_GET['playlist_id'] ?? '';
$playlistId = preg_replace('/[^A-Za-z0-9]/', '', $rawPlaylistId);

if ($playlistId === '') {
    http_response_code(400);
    echo 'Missing or invalid playlist_id.';
    exit;
}

try {
    // -------------------------------------------------------------------------
    // 1. Fetch all tracks in the playlist (handle pagination)
    // -------------------------------------------------------------------------
    $nextTracksUrl = "https://api.spotify.com/v1/playlists/{$playlistId}/tracks";

    $allTracks   = [];
    $localTracks = [];

    while ($nextTracksUrl !== null) {
        [$status, $tracks] = spotifyRequest('GET', $nextTracksUrl, $accessToken);

        if ($status !== 200 || !is_array($tracks)) {
            throw new RuntimeException('Failed to fetch tracks (HTTP ' . $status . ').');
        }

        if (empty($tracks['items']) || !is_array($tracks['items'])) {
            break;
        }

        foreach ($tracks['items'] as $item) {
            // Skip items without a proper track object
            if (!isset($item['track'])) {
                continue;
            }

            $track = $item['track'];

            // Non-local tracks (have external Spotify URL)
            if (isset($track['external_urls']['spotify'])) {
                $allTracks[] = $item;
            } else {
                // Local or unavailable tracks
                $artists = [];
                if (!empty($track['artists']) && is_array($track['artists'])) {
                    foreach ($track['artists'] as $artist) {
                        if (isset($artist['name'])) {
                            $artists[] = $artist['name'];
                        }
                    }
                }

                $artistNames = implode(', ', $artists);
                $trackName   = $track['name'] ?? '(unknown track)';

                echo 'Skipped track (local/unavailable): ' .
                    htmlspecialchars($trackName . ' by ' . $artistNames, ENT_QUOTES, 'UTF-8') .
                    '<br>' . PHP_EOL;

                $localTracks[] = $item;
            }
        }

        $nextTracksUrl = $tracks['next'] ?? null;
    }

    if (empty($allTracks)) {
        echo 'No playable tracks found in this playlist.';
        exit;
    }

    // -------------------------------------------------------------------------
    // 2. Fetch playlist metadata
    // -------------------------------------------------------------------------
    [$status, $playlistData] = spotifyRequest(
        'GET',
        "https://api.spotify.com/v1/playlists/{$playlistId}",
        $accessToken
    );

    if ($status !== 200 || !is_array($playlistData)) {
        throw new RuntimeException('Failed to fetch playlist metadata (HTTP ' . $status . ').');
    }

    $playlistName = $playlistData['name'] ?? '(Unknown Playlist)';

    // -------------------------------------------------------------------------
    // 3. Create a backup playlist
    // -------------------------------------------------------------------------
    $backupPayload = [
        'name'        => 'Backup of Playlist: ' . $playlistName,
        'description' => 'This is a backup created before shuffling the playlist.',
        'public'      => false,
    ];

    [$status, $backupPlaylist] = spotifyRequest(
        'POST',
        'https://api.spotify.com/v1/me/playlists',
        $accessToken,
        $backupPayload
    );

    if (($status < 200 || $status >= 300) || empty($backupPlaylist['id'])) {
        throw new RuntimeException('Failed to create backup playlist (HTTP ' . $status . ').');
    }

    $backupPlaylistId = $backupPlaylist['id'];

    // -------------------------------------------------------------------------
    // 4. Add all current tracks to the backup playlist
    // -------------------------------------------------------------------------
    $uris = array_map(
        static function (array $trackItem): string {
            return $trackItem['track']['uri'];
        },
        $allTracks
    );

    addTracksInChunks($backupPlaylistId, $uris, $accessToken);

    // -------------------------------------------------------------------------
    // 5. Shuffle the tracks and build shuffled URI list
    // -------------------------------------------------------------------------
    shuffle($allTracks);

    $shuffledUris = array_map(
        static function (array $trackItem): string {
            return $trackItem['track']['uri'];
        },
        $allTracks
    );

    // -------------------------------------------------------------------------
    // 6. Remove all tracks from the original playlist
    //    Note: This relies on Spotify allowing PUT with empty 'uris' to clear.
    // -------------------------------------------------------------------------
    [$status,] = spotifyRequest(
        'PUT',
        "https://api.spotify.com/v1/playlists/{$playlistId}/tracks",
        $accessToken,
        ['uris' => []]
    );

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Failed to remove tracks from playlist (HTTP ' . $status . ').');
    }

    // -------------------------------------------------------------------------
    // 7. Add shuffled tracks back to the original playlist
    // -------------------------------------------------------------------------
    addTracksInChunks($playlistId, $shuffledUris, $accessToken);

    // -------------------------------------------------------------------------
    // 8. Clear all tracks from the backup playlist
    // -------------------------------------------------------------------------
    [$status,] = spotifyRequest(
        'PUT',
        "https://api.spotify.com/v1/playlists/{$backupPlaylistId}/tracks",
        $accessToken,
        ['uris' => []]
    );

    if ($status < 200 || $status >= 300) {
        // Not fatal to the main shuffle, so just warn.
        echo 'Warning: Failed to clear backup playlist (HTTP ' . $status . ').<br>';
    }

    // -------------------------------------------------------------------------
    // 9. Unfollow the backup playlist
    // -------------------------------------------------------------------------
    [$status,] = spotifyRequest(
        'DELETE',
        "https://api.spotify.com/v1/playlists/{$backupPlaylistId}/followers",
        $accessToken
    );

    if ($status < 200 || $status >= 300) {
        echo 'Warning: Failed to unfollow backup playlist (HTTP ' . $status . ').<br>';
    }

    // -------------------------------------------------------------------------
    // 10. Final output
    // -------------------------------------------------------------------------
    echo 'Successfully shuffled tracks.<br>';

    if (!empty($localTracks)) {
        echo 'Note: ' . count($localTracks) . ' local/unavailable track(s) were skipped.<br>';
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
