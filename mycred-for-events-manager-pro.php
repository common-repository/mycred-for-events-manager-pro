<?php
/*
Plugin Name: myCRED for Events Manager Pro
Plugin URI: https://mycred.me/
Description: This plugin connects myCRED with the Events Manager Pro plugin.
Version: 2.7
Depends: Events Manager Pro
Tested up to: WP 6.6.2
Text Domain: mycred_em
Author: myCred
Author URI: https://mycred.me/
*/
define('EM_PRO_MYCRED_VERSION', '2.7');
define('EM_PRO_MYCRED_VERSION_EM_PRO_MIN', '3.2.7');

//check pre-requisites
require_once('requirements-check.php');
$requirements = new EMP_MYCRED\Requirements_Check('Events Manager Pro myCred', __FILE__, '5.6');
if( !$requirements->passes(false) ) return;
unset($requirements);

//Set when to run the plugin : after EM is loaded.
function events_manager_pro_mycred_loader() {
	if ( !defined('EMP_VERSION') || version_compare(EMP_VERSION, EM_PRO_MYCRED_VERSION_EM_PRO_MIN, '<') ) {
		//add notice and prevent further loading
		add_action('admin_notices', 'events_manager_pro_MYCRED_version_warning_critical');
		add_action('network_admin_notices', 'events_manager_pro_mycred_version_warning_critical');
		return;
	}
	if( version_compare( get_option('emp_mycred_version'), EM_PRO_MYCRED_VERSION ) ){
		include('emp-mycred-install.php');
		emp_mycred_install_or_update();
	}
	// load only in non-legacy mode
	if( !EM_Options::site_get('legacy-gateways', false) && !em_constant('EMP_GATEWAY_LEGACY') ) {
		include( 'gateway.mycred.php' );
		include( 'gateway.mycred-checkout.php' );
	}else{
		add_action('admin_notices', 'events_manager_pro_mycred_legacy_notice');
	}
}
add_action( 'em_gateways_init', 'events_manager_pro_mycred_loader', 1000 );


function events_manager_pro_mycred_version_warning_critical() {
	// translators: plugin names and version numbers
	$warning = __('Please make sure you have %1$s version %2$s or greater installed, as this may prevent %3$s from functioning properly.', 'mycred_em');
	// translators: plugin names
	$inactive_warning = esc_html__('Until it is updated, %s will remain inactive to prevent further errors.', 'mycred_em');
	$inactive_warning = sprintf( $inactive_warning, '<strong>Events Manager Pro - myCred</strong>');
	?>
	<div class="error">
		<p>
			<?php echo sprintf( esc_html($warning), '<a href="http://eventsmanagerpro.com/downloads/">Events Manager Pro</a>', esc_html(EM_PRO_MYCRED_VERSION_EM_PRO_MIN), '<strong>Events Manager Pro - myCred</strong>'); ?>
			<em><?php esc_html_e('Only admins see this message.', 'em-wc'); ?></em>
		</p>
		<p><?php echo $inactive_warning; ?>
	</div>
	<?php
}

function events_manager_pro_mycred_legacy_notice(){
	?>
	<div class="notice notice-error"><p><b>Events Manager Pro for myCred 2.0</b> will not work in legacy mode. Please disable legacy mode in your gateway settings.</p></div>
	<?php
}

//run regardless in case there's updates
function events_maanger_pro_mycred_updates( $dependencies ){
	$dependencies['events-manager-pro-mycred'] = array(
		'name' => 'Events Manager Pro - myCred',
		'version' => EM_PRO_MYCRED_VERSION,
		'plugin' => plugin_basename(__FILE__),
	);
	return $dependencies;
}
add_filter('pxl_updates_depend_on_events-manager-pro', 'events_maanger_pro_mycred_updates');

//Add translation
function emp_mycred_load_plugin_textdomain() {
	load_plugin_textdomain('mycred_em', false, dirname( plugin_basename( __FILE__ ) ).'/languages');
}
add_action('plugins_loaded', 'emp_mycred_load_plugin_textdomain');

add_action( 'mycred_parse_log_entry',  'parse_log_entries' , 1000, 2 );		
function parse_log_entries( $content, $log_entry ) {

	if ( in_array( $log_entry->ref, array( 'ticket_purchase', 'ticket_purchase_refund' ) ) ) {

	           $booking_id = $log_entry->ref_id;
				$data       = maybe_unserialize( $log_entry->data );
				if ( array_key_exists( 'bid', $data ) )
					$booking_id = $data['bid'];

				$content = str_replace( '%bookingid%', $booking_id ?? '', $content ?? '' );
	}

			return $content;
}

add_action( 'mycred_all_references',  'add_badge_support',1000 );
function add_badge_support( $references ) {

			if ( ! class_exists( 'EM_Pro' ) ) return $references;

			$references['ticket_purchase']        = __( 'Event Payment (Events Manager)', 'mycred_em' );
			$references['ticket_sale']            = __( 'Event Sale (Events Manager)', 'mycred_em' );
			$references['ticket_purchase_refund'] = __( 'Event Payment Refund (Events Manager)', 'mycred_em' );
			$references['ticket_sale_refund']     = __( 'Event Sale Refund (Events Manager)', 'mycred_em' );

			return $references;
}

add_action( 'em_ticket_edit_form_fields','ticket_edit_form_fields_func',1000,2);
function ticket_edit_form_fields_func($col_count, $EM_Ticket) {
			
			global $post;

			$tickets_reward =  get_post_meta( $EM_Ticket->event_id, 'mycred_tickets_reward_' . $EM_Ticket->ticket_id, true );

			if( empty( $tickets_reward ) ) {

				return;

			}

			$userpointtypes = mycred_get_types();
		
			$html = '
					
			<div>
				<label for="point_reward">Point Reward:</label>
				<input type="number" name="em_tickets[' . $EM_Ticket->ticket_id . '][userpoints]" value="' . $tickets_reward['userpoints'] . '" >
				<select name="em_tickets[' . $EM_Ticket->ticket_id . '][pointtype]" >';
				
				foreach ($userpointtypes as $key => $value) {
					$html .= '
				  <option value="'.$key.'">' . $value . '</option>';
				  
				}
	
			$html .= '</select>'; 	  
			$html .= '</div>';
	
			echo $html;	
	
}


add_action( 'em_ticket_save_pre','em_ticket_save_pre_func',1000);
function em_ticket_save_pre_func($ticket) {
	
			global $post;
			
			if( ! isset( $_REQUEST['em_tickets'][ $ticket->ticket_id ] ) ) return;

			$submited_data = $_REQUEST['em_tickets'][ $ticket->ticket_id ];

			if( ! empty( $submited_data['userpoints'] ) ) {

				$tickets_reward =  array(
					'userpoints' => floatval( $submited_data['userpoints'] ),
					'pointtype'  => sanitize_text_field( $submited_data['pointtype'] )
				);

				mycred_update_post_meta( $ticket->event_id, 'mycred_tickets_reward_' . $ticket->ticket_id, $tickets_reward );

			}

}

add_filter( 'mycred_all_references','mycredpro_add_custom_references',1000 );
function mycredpro_add_custom_references( $list ) {
			$list['emp_ticket_purchase'] = 'Ticket Purchase Reference';
			return $list;
}

