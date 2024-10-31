
<?php

//Run a security check
$ajax_nonce = wp_create_nonce( "s3bubble-nonce-security" );
require_once('inc/functions.php');
$cfg = get_option('s3bubblebackup_options'); 

?>
<div class="wrap">
	<h2><div class="dashicons dashicons-cloud"></div>Backup WP-CONTENT folder and DATABASE</h2>	
	<?php
	if(!function_exists('curl_multi_exec') || !function_exists('curl_init')) {
		echo "This plugin requires PHP curl to connect to Amazon S3 please contact your hosting to install.";
		exit();
	}

	?>				
	<div class="postbox-container" style="width: 50%">
		<div class="metabox-holder">
			<div class="postbox" style="margin: 0;">
				<h3 class="hndle"><span>Backup database & wp-content folder</span></h3>
				<div class="inside s3bubble-running-backup">
					<form method="post" id="s3bubble-backup-backingup-sql-form">
						<?php wp_nonce_field('s3bubble_quickdo');?>
						<p><input type="submit" name="submit_s3bubble_now" class="button button-primary button-hero" value="Create a full backup now!" /></p>
						<p><span class="description">Click the button above to create a full backup of your database and wp-content folder for you to instantly download no AWS connection needed for this setting.</span></p>
					</form>
				</div>
			</div> 
		</div>
	</div>
	<div class="postbox-container" style="width: 50%">
		
		<div class="metabox-holder">
			<div class="postbox" style="margin: 0 0 0 8px;">
				<h3 class="hndle"><span>Diagnostics</span></h3>
				<div class="inside">
					<p class="s3bubble-checks-p"><strong>S3Bubble</strong> will now run some checks to make sure your backups will complete successfully.</p>
					<ul class="s3bubble-checks-list">
						<?php echo recursive_directory_size(WP_CONTENT_DIR,TRUE,'s3bubblebackups'); ?>
						<?php echo database_size(); ?>
						<li><?php echo implode('</li><li>', system_files()); ?>
						<?php echo gzip_enabled(); ?>
						<?php echo scheduling_active(); ?>
						<?php echo memory_limit(); ?>
					</ul>
				</div>
			</div>
		</div>
	</div>
</div>
<script type="text/javascript">
	jQuery(document).ready(function($) {
		// Run debug form
		$( "#s3bubble-backup-backingup-sql-form" ).submit(function( event ) {
			$(".s3bubble-running-backup").append("<div class='s3bck'><img src='<?php echo plugins_url( 'assets/images/ajax_loader.gif', __FILE__ ); ?>' /><h2>Running backup please wait do not refresh...</h2></div>");
			$("#s3bubble-backup-backingup-sql-form").slideUp();
			var sendData = {
				action: 's3bubble_backup_all_internal',
				security: '<?php echo $ajax_nonce; ?>'
			}	
			$.post("<?php echo admin_url('admin-ajax.php'); ?>", sendData, function(response) {
				$(".s3bck").html("<h2>" + response + "</h2>");
			},'json');
		  	event.preventDefault();
		});

	});
</script>