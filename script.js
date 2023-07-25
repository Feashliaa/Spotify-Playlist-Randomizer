function shufflePlaylist(playlistId, clickedImage) {
    // Disable other shuffle buttons while one is loading
    const allPlaylistImages = document.querySelectorAll('.playlist-image');
    allPlaylistImages.forEach(image => {
        if (image !== clickedImage) {
            image.style.pointerEvents = 'none';
        }
    });

    // Show the loader for this playlist
    document.getElementById('loader-' + playlistId).style.display = 'block';

    // Make an API call to shuffle the playlist
    fetch(`getPlaylistTracks.php?playlist_id=${playlistId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            // Hide the loader for this playlist
            document.getElementById('loader-' + playlistId).style.display = 'none';

            // Return the response as text
            return response.text();
        })
        .then(data => {
            // Hide the loader for this playlist
            document.getElementById('loader-' + playlistId).style.display = 'none';

            // Enable other shuffle buttons after loading is complete
            allPlaylistImages.forEach(image => {
                if (image !== clickedImage) {
                    image.style.pointerEvents = 'auto';
                }
            });
        })
        .catch(error => {
            console.error('Error:', error);
            // Enable other shuffle buttons even if there's an error
            allPlaylistImages.forEach(image => {
                if (image !== clickedImage) {
                    image.style.pointerEvents = 'auto';
                }
            });
        });
}

