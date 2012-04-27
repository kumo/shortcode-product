<?php
/*
Plugin Name: WooCommerce Product Shortcode
Plugin URI: http://woothemes.com/woocommerce
Description: Extends WooCommerce with a shortcode for viewing a customer's bought product (thanks http://chromeorange.co.uk/adding-an-additional-checkbox-to-the-woocommerce-checkout/)
Version: 1.0
Author: Rob Clarke
Author URI: 

  Copyright: © 2012 Rob Clarke
  License: GNU General Public License v3.0
  License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

function my_scripts_method() {
	wp_enqueue_script('sublimevideo', 'http://cdn.sublimevideo.net/js/d1yraopd.js');
}

function view_product() {

	woocommerce_admin_fields( array(
		array(
			'name' 		=> __( 'View Product Page', 'woocommerce' ),
			'desc' 		=> __( 'If you define a \'Product\' page the customer can view their downloadable files.', 'woocommerce' ),
			'tip' 		=> '',
			'id' 		=> 'woocommerce_view_product_page_id',
			'std' 		=> '',
			'class'		=> 'chosen_select_nostd',
			'css' 		=> 'min-width:300px;',
			'type' 		=> 'single_select_page',
			'desc_tip'	=>  true,
		),

	));

}

function save_view_product() {

	if ( isset($_POST['woocommerce_view_product_page_id']) ) :
          update_option( 'woocommerce_view_product_page_id', woocommerce_clean( $_POST['woocommerce_view_product_page_id']) );
	else :
		delete_option('woocommerce_view_product_page_id');
	endif;

}
function get_woocommerce_view_product( $atts ) {
	global $woocommerce;
	return $woocommerce->shortcode_wrapper('woocommerce_view_product', $atts); 
}


function get_woocommerce_download_product_url( $atts ) {
	
  if ( isset($atts['file']) && isset($atts['order']) && isset($atts['email']) ) :
	
		global $wpdb;
		
		$download_file = (int) urldecode($atts['file']);
		$order_key = urldecode( $atts['order'] );
		$email = urldecode( $atts['email'] );
		
		if (!is_email($email)) :
			wp_die( __('Invalid email address.', 'woocommerce') . ' <a href="'.home_url().'">' . __('Go to homepage &rarr;', 'woocommerce') . '</a>' );
		endif;
		
		$download_result = $wpdb->get_row( $wpdb->prepare("
			SELECT order_id, downloads_remaining,user_id,download_count,access_expires
			FROM ".$wpdb->prefix."woocommerce_downloadable_product_permissions
			WHERE user_email = %s
			AND order_key = %s
			AND product_id = %s
		;", $email, $order_key, $download_file ) );
		
		if (!$download_result) :
			wp_die( __('Invalid download.', 'woocommerce') . ' <a href="'.home_url().'">' . __('Go to homepage &rarr;', 'woocommerce') . '</a>' );
			exit;
		endif;
		
		$order_id = $download_result->order_id;
		$downloads_remaining = $download_result->downloads_remaining;
		$download_count = $download_result->download_count;
		$user_id = $download_result->user_id;
		$access_expires = $download_result->access_expires;
				
		if ($user_id && get_option('woocommerce_downloads_require_login')=='yes'):
			if (!is_user_logged_in()):
				wp_die( __('You must be logged in to download files.', 'woocommerce') . ' <a href="'.wp_login_url(get_permalink(woocommerce_get_page_id('myaccount'))).'">' . __('Login &rarr;', 'woocommerce') . '</a>' );
				exit;
			else:
				$current_user = wp_get_current_user();
				if($user_id != $current_user->ID):
					wp_die( __('This is not your download link.', 'woocommerce'));
					exit;
				endif;
			endif;
		endif;
		
		if ($order_id) :
			$order = new WC_Order( $order_id );
			if ($order->status!='completed' && $order->status!='processing' && $order->status!='publish') :
				wp_die( __('Invalid order.', 'woocommerce') . ' <a href="'.home_url().'">' . __('Go to homepage &rarr;', 'woocommerce') . '</a>' );
				exit;
			endif;
		endif;
				
		if ($downloads_remaining=='0') :
		
			wp_die( __('Sorry, you have reached your download limit for this file', 'woocommerce') . ' <a href="'.home_url().'">' . __('Go to homepage &rarr;', 'woocommerce') . '</a>' );
			exit;
			
		endif;
		
		if ($access_expires > 0 && strtotime($access_expires) < current_time('timestamp')) :
		
			wp_die( __('Sorry, this download has expired', 'woocommerce') . ' <a href="'.home_url().'">' . __('Go to homepage &rarr;', 'woocommerce') . '</a>' );
			exit;
			
		endif;
			
		if ($downloads_remaining>0) :
		
			$wpdb->update( $wpdb->prefix . "woocommerce_downloadable_product_permissions", array( 
				'downloads_remaining' => $downloads_remaining - 1, 
			), array( 
				'user_email' => $email,
				'order_key' => $order_key,
				'product_id' => $download_file 
			), array( '%d' ), array( '%s', '%s', '%d' ) );
			
		endif;
		
		// Count the download
		$wpdb->update( $wpdb->prefix . "woocommerce_downloadable_product_permissions", array( 
			'download_count' => $download_count + 1, 
		), array( 
			'user_email' => $email,
			'order_key' => $order_key,
			'product_id' => $download_file 
		), array( '%d' ), array( '%s', '%s', '%d' ) );
		
		// Get the downloads URL and try to replace the url with a path
		$file_path = apply_filters('woocommerce_file_download_path', get_post_meta($download_file, '_file_path', true), $download_file);	
		
		if (!$file_path) exit;
		
		$file_download_method = apply_filters('woocommerce_file_download_method', get_option('woocommerce_file_download_method'), $download_file);
		
		if ($file_download_method=='redirect') :
		  echo $file_path;
		  return;
		endif;
		
		// Get URLS with https
		$site_url = site_url();
		$network_url = network_admin_url();
		if (is_ssl()) :
			$site_url = str_replace('https:', 'http:', $site_url);
			$network_url = str_replace('https:', 'http:', $network_url);
		endif;
		
		if (!is_multisite()) :	
			$file_path = str_replace(trailingslashit($site_url), ABSPATH, $file_path);
		else :
			$upload_dir = wp_upload_dir();
			
			// Try to replace network url
			$file_path = str_replace(trailingslashit($network_url), ABSPATH, $file_path);
			
			// Now try to replace upload URL
			$file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_path);
		endif;
		
		// See if its local or remote
		if (strstr($file_path, 'http:') || strstr($file_path, 'https:') || strstr($file_path, 'ftp:')) :
			$remote_file = true;
		else :
			$remote_file = false;
			$file_path = realpath($file_path);
		endif;
		
		echo $file_path;
		
	endif;
}

/**
 * My Account Shortcode.
 *
 * @package WooCommerce
 * @since 1.4
 */
function woocommerce_view_product( $atts ) {
	global $woocommerce, $current_user;
	
	$woocommerce->nocache();

	if ( ! is_user_logged_in() ) :
		
		woocommerce_get_template( 'myaccount/form-login.php' );
	
	else :

	$user_id      	= get_current_user_id();
	$order_key		= ( isset( $_GET['order'] ) ) ? $_GET['order'] : 0;
	$product_id  =  ( isset( $_GET['file'] ) ) ? $_GET['file'] : 0;

		get_currentuserinfo();
		
		$download_url = add_query_arg('download_file', $product_id, add_query_arg('order', $order_key, add_query_arg('email', $_GET['email'], home_url())))
		
    
?>
<h2><?php _e('Product Details', 'woocommerce'); ?></h2>

<video class="sublime" width="480" height="352" data-uid="e7e5370e" preload="none">
  <source src="<?php echo esc_url( get_woocommerce_download_product_url($_GET) ); ?>" />
</video>
		
		<?php
		/*woocommerce_get_template( 'my-video.php', array(
			'current_user' 	=> $current_user,
			'order' 	=> $order_id
		), dirname( plugin_basename( __FILE__ ) )  );*/

	endif;
		
}

add_action( 'woocommerce_settings_page_options', 'view_product' );
add_action( 'woocommerce_update_options_pages', 'save_view_product' );
add_action('wp_enqueue_scripts', 'my_scripts_method');

add_shortcode('woocommerce_view_product', 'get_woocommerce_view_product');

?>