<?php
/**
 * Plugin Name: Lock Login
 * Description: Block attempts to bruteforce your site.
 * Author: TocinoDev
 * Author URI: https://tocino.mx
 * Version: 0.1.7
 * Tested up to: 6.1
 * Requires PHP: 7.4
*/
use Locklogin\App as LockloginApp;

defined('ABSPATH') || exit;

if(!defined('LOCKLOGIN_FILE'))
	define('LOCKLOGIN_FILE', __FILE__);
if(!defined('LOCKLOGIN_URL'))
	define('LOCKLOGIN_URL', plugin_dir_url(LOCKLOGIN_FILE));

require 'vendor/autoload.php';

LockloginApp::boot();