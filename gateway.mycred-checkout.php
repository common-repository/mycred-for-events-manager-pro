<?php
namespace EM\Payments\Mycred\Checkout;
use EM_Multiple_Bookings, EM_Pro, EM\Payments\Gateways;
use Exception;

class Gateway extends \EM\Payments\Mycred\Gateway {
	public static $gateway                    = 'mycred_checkout';
	public static $title                      = 'myCred';
	public static $status                     = 4;
	public static $status_txt                 = '';
	public static $button_enabled             = true;
	public static $supports_multiple_bookings = true;
	public static $supports_manual_bookings = true;
	public static $can_manually_approve       = false;
	public static $has_timeout                = false;
	public static $count_pending_spaces       = true;
	public static $registered_timer           = 0;

	public static $label        = '';
	public static $prefs        = NULL;
	public static $mycred_type  = MYCRED_DEFAULT_TYPE_KEY;
	public static $core         = NULL;
	public static $booking_cols = 0;
	public static $instance     = NULL;

	
	public static function init(){
		parent::init();

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

        $mycred = mycred();
		$settings            = get_option( 'mycred_eventsmanager_gateway_prefs' );
		static::$prefs       =  wp_parse_args( $settings,$defaults  );
		static::$mycred_type = static::$prefs['type'];
		static::$core        = mycred( static::$mycred_type );
		static::$title       = strip_tags( static::$title );
		static::$status_txt  = 'Paid using ' . static::$title;

		static::$instance = new static();

		if ( isset( $_GET['gateway'] ) && $_GET['gateway'] === 'mycred_checkout' ) {
		    add_action('em_gateway_settings_footer', array(__CLASS__, 'em_gateway_settings_footer'));
		}

		add_action('em_bookings_single_metabox_footer', array(static::class, 'add_payment_form'),1,1); //add payment to booking
		add_filter('em_booking_set_status',array(static::class,'em_booking_set_status'),1,2);
		
		add_action( 'em_template_my_bookings_header', array( __CLASS__, 'say_thanks' ), 1000 );
		add_filter( 'em_booking_set_status', array( __CLASS__, 'refunds' ), 1000, 2 );
		add_action('em_gateway_update', array(__CLASS__, 'em_gateway_update'));
		add_filter('em_get_currencies', array(__CLASS__, 'add_currency'), 1000);
		
		

		if ( self::points_as_currency() ) {
           add_filter('em_get_currency_formatted', array(__CLASS__, 'format_price'), 10, 4);
        }

        if ( static::gateway_can_be_used() ) {
        	add_filter( 'em_booking_form_tickets_cols',               array( static::class, 'ticket_columns' ), 1000, 2 );
        	add_action('em_booking_form_tickets_col_' . MYCRED_SLUG, array(__CLASS__, 'ticket_col'), 1000, 2);
        	add_action('em_cart_form_after_totals', array(__CLASS__, 'checkout_total'), 1000);
        }

	}

	public static function gateway_can_be_used() {

	    $enabled = static::is_active(); // Replace $this-> with static:: for static method call
	    if ( $enabled ) {

	        if ( ! is_user_logged_in() )
	            $enabled = false;

	        else {

	            $user_id = get_current_user_id();
	            if ( static::$core->exclude_user( $user_id ) ) 
	                $enabled = false;

	        }

	    }

	    return apply_filters( 'mycred_em_gateway_can_be_used', $enabled, static::$instance ); // Use static reference
	}


	public static function checkout_total($EM_Multiple_Booking) {
    
	    if (!is_user_logged_in()) return;

	    $user_id = get_current_user_id();
	    $mycred = mycred();
	    $balance = static::$core->get_users_balance($user_id);

	    $total   = $EM_Multiple_Booking->get_price();
	    $price   = static::maybe_exchange($total);

	    $color   = '';
	    if ($balance < $price) {
	        $color = ' style="color:red;"';
	    }

	    $content = '
	    <tr>
	    <th colspan="2">' . static::$core->template_tags_general(static::$prefs['labels']['checkout']) . '</th>
	    <td>' . static::$core->format_creds($price) . '</td>
	    </tr>
	    <tr>
	    <th colspan="2">' . __('Your Balance', 'mycred_em') . '</th>
	    <td' . $color . '>' . static::$core->format_creds($balance) . '</td>
	    </tr>';

	    echo apply_filters('mycred_em_checkout_total', $content, $EM_Multiple_Booking, static::class);
	}

	public static function ticket_col($EM_Ticket, $EM_Event) {
	    
	    $ticket_price = $EM_Ticket->get_price(false);
	    $price        = empty($ticket_price) ? 0 : static::maybe_exchange($ticket_price);
	    
	    $content      = apply_filters('mycred_em_ticket_column', static::$core->format_creds($price), $EM_Ticket, $EM_Event, static::class);
	    
	    if ($content != '') {
	        echo '<td class="em-bookings-ticket-table-points">' . static::$core->format_creds($price) . '</td>';
	    }
	}

	public static function add_currency($currencies) {
	    
	    $currencies->names['XMY']        = static::$core->plural();
	    $currencies->symbols['XMY']      = '';
	    $currencies->true_symbols['XMY'] = '';

	    if (!empty(static::$core->before)) {
	        $currencies->symbols['XMY'] = static::$core->before;
	    } elseif (!empty(static::$core->after)) {
	        $currencies->symbols['XMY'] = static::$core->after;
	    }

	    if (!empty(static::$core->before)) {
	        $currencies->true_symbols['XMY'] = static::$core->before;
	    } elseif (!empty(static::$core->after)) {
	        $currencies->true_symbols['XMY'] = static::$core->after;
	    }

	    return $currencies;
	}

	public static function em_gateway_update() {
	    
	    if ( ! isset( $_POST['mycred_gateway'] ) || ! is_array( $_POST['mycred_gateway'] ) ) return;

	    $new_settings = array();
	    $format = '';

	    // Setup
	    $new_settings['setup'] = isset( $_POST['mycred_gateway']['setup'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['setup'] ) ) : '';
	    $new_settings['type'] = isset( $_POST['mycred_gateway']['type'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['type'] ) ) : '';
	    $new_settings['refund'] = isset( $_POST['mycred_gateway']['refund'] ) ? abs( sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['refund'] ) ) ) : '';
	    $new_settings['share'] = isset( $_POST['mycred_gateway']['share'] ) ? abs( sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['share'] ) ) ) : '';

	    // Logs
	    $new_settings['log']['purchase'] = isset( $_POST['mycred_gateway']['log']['purchase'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['log']['purchase'] ) ) : '';
	    $new_settings['log']['refund'] = isset( $_POST['mycred_gateway']['log']['refund'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['log']['refund'] ) ) : '';

	    if ( $new_settings['setup'] == 'multi' ) {
	        $new_settings['rate'] = isset( $_POST['mycred_gateway']['rate'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['rate'] ) ) : '';
	    } else {
	        $new_settings['rate'] = static::$prefs['rate'];
	    }

	    // Override Pricing Options
	    if ( $new_settings['setup'] == 'single' ) {
	        update_option( 'dbem_bookings_currency_decimal_point', static::$core->format['separators']['decimal'] );
	        update_option( 'dbem_bookings_currency_thousands_sep', static::$core->format['separators']['thousand'] );
	        update_option( 'dbem_bookings_currency', 'XMY' );

	        if ( empty( static::$core->before ) && ! empty( static::$core->after ) )
	            $format = '@ #';
	        elseif ( ! empty( static::$core->before ) && empty( static::$core->after ) )
	            $format = '# @';

	        update_option( 'dbem_bookings_currency_format', $format );
	    }

	    // Labels
	    $new_settings['labels']['link'] = isset( $_POST['mycred_gateway']['labels']['link'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['labels']['link'] ) ) : '';
	    $new_settings['labels']['header'] = isset( $_POST['mycred_gateway']['labels']['header'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['labels']['header'] ) ) : '';
	    $new_settings['labels']['button'] = isset( $_POST['mycred_gateway']['labels']['button'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['labels']['button'] ) ) : '';

	    // Messages
	    $new_settings['messages']['success'] = isset( $_POST['mycred_gateway']['messages']['success'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['messages']['success'] ) ) : '';
	    $new_settings['messages']['error'] = isset( $_POST['mycred_gateway']['messages']['error'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['messages']['error'] ) ) : '';
	    $new_settings['messages']['url'] = isset( $_POST['mycred_gateway']['messages']['url'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['messages']['url'] ) ) : '';
	    $new_settings['messages']['text'] = isset( $_POST['mycred_gateway']['messages']['text'] ) ? sanitize_text_field( wp_unslash( $_POST['mycred_gateway']['messages']['text'] ) ) : '';

	    // Save Settings
	    $current = static::$prefs;
	    static::$prefs = mycred_apply_defaults($current, $new_settings);
	    update_option('mycred_eventsmanager_gateway_prefs', static::$prefs);

	    // Let others play
	    do_action('mycred_em_save_settings', static::class);
	}

	public static function ticket_columns( $columns, $EM_Event ) {

			if ( ! $EM_Event->is_free() ) {

				$original_columns = $columns;

				unset( $columns['price'] );
				unset( $columns['type'] );
				unset( $columns['spaces'] );

				$columns['type']  = __( 'Ticket Type', 'mycred_em' );

				if ( self::points_as_currency() ) {
					$columns[ MYCRED_SLUG ] = __( 'Price', 'mycred_em' );
				}

				else {

					$columns['price']       = __( 'Price', 'mycred_em' );
					$columns[ MYCRED_SLUG ] = self::$core->plural();

				}

				$columns['spaces'] = __( 'Spaces', 'mycred_em' );

				$columns = apply_filters( 'mycred_em_ticket_columns', $columns, $original_columns, $EM_Event, static::class );


			}

			self::$booking_cols = count( $columns );

			return $columns;

	}

	public static function say_thanks() {

     echo '<div class="em-booking-message em-booking-message-success">' . esc_html( 'Booking Successful' ) . '</div>';

    }

    public static function single_currency() {
    
	    if ( static::$prefs['setup'] == 'single' ) return true;
	    return false;
	}

    public static function refunds( $result, $EM_Booking ) {

	    // Cancellation or Rejection refunds the payment
	    if ( in_array( $EM_Booking->booking_status, array( 2, 3 ) ) && in_array( $EM_Booking->previous_status, array( 0, 1 ) ) && static::$prefs['refund'] > 0 ) {

	        $user_id    = $EM_Booking->person_id;
	        $booking_id = $EM_Booking->booking_id;

	        // Make sure user has paid for this to refund
	        if ( static::has_paid( $booking_id, $user_id ) && ! static::has_been_refunded( $booking_id, $user_id ) ) {

	            // Get Cost
	            $cost   = $EM_Booking->get_price();

	            // Amount to refund
	            $refund = $EM_Booking->get_price();
	            if ( static::$prefs['refund'] != 100 )
	                $refund = floatval((static::$prefs['refund'] / 100) * $EM_Booking->get_price());

	            // Refund
	            static::$core->add_creds(
	                'ticket_purchase_refund',
	                $user_id,
	                $refund,
	                static::$prefs['log']['refund'],
	                $booking_id,
	                array( 'ref_type' => 'post' ),
	                static::$mycred_type
	            );

	            static::refund_profit_shares( $EM_Booking );
	        }
	    } else {

	        $user_id    = $EM_Booking->person_id;
	        $price      = $EM_Booking->get_price();  
	        $cost       = floatval( static::get_point_cost( $EM_Booking ) );
	        $booking_id = $EM_Booking->booking_id;

	         if ( static::$core->has_entry( 'ticket_purchase', $booking_id, $user_id) ) return;

	         if(static::uses_gateway( $EM_Booking )) {

		        static::$core->add_creds(
		            'ticket_purchase',
		            $user_id,
		            0 - $price,
		            static::$prefs['log']['purchase'],
		            $booking_id,
		            array( 'ref_type' => 'post' ),
		            static::$mycred_type
		        );

	        }
	    }

	    return $result;
	}

	public static function refund_profit_shares( $EM_Booking ) {
	    
	    if ( static::$prefs['share'] > 0 ) {
	        $booking_id = (int) $EM_Booking->booking_id;

	        foreach ( $EM_Booking->get_tickets_bookings()->tickets_bookings as $EM_Ticket_Booking ) {

	            // Get Event Post
	            $event_booking = $EM_Ticket_Booking->get_booking()->get_event();
	            $event_post    = get_post( (int) $event_booking->post_id );

	            // Make sure the event object exists
	            if ( $event_post !== NULL ) {

	                // Get share
	                $price = static::maybe_exchange( $EM_Ticket_Booking->get_price() );
	                $share = static::get_share( $price );

	                // Payout (refund)
	                static::$core->add_creds(
	                    'ticket_sale_refund',
	                    $event_post->post_author,
	                    0 - $share,
	                    static::$prefs['log']['payout_refund'],
	                    $event_post->ID,
	                    array( 'ref_type' => 'post', 'bid' => $booking_id ),
	                    static::$mycred_type
	                );
	            }
	        }
	    }

	    do_action( 'mycred_em_refund_profit_shares', $EM_Booking );
	}

	public static function get_share( $value = 0 ) {
	    
	    $share = $value;

	    if ( static::$prefs['share'] != 100 ) {
	        $share = ( static::$prefs['share'] / 100 ) * $value;
	    }

	    $share = static::$core->number( $share );

	    return apply_filters( 'mycred_em_get_share', $share, $value, static::$instance );
	}



	public static function has_been_refunded( $booking_id = 0, $user_id = 0 ) {
	    
	    $refunded = static::$core->has_entry(
	        'ticket_purchase_refund', 
	        $booking_id, 
	        $user_id, 
	        array( 'ref_type' => 'post' ), 
	        static::$mycred_type
	    );

	    // If multiple bookings are enabled, check for a refund in the previous booking ID as well
	    if ( ! $refunded && get_option( 'dbem_multiple_bookings' ) ) {
	        $refunded = static::$core->has_entry(
	            'ticket_purchase_refund', 
	            $booking_id - 1, 
	            $user_id, 
	            array( 'ref_type' => 'post' ), 
	            static::$mycred_type
	        );
	    }

	    return apply_filters( 'mycred_em_has_been_refunded', $refunded, $booking_id, $user_id );
	}


	public static function format_price( $formatted_price, $price, $currency, $format ) {
    
	    if ( $currency == 'XMY' ) {
	        return static::$core->format_creds( $price ); // Use static:: to reference static properties
	    }

	    return $formatted_price;
	}

	public static function is_live_mode ( $check_limited = true ) {
		return true;
	}
	
	public static function is_test_mode ( $check_limited = true ) {
		return false;
	}

	public static function em_gateway_settings_footer() {
	    
	    $mycred_types = mycred_get_types();

	    do_action('mycred_em_before_settings', static::class);

	    ?>

	    <hr />
	    <h3><?php _e('Setup', 'mycred_em'); ?></h3>
	    <p><?php printf(__('If you are unsure how to use this gateway, feel free to consult the %s.', 'mycred_em'), sprintf('<a href="http://codex.mycred.me/chapter-iii/gateway/events-manager/" target="_blank">%s</a>', __('online documentation', 'mycred_em'))); ?></p>
	    <table class="form-table">

	        <tr>
	            <th scope="row"><?php _e('Point Type', 'mycred_em'); ?></th>
	            <td>
	                <?php if (count($mycred_types) > 1) : ?>
	                    <?php mycred_types_select_from_dropdown('mycred_gateway[type]', 'mycred-gateway-type', static::$prefs['type']); ?>
	                <?php else : ?>
	                    <p><?php echo static::$core->plural(); ?></p>
	                    <input type="hidden" name="mycred_gateway[type]" value="<?php echo MYCRED_DEFAULT_TYPE_KEY; ?>" />
	                <?php endif; ?>
	                <p><span class="description"><?php _e('The point type to accept as payment.', 'mycred_em'); ?></span></p>
	            </td>
	        </tr>

	        <tr>
	            <th scope="row"><?php _e('Store Currency', 'mycred_em'); ?></th>
	            <td>
	                <label for="mycred-gateway-setup-single"><input type="radio" name="mycred_gateway[setup]" id="mycred-gateway-setup-single" value="single" <?php checked(static::$prefs['setup'], 'single'); ?> /> <?php _e('Bookings are paid using Points only.', 'mycred_em'); ?></label><br /><br />
	                <label for="mycred-gateway-setup-multi"><input type="radio" name="mycred_gateway[setup]" id="mycred-gateway-setup-multi" value="multi" <?php checked(static::$prefs['setup'], 'multi'); ?> /> <?php _e('Bookings are paid using Real Money or Points.', 'mycred_em'); ?></label>
	            </td>
	        </tr>

	        <tr>
	            <th scope="row"><?php _e('Refunds', 'mycred_em'); ?></th>
	            <td>
	                <input name="mycred_gateway[refund]" type="text" id="mycred-gateway-log-refund" value="<?php echo esc_attr(static::$prefs['refund']); ?>" size="5" /> %<br />
	                <p><span class="description"><?php _e('The percentage of the paid amount to refund if a user cancels their booking or if a booking is rejected. Use zero for no refunds.', 'mycred_em'); ?></span></p>
	            </td>
	        </tr>

	        <tr>
	            <th scope="row"><?php _e('Profit Sharing', 'mycred_em'); ?></th>
	            <td>
	                <input name="mycred_gateway[share]" type="text" id="mycred-gateway-profit-sharing" value="<?php echo esc_attr(static::$prefs['share']); ?>" size="5" /> %<br />
	                <p><span class="description"><?php _e('Option to share sales with the event owner. Use zero to disable.', 'mycred_em'); ?></span></p>
	            </td>
	        </tr>
	    </table>

	    <table class="form-table" id="mycred-exchange-rate" style="display: <?php echo (static::$prefs['setup'] == 'multi') ? 'block' : 'none'; ?>;">
	        <tr>
	            <th scope="row"><?php _e('Exchange Rate', 'mycred_em'); ?></th>
	            <td>
	                <input name="mycred_gateway[rate]" type="text" id="mycred-gateway-rate" size="6" value="<?php echo esc_attr(static::$prefs['rate']); ?>" /><br />
	                <p><span class="description"><?php printf(__('How many %s is needed to pay for 1 %s?', 'mycred_em'), static::$core->plural(), em_get_currency_symbol()); ?></span></p>
	            </td>
	        </tr>
	    </table>

	    <hr />
	    <h3><?php _e('Log Templates', 'mycred_em'); ?></h3>
	    <table class="form-table">
	        <tr>
	            <th scope="row"><?php _e('Booking Payments', 'mycred_em'); ?></th>
	            <td>
	                <input name="mycred_gateway[log][purchase]" type="text" id="mycred-gateway-log-purchase" style="width: 95%;" value="<?php echo esc_attr(static::$prefs['log']['purchase']); ?>" size="45" />
	                <p><span class="description"><?php echo static::$core->available_template_tags(array('general'), '%bookingid%'); ?></span></p>
	            </td>
	        </tr>
	        <tr>
	            <th scope="row"><?php _e('Payment Refunds', 'mycred_em'); ?></th>
	            <td>
	                <input name="mycred_gateway[log][refund]" type="text" id="mycred-gateway-log-refund" style="width: 95%;" value="<?php echo esc_attr(static::$prefs['log']['refund']); ?>" size="45" />
	                <p><span class="description"><?php echo static::$core->available_template_tags(array('general'), '%bookingid%'); ?></span></p>
	            </td>
	        </tr>
	        <tr>
	            <th scope="row"><?php _e('Profit Share Payouts', 'mycred_em'); ?></th>
	            <td>
	                <input name="mycred_gateway[log][payout]" type="text" id="mycred-gateway-log-payout-purchase" style="width: 95%;" value="<?php echo esc_attr(static::$prefs['log']['purchase']); ?>" size="45" />
	                <p><span class="description"><?php _e('Ignored if profit sharing is disabled.', 'mycred_em'); ?> <?php echo static::$core->available_template_tags(array('general', 'post')); ?></span></p>
	            </td>
	        </tr>
	        <tr>
	            <th scope="row"><?php _e('Profit Share Refunds', 'mycred_em'); ?></th>
	            <td>
	                <input name="mycred_gateway[log][payout_refund]" type="text" id="mycred-gateway-log-payout-refund" style="width: 95%;" value="<?php echo esc_attr(static::$prefs['log']['refund']); ?>" size="45" />
	                <p><span class="description"><?php _e('Ignored if profit sharing is disabled.', 'mycred_em'); ?> <?php echo static::$core->available_template_tags(array('general', 'post')); ?></span></p>
	            </td>
	        </tr>
	    </table>

	    <script type="text/javascript">
	        jQuery(function($){
	            $('input[name="mycred_gateway[setup]"]').change(function(){
	                if ($(this).val() == 'multi') {
	                    $('#mycred-exchange-rate').show();
	                } else {
	                    $('#mycred-exchange-rate').hide();
	                }
	            });
	        });
	    </script>

	    <hr />
	    <h3><?php _e('Labels', 'mycred_em'); ?></h3>
	    <table class="form-table">
	        <tr valign="top">
	            <th scope="row"><?php _e('Payment Link Label', 'mycred_em'); ?></th>
	            <td>
	                <input name="mycred_gateway[labels][link]" type="text" id="mycred-gateway-labels-link" style="width: 95%" value="<?php echo esc_attr(static::$prefs['labels']['link']); ?>" size="45" /><br />
	                <p><span class="description"><?php _e('The payment link shows / hides the payment form under "My Bookings". No HTML allowed.', 'mycred_em'); ?></span></p>
	            </td>
	        </tr>
	        <tr valign="top">
	            <th scope="row"><?php _e('Payment Header', 'mycred_em'); ?></th>
	            <td>
	                <input name="mycred_gateway[labels][header]" type="text" id="mycred-gateway-labels-header" style="width: 95%" value="<?php echo esc_attr(static::$prefs['labels']['header']); ?>" size="45" /><br />
	                <p><span class="description"><?php _e('Shown on top of the payment form. No HTML allowed.', 'mycred_em'); ?></span></p>
	            </td>
	        </tr>
	        <tr valign="top">
	            <th scope="row"><?php _e('Button Label', 'mycred_em'); ?></th>
	            <td>
	                <input name="mycred_gateway[labels][button]" type="text" id="mycred-gateway-labels-button" style="width: 95%" value="<?php echo esc_attr(static::$prefs['labels']['button']); ?>" size="45" /><br />
	                <p><span class="description"><?php _e('Text shown on the payment button. No HTML allowed.', 'mycred_em'); ?></span></p>
	            </td>
	        </tr>
	    </table>

	    <?php
	    do_action('mycred_em_after_settings', static::class);
	}

	public static function add_payment_form() {
		?>
		<div id="em-gateway-payment" class="stuffbox">
			<h3>
				<?php _e('Add myCred Payment', 'em-pro'); ?>
			</h3>
			<div class="inside">
				<div>
					<form method="post" action="" style="padding:5px;">
						<table class="form-table">
							<tbody>
							  <tr valign="top">
								  <th scope="row"><?php _e('Amount', 'em-pro') ?></th>
									  <td><input type="text" name="transaction_total_amount" value="<?php if(!empty($_REQUEST['transaction_total_amount'])) echo esc_attr($_REQUEST['transaction_total_amount']); ?>" />
									  <br />
									  <em><?php _e('Please enter a valid payment amount (e.g. 10.00). Use negative numbers to credit a booking.','em-pro'); ?></em>
								  </td>
							  </tr>
							  <tr valign="top">
								  <th scope="row"><?php _e('Comments', 'em-pro') ?></th>
								  <td>
										<textarea name="transaction_note"><?php if(!empty($_REQUEST['transaction_note'])) echo esc_attr($_REQUEST['transaction_note']); ?></textarea>
								  </td>
							  </tr>
							</tbody>
						</table>
						<input type="hidden" name="action" value="gateway_add_payment" />
						<input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce('gateway_add_payment'); ?>" />
						<input type="hidden" name="redirect_to" value="<?php echo (!empty($_REQUEST['redirect_to'])) ? $_REQUEST['redirect_to']:em_wp_get_referer(); ?>" />
						<input type="submit" class="<?php if( is_admin() ) echo 'button-primary'; ?>" value="<?php _e('Add myCred Payment', 'em-pro'); ?>" />
					</form>
				</div>					
			</div>

			
		</div> 
		<?php
	}

	public static function em_booking_set_status($result, $EM_Booking){
		if($EM_Booking->booking_status == 1 && $EM_Booking->previous_status == static::$status && static::uses_gateway($EM_Booking) && (empty($_REQUEST['action']) || !in_array($_REQUEST['action'], array('gateway_add_payment')) ) ){
			static::record_transaction($EM_Booking, $EM_Booking->get_price(false,false,true), get_option('dbem_bookings_currency'), current_time('mysql'), '', 'Completed', '');
		}
		return $result;
	}

	public static function booking_add($EM_Event, $EM_Booking, $post_validation = true) {
		
		global $wpdb, $wp_rewrite, $EM_Notices;

		// Register current timestamp for the booking
		static::$registered_timer = current_time('timestamp', 1);

		// Call the parent static booking_add method
		parent::booking_add($EM_Event, $EM_Booking, $post_validation);

		// Check if post validation passed and the booking ID is empty
		if ($post_validation && empty($EM_Booking->booking_id)) {

			// If multiple bookings are enabled
			if (get_option('dbem_multiple_bookings') && get_class($EM_Booking) == 'EM_Multiple_Booking' && static::uses_gateway( $EM_Booking )) {

				// Retrieve user ID, price, and point cost
				$user_id    = $EM_Booking->person_id;
				$price      = $EM_Booking->get_price();  
				$cost       = static::get_point_cost($EM_Booking);
				$booking_id = $EM_Booking->booking_id;

				// Deduct points using myCred
				static::$core->add_creds(
					'ticket_purchase',
					$user_id,
					0 - $cost,
					static::$prefs['log']['purchase'],
					$booking_id,
					array('ref_type' => 'post'),
					static::$mycred_type
				);

				// Add filter to save multiple bookings
				add_filter('em_multiple_booking_save', array(static::class, 'em_booking_save'), 1000, 2);
			} else {
				
				// Add filter to save single booking
				add_filter('em_booking_save', array(static::class, 'em_booking_save'), 1000, 2);
			}
		}
	}

	public static function em_booking_save( $result, $EM_Booking ) {

	    global $wpdb, $wp_rewrite, $EM_Notices;

	    // Make sure booking save was successful before we try anything
	    if ( $result ) {

	        if ( $EM_Booking->get_price() > 0 ) {

	            // Authorize & Capture point payment
	            $captured = self::authorize_and_capture( $EM_Booking );

	            // Payment Successful
	            if ( $captured ) {

	                // Set booking status, but no emails sent
	                if ( ! get_option( 'em_' . static::$gateway . '_manual_approval', false ) || ! get_option( 'dbem_bookings_approval' ) ) {
	                    $EM_Booking->set_status( 1, false ); // Approve
	                } else {
	                    $EM_Booking->set_status( 0, false ); // Set back to normal "pending"
	                }

	            } else {
	                // Authorization declined. Either because: 
	                // 1. User not logged in 
	                // 2. User is excluded from point type 
	                // 3. Insufficient funds

	                // Not good... error inserted into booking in capture function. Delete this booking from DB
	                if ( ! is_user_logged_in() && get_option( 'dbem_bookings_anonymous' ) && ! get_option( 'dbem_bookings_registration_disable' ) && ! empty( $EM_Booking->person_id ) ) {

	                    // Delete the user we just created, only if created after em_booking_add filter is called (when a new user for this booking would be created)
	                    $EM_Person = $EM_Booking->get_person();
	                    if ( strtotime( $EM_Person->data->user_registered ) >= static::$registered_timer ) {

	                        if ( is_multisite() ) {
	                            include_once ABSPATH . '/wp-admin/includes/ms.php';
	                            wpmu_delete_user( $EM_Person->ID );
	                        } else {
	                            include_once ABSPATH . '/wp-admin/includes/user.php';
	                            wp_delete_user( $EM_Person->ID );
	                        }

	                        // Remove email confirmation
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


	public static function authorize_and_capture( $EM_Booking ) {
	    
	    $user_id    = $EM_Booking->person_id;
	    $booking_id = $EM_Booking->booking_id;
	    $captured   = false;

	    // Make sure user is not excluded from the set point type
	    if ( self::$core->exclude_user( $user_id ) ) {
	        $EM_Booking->add_error( self::$core->template_tags_general( self::$prefs['messages']['excluded'] ) );
	    }
	    // User cannot afford to pay
	    elseif ( ! self::can_pay( $EM_Booking ) ) {
	        $EM_Booking->add_error( self::$core->template_tags_general( self::$prefs['messages']['error'] ) );
	    }
	    // User has not yet paid (preferred)
	    elseif ( ! self::has_paid( $booking_id, $user_id ) ) {
	        // Get Cost
	        $price = $EM_Booking->get_price();
	        $cost  = floatval( self::get_point_cost( $EM_Booking ) );

	         if ( static::$core->has_entry( 'ticket_purchase', $booking_id, $user_id) ) return;

	         if(static::uses_gateway( $EM_Booking )) {

	        // Charge
	        $captured = self::$core->add_creds(
	            'ticket_purchase',
	            $user_id,
	            0 - $cost,
	            self::$prefs['log']['purchase'],
	            $booking_id,
	            array( 'ref_type' => 'post' ),
	            self::$mycred_type
	        );
	    }

	        // Points were successfully taken from the user's balance
	        if ( $captured ) {
	            // Log transaction with EM
	            $transaction_id = time() . $user_id;
	            $currency       = get_option( 'dbem_bookings_currency' );
	            $amount_paid    = $price;
	            if ( self::points_as_currency() ) {
	                $currency    = '';
	                $amount_paid = $cost;
	            }

	            $EM_Booking->booking_meta[ self::$gateway ] = array( 'txn_id' => $transaction_id, 'amount' => $amount_paid );
	            self::record_transaction( $EM_Booking, $amount_paid, $currency, date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), $transaction_id, 'Completed', '' );

	            // Profit share (if enabled)
	            self::pay_profit_share( $EM_Booking );
	        }
	        // Something declined the transaction. User's balance has not changed!
	        else {
	            $message = apply_filters( 'mycred_em_charge_failed', __( 'Payment declined. Please try a different payment option.', 'mycred_em' ), $EM_Booking, self::$instance );
	            $EM_Booking->add_error( $message );
	        }
	    }
	    // A payment was found for this booking
	    else {
	        $EM_Booking->add_error( sprintf( __( 'Duplicate transaction for booking ID: %s', 'mycred_em' ), $booking_id ) );
	    }

	    return apply_filters( 'mycred_em_authorize_and_capture', $captured, $EM_Booking, self::$instance );
	}

	public static function has_paid( $booking_id = 0, $user_id = 0 ) {
    
	    // Access the static instance of core
	    $core = static::$core;

	    // Check if the user has paid for the booking
	    $paid = $core->has_entry( 'ticket_purchase', $booking_id, $user_id, array( 'ref_type' => 'post' ), static::$mycred_type );

	    // If not paid and multiple bookings are enabled, check the previous booking
	    if ( ! $paid && get_option( 'dbem_multiple_bookings' ) ) {
	        $paid = $core->has_entry( 'ticket_purchase', $booking_id - 1, $user_id, array( 'ref_type' => 'post' ), static::$mycred_type );
	    }

	    return apply_filters( 'mycred_em_has_paid', $paid, $booking_id, $user_id, static::class );
	}

	public static function pay_profit_share( $EM_Booking ) {
	    
	    // Access the static preferences and core instance
	    if ( static::$prefs['share'] > 0 ) {
	        $booking_id = (int) $EM_Booking->booking_id;
	        
	        foreach ( $EM_Booking->get_tickets_bookings()->tickets_bookings as $EM_Ticket_Booking ) {
	            // Get Event Post
	            $event_booking = $EM_Ticket_Booking->get_booking()->get_event();
	            $event_post    = get_post( (int) $event_booking->post_id );

	            // Ensure the event object exists
	            if ( $event_post !== NULL ) {
	                // Get share
	                $price = static::maybe_exchange( $EM_Ticket_Booking->get_price() ); // Assuming maybe_exchange is also static
	                $share = static::get_share( $price ); // Assuming get_share is also static

	                  if ( static::core->has_entry( 'ticket_sale', $event_post->ID, $event_post->post_author) ) return;


	                // Payout
	                static::$core->add_creds(
	                    'ticket_sale',
	                    $event_post->post_author,
	                    $share,
	                    static::$prefs['log']['payout'],
	                    $event_post->ID,
	                    array( 'ref_type' => 'post', 'bid' => $booking_id ),
	                    static::$mycred_type
	                );
	            }
	        }
	    }

	    do_action( 'mycred_em_pay_profit_share', $EM_Booking, static::class );
	}


	public static function can_pay( $EM_Booking ) {
	    
	    $solvent = false;
	    $balance = self::$core->get_users_balance( $EM_Booking->person_id, self::$mycred_type );
	    $cost    = self::get_point_cost( $EM_Booking );

	    if ( $cost == 0 || $balance >= $cost ) {
	        $solvent = true;
	    }

	    return apply_filters( 'mycred_em_can_pay', $solvent, $EM_Booking, self::$instance );
	}


	public static function get_point_cost( $EM_Booking ) {

		$price = 0;

		// Loop through ticket bookings and sum up the prices
		foreach ( $EM_Booking->get_tickets_bookings()->tickets_bookings as $EM_Ticket_Booking ) {
			$price += floatval( $EM_Ticket_Booking->get_price( true ) );
		}

		// Calculate discounts, if any
		$discount = floatval( $EM_Booking->get_price_discounts_amount('pre') ) + floatval( $EM_Booking->get_price_discounts_amount('post') );
		if ( $discount > 0 ) {
			$price -= $discount;
		}

		// Assuming maybe_exchange can be static too, if needed, change it to static::maybe_exchange
		$cost = static::maybe_exchange( $price );

		// Apply filter for point cost
		return apply_filters( 'mycred_em_get_point_cost', $cost, $EM_Booking, static::class );
	}

	public static function maybe_exchange( $value = 0 ) {
    
	    $exchanged = $value; // Start with the original value

	    $instance = new \EM\Payments\Mycred\Checkout\Gateway();

	    // Check if points should be treated as currency
	    if ( $instance->points_as_currency() ) { // Change $this to self
	        // Convert points to currency using the defined rate
	        $exchanged = mycred()->number( self::$prefs['rate'] * $value ); // Change $this to self
	    }

	    return $exchanged; // Return the converted value
	}

	public static function points_as_currency() {
    
	    $points_currency = false;
	    
	    // Check if the setup is 'single'
	    if ( self::$prefs['setup'] == 'single' ) { // Use self for static property
	        $points_currency = true;
	    }

	    return apply_filters( 'mycred_em_points_as_currency', $points_currency, static::class ); // Use static::class
	}



	
	
}
Gateway::init();