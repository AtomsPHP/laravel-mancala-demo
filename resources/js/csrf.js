/**
 * The CSRF token Laravel put in the page head.
 *
 * The JSON endpoints here are session-authenticated — creating a game and
 * minting a connection ticket both act as whoever holds the cookie — so they
 * sit in the web middleware group and expect this header.
 */
export function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}
