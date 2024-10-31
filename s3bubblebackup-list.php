<?php 

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

require_once('inc/functions.php');
$cfg = get_option('s3bubblebackup_options'); 
if(isset($_POST['s3bubble-delete-file'])) {
	$filepath = $cfg['export_dir'] . '/' . $_POST['filename'];
	if (file_exists($filepath)) {
		unlink($filepath);
	}
}
?>
<div class="wrap"> 
	<div id="poststuff">
        <div class="postbox">
            <h3 class="hndle"><span>Backup Protection Status</span></h3>
            <div class="inside">
                <p>
                    <strong>Your local directory filesize is <?php echo recursive_directory_size_backup($cfg['export_dir'],TRUE); ?>.</strong>
                </p>
                <p><span class="description">Click <strong>download button</strong> to download backup to your computer. Depending on your bandwidth, number of posts and server capabilities, an backup should be performed at a low-traffic hour.</span> All local backups will be removed every hour to avoid server load everything will be stored on Amazon S3.</p>
            </div>
        </div>
	</div>
	<h2>Amazon S3 Backups <small style="float: right;font-size: 14px;font-style: italic;">Bucket: <?php echo get_option('s3_bucket_name'); ?></small></h2>
	<?php
	if(!function_exists('curl_multi_exec') || !function_exists('curl_init')) {
		echo "This plugin requires PHP curl to connect to Amazon S3 please contact your hosting to install.";
		exit();
	}

	try {
		// Get the contents of our bucket
		$iterator = $client -> listObjects(array(
			'Bucket' => get_option('s3_bucket_name')
		));
		?>
		<table id="s3RemoteTable" class="widefat tablesorter"> 
			<thead>
				<tr>
					<th scope="col">#</th>
					<th>File (backup date/time)</th>
					<th>File (backup link/name)</th>
					<th>Filesize</th>
					<th>State</th>
					<th>Download</th>
				</tr>
			</thead>
			<tfoot>
				<tr>
					<th scope="col">#</th>
					<th>File (backup date/time)</th>
					<th>File (backup link/name)</th>
					<th>Filesize</th>
					<th>State</th>
					<th>Download</th>
				</tr>
			</tfoot>
			<?php
			if (isset($iterator['Contents'])) {
					$a = 0;
					foreach ($iterator['Contents'] as $object) {
						$file_parts = pathinfo($object['Key']);
						if(isset($file_parts['extension'])){
							if($file_parts['extension'] == 'sql' || $file_parts['extension'] == 'gz'){
								$fdate = explode("T", $object['LastModified']);	
								$fname = $object['Key'];
								$fsize = $object['Size'];
								$furl  = $client -> getObjectUrl(get_option('s3_bucket_name'), $fname, '+59 minutes');
					            ?>
					            <tr>
					                <td><?php echo ++$a; ?></td>
					                <td id="<?php echo strtotime($object['LastModified']); ?>"><?php echo date('D F j, Y', strtotime($fdate[0])); ?> <?php echo substr($fdate[1], 0, -5); ?></td>
					                <td><?php echo '<a href="' . $furl . '">' . $fname . '</a>'; ?></td>
					                <td><?php echo size_format($fsize, 2); ?></td>
					                <td>Private on Amazon</td>
					                <td><a href="<?php echo $furl; ?>" class="button">Download</a>
					            </tr>
	        <?php }}}} ?>
		</table>
		<?php
	}
	catch (Exception $e) {

		echo '<div id="message" class="updated fade"><p>'.$e -> getMessage().'</p></div>';
	} ?>
</div>
