<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shuffled Spotify Links</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
</head>

<body>
    <div class="container">
        <div class="spacer">
            <h1>Shuffled Spotify Links</h1>
        </div>

        <div class="spacer">
            <form method="post">
                <button type="button" name="shuffle" id="shuffleBtn">Shuffle Links</button>
                <button type="button" id="selectAllBtn" onclick="selectText()">Select All</button>
                <button type="button" id="copyBtn">Copy</button>
            </form>
        </div>

        <div class="spacer">
            <div id="popup">
                <span>Copied Shuffled Links!</span>
            </div>
        </div>

        <div class="spacer">
            <div id="dropzone">Drop your text file here</div>
            <div id="songCounterContainer">
                <span id="songCounter">0</span> songs
            </div>
        </div>

        <div class="spacer">
            <?php
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
            error_reporting(E_ALL);

            session_start();

            // Your Spotify application credentials
            $clientID = 'c21ff8453209440cb1b84c09435be0c2';
            $clientSecret = '2959c1ff340c4948b073360d9a66ef65';

            // The Spotify accounts service URL
            $accountsServiceURL = 'https://accounts.spotify.com';

            // The URL of your application's authorization callback
            $redirectURL = 'http://localhost/Playlist_Randomizer/Spotify-Playlist-Randomizer/callback.php';

            // The scopes your application needs access to
            $scopes = 'playlist-read-private playlist-read-collaborative playlist-modify-private playlist-modify-public';

            // Generate a random string for the state parameter
            $state = bin2hex(random_bytes(16));

            // The URL the user will be redirected to in order to authorize your application
            $authURL = $accountsServiceURL . '/authorize?response_type=code&client_id=' . $clientID . '&scope=' . urlencode($scopes) . '&redirect_uri=' . urlencode($redirectURL) . '&state=' . $state . '&show_dialog=true';

            echo '<div class="auth-link"><a href="' . $authURL . '">Log in with Spotify</a></div>';

            // Echo a logout link if the user is logged in
            if (isset($_SESSION['access_token'])) {
                echo "<br>";
                echo '<div class="auth-link"><a href="logout.php">Log out</a></div>';
            }

            if (isset($_SESSION['access_token'])) {
                $accessToken = $_SESSION['access_token'];

                // Fetch the user's playlists
                $playlistOptions = [
                    'http' => [
                        'header' => "Authorization: Bearer $accessToken\r\n",
                        'method' => 'GET'
                    ]
                ];
                $playlistContext = stream_context_create($playlistOptions);
                $playlistResult = file_get_contents('https://api.spotify.com/v1/me/playlists', false, $playlistContext);

                if ($playlistResult === FALSE) {
                    die('Failed to fetch playlists');
                }

                $playlists = json_decode($playlistResult, true);

                echo '<div class="playlist-container">';
                foreach ($playlists['items'] as $playlist) {
                    // Get the URL of the first image (largest size)
                    $imageUrl = $playlist['images'][0]['url'];

                    echo '<div class="playlist">';
                    echo '<h2>' . htmlspecialchars($playlist['name']) . '</h2>';
                    echo '<img src="' . $imageUrl . '" onclick="shufflePlaylist(\'' . $playlist['id'] . '\')" class="playlist-image">';
                    echo '</div>';
                }
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <script src="script.js"></script>
</body>

</html>