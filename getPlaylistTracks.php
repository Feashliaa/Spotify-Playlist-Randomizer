<?php
session_start();

if (isset($_SESSION['access_token'])) {
    $accessToken = $_SESSION['access_token'];

    $playlistId = $_GET['playlist_id'];  // Get the playlist id from the URL parameters

    // Fetch all the tracks in the playlist
    $nextTracksUrl = "https://api.spotify.com/v1/playlists/$playlistId/tracks";
    $playlistApiUrl = "https://api.spotify.com/v1/playlists/$playlistId/tracks";

    $allTracks = [];

    do {
        $tracksOptions = [
            'http' => [
                'header' => "Authorization: Bearer $accessToken\r\n",
                'method' => 'GET'
            ]
        ];
        $tracksContext = stream_context_create($tracksOptions);
        $tracksResult = file_get_contents($nextTracksUrl, false, $tracksContext);

        if ($tracksResult === FALSE) {
            // Handle error
            die('Failed to fetch tracks');
        }

        $tracks = json_decode($tracksResult, true);
        $allTracks = array_merge($allTracks, $tracks['items']);
        $nextTracksUrl = $tracks['next'];
    } while ($nextTracksUrl !== null);

    // Fetch the playlist's data
    $playlistOptions = [
        'http' => [
            'header' => "Authorization: Bearer $accessToken\r\n",
            'method' => 'GET'
        ]
    ];
    $playlistContext = stream_context_create($playlistOptions);
    $playlistResult = file_get_contents("https://api.spotify.com/v1/playlists/$playlistId", false, $playlistContext);
    if ($playlistResult === FALSE) {
        die('Failed to fetch playlist');
    }
    $playlistData = json_decode($playlistResult, true);

    // Create a backup playlist
    $backupPlaylistOptions = [
        'http' => [
            'header' => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode([
                'name' => 'Backup of Playlist: ' . $playlistData['name'],
                'description' => 'This is a backup created before shuffling the playlist.',
                'public' => false  // The backup playlist is private
            ])
        ]
    ];

    $backupPlaylistContext = stream_context_create($backupPlaylistOptions);
    $backupPlaylistResult = file_get_contents('https://api.spotify.com/v1/me/playlists', false, $backupPlaylistContext);
    if ($backupPlaylistResult === FALSE) {
        die('Failed to create backup playlist');
    }

    $backupPlaylist = json_decode($backupPlaylistResult, true);
    $backupPlaylistId = $backupPlaylist['id'];

    // Add all tracks to the backup playlist
    $backupPlaylistApiUrl = "https://api.spotify.com/v1/playlists/$backupPlaylistId/tracks";
    $uris = array_map(function ($trackItem) {
        return $trackItem['track']['uri'];
    }, $allTracks);

    // Get the track URIs in chunks of 100
    $uriChunks = array_chunk($uris, 100);

    foreach ($uriChunks as $chunk) {
        // Prepare the data for the API request
        $data = [
            'uris' => $chunk
        ];

        // Make the API request to add the tracks to the backup playlist
        $options = [
            'http' => [
                'header' => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($data)
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($backupPlaylistApiUrl, false, $context);
        if ($result === FALSE) {
            die('Failed to add tracks to backup playlist');
        }
    }

    // Shuffle the tracks
    shuffle($allTracks);
    // Get the URIs of all tracks
    $shuffledUris = array_map(function ($trackItem) {
        return $trackItem['track']['uri'];
    }, $allTracks);

    // Remove all tracks from the playlist
    $options = [
        'http' => [
            'header' => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
            'method' => 'PUT',
            'content' => json_encode(['uris' => []])  // Empty array to remove all tracks
        ]
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($playlistApiUrl, false, $context);
    if ($result === FALSE) {
        die('Failed to remove tracks from playlist');
    }

    // Get the shuffled track URIs in chunks of 100
    $uriChunks = array_chunk($shuffledUris, 100);

    foreach ($uriChunks as $chunk) {
        // Prepare the data for the API request
        $data = [
            'uris' => $chunk
        ];

        // Make the API request to add the tracks to the playlist
        $options = [
            'http' => [
                'header' => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($data)
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($playlistApiUrl, false, $context);
        if ($result === FALSE) {
            die('Failed to add tracks to playlist');
        }
    }

    // Clear all tracks from the backup playlist using cURL
    $backupClearUrl = "https://api.spotify.com/v1/playlists/$backupPlaylistId/tracks";
    $backupClearOptions = [
        CURLOPT_URL => $backupClearUrl,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_HTTPHEADER => array("Authorization: Bearer $accessToken", "Content-Type: application/json"),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_POSTFIELDS => json_encode(['uris' => []]) // Empty array to remove all tracks
    ];

    $backupClearCurl = curl_init();
    curl_setopt_array($backupClearCurl, $backupClearOptions);
    $backupClearResult = curl_exec($backupClearCurl);
    $backupClearHttpCode = curl_getinfo($backupClearCurl, CURLINFO_HTTP_CODE);
    curl_close($backupClearCurl);

    // Check the HTTP status code to determine if clearing the playlist was successful
    if ($backupClearHttpCode === 200 || $backupClearHttpCode === 201) {
        echo "Successfully shuffled tracks and cleared backup playlist.";
    } else {
        echo "Failed to clear backup playlist. HTTP status code: $backupClearHttpCode";
    }

    // Unfollow the backup playlist
    $unfollowUrl = "https://api.spotify.com/v1/playlists/$backupPlaylistId/followers";
    $unfollowOptions = [
        CURLOPT_URL => $unfollowUrl,
        CURLOPT_CUSTOMREQUEST => "DELETE",
        CURLOPT_HTTPHEADER => array("Authorization: Bearer $accessToken", "Content-Type: application/json"),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false
    ];

    $unfollowCurl = curl_init();
    curl_setopt_array($unfollowCurl, $unfollowOptions);
    $unfollowResult = curl_exec($unfollowCurl);
    $unfollowHttpCode = curl_getinfo($unfollowCurl, CURLINFO_HTTP_CODE);
    curl_close($unfollowCurl);

    // Check the HTTP status code to determine if unfollowing the playlist was successful
    if ($unfollowHttpCode === 200 || $unfollowHttpCode === 201) {
        echo "Successfully unfollowed backup playlist.";
    } else {
        echo "Failed to unfollow backup playlist. HTTP status code: $unfollowHttpCode";
    }
}
