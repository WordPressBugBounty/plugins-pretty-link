/**
 * Pretty Links social share bar — client-side handlers.
 *
 * Only thing we actually need JS for: the Copy button. Everything else is
 * a plain <a href> to a share URL.
 */
( function () {
  'use strict';

  function copyText( text ) {
    if ( navigator.clipboard && navigator.clipboard.writeText ) {
      return navigator.clipboard.writeText( text );
    }
    // Fallback for older browsers — off-screen textarea + execCommand.
    return new Promise( function ( resolve, reject ) {
      try {
        var ta = document.createElement( 'textarea' );
        ta.value = text;
        ta.setAttribute( 'readonly', '' );
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild( ta );
        ta.select();
        var ok = document.execCommand( 'copy' );
        document.body.removeChild( ta );
        if ( ok ) {
          resolve();
        } else {
          reject( new Error( 'copy failed' ) );
        }
      } catch ( err ) {
        reject( err );
      }
    } );
  }

  function flash( btn ) {
    btn.classList.add( 'is-copied' );
    var label = btn.querySelector( '.prli-social-buttons__label' );
    var originalLabel = label ? label.textContent : null;
    if ( label ) {
      label.textContent = 'Copied!';
    }
    setTimeout( function () {
      btn.classList.remove( 'is-copied' );
      if ( label && originalLabel !== null ) {
        label.textContent = originalLabel;
      }
    }, 1600 );
  }

  document.addEventListener( 'click', function ( event ) {
    var btn = event.target.closest( '[data-prli-copy]' );
    if ( ! btn ) {
      return;
    }
    event.preventDefault();
    var text = btn.getAttribute( 'data-prli-copy' );
    if ( ! text ) {
      return;
    }
    copyText( text ).then(
      function () {
        flash( btn );
      },
      function () {
        // Silent failure: the user can still copy the URL from the address bar.
      }
    );
  } );
} )();
