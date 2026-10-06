/**
 * Onboarding wizard: guard the submit buttons that do slow work.
 *
 * Since the Features step installs queued add-ons on save, Continue can sit
 * downloading and activating plugins for minutes. The button stayed live and
 * said nothing, so people clicked again — and a second POST races the first
 * into Plugin_Upgrader::install() for the same destination. The loser gets
 * core's `folder_exists`, which the drain records as a failure, so the Finish
 * step reports "not installed" for an add-on that is installed and active.
 *
 * Every submit control on the page is dimmed, not just the one that was
 * pressed and not just the form it belongs to: the Finish step renders two
 * sibling forms that POST to the same URL, so dimming only the submitting one
 * leaves a live Continue sitting under the spinner, and clicking it fires the
 * very second request this exists to prevent.
 *
 * Progressive enhancement only: with JS off the form submits exactly as
 * before, and the server is unchanged either way.
 */
( function () {
	'use strict';

	const BUSY_CLASS = 'is-busy';
	const WORKING_CLASS = 'is-working';
	const FORM_SELECTOR = '.prli-onboarding-form';
	const SUBMIT_SELECTOR = 'button[type="submit"], input[type="submit"]';

	/**
	 * Dim every submit control on the page and label the one that was pressed.
	 *
	 * @param {HTMLElement} submitter The control that triggered the submit.
	 */
	function markBusy( submitter ) {
		document.querySelectorAll( FORM_SELECTOR ).forEach( function ( form ) {
			form.classList.add( BUSY_CLASS );

			form.querySelectorAll( SUBMIT_SELECTOR ).forEach(
				function ( control ) {
					control.classList.add( BUSY_CLASS );
					// aria-disabled rather than the disabled property: a disabled
					// submit control is left out of the form's entry list, and
					// handlePost() branches on `prli_action` for the Finish step's
					// drain and for Skip. Pointer events are killed in CSS
					// instead, which keeps the value in the payload.
					control.setAttribute( 'aria-disabled', 'true' );
				}
			);
		} );

		// The spinner and the label go only on the control that was pressed,
		// and only when it declares a label — Skip carries none, and putting
		// "Working…" on the Continue button the user did not press would say
		// the wrong thing about which operation is running.
		if ( submitter && submitter.hasAttribute( 'data-busy-label' ) ) {
			// Stash the resting label before overwriting it. The swap is
			// otherwise one-way, and a page restored from the back/forward
			// cache comes back with the DOM exactly as it was left — so the
			// button would read "Working…" for ever while being perfectly
			// clickable.
			if ( ! submitter.hasAttribute( 'data-idle-label' ) ) {
				submitter.setAttribute(
					'data-idle-label',
					submitter.textContent
				);
			}
			submitter.classList.add( WORKING_CLASS );
			submitter.textContent = submitter.getAttribute( 'data-busy-label' );
		}
	}

	/**
	 * Clear the busy state. Used when a page is restored from the back/forward
	 * cache, where the DOM comes back exactly as it was left — permanently
	 * dimmed, with the form-level guard refusing any further submit.
	 */
	function clearBusy() {
		document
			.querySelectorAll(
				FORM_SELECTOR + ', ' + FORM_SELECTOR + ' .' + BUSY_CLASS
			)
			.forEach( function ( node ) {
				node.classList.remove( BUSY_CLASS, WORKING_CLASS );
				node.removeAttribute( 'aria-disabled' );

				if ( node.hasAttribute( 'data-idle-label' ) ) {
					node.textContent = node.getAttribute( 'data-idle-label' );
					node.removeAttribute( 'data-idle-label' );
				}
			} );
	}

	/**
	 * Submit handler: guard every onboarding form on the page.
	 *
	 * @param {Event} event The submit event.
	 */
	function onSubmit( event ) {
		const form = event.target;

		if ( ! form || ! form.classList.contains( 'prli-onboarding-form' ) ) {
			return;
		}

		// A form already in flight must not queue a second request.
		if ( form.classList.contains( BUSY_CLASS ) ) {
			event.preventDefault();
			return;
		}

		markBusy( event.submitter );
	}

	document.addEventListener( 'submit', onSubmit, true );

	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			clearBusy();
		}
	} );
} )();
