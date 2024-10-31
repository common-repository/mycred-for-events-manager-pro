<?php
function emp_mycred_install_or_update(){
	//mycred_checkout
	add_option('em_mycred_checkout_option_name', esc_html__('Pay with myCred', 'mycred_em'));
	add_option('em_mycred_checkout_booking_feedback', esc_html__emp('Booking successfull.','mycred_em'));
	add_option('em_mycred_checkout_booking_feedback_free', esc_html__emp('Booking successful.', 'events-manager'));
	add_option('em_mycred_checkout_button', esc_html__('Pay with myCred', 'mycred_em'));
	add_option('em_mycred_checkout_booking_feedback_completed', esc_html__emp('Thank you for your payment. Your transaction has been completed an you will soon receive an email confirmation.', 'mycred_em'));
	add_option('em_mycred_checkout_booking_feedback_cancelled', esc_html__emp('Your booking payment has been cancelled, please try again.', 'mycred_em'));
	add_option('em_mycred_checkout_inc_tax', false );
	add_option('em_mycred_checkout_reserve_pending', true );
	add_option('em_offline_booking_feedback', emp__('Booking successful.', 'events-manager'));

	
	//mycred_elements
	add_option('em_mycred_elements_option_name', esc_html__('Pay with myCred Elements', 'mycred_em'));
	add_option('em_mycred_elements_booking_feedback', esc_html__emp('Booking successfull.','mycred_em'));
	add_option('em_mycred_elements_booking_feedback_free', esc_html__emp('Booking successful.', 'events-manager'));
	add_option('em_mycred_elements_booking_feedback_completed', esc_html__emp('Thank you for your payment. Your transaction has been completed an you will soon receive an email confirmation.', 'mycred_em'));
	add_option('em_mycred_elements_booking_feedback_cancelled', esc_html__emp('Your booking payment has been cancelled, please try again.', 'mycred_em'));
	add_option('em_mycred_elements_inc_tax', false );
	add_option('em_mycred_elements_reserve_pending', true );
	
	// update for new test mode
	$current_version = get_option('emp_mycred_version');
	if( $current_version ) {
		if ( version_compare( '2.0', $current_version, '>' ) ) {
			// copy creds and other settings from myCred
			$current_api = get_option( 'em_mycred_checkout_api' );
			update_option( 'em_mycred_elements_api', $current_api );
			
			update_option('em_mycred_elements_option_name', get_option('em_mycred_checkout_option_name'));
			update_option('em_mycred_elements_booking_feedback', get_option('em_mycred_checkout_booking_feedback'));
			update_option('em_mycred_elements_booking_feedback_free', get_option('em_mycred_checkout_booking_feedback_free'));
			update_option('em_mycred_elements_booking_feedback_completed', get_option('em_mycred_checkout_booking_feedback_completed'));
			update_option('em_mycred_elements_booking_feedback_cancelled', get_option('em_mycred_checkout_booking_feedback_cancelled'));
			update_option('em_mycred_elements_inc_tax', get_option('em_mycred_checkout_inc_tax'));
			update_option('em_mycred_elements_reserve_pending', get_option('em_mycred_checkout_reserve_pending'));
			update_option('em_mycred_elements_mode', get_option('em_mycred_checkout_mode'));
		}
		
	}
	
	//update version
	update_option('emp_mycred_version', EM_PRO_MYCRED_VERSION);
}