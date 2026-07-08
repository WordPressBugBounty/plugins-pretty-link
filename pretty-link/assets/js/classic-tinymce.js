/**
 * Classic TinyMCE plugin for Pretty Links.
 *
 * Block-editor-style inserter: typeahead search against the REST API,
 * per-link defaults for new_window/nofollow/sponsored, and an inline
 * "create new" form. Emits raw <a> HTML matching the Block editor's
 * shape so the anchor classes (`pretty-link pretty-link-tinymce`) and
 * rel/target attributes stay consistent across surfaces.
 *
 * Vanilla ES5 — no build step. Depends on `wp.apiFetch` (enqueued by
 * ClassicEditor::enqueueBridge) and a bootstrap blob at
 * `window.prliClassicInserter`.
 */
( function () {
  if ( typeof tinymce === 'undefined' || ! tinymce.PluginManager ) {
    return;
  }

  var apiFetch = ( window.wp && window.wp.apiFetch ) || null;
  var i18n = function ( key ) {
    var data = window.prliClassicInserter || {};
    var strings = data.i18n || {};
    return strings[ key ] || key;
  };

  // Minimal escapers — we compose HTML by hand rather than innerHTML-ing
  // user input, so these only protect attribute values & text nodes.
  function escHtml( str ) {
    return String( str == null ? '' : str )
      .replace( /&/g, '&amp;' )
      .replace( /</g, '&lt;' )
      .replace( />/g, '&gt;' )
      .replace( /"/g, '&quot;' )
      .replace( /'/g, '&#39;' );
  }

  // Walks up from the caret / current selection and returns the nearest
  // `<a class="pretty-link">` ancestor, or null. Uses `editor.dom` so the
  // lookup runs against the TinyMCE iframe document, not the parent.
  function findPrettyLinkAnchor( editor ) {
    try {
      var node = editor.selection.getNode();
      if ( ! node ) { return null; }
      var anchor = editor.dom.getParent( node, 'a' );
      if ( ! anchor ) { return null; }
      var cls = anchor.className || '';
      if ( /\bpretty-link\b/.test( cls ) ) {
        return anchor;
      }
      return null;
    } catch ( _err ) {
      return null;
    }
  }

  function buildAnchorHtml( link, opts ) {
    var text = opts.text && opts.text.trim()
      ? opts.text.trim()
      : ( link.name || link.slug || link.pretty_url );

    var relParts = [];
    if ( opts.nofollow ) { relParts.push( 'nofollow' ); }
    if ( opts.sponsored ) { relParts.push( 'sponsored' ); }
    if ( opts.newWindow ) { relParts.push( 'noreferrer' ); relParts.push( 'noopener' ); }

    var attrs = ' href="' + escHtml( link.pretty_url ) + '"';
    attrs += ' class="pretty-link pretty-link-tinymce"';
    if ( relParts.length ) {
      attrs += ' rel="' + escHtml( relParts.join( ' ' ) ) + '"';
    }
    if ( opts.newWindow ) {
      attrs += ' target="_blank"';
    }
    return '<a' + attrs + '>' + escHtml( text ) + '</a>';
  }

  /**
   * Dialog renderer — builds a DOM overlay, wires it up, returns a
   * `close()` handle. Opened via the toolbar button's onclick.
   */
  function openInserter( editor, preselectedAnchor ) {
    if ( ! apiFetch ) {
      // apiFetch should always be present (ClassicEditor enqueues it
      // before this runs), but guard against theme/plugin conflicts
      // that dequeue wp-api-fetch and fall back to the legacy dialog.
      legacyFallback( editor );
      return;
    }

    var overlay = document.createElement( 'div' );
    overlay.className = 'prli-ct-overlay';
    overlay.setAttribute( 'role', 'dialog' );
    overlay.setAttribute( 'aria-modal', 'true' );
    overlay.setAttribute( 'aria-label', i18n( 'title' ) );

    // If the cursor is inside an existing pretty-link anchor, open in
    // edit mode: preload the toggles + link text from the anchor and
    // replace it on apply instead of inserting a fresh one. A caller
    // can also pass the anchor directly when intercepting a click —
    // the selection doesn't always settle inside the anchor fast
    // enough to be read via selection.getNode.
    var existingAnchor = preselectedAnchor || findPrettyLinkAnchor( editor );

    var selectedText = '';
    try { selectedText = editor.selection.getContent( { format: 'text' } ) || ''; } catch ( _err ) {}

    var state = {
      query: '',
      results: [],
      loading: false,
      selectedIndex: -1,
      picked: null,
      newWindow: false,
      nofollow: false,
      sponsored: false,
      text: selectedText,
      creating: false,
      createOpen: false,
      createUrl: '',
      createSlug: '',
      createError: '',
      editing: !! existingAnchor,
      existingAnchor: existingAnchor,
    };

    if ( existingAnchor ) {
      var rel = existingAnchor.getAttribute( 'rel' ) || '';
      state.newWindow = existingAnchor.getAttribute( 'target' ) === '_blank';
      state.nofollow  = /\bnofollow\b/.test( rel );
      state.sponsored = /\bsponsored\b/.test( rel );
      state.text      = existingAnchor.textContent || '';
      state.picked    = {
        id: null,
        name: state.text,
        slug: '',
        pretty_url: existingAnchor.getAttribute( 'href' ) || '',
      };
    }

    var headerTitle = state.editing ? i18n( 'editTitle' ) : i18n( 'title' );
    var applyLabel  = state.editing ? i18n( 'update' ) : i18n( 'apply' );

    overlay.innerHTML = [
      '<div class="prli-ct-backdrop"></div>',
      '<div class="prli-ct-modal" role="document">',
        '<div class="prli-ct-header">',
          '<h2 class="prli-ct-title">', escHtml( headerTitle ), '</h2>',
          '<button type="button" class="prli-ct-close" aria-label="Close">×</button>',
        '</div>',
        '<div class="prli-ct-body">',
          '<div class="prli-ct-search-wrap">',
            '<input type="text" class="prli-ct-search" placeholder="', escHtml( i18n( 'searchPlaceholder' ) ), '" autocomplete="off" />',
          '</div>',
          '<ul class="prli-ct-results" role="listbox"></ul>',
          '<div class="prli-ct-text-row">',
            '<label class="prli-ct-label">', escHtml( i18n( 'linkText' ) ), '</label>',
            '<input type="text" class="prli-ct-text" />',
            '<p class="prli-ct-help">', escHtml( i18n( 'linkTextHelp' ) ), '</p>',
          '</div>',
          '<div class="prli-ct-toggles">',
            '<label class="prli-ct-toggle"><input type="checkbox" data-flag="newWindow" /> ', escHtml( i18n( 'openInNewTab' ) ), '</label>',
            '<label class="prli-ct-toggle"><input type="checkbox" data-flag="nofollow" /> ', escHtml( i18n( 'nofollow' ) ), '</label>',
            '<label class="prli-ct-toggle"><input type="checkbox" data-flag="sponsored" /> ', escHtml( i18n( 'sponsored' ) ), '</label>',
          '</div>',
          '<details class="prli-ct-create">',
            '<summary>', escHtml( i18n( 'createNew' ) ), '</summary>',
            '<div class="prli-ct-create-form">',
              '<label class="prli-ct-label">', escHtml( i18n( 'targetUrl' ) ), '</label>',
              '<input type="url" class="prli-ct-create-url" placeholder="https://example.com" />',
              '<label class="prli-ct-label">', escHtml( i18n( 'slug' ) ), '</label>',
              '<input type="text" class="prli-ct-create-slug" placeholder="my-link" />',
              '<div class="prli-ct-create-actions">',
                '<button type="button" class="prli-ct-create-btn button button-primary">', escHtml( i18n( 'createInsert' ) ), '</button>',
                '<span class="prli-ct-create-status"></span>',
              '</div>',
              '<p class="prli-ct-create-error" role="alert"></p>',
            '</div>',
          '</details>',
        '</div>',
        '<div class="prli-ct-footer">',
          state.editing
            ? '<button type="button" class="prli-ct-remove button button-link-delete">' + escHtml( i18n( 'removeLink' ) ) + '</button>'
            : '',
          '<span class="prli-ct-footer-spacer"></span>',
          '<button type="button" class="prli-ct-cancel button">', escHtml( i18n( 'cancel' ) ), '</button>',
          '<button type="button" class="prli-ct-apply button button-primary">', escHtml( applyLabel ), '</button>',
        '</div>',
      '</div>',
    ].join( '' );

    document.body.appendChild( overlay );

    var els = {
      backdrop: overlay.querySelector( '.prli-ct-backdrop' ),
      closeBtn: overlay.querySelector( '.prli-ct-close' ),
      cancelBtn: overlay.querySelector( '.prli-ct-cancel' ),
      applyBtn: overlay.querySelector( '.prli-ct-apply' ),
      removeBtn: overlay.querySelector( '.prli-ct-remove' ),
      search: overlay.querySelector( '.prli-ct-search' ),
      results: overlay.querySelector( '.prli-ct-results' ),
      text: overlay.querySelector( '.prli-ct-text' ),
      toggles: overlay.querySelectorAll( '.prli-ct-toggle input' ),
      createDetails: overlay.querySelector( '.prli-ct-create' ),
      createUrl: overlay.querySelector( '.prli-ct-create-url' ),
      createSlug: overlay.querySelector( '.prli-ct-create-slug' ),
      createBtn: overlay.querySelector( '.prli-ct-create-btn' ),
      createStatus: overlay.querySelector( '.prli-ct-create-status' ),
      createError: overlay.querySelector( '.prli-ct-create-error' ),
    };

    els.text.value = state.text;
    // Seed toggle checkboxes from state (covers edit mode where we
    // preloaded newWindow/nofollow/sponsored from the existing anchor).
    for ( var t = 0; t < els.toggles.length; t++ ) {
      var tcb = els.toggles[ t ];
      tcb.checked = !! state[ tcb.getAttribute( 'data-flag' ) ];
    }
    // In edit mode the user already has a link — Apply is enabled even
    // before they re-pick from the search list, so they can just update
    // toggles or text and save.
    els.applyBtn.disabled = ! state.picked;

    function close() {
      if ( overlay.parentNode ) { overlay.parentNode.removeChild( overlay ); }
      document.removeEventListener( 'keydown', onKey );
    }

    function onKey( e ) {
      if ( e.key === 'Escape' ) { close(); }
    }
    document.addEventListener( 'keydown', onKey );

    els.backdrop.addEventListener( 'click', close );
    els.closeBtn.addEventListener( 'click', close );
    els.cancelBtn.addEventListener( 'click', close );

    function setToggles( from ) {
      state.newWindow = !! from.new_window;
      state.nofollow  = !! from.nofollow;
      state.sponsored = !! from.sponsored;
      for ( var i = 0; i < els.toggles.length; i++ ) {
        var cb = els.toggles[ i ];
        cb.checked = !! state[ cb.getAttribute( 'data-flag' ) ];
      }
    }

    for ( var i = 0; i < els.toggles.length; i++ ) {
      els.toggles[ i ].addEventListener( 'change', function ( e ) {
        state[ e.target.getAttribute( 'data-flag' ) ] = !! e.target.checked;
      } );
    }

    els.text.addEventListener( 'input', function ( e ) {
      state.text = e.target.value;
    } );

    function renderResults() {
      els.results.innerHTML = '';
      if ( state.suppressResults ) {
        return;
      }
      if ( state.loading ) {
        var li = document.createElement( 'li' );
        li.className = 'prli-ct-result-empty';
        li.textContent = '…';
        els.results.appendChild( li );
        return;
      }
      if ( ! state.results.length ) {
        if ( state.query.length >= 2 ) {
          var none = document.createElement( 'li' );
          none.className = 'prli-ct-result-empty';
          none.textContent = i18n( 'noResults' );
          els.results.appendChild( none );
        }
        return;
      }
      state.results.forEach( function ( link, idx ) {
        var li = document.createElement( 'li' );
        li.className = 'prli-ct-result' + ( idx === state.selectedIndex ? ' is-selected' : '' ) + ( state.picked && state.picked.id === link.id ? ' is-picked' : '' );
        li.setAttribute( 'role', 'option' );
        li.innerHTML = '<span class="prli-ct-result-name">' + escHtml( link.name || link.slug ) + '</span><span class="prli-ct-result-url">' + escHtml( link.pretty_url ) + '</span>';
        li.addEventListener( 'click', function () { pick( link ); } );
        els.results.appendChild( li );
      } );
    }

    function pick( link ) {
      state.picked = link;
      setToggles( link );
      // Reflect the selection in the search input so the user sees
      // which link is currently staged — otherwise the only cue is
      // a subtle background tint on the suggestion row.
      if ( link && ( link.name || link.slug ) ) {
        els.search.value = link.name || link.slug;
        state.query = els.search.value;
      }
      // Collapse the results list — the user picked, the selection is
      // now represented by the search input value + toggles, so the
      // floating list just takes up space. Re-opens on next keystroke.
      state.results = [];
      state.selectedIndex = -1;
      state.suppressResults = true;
      els.applyBtn.disabled = false;
      renderResults();
    }

    var searchReqId = 0;
    var searchTimer = null;
    function runSearch( q ) {
      state.query = q;
      clearTimeout( searchTimer );
      if ( q.length < 2 ) {
        state.results = [];
        state.selectedIndex = -1;
        state.loading = false;
        renderResults();
        return;
      }
      state.loading = true;
      renderResults();
      searchTimer = setTimeout( function () {
        var myId = ++searchReqId;
        apiFetch( {
          path: '/pretty-links/v1/links?per_page=10&status=any&search=' + encodeURIComponent( q ),
        } )
          .then( function ( links ) {
            if ( myId !== searchReqId ) { return; }
            state.results = Array.isArray( links ) ? links : [];
            state.selectedIndex = -1;
            state.loading = false;
            renderResults();
          } )
          .catch( function () {
            if ( myId !== searchReqId ) { return; }
            state.results = [];
            state.loading = false;
            renderResults();
          } );
      }, 200 );
    }

    els.search.addEventListener( 'input', function ( e ) {
      // User started typing again — reopen the results list.
      state.suppressResults = false;
      runSearch( e.target.value );
    } );
    els.search.addEventListener( 'keydown', function ( e ) {
      if ( e.key === 'ArrowDown' && state.results.length ) {
        e.preventDefault();
        state.selectedIndex = Math.min( state.selectedIndex + 1, state.results.length - 1 );
        renderResults();
      } else if ( e.key === 'ArrowUp' && state.results.length ) {
        e.preventDefault();
        state.selectedIndex = Math.max( state.selectedIndex - 1, 0 );
        renderResults();
      } else if ( e.key === 'Enter' ) {
        e.preventDefault();
        if ( state.selectedIndex >= 0 ) {
          pick( state.results[ state.selectedIndex ] );
        } else if ( state.picked ) {
          doInsert();
        }
      }
    } );

    els.applyBtn.addEventListener( 'click', doInsert );
    function doInsert() {
      if ( ! state.picked ) { return; }
      if ( state.editing && state.existingAnchor ) {
        // Update the existing anchor in place. setAttribs handles
        // removal for keys set to null/empty string (target/rel).
        var relParts = [];
        if ( state.nofollow ) { relParts.push( 'nofollow' ); }
        if ( state.sponsored ) { relParts.push( 'sponsored' ); }
        if ( state.newWindow ) { relParts.push( 'noreferrer' ); relParts.push( 'noopener' ); }
        editor.dom.setAttribs( state.existingAnchor, {
          href: state.picked.pretty_url,
          class: 'pretty-link pretty-link-tinymce',
          target: state.newWindow ? '_blank' : null,
          rel: relParts.length ? relParts.join( ' ' ) : null,
        } );
        var newText = state.text && state.text.trim()
          ? state.text.trim()
          : ( state.picked.name || state.picked.slug || state.picked.pretty_url );
        state.existingAnchor.textContent = newText;
        editor.selection.select( state.existingAnchor );
        editor.nodeChanged();
        close();
        return;
      }
      var html = buildAnchorHtml( state.picked, {
        text: state.text,
        newWindow: state.newWindow,
        nofollow: state.nofollow,
        sponsored: state.sponsored,
      } );
      editor.insertContent( html );
      close();
    }

    if ( els.removeBtn ) {
      els.removeBtn.addEventListener( 'click', function () {
        if ( ! state.existingAnchor ) { return; }
        // Replace the anchor with its text content so the words
        // stay but the link is gone.
        var textNode = editor.getDoc().createTextNode( state.existingAnchor.textContent || '' );
        state.existingAnchor.parentNode.replaceChild( textNode, state.existingAnchor );
        editor.nodeChanged();
        close();
      } );
    }

    els.createBtn.addEventListener( 'click', function () {
      if ( state.creating ) { return; }
      var url = ( els.createUrl.value || '' ).trim();
      var slug = ( els.createSlug.value || '' ).trim().replace( /^\/+/, '' );
      if ( ! url || ! slug ) { return; }

      state.creating = true;
      els.createBtn.disabled = true;
      els.createStatus.textContent = i18n( 'creating' );
      els.createError.textContent = '';

      apiFetch( {
        path: '/pretty-links/v1/links',
        method: 'POST',
        data: { url: url, slug: slug, redirect_type: '302' },
      } )
        .then( function ( link ) {
          state.creating = false;
          els.createBtn.disabled = false;
          els.createStatus.textContent = '';
          if ( link && link.pretty_url ) {
            pick( link );
            doInsert();
          }
        } )
        .catch( function ( err ) {
          state.creating = false;
          els.createBtn.disabled = false;
          els.createStatus.textContent = '';
          els.createError.textContent = ( err && err.message ) || i18n( 'createError' );
        } );
    } );

    setTimeout( function () { els.search.focus(); }, 0 );

    // Edit mode: kick off a search for the anchor's slug so the real
    // link record shows up in the results list (and gets auto-picked
    // when it matches the href). Populates the search input visibly so
    // the user sees what was looked up.
    if ( state.editing && state.existingAnchor ) {
      var href = state.existingAnchor.getAttribute( 'href' ) || '';
      var slug = '';
      try {
        var u = new URL( href, window.location.origin );
        slug = ( u.pathname || '' ).replace( /\/$/, '' ).split( '/' ).pop() || '';
      } catch ( _err ) {
        slug = href.split( '?' )[ 0 ].replace( /\/$/, '' ).split( '/' ).pop() || '';
      }
      if ( slug ) {
        els.search.value = slug;
        var lookupId = ++searchReqId;
        state.loading = true;
        renderResults();
        apiFetch( {
          path: '/pretty-links/v1/links?per_page=10&status=any&search=' + encodeURIComponent( slug ),
        } )
          .then( function ( links ) {
            if ( lookupId !== searchReqId ) { return; }
            state.results = Array.isArray( links ) ? links : [];
            state.loading = false;
            state.query   = slug;
            // Auto-pick the row whose pretty_url matches the
            // anchor's href exactly — that's the record the
            // user is editing. Fall back to the first hit if
            // nothing matches exactly (shouldn't happen, but
            // keeps the UI responsive).
            var match = null;
            for ( var i = 0; i < state.results.length; i++ ) {
              if ( state.results[ i ].pretty_url === href ) {
                match = state.results[ i ];
                break;
              }
            }
            if ( ! match && state.results.length ) {
              match = state.results[ 0 ];
            }
            if ( match ) {
              // Route through pick() so the search input
              // shows the matched link's name and the list
              // collapses — same visual state as the user
              // clicking the suggestion themselves.
              pick( match );
              return;
            }
            renderResults();
          } )
          .catch( function () {
            if ( lookupId !== searchReqId ) { return; }
            state.loading = false;
            renderResults();
          } );
      }
    }
  }

  /**
   * Fallback dialog when wp.apiFetch isn't available — mirrors the
   * pre-rewrite UX so the button still does something useful if the
   * bridge script got dequeued.
   */
  function legacyFallback( editor ) {
    editor.windowManager.open( {
      title: i18n( 'title' ),
      body: [
        { type: 'textbox', name: 'ref', label: 'Link ID or slug', value: '' },
        { type: 'textbox', name: 'text', label: 'Link text (optional)', value: '' },
      ],
      onsubmit: function ( e ) {
        var ref = String( e.data.ref || '' ).trim();
        var text = String( e.data.text || '' ).trim();
        if ( ! ref ) { return; }
        var isNumeric = /^[0-9]+$/.test( ref );
        var attr = isNumeric ? ( 'id="' + ref + '"' ) : ( 'slug="' + ref.replace( /"/g, '&quot;' ) + '"' );
        var textAttr = text ? ( ' text="' + text.replace( /"/g, '&quot;' ) + '"' ) : '';
        editor.insertContent( '[prettylink ' + attr + textAttr + ' source="tinymce"]' );
      },
    } );
  }

  tinymce.PluginManager.add( 'prettylink', function ( editor ) {
    editor.addButton( 'prettylink', {
      title: i18n( 'buttonTitle' ),
      icon: 'link',
      onclick: function () { openInserter( editor ); },
      // Light up the toolbar button when the caret is inside a
      // pretty-link anchor so users see that clicking it will edit,
      // not insert.
      onPostRender: function () {
        var btn = this;
        editor.on( 'NodeChange', function () {
          btn.active( !! findPrettyLinkAnchor( editor ) );
        } );
      },
    } );

    // Hijack clicks on pretty-link anchors so our modal opens instead
    // of TinyMCE's native inline link popup (wplink / contexttoolbar).
    // Place the caret inside the anchor first so findPrettyLinkAnchor
    // picks it up when we open the inserter.
    editor.on( 'click', function ( e ) {
      var target = e.target;
      while ( target && target !== editor.getBody() ) {
        if ( target.nodeName === 'A' && /\bpretty-link\b/.test( target.className || '' ) ) {
          e.preventDefault();
          e.stopImmediatePropagation();
          editor.selection.select( target );
          openInserter( editor, target );
          return false;
        }
        target = target.parentNode;
      }
    } );

    // Suppress WP's native link context toolbar (wplink) for our
    // anchors — it races the click handler and occasionally flashes up
    // before our modal appears. Leaves non-pretty anchors alone.
    editor.on( 'wptoolbar', function ( e ) {
      if ( e.element && e.element.nodeName === 'A' && /\bpretty-link\b/.test( e.element.className || '' ) ) {
        e.toolbar = null;
      }
    } );
  } );
} )();
