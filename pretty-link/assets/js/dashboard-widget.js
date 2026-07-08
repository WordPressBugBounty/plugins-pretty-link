/**
 * Pretty Links — Dashboard quick-add widget.
 *
 * Vanilla JS (no React/webpack) because the widget lives on WordPress's
 * main admin dashboard, outside the Pretty Links bundle graph. Submits
 * via `POST /wp-json/pretty-links/v1/links` — the same REST endpoint the
 * React admin uses. On success the visitor is redirected to the Links
 * list. On error the message renders inline; no page reload.
 */
( function () {
  'use strict';

  function install() {
    var slugInput = document.getElementById( 'prli-quick-add-slug' );
    if ( slugInput ) {
      // Server rejects slugs starting with `/`; strip them at the
      // keystroke level so users don't have to round-trip an error.
      slugInput.addEventListener( 'input', function () {
        var next = slugInput.value.replace( /^\/+/, '' );
        if ( next !== slugInput.value ) {
          slugInput.value = next;
        }
      } );
    }

    var form = document.querySelector( '[data-prli-quick-add-form]' );
    if ( ! form ) {
      return;
    }

    var notice       = document.querySelector( '[data-prli-quick-add-notice]' );
    var submitBtn    = form.querySelector( '[data-prli-quick-add-submit]' );
    var submitLabel  = form.querySelector( '[data-prli-quick-add-submit-label]' );
    var defaultLabel = submitLabel ? submitLabel.textContent : '';
    var bootstrap    = ( typeof window !== 'undefined' && window.prliQuickAdd ) || {};

    function showNotice( kind, message ) {
      if ( ! notice ) {
        return;
      }
      notice.hidden = false;
      notice.className = 'prli-quick-add__notice prli-quick-add__notice--' + kind;
      notice.textContent = message;
    }

    function hideNotice() {
      if ( notice ) {
        notice.hidden = true;
        notice.textContent = '';
      }
    }

    function setSubmitting( busy ) {
      if ( ! submitBtn ) {
        return;
      }
      submitBtn.disabled = !! busy;
      if ( submitLabel ) {
        submitLabel.textContent = busy
          ? ( bootstrap.savingLabel || 'Saving…' )
          : defaultLabel;
      }
    }

    function messageFor( errorCode, fallback ) {
      switch ( errorCode ) {
        case 'url_required':  return 'A target URL is required.';
        case 'url_invalid':   return 'Target URL must start with http:// or https://.';
        case 'slug_in_use':   return 'That slug is already in use.';
        case 'slug_reserved': return 'That slug is reserved. Pick another.';
        case 'invalid_slug':  return 'Slugs cannot start with a forward slash.';
        case 'insert_failed': return 'Could not create the Pretty Link.';
        default:              return fallback || 'Something went wrong.';
      }
    }

    form.addEventListener( 'submit', function ( e ) {
      e.preventDefault();
      hideNotice();

      var urlInput = form.querySelector( '#prli-quick-add-target-url' );
      var slug     = slugInput ? slugInput.value.trim() : '';
      var url      = urlInput ? urlInput.value.trim() : '';
      if ( ! url ) {
        showNotice( 'error', messageFor( 'url_required' ) );
        return;
      }

      var payload = { url: url };
      if ( slug !== '' ) {
        payload.slug = slug;
      }

      setSubmitting( true );

      fetch( bootstrap.restRoot, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce':   bootstrap.restNonce || '',
        },
        body: JSON.stringify( payload ),
      } )
        .then( function ( res ) {
          return res.json().then( function ( body ) {
            return { ok: res.ok, status: res.status, body: body };
          } );
        } )
        .then( function ( result ) {
          if ( result.ok ) {
            // Redirect to the Links list — same end-state as
            // the v3 server-rendered form had.
            window.location.href = bootstrap.linksListUrl || '';
            return;
          }
          var code = result.body && ( result.body.code || result.body.error );
          var msg  = result.body && result.body.message;
          showNotice( 'error', messageFor( code, msg ) );
        } )
        .catch( function () {
          showNotice( 'error', messageFor( null, 'Network error. Please try again.' ) );
        } )
        .finally( function () {
          setSubmitting( false );
        } );
    } );
  }

  if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', install );
  } else {
    install();
  }
} )();
