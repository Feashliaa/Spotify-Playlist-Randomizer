<?php

require __DIR__ . '/../vendor/autoload.php';

// use environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Display errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Spotify application credentials
if (isset($_GET['code'])) {
    $code = $_GET['code']; // The authorization code returned by Spotify
    $url = 'https://accounts.spotify.com/api/token'; // The Spotify accounts service URL
    $data = [ 
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $_ENV['APP_URL'] . 'callback.php',
        'client_id' => $_ENV['CLIENT_ID'],
        'client_secret' => $_ENV['CLIENT_SECRET']
    ];

    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
                "Authorization: Basic " . base64_encode($data['client_id'] . ":" . $data['client_secret']) . "\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];

    // Send a POST request with the authorization code, client ID and client secret
    $context  = stream_context_create($options);

    // Store the access token and refresh token in session variables
    $result = file_get_contents($url, false, $context);
    if ($result === FALSE) {
        // Handle error
        die('Failed to fetch access token');
    }

    // Decode the response
    $response = json_decode($result, true);

    // Store the access token and refresh token in session variables
    $accessToken = $response['access_token'];

    // Store the access token in session variables
    $_SESSION['access_token'] = $accessToken;

    // redirect to index.php
    header('Location: ' . $_ENV['APP_URL'] . 'index.php');
    exit;
}
