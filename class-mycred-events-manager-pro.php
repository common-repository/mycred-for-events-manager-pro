<?php

if ( ! class_exists( 'MyCred_Events_Manager' ) && defined( 'EM_VERSION' ) ) :
class MyCred_Events_Manager {

                public $label        = '';
		public $prefs        = NULL;
		public $mycred_type  = MYCRED_DEFAULT_TYPE_KEY;
		public $core         = NULL;
		public $booking_cols = 0;
		public $title        = '';	
		public $gateway      = MYCRED_SLUG;
		public $status       = 0;
		public $status_txt   = '';

        function __construct() {

			// Default settings
			$defaults = array(
				'setup'    => 'on',
				'type'     => MYCRED_DEFAULT_TYPE_KEY,
				'rate'     => 100,
				'share'    => 0,
				'log'      => array(
					'purchase'      => __( 'Ticket payment %bookingid%', 'mycred_em' ),
					'refund'        => __( 'Ticket refund %bookingid%', 'mycred_em' ),
					'payout'        => __( 'Event booking payment for %link_with_title%', 'mycred_em' ),
					'payout_refund' => __( 'Event booking refund for %link_with_title%', 'mycred_em' )
				),
				'refund'   => 0,
				'labels'   => array(
					'header'   => __( 'Pay using your %_plural% balance', 'mycred_em' ),
					'button'   => __( 'Pay Now', 'mycred_em' ),
					'link'     => __( 'Pay', 'mycred_em' ),
					'checkout' => __( '%plural% Cost', 'mycred_em' )
				),
				'messages' => array(
					'success'  => __( 'Thank you for your payment!', 'mycred_em' ),
					'error'    => __( "I'm sorry but you can not pay for these tickets using %_plural%", 'mycred_em' ),
					'excluded' => __( 'You can not pay using this gateway.', 'mycred_em' )
				)
			);

			$settings          = get_option( 'mycred_eventsmanager_gateway_prefs' );
			$this->prefs       = mycred_apply_defaults( $defaults, $settings );
			$this->mycred_type = $this->prefs['type'];

			// Load myCRED
			$this->core        = mycred( $this->mycred_type );

			// Apply Whitelabeling
			$this->label       = mycred_label();
			$this->title       = strip_tags( $this->label );
			$this->status_txt  = 'Paid using ' . $this->title;


		}

		public function load() {
		
			 if ( isset( $_GET['gateway'] ) && $_GET['gateway'] === 'mycred_checkout' ) {
			   add_action('em_gateway_settings_footer', array( $this,'em_gateway_settings_footer'));
			 }
			 
			 add_action('em_gateway_update',                    array( $this, 'em_gateway_update'));
			 add_filter( 'em_get_currencies',                  array( $this, 'add_currency' ),1000 );
			 if ( $this->single_currency() ) {
				add_filter('em_get_currency_formatted', array($this,'format_price'),10,4);
			 }
			 add_filter( 'em_booking_set_status',              array( $this, 'refunds' ), 1000, 2 );
			 add_action( 'em_template_my_bookings_header',     array( $this, 'say_thanks' ),1000 );

			// Adjust Ticket Columns
			add_filter( 'em_booking_form_tickets_cols',               array( $this, 'ticket_columns' ), 1000, 2 );
			add_action( 'em_booking_form_tickets_col_' . MYCRED_SLUG, array( $this, 'ticket_col' ), 1000, 2 );
			add_action( 'em_cart_form_after_totals',                  array( $this, 'checkout_total' ),1000 );


		}

		function uses_gateway($EM_Booking){
		  return (!empty($EM_Booking->booking_meta['gateway']) && $EM_Booking->booking_meta['gateway'] == $this->gateway);
	        }

		public function em_booking_get_status($message, $EM_Booking){

			$mycred = mycred();

			if( get_option('dbem_multiple_bookings') && $message === 'Approved'  ) {

					$user_id    = $EM_Booking->person_id;
						$price    = $EM_Booking->get_price();  
						$cost     = floatval($this->get_point_cost( $EM_Booking ));
						$booking_id = $EM_Booking->booking_id;

				        $this->core->add_creds(
								'ticket_purchase',
								$user_id,
								0 - $cost,
								$this->prefs['log']['purchase'],
								$booking_id,
								array( 'ref_type' => 'post' ),
								$this->mycred_type
								);

			    	 add_filter('em_multiple_booking_save', array(&$this, 'em_booking_save'),1000,2);			    
			}

			else {
				if( $message === 'Approved'){
						$user_id    = $EM_Booking->person_id;
						$price    = $EM_Booking->get_price();  
						$cost     = floatval($this->get_point_cost( $EM_Booking ));
						$booking_id = $EM_Booking->booking_id;

				        $this->core->add_creds(
								'ticket_purchase',
								$user_id,
								0 - $cost,
								$this->prefs['log']['purchase'],
								$booking_id,
								array( 'ref_type' => 'post' ),
								$this->mycred_type
								);
			    	 add_filter('em_booking_save', array(&$this, 'em_booking_save'),1000,2);
			    }
				}	

		}

		

		function say_thanks() {
			
		   echo '<div class="em-booking-message em-booking-message-success">' . esc_html( 'Booking Successful' ) . '</div>';
			
		}

	        function has_paid( $booking_id = 0, $user_id = 0 ) {

			$paid = $this->core->has_entry( 'ticket_purchase', $booking_id, $user_id, array( 'ref_type' => 'post' ), $this->mycred_type );

			if ( ! $paid && get_option( 'dbem_multiple_bookings' ) )
				$paid = $this->core->has_entry( 'ticket_purchase', $booking_id - 1, $user_id, array( 'ref_type' => 'post' ), $this->mycred_type );

			return apply_filters( 'mycred_em_has_paid', $paid, $booking_id, $user_id, $this );

		}

	  
	        function authorize_and_capture( $EM_Booking ) {

			$user_id    = $EM_Booking->person_id;
			$booking_id = $EM_Booking->booking_id;
			$captured   = false;

			// Make sure user is not excluded from the set point type
			if ( $this->core->exclude_user( $user_id ) ) {

				$EM_Booking->add_error( $this->core->template_tags_general( $this->prefs['messages']['excluded'] ) );

			}

			// User can not afford to pay
			elseif ( ! $this->can_pay( $EM_Booking ) ) {

				$EM_Booking->add_error( $this->core->template_tags_general( $this->prefs['messages']['error'] ) );

			}

			// User has not yet paid (prefered)
			elseif ( ! $this->has_paid( $booking_id, $user_id ) ) {

				

				// Get Cost
				$price    = $EM_Booking->get_price();
				$cost     = floatval($this->get_point_cost( $EM_Booking ));

				// Charge
				$captured = $this->core->add_creds(
					'ticket_purchase',
					$user_id,
					0 - $cost,
					$this->prefs['log']['purchase'],
					$booking_id,
					array( 'ref_type' => 'post' ),
					$this->mycred_type
				);

				// Points were successfully takes from the users balance
				if ( $captured ) {

					// Log transaction with EM
					$transaction_id = time() . $user_id;
					$currency       = get_option( 'dbem_bookings_currency' );
					$amount_paid    = $price;
					if ( $this->points_as_currency() ) {
						$currency    = '';
						$amount_paid = $cost;
					}

					$EM_Booking->booking_meta[ $this->gateway ] = array( 'txn_id' => $transaction_id, 'amount' => $amount_paid );
					$this->record_transaction( $EM_Booking, $amount_paid, $currency, date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), $transaction_id, 'Completed', '' );

					// Profit share (if enabled)
					$this->pay_profit_share( $EM_Booking );

				}

				// Something declined the transaction. Users balance has not changed!
				else {

					$message = apply_filters( 'mycred_em_charge_failed', __( 'Payment declined. Please try a different payment option.', 'mycred_em' ), $EM_Booking, $this );
					$EM_Booking->add_error( $message );

				}

			}

			// A payment was found for this booking
			// Should never happen but if it does, we need to show more than just an empty error message.
			else {

				$EM_Booking->add_error( sprintf( __( 'Duplicate transaction for booking ID: %s', 'mycred_em' ), $booking_id ) );

			}

			return apply_filters( 'mycred_em_authorize_and_capture', $captured, $EM_Booking, $this );

		} 

 

	        function em_booking_save( $result, $EM_Booking ) {

			global $wpdb, $wp_rewrite, $EM_Notices;

			//make sure booking save was successful before we try anything
			if ( $result ) {

				if ( $EM_Booking->get_price() > 0 ) {

					// Authorize & Capture point payment
					$captured = $this->authorize_and_capture( $EM_Booking );

					// Payment Successfull
					if ( $captured ) {

						// Set booking status, but no emails sent
						if ( ! get_option( 'em_' . $this->gateway . '_manual_approval', false ) || ! get_option( 'dbem_bookings_approval' ) ) {
							$EM_Booking->set_status( 1, false ); //Approve
						}

						else {
							$EM_Booking->set_status( 0, false ); //Set back to normal "pending"
						}

					}

					// Authorization declined. Either because: 1. User not logged in 2. User is excluded from point type 3. Insufficient funds
					else {

						// not good.... error inserted into booking in capture function. Delete this booking from db
						if ( ! is_user_logged_in() && get_option( 'dbem_bookings_anonymous' ) && ! get_option( 'dbem_bookings_registration_disable' ) && ! empty( $EM_Booking->person_id ) ) {

							//delete the user we just created, only if created after em_booking_add filter is called (which is when a new user for this booking would be created)
							$EM_Person = $EM_Booking->get_person();
							if ( strtotime( $EM_Person->data->user_registered ) >= $this->registered_timer ) {

								if ( is_multisite() ) {
									include_once ABSPATH . '/wp-admin/includes/ms.php';
									wpmu_delete_user( $EM_Person->ID );
								}
								else {
									include_once ABSPATH . '/wp-admin/includes/user.php';
									wp_delete_user( $EM_Person->ID );
								}

								// remove email confirmation
								global $EM_Notices;

								$EM_Notices->notices['confirms'] = array();

							}

						}

						$EM_Booking->manage_override = true;
						$EM_Booking->delete();
						$EM_Booking->manage_override = false;

						return false;

					}

				}

			}

			return $result;

		}


		function get_point_cost( $EM_Booking ) {

			$price    = 0;

			foreach ( $EM_Booking->get_tickets_bookings()->tickets_bookings as $EM_Ticket_Booking ) {
				$price += (int) $EM_Ticket_Booking->get_price(true);
			}

			//calculate discounts, if any:
			$discount = $EM_Booking->get_price_discounts_amount('pre') + $EM_Booking->get_price_discounts_amount('post');
			if ( $discount > 0 )
				$price = $price - $discount;

			$cost     = $this->maybe_exchange( $price );

			return apply_filters( 'mycred_em_get_point_cost', $cost, $EM_Booking, $this );

		}

		function get_cost( $EM_Booking ) {
			return $this->get_point_cost( $EM_Booking );
		}

		function maybe_exchange( $value = 0 ) {

			$exchanged = $value;
			if ( ! $this->points_as_currency() ||  $this->points_as_currency() )
				$exchanged = $this->core->number( $this->prefs['rate'] * $value );

			return $exchanged;

		}

		function checkout_total( $EM_Multiple_Booking ) {

			if ( ! is_user_logged_in() ) return;

			$user_id = get_current_user_id();
			$mycred = mycred();
			$balance = $this->core->get_users_balance($user_id);
		
			$total   = $EM_Multiple_Booking->get_price();
			$price   = $this->maybe_exchange( $total );

			$color   = '';
			if ( $balance < $price )
				$color = ' style="color:red;"';

			$content = '
				<tr>
					<th colspan="2">' . $this->core->template_tags_general( $this->prefs['labels']['checkout'] ) . '</th>
					<td>' . $this->core->format_creds( $price ) . '</td>
				</tr>
				<tr>
					<th colspan="2">' . __( 'Your Balance', 'mycred_em' ) . '</th>
					<td' . $color . '>' . $this->core->format_creds( $balance ) . '</td>
				</tr>';

			echo apply_filters( 'mycred_em_checkout_total', $content, $EM_Multiple_Booking, $this );

		}

		function ticket_col( $EM_Ticket, $EM_Event ) {

			$ticket_price = $EM_Ticket->get_price( false );
			if ( empty( $ticket_price ) ) $price = 0;

			$price        = $this->maybe_exchange( $ticket_price );
			$content      = apply_filters( 'mycred_em_ticket_column', $this->core->format_creds( $price ), $EM_Ticket, $EM_Event, $this );

			if ( $content != '' )
				echo '<td class="em-bookings-ticket-table-points">' . $this->core->format_creds( $price ) . '</td>';

		}

		function points_as_currency() {

			$points_currency = false;
			if ( $this->prefs['setup'] == 'single'  )
				$points_currency = true;

			return apply_filters( 'mycred_em_points_as_currency', $points_currency, $this );

		}

		function ticket_columns( $columns, $EM_Event ) {

			if ( ! $EM_Event->is_free() ) {

				$original_columns = $columns;

				unset( $columns['price'] );
				unset( $columns['type'] );
				unset( $columns['spaces'] );

				$columns['type']  = __( 'Ticket Type', 'mycred_em' );

				if ( $this->points_as_currency() )
					$columns[ MYCRED_SLUG ] = __( 'Price', 'mycred_em' );

				else {

					$columns['price']       = __( 'Price', 'mycred_em' );
					$columns[ MYCRED_SLUG ] = $this->core->plural();

				}

				$columns['spaces'] = __( 'Spaces', 'mycred_em' );

				$columns = apply_filters( 'mycred_em_ticket_columns', $columns, $original_columns, $EM_Event, $this );

			}

			$this->booking_cols = count( $columns );

			return $columns;

		}

		function has_been_refunded( $booking_id = 0, $user_id = 0 ) {

			$refunded = $this->core->has_entry( 'ticket_purchase_refund', $booking_id, $user_id, array( 'ref_type' => 'post' ), $this->mycred_type );

			if ( ! $refunded && get_option( 'dbem_multiple_bookings' ) )
				$refunded = $this->core->has_entry( 'ticket_purchase_refund', $booking_id - 1, $user_id, array( 'ref_type' => 'post' ), $this->mycred_type );

			return apply_filters( 'mycred_em_has_been_refunded', $refunded, $booking_id, $user_id, $this );

		}

		function refunds( $result, $EM_Booking ) {

			// Cancellation or Rejection refunds the payment
			if ( in_array( $EM_Booking->booking_status, array( 2, 3 ) ) && in_array( $EM_Booking->previous_status, array( 0, 1 ) ) && $this->prefs['refund'] > 0 ) {

				$user_id    = $EM_Booking->person_id;
				$booking_id = $EM_Booking->booking_id;

				// Make sure user has paid for this to refund
				if ( $this->has_paid( $booking_id, $user_id ) && ! $this->has_been_refunded( $booking_id, $user_id ) ) {

					// Get Cost
					$cost   = $EM_Booking->get_price();

					// Amount to refund
					$refund = $EM_Booking->get_price();
					if ( $this->prefs['refund'] != 100 )
						$refund = floatval(($this->prefs['refund'] / 100) * $EM_Booking->get_price());


					// Refund
					$this->core->add_creds(
						'ticket_purchase_refund',
						$user_id,
						$refund,
						$this->prefs['log']['refund'],
						$booking_id,
						array( 'ref_type' => 'post' ),
						$this->mycred_type
					);

					$this->refund_profit_shares( $EM_Booking );

				}

			}

			else {

				                $user_id    = $EM_Booking->person_id;
						$price    = $EM_Booking->get_price();  
						$cost     = floatval($this->get_point_cost( $EM_Booking ));
						$booking_id = $EM_Booking->booking_id;


				                $this->core->add_creds(
								'ticket_purchase',
								$user_id,
								0 - $EM_Booking->get_price(),
								$this->prefs['log']['purchase'],
								$booking_id,
								array( 'ref_type' => 'post' ),
								$this->mycred_type
								);

			}

			return $result;

		}

		function get_share( $value = 0 ) {

			$share = $value;
			if ( $this->prefs['share'] != 100 )
				$share = ( $this->prefs['share'] / 100 ) * $value;

			$share = $this->core->number( $share );

			return apply_filters( 'mycred_em_get_share', $share, $value, $this );

		}

		function refund_profit_shares( $EM_Booking ) {

			if ( $this->prefs['share'] > 0 ) {

				$booking_id = (int) $EM_Booking->booking_id;
				foreach ( $EM_Booking->get_tickets_bookings()->tickets_bookings as $EM_Ticket_Booking ) {

					// Get Event Post
					$event_booking = $EM_Ticket_Booking->get_booking()->get_event();
					$event_post    = get_post( (int) $event_booking->post_id );

					// $share = ( $final_price / 100 ) * $profit_sharing;

					// Need to make sure the event object exists
					if ( $event_post !== NULL ) {

						// Get share
						$price = $this->maybe_exchange( $EM_Ticket_Booking->get_price(true) );
						$share = ( $EM_Booking->get_price() / 100 ) * $this->prefs['share'];

						// Payout
						$this->core->add_creds(
							'ticket_sale_refund',
							$event_post->post_author,
							$share,
							$this->prefs['log']['payout_refund'],
							$event_post->ID,
							array( 'ref_type' => 'post', 'bid' => $booking_id ),
							$this->mycred_type
						);

					}

				}

			}

			do_action( 'mycred_em_refund_profit_shares', $EM_Booking, $this );

		}

		public function format_price( $formatted_price, $price, $currency, $format ) {

			if ( $currency == 'XMY' )
				return $this->core->format_creds( $price );

			return $formatted_price;

		}

		public function single_currency() {

			if ( $this->prefs['setup'] == 'single' ) return true;

			return false;

		}

		public function add_currency( $currencies ) {

			$currencies->names['XMY']        = $this->core->plural();
			$currencies->symbols['XMY']      = '';
			$currencies->true_symbols['XMY'] = '';

			if ( ! empty( $this->core->before ) )
				$currencies->symbols['XMY'] = $this->core->before;

			elseif ( ! empty( $this->core->after ) )
				$currencies->symbols['XMY'] = $this->core->after;

			if ( ! empty( $this->core->before ) )
				$currencies->true_symbols['XMY'] = $this->core->before;

			elseif ( ! empty( $this->core->after ) )
				$currencies->true_symbols['XMY'] = $this->core->after;

			return $currencies;

		}

		public function em_gateway_update() {
		
			if ( ! isset( $_POST['mycred_gateway'] ) || ! is_array( $_POST['mycred_gateway'] ) ) return;

			$new_settings                    = array();
			$format                          = '';

			// Setup
			$new_settings['setup']           = isset( $_POST['mycred_gateway']['setup'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['setup'] ) ) : '';
			$new_settings['type']            = isset( $_POST['mycred_gateway']['type'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['type'] ) ) : '';
			$new_settings['refund']          = isset( $_POST['mycred_gateway']['refund'] ) ? abs( sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['refund'] ) ) ) : '';
			$new_settings['share']           = isset( $_POST['mycred_gateway']['share'] ) ? abs( sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['share'] ) ) ) : '';

			// Logs
			$new_settings['log']['purchase'] = isset( $_POST['mycred_gateway']['log']['purchase'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['log']['purchase'] ) ) : '';
			$new_settings['log']['refund']   = isset( $_POST['mycred_gateway']['log']['refund'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['log']['refund'] ) ) : '';

			if ( $new_settings['setup'] == 'multi' )
				$new_settings['rate'] = isset( $_POST['mycred_gateway']['rate'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['rate'] ) ) : '';
			else
				$new_settings['rate'] = $this->prefs['rate'];

			// Override Pricing Options
			if ( $new_settings['setup'] == 'single' ) {

				update_option( 'dbem_bookings_currency_decimal_point', $this->core->format['separators']['decimal'] );
				update_option( 'dbem_bookings_currency_thousands_sep', $this->core->format['separators']['thousand'] );
				update_option( 'dbem_bookings_currency', 'XMY' );

				if ( empty( $this->core->before ) && ! empty( $this->core->after ) )
					$format = '@ #';

				elseif ( ! empty( $this->core->before ) && empty( $this->core->after ) )
					$format = '# @';

				update_option( 'dbem_bookings_currency_format', $format );

			}

			// Labels
			$new_settings['labels']['link']      = isset( $_POST['mycred_gateway']['labels']['link'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['labels']['link'] ) ) : '';
			$new_settings['labels']['header']    = isset( $_POST['mycred_gateway']['labels']['header'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['labels']['header'] ) ) : '';
			$new_settings['labels']['button']    = isset( $_POST['mycred_gateway']['labels']['button'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['labels']['button'] ) ) : '';

			// Messages
			$new_settings['messages']['success'] = isset( $_POST['mycred_gateway']['messages']['success'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['messages']['success'] ) ) : '';
			$new_settings['messages']['error']   = isset( $_POST['mycred_gateway']['messages']['error'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['messages']['error'] ) ) : '';
			$new_settings['messages']['url']   = isset( $_POST['mycred_gateway']['messages']['url'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['messages']['url'] ) ) : '';
			$new_settings['messages']['text']   = isset($_POST['mycred_gateway']['messages']['text']) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['messages']['text'] ) ) : '';

			// Save Settings
			$current     = $this->prefs;

			$this->prefs = mycred_apply_defaults($current,$new_settings);
			update_option('mycred_eventsmanager_gateway_prefs',$this->prefs);

			// Let others play
			do_action( 'mycred_em_save_settings', $this );

		}

		public function em_gateway_settings_footer() {

			$mycred_types = mycred_get_types();

			do_action( 'mycred_em_before_settings', $this );

			?>

			<hr />
<h3><?php _e( 'Setup', 'mycred_em' ); ?></h3>
<p><?php printf( __( 'If you are unsure how to use this gateway, feel free to consult the %s.', 'mycred_em' ), sprintf( '<a href="http://codex.mycred.me/chapter-iii/gateway/events-manager/" target="_blank">%s</a>', __( 'online documentation', 'mycred_em' ) ) ); ?></p>
<table class="form-table">

	<tr>
		<th scope="row"><?php _e( 'Point Type', 'mycred_em' ); ?></th>
		<td>
			<?php if ( count( $mycred_types ) > 1 ) : ?>

			<?php mycred_types_select_from_dropdown( 'mycred_gateway[type]', 'mycred-gateway-type', $this->prefs['type'] ); ?>
			<?php else : ?>
			<p><?php echo $this->core->plural(); ?></p>
			<input type="hidden" name="mycred_gateway[type]" value="<?php echo MYCRED_DEFAULT_TYPE_KEY; ?>" />
			<?php endif; ?>
			<p><span class="description"><?php _e( 'The point type to accept as payment.', 'mycred_em' ); ?></span></p>

		</td>
	</tr>
	<tr>
		<th scope="row"><?php _e( 'Store Currency', 'mycred_em' ); ?></th>
		<td>
			<label for="mycred-gateway-setup-single"><input type="radio" name="mycred_gateway[setup]" id="mycred-gateway-setup-single" value="single"<?php checked( $this->prefs['setup'], 'single' ); ?> /> <?php _e( 'Bookings are paid using Points only.', 'mycred_em' ); ?></label><br /><br />
			<label for="mycred-gateway-setup-multi"><input type="radio" name="mycred_gateway[setup]" id="mycred-gateway-setup-multi" value="multi"<?php checked( $this->prefs['setup'], 'multi' ); ?> /> <?php _e( 'Bookings are paid using Real Money or Points.', 'mycred_em' ); ?></label>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php _e( 'Refunds', 'mycred_em' ); ?></th>
		<td>
			<input name="mycred_gateway[refund]" type="text" id="mycred-gateway-log-refund" value="<?php echo esc_attr( $this->prefs['refund'] ); ?>" size="5" /> %<br />
			<p><span class="description"><?php _e( 'The percentage of the paid amount to refund if a user cancels their booking or if a booking is rejected. Use zero for no refunds.', 'mycred_em' ); ?></span></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php _e( 'Profit Sharing', 'mycred_em' ); ?></th>
		<td>
			<input name="mycred_gateway[share]" type="text" id="mycred-gateway-profit-sharing" value="<?php echo esc_attr( $this->prefs['share'] ); ?>" size="5" /> %<br />
			<p><span class="description"><?php _e( 'Option to share sales with the event owner. Use zero to disable.', 'mycred_em' ); ?></span></p>
		</td>
	</tr>
</table>
<table class="form-table" id="mycred-exchange-rate" style="display: <?php echo ( $this->prefs['setup'] == 'multi' ) ? 'block' : 'none'; ?>;">
	<tr>
		<th scope="row"><?php _e( 'Exchange Rate', 'mycred_em' ); ?></th>
		<td>
			<input name="mycred_gateway[rate]" type="text" id="mycred-gateway-rate" size="6" value="<?php echo esc_attr( $this->prefs['rate'] ); ?>" /><br />
			<p><span class="description"><?php printf( __( 'How many %s is needed to pay for 1 %s?', 'mycred_em' ), $this->core->plural(), em_get_currency_symbol() ); ?></span></p>
		</td>
	</tr>
</table>

<hr />
<h3><?php _e( 'Log Templates', 'mycred_em' ); ?></h3>
<table class="form-table">
	<tr>
		<th scope="row"><?php _e( 'Booking Payments', 'mycred_em' ); ?></th>
		<td>
			<input name="mycred_gateway[log][purchase]" type="text" id="mycred-gateway-log-purchase" style="width: 95%;" value="<?php echo esc_attr( $this->prefs['log']['purchase'] ); ?>" size="45" />
			<p><span class="description"><?php echo $this->core->available_template_tags( array( 'general' ), '%bookingid%' ); ?></span></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php _e( 'Payment Refunds', 'mycred_em' ); ?></th>
		<td>
			<input name="mycred_gateway[log][refund]" type="text" id="mycred-gateway-log-refund" style="width: 95%;" value="<?php echo esc_attr( $this->prefs['log']['refund'] ); ?>" size="45" />
			<p><span class="description"><?php echo $this->core->available_template_tags( array( 'general' ), '%bookingid%' ); ?></span></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php _e( 'Profit Share Payouts', 'mycred_em' ); ?></th>
		<td>
			<input name="mycred_gateway[log][payout]" type="text" id="mycred-gateway-log-payout-purchase" style="width: 95%;" value="<?php echo esc_attr( $this->prefs['log']['purchase'] ); ?>" size="45" />
			<p><span class="description"><?php _e( 'Ignored if profit sharing is disabled.', 'mycred_em' ); ?> <?php echo $this->core->available_template_tags( array( 'general', 'post' ) ); ?></span></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php _e( 'Profit Share Refunds', 'mycred_em' ); ?></th>
		<td>
			<input name="mycred_gateway[log][payout_refund]" type="text" id="mycred-gateway-log-payout-refund" style="width: 95%;" value="<?php echo esc_attr( $this->prefs['log']['refund'] ); ?>" size="45" />
			<p><span class="description"><?php _e( 'Ignored if profit sharing is disabled.', 'mycred_em' ); ?> <?php echo $this->core->available_template_tags( array( 'general', 'post' ) ); ?></span></p>
		</td>
	</tr>
</table>
<script type="text/javascript">
jQuery(function($){
	$('input[name="mycred_gateway[setup]"]').change(function(){
		if ( $(this).val() == 'multi' ) {
			$('#mycred-exchange-rate').show();
		}
		else {
			$('#mycred-exchange-rate').hide();
		}
	});
});
</script>

<hr />
<h3><?php _e( 'Labels', 'mycred_em' ); ?></h3>
<table class="form-table">
	<tr valign="top">
		<th scope="row"><?php _e( 'Payment Link Label', 'mycred_em' ); ?></th>
		<td>
			<input name="mycred_gateway[labels][link]" type="text" id="mycred-gateway-labels-link" style="width: 95%" value="<?php echo esc_attr( $this->prefs['labels']['link'] ); ?>" size="45" /><br />
			<p><span class="description"><?php _e( 'The payment link shows / hides the payment form under "My Bookings". No HTML allowed.', 'mycred_em' ); ?></span></p>
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e( 'Payment Header', 'mycred_em' ); ?></th>
		<td>
			<input name="mycred_gateway[labels][header]" type="text" id="mycred-gateway-labels-header" style="width: 95%" value="<?php echo esc_attr( $this->prefs['labels']['header'] ); ?>" size="45" /><br />
			<p><span class="description"><?php _e( 'Shown on top of the payment form. No HTML allowed.', 'mycred_em' ); ?></span></p>
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e( 'Button Label', 'mycred_em' ); ?></th>
		<td>
			<input name="mycred_gateway[labels][button]" type="text" id="mycred-gateway-labels-button" style="width: 95%" value="<?php echo esc_attr( $this->prefs['labels']['button'] ); ?>" size="45" /><br />
			<p><span class="description"><?php _e( 'The button label for payments. No HTML allowed.', 'mycred_em' ); ?></span></p>
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e( 'Cart & Checkout Cost', 'mycred_em' ); ?></th>
		<td>
			<input name="mycred_gateway[labels][checkout]" type="text" id="mycred-gateway-labels-button" style="width: 95%" value="<?php echo esc_attr( $this->prefs['labels']['checkout'] ); ?>" size="45" /><br />
			<p><span class="description"><?php echo $this->core->template_tags_general( __( 'Label for cost in %plural%.', 'mycred_em' ) ); ?> <?php echo $this->core->available_template_tags( array( 'general' ) ); ?></span></p>
		</td>
	</tr>
</table>
<hr />
<h3><?php _e( 'Messages', 'mycred_em' ); ?></h3>
<table class='form-table'>
	<tr valign="top">
		<th scope="row"><?php _e( 'Successful Payments', 'mycred_em' ); ?></th>
		<td>
			<input type="text" name="mycred_gateway[messages][success]" id="mycred-gateway-messages-success" style="width: 95%;" value="<?php echo esc_attr( $this->prefs['messages']['success'] ); ?>" /><br />
			<p><span class="description"><?php _e( 'Optional message to show users when they have successfully paid using points.', 'mycred_em' ); ?> <?php echo $this->core->available_template_tags( array( 'general' ) ); ?></span></p>
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e( 'Insufficient Funds', 'mycred_em' ); ?></th>
		<td>
			<input type="text" name="mycred_gateway[messages][error]" id="mycred-gateway-messages-error" style="width: 95%;" value="<?php echo esc_attr( $this->prefs['messages']['error'] ); ?>" /><br />
			<p><span class="description"><?php _e( 'The message to show users that can not afford to pay for their order using points. Can not be empty! No HTML allowed!', 'mycred_em' ); ?> <?php echo $this->core->available_template_tags( array( 'general' ) ); ?></span></p>
		</td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e( 'Excluded', 'mycred_em' ); ?></th>
		<td>
			<input type="text" name="mycred_gateway[messages][excluded]" id="mycred-gateway-messages-excluded" style="width: 95%;" value="<?php echo esc_attr( $this->prefs['messages']['excluded'] ); ?>" /><br />
			<p><span class="description"><?php _e( 'Optional message to show users who are excluded from the selected point type. No HTML allowed.', 'mycred_em' ); ?></span></p>
		</td>
	</tr>
</table>
			<?php
		}
	}
endif;

