<?php
/*
Plugin Name: Amazon S3 Scheduled Cloud Backup
Plugin URI: http://samueleast.co.uk
Description: The Backup Plugin offers you a totally controllable one stop way to store your data securely and ensure you webpage is safe.
Version: 0.5
Author: 
Author URI: http://samueleast.co.uk
Text Domain: backup
*/

if(!defined('WP_S3BUBBLEBACKUP_OPTIONS')) 				define('WP_S3BUBBLEBACKUP_OPTIONS', 's3bubblebackup_options');
if(!defined('WP_S3BUBBLEBACKUP_SCHEDULED_BACKUPS')) 	define('WP_S3BUBBLEBACKUP_SCHEDULED_BACKUPS', 'WP_S3BUBBLEBACKUP_SCHEDULED_BACKUPS');

define('S3BUBBLEBACKUP_PLUGIN_URL', WP_PLUGIN_URL . '/' . dirname(plugin_basename(__FILE__)));
define('S3BUBBLEBACKUP_PLUGIN_PATH', WP_PLUGIN_DIR . '/' . dirname(plugin_basename(__FILE__)));
define('WP_S3BUBBLEBACKUP_VERSION', '1.9.1');
//

function wp_s3bubblebackup_menu() {
	add_menu_page('AWS Backup', 'AWS Backup', 'manage_options', __FILE__, 'wp_s3bubblebackup_display_settings_page');
	add_submenu_page(__FILE__, 'Backup All', 'Backup All', 'manage_options', S3BUBBLEBACKUP_PLUGIN_PATH . '/s3bubblebackup-all.php');
	add_submenu_page(__FILE__, 'Backup Options', 'Backup Options', 'manage_options', S3BUBBLEBACKUP_PLUGIN_PATH . '/s3bubblebackup-options.php');
	add_submenu_page(__FILE__, 'Backup Database', 'Backup Database', 'manage_options', S3BUBBLEBACKUP_PLUGIN_PATH . '/s3bubblebackup-database.php');
	add_submenu_page(__FILE__, 'Backup Files', 'Backup Files', 'manage_options', S3BUBBLEBACKUP_PLUGIN_PATH . '/s3bubblebackup-files.php');
	add_submenu_page(__FILE__, 'Backup List', 'Backup List', 'manage_options', S3BUBBLEBACKUP_PLUGIN_PATH . '/s3bubblebackup-list.php');
}

add_action('admin_menu', 'wp_s3bubblebackup_menu');

function wp_s3bubblebackup_display_settings_page() {
	include(S3BUBBLEBACKUP_PLUGIN_PATH.'/s3bubblebackup-keys.php');
}

register_activation_hook( __FILE__, 's3bubblebackup_install');

function s3bubblebackup_install() {
	$backup_options = array();

	$backup_options['export_dir'] = WP_CONTENT_DIR.'/awsbackups';
	$backup_options['compression'] = 'none';
	$backup_options['gzip_lvl'] = 0;
	$backup_options['period'] = 86400;
	$backup_options['period_files'] = 86400;
	$backup_options['schedule'] = time();
	$backup_options['active'] = 0;

	$backup_options['speriod'] = 'daily';
	$backup_options['fperiod'] = 'daily';

	// BEGIN Amazon S3 options
	$backup_options['s3backup_access_key'] = '';
	$backup_options['s3backup_secret_key'] = '';
	$backup_options['s3backup_bucket_name'] = '';
	$backup_options['auto_amazon'] = 0;
	// END Amazon S3 options

	// BEGIN email options
	$backup_options['s3bubble_email'] = '';
	$backup_options['send_attachment'] = 0;
	// END email options

	add_option(WP_S3BUBBLEBACKUP_OPTIONS, $backup_options);
	
	//set hourly schedule to remove backups
	wp_schedule_event( time(), 'hourly', 's3bubble_backup_clear_local_backups' );
	
}

add_action('run_s3bubble_backup_database', 's3bubblebackup_run_database');
add_action('run_s3bubble_backup_files', 's3bubblebackup_run_files');
add_action( 'admin_head', 's3bubble_backup_css_admin');
add_action( 'admin_head', 's3bubble_backup_js_admin');

/*
 * Include the function for the menu item css fix
 */ 
function s3bubble_backup_css_admin(){
	wp_register_style( 's3bubble-backup-css', plugins_url('assets/css/styles.css', __FILE__) );
	wp_enqueue_style('s3bubble-backup-css');
}

/*
 * Include javascript to the admin section of the plugin
 */ 
function s3bubble_backup_js_admin(){
	wp_register_script( 's3bubble-backup-stupidtable-js', plugins_url('assets/js/stupidtable.min.js', __FILE__) );
	wp_enqueue_script('s3bubble-backup-stupidtable-js');
	wp_register_script( 's3bubble-backup-scripts-js', plugins_url('assets/js/scripts.js', __FILE__) );
	wp_enqueue_script('s3bubble-backup-scripts-js');
}

use Aws\S3\S3Client;

/*
 * Backs all files
 * @author sam
 * @params
 */
function s3bubblebackup_backup_all($mode = 'auto') {
    global $wpdb;
	// increased resources for slow servers or large databases
	@ini_set('memory_limit','256M'); // 256 megabytes
	@ini_set('max_execution_time', 600); // 300 seconds = 5 minutes
	@date_default_timezone_set(get_option('timezone_string'));
	// end resource increase

	if(defined('S3BUBBLE_BACKUP_RETURN')) return;
	$cfg = get_option('s3bubblebackup_options'); 
	if(!$cfg['active'] and $mode == 'auto') return;
	if(empty($cfg['export_dir'])) return;
	
	require_once('inc/functions.php');
	define('S3BUBBLE_COMPRESSION', $cfg['compression']);
	define('S3BUBBLE_GZIP_LVL', $cfg['gzip_lvl']);
	define('S3BUBBLE_BACKUP_RETURN', true);

	$BackupName = sanitize_title( get_bloginfo( 'name' ), "s3bubble" );
    
    // Clear the backup directory
	s3bubble_backup_clear_backup_folder();
	
	$timenow 			= 	time();
	$mtime 				= 	explode(' ', microtime());
    $time_start 		= 	$mtime[1] + $mtime[0];
	$key 				= 	substr(md5(md5(DB_NAME.'|'.microtime())), 0, 6);
	$date 				= 	date('m.d.y-H.i.s', $timenow);
	$upload_info = wp_upload_dir();			// Array of upload directory info
	$upload_dir = $upload_info['basedir'];	// base path to /uploads without trailing/
	$upload_url = $upload_info['baseurl'];	// url to /uploads without trailing/
	$content_url = content_url();
	$admin_url  = admin_url();
	$wp_version = get_bloginfo('version');	// Blog Version
	$timestamp = current_time('timestamp');	// Current time

	list($file, $fp) 	=	s3bubblebackup_open($cfg['export_dir'].'/Backup_'.$date.'_'.$key);
    $file = WP_CONTENT_DIR . '/Backup_' . $date . '_' . $key . '.sql';
	
	if($file) {

        s3bubblebackup_exec($file, 'backup');

		$backup_file_name = $BackupName . '_'.date('Y_m_d_H_i_s', $timestamp).'_wordpress_v'.$wp_version.'.tar.gz';
		if(function_exists('exec')) {
			chdir($upload_dir);
			chdir("../../");
			exec('tar -czf '.$backup_file_name.' --exclude="awsbackups*" wp-content/ && mv '.$backup_file_name.' wp-content/awsbackups');
		}else{
			Zip(WP_CONTENT_DIR . '/', WP_CONTENT_DIR . '/awsbackups/' . $backup_file_name);
		}
		if (file_exists($file)) {
				unlink($file);
		}

		$url = WP_CONTENT_URL . '/awsbackups/' . $backup_file_name;
        $result = 'Your wp-content folder and database has been successfully backed up - <a href="' . $url . '">Download backup now!</a>';

	}
	else {
		$result = "Failed to open: " . $fp . "s.";
	}
	$mtime 			= 	explode(' ', microtime());
	$time_end 		= 	$mtime[1] + $mtime[0];
	$time_total 	= 	$time_end - $time_start;
	$cfg['logs'][] 	= 	array ('file' => $file, 'size' => @filesize($file), 'started' => $timenow, 'took' => $time_total, 'status'	=> $result);					
	update_option('s3bubblebackup_options', $cfg);
	return $result;
}


/*
 * Backs up the database
 * @author sam
 * @params
 */
function s3bubblebackup_run_database($mode = 'auto') {
    global $wpdb;
	// increased resources for slow servers or large databases
	@ini_set('memory_limit','256M'); // 256 megabytes
	@ini_set('max_execution_time', 600); // 300 seconds = 5 minutes
	@date_default_timezone_set(get_option('timezone_string'));
	// end resource increase

	if(defined('S3BUBBLE_BACKUP_RETURN')) return;
	$cfg = get_option('s3bubblebackup_options'); 
	if(!$cfg['active'] and $mode == 'auto') return;
	if(empty($cfg['export_dir'])) return;
	
	require_once('inc/functions.php');
	define('S3BUBBLE_COMPRESSION', $cfg['compression']);
	define('S3BUBBLE_GZIP_LVL', $cfg['gzip_lvl']);
	define('S3BUBBLE_BACKUP_RETURN', true);
    
    // Clear the backup directory
	s3bubble_backup_clear_backup_folder();
	
	$timenow 			= 	time();
	$mtime 				= 	explode(' ', microtime());
    $time_start 		= 	$mtime[1] + $mtime[0];
	$key 				= 	substr(md5(md5(DB_NAME.'|'.microtime())), 0, 6);
	$date 				= 	date('m.d.y-H.i.s', $timenow);
	list($file, $fp) 	=	s3bubblebackup_open($cfg['export_dir'].'/Backup_'.$date.'_'.$key);
    $file = $cfg['export_dir'] . '/Backup_' . $date . '_' . $key . '.sql';
	
	if($file) {
		//@set_time_limit(0);

        s3bubblebackup_exec($file, 'backup');

		$amazon_automation = get_option('auto_amazon');
		if($amazon_automation == 0) {
			$result = 'Successful';
		}
		// edited by sam auto upload force
		$surl = S3BUBBLEBACKUP_PLUGIN_PATH . '/classes/vendor/autoload.php';	
		if(!class_exists('S3Client'))
	        require_once($surl);

		$client = S3Client::factory(array(
			'key' => get_option('s3backup_access_key'),
			'secret' => get_option('s3backup_secret_key')
		));

		$bucket = get_option('s3_bucket_name');
		try {
			
			$return = $client->putObject(array(
			    'Bucket' => $bucket,
			    'Key'    => basename($fp),
			    'SourceFile' => $fp
			));
			
			// send attached mysql to user
			$whitelist = array(
			    '127.0.0.1',
			    '::1'
			);

			if(!in_array($_SERVER['REMOTE_ADDR'], $whitelist)){
				$send_attachment = get_option('send_attachment');
				if($send_attachment == 1) {
					$s3bubble_email = get_option('s3bubble_email');
					$s3bubble_subject = get_bloginfo() . ' ' . date('m/d/y H:i:s', $timenow) . ' new database backup available!';
					$headers = "From: support@s3bubble.com\r\n";
					$headers .= "Reply-To: support@s3bubble.com\r\n";
					wp_mail($s3bubble_email, $s3bubble_subject, 'AWS Backup a new backup is now available!', $headers, array($fp));
				}
			}

            if (file_exists($file)) {
				unlink($file);
			}
			$url = admin_url( 'admin.php?page=s3bubble-amazon-s3-backup/s3bubblebackup-list.php');
            $result = 'Successfully backed up and uploaded - <a href="' . $url . '">View backup now!</a>';
		}
		catch (Exception $e) {
            if (file_exists($file)) {
				unlink($file);
			}
			$url = admin_url( 'admin.php?page=s3bubble-amazon-s3-backup/s3bubblebackup.php');
	        $result = 'ERROR: ' . $e -> getMessage() . ' - <a href="' . $url . '">Please check details!</a>';
		}

	}
	else {
		$result = "Failed to open: " . $fp . "s.";
	}
	$mtime 			= 	explode(' ', microtime());
	$time_end 		= 	$mtime[1] + $mtime[0];
	$time_total 	= 	$time_end - $time_start;
	$cfg['logs'][] 	= 	array ('file' => $file, 'size' => @filesize($file), 'started' => $timenow, 'took' => $time_total, 'status'	=> $result);					
	update_option('s3bubblebackup_options', $cfg);
	return $result;
}

/*
 * Back the contents of the wp-content folder
 * @author sam
 * @params
 */
function s3bubblebackup_run_files($mode = 'auto') {
    global $wpdb;
	// increased resources for slow servers or large databases
	@ini_set('memory_limit','256M'); // 256 megabytes
	@ini_set('max_execution_time', 600); // 300 seconds = 5 minutes
	@date_default_timezone_set(get_option('timezone_string'));
	// end resource increase
	if(defined('S3BUBBLE_BACKUP_RETURN')) return;

	// Clear the backup directory
	s3bubble_backup_clear_backup_folder();
	
	$timenow 			= 	time();
    $mtime 				= 	explode(' ', microtime());
    $time_start 		= 	$mtime[1] + $mtime[0];

	$upload_info = wp_upload_dir();			// Array of upload directory info
	$upload_dir = $upload_info['basedir'];	// base path to /uploads without trailing/
	$upload_url = $upload_info['baseurl'];	// url to /uploads without trailing/
	$content_url = content_url();
	$admin_url  = admin_url();
	$wp_version = get_bloginfo('version');	// Blog Version
	$timestamp = current_time('timestamp');	// Current time
	$backup_file_name = 'backup_'.date('Y_m_d_H_i_s', $timestamp).'_wordpress_v'.$wp_version.'.tar.gz';
	if(function_exists('exec')) {
		chdir($upload_dir);
		chdir("../../");
		exec('tar -czf '.$backup_file_name.' --exclude="awsbackups*" wp-content/ && mv '.$backup_file_name.' wp-content/awsbackups');
	}else{
		Zip(WP_CONTENT_DIR . '/', WP_CONTENT_DIR . '/awsbackups/' . $backup_file_name);
	}
	/*
	 * Upload files to Amazon S3
	 */
	$surl = S3BUBBLEBACKUP_PLUGIN_PATH . '/classes/vendor/autoload.php';	
		if(!class_exists('S3Client'))
	        require_once($surl);

	$client = S3Client::factory(array(
		'key' => get_option('s3backup_access_key'),
		'secret' => get_option('s3backup_secret_key')
	));

	$bucket = get_option('s3_bucket_name');
	$cfg = get_option('s3bubblebackup_options'); 
	$filename = $cfg['export_dir'] . '/' . $backup_file_name;
	try {

		$return = $client->putObject(array(
		    'Bucket' => get_option('s3_bucket_name'),
		    'Key'    => basename($filename),
		    'SourceFile' => $filename
		));
		
		$furl  = $client -> getObjectUrl(get_option('s3_bucket_name'), basename($filename), '+59 minutes');
		
		// send attached mysql to user
		$whitelist = array(
		    '127.0.0.1',
		    '::1'
		);
		if(!in_array($_SERVER['REMOTE_ADDR'], $whitelist)){
			$s3bubble_email = get_option('s3bubble_email');
			$s3bubble_subject = get_bloginfo() . ' ' . date('m/d/y H:i:s', $timenow) . ' new filesystem backup available!';
			$headers = "From: support@s3bubble.com\r\n";
			$headers .= "Reply-To: support@s3bubble.com\r\n";
			$headers .= "Content-type: text/html\r\n";
			wp_mail($s3bubble_email, $s3bubble_subject, 'A new filesystem backup is now available! <a href="' . $furl . '">Download Backup</a> This link will expire in 59 minutes.', $headers);
		}
		if (file_exists($filename)) {
			unlink($filename);
		}
		$url = admin_url( 'admin.php?page=s3bubble-amazon-s3-backup/s3bubblebackup-list.php');
        $result = 'Successfully backed up and uploaded - <a href="' . $url . '">View backup now!</a>';
		
		
	}
	catch (Exception $e) {
		if (file_exists($filename)) {
			unlink($filename);
		}
		$url = admin_url( 'admin.php?page=s3bubble-amazon-s3-backup/s3bubblebackup.php');
        $result = 'ERROR: ' . $e -> getMessage() . ' - <a href="' . $url . '">Please check details!</a>';

	}
	$mtime 			= 	explode(' ', microtime());
	$time_end 		= 	$mtime[1] + $mtime[0];
	$time_total 	= 	$time_end - $time_start;
	$cfg['logs'][] 	= 	array ('file' => $filename, 'size' => @filesize($filename), 'started' => $timenow, 'took' => $time_total, 'status'	=> $result);					
	update_option('s3bubblebackup_options', $cfg);
	return $result;
}

/*
 * Zip fallback if exec is not installed
 * @author sam
 * @params sourec destination
 */ 
function Zip($source, $destination){
    if (!extension_loaded('zip') || !file_exists($source)) {
        return false;
    }

    $zip = new ZipArchive();
    if (!$zip->open($destination, ZIPARCHIVE::CREATE)) {
        return false;
    }

    $source = str_replace('\\', '/', realpath($source));

    if (is_dir($source) === true)
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($files as $file)
        {
            $file = str_replace('\\', '/', $file);

            // Ignore "." and ".." folders
            if( in_array(substr($file, strrpos($file, '/')+1), array('.', '..')) )
                continue;

            $file = realpath($file);

            if (is_dir($file) === true)
            {
                $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
            }
            else if (is_file($file) === true)
            {
                $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
            }
        }
    }
    else if (is_file($source) === true)
    {
        $zip->addFromString(basename($source), file_get_contents($source));
    }

    return $zip->close();
}

add_filter('cron_schedules', 's3bubblebackup_interval');

function s3bubblebackup_interval() {
	$cfg = get_option('s3bubblebackup_options');
	$cfg['period'] = ($cfg['period'] == 0) ? 86400 : $cfg['period'];
	$cfg['period_files'] = ($cfg['period_files'] == 0) ? 86400 : $cfg['period_files'];
	return array(
		's3bubble_backup_database' => array(
			'interval' => $cfg['period'],
			'display' => 'AWS Backup Interval - '.($cfg['period']/60).' minutes'
		),
		's3bubble_backup_files' => array(
			'interval' => $cfg['period_files'],
			'display' => 'AWS Backup Interval - '.($cfg['period_files']/60).' minutes'
		)
	);
}

/*
* S3Bubble run a file backup
* @author sameast
* @none
*/
add_action( 'wp_ajax_s3bubble_backup_files_internal', 's3bubble_backup_files_internal_callback' );

function s3bubble_backup_files_internal_callback(){
	
	// Run security check
	check_ajax_referer( 's3bubble-nonce-security', 'security' );

	if(isset($_POST)) {
		echo json_encode(s3bubblebackup_run_files());
	}else{
		echo json_encode("There has been a error please contact support@s3bubble.com.");
	}

	wp_die();	
	
}

/*
* S3Bubble run a backup of all files
* @author sameast
* @none
*/
add_action( 'wp_ajax_s3bubble_backup_all_internal', 's3bubble_backup_all_internal_callback' );

function s3bubble_backup_all_internal_callback(){
	
	// Run security check
	check_ajax_referer( 's3bubble-nonce-security', 'security' );

	if(isset($_POST)) {
		echo json_encode(s3bubblebackup_backup_all('backupnow'));
	}else{
		echo json_encode("There has been a error please contact support@s3bubble.com.");
	}

	wp_die();	
	
}

/*
* S3Bubble run a sql backup
* @author sameast
* @none
*/
add_action( 'wp_ajax_s3bubble_backup_sql_internal', 's3bubble_backup_sql_internal_callback' );

function s3bubble_backup_sql_internal_callback(){
	
	// Run security check
	check_ajax_referer( 's3bubble-nonce-security', 'security' );

	if(isset($_POST)) {
		echo json_encode(s3bubblebackup_run_database('backupnow'));
	}else{
		echo json_encode("There has been a error please contact support@s3bubble.com.");
	}

	wp_die();	
	
}

/*
 * Clear all ajax functions
 */
add_action( 'wp_ajax_s3bubble_backup_clear_all', 's3bubble_backup_clear_all_callback' );

/*
* S3Bubble cleans out the backup folder
* @author sameast
* @none
*/
function s3bubble_backup_clear_backup_folder() {

    $cfg = get_option('s3bubblebackup_options');
	$files = glob( $cfg['export_dir'] . '/*'); // get all file names
	foreach($files as $file){ // iterate files
		$path_parts = pathinfo($file);
	    if(is_file($file) && $path_parts['basename'] != 'index.html' && $path_parts['extension'] == 'sql' || $path_parts['extension'] == 'gz'){
	    	unlink($file); 
	    }
	}

}

function s3bubble_backup_clear_all_callback() {

    $cfg = get_option('s3bubblebackup_options');
	$files = glob( $cfg['export_dir'] . '/*'); // get all file names
	foreach($files as $file){ // iterate files
		$path_parts = pathinfo($file);
	    if(is_file($file) && $path_parts['basename'] != 'index.html' && $path_parts['extension'] == 'sql' || $path_parts['extension'] == 'gz'){
	    	unlink($file); 
	    }
	}
	echo json_encode($files);
	die(); // this is required to return a proper result
}

add_action( 's3bubble_backup_clear_local_backups', 's3bubble_backup_clear_local_backups_hourly' );

/**
 * On the scheduled action hook, run the function.
 */
function s3bubble_backup_clear_local_backups_hourly() {
	// do something every hour
    $cfg = get_option('s3bubblebackup_options');
	$files = glob( $cfg['export_dir'] . '/*'); // get all file names
	foreach($files as $file){ // iterate files
		$path_parts = pathinfo($file);
	    if(is_file($file) && $path_parts['basename'] != 'index.html' && $path_parts['extension'] == 'sql' || $path_parts['extension'] == 'gz'){
	    	unlink($file); 
	    }
	}
	echo json_encode($files);
	
}
?>
