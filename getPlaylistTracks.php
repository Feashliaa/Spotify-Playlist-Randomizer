<?php
session_start();

/**
 * Simple Spotify API request helper using cURL.
 *
 * @param string      $method       HTTP method (GET, POST, PUT, DELETE)
 * @param string      $url          Full Spotify API URL
 * @param string      $accessToken  OAuth access token
 * @param array|null  $payload      Optional JSON payload
 *
 * @return array [int $statusCode, array|null $body]
 * @throws RuntimeException on transport errors
 */
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
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    return [$statusCode, $decoded];
}

/**
 * Add tracks to a playlist in chunks of 100 URIs.
 *
 * @param string $playlistId
 * @param array  $uris
 * @param string $accessToken
 *
 * @throws RuntimeException
 */
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

if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo 'Not authenticated.';
    exit;
}

$accessToken = $_SESSION['access_token'];

// Basic validation for playlist_id
$rawPlaylistId = $_GET['playlist_id'] ?? '';
$playlistId    = preg_replace('/[^A-Za-z0-9]/', '', $rawPlaylistId);

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
