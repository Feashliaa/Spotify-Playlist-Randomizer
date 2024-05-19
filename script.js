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

            console.log(data);

            // Hide the loader for this playlist
            document.getElementById('loader-' + playlistId).style.display = 'none';

            // Enable other shuffle buttons after loading is complete
            allPlaylistImages.forEach(image => {
                if (image !== clickedImage) {
                    image.style.pointerEvents = 'auto';
                }
            });

            /* data will look something like this
            
            Skipped track: Hollow by Tesseract<br>
            
            Skipped track: Rebirth by Tesseract<br>
            
            Successfully shuffled tracks and cleared backup playlist. <br>Successfully unfollowed backup playlist. <br>

            we need to extract the skipped tracks, if any, and show them in an alert
            */
            const cleanedData = data.replace(/<br>/g, ''); // Remove <br> tags from the data
            const skippedTracks = cleanedData.match(/Skipped track: .+ by .+/g); // regex to match skipped tracks
            console.log('Skipped Tracks:', skippedTracks);
            if (skippedTracks) {
                alert(skippedTracks.join('\n') + '\n\nSuccessfully shuffled tracks and cleared backup playlist. Successfully unfollowed backup playlist. Add the removed tracks back to the playlist if needed.');
            } else {
                alert('No skipped tracks were found in the playlist.');
            }
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