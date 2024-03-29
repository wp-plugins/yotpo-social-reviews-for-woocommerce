<?php
/*
	Plugin Name: Yotpo Social Reviews for Woocommerce
	Description: Yotpo Social Reviews helps Woocommerce store owners generate a ton of reviews for their products. Yotpo is the only solution which makes it easy to share your reviews automatically to your social networks to gain a boost in traffic and an increase in sales.
	Author: Yotpo
	Version: 1.1.4
	Author URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=woocommerce_plugin_page_link	
	Plugin URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=woocommerce_plugin_page_link
 */
register_activation_hook(   __FILE__, 'wc_yotpo_activation' );
register_uninstall_hook( __FILE__, 'wc_yotpo_uninstall' );
register_deactivation_hook( __FILE__, 'wc_yotpo_deactivate' );
add_action('plugins_loaded', 'wc_yotpo_init');
add_action('init', 'wc_yotpo_redirect');
add_action( 'woocommerce_order_status_completed', 'wc_yotpo_map');
		
function wc_yotpo_init() {
	$is_admin = is_admin();	
	if($is_admin) {
		if (isset($_GET['download_exported_reviews'])) {
			if(current_user_can('manage_options')) {
				require('classes/class-wc-yotpo-export-reviews.php');	
				$export = new Yotpo_Review_Export();
				list($file, $errors) = $export->exportReviews();	
				if(is_null($errors)) {
					$export->downloadReviewToBrowser($file);	
				}
			}
			exit;
		}		
		include( plugin_dir_path( __FILE__ ) . 'templates/wc-yotpo-settings.php');
		include(plugin_dir_path( __FILE__ ) . 'lib/yotpo-api/Yotpo.php');
		add_action( 'admin_menu', 'wc_yotpo_admin_settings' );
	}
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	if(!empty($yotpo_settings['app_key']) && wc_yotpo_compatible()) {			
		if(!$is_admin) {
			add_action( 'wp_enqueue_scripts', 'wc_yotpo_load_js' );
			add_action( 'template_redirect', 'wc_yotpo_front_end_init' );	
		}								
	}			
}

function wc_yotpo_redirect() {
	if ( get_option('wc_yotpo_just_installed', false)) {
		delete_option('wc_yotpo_just_installed');
		wp_redirect( ( ( is_ssl() || force_ssl_admin() || force_ssl_login() ) ? str_replace( 'http:', 'https:', admin_url( 'admin.php?page=woocommerce-yotpo-settings-page' ) ) : str_replace( 'https:', 'http:', admin_url( 'admin.php?page=woocommerce-yotpo-settings-page' ) ) ) );
		exit;
	}	
}

function wc_yotpo_admin_settings() {
	add_action( 'admin_enqueue_scripts', 'wc_yotpo_admin_styles' );	
	$page = add_menu_page( 'Yotpo', 'Yotpo', 'manage_options', 'woocommerce-yotpo-settings-page', 'wc_display_yotpo_admin_page', 'none', null );			
}

function wc_yotpo_front_end_init() {
	$settings = get_option('yotpo_settings',wc_yotpo_get_degault_settings());
	add_action('woocommerce_thankyou', 'wc_yotpo_conversion_track');		
	if(is_product()) {
		
		$widget_location = $settings['widget_location'];	
		if($settings['disable_native_review_system']) {
			add_filter( 'comments_open', 'wc_yotpo_remove_native_review_system', null, 2);	
		}						
		if($widget_location == 'footer') {		
			add_action('woocommerce_after_single_product', 'wc_yotpo_show_widget', 10);
		}
		elseif($widget_location == 'tab') {
			add_action('woocommerce_product_tabs', 'wc_yotpo_show_widget_in_tab');		
		}
		if($settings['bottom_line_enabled_product']) {	
			add_action('woocommerce_single_product_summary', 'wc_yotpo_show_buttomline',7);	
			wp_enqueue_style('yotpoSideBootomLineStylesheet', plugins_url('assets/css/bottom-line.css', __FILE__));
		}			
	}
	elseif ($settings['bottom_line_enabled_category']) {
		add_action('woocommerce_after_shop_loop_item_title', 'wc_yotpo_show_buttomline',7);
		wp_enqueue_style('yotpoSideBootomLineStylesheet', plugins_url('assets/css/bottom-line.css', __FILE__));
	}							
}

function wc_yotpo_activation() {
	if(current_user_can( 'activate_plugins' )) {
		update_option('wc_yotpo_just_installed', true);
	    $plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
    	check_admin_referer( "activate-plugin_{$plugin}" );
		$default_settings = get_option('yotpo_settings', false);
		if(!is_array($default_settings)) {
			add_option('yotpo_settings', wc_yotpo_get_degault_settings());
		}
		update_option('native_star_ratings_enabled', get_option('woocommerce_enable_review_rating'));
		update_option('woocommerce_enable_review_rating', 'no');			
	}        
}

function wc_yotpo_uninstall() {
	if(current_user_can( 'activate_plugins' ) && __FILE__ == WP_UNINSTALL_PLUGIN ) {
		check_admin_referer( 'bulk-plugins' );
		delete_option('yotpo_settings');	
	}	
}

function wc_yotpo_show_widget() {		 
	$product = get_product();
	if($product->post->comment_status == 'open') {		
		$product_data = wc_yotpo_get_product_data($product);	
		$yotpo_div = "<div class='yotpo yotpo-main-widget'
	   				data-product-id='".$product_data['id']."'
	   				data-name='".$product_data['title']."' 
	   				data-url='".$product_data['url']."' 
	   				data-image-url='".$product_data['image-url']."' 
	  				data-description='".$product_data['description']."' 
	  				data-lang='".$product_data['lang']."'></div>";
		echo $yotpo_div;
	}						
}

function wc_yotpo_show_widget_in_tab($tabs) {
	$product = get_product();
	if($product->post->comment_status == 'open') {
		$settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	 	$tabs['yotpo_widget'] = array(
	 	'title' => $settings['widget_tab_name'],
	 	'priority' => 50,
	 	'callback' => 'wc_yotpo_show_widget'
	 	);
	}
	return $tabs;		
}

function wc_yotpo_load_js(){
	if(wc_yotpo_is_who_commerce_installed()) {		
    	wp_enqueue_script('yquery', plugins_url('assets/js/headerScript.js', __FILE__) ,null,null);
		$settings = get_option('yotpo_settings',wc_yotpo_get_degault_settings());
		wp_localize_script('yquery', 'yotpo_settings', array('app_key' => $settings['app_key']));    	    	
	}
}

function wc_yotpo_is_who_commerce_installed() {
	return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
}

function wc_yotpo_show_qa_bottomline() {
    $product_data = wc_yotpo_get_product_data(get_product());
    echo "<div class='yotpo QABottomLine'
         data-appkey='".$product_data['app_key']."'
         data-product-id='".$product_data['id']."'></div>";
}

function wc_yotpo_show_buttomline() {
	$product = get_product();
	$show_bottom_line = is_product() ? $product->post->comment_status == 'open' : true;
	if($show_bottom_line) {
		$product_data = wc_yotpo_get_product_data($product);	
		$yotpo_div = "<div class='yotpo bottomLine' 
	   				data-product-id='".$product_data['id']."'
	   				data-url='".$product_data['url']."' 
	   				data-lang='".$product_data['lang']."'></div>";
		echo $yotpo_div;	
	}	
				
}

function wc_yotpo_get_product_data($product) {	
	$product_data = array();
	$settings = get_option('yotpo_settings',wc_yotpo_get_degault_settings());
	$product_data['app_key'] = $settings['app_key'];
	$product_data['shop_domain'] = wc_yotpo_get_shop_domain(); 
	$product_data['url'] = get_permalink($product->id);
	$product_data['lang'] = $settings['language_code']; 
	if($settings['yotpo_language_as_site'] == true) {
		$lang = explode('-', get_bloginfo('language'));
		// In some languages there is a 3 letters language code
		//TODO map these iso-639-2 to iso-639-1 (from 3 letters language code to 2 letters language code) 
		if(strlen($lang[0]) == 2) {
			$product_data['lang'] = $lang[0];	
		}		
	}
	$product_data['description'] = strip_tags($product->get_post_data()->post_excerpt);
	$product_data['id'] = $product->id;	
	$product_data['title'] = $product->get_title();
	$product_data['image-url'] = wc_yotpo_get_product_image_url($product->id);
	$product_data['product-models'] = $product->get_sku();	
	return $product_data;
}

function wc_yotpo_get_shop_domain() {
	return parse_url(get_bloginfo('url'),PHP_URL_HOST);
}

function wc_yotpo_remove_native_review_system($open, $post_id) {
	if(get_post_type($post_id) == 'product') {
		return false;
	}
	return $open;
}

function wc_yotpo_map($order_id) {
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	$secret = $yotpo_settings['secret'];
	$app_key = $yotpo_settings['app_key'];
	if(!empty($app_key) && !empty($secret) && wc_yotpo_compatible()){
		try {		
				$purchase_data = wc_yotpo_get_single_map_data($order_id);
				if(!is_null($purchase_data) && is_array($purchase_data)) {
					require_once(plugin_dir_path( __FILE__ ) . 'lib/yotpo-api/Yotpo.php');					
					$yotpo_api = new Yotpo($app_key, $secret);
					$get_oauth_token_response = $yotpo_api->get_oauth_token();
					if(!empty($get_oauth_token_response) && !empty($get_oauth_token_response['access_token'])) {
						$purchase_data['utoken'] = $get_oauth_token_response['access_token'];
						$purchase_data['platform'] = 'woocommerce';
						$response = $yotpo_api->create_purchase($purchase_data);
				}
			}		
		}
		catch (Exception $e) {
			error_log($e->getMessage());
		}
	}
}

function wc_yotpo_get_single_map_data($order_id) {
	$order = new WC_Order($order_id);
	$data = null;
	if(!is_null($order->id)) {
		$data = array();
		$data['order_date'] = $order->order_date;
		$data['email'] = $order->billing_email;
		$data['customer_name'] = $order->billing_first_name.' '.$order->billing_last_name;
		$data['order_id'] = $order_id;
		$data['currency_iso'] = wc_yotpo_get_order_currency($order);
		$products_arr = array();
		foreach ($order->get_items() as $product) 
		{
			$product_instance = get_product($product['product_id']);
 
			$description = '';
			if (is_object($product_instance)) {
				$description = strip_tags($product_instance->get_post_data()->post_excerpt);	
			}
			$product_data = array();   
			$product_data['url'] = get_permalink($product['product_id']); 
			$product_data['name'] = $product['name'];
			$product_data['image'] = wc_yotpo_get_product_image_url($product['product_id']);
			$product_data['description'] = $description;
			$product_data['price'] = $product['line_total'];
			$products_arr[$product['product_id']] = $product_data;	
		}	
		$data['products'] = $products_arr;
	}
	return $data;
}

function wc_yotpo_get_product_image_url($product_id) {
	$url = wp_get_attachment_url(get_post_thumbnail_id($product_id));
	return $url ? $url : null;
}

function wc_yotpo_get_past_orders() {
	$result = null;
	$args = array(
		'post_type'		 => 'shop_order',
		'posts_per_page' => -1
	);

	if (defined('WC_VERSION') && (version_compare(WC_VERSION, '2.2.0') >= 0)) {
		$args['post_status'] = 'wc-completed';
	} else {
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'shop_order_status',
				'field'    => 'slug',
				'terms'    => array('completed'),
				'operator' => 'IN'
			)
		);
	}
	
	add_filter( 'posts_where', 'wc_yotpo_past_order_time_query' );
	$query = new WP_Query( $args );
	remove_filter( 'posts_where', 'wc_yotpo_past_order_time_query' );
	wp_reset_query();
	if ($query->have_posts()) {
		$orders = array();
		while ($query->have_posts()) { 
			$query->the_post();
			$order = $query->post;		
			$single_order_data = wc_yotpo_get_single_map_data($order->ID);
			if(!is_null($single_order_data)) {
				$orders[] = $single_order_data;
			}      	
		}
		if(count($orders) > 0) {
			$post_bulk_orders = array_chunk($orders, 200);
			$result = array();
			foreach ($post_bulk_orders as $index => $bulk)
			{
				$result[$index] = array();
				$result[$index]['orders'] = $bulk;
				$result[$index]['platform'] = 'woocommerce';			
			}
		}		
	}
	return $result;
}

function wc_yotpo_past_order_time_query( $where = '' ) {
	// posts in the last 30 days
	$where .= " AND post_date > '" . date('Y-m-d', strtotime('-90 days')) . "'";
	return $where;
}

function wc_yotpo_send_past_orders() {
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	if (!empty($yotpo_settings['app_key']) && !empty($yotpo_settings['secret']))
	{
		$past_orders = wc_yotpo_get_past_orders();		
		$is_success = true;
		if(!is_null($past_orders) && is_array($past_orders)) {
			$yotpo_api = new Yotpo($yotpo_settings['app_key'], $yotpo_settings['secret']);
			$get_oauth_token_response = $yotpo_api->get_oauth_token();
			if(!empty($get_oauth_token_response) && !empty($get_oauth_token_response['access_token'])) {
				foreach ($past_orders as $post_bulk) 
					if (!is_null($post_bulk))
					{
						$post_bulk['utoken'] = $get_oauth_token_response['access_token'];
						$response = $yotpo_api->create_purchases($post_bulk);						
						if ($response['code'] != 200 && $is_success)
						{
							$is_success = false;
							$message = !empty($response['status']) && !empty($response['status']['message']) ? $response['status']['message'] : 'Error occurred';
							wc_yotpo_display_message($message, true);
						}
					}
				if ($is_success)
				{
					wc_yotpo_display_message('Past orders sent successfully' , false);
					$yotpo_settings['show_submit_past_orders'] = false;
					update_option('yotpo_settings', $yotpo_settings);
				}	
			}
		}
		else {
			wc_yotpo_display_message('Could not retrieve past orders', true);
		}	
	}
	else {
		wc_yotpo_display_message('You need to set your app key and secret token to post past orders', false);
	}		
}

function wc_yotpo_conversion_track($order_id) {
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	$order = new WC_Order($order_id);
	$currency = wc_yotpo_get_order_currency($order);
	
	$conversion_params = "app_key="      .$yotpo_settings['app_key'].
           				 "&order_id="    .$order_id.
           				 "&order_amount=".$order->get_total().
           				 "&order_currency="  .$currency;
	echo "<img 
   	src='https://api.yotpo.com/conversion_tracking.gif?$conversion_params'
	width='1'
	height='1'></img>";
}

function wc_yotpo_get_degault_settings() {
	return array( 'app_key' => '',
				  'secret' => '',
				  'widget_location' => 'footer',
				  'language_code' => 'en',
				  'widget_tab_name' => 'Reviews',
				  'bottom_line_enabled_product' => true,
				  'bottom_line_enabled_category' => true,
				  'yotpo_language_as_site' => true,
				  'show_submit_past_orders' => true,
				  'disable_native_review_system' => true,
				  'native_star_ratings_enabled' => 'no');
}

function wc_yotpo_admin_styles($hook) {
	if($hook == 'toplevel_page_woocommerce-yotpo-settings-page') {		
		wp_enqueue_script( 'yotpoSettingsJs', plugins_url('assets/js/settings.js', __FILE__), array('jquery-effects-core'));				
		wp_enqueue_style( 'yotpoSettingsStylesheet', plugins_url('assets/css/yotpo.css', __FILE__));
	}
	wp_enqueue_style('yotpoSideLogoStylesheet', plugins_url('assets/css/side-menu-logo.css', __FILE__));
}

function wc_yotpo_compatible() {
	return version_compare(phpversion(), '5.2.0') >= 0 && function_exists('curl_init');
}

function wc_yotpo_deactivate() {
	update_option('woocommerce_enable_review_rating', get_option('native_star_ratings_enabled'));	
}

add_filter('woocommerce_tab_manager_integration_tab_allowed', 'wc_yotpo_disable_tab_manager_managment');

function wc_yotpo_disable_tab_manager_managment($allowed, $tab = null) {
	if($tab == 'yotpo_widget') {
		$allowed = false;
		return false;
	}
}

function wc_yotpo_get_order_currency($order) {
	if(is_null($order) || !is_object($order)) {
		return '';
	}
	if(method_exists($order,'get_order_currency')) { 
		return $order->get_order_currency();
	}
	if(isset($order->order_custom_fields) && isset($order->order_custom_fields['_order_currency'])) {		
 		if(is_array($order->order_custom_fields['_order_currency'])) {
 			return $order->order_custom_fields['_order_currency'][0];
 		}	
	}
	return '';
}