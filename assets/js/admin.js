document.addEventListener( 'DOMContentLoaded', function () {
	var selectAll = document.getElementById( 'dsfm_select_all' );
	if ( ! selectAll ) {
		return;
	}
	selectAll.addEventListener( 'change', function () {
		var checkboxes = document.querySelectorAll( '.dsfm_file_checkbox' );
		checkboxes.forEach( function ( checkbox ) {
			checkbox.checked = selectAll.checked;
		} );
	} );
} );
