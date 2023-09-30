<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spotify Shuffler</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</head>

<body>
    <div class="container">
        <div class="spacer">
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
            $clientID = $_ENV['CLIENT_ID'];
            $clientSecret = $_ENV['CLIENT_SECRET'];

            // The Spotify accounts service URL
            $accountsServiceURL = 'https://accounts.spotify.com';

            // The URL of application's authorization callback
            $redirectURL = $_ENV['APP_URL'] . 'callback.php';

            // The scopes application needs access to
            $scopes = 'user-read-private playlist-read-private playlist-read-collaborative playlist-modify-private playlist-modify-public';

            // Generate a random string for the state parameter
            $state = bin2hex(random_bytes(16));

            // The URL the user will be redirected to in order to authorize application
            $authURL = $accountsServiceURL . '/authorize?response_type=code&client_id=' . $clientID . '&scope=' . urlencode($scopes) . '&redirect_uri=' . urlencode($redirectURL) . '&state=' . $state . '&show_dialog=true';

            // Output the header
            if (isset($_SESSION['access_token'])) {

                // get the username
                $accessToken = $_SESSION['access_token'];

                // Initialize cURL
                $ch = curl_init();

                // Set the cURL options
                curl_setopt($ch, CURLOPT_URL, "https://api.spotify.com/v1/me");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

                // Set the headers including the access token
                $headers = array(
                    'Authorization: Bearer ' . $accessToken
                );
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                // Execute the cURL and get the response
                $result = curl_exec($ch);

                // Close cURL
                curl_close($ch);

                // Decode the JSON response
                $user = json_decode($result);


                echo '<div class="header-bar">';
                echo '<div class="auth-link"><a href="logout.php"><i class="fab fa-spotify"></i>Log Out</a></div>';
                echo '<div class="spacer"></div>';
                echo '<h1>Spotify Playlist Shuffler</h1>';
                // Display username if exists
                if (isset($user->display_name)) {
                    echo '<div class="username">User: ' . htmlspecialchars($user->display_name) . '</div>';
                } else {
                    echo '<div class="username">User: </div>'; // Fallback to a default message
                }
                echo '</div>'; // This is the closing tag for your existing "header-bar" div

            } else {
                echo '<div class="header-bar">';
                echo '<div class="auth-link"><a href="' . $authURL . '"><i class="fab fa-spotify"></i>Log In</a></div>';
                echo '<div class="spacer"></div>';
                echo '<h1>Spotify Playlist Shuffler</h1>';
                echo '</div>';
                echo '<div class="spacer"></div>';
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


                // Fetch the user's playlists
                $playlistContext = stream_context_create($playlistOptions);
                $playlistResult = file_get_contents('https://api.spotify.com/v1/me/playlists', false, $playlistContext);

                if ($playlistResult === FALSE) {
                    // Output a message to the user
                    echo '<div class="error-message" id="error-message">Failed to fetch playlists</div>';
                    // JavaScript for fade-in and fade-out
                    echo '<script>

                            var errorMessage = document.querySelector("#error-message");

                            // Fade in immediately
                            errorMessage.style.opacity = "1"; 

                            // Fade out after 2.5 seconds

                            setTimeout(function() { 
                                errorMessage.style.opacity = "0"; 
                            }, 2500);
                            
                          </script>';
                } else {
                    $playlists = json_decode($playlistResult, true);

                    echo '<div class="playlist-container">';
                    foreach ($playlists['items'] as $playlist) {

                        // If there is a playlist with no tracks, skip it
                        if ($playlist['tracks']['total'] === 0) {
                            continue;
                        }

                        // Get the URL of the first image (largest size)
                        // if there is no image, use a default image
                        if (count($playlist['images']) === 0) {
                            $imageUrl = 'https://via.placeholder.com/200';
                        } else {
                            $imageUrl = $playlist['images'][0]['url'];
                        }

                        // Output the playlist
                        echo '<div class="playlist">';
                        echo '<h2>' . htmlspecialchars($playlist['name']) . '</h2>';
                        echo '<img src="' . $imageUrl . '" onclick="shufflePlaylist(\'' . $playlist['id'] . '\', this)" class="playlist-image">';
                        echo '<div class="song-counter">' . $playlist['tracks']['total'] . ' songs</div>';
                        echo '<div class="loader" id="loader-' . $playlist['id'] . '"></div>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
            }
            ?>

        </div>

        <script src="script.js"></script>

        <form action="https://www.paypal.com/donate" method="post" target="_top" class="paypal-donate-form">
            <input type="hidden" name="business" value="L7S2SKNB2TEN4" />
            <input type="hidden" name="no_recurring" value="0" />
            <input type="hidden" name="item_name" value="Help Cover Web Hosting Costs" />
            <input type="hidden" name="currency_code" value="USD" />
            <input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_LG.gif" border="0"
                name="submit" title="PayPal - The safer, easier way to pay online!" alt="Donate with PayPal button" />
            <img alt="" border="0" src="https://www.paypal.com/en_US/i/scr/pixel.gif" width="1" height="1" />
        </form>
        <script src="script.js"></script>
</body>

</html>