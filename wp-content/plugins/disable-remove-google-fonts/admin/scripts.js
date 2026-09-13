/* global ajaxurl, drgfNotice, drgfCheck */
jQuery( document ).ready( function() {

	// Hook into the "notice-dismiss-welcome" class we added to the notice, so
	// Only listen to YOUR notices being dismissed
	jQuery( document ).on(
		'click',
		'.notice-dismiss-drgf .notice-dismiss',
		function() {
			// Read the "data-notice" information to track which notice
			// is being dismissed and send it via AJAX
			const type = jQuery( this ).closest( '.notice-dismiss-drgf' ).data( 'notice' );
			// Make an AJAX call
			// Since WP 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
			jQuery.ajax(
				ajaxurl,
				{
					type: 'POST',
					data: {
						action: 'drgf_dismiss_notice',
						type: type,
						nonce: drgfNotice.nonce,
					},
				}
			);
		}
	);


	// Handle admin bar "Check Google Fonts" click - capture current page and open in new window.
	jQuery( document ).on(
		'click',
		'#wp-admin-bar-drgf-check-fonts .ab-item',
		function( e ) {
			e.preventDefault();
			
			// Only allow on public-facing pages (not admin pages).
			if ( window.location.href.indexOf( '/wp-admin/' ) !== -1 ) {
				alert( drgfCheck.adminPageError || 'This feature is only available on public-facing pages.' );
				return;
			}
			
			// Get the full HTML of the current page.
			// Clone the document to get all HTML including dynamically added content.
			const htmlContent = document.documentElement.outerHTML;
			
			// Send captured HTML to server.
			jQuery.ajax(
				{
					url: drgfCheck.ajaxurl,
					type: 'POST',
					data: {
						action: 'drgf_capture_current_page',
						nonce: drgfCheck.nonce,
						html: htmlContent,
						url: window.location.href,
					},
					timeout: 10000,
				}
			)
			.done( function() {
				// Open results page in a new window/tab.
				window.open( drgfCheck.resultsPageUrl, '_blank' );
			} )
			.fail( function() {
				// Even if capture fails, open results page in a new window/tab.
				window.open( drgfCheck.resultsPageUrl, '_blank' );
			} );
		}
	);
} );
