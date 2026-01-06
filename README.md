# Spotify Playlist Shuffler

A simple web app that **truly shuffles** your Spotify playlists by reordering the actual tracks. Unlike Spotify's built-in shuffle (which uses an algorithm that favors certain songs), this physically randomizes the track order in your playlist.

> **Note:** Due to Spotify's API policies, this app must be self-hosted. Each user needs to create their own Spotify Developer app.

## Why This Exists

Spotify's shuffle isn't random, it uses an algorithm that tends to play certain songs more often. This tool actually reorders the tracks in your playlist, so when you play it straight through (no shuffle), you get a truly randomized experience.

## Features

- OAuth login with Spotify
- View all your playlists
- One-click shuffle for any playlist
- Handles large playlists (pagination support)
- Skips local/unavailable tracks gracefully

## Requirements

- PHP 7.4+ with cURL extension
- Composer
- A Spotify account
- A web server (Apache/Nginx) or PHP's built-in server for local use

## Setup

### 1. Create a Spotify Developer App

1. Go to the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard)
2. Click **Create App**
3. Fill in the details:
   - **App name:** Playlist Shuffler (or whatever you want)
   - **App description:** Personal playlist shuffler
   - **Redirect URI:** `http://localhost:8000/callback.php` (for local dev) or your deployed URL
4. Check the **Web API** box
5. Click **Save**
6. Go to **Settings** and note your **Client ID** and **Client Secret**

### 2. Clone and Configure

```bash
git clone https://github.com/Feashliaa/Spotify-Playlist-Randomizer.git
cd spotify-shuffler
```

Copy the example environment file and fill in your credentials:

```bash
cp .env.example .env
```

Edit `.env`:

```env
CLIENT_ID=your_spotify_client_id
CLIENT_SECRET=your_spotify_client_secret
APP_URL=http://localhost:8000
```

### 3. Install Dependencies

```bash
composer install
```

### 4. Run Locally

```bash
php -S localhost:8000
```

Then visit `http://localhost:8000` in your browser.

## Deployment Options

### Option A: Traditional Web Hosting

Upload all files to your web host and set the environment variables. Make sure:
- PHP 7.4+ is available
- The `curl` extension is enabled
- Set your `APP_URL` to your actual domain (e.g., `https://shuffle.yourdomain.com`)
- Update the **Redirect URI** in your Spotify app to match

### Option B: Railway / Render / Fly.io

These platforms support PHP apps. Set the environment variables in their dashboard:
- `CLIENT_ID`
- `CLIENT_SECRET`
- `APP_URL`

### Option C: Local Only

Just run it on your own machine when you need it. No hosting required.

## Environment Variables

| Variable | Description |
|----------|-------------|
| `CLIENT_ID` | Your Spotify app's Client ID |
| `CLIENT_SECRET` | Your Spotify app's Client Secret |
| `APP_URL` | The base URL where the app is hosted (no trailing slash) |

## File Structure

```
├── index.php        # Main UI and login flow
├── callback.php     # OAuth callback handler
├── shuffle.php      # Playlist shuffle logic
├── logout.php       # Session logout
├── script.js        # Frontend JavaScript
├── style.css        # Styles
├── composer.json    # PHP dependencies
└── .env.example     # Environment template
```

## How It Works

1. You log in via Spotify OAuth
2. The app fetches all your playlists
3. When you click a playlist image, it:
   - Fetches all tracks from the playlist
   - Creates a temporary backup (just in case)
   - Shuffles the track order randomly
   - Clears the playlist
   - Re-adds all tracks in the new shuffled order
   - Deletes the backup
4. Your playlist is now physically reordered

## Limitations

- **Local files are skipped** — Spotify's API can't manipulate local files in playlists
- **You can only shuffle playlists you own** — Can't modify others' playlists
- **Rate limits** — Very large playlists may hit Spotify's rate limits

## Troubleshooting

### "Failed to fetch playlists"
- Check that your `CLIENT_ID` and `CLIENT_SECRET` are correct
- Make sure the Redirect URI in your Spotify app exactly matches your `APP_URL/callback.php`

### "Not authenticated"
- Your session may have expired—try logging out and back in
- Check that cookies are enabled in your browser

### Shuffle seems to hang on large playlists
- Large playlists (1000+ songs) take time due to API pagination
- Check your PHP timeout settings if it fails

## License

MIT: Do whatever you want with it.

## Acknowledgments

Built out of frustration with Spotify's "shuffle" algorithm.