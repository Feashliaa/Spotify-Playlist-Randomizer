<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $url = 'https://accounts.spotify.com/api/token';
    $data = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => 'http://localhost/Playlist_Randomizer/Spotify-Playlist-Randomizer/callback.php',
        'client_id' => "c21ff8453209440cb1b84c09435be0c2",
        'client_secret' => "2959c1ff340c4948b073360d9a66ef65"
    ];

    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
                "Authorization: Basic " . base64_encode($data['client_id'] . ":" . $data['client_secret']) . "\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];

    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    if ($result === FALSE) {
        /* Handle error */
    }

    $response = json_decode($result, true);
    $accessToken = $response['access_token'];
    $_SESSION['access_token'] = $accessToken;

    // redirect to index.php
    header('Location: http://localhost/Playlist_Randomizer/Spotify-Playlist-Randomizer/index.php');
    exit;
}
