<?php
require __DIR__ . '/vendor/autoload.php';

// Load environment variables only if .env exists (local development)
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

/**
 * GET request helper for Spotify API
 */
function spotifyGet(string $url, string $accessToken): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Spotify API error: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode($response, true)];
}

/**
 * Fetch the logged-in Spotify user's profile
 */
function fetchUserProfile(string $accessToken): ?array
{
    try {
        [$status, $data] = spotifyGet('https://api.spotify.com/v1/me', $accessToken);
        return ($status === 200) ? $data : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Fetch playlists for the logged-in user
 */
function fetchUserPlaylists(string $accessToken): ?array
{
    try {
        [$status, $data] = spotifyGet('https://api.spotify.com/v1/me/playlists', $accessToken);
        return ($status === 200) ? $data : null;
    } catch (Throwable $e) {
        return null;
    }
}

// -----------------------------------------------------------------------------
// Build login URL
// -----------------------------------------------------------------------------
$clientID    = getenv('CLIENT_ID');
$appUrl      = rtrim(getenv('APP_URL'), '/'); // ensures no double slashes
$redirectURL = $appUrl . '/callback.php';
$scopes      = 'user-read-private playlist-read-private playlist-read-collaborative playlist-modify-private playlist-modify-public';
$state       = bin2hex(random_bytes(16));

$authURL = 'https://accounts.spotify.com/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $clientID,
    'scope'         => $scopes,
    'redirect_uri'  => $redirectURL,
    'state'         => $state,
    'show_dialog'   => 'true'
]);

$loggedIn    = isset($_SESSION['access_token']);
$accessToken = $_SESSION['access_token'] ?? null;
$userData    = $loggedIn ? fetchUserProfile($accessToken) : null;
?>
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

    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</head>

<body>
    <div class="container">
        <div class="spacer">

            <?php
            // ---------------------------------------------------------------------
            // HEADER BAR
            // ---------------------------------------------------------------------
            echo '<div class="header-bar">';

            if ($loggedIn) {
                echo '<div class="auth-link"><a href="logout.php"><i class="fab fa-spotify"></i>Log Out</a></div>';
            } else {
                echo '<div class="auth-link"><a href="' . $authURL . '"><i class="fab fa-spotify"></i>Log In</a></div>';
            }

            echo '<div class="spacer"></div>';
            echo '<h1>Spotify Playlist Shuffler</h1>';

            if ($loggedIn && isset($userData['display_name'])) {
                echo '<div class="username">User: ' . htmlspecialchars($userData['display_name']) . '</div>';
            } elseif ($loggedIn) {
                echo '<div class="username">User: (Unknown)</div>';
            }

            echo '</div>'; // end header bar
            echo '<div class="spacer"></div>';
            ?>

            <?php if ($loggedIn): ?>
                <?php
                $playlistData = fetchUserPlaylists($accessToken);

                if (!$playlistData) {
                    echo '<div class="error-message" id="error-message">Failed to fetch playlists</div>';
                    echo '<script>
                        const msg = document.getElementById("error-message");
                        msg.style.opacity = "1";
                        setTimeout(() => msg.style.opacity = "0", 2500);
                      </script>';
                } else {
                    echo '<div class="playlist-container">';

                    foreach ($playlistData['items'] as $playlist) {
                        if (($playlist['tracks']['total'] ?? 0) === 0) continue;

                        $playlistName = htmlspecialchars($playlist['name'] ?? 'Untitled');
                        $playlistId   = htmlspecialchars($playlist['id']);
                        $imageUrl     = $playlist['images'][0]['url'] ?? 'https://via.placeholder.com/200';

                        echo '<div class="playlist">';
                        echo '<h2>' . $playlistName . '</h2>';
                        echo '<img src="' . $imageUrl . '" 
                              class="playlist-image"
                              onclick="shufflePlaylist(\'' . $playlistId . '\', this)">';
                        echo '<div class="song-counter">' . intval($playlist['tracks']['total']) . ' songs</div>';
                        echo '<div class="loader" id="loader-' . $playlistId . '"></div>';
                        echo '</div>';
                    }

                    echo '</div>'; // playlist-container
                }
                ?>
            <?php endif; ?>

        </div>

        <div id="toast" class="toast"></div>
        <script src="script.js"></script>

        <form action="https://www.paypal.com/donate" method="post" target="_top" class="paypal-donate-form">
            <input type="hidden" name="business" value="L7S2SKNB2TEN4">
            <input type="hidden" name="no_recurring" value="0">
            <input type="hidden" name="item_name" value="Help Cover Web Hosting Costs">
            <input type="hidden" name="currency_code" value="USD">
            <input type="image"
                src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_LG.gif"
                border="0"
                name="submit"
                alt="Donate with PayPal">
        </form>
    </div>

</body>

</html>