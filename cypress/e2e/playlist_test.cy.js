
it('Brings to Spotify Login Page', () => {
    // Logging in
    cy.visit('http://localhost/Playlist_Randomizer/Spotify-Playlist-Randomizer/index.php');

    // Click the login button to go to the Spotify login page
    cy.get('[data-cy=loginButton]').click();

    // Ensure commands run against the correct origin
    cy.origin('https://accounts.spotify.com', () => {
        // Type into the username input on the Spotify login page
        cy.get('[data-testid="login-username"]').type('rwdorrington@gmail.com');

        // Type into the password input on the Spotify login page
        cy.get('[data-testid="login-password"]').type('8H^n9w&%4Naa');

        // Click the login button on the Spotify login page
        cy.get('[data-testid="login-button"]').click();

        // Assert that the URL contains the expected substring
        cy.url().should('include', 'authorize');

        // Click the agree button on the Spotify login page
        cy.get('[data-testid="auth-accept"]').click();
    });

    // wait for the final redirect to the app, and ascertain that the URL contains the expected substring "index.php"
    cy.url().should('include', 'index.php');

    // if so, we can click the logout button
    cy.get('[data-cy=logoutButton]').click();
});
