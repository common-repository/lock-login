<?php
namespace Locklogin;

use Browser;

class App
{
	private static $instance = null;

	private $db;

	public function __construct()
	{
		$this->db = new DB();
		
		add_filter('cron_schedules', array($this, 'cron_schedules'));
		add_action('locklogin_cron', array($this->db, 'clear_records'));

		register_activation_hook(LOCKLOGIN_FILE, array(Installation::class, 'install'));
		register_deactivation_hook(LOCKLOGIN_FILE, array(Installation::class, 'uninstall'));

		add_action('wp_login_failed', array($this, 'login_failed'), 10, 2);
		add_action('wp_authenticate_user', array($this, 'authenticate_user'), 100, 2); // set at last
		add_filter('shake_error_codes', array($this, 'failure_shake'), 10);

		add_action('admin_init', array($this, 'unlock_users'));
		add_action('admin_menu', array($this, 'register_admin_menu'));
	}

	/**
	 * Static function for boot the application
	 */
	public static function boot()
	{
		if(static::$instance === null){
			static::$instance = new static();
		}
	}

	/**
	 * @param 	$username 	string
	 * @param 	$error  	WP_Error 	
	 */
	public function login_failed($username, $error)
	{
		$server_data = $this->get_server_data();
		$loaded_from = 'wp-login';
		if(isset($GLOBALS['wp_xmlrpc_server']) && is_object($GLOBALS['wp_xmlrpc_server'])){
			$loaded_from = 'wp_xmlrpc_server';
		}
		//sdlo('login failed', $username);
		
		$this->db->record_failed($username, $error, $server_data, $loaded_from);
	}

	/**
	 * @param 	$user 		WP_User
	 * @param 	$password 	string
	 *
	 * @return WP_Error|WP_User
	 */
	public function authenticate_user($user, $password)
	{
		if(is_wp_error($user))
			return $user;

		$server_data = $this->get_server_data();
		if(!$this->db->user_check($user->user_login, $server_data['REMOTE_ADDR'])){
			$error = new \WP_Error();
			$error->add('too_many_retries', "Your user has surpased the limit retries, please wait 20 minutes");
			return $error;
		} else {
			return $user;
		}
	}

	/**
	 * @param 	$error_code 	array
	 * 
	 * @return 	$error_cores 	array
	 */
	public function failure_shake($error_code)
	{
		$error_codes[] = 'too_many_retries';
		return $error_codes;
	}

	/**
	 * @return 	anonymous	array
	 */
	private function get_server_data()
	{
		$browser = new Browser();
		return [
			'HTTP_HOST' => filter_var($_SERVER['HTTP_HOST'], FILTER_SANITIZE_STRING),
			'SERVER_NAME' => filter_var($_SERVER['SERVER_NAME'], FILTER_SANITIZE_STRING),
			'SERVER_ADDR' => filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP),
			'REMOTE_ADDR' => filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP),
			'browser' => $browser->getBrowser(),
			'browser_version' => $browser->getVersion(),
			'platform' => $browser->getPlatform()
		];
	}

	/**
	 * Add two more types of schedules, one for 20 minutes and other for 30 minutes
	 *
	 * @param 	$schedules 	array
	 * 
	 * @return 	$schedules 	array
	 */
	public function cron_schedules($schedules)
	{
		$schedules['locklogin30'] = array(
			'interval' => 30*60,
			'display' => __('Half Hour')
		);

		$schedules['locklogin20'] = array(
			'interval' => 20*60,
			'display' => __('third of Hour')
		);

		return $schedules;
	}

	/**
	 * Unlock a determinated user for allow to login once again
	 */
	public function unlock_users()
	{
		if(current_user_can('manage_options') && isset($_POST['locklogin_id']) && is_numeric($_POST['locklogin_id'])){
			$this->db->disable_lock(filter_var($_POST['locklogin_id']), FILTER_VALIDATE_INT);
			wp_redirect(get_admin_url().'options-general.php?page=lock-login');
			exit;
		}

		if(current_user_can('manage_options') 
			&& isset($_GET['page']) 
			&& strval($_GET['page']) == 'lock-login'
			&& isset($_GET['flush-locked-records'])
			&& intval($_GET['flush-locked-records']) == 1){
			$this->db->drop_records();
			wp_redirect(get_admin_url().'options-general.php?page=lock-login');
			exit;
		}
	}

	/**
	 * Register the admin menu for the wordpress panel
	 */
	public function register_admin_menu()
	{
		add_submenu_page(
			'options-general.php',
			'Lock Login',
			'Lock Login',
			'manage_options',
			'lock-login',
			array($this, 'admin_callback')
		);
	}

	/**
	 * Callback function for construct the admin page
	 */
	public function admin_callback()
	{
		$results = $this->db->get_locked_today();
		?>
		<div class="wrap">
			<h2><?php echo esc_html(get_admin_page_title()); ?></h2>
			<h3>Lock tracking</h3>
			<table class="wp-list-table widefat fixed striped table-view-list posts">
				<thead>
					<tr>
						<td>Username</td>
						<td>Ip Address</td>
						<td>Browser</td>
						<td>Platform</td>
						<td>Attempts</td>
						<td>Date</td>
						<td>Action</td>
					</tr>
				</thead>
				<tbody>
					<?php 
					if($results): 
					foreach ($results as $obj):
					?>
					<tr>
						<td><?php echo esc_html($obj->username); ?></td>
						<td><?php echo esc_html($obj->remote_addr); ?></td>
						<td><?php echo esc_html($obj->browser.' ('.$obj->browser_version.')'); ?></td>
						<td><?php echo esc_html($obj->platform); ?></td>
						<td><?php echo esc_html($obj->attempts); ?></td>
						<td><?php echo esc_html($obj->created_at.' '.$obj->created_time_at); ?></td>
						<td>
							<form method="post">
								<input type="hidden" name="locklogin_id" value="<?php echo esc_html($obj->id); ?>">
								<button type="submit">Unlock</button>
							</form>
						</td>
					</tr>
					<?php
					endforeach;
					endif; 
					?>
				</tbody>
			</table>
			<p style="margin-top: 20px; margin-bottom: 6px;">For delete the registered records click the below button, the purpose of deleting the records it's for empty space, because all the failed records are registered in the database.</p>
			<a class="button" href="<?php echo get_admin_url().'options-general.php?page=lock-login&flush-locked-records=1'; ?>" style="background-color: #ffdd38;">Flush Records</a>
		</div>
		<?php
	}

}