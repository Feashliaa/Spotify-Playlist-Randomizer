// Global guard, safe even if this file is accidentally included twice
if (typeof window.isShuffling === 'undefined') {
    window.isShuffling = false;
}

async function shufflePlaylist(playlistId, clickedImage) {
    if (window.isShuffling) return;
    window.isShuffling = true;

    const cards = document.querySelectorAll('.playlist');
    const loader = document.getElementById('loader-' + playlistId);

    // Disable all cards
    cards.forEach(card => {
        card.style.pointerEvents = 'none';
        card.style.opacity = '0.5';
    });

    if (loader) loader.style.display = 'block';

    const originalUris = [];
    const skippedTracks = [];
    let offset = 0;
    let total = null;

    try {
        // ----------------------------------------------------------------
        // 1. Fetch all pages
        // ----------------------------------------------------------------
        do {
            const url = `getPlaylistTracks.php?playlist_id=${encodeURIComponent(playlistId)}&offset=${offset}`;
            const response = await fetch(url);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || `Failed to fetch tracks (HTTP ${response.status})`);
            }

            originalUris.push(...data.uris);
            skippedTracks.push(...(data.skipped || []));

            if (total === null) total = data.total;

            // Update progress
            const fetched = Math.min(originalUris.length, total);
            showToast(`Fetching tracks… ${fetched} / ${total}`, true);

            offset = data.next_offset;

        } while (offset !== null);

        if (originalUris.length === 0) {
            throw new Error('No playable tracks found in this playlist.');
        }

        // ----------------------------------------------------------------
        // 2. Shuffle a copy client-side
        // ----------------------------------------------------------------
        const shuffledUris = [...originalUris];
        for (let i = shuffledUris.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [shuffledUris[i], shuffledUris[j]] = [shuffledUris[j], shuffledUris[i]];
        }

        showToast('Applying shuffle…', true);

        // ----------------------------------------------------------------
        // 3. Send shuffled URIs to server
        // ----------------------------------------------------------------
        const applyResponse = await fetch('applyShuffledTracks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ playlist_id: playlistId, uris: shuffledUris }),
        });

        const applyData = await applyResponse.json();

        if (!applyResponse.ok) {
            throw new Error(applyData.error || `Apply failed (HTTP ${applyResponse.status})`);
        }

        // ----------------------------------------------------------------
        // 4. Success
        // ----------------------------------------------------------------
        let message = 'Playlist shuffled successfully!';
        if (skippedTracks.length > 0) {
            message += `\n\n${skippedTracks.length} local/unavailable track(s) skipped.`;
        }
        showToast(message);

    } catch (err) {
        console.error('Shuffle error:', err);

        // Offer restore if we have the original URIs
        if (originalUris.length > 0) {
            showToastWithRestore(
                `Error: ${err.message}. Restore original order?`,
                async () => {
                    showToast('Restoring…', true);
                    try {
                        const restoreResponse = await fetch('applyShuffledTracks.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ playlist_id: playlistId, uris: originalUris }),
                        });
                        const restoreData = await restoreResponse.json();
                        if (!restoreResponse.ok) throw new Error(restoreData.error);
                        showToast('Playlist restored to original order.');
                    } catch (restoreErr) {
                        showToast('Restore failed: ' + restoreErr.message);
                    }
                }
            );
        } else {
            showToast('Error Sorry: ' + err.message);
        }

    } finally {
        if (loader) loader.style.display = 'none';
        cards.forEach(card => {
            card.style.pointerEvents = 'auto';
            card.style.opacity = '';
        });
        window.isShuffling = false;
    }
}

function showToast(message, persistent = false) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.classList.add('show');

    if (!persistent) {
        setTimeout(() => toast.classList.remove('show'), 3000);
    }
}

function showToastWithRestore(message, onRestore) {
    const toast = document.getElementById('toast');
    toast.innerHTML = '';

    const text = document.createElement('span');
    text.textContent = message + ' ';
    toast.appendChild(text);

    const btn = document.createElement('button');
    btn.textContent = 'Restore';
    btn.style.cssText = 'margin-left:8px;padding:4px 10px;cursor:pointer;border:1px solid #fff;background:transparent;color:#fff;border-radius:4px;font-size:0.85em;';
    btn.onclick = () => {
        toast.classList.remove('show');
        onRestore();
    };
    toast.appendChild(btn);

    toast.classList.add('show');
}