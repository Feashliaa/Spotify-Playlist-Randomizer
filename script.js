// Global guard, safe even if this file is accidentally included twice
if (typeof window.isShuffling === 'undefined') {
    window.isShuffling = false;
}

function shufflePlaylist(playlistId, clickedImage) {
    console.log('shufflePlaylist called for', playlistId);

    if (window.isShuffling) {
        console.log('Shuffle blocked: already shuffling.');
        return;
    }
    window.isShuffling = true;
    console.log('Shuffle started.');

    const cards = document.querySelectorAll('.playlist');
    const loader = document.getElementById('loader-' + playlistId);

    console.log('Disabling UI elements during shuffle...');
    for (const card of cards) {
        console.log('Disabling card:', card);
    }

    // Disable ALL playlist cards (no clicks anywhere inside them)
    cards.forEach(card => {
        card.style.pointerEvents = 'none';
        card.style.opacity = '0.5';
    });

    if (loader) {
        loader.style.display = 'block';
    }

    fetch(`getPlaylistTracks.php?playlist_id=${encodeURIComponent(playlistId)}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Request failed: ${response.status}`);
            }
            return response.text();
        })
        .then(rawText => {
            if (loader) {
                loader.style.display = 'none';
            }

            const text = rawText.replace(/<br\s*\/?>/gi, '').trim();
            const skipped = text.match(/Skipped track:\s.*? by .*?(?=$|\n)/g);

            if (skipped && skipped.length > 0) {
                let message = `Playlist shuffled with ${skipped.length} skipped track(s):\n\n`;
                skipped.forEach(line => {
                    message += line + '\n';
                });
                showToast(message);
            } else {
                showToast('Playlist shuffled successfully!');
            }
        })
        .catch(err => {
            console.error('Shuffle error:', err);
            if (loader) {
                loader.style.display = 'none';
            }
            showToast('Error during shuffle: ' + err.message);
        })
        .finally(() => {
            // Re-enable all playlist cards
            cards.forEach(card => {
                card.style.pointerEvents = 'auto';
                card.style.opacity = '';
            });

            window.isShuffling = false;
            console.log('Shuffle finished, UI re-enabled.');
        });
}


function showToast(message) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.classList.add('show');

    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}
