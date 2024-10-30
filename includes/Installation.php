<?php
namespace Locklogin;

class Installation
{
	public static function install()
	{
		$db = new DB();
		$db->create_tables();
		if(!wp_next_scheduled('locklogin_cron')){
			// add the cron job
			wp_schedule_event(time(), 'locklogin20', 'locklogin_cron');
		}
	}

	public static function uninstall()
	{
		$db = new DB();
		$db->drop_tables();
		// clean the cron job
		wp_clear_scheduled_hook('locklogin_cron');
	}
}