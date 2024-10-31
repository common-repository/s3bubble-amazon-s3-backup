<?php

// Set the default timezone
if(!empty(get_option('timezone_string'))){
	date_default_timezone_set(get_option('timezone_string'));
}

$surl = S3BUBBLEBACKUP_PLUGIN_PATH . '/classes/vendor/autoload.php';	
$awse = "";
if(!class_exists('S3Client'))
    require_once($surl);
    use Aws\S3\S3Client;

$cfg = get_option('s3bubblebackup_options'); 
if(isset($_POST['s3bubblesubmit'])) {
	check_admin_referer('s3bubble_options');

	update_option('s3backup_access_key', $_POST['s3_access_key']);
	update_option('s3backup_secret_key', $_POST['s3_secret_key']);
	
	/*
	 * Run a cunnection test
	 */
	$client = S3Client::factory(array(
		'key' => get_option('s3backup_access_key'),
		'secret' => get_option('s3backup_secret_key')
	));
	try {
		$iterator = $client -> listBuckets();
		$buckets = $iterator->toArray();
		$awse = '<span style="color:green;">You are successfully connected.</span>';
	}
	catch (Exception $e) {
		$awse = '<span style="color:red;">ERROR: '.$e -> getMessage().'</span>';
	} 
	?><div id="message" class="updated fade"><p>Options saved! - <?php echo $awse; ?></p></div><?php
}
?>
<div class="wrap">
	<h2>AWS Backup</h2>					
	<div class="postbox-container" style="width: 50%">
		<?php
			if(!function_exists('curl_multi_exec') || !function_exists('curl_init')) {
				echo "This plugin requires PHP curl to connect to Amazon S3 please contact your hosting to install.";
				exit();
			}
		?>
		<div class="metabox-holder">
			<div class="postbox s3bubble-enhance">
				<h3 class="hndle"><span>Please enter your AWS keys before we get started</span></h3>
				<div class="inside">
					<form method="post" action="" class="s3bubble-backup-forms"> 
						<?php wp_nonce_field('s3bubble_options');?>
                        <p>
                        	<label for="s3_access_key">AWS Access Key</label><br>
                            <input name="s3_access_key" id="s3_access_key" placeholder="App Access Key" value="<?php echo get_option('s3backup_access_key'); ?>" class="s3bubble-backup-input" type="text">
                            
                        </p>
                        <p>
                        	<label for="s3_secret_key">AWS Secret Key</label><br>
                            <input name="s3_secret_key" id="s3_secret_key" placeholder="App Secret Key" value="<?php echo get_option('s3backup_secret_key'); ?>" class="s3bubble-backup-input" type="password">
                            
                        </p>
                        <hr>
                        <p>
                            <input type="submit" name="s3bubblesubmit" class="button button-primary button-hero" value="Save Changes">
                        </p>
					</form>
				</div>
			</div>
		</div>
	</div>
	<div class="postbox-container" style="width: 50%"></div>
</div>