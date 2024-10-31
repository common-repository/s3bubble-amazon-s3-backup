<?php

$whitelist = array(
    '127.0.0.1',
    '::1'
);

if(in_array($_SERVER['REMOTE_ADDR'], $whitelist)){
    // not valid
    echo '<div id="message" class="error fade"><p>!Important email settings may not work on localhost</p></div>';
}else{
	date_default_timezone_set(get_option('timezone_string'));
}

// Include AWS client and check for keys
$surl = S3BUBBLEBACKUP_PLUGIN_PATH . '/classes/vendor/autoload.php';	
if(!class_exists('S3Client'))
    require_once($surl);
    use Aws\S3\S3Client;

try {

	$client = S3Client::factory(array(
		'key' => get_option('s3backup_access_key'),
		'secret' => get_option('s3backup_secret_key')
	));
	$iterator = $client -> listBuckets();

}
catch (Exception $e) {
	echo '<div class="wrap"><div id="message" class="error fade"><p>ERROR: You haven\'t entered your AWS Keys please do this first. <a href="' . admin_url( "admin.php?page=s3bubble-amazon-s3-backup%2Fs3bubblebackup.php", false ) . '">Take me there now</a></p></div></div>';
	exit();
} 

$cfg = get_option('s3bubblebackup_options'); 
if(isset($_POST['s3bubblesubmit'])) {
	check_admin_referer('s3bubble_options');
	
	$temp['export_dir']		=	rtrim(stripslashes_deep(trim($_POST['export_dir'])), '/');
	$temp['compression']	=	stripslashes_deep(trim($_POST['compression']));
	$temp['gzip_lvl']		=	intval($_POST['gzip_lvl']);
	$temp['period']			=	intval($_POST['severy']) * intval($_POST['speriod']);
	$temp['period_files']	=	intval($_POST['fevery']) * intval($_POST['fperiod']);
	$temp['active']			=	isset($_POST['active']) ? $_POST['active'] : 0;
	$temp['active_files']	=	isset($_POST['active_files']) ? $_POST['active_files'] : 0;
	$temp['logs']			=	$cfg['logs'];
    $seconds                =   0;
	$timenow 				= 	time();
	$year 					= 	date('Y', $timenow);
	$month  				= 	date('n', $timenow);
	$day   					= 	date('j', $timenow);
	$hours   				= 	intval($_POST['hours']);
	$minutes 				= 	intval($_POST['minutes']);
	$temp['schedule'] 		= 	mktime($hours, $minutes, $seconds, $month, $day, $year);
	
	$fhours   				= 	intval($_POST['fhours']);
	$fminutes 				= 	intval($_POST['fminutes']);
	$temp['fschedule'] 		= 	mktime($fhours, $fminutes, $seconds, $month, $day, $year);

	update_option('s3bubblebackup_options', $temp);
	update_option('s3_bucket_name', $_POST['s3_bucket_name']);
	update_option('s3bubble_email', $_POST['s3bubble_email']);
	update_option('send_attachment', isset($_POST['send_attachment']) ? $_POST['send_attachment'] : 0);
	if($temp['active'] == 0)
		wp_clear_scheduled_hook('run_s3bubble_backup_database');
	else {
		wp_clear_scheduled_hook('run_s3bubble_backup_database');
		wp_schedule_event($temp['schedule'], 's3bubble_backup_database', 'run_s3bubble_backup_database');
	}
	if($temp['active_files'] == 0)
		wp_clear_scheduled_hook('run_s3bubble_backup_files');
	else {
		wp_clear_scheduled_hook('run_s3bubble_backup_files');
		wp_schedule_event($temp['fschedule'], 's3bubble_backup_files', 'run_s3bubble_backup_files');
	}
	$cfg = $temp;

	?><div id="message" class="updated fade"><p>Options saved!</p></div><?php
}
?>
<div class="wrap">
	<h2><div class="dashicons dashicons-cloud"></div> AWS Backup</h2>					
	<div class="postbox-container" style="width: 50%">
		<?php
			if(!function_exists('curl_multi_exec') || !function_exists('curl_init')) {
				echo "This plugin requires PHP curl to connect to Amazon S3 please contact your hosting to install.";
				exit();
			}
		?>
		<div class="metabox-holder">
			<div class="postbox s3bubble-enhance">
				<h3 class="hndle"><span>App Settings</span></h3>
				<div class="inside">
					<form method="post" action="" class="s3bubble-backup-forms"> 
						<?php wp_nonce_field('s3bubble_options');?>
                        <p>
                        	<label for="s3_bucket_name">Amazon S3 Bucket Name</label><br>
                        	
                        	<?php
							try {
								$iterator = $client -> listBuckets(array());
								$buckets = $iterator->toArray();
								echo '<select name="s3_bucket_name" class="s3bubble-backup-select"><option value="' . get_option('s3_bucket_name') . '">' . get_option('s3_bucket_name') . '</option>';
								if (isset($buckets['Buckets'])) {
									foreach ($buckets['Buckets'] as $object) { ?>
										<option value="<?php echo $object['Name']; ?>" <?php //if(get_option('auto_amazon') == 1) echo 'selected'; ?>><?php echo $object['Name']; ?></option>								
									<?php }
								}
								echo '</select>';
							}
							catch (Exception $e) {
								echo '<input type="text" placeholder"Amazon S3 Bucket Name" value="' . get_option('s3_bucket_name') . '" name="s3_bucket_name" class="s3bubble-backup-input" />';
							} ?>
                        </p>
                        <hr>
						<h4>General Settings</h4>
                        <p style="display: none;">
                            <input type="hidden" name="export_dir" id="export_dir" value="<?php echo esc_attr($cfg['export_dir']); ?>" class="regular-text">
                            <br><small>All your backups will be saved here. Default is <?php echo WP_CONTENT_DIR . '/s3bubblebackups'; ?></small>
                        </p>
                        <p>
                        	<label for="s3bubble_email">Notification email</label><br>
                            <input type="email" name="s3bubble_email" id="s3bubble_email" value="<?php echo get_option('s3bubble_email'); ?>" class="s3bubble-backup-input">
                            <br><small>You will receive notification messages at this address.</small>
                        </p>
                        <p>
                            <?php
                            $none_selected = ($cfg['compression'] == 'none') ? 'selected' : '';
                            $gz_selected = ($cfg['compression'] == 'gz') ? 'selected' : '';
                            ?>
                            <select name="compression" id="compression">
                                <option value="none" <?php echo $none_selected;?>>None</option>
                                <?php if(function_exists('gzopen')) { ?> <option value="gz" <?php echo $gz_selected; ?>>GZIP</option> <?php } ?>
                            </select> 
                            <?php if(function_exists('gzopen')) { ?>
                                <select name="gzip_lvl">
                                    <?php
                                    for($i = 1; $i <= 9; $i++) {
                                        $selected = ($cfg['gzip_lvl'] == $i) ? 'selected' : '';
                                        ?>
                                        <option value="<?php echo $i; ?>" <?php echo $selected; ?>>Use GZIP compression level <?php echo $i;?></option>
                                    <?php } ?>
                                </select>
                            <?php } ?>
                        </p>
                        <p>
                        	<label for="active">Database backup schedule settings</label><hr>
                            <input type="checkbox" name="active" value="1" <?php echo ($cfg['active'] ? 'checked' : ''); ?>> Activate database backup schedule<br>
                            <input type="checkbox" name="send_attachment" value="1"<?php echo (get_option('send_attachment') ? ' checked' : ''); ?> /> Send backup as attachment<br>
                            <p>
                            <?php 
                            list($hours, $minutes, $seconds) = explode('-', date('H-i-s', $cfg['schedule']));
                            $times = array('hours', 'minutes');
                            $periods = array(3600 => 'Hour(s)', 86400 => 'Day(s)', 604800 => 'Week(s)', 2592000 => 'Month(s)');

                            $tmonth	= $cfg['period'] / 2592000;
                            $tweek	= $cfg['period'] / 604800;
                            $tday	= $cfg['period'] / 86400;
                            $thour	= $cfg['period'] / 3600;

                            if(is_int($tmonth) 		&& $tmonth > 0) 	{ $speriod = 2592000;  $severy	= $tmonth; }
                            elseif(is_int($tweek) 	&& $tweek > 0)		{ $speriod = 604800;   $severy	= $tweek; }
                            elseif(is_int($tday) 	&& $tday > 0)		{ $speriod = 86400;    $severy	= $tday; }
                            elseif(is_int($thour)	&& $thour > 0)		{ $speriod = 3600;     $severy	= $thour; }
                            ?>
                            <strong>Run every</strong>
                            <select name="severy" id="severy">
                                <?php for($i = 1; $i <= 12; $i++) { $selected = ($severy == $i) ? 'selected' : ''; ?>
                                    <option <?php echo $selected; ?>><?php echo $i; ?></option>
                                <?php } ?>
                            </select> 
                            <select name="speriod" id="speriod"> 
                                <?php
                                foreach($periods as $period => $display) {
                                    $selected = ($period == $speriod) ? 'selected' : ''; ?>
                                    <option value="<?php echo $period; ?>" <?php echo $selected; ?>><?php echo $display; ?></option>
                                <?php } ?>
                            </select>
                            <span id="database-time-section">
	                            <strong>At</strong> 
	                            <?php
	                            foreach($times as $time) {
	                                $max = $time == 'hours' ? 24 : 60;
	                                ?>: <select name="<?php echo $time; ?>" id="database-<?php echo $time; ?>">
	                                    <?php for($i = 0; $i<$max; $i++) { $selected = ($$time == $i) ? 'selected' : ''; ?>
	                                        <option <?php echo $selected; ?>><?php echo (($i < 10) ? '0' . $i : $i ); ?></option>
	                                    <?php } ?>
	                                </select>
	                            <?php } ?>
                            </span>
                            <span id="database-time-info"></span>
                            </p>
                        </p>
                        <p>
                        	<label for="active_files">Files backup schedule settings</label><hr>
                            <input type="checkbox" name="active_files" value="1" <?php echo isset($cfg['active_files']) ? 'checked' : ''; ?>> Activate files backup schedule
                            <p>
                            <?php 
                            list($fhours, $fminutes, $fseconds) = explode('-', date('H-i-s', $cfg['fschedule']));
                            $ftimes = array('fhours', 'fminutes');
                            $fperiods = array( 86400 => 'Day(s)', 604800 => 'Week(s)', 2592000 => 'Month(s)');

                            $fmonth	= $cfg['period_files'] / 2592000;
                            $fweek	= $cfg['period_files'] / 604800;
                            $fday	= $cfg['period_files'] / 86400;
                            $fhour	= $cfg['period_files'] / 3600;

                            if(is_int($fmonth) 		&& $fmonth > 0) 	{ $fperiod = 2592000;  $fevery	= $fmonth; }
                            elseif(is_int($fweek) 	&& $fweek > 0)		{ $fperiod = 604800;   $fevery	= $fweek; }
                            elseif(is_int($fday) 	&& $fday > 0)		{ $fperiod = 86400;    $fevery	= $fday; }
                            elseif(is_int($fhour)	&& $fhour > 0)		{ $fperiod = 3600;     $fevery	= $fhour; }
                            ?>
                            <strong>Run every </strong>
                            <select name="fevery" id="fevery"> 
                                <?php for($i = 1; $i <= 12; $i++) { $selected = ($fevery == $i) ? 'selected' : ''; ?>
                                    <option <?php echo $selected; ?>><?php echo $i; ?></option>
                                <?php } ?>
                            </select> 
                            <select name="fperiod" id="fperiod"> 
                                <?php
                                foreach($fperiods as $period => $display) {
                                    $selected = ($period == $speriod) ? 'selected' : ''; ?>
                                    <option value="<?php echo $period; ?>" <?php echo $selected; ?>><?php echo $display; ?></option>
                                <?php } ?>
                            </select>
                            <span id="filesystem-time-section">
                            <strong>At</strong> 
                            <?php
                            foreach($ftimes as $time) {
                                $max = $time == 'hours' ? 24 : 60;
                                ?>: <select name="<?php echo $time; ?>" id="filesystem-<?php echo $time; ?>">
                                    <?php for($i = 0; $i<$max; $i++) { $selected = ($$time == $i) ? 'selected' : ''; ?>
                                        <option <?php echo $selected; ?>><?php echo (($i < 10) ? '0' . $i : $i ); ?></option>
                                    <?php } ?>
                                </select>
                            <?php } ?>
                            </span>
                            <span id="filesystem-time-info"></span>
                            </p>
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
	<div class="postbox-container" style="width: 50%">
		<div class="metabox-holder">
			<div class="postbox" style="margin: 0 0 0 8px;">
				<h3 class="hndle"><span>Options Checks</span></h3>
				<div class="inside">
				    <ul>
				    	<li>Current server time:<strong><?php echo date('F j, Y, H:i:s'); ?></strong></li>
				    <?php
						if(wp_get_schedule('run_s3bubble_backup_database')){
				            echo '<li><strong>Database scheduling is active!</strong><i class="wp-menu-image dashicons-before dashicons-yes" style="color: green;"></i></li>';
							$format_scheduled_backup_database = date('F j, Y, H:i:s', wp_next_scheduled('run_s3bubble_backup_database'));
							echo '<li>Your next database backup will run at: <strong>' . $format_scheduled_backup_database . '</strong></li>';
					    }else{
					    	echo '<li><strong>Database scheduling is not active!</strong><i class="wp-menu-image dashicons-before dashicons-no" style="color: red;"></i></li>';
					    }
					?>
					</ul>
					<ul>
					<?php
						if(wp_get_schedule('run_s3bubble_backup_files')){
				            echo '<li><strong>Filesystem scheduling is active!</strong><i class="wp-menu-image dashicons-before dashicons-yes" style="color: green;"></i></li>';
							$format_scheduled_backup_files = date('F j, Y, H:i:s', wp_next_scheduled('run_s3bubble_backup_files'));
							echo '<li>Your next filesystem backup will run at: <strong>' . $format_scheduled_backup_files . '</strong></li>';
						}else{
							echo '<li><strong>Filesystem scheduling is not active!</strong><i class="wp-menu-image dashicons-before dashicons-no" style="color: red;"></i></li>';
						}
					?>
					</ul>	
				</div>
			</div>	
		</div>
	</div>
</div>