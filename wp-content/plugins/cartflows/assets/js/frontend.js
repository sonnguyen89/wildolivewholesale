(function($){

	/* It will redirect if anyone clicked on link before ready */
	$(document).on( 'click', 'a[href*="wcf-next-step"]', function(e) {
		
		e.preventDefault();

		if( 'undefined' !== typeof cartflows.is_pb_preview && '1' == cartflows.is_pb_preview ) {
			e.stopPropagation();
			return;
		}

		window.location.href = cartflows.next_step; 

		return false;
	});

	/* Once the link is ready this will work to stop conditional propogation*/
	$(document).on( 'click', '.wcf-next-step-link', function(e) {

		if( 'undefined' !== typeof cartflows.is_pb_preview && '1' == cartflows.is_pb_preview ) {
			e.preventDefault();
			e.stopPropagation();
			return false;
		}
	});

	// Remove css when oceanwp theme is enabled.
	var remove_oceanwp_custom_style = function(){
		if( 'OceanWP' === cartflows.current_theme && 'default' !== cartflows.page_template){
			var style = document.getElementById("oceanwp-style-css");
			if( null != style ){
				style.remove();
			}
		}
	};

	$(document).ready(function($) {
		
		/* Assign the class & link to specific button */
		var next_links = $('a[href*="wcf-next-step"]');

		if ( next_links.length > 0 && 'undefined' !== typeof cartflows.next_step ) {
			next_links.addClass( 'wcf-next-step-link' );
			next_links.attr( 'href', cartflows.next_step );
		}
		remove_oceanwp_custom_style();
		
	});
	
})(jQuery);