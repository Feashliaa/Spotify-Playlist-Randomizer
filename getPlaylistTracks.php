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

    // Create a backup playlist
    $backupPlaylistOptions = [
        'http' => [
            'header' => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode([
                'name' => 'Backup of playlist ' . $playlistId,
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
}
