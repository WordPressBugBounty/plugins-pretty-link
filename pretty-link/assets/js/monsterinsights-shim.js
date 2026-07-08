/**
 * MonsterInsights URL Builder → v4 Add Link prefill bridge.
 *
 * Runs inline (via wp_add_inline_script with 'before' position) ahead
 * of the prli-shared script tag, so the mutation lands before
 * bootstrap.js snapshots window.prliAdmin. See
 * src/monsterinsights-shim.php for the server side.
 *
 * Mirrors MonsterInsights' own footer JS (in v3 MI prints inline on
 * post-new.php?post_type=pretty-link; in v4 we redirect away from that
 * URL so MI's footer never runs). Behavior preserved 1:1:
 *
 *  - Storage key: `MonsterInsightsURL` (unchanged).
 *  - Value shape: JSON `{ value: string, expiry: number(unix-seconds) }`.
 *  - 10-minute TTL — values past `expiry` are removed and ignored.
 *  - Title is derived from the URL's UTM query/fragment params using
 *    MI's exact concatenation:
 *      `${utm_campaign} ${utm_medium} on ${utm_source} for ${utm_term} - ${utm_content}`
 *    (each segment only added if its param is present).
 *  - URL is split on `?` first; if no querystring, fall back to `#` —
 *    matching MI's `getWithExpiry()` fragment-mode handling.
 *
 * Divergences from MI:
 *  - MI clears the storage key on form `submit`; v4 uses a REST submit
 *    flow without a vanilla form event, so we clear on read instead.
 *    Slight UX difference: refreshing the v4 add-link page within the
 *    10-min window won't repopulate. Acceptable — the user can re-run
 *    the URL Builder to retry.
 */
( function () {
  if ( typeof window === 'undefined' || ! window.localStorage ) {
    return;
  }

  var STORAGE_KEY = 'MonsterInsightsURL';
  var raw;
  try {
    raw = window.localStorage.getItem( STORAGE_KEY );
  } catch ( e ) {
    return;
  }
  if ( ! raw ) {
    return;
  }

  var parsed;
  try {
    parsed = JSON.parse( raw );
  } catch ( e ) {
    try {
      window.localStorage.removeItem( STORAGE_KEY );
    } catch ( ignored ) {}
    return;
  }
  if ( ! parsed || typeof parsed !== 'object' ) {
    return;
  }

  // Expiry handling matches MI's getWithExpiry().
  var nowSeconds = Math.round( new Date().getTime() / 1000 );
  if ( typeof parsed.expiry === 'number' && nowSeconds > parsed.expiry ) {
    try {
      window.localStorage.removeItem( STORAGE_KEY );
    } catch ( ignored ) {}
    return;
  }

  var url = typeof parsed.value === 'string' ? parsed.value : '';
  if ( ! url ) {
    return;
  }

  // Replicate MI's footer-JS title formula: split URL on `?` (then on
  // `#` as a fallback) and concatenate UTM params into a sentence.
  var pathArray = url.split( '?' );
  if ( pathArray.length <= 1 ) {
    pathArray = url.split( '#' );
  }
  var postTitle = '';
  try {
    var urlParams = new URLSearchParams( pathArray[ 1 ] || '' );
    if ( urlParams.has( 'utm_campaign' ) ) {
      postTitle += urlParams.get( 'utm_campaign' );
    }
    if ( urlParams.has( 'utm_medium' ) ) {
      postTitle += ' ' + urlParams.get( 'utm_medium' );
    }
    if ( urlParams.has( 'utm_source' ) ) {
      postTitle += ' on ' + urlParams.get( 'utm_source' );
    }
    if ( urlParams.has( 'utm_term' ) ) {
      postTitle += ' for ' + urlParams.get( 'utm_term' );
    }
    if ( urlParams.has( 'utm_content' ) ) {
      postTitle += ' - ' + urlParams.get( 'utm_content' );
    }
  } catch ( e ) {
    postTitle = '';
  }

  window.prliAdmin = window.prliAdmin || {};
  window.prliAdmin.prefill = window.prliAdmin.prefill || {};
  if ( ! window.prliAdmin.prefill.url ) {
    window.prliAdmin.prefill.url = url;
  }
  if ( postTitle && ! window.prliAdmin.prefill.name ) {
    window.prliAdmin.prefill.name = postTitle;
  }

  try {
    window.localStorage.removeItem( STORAGE_KEY );
  } catch ( ignored ) {
    // Best-effort cleanup.
  }
}() );
