<?php
/*
Plugin Name: ticketbud
Plugin URI: http://www.ticketbud.com/
Description: Sell tickets and collect your proceeds through Paypal.  The Ticketbud plugin generates ticket-buying links and displays information for your Ticketbud events.  It makes it simple for your guests and attendees to buy tickets to your events.
Version: 1.0.2
Author: ken berland, lisa chambers
Author URI: http://www.ticketbud.com/
*/

define('TICKETBUD_VERSION', '1.0.2');
$ticketbud_unused_api_key = '';

function ticketbud_init() {
	global $ticketbud_unused_api_key, $ticketbud_api_host, $ticketbud_api_port;

	if ( $ticketbud_unused_api_key )
		$ticketbud_api_host = 'www.ticketbud.com';
	else
	  $ticketbud_api_host = 'www.ticketbud.com';

	$ticketbud_api_port = 80;
	add_action('admin_menu', 'ticketbud_config_page');
	ticketbud_admin_warnings();
}
add_action('init', 'ticketbud_init');

if ( !function_exists('wp_nonce_field') ) {
	function ticketbud_nonce_field($action = -1) { return; }
	$ticketbud_nonce = -1;
} else {
	function ticketbud_nonce_field($action = -1) { return wp_nonce_field($action); }
	$ticketbud_nonce = 'ticketbud-update-key';
}

if ( !function_exists('number_format_i18n') ) {
	function number_format_i18n( $number, $decimals = null ) { return number_format( $number, $decimals ); }
}

function ticketbud_config_page() {
	if ( function_exists('add_submenu_page') )
		add_submenu_page('plugins.php', __('Ticketbud Configuration'), __('Ticketbud Configuration'), 'manage_options', 'ticketbud-key-config', 'ticketbud_conf');

}

function ticketbud_conf() {
	global $ticketbud_nonce, $ticketbud_unused_api_key;

	if ( isset($_POST['submit']) ) {
		if ( function_exists('current_user_can') && !current_user_can('manage_options') )
			die(__('Cheatin&#8217; uh?'));

		check_admin_referer( $ticketbud_nonce );
		// $key = preg_replace( '/[^a-h0-9]/i', '', $_POST['key'] );
		$key = $_POST['key'];

		if ( empty($key) ) {
			$key_status = 'empty';
			$ms[] = 'new_key_empty';
			delete_option('ticketbud_username');
		} else {
			$key_status = ticketbud_verify_user( $key );
		}

		if ( $key_status == 'valid' ) {
			update_option('ticketbud_username', $key);
			$ms[] = 'new_key_valid';
		} else if ( $key_status == 'invalid' ) {
			$ms[] = 'new_key_invalid';
		} else if ( $key_status == 'failed' ) {
			$ms[] = 'new_key_failed';
		}

		if ( isset( $_POST['ticketbud_discard_month'] ) )
			update_option( 'ticketbud_discard_month', 'true' );
		else
			update_option( 'ticketbud_discard_month', 'false' );
	} elseif ( isset($_POST['check']) ) {
		ticketbud_get_server_connectivity(0);
	} elseif ( isset($_POST['clear']) ) {
		$url = sprintf(__('http://ticketbud.com/rss?username=%s'), get_option('ticketbud_username') );
		$rss = fetch_feed($url);
		$cache = call_user_func(array($rss->cache_class, 'create'), $rss->cache_location, call_user_func($rss->cache_name_function, $rss->feed_url), 'spc');
		$cache->unlink();
		error_log( "cleared tb feed cache." );
	}

	if ( $key_status != 'valid' ) {
		$key = get_option('ticketbud_username');
		if ( empty( $key ) ) {
			if ( $key_status != 'failed' ) {
				if ( ticketbud_verify_user( '1234567890ab' ) == 'failed' )
					$ms[] = 'no_connection';
				else
					$ms[] = 'key_empty';
			}
			$key_status = 'empty';
		} else {
			$key_status = ticketbud_verify_user( $key );
		}
		if ( $key_status == 'valid' ) {
			$ms[] = 'key_valid';
		} else if ( $key_status == 'invalid' ) {
			delete_option('ticketbud_username');
			$ms[] = 'key_empty';
		} else if ( !empty($key) && $key_status == 'failed' ) {
			$ms[] = 'key_failed';
		}
	}

	$messages = array(
		'new_key_empty' => array('color' => 'aa0', 'text' => __('Your username has been deleted.')),
		'new_key_valid' => array('color' => '2d2', 'text' => sprintf(__('Your username has been verified.<br />Now, <a href="%s" style="color:#fff">configure the Ticketbud widget</a> to start selling tickets!'), './widgets.php')),
		'new_key_invalid' => array('color' => 'd22', 'text' => __('The username you entered is invalid. Please double-check it.')),
		'new_key_failed' => array('color' => 'd22', 'text' => __('The username you entered could not be verified because a connection to ticketbud.com could not be established. Please check your server configuration.')),
		'no_connection' => array('color' => 'd22', 'text' => __('There was a problem connecting to the Ticketbud server. Please check your server configuration.')),
		'key_empty' => array('color' => 'aa0', 'text' => sprintf(__('Please enter a ticketbud username. (<a href="%s" style="color:#fff">Get a username.</a>)'), 'http://ticketbud.com/signup')),
		'key_valid' => array('color' => '2d2', 'text' =>sprintf(__('Your username has been verified.<br />Now, <a href="%s" style="color:#fff">configure the Ticketbud widget</a> to start selling tickets!'), './widgets.php')),
		'key_failed' => array('color' => 'aa0', 'text' => __('The username below was previously validated but a connection to ticketbud.com can not be established at this time. Please check your server configuration.')));
?>
<?php if ( !empty($_POST['submit'] ) ) : ?>
<div id="message" class="updated fade"><p><strong><?php _e('Options saved.') ?></strong></p></div>
<?php endif; ?>
<div class="wrap">
<h2><?php _e('Ticketbud Configuration'); ?></h2>
<div class="narrow">
<form action="" method="post" id="ticketbud-conf" style="margin: auto; width: 400px; ">
<?php if ( !$ticketbud_unused_api_key ) { ?>
	<p><?php printf(__('Once configured, this <a href="%1$s">Ticketbud</a> plugin will display links to your events.  Enter your username from ticketbud.com below.  If you don\'t have an account yet, you can get one at <a href="%2$s">Ticketbud.com</a>.'), 'http://ticketbud.com/', 'http://ticketbud.com/get/'); ?></p>

<h3><label for="key"><?php _e('Ticketbud Username'); ?></label></h3>
<?php foreach ( $ms as $m ) : ?>
	<p style="padding: .5em; background-color: #<?php echo $messages[$m]['color']; ?>; color: #fff; font-weight: bold;"><?php echo $messages[$m]['text']; ?></p>
<?php endforeach; ?>
<p><input id="key" name="key" type="text" size="15" value="<?php echo get_option('ticketbud_username'); ?>" style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;" /> 

</p>
<?php if ( $invalid_key ) { ?>
<h3><?php _e('Why might my key be invalid?'); ?></h3>
<p><?php _e('This can mean one of two things, either you copied the key wrong or that the plugin is unable to reach the Ticketbud servers, which is most often caused by an issue with your web host around firewalls or similar.'); ?></p>
<?php } ?>
<?php } ?>
<?php ticketbud_nonce_field($ticketbud_nonce) ?>
	<p class="submit"><input type="submit" name="submit" value="<?php _e('Update options &raquo;'); ?>" /></p>
</form>

<form action="" method="post" id="ticketbud-connectivity" style="margin: auto; width: 400px; ">

<h3><?php _e('Server Connectivity'); ?></h3>
<?php
	if ( !function_exists('fsockopen') || !function_exists('gethostbynamel') ) {
		?>
			<p style="padding: .5em; background-color: #d22; color: #fff; font-weight:bold;"><?php _e('Network functions are disabled.'); ?></p>
			<p><?php echo sprintf( __('Your web host or server administrator has disabled PHP\'s <code>fsockopen</code> or <code>gethostbynamel</code> functions.  <strong>Ticketbud cannot work correctly until this is fixed.</strong>  Please contact your web host or firewall administrator and give them <a href="%s" target="_blank">this information about Ticketbud\'s system requirements</a>.'), 'http://blog.ticketbud.com/ticketbud-hosting-faq/'); ?></p>
		<?php
	} else {
		$servers = ticketbud_get_server_connectivity();
		$fail_count = count($servers) - count( array_filter($servers) );
		if ( is_array($servers) && count($servers) > 0 ) {
			// some connections work, some fail
			if ( $fail_count > 0 && $fail_count < count($servers) ) { ?>
				<p style="padding: .5em; background-color: #aa0; color: #fff; font-weight:bold;"><?php _e('Unable to reach some Ticketbud servers.'); ?></p>
				<p><?php echo sprintf( __('A network problem or firewall is blocking some connections from your web server to Ticketbud.com.  Ticketbud is working but this may cause problems during times of network congestion.  Please contact your web host or firewall administrator and give them <a href="%s" target="_blank">this information about Ticketbud and firewalls</a>.'), 'http://blog.ticketbud.com/ticketbud-hosting-faq/'); ?></p>
			<?php
			// all connections fail
			} elseif ( $fail_count > 0 ) { ?>
				<p style="padding: .5em; background-color: #d22; color: #fff; font-weight:bold;"><?php _e('Unable to reach any Ticketbud servers.'); ?></p>
				<p><?php echo sprintf( __('A network problem or firewall is blocking all connections from your web server to Ticketbud.com.  <strong>Ticketbud cannot work correctly until this is fixed.</strong>  Please contact your web host or firewall administrator and give them <a href="%s" target="_blank">this information about Ticketbud and firewalls</a>.'), 'http://blog.ticketbud.com/ticketbud-hosting-faq/'); ?></p>
			<?php
			// all connections work
			} else { ?>
				<p style="padding: .5em; background-color: #2d2; color: #fff; font-weight:bold;"><?php  _e('All Ticketbud servers are available.'); ?></p>
				<p><?php _e('Ticketbud is working correctly.  All servers are accessible.'); ?></p>
			<?php
			}
		} else {
			?>
				<p style="padding: .5em; background-color: #d22; color: #fff; font-weight:bold;"><?php _e('Unable to find Ticketbud servers.'); ?></p>
				<p><?php echo sprintf( __('A DNS problem or firewall is preventing all access from your web server to Ticketbud.com.  <strong>Ticketbud cannot work correctly until this is fixed.</strong>  Please contact your web host or firewall administrator and give them <a href="%s" target="_blank">this information about Ticketbud and firewalls</a>.'), 'http://blog.ticketbud.com/ticketbud-hosting-faq/'); ?></p>
			<?php
		}
	}
	
	if ( !empty($servers) ) {
?>
<table style="width: 100%;">
<thead><th><?php _e('Ticketbud server'); ?></th><th><?php _e('Network Status'); ?></th></thead>
<tbody>
<?php
		asort($servers);
		foreach ( $servers as $ip => $status ) {
			$color = ( $status ? '#2d2' : '#d22');
	?>
		<tr>
		<td><?php echo htmlspecialchars($ip); ?></td>
		<td style="padding: 0 .5em; font-weight:bold; color: #fff; background-color: <?php echo $color; ?>"><?php echo ($status ? __('No problems') : __('Obstructed') ); ?></td>
		
	<?php
		}
	}
?>
</tbody>
</table>
	<p><?php if ( get_option('ticketbud_connectivity_time') ) echo sprintf( __('Last checked %s ago.'), human_time_diff( get_option('ticketbud_connectivity_time') ) ); ?></p>
	<p class="submit"><input type="submit" name="check" value="<?php _e('Check network status &raquo;'); ?>" /></p>
	<p class="submit"><input type="submit" name="clear" value="<?php _e('Reload My Events&raquo;'); ?>" /></p>
</form>

</div>
</div>
<?php
}



function ticketbud_get_key() {
	global $ticketbud_unused_api_key;
	if ( !empty($ticketbud_unused_api_key) )
		return $ticketbud_unused_api_key;
	return get_option('ticketbud_username');
}

function ticketbud_verify_user( $key, $ip = null ) {
	global $ticketbud_api_host, $ticketbud_api_port, $ticketbud_unused_api_key;
	$blog = urlencode( get_option('home') );
	if ( $ticketbud_unused_api_key )
		$key = $ticketbud_unused_api_key;
	//error_log( $key." ".strlen($key));
	if ( strlen($key) < 1 )
	  return 'invalid';
	$response = ticketbud_http_get('ticketbud.com', '/check_user_name?username='.$key, $ticketbud_api_port, $ip);
	//error_log( $response[1] );
	if ( !is_array($response) || !isset($response[1]) || $response[1] == '{"result":true}' )
		return 'invalid';
	return 'valid';
}

// Check connectivity between the WordPress blog and Ticketbud's servers.
// Returns an associative array of server IP addresses, where the key is the IP address, and value is true (available) or false (unable to connect).
function ticketbud_check_server_connectivity() {
	global $ticketbud_api_host, $ticketbud_api_port, $ticketbud_unused_api_key;
	
	$test_host = 'ticketbud.com';
	
	// Some web hosts may disable one or both functions
	if ( !function_exists('fsockopen') || !function_exists('gethostbynamel') )
		return array();
	
	$ips = gethostbynamel($test_host);
	if ( !$ips || !is_array($ips) || !count($ips) )
		return array();
		
	$servers = array();
	foreach ( $ips as $ip ) {
		$response = ticketbud_verify_user( ticketbud_get_key(), $ip );
		// even if the key is invalid, at least we know we have connectivity
		if ( $response == 'valid' || $response == 'invalid' )
			$servers[$ip] = true;
		else
			$servers[$ip] = false;
	}

	return $servers;
}

// Check the server connectivity and store the results in an option.
// Cached results will be used if not older than the specified timeout in seconds; use $cache_timeout = 0 to force an update.
// Returns the same associative array as ticketbud_check_server_connectivity()
function ticketbud_get_server_connectivity( $cache_timeout = 86400 ) {
	$servers = get_option('ticketbud_available_servers');
	if ( (time() - get_option('ticketbud_connectivity_time') < $cache_timeout) && $servers !== false )
		return $servers;
	
	// There's a race condition here but the effect is harmless.
	$servers = ticketbud_check_server_connectivity();
	update_option('ticketbud_available_servers', $servers);
	update_option('ticketbud_connectivity_time', time());
	return $servers;
}

// Returns true if server connectivity was OK at the last check, false if there was a problem that needs to be fixed.
function ticketbud_server_connectivity_ok() {
	// skip the check on WPMU because the status page is hidden
	global $ticketbud_unused_api_key;
	if ( $ticketbud_unused_api_key )
		return true;
	$servers = ticketbud_get_server_connectivity();
	return !( empty($servers) || !count($servers) || count( array_filter($servers) ) < count($servers) );
}

function ticketbud_admin_warnings() {
	global $ticketbud_unused_api_key;
	if ( !get_option('ticketbud_username') && !$ticketbud_unused_api_key && !isset($_POST['submit']) ) {
		function ticketbud_warning() {
			echo "
			<div id='ticketbud-warning' class='updated fade'><p><strong>".__('Ticketbud is almost ready.')."</strong> ".sprintf(__('You must <a href="%1$s">enter your ticketbud username</a> for it to work.'), "plugins.php?page=ticketbud-key-config")."</p></div>
			";
		}
		add_action('admin_notices', 'ticketbud_warning');
		return;
	} elseif ( get_option('ticketbud_connectivity_time') && empty($_POST) && is_admin() && !ticketbud_server_connectivity_ok() ) {
		function ticketbud_warning() {
			echo "
			<div id='ticketbud-warning' class='updated fade'><p><strong>".__('Ticketbud has detected a problem.')."</strong> ".sprintf(__('A server or network problem is preventing Ticketbud from working correctly.  <a href="%1$s">Click here for more information</a> about how to fix the problem.'), "plugins.php?page=ticketbud-key-config")."</p></div>
			";
		}
		add_action('admin_notices', 'ticketbud_warning');
		return;
	}
}

function ticketbud_get_host($host) {
	// if all servers are accessible, just return the host name.
	// if not, return an IP that was known to be accessible at the last check.
	if ( ticketbud_server_connectivity_ok() ) {
		return $host;
	} else {
		$ips = ticketbud_get_server_connectivity();
		// a firewall may be blocking access to some Ticketbud IPs
		if ( count($ips) > 0 && count(array_filter($ips)) < count($ips) ) {
			// use DNS to get current IPs, but exclude any known to be unreachable
			$dns = (array)gethostbynamel( rtrim($host, '.') . '.' );
			$dns = array_filter($dns);
			foreach ( $dns as $ip ) {
				if ( array_key_exists( $ip, $ips ) && empty( $ips[$ip] ) )
					unset($dns[$ip]);
			}
			// return a random IP from those available
			if ( count($dns) )
				return $dns[ array_rand($dns) ];
			
		}
	}
	// if all else fails try the host name
	return $host;
}

// return a comma-separated list of role names for the given user
function ticketbud_get_user_roles($user_id ) {
	$roles = false;
	
	if ( !class_exists('WP_User') )
		return false;
	
	if ( $user_id > 0 ) {
		$comment_user = new WP_User($user_id);
		if ( isset($comment_user->roles) )
			$roles = join(',', $comment_user->roles);
	}
	
	return $roles;
}

// Returns array with headers in $response[0] and body in $response[1]
function ticketbud_http_post($request, $host, $path, $port = 80, $ip=null) {
	global $wp_version;
	
	$ticketbud_version = constant('TICKETBUD_VERSION');

	$http_request  = "POST $path HTTP/1.0\r\n";
	$http_request .= "Host: $host\r\n";
	$http_request .= "Content-Type: application/x-www-form-urlencoded; charset=" . get_option('blog_charset') . "\r\n";
	$http_request .= "Content-Length: " . strlen($request) . "\r\n";
	$http_request .= "User-Agent: WordPress/$wp_version | Ticketbud/$ticketbud_version\r\n";
	$http_request .= "\r\n";
	$http_request .= $request;
	
	$http_host = $host;
	// use a specific IP if provided - needed by ticketbud_check_server_connectivity()
	if ( $ip && long2ip(ip2long($ip)) ) {
		$http_host = $ip;
	} else {
		$http_host = ticketbud_get_host($host);
	}

	$response = '';
	if( false != ( $fs = @fsockopen($http_host, $port, $errno, $errstr, 10) ) ) {
		fwrite($fs, $http_request);

		while ( !feof($fs) )
			$response .= fgets($fs, 1160); // One TCP-IP packet
		fclose($fs);
		$response = explode("\r\n\r\n", $response, 2);
	}
	return $response;
}

function ticketbud_http_get($host, $path, $port = 80, $ip=null) {
	global $wp_version;
	
	$ticketbud_version = constant('TICKETBUD_VERSION');

	$http_request  = "GET $path HTTP/1.0\r\n";
	$http_request .= "Host: $host\r\n";
	$http_request .= "User-Agent: WordPress/$wp_version | Ticketbud/$ticketbud_version\r\n";
	$http_request .= "\r\n";
	
	$http_host = $host;
	// use a specific IP if provided - needed by ticketbud_check_server_connectivity()
	if ( $ip && long2ip(ip2long($ip)) ) {
		$http_host = $ip;
	} else {
		$http_host = ticketbud_get_host($host);
	}

	$response = '';
	if( false != ( $fs = @fsockopen($http_host, $port, $errno, $errstr, 10) ) ) {
		fwrite($fs, $http_request);

		while ( !feof($fs) )
			$response .= fgets($fs, 1160); // One TCP-IP packet
		fclose($fs);
		$response = explode("\r\n\r\n", $response, 2);
	}
	return $response;
}


function ticketbud_kill_proxy_check( $option ) { return 0; }
add_filter('option_open_proxy_check', 'ticketbud_kill_proxy_check');

/**
 * From RSS widget class
 *
 * @since 2.8.0
 */

class Ticketbud_Widget extends WP_Widget {

	function Ticketbud_Widget() {
		$widget_ops = array( 'description' => __('Promote and sell tickets to your ticketbud events.') );
		$control_ops = array( 'width' => 400, 'height' => 200 );
		$this->WP_Widget( 'ticketbud', __('Ticketbud'), $widget_ops, $control_ops );
	}

	function widget($args, $instance) {

		if ( isset($instance['error']) && $instance['error'] )
			return;

		extract($args, EXTR_SKIP);

		$url = $instance['url'];
		while ( stristr($url, 'http') != $url )
			$url = substr($url, 1);

		if ( empty($url) )
			return;

		$rss = fetch_feed($url);
		$title = $instance['title'];
		$desc = '';
		$link = '';

		if ( ! is_wp_error($rss) ) {
			$desc = esc_attr(strip_tags(@html_entity_decode($rss->get_description(), ENT_QUOTES, get_option('blog_charset'))));
			if ( empty($title) )
				$title = esc_html(strip_tags($rss->get_title()));
			$link = esc_url(strip_tags($rss->get_permalink()));
			while ( stristr($link, 'http') != $link )
				$link = substr($link, 1);
		}

		if ( empty($title) )
			$title = empty($desc) ? __('Unknown Feed') : $desc;

		$title = apply_filters('widget_title', $title, $instance, $this->id_base);
		$url = esc_url(strip_tags($url));
		$icon = includes_url('images/rss.png');
		//if ( $title )
		$userurl = "http://" . get_option('ticketbud_username') . ".ticketbud.com/";
		$title = "<a class='rsswidget' href='$userurl' title='$title'>$title</a>";

		echo $before_widget;
		//		if ( $title )
			echo $before_title . $title . $after_title;
		ticketbud_widget_output( $rss, $instance );
		echo $after_widget;

		if ( ! is_wp_error($rss) )
			$rss->__destruct();
		unset($rss);
	}

	function update($new_instance, $old_instance) {
		$testurl = ( isset($new_instance['url']) && ($new_instance['url'] != $old_instance['url']) );
		return ticketbud_widget_process( $new_instance, $testurl );
	}

	function form($instance) {

		if ( empty($instance) )
		  $instance = array( 'title' => 'Tickets to Upcoming Events', 'url' => '', 'items' => 10, 'error' => false, 'show_summary' => 0, 'show_author' => 0, 'show_date' => 1 , 'show_poweredby' => 0);
		$instance['number'] = $this->number;

		ticketbud_widget_form( $instance );
	}
}

/**
 * Display the RSS entries in a list.
 *
 * @since 2.5.0
 *
 * @param string|array|object $rss RSS url.
 * @param array $args Widget arguments.
 */
function ticketbud_widget_output( $rss, $args = array() ) {
	if ( is_string( $rss ) ) {
		$rss = fetch_feed($rss);
	} elseif ( is_array($rss) && isset($rss['url']) ) {
		$args = $rss;
		$rss = fetch_feed($rss['url']);
	} elseif ( !is_object($rss) ) {
		return;
	}

	if ( is_wp_error($rss) ) {
		if ( is_admin() || current_user_can('manage_options') )
			echo '<p>' . sprintf( __('<strong>RSS Error</strong>: %s'), $rss->get_error_message() ) . '</p>';
		return;
	}

	$default_args = array( 'show_author' => 0, 'show_date' => 0, 'show_summary' => 0, 'show_poweredby' => 0 );
	$args = wp_parse_args( $args, $default_args );
	extract( $args, EXTR_SKIP );

	$items = (int) $items;
	if ( $items < 1 || 20 < $items )
		$items = 10;
	$show_summary  = (int) $show_summary;
	$show_author   = (int) $show_author;
	$show_date     = (int) $show_date;
	$show_poweredby   = (int) $show_poweredby;

	if ( !$rss->get_item_quantity() ) {
		echo '<ul><li>' . __( 'There are no current events.' ) . '</li></ul>';
		$rss->__destruct();
		unset($rss);
		return;
	}

	echo '<ul>';
	foreach ( $rss->get_items(0, $items) as $item ) {
		$link = $item->get_link();
		while ( stristr($link, 'http') != $link )
			$link = substr($link, 1);
		$link = esc_url(strip_tags($link));
		$title = esc_attr(strip_tags($item->get_title()));
		if ( empty($title) )
			$title = __('Untitled');

		$desc = str_replace( array("\n", "\r"), ' ', esc_attr( strip_tags( @html_entity_decode( $item->get_description(), ENT_QUOTES, get_option('blog_charset') ) ) ) );
		$desc = wp_html_excerpt( $desc, 360 );

		// Append ellipsis. Change existing [...] to [&hellip;].
		if ( '[...]' == substr( $desc, -5 ) )
			$desc = substr( $desc, 0, -5 ) . '[&hellip;]';
		elseif ( '[&hellip;]' != substr( $desc, -10 ) )
			$desc .= ' [&hellip;]';

		$desc = esc_html( $desc );

		if ( $show_summary ) {
			$summary = "<div class='rssSummary'>$desc</div>";
		} else {
			$summary = '';
		}

		$date = '';
		if ( $show_date ) {
			$date = $item->get_date();
			$myLocaltime = new DateTime($date);
			if ( get_option('timezone_string') ){
			  $myTimezone = get_option('timezone_string');
			}else{
			  $myTimezone = ticketbudGetTimeZoneStringFromOffset(get_option('gmt_offset'));
			}
			$myLocaltime->setTimezone(new DateTimeZone( $myTimezone ) );
			// error_log($myTimezone . " " . ($myLocaltime->getOffset()/ 3600) );
			if ( $date ) {
			  if ( $date_stamp = strtotime( $myLocaltime->format( get_option( 'date_format' ) ) ) )
				  $date = '<br /><span class="rss-date">' . date_i18n( get_option( 'date_format' ), $date_stamp ) . '</span>';
				else
					$date = '';
			}
		}

		$author = '';
		if ( $show_author ) {
			$author = $item->get_author();
			if ( is_object($author) ) {
				$author = $author->get_name();
				$author = ' <cite>' . esc_html( strip_tags( $author ) ) . '</cite>';
			}
		}

		if ( $link == '' ) {
			echo "<li>$title{$date}{$summary}{$author}</li>";
		} else {
 			echo "<li><a class='rsswidget' href='$link' title='$desc'><b>$title</b>{$date}{$summary}{$author}</a></li>";
		}
	}
	echo '</ul>';

	if ( $show_poweredby ) {
 	  echo "<a href='http://ticketbud.com' title='Sell Tickets Online'><img  style='padding:0px 0px 10px 25px;' src='http://ticketbud.com/images/Ticketbud-sm.png' alt='Ticketbud' border='0'/></a>";
	}

	$rss->__destruct();
	unset($rss);
}

function ticketbud_widget_form( $args, $inputs = null ) {

        $default_inputs = array( 'url' => true, 'title' => true, 'items' => true, 'show_summary' => true, 'show_author' => true, 'show_date' => true, 'show_poweredby' => true );
	$inputs = wp_parse_args( $inputs, $default_inputs );
	extract( $args );
	extract( $inputs, EXTR_SKIP);

	$number = esc_attr( $number );
	$title  = esc_attr( $title );
	$url    = esc_url( $url );
	$items  = (int) $items;
	if ( $items < 1 || 20 < $items )
		$items  = 10;
	$show_summary   = (int) $show_summary;
	$show_author    = (int) $show_author;
	$show_date      = (int) $show_date;
	$show_poweredby      = (int) $show_poweredby;

	if ( !empty($error) )
		echo '<p class="widget-error"><strong>' . sprintf( __('RSS Error: %s'), $error) . '</strong></p>';

	if ( $inputs['url'] ) :
?>
	<input type="hidden" id="ticketbud-url-<?php echo $number; ?>" name="widget-ticketbud[<?php echo $number; ?>][url]" type="text" value="<?php echo sprintf(__('http://ticketbud.com/rss?username=%s'), get_option('ticketbud_username') ); ?>" />
<?php endif; if ( $inputs['title'] ) : ?>
	<p><label for="ticketbud-title-<?php echo $number; ?>"><?php _e('Enter an optional title for your events:'); ?></label>
	<input class="widefat" id="ticketbud-title-<?php echo $number; ?>" name="widget-ticketbud[<?php echo $number; ?>][title]" type="text" value="<?php echo $title; ?>" /></p>
<?php endif; if ( $inputs['items'] ) : ?>
	<p><label for="ticketbud-items-<?php echo $number; ?>"><?php _e('How many events would you like to display?'); ?></label>
	<select id="ticketbud-items-<?php echo $number; ?>" name="widget-ticketbud[<?php echo $number; ?>][items]">
<?php
		for ( $i = 1; $i <= 20; ++$i )
			echo "<option value='$i' " . ( $items == $i ? "selected='selected'" : '' ) . ">$i</option>";
?>
	</select></p>
<?php endif; if ( $inputs['show_summary'] ) : ?>
	<p><input id="ticketbud-show-summary-<?php echo $number; ?>" name="widget-ticketbud[<?php echo $number; ?>][show_summary]" type="checkbox" value="1" <?php if ( $show_summary ) echo 'checked="checked"'; ?>/>
	<label for="ticketbud-show-summary-<?php echo $number; ?>"><?php _e('Display event content?'); ?></label></p>
<?php endif; if ( $inputs['show_author'] ) : ?>
	<p><input id="ticketbud-show-author-<?php echo $number; ?>" name="widget-ticketbud[<?php echo $number; ?>][show_author]" type="checkbox" value="1" <?php if ( $show_author ) echo 'checked="checked"'; ?>/>
	<label for="ticketbud-show-author-<?php echo $number; ?>"><?php _e('Display event author if available?'); ?></label></p>
<?php endif; if ( $inputs['show_date'] ) : ?>
	<p><input id="ticketbud-show-date-<?php echo $number; ?>" name="widget-ticketbud[<?php echo $number; ?>][show_date]" type="checkbox" value="1" <?php if ( $show_date ) echo 'checked="checked"'; ?>/>
	<label for="ticketbud-show-date-<?php echo $number; ?>"><?php _e('Display event date?'); ?></label></p>
<?php endif; if ( $inputs['show_poweredby'] ) : ?>
	<p><input id="ticketbud-show-poweredby-<?php echo $number; ?>" name="widget-ticketbud[<?php echo $number; ?>][show_poweredby]" type="checkbox" value="1" <?php if ( $show_poweredby ) echo 'checked="checked"'; ?>/>
	<label for="ticketbud-show-poweredby-<?php echo $number; ?>"><?php _e('Show \'Powered by Ticketbud.com\' logo?'); ?></label></p>



<?php
	endif;
	foreach ( array_keys($default_inputs) as $input ) :
		if ( 'hidden' === $inputs[$input] ) :
			$id = str_replace( '_', '-', $input );
?>
	<input type="hidden" id="ticketbud-<?php echo $id; ?>-<?php echo $number; ?>" name="widget-ticketbud[<?php echo $number; ?>][<?php echo $input; ?>]" value="<?php echo $$input; ?>" />
<?php
		endif;
	endforeach;
}

/**
 * Process RSS feed widget data and optionally retrieve feed items.
 *
 * The feed widget can not have more than 20 items or it will reset back to the
 * default, which is 10.
 *
 * The resulting array has the feed title, feed url, feed link (from channel),
 * feed items, error (if any), and whether to show summary, author, and date.
 * All respectively in the order of the array elements.
 *
 * @since 2.5.0
 *
 * @param array $widget_rss RSS widget feed data. Expects unescaped data.
 * @param bool $check_feed Optional, default is true. Whether to check feed for errors.
 * @return array
 */
function ticketbud_widget_process( $widget_rss, $check_feed = true ) {
	$items = (int) $widget_rss['items'];
	if ( $items < 1 || 20 < $items )
		$items = 10;
	$url           = esc_url_raw(strip_tags( $widget_rss['url'] ));
	$title         = trim(strip_tags( $widget_rss['title'] ));
	$show_summary  = isset($widget_rss['show_summary']) ? (int) $widget_rss['show_summary'] : 0;
	$show_author   = isset($widget_rss['show_author']) ? (int) $widget_rss['show_author'] :0;
	$show_date     = isset($widget_rss['show_date']) ? (int) $widget_rss['show_date'] : 0;
	$show_poweredby= isset($widget_rss['show_poweredby']) ? (int) $widget_rss['show_poweredby'] :0;


	if ( $check_feed ) {
		$rss = fetch_feed($url);
		$error = false;
		$link = '';
		if ( is_wp_error($rss) ) {
			$error = $rss->get_error_message();
		} else {
			$link = esc_url(strip_tags($rss->get_permalink()));
			while ( stristr($link, 'http') != $link )
				$link = substr($link, 1);

			$rss->__destruct();
			unset($rss);
		}
	}

	return compact( 'title', 'url', 'link', 'items', 'error', 'show_summary', 'show_author', 'show_date', 'show_poweredby' );
}

function ticketbud_widgets_init() {
	if ( !is_blog_installed() )
		return;

	register_widget('Ticketbud_Widget');
	// do_action('widgets_init');
}

function ticketbudGetTimeZoneStringFromOffset($offset) {
  $timezones = array( 
		     '-12'=>'Pacific/Kwajalein', 
		     '-11'=>'Pacific/Samoa', 
		     '-10'=>'Pacific/Honolulu', 
		     '-9.5'=>'Pacific/Marquesas', 
		     '-9'=>'America/Juneau', 
		     '-8'=>'America/Los_Angeles', 
		     '-7'=>'America/Denver', 
		     '-6'=>'America/Mexico_City', 
		     '-5'=>'America/New_York', 
		     '-4.5'=>'America/Caracas', 
		     '-4'=>'America/St_Kitts', 
		     '-3.5'=>'America/St_Johns', 
		     '-3'=>'America/Argentina/Buenos_Aires', 
		     '-2'=>'Atlantic/Azores',// no cities here so just picking an hour ahead 
		     '-1'=>'Atlantic/Azores', 
		     '0'=>'Europe/London', 
		     '1'=>'Europe/Paris', 
		     '2'=>'Europe/Helsinki', 
		     '3'=>'Europe/Moscow', 
		     '3.5'=>'Asia/Tehran', 
		     '4'=>'Asia/Baku', 
		     '4.5'=>'Asia/Kabul', 
		     '5'=>'Asia/Karachi', 
		     '5.5'=>'Asia/Calcutta', 
		     '5.75'=>'Asia/Katmandu', 
		     '6'=>'Asia/Colombo', 
		     '6.5'=>'Asia/Rangoon', 
		     '7'=>'Asia/Bangkok', 
		     '8'=>'Asia/Singapore', 
		     '9'=>'Asia/Tokyo', 
		     '9.5'=>'Australia/Darwin', 
		     '10'=>'Pacific/Guam', 
		     '10.5'=>'Australia/Lord_Howe', 
		     '11'=>'Asia/Magadan', 
		     '11.5'=>'Pacific/Norfolk', 
		     '12'=>'Asia/Kamchatka',
		     '12.75'=>'Pacific/Chatham',
		     '13'=>'Pacific/Tongatapu',
		     '14'=>'Pacific/Kiritimati'
		      ); 
  return $timezones[$offset];
} 



add_action('init', 'ticketbud_widgets_init', 1);
