<?php
session_start();

// Unset the access token
if (isset($_SESSION['access_token'])) {
    unset($_SESSION['access_token']);
}

// Redirect to the index page
header('Location: index.php');
exit;
