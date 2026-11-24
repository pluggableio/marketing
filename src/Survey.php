<?php
namespace Pluggable\Marketing;

/**
 * if accessed directly, exit.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @package Marketing
 * 
 * @subpackage Survey
 * 
 * @author Pluggable <hi@pluggable.io>
 */
class Survey {
	
	public $args;

	public $plugin;

	public $plugin_file;
	
	public $activated_key;

	public $server;

	public $hash_survey;
	
	public function __construct( $plugin, $args = [] ) {

		$this->plugin	= wp_parse_args( $plugin, [
			'server'		=> 'https://my.pluggable.io',
			'hash_survey'	=> 'a7719b8f-a43b-4c1d-aeb3-2823ef174f54'
		] );

		$this->plugin_file		= $this->plugin['file'];
		$this->server			= $this->plugin['server'];
		$this->hash_survey		= $this->plugin['hash_survey'];
		$this->activated_key	= "pl-survey_{$this->plugin['TextDomain']}-activated";

		$this->args = wp_parse_args( $args, [
			'text'		=> sprintf( __( 'Thanks for using <strong>%1$s</strong>!<br />Help us understand how the plugin is used on different sites. If you agree, the details listed below are sent to our servers. Nothing is sent unless you tick the box and click the send button.', 'pluggable' ), $this->plugin['Name'] ),
			/* translators: %s: host the details are sent to */
			'consent'	=> sprintf( __( 'Yes, send my email address, first and last name, the plugin name and my site URL to %s so you can contact me about this plugin.', 'pluggable' ), wp_parse_url( $this->server, PHP_URL_HOST ) ),
			'remind'	=> __( 'Remind me later', 'pluggable' ),
			'button'	=> __( 'Send my details', 'pluggable' ),
			'decline'	=> __( 'No thanks', 'pluggable' ),
			'delay'		=> 2 * DAY_IN_SECONDS,
		] );

		$this->hooks();
	}

	public function hooks() {
		register_activation_hook( $this->plugin_file, [ $this, 'activate' ] );
		register_deactivation_hook( $this->plugin_file, [ $this, 'deactivate' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
		add_action( 'admin_footer', [ $this, 'admin_footer' ] );
		add_action( "wp_ajax_{$this->plugin['TextDomain']}_survey", [ $this, 'ajax' ] );
	}

	public function activate() {
		update_option( $this->activated_key, date_i18n( 'U' ) );
	}

	public function deactivate() {
		delete_option( $this->activated_key );
	}

	public function admin_notices() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if( date_i18n( 'U' ) <= get_option( $this->activated_key ) + $this->args['delay'] ) return;

		printf(
			'<div id="%1$s-survey-notice" class="notice notice-success is-dismissible pl-survey pl-notice pl-shadow" data-slug="%1$s" data-nonce="%5$s">
				<p>%2$s</p>
				<p><label><input type="checkbox" class="pl-survey-consent" value="1" /> %6$s</label></p>
				<p>
					<button class="button button-primary pl-survey-btn" data-participate="1" disabled="disabled">%3$s</button>
					<button class="button pl-survey-btn" data-participate="0">%4$s</button>
					<button class="button pl-survey-btn" data-participate="-1">%7$s</button>
				</p>
			</div>',
			esc_attr( $this->plugin['TextDomain'] ),
			wp_kses( $this->args['text'], [ 'strong' => [], 'br' => [], 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ),
			esc_html( $this->args['button'] ),
			esc_html( $this->args['remind'] ),
			esc_attr( wp_create_nonce( 'pl-survey' ) ),
			esc_html( $this->args['consent'] ),
			esc_html( $this->args['decline'] )
		);
	}

	public function admin_footer() {

		if( did_action( 'pl-survey_footer-loaded' ) ) return;
		do_action( 'pl-survey_footer-loaded' );
		
		?>
		<script type="text/javascript">
			jQuery(function($){
			    var send = function( $notice, participate, consent ) {
			        var $slug = $notice.data('slug')
			        var $nonce = $notice.data('nonce')
			        $.ajax({
			            url: ajaxurl,
			            data: { 'action' : $slug + '_survey', participate : participate, consent : consent, _wpnonce : $nonce },
			            type: 'POST',
			            success: function(ret) {
			                $('#'+$slug+'-survey-notice').slideUp(500)
			            },
			            error: function() {
			                $notice.find('.pl-survey-btn, .pl-survey-consent').prop('disabled', false)
			                $notice.find('.pl-survey-consent').trigger('change')
			            }
			        })
			    }

			    // The agree button only works while the consent box is ticked.
			    $(document).on('change', '.pl-survey .pl-survey-consent', function(){
			        $(this).closest('.pl-survey').find('.pl-survey-btn[data-participate="1"]').prop('disabled', ! this.checked)
			    })

			    $(document).on('click', '.pl-survey .pl-survey-btn', function(e){
			        var $notice = $(this).closest('.pl-survey')
			        var participate = String( $(this).data('participate') )
			        var consent = $notice.find('.pl-survey-consent').is(':checked') ? 1 : 0
			        if ( '1' === participate && ! consent ) return
			        $notice.find('.pl-survey-btn, .pl-survey-consent').prop('disabled', true)
			        send( $notice, participate, '1' === participate ? 1 : 0 )
			    })

			    // Closing the notice with the X is a dismissal, never an agreement.
			    $(document).on('click', '.pl-survey .notice-dismiss', function(e){
			        send( $(this).closest('.pl-survey'), '-1', 0 )
			    })
			})
		</script>
		<?php
	}

	public function ajax() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'pluggable' ) ], 403 );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pl-survey' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'pluggable' ) ], 403 );
		}

		$participate = isset( $_POST['participate'] ) ? sanitize_text_field( wp_unslash( $_POST['participate'] ) ) : '';
		$consent     = isset( $_POST['consent'] ) ? sanitize_text_field( wp_unslash( $_POST['consent'] ) ) : '';

		if ( '1' === $participate && '1' === $consent ) { // agreed
			$user = wp_get_current_user();

			if ( '' !== $this->hash_survey ) {
				wp_remote_post(
					"{$this->server}/?fluentcrm=1&route=contact&hash={$this->hash_survey}",
					array(
						'body' => array(
							'first_name'    => $user->first_name,
							'last_name'     => $user->last_name,
							'email'     	=> $user->user_email,
							'plugin'     	=> $this->plugin['TextDomain'],
							'site_url'     	=> site_url(),
						),
					)
				);
			}

			update_option( $this->activated_key, date_i18n( 'U' ) + YEAR_IN_SECONDS );
		} elseif ( '0' === $participate ) { // remind later
			update_option( $this->activated_key, date_i18n( 'U' ) + WEEK_IN_SECONDS );
		} else { // declined, dismissed or anything unexpected: send nothing
			update_option( $this->activated_key, date_i18n( 'U' ) + YEAR_IN_SECONDS );
		}

		wp_send_json_success();
	}
}
