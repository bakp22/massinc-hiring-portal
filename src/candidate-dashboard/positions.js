( function () {
	function init( root ) {
		var errorMessage = root.querySelector( '.massinc-positions-error' );

		function saveRequirement( positionId, field, required ) {
			return fetch(
				root.dataset.jobsEndpoint + '/' + positionId + '/requirements',
				{
					method: 'PATCH',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': root.dataset.nonce,
					},
					body: JSON.stringify( {
						field: field,
						required: required,
					} ),
				}
			).then( function ( response ) {
				return response.json().then( function ( result ) {
					if ( ! response.ok ) {
						throw new Error(
							result.message ||
								'Could not save. Please try again.'
						);
					}
					return result;
				} );
			} );
		}

		root.querySelectorAll( '[data-position-id]' ).forEach(
			function ( row ) {
				var switches = row.querySelectorAll( 'input[data-field]' );
				switches.forEach( function ( input ) {
					input.addEventListener( 'change', function () {
						var previous = ! input.checked;
						switches.forEach( function ( control ) {
							control.disabled = true;
						} );
						row.setAttribute( 'aria-busy', 'true' );
						errorMessage.hidden = true;
						errorMessage.textContent = '';
						saveRequirement(
							row.dataset.positionId,
							input.dataset.field,
							input.checked
						)
							.then( function ( result ) {
								switches.forEach( function ( control ) {
									control.checked =
										result.required_fields[
											control.dataset.field
										];
								} );
							} )
							.catch( function ( error ) {
								input.checked = previous;
								errorMessage.hidden = false;
								errorMessage.textContent =
									error.message ||
									'Could not save. Check your connection and try again.';
							} )
							.finally( function () {
								switches.forEach( function ( control ) {
									control.disabled = false;
								} );
								row.setAttribute( 'aria-busy', 'false' );
								if (
									document.activeElement === document.body
								) {
									input.focus();
								}
							} );
					} );
				} );
			}
		);
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document
			.querySelectorAll( '.massinc-positions[data-jobs-endpoint]' )
			.forEach( init );
	} );
} )();
