<?php
namespace Locklogin;

class DB
{
	private $wpdb;
	private $register_table_name;

	public function __construct()
	{
		global $wpdb;
		// set the wpdb as a reference
		$this->wpdb =& $wpdb;
		$this->register_table_name = $this->wpdb->prefix.'lock_registers_rec';
	}

	private function get_current_date()
	{
		$current_date = current_datetime();
		return $current_date->format("Y-m-d");
	}

	private function get_current_time()
	{
		$current_time = current_datetime();
		return $current_time->format("H:i:s");
	}

	private function get_expiration_time()
	{
		$current_time = current_datetime();
		$expiration_time = $current_time->add(new \DateInterval('PT20M'));
		return $expiration_time->format("H:i:s");
	}

	private function has_reached($expired_time)
	{
		$current = $this->get_current_time();
		if((strtotime($expired_time) - strtotime($current)) <= 0){
			return true;
		} else {
			return false;
		}
	}

	public function get_locked_today()
	{
		$register_name = $this->register_table_name;
		return $this->wpdb->get_results($this->wpdb->prepare("SELECT id, username, http_host, remote_addr, browser, browser_version, platform, attempts, created_at, created_time_at FROM $register_name WHERE created_at = %s AND attempts > 0", $this->get_current_date()));
	}

	public function disable_lock($id)
	{
		$register_name = $this->register_table_name;
		$this->wpdb->update(
			$register_name,
			array(
				'attempts' => 0
			),
			array('id' => $id),
			array('%d')
		);
	}

	public function create_tables()
	{
		require_once ABSPATH."wp-admin/includes/upgrade.php";
		$charset_collate = $this->wpdb->get_charset_collate();

		$register_name = $this->register_table_name;
		if(!$this->wpdb->query($this->wpdb->prepare("show tables like %s", $register_name))){
			$sql_register = "CREATE TABLE $register_name(
							id bigint(20) unsigned NOT NULL auto_increment,
							username varchar(300) NOT NULL default '',
							http_host varchar(500) NOT NULL default '',
							server_name varchar(300) NOT NULL default '',
							server_addr varchar(100) NOT NULL default '',
							remote_addr varchar(100) NOT NULL default '',
							browser varchar(100) NOT NULL default '',
							browser_version varchar(100) NOT NULL default '',
							platform varchar(100) NOT NULL default '',
							attempts tinyint(3) NOT NULL default 0,
							screen varchar(50) NOT NULL default '',
							status tinyint(3) NOT NULL default 0,
							errormsg varchar(1000) NOT NULL default '',
							created_at date NOT NULL default '1000-01-01',
							created_time_at time NOT NULL default '00:00:00',
							updated_time_at time NOT NULL default '00:00:00',
							expired_time_at time NOT NULL default '00:00:00',
							PRIMARY KEY  (id)
						) $charset_collate";

			dbDelta($sql_register);
		}
	}

	public function drop_tables()
	{
		
		$register_name = $this->register_table_name;
		if($this->wpdb->query($this->wpdb->prepare("show tables like %s", $register_name))){
			$this->wpdb->query("DROP TABLE $register_name");
		}
	}

	/**
	 * @param 	$username 		string
	 * @param 	$error 			WP_Error
	 * @param 	$ips 			Array
	 * @param 	$loaded_from 	string
	 */
	public function record_failed($username, $error, $server_data, $loaded_from)
	{
		$today = $this->get_current_date();
		$remote_addr = $server_data['REMOTE_ADDR'];
		$register_name = $this->register_table_name;
		$today_record = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT id, remote_addr, attempts FROM $register_name WHERE created_at = '%s' and username = '%s' and remote_addr = '%s'", esc_sql($today), esc_sql($username), esc_sql($remote_addr) ) );
		if(!$today_record){
			$this->wpdb->insert(
				$register_name,
				array(
					'username' => $username,
					'http_host' => $server_data['HTTP_HOST'],
					'server_name' => $server_data['SERVER_NAME'],
					'server_addr' => $server_data['SERVER_ADDR'],
					'remote_addr' => $remote_addr,
					'browser' => $server_data['browser'],
					'browser_version' => $server_data['browser_version'],
					'platform' => $server_data['platform'],
					'attempts' => 1,
					'screen' => $loaded_from,
					'status' => 1,
					'errormsg' => "failed login from $loaded_from",
					'created_at' => $today,
					'created_time_at' => $this->get_current_time(),
					'updated_time_at' => $this->get_current_time(),
					'expired_time_at' => $this->get_expiration_time(),
				),
				array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
			);
		} else if($today_record->attempts <= 3) {
			$this->wpdb->update(
				$register_name,
				array(
					'attempts' => ++$today_record->attempts,
					'updated_time_at' => $this->get_current_time(),
				),
				array('id' => $today_record->id),
				array('%d', '%s')
			);
		}
	}

	/**
	 * @param 	$username 		string
	 *
	 * @return 	bool
	 */
	public function user_check($username, $remote_addr)
	{
		$today = $this->get_current_date();
		$register_name = $this->register_table_name;
		$user_record = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT id, remote_addr, attempts FROM $register_name WHERE created_at = '%s' and username = '%s' and remote_addr = '%s'", esc_sql($today), esc_sql($username), esc_sql($remote_addr) ) );
		if(!$user_record){
			return true;
		} else if($user_record->attempts < 3){
			return true;
		} else if ($user_record->attempts >= 3){
			return false;
		}
	}


	public function clear_records()
	{
		$today = $this->get_current_date();
		$register_name = $this->register_table_name;
		$records = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT id, remote_addr, attempts, expired_time_at FROM $register_name WHERE created_at = '%s'", esc_sql($today) ) );
		if($records){
			foreach ($records as $row) {
				if($this->has_reached($row->expired_time_at)){
					$this->wpdb->update($register_name, array('attempts' => 0), array('id' => $row->id), array('%d'));
				}
			}
		}
	}

	public function drop_records()
	{
		$register_name = $this->register_table_name;
		$this->wpdb->query("DELETE FROM $register_name");
	}
}