<?php
/**
 * Registers hooks to enqueue styles and scripts for the client UI
 */

// Make sure we're included from within the plugin
require( ECP1_DIR . '/includes/check-ecp1-defined.php' );

// We need the calendar providers for script enqueueing
require_once( ECP1_DIR . '/includes/external-calendar-providers.php' );
require_once( ECP1_DIR . '/includes/data/ecp1-settings.php' );

// Define a global variable for the dynamic FullCalendar load script
$_ecp1_dynamic_calendar_script = null;

// Enqueue the jQuery and jQuery UI scripts that FullCalendar requires
// + Enqueue the FulLCalendar JS and CSS
add_action( 'wp_enqueue_scripts', 'ecp1_add_client_scripts' );
function ecp1_add_client_scripts() {
	if ( is_single() || is_page() ) {
		// jQuery and jQuery UI first as they're required by FullCalendar
		wp_enqueue_script( 'jquery' );
		//wp_enqueue_script( 'jquery-ui-core' );
		
		// Register the FullCalendar scripts and styles
		wp_register_style( 'ecp1_fullcalendar_style_all', plugins_url( '/fullcalendar/fullcalendar.css', dirname( __FILE__ ) ), false, false, 'all' );
		wp_register_style( 'ecp1_fullcalendar_style_print', plugins_url( '/fullcalendar/fullcalendar.print.css', dirname( __FILE__ ) ), false, array( 'ecp1_fullcalendar_style_all' ), 'print' );
		wp_register_style( 'ecp1_client_style', plugins_url( '/css/ecp1-client.css', dirname( __FILE__ ) ), false, array( 'ecp1_fullcalendar_style_all' ), 'all' );
		wp_register_script( 'ecp1_fullcalendar_script', plugins_url( '/fullcalendar/fullcalendar.min.js', dirname( __FILE__ ) ), array( 'jquery' ) );
		
		// Enqueue the registered scripts and styles
		wp_enqueue_style( 'ecp1_fullcalendar_style_all' );
		wp_enqueue_style( 'ecp1_fullcalendar_style_print' );
		wp_enqueue_style( 'ecp1_client_style' );
		wp_enqueue_script( 'ecp1_fullcalendar_script' );
		
		// Are there any enabled external calendar providers we should enqueue?
		$providers = ecp1_calendar_providers();
		foreach( $providers as $provider=>$details ) {
			if ( _ecp1_calendar_provider_enabled( $provider ) ) {
				wp_register_script( 'ecp1_calendar_provider-' . $provider, plugins_url( '/fullcalendar/' . $details['fullcal_plugin'], dirname( __FILE__ ) ), array( 'ecp1_fullcalendar_script' ) );
				wp_enqueue_script( 'ecp1_calendar_provider-' . $provider );
			}
		}
	}
}

// Function that will return the necessary HTML blocks and queue some static
// JS for the document load event to render a FullCalendar instance
function ecp1_render_calendar( $calendar ) {
	global $_ecp1_dynamic_calendar_script;
	
	// Make sure the calendar provided is valid
	if ( ! is_array( $calendar ) )
		return sprintf( '<div id="ecp1_calendar" class="ecp1_error">%s</div>', __( 'Invalid calendar cannot display.' ) );
	
	// Extract the calendar fields or go to defaults

	$first_day = get_option( 'start_of_week ' );	// 0=Sunday 6=Saturday (uses WordPress)
	if ( array_key_exists( 'ecp1_first_day', $calendar ) && is_numeric( $calendar['ecp1_first_day'] ) &&
			( 0 <= $calendar['ecp1_first_day'] && $calendar['ecp1_first_day'] <= 6 ) ) {
		$first_day = $calendar['ecp1_first_day'];
	}
	
	$description = '';	// Text based description make sure it's escaped
	if ( array_key_exists( 'ecp1_description', $calendar ) ) {
		$description = htmlspecialchars( $calendar['ecp1_description'] );
	}
	
	$timezone = get_option( 'timezone_string' );	// Timezone events in this calendar occur in
	if ( array_key_exists( 'ecp1_timezone', $calendar ) ) {
		// Only use the name if NOT WordPress Default
		if ( '_' != $calendar['ecp1_timezone'] ) {
			try {
				$dtz = new DateTimeZone( $calendar['ecp1_timezone'] );
				$offset = $dtz->getOffset( new DateTime( 'now' ) );
				$offset = 'UTC' . ( $offset < 0 ? ' - ' : ' + ' ) . ( abs( $offset/3600 ) );
				$ex = explode( '/', $dtz->getName() );
				$name = str_replace( '_', ' ', ( isset( $ex[2] ) ? $ex[2] : $ex[1] ) ); // Continent/Country/City
				$timezone = sprintf ( '%s (%s)', $name, $offset );
			} catch( Exception $tzmiss ) {
				// not a valid timezone
				$timezone = __( 'Unknown' );
			}
		}
	} elseif ( $timezone == null ) {
		$timezone = 'UTC';
	}
	
	$default_view = 'month';	// How the calendar displays by default
	if ( array_key_exists( 'ecp1_default_view', $calendar ) &&
			in_array( $calendar['ecp1_default_view'], array( 'month', 'week', 'day' ) ) ) {
		$default_view = $calendar['ecp1_default_view'];
	}
	
	// If the calendar has an external URL and they're enabled use it
	// otherwise by default will request events on this particular calendar
	$events_url = '/todo/noevents-here.php'; // TODO: Make this specific to calendar
	if ( array_key_exists( 'ecp1_external_url', $calendar ) && _ecp1_get_option( 'use_external_cals' ) ) {
		if ( '' != $calendar['ecp1_external_url'] ) { // a url has been given
			$events_url = urldecode( $calendar['ecp1_external_url'] );
		}
	}
	
	// Register a hook to print the static JS to load FullCalendar on #ecp1_calendar
	add_action( 'wp_print_footer_scripts', 'ecp1_print_fullcalendar_load' );
	
	// Now build the actual JS that will be loaded
	// TODO: Add eventClick function
	// TODO: Event Colors + Featured Events Source
	// TODO: Add Event Sources
	// TODO: Add currentTimezone to events hash
	$_ecp1_dynamic_calendar_script = <<<ENDOFSCRIPT
jQuery(document).ready(function($) {
	// $() will work as an alias for jQuery() inside of this function
	$('#ecp1_calendar div.fullcal').empty().fullCalendar({
		header: { left: 'prev,next today', center: 'title', right: 'month,agendaWeek,agendaDay' },
		timeFormat: { agenda: 'h:mmtt( - h:mmtt	)', '': 'h(:mm)tt' },
		weekends: true,
		defaultView: '$default_view',
		allDaySlot: false,
		events: {
			url: '$events_url'
		}
	});
});
ENDOFSCRIPT;
	
	// Now return HTML that the above script will use
	$description = '' != $description ? '<p><strong>' . $description . '</strong></p>' : '';
	$timezone = '<p><em>Events occur at ' . $timezone . ' local time.</em></p>';
	return sprintf( '<div id="ecp1_calendar">%s<div class="fullcal">%s</div>%s</div>', $description, __( 'Loading...' ), $timezone );
}

// Function to print the dynamic load script 
function ecp1_print_fullcalendar_load() {
	global $_ecp1_dynamic_calendar_script;
	if ( null != $_ecp1_dynamic_calendar_script ) {
		printf( '%s<!-- Every Calendar +1 Init -->%s<script type="text/javascript">%s%s%s</script>%s', "\n", "\n", "\n", $_ecp1_dynamic_calendar_script, "\n", "\n" );
	}
}

?>
