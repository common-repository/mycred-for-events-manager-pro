<?php
namespace EMP_MYCRED;

/* Thanks Mark Jaquith - https://markjaquith.wordpress.com/2018/02/19/handling-old-wordpress-and-php-versions-in-your-plugin/ */
class Requirements_Check {
	private $title;
	private $php;
	private $wp;
	private $file;
	
	public function __construct( $title = '', $file = '', $php = '5.3', $wp = '4.5' ) {
		$this->title = $title;
		$this->php = $php;
		$this->wp = $wp;
		$this->file = $file;
			
	}
	
	public function passes( $deactivate = true ) {
		$passes = $this->php_passes() && $this->wp_passes();
		if ( ! $passes && $deactivate ) {
			add_action( 'admin_notices', array( $this, 'deactivate' ) );
		}
		return $passes;
	}
	
	public function deactivate() {
		if ( isset( $this->file ) ) {
			deactivate_plugins( plugin_basename( $this->file ) );
		}
	}
	
	private function php_passes() {
		if ( $this->__php_at_least( $this->php ) ) {
			return true;
		} else {
			add_action( 'admin_notices', array( $this, 'php_version_notice' ) );
			return false;
		}
	}
	
	private static function __php_at_least( $min_version ) {
		return version_compare( phpversion(), $min_version, '>=' );
	}
	
	public function php_version_notice() {
		echo '<div class="error">';
		echo "<p>The &#8220;" . esc_html( $this->title ) . "&#8221; plugin cannot run on PHP versions older than " . $this->php . '. Please contact your host and ask them to upgrade.</p>';
		echo '</div>';
	}
	
	private function wp_passes() {
		if ( $this->__wp_at_least( $this->wp ) ) {
			return true;
		} else {
			add_action( 'admin_notices', array( $this, 'wp_version_notice' ) );
			return false;
		}
	}
	
	private static function __wp_at_least( $min_version ) {
		return version_compare( get_bloginfo( 'version' ), $min_version, '>=' );
	}
	
	public function wp_version_notice() {
		echo '<div class="error">';
		echo "<p>The &#8220;" . esc_html( $this->title ) . "&#8221; plugin cannot run on WordPress versions older than " . $this->wp . '. Please update WordPress.</p>';
		echo '</div>';
	}
}