<?php
namespace EM\Payments\Mycred;

use EM_Booking, EM_Bookings, EM_Object, EM_Multiple_Bookings, EM_Multiple_Booking, EMP_Logs, EM_Pro, EM;

/**
 * Base class for myCred gateways, should not be used directly
 */
class Gateway extends \EM\Payments\Gateway {
	
	public static $js_loaded = false;

	/**
	 * Sets up gateaway and adds relevant actions/filters
	 */
	public static function init(){
		//Booking Interception
		parent::init();
		static::$status_txt = __('Awaiting myCred Checkout Payment','mycred_em');
		if( static::is_active() ) {
		
		}
	}
	
	/**
	 * Deletes bookings pending payment that are more than x minutes old, defined by the options page. This also provides a fallback check on webhooks in case there's a failed connection.
	 */
	public static function handle_booking_timeout( $booking_ids ){
		if( count($booking_ids) > 0 ){
			//go through each booking and check the status of the checkout session via the paymentintent
			foreach( $booking_ids as $booking_id ){
				$EM_Booking = em_get_booking($booking_id);
				// check for session or paymentintent (will work for elements too)
				
				//Verify if Payment has been made by searching for the session checkout and validating the paymentintent
				try {
					$force_mode = static::force_mode( $EM_Booking ); // for Test Mode
					$payment_intent = static::get_payment_intent( $EM_Booking );
					if( $payment_intent ) {
						static::handle_payment_intent( $EM_Booking, $payment_intent );
					}
					static::$force_mode = $force_mode; // for Test Mode
				}catch( Exception $e ){
					
					EM_Pro::log( "Booking Timeout Error for Booking ID #$booking_id - <".get_class($e)."> (".$e->getCode().'): '.$e->getMessage(), 'mycred_checkout' );
				}
			}
		}
	}

	
	public static function handle_payment_status($EM_Booking, $amount, $payment_status, $currency, $txn_id, $timestamp, $args){
		$EM_DateTime = new \EM_DateTime($timestamp, 'UTC');
		$timestamp = $EM_DateTime->getDateTime();
		$filter_args = array( 'amount' => $amount, 'payment_status' => $payment_status, 'payment_currency' => $currency, 'transaction_id' => $txn_id, 'timestamp' => $timestamp, 'args' => $args );
		switch (strtolower($payment_status)) {
			case 'completed':
			case 'processed': // case: successful payment
				static::record_transaction($EM_Booking, $amount, $currency, $timestamp, $txn_id, $payment_status, '');
				
				if( $amount >= $EM_Booking->get_price() && (!get_option('em_'.static::$gateway.'_manual_approval', false) || !get_option('dbem_bookings_approval')) ){
					$EM_Booking->approve(true, true); //approve and ignore spaces
				}else{
					//TODO do something if pp payment not enough
					$EM_Booking->set_status(0); //Set back to normal "pending"
				}
				do_action('em_payment_processed', $EM_Booking, static::class, $filter_args);
				break;
			
			case 'reversed':
			case 'voided' :
				// case: charge back
				$note = 'Last transaction has been reversed. Reason: Payment has been reversed (charge back)';
				static::record_transaction($EM_Booking, $amount, $currency, $timestamp, $txn_id, $payment_status, $note);
				
				//We need to cancel their booking.
				$EM_Booking->cancel();
				do_action('em_payment_reversed', $EM_Booking, static::class, $filter_args);
				
				break;
			
			case 'refunded':
				// case: refund
				$note = 'Payment has been refunded';
				static::record_transaction($EM_Booking, $amount, $currency, $timestamp, $txn_id, $payment_status, $note);
				$amount = $amount < 0 ? $amount * -1 : $amount; //we need to compare two positive numbers for refunds
				if( $amount >= $EM_Booking->get_price() ){
					$EM_Booking->cancel();
				}else{
					$EM_Booking->set_status(0, false); //Set back to normal "pending" but don't send email about it to prevent confusion
				}
				do_action('em_payment_refunded', $EM_Booking, static::class, $filter_args);
				break;
			
			case 'denied':
				// case: denied
				$note = 'Last transaction has been reversed. Reason: Payment Denied';
				static::record_transaction($EM_Booking, $amount, $currency, $timestamp, $txn_id, $payment_status, $note);
				
				$EM_Booking->cancel();
				do_action('em_payment_denied', $EM_Booking, static::class, $filter_args);
				break;
			
			case 'in-progress':
			case 'pending':
				// case: payment is pending
				$pending_str = array(
					'address' => 'Customer did not include a confirmed shipping address',
					'authorization' => 'Funds not captured yet',
					'echeck' => 'eCheck that has not cleared yet',
					'intl' => 'Payment waiting for aproval by service provider',
					'multi-currency' => 'Payment waiting for service provider to handle multi-currency process',
					'unilateral' => 'Customer did not register or confirm his/her email yet',
					'upgrade' => 'Waiting for service provider to upgrade the PayPal account',
					'verify' => 'Waiting for service provider to verify his/her PayPal account',
					'paymentreview' => 'Paypal is currently reviewing the payment and will approve or reject within 24 hours',
					'*' => ''
				);
				$reason = @$args['pending_reason'];
				$note = 'Last transaction is pending. Reason: ' . (isset($pending_str[$reason]) ? $pending_str[$reason] : $pending_str['*']);
				
				static::record_transaction($EM_Booking, $amount, $currency, $timestamp, $txn_id, $payment_status, $note);
				
				do_action('em_payment_pending', $EM_Booking, static::class, $filter_args);
				break;
			case 'Canceled_Reversal':
				//do nothing, just update the transaction
				break;
			default:
				// case: various error cases
		}
	}


}