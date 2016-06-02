<?php

/**
 * Provides implementations for the DesktopServer Transfer API via custom endpoint.
 * This class is used when the XML RPC method is blocked.
 */
class DS_API_Xfer
{
	const CODE_SUCCESS = 0;						// general error code returned from API call
	const CODE_ERROR_AUTH = 1;					// cannot authenticate
	const CODE_ERROR_ACTION = 2;				// unrecognized action
	const CODE_CLEAN_DIR = 3;					// Unable to empty work directory
	const CODE_FILESYSTEM = 4;					// Unable to initialize compatible WP_Filesystem
	const CODE_CREATE_DIR = 5;					// Cannot create work folder
	const CODE_MISSING_PARAMETERS = 6;			// Request is missing parameters
	const CODE_OPEN_FILE = 7;					// Unable to open file
	const CODE_REMOVE = 8;						// Cannot remove folder
	const CODE_SESSION = 9;						// Invalid session
	const CODE_MISSING_FILE = 10;				// File is missing

	private $_ds_plugin = NULL;					// reference to DesktopServer instance
	private $_rel_dir = NULL;					// relative directory to use based on WP_Filesystem connection

	private static $_debug = TRUE;
	private static $_debug_output = FALSE;

	public function process($action, $ds_plugin)
	{
		$this->_ds_plugin = $ds_plugin;

		// make sure they're legit
		if (!$this->_authenticate_call())
			$this->_api_result(self::CODE_ERROR_AUTH, __('Unable to authenticate1', 'desktopserver'));

		// check session
/*		if ('details' !== $action) {
			@session_start();
			$id = isset($_POST['session_id']) ? $_POST['session_id'] : NULL;
			if (session_id() !== $id)
				$this->_api_result(self::CODE_SESSION, __('Invalid session.', 'desktopserver'));
		} */
		// check session life span

		// TODO: set max post size on 'begin' API call
//		@ini_set('post_max_size', '2M');

		switch ($action) {
		case 'details':	$this->_api_details();				break;
		case 'begin':	$this->_api_begin_xfer();			break;
		case 'send':	$this->_api_send_xfer();			break;
		case 'receive':	$this->_api_receive_xfer();			break;
		case 'filesent':$this->_api_filesent_xfer();		break;
		case 'complete':$this->_api_complete_xfer();		break;
		case 'process':	$this->_api_process();				break;
		case 'end':		$this->_api_end_xfer();				break;
		default:
			$this->_api_result(self::CODE_ERROR_ACTION, sprintf(__('Unrecognized action: %s', 'desktopserver'), $action));
		}
	}

	/**
	 * Performes authentication process for all DS_API calls. Uses <code>$_POST</code> data to obtain credentials
	 * @return boolean TRUE on successful authentication; otherwise FALSE
	 */
	private function _authenticate_call()
	{
self::log(__METHOD__.'()');
		$username = isset($_POST['username']) ? $_POST['username'] : '';
		$password = isset($_POST['password']) ? $_POST['password'] : '';
self::log('user=' . $username . ' pass=' . $password);

		if (!empty($username) && !empty($password)) {
			$creds = array(
				'user_login' => $username,
				'user_password' => $password,
				'remember' => FALSE);
			if (!is_wp_error($user = wp_signon($creds, FALSE)) &&
				($user->has_cap('update_core') || (isset($user->allcaps) && TRUE === $user->allcaps['update_core']))) {
				return TRUE;
			}
/*else {
	self::log('object: ' . get_class($user));
	self::log('is set: ' . (isset($user->allcaps) ? 'TRUE' : 'FALSE'));
	self::log('id: ' . $user->ID);
	self::log('user: ' . $user->data->user_login);
	self::log('caps: ' . var_export($user->allcaps, TRUE));
	self::log('update: ' . var_export($user->allcaps['update_core']));
	self::log('bad credentials ' . $user->get_error_message() . ' ' . var_export($user, TRUE));
}*/
		}
//else get_error_message('missing credentials');
		// purposefully do not give any indication as to why authentication failed.
		// doesn't matter if it's bad user, bad password or no permissions - they all just fail
		return FALSE;
	}

	/**
	 * Returns server details needed for DesktopServer deployment
	 */
	private function _api_details()
	{
self::log(__METHOD__.'()');
		$server_details = array();

		$wp_constants = array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST');
		foreach ($wp_constants as $constant) {
			$value = '';
			if (defined($constant)) {
				$value = constant( $constant );
			}
			$server_details[$constant] = $value;
		}

		$server_details['HOST'] = $this->_detect_host();
//		$server_details['SERVER'] = $_SERVER;

		global $wp_version;
		$server_details['DOCUMENT_ROOT'] = $this->_ds_plugin->doc_root;
		$server_details['DEPLOY_ROOT'] = $this->_ds_plugin->ds_deploy;
$dirs = wp_upload_dir();
self::log(__METHOD__.'() wp_upload_dirs = ' . var_export($dirs, TRUE));

		require_once(ABSPATH . '/wp-admin/includes/plugin.php');
		$pdata = get_plugin_data(dirname(__FILE__) . '/desktopserver.php');
		$server_details['DS_VERSION'] = $pdata['Version'];
		$server_details['WP_VERSION'] = $wp_version;
		$server_details['MAX_UPLOAD'] = wp_max_upload_size();

		// Test for compatible WP_Filesystem availability
		$fsm = '';
		$wp_filesystem = $this->_get_filesystem();
		if (defined('FS_METHOD')) {
			$fsm = constant('FS_METHOD');	// TODO: $fsm = FS_METHOD;
		} else {
			if (isset($wp_filesystem)) {
				if (isset($wp_filesystem->method)) {
					$fsm = $wp_filesystem->method;
				}
			}
		}
		$server_details['FS_METHOD'] = $fsm;

		if (defined('FTP_USER'))
			$server_details['FTP_USER'] = FTP_USER;
		if (defined('FTP_PASS'))
			$server_details['FTP_PASS'] = FTP_PASS;
		if (defined('FTP_HOST'))
			$server_details['FTP_HOST'] = FTP_HOST;

		// Save details to session for db_runnner.php
		if (!session_id())
			@session_start();
		$server_details['session_id'] = session_id();

		// include the API address
		$server_details['IP_ADDR'] = $_SERVER['REMOTE_ADDR'];

		// TODO: add wp-admin and wp-include directories

		$_SESSION['server_details'] = $server_details;

		$this->_api_result(self::CODE_SUCCESS, 'ok', $server_details);
	}

	/**
	 * Called to start a file transfer operation
	 */
	private function _api_begin_xfer()
	{
self::log(__METHOD__.'()');
		$wp_filesystem = $this->_get_filesystem();

		$dirs = wp_upload_dir();
self::log('upload dirs=' . var_export($dirs, TRUE));

		// Destory any prior deployment folder
		$dir = $this->_get_rel_dir();
self::log('Clearing directory: ' . $dir . ' ds-deploy: ' . $this->_ds_plugin->ds_deploy);
		$res = $wp_filesystem->rmdir($dir, TRUE);
		if (!$res) {
self::log(__METHOD__.'() unable to empty work directory');
//			$this->_api_result(self::CODE_CLEAN_DIR, __('Unable to empty work directory', 'desktopserver'));
		}

		if (!$wp_filesystem->mkdir($dir, FALSE)) { // was: 775?
self::log(__METHOD__.'() cannot create work folder');
//			$this->_api_result(self::CODE_CREATE_DIR, __('Cannot create work folder.', 'desktopserver'));
		}

		// TODO: create .htaccess file and index.php to block access

		$this->_api_result(self::CODE_SUCCESS, 'ok');
	}

	/**
	 * Handle sending of file to client
	 */
	private function _api_send_xfer()
	{
self::log(__METHOD__.'()');
		throw new Exception('not implemented');
	}

	/**
	 * Process file upload requests
	 */
	private function _api_receive_xfer()
	{
self::log(__METHOD__.'()');
		/**
		 * Parameters:
		 *	'file' - name of file to upload into wp-content/uploads/ds-deploy directory
		 *	'contents' - contents of file to upload
		 *	'chunk' - for 'chunked' files, the index 0..n of the chunk to write
		 *	'size' - the size of each chunk
		 */

		$wp_filesystem = $this->_get_filesystem();

		// Create our temp deployment folder if missing
		$dir = $this->_get_rel_dir();
self::log(__METHOD__.'() creating directory: ' . $dir);
		if (!$wp_filesystem->is_dir($dir)) {
			if (!$wp_filesystem->mkdir($dir, FALSE)) { // was: 0775
self::log(__METHOD__.'() cannot create work folder');
//				$this->_api_result(self::CODE_CREATE_DIR, __('Cannot create work folder.', 'desktopserver'));
			}
		}

		$file = isset($_POST['file']) ? $_POST['file'] : NULL;
		$contents = isset($_POST['contents']) ? stripslashes($_POST['contents']) : NULL;
		$chunk = isset($_POST['chunk']) ? intval($_POST['chunk']) : NULL;
		$size = isset($_POST['size']) ? absint($_POST['size']) : NULL;
		$target_dir = isset($_POST['dir']) ? $_POST['dir'] : NULL;
		// if provided and not empty, add the file's directory to where we're placing the file
		if (NULL !== $target_dir && !empty($target_dir))
			$dir = trailingslashit($dir) . $target_dir;

self::log(__METHOD__.'() file=' . $file . ' chunk=' . $chunk . ' size=' . $size . ' contents=' . substr($contents, 0, 20));
		if (NULL === $file || NULL === $contents)
			$this->_api_result(self::CODE_MISSING_PARAMETERS, __('Request is missing parameters.', 'desktopserver'));

		// build fully qualified file name
//		$filename = trailingslashit($dir) . $file;
//		$filename = trailingslashit($this->_ds_plugin->ds_deploy) . $file;
		$filename = trailingslashit($dir) . $file;
self::log(__METHOD__.'() full path name=' . $filename);
self::log(__METHOD__.'() path name=' . $file);

		// if file doesn't exist, create it via Filesystem class
self::log(__METHOD__.'() chdir to ' . $dir);
		$wp_filesystem->chdir($dir);
		if (!$wp_filesystem->exists($file)) {
self::log(__METHOD__.'() file does not exist - creating ' . $$filename);
			$res = $wp_filesystem->put_contents($file, 'ph');
self::log(__METHOD__.'() put_contents() returned ' . var_export($res, TRUE));
		}
		if (!$wp_filesystem->exists($file)) {
self::log(__METHOD__.'() file still does not exist');
			$this->_reset_permissions();
			$fh = fopen($filename, 'w+');
			if (FALSE !== $fh) {
				fwrite($fh, 'ph');
				fclose($fh);
			} else {
self::log(' - error opening file ');
$ret = file_exists(dirname($filename));
self::log(' - dir exists ' . var_export($ret, TRUE));
			}
		}
self::log(' - check if file exists ' . $file);
		if (!$wp_filesystem->exists($file) && 'wpengine' === $this->_detect_host()) {
self::log(' - file still does not exist and running on wpengine');
			if (!class_exists('WP_Filesystem_SSH2', FALSE))
				require_once(ABSPATH . '/wp-admin/includes/class-wp-filesystem-ssh2.php');
			// create a new filesystem object, forcing to use SFTP
			$args = array(
				'port' => 2222,
				'hostname' => 'djesch.wpengine.com',
				'username' => 'djesch',
				'password' => 'efRNo_6RPBY8Q_8k7',
			);
			$fs = new WP_Filesystem_SSH2($args);
self::log(' - created fs instance is ' . get_class($fs));
			$fs_dir = substr($dir, strpos($dir, '/wp-content'));
self::log(" - chdir('{$fs_dir}')");
			$fs->chdir($dir);
$fs_file = $fs_dir . '/' . $file;
			$ret = $fs->put_contents($fs_file, 'ph');
self::log(" - put_contents('{$fs_file}') returned " . var_export($ret, TRUE));
			$this->_reset_permissions();
		}
		if (!$wp_filesystem->exists($file)) {
self::log(__METHOD__.'() unable to create dbrunner file');
			$this->_api_result(self::CODE_MISSING_FILE, 'cannot create file');
		}
self::log(__METHOD__."() calling chmod('{$file}')");
		$wp_filesystem->chmod($file, FS_CHMOD_FILE); // was: 0775
/*		if (!$wp_filesystem->exists($filename)) {
self::log(__METHOD__.'() file does not exist - creating');
			$wp_filesystem->put_contents($filename, 'ph');
		} */

		// write data to file
self::log(__METHOD__.'() opening file for write: ' . $filename);
		$fh = fopen($filename, 'r+');
		if (FALSE === $fh)
			$this->_api_result(self::CODE_OPEN_FILE, __('Unable to open file.', 'desktopserver'));

		// adjust pointer for larger files
		if (NULL !== $chunk && NULL !== $size)
			fseek($fh, $chunk * $size);

		// write contents
		fwrite($fh, $contents);
		fclose($fh);

		$this->_api_result(self::CODE_SUCCESS, 'ok');
	}

	/**
	 * Called to notify server that a file has been sent via LFTP. Resets file permissions on file.
	 */
	private function _api_filesent_xfer()
	{
		$file = isset($_POST['file']) ? $_POST['file'] : NULL;
		if (NULL === $file || empty($file))
			$this->_api_result(self::CODE_MISSING_PARAMETERS, __('Request is missing parameters.', 'desktopserver'));

		$file = basename($file);
		$wp_filesystem = $this->_get_filesystem();
		$dir = $this->_get_rel_dir();

		$wp_filesystem->chdir($dir);
		if (!$wp_filesystem->exists($file)) {
self::log(__METHOD__.'() file does not exist - error' . $dir . $file);
			$this->_api_result(self::CODE_MISSING_FILE, __('File is missing.', 'desltopserver'));
		}
		$wp_filesystem->chmod($file, 0775);

		$this->_api_result(self::CODE_SUCCESS, 'ok');
	}

	/**
	 * Called to complete a file transfer operation
	 */
	private function _api_end_xfer()
	{
		$wp_filesystem = $this->_get_filesystem();

		// Destroy any prior deployment folder
		$dir = $this->_get_rel_dir();
self::log(__METHOD__.'() removing directory: ' . $dir);
		$res = $wp_filesystem->rmdir($dir, TRUE);
		if (!$res)
			$this->_api_result(self::CODE_REMOVE, __('Cannot remove folder.', 'desktopserver'));

		// TODO: destroy session

		$this->_api_result(self::CODE_SUCCESS, 'ok');
	}

	/**
	 * API call to reset file permissions before any processing is performed
	 */
	private function _api_complete_xfer()
	{
		$this->_reset_permissions();
	}

	/**
	 * Loads the specified file for execution
	 */
	private function _api_process()
	{
		$script = isset($_POST['script']) ? $_POST['script'] : NULL;

		if (NULL !== $script)
			$this->_api_result(self::CODE_MISSING_PARAMETERS, __('Request is missing parameters.', 'desktopserver'));

		$filename = trailingslashit($this->_ds_plugin->ds_deploy) . $script;
		if (!file_exists($filename))
			$this->_api_result(self::CODE_OPEN_FILE, __('Unable to open file.', 'desktopserver'));

		try {
			require_once($filename);
		} catch (Exception $ex) {
		}

		$this->_api_result(self::CODE_SUCCESS, 'ok');
	}

	/**
	 * Load the Filesystem class code and return an instance of it
	 * @return WP_Filesystem Instance of the Filesystem class
	 */
	private function _get_filesystem()
	{
		// load the filesystem code
		require_once(ABSPATH . '/wp-admin/includes/file.php');
		global $wp_filesystem;
		$args = array(
			'hostname' => 'deploy.postmy.info',
			'username' => 'spress-deploy',
			'password' => 'J3NeM4yx',
		);
		if (!WP_Filesystem($args)) {
$method = get_filesystem_method( $args, FALSE, FALSE);
//WP_Filesystem_FTPext
self::log(__METHOD__.'() method=' . $method);
			$this->_api_result(self::CODE_FILESYSTEM, __('Unable to initialize compatible WP_Filesystem.', 'desktopserver'));
		}
//self::log(__METHOD__.'() fs=' . var_export($wp_filesystem, TRUE));

		return $wp_filesystem;
	}

	/**
	 * Attempts to detect the hosting service that the DesktopServer plugin is running on
	 * @return string The hosting service name or 'standard' if the name cannot be specfically determined
	 */
	private function _detect_host()
	{
		if (defined('PWP_NAME') && defined('WPE_APIKEY'))
			return 'wpengine';
		if (isset($_SERVER['GD_PHP_HANDLER']) && isset($_SERVER['GD_ERROR_DOC']))
			return 'godaddy';

		return 'standard';
	}

	/**
	 * Get the path, relative to where we need to create our files
	 * @return string The path prefix for the ds-deploy/ directory
	 */
	private function _get_rel_dir()
	{
		if (NULL !== $this->_rel_dir)
			return $this->_rel_dir;

		global $wp_filesystem;
		// construct relative directory directory to ds-deploy/ based on connection type
		$inst = get_class($wp_filesystem);
self::log(__METHOD__.'() instance=' . $inst);
		switch ($inst) {
		case 'WP_Filesystem_FTPext':
			// workling via FTP - get path relative to where FTP logs in
			$dir = $this->_ds_plugin->ds_deploy;
			$pos = strpos($dir, 'wp-content');
			if (FALSE !== $pos) {
				$dir = substr($dir, 0, $pos - 1);
				$dir = dirname($dir);
				$this->_rel_dir = substr($this->_ds_plugin->ds_deploy, strlen($dir));
			} else {
				// punt
				$this->_rel_dir = dirname(dirname(dirname($this->_ds_plugin->ds_deploy)));
			}
			break;
		case 'WP_Filesystem_Direct':
			// working directly - get full path name
			$this->_rel_dir = $this->_ds_plugin->ds_deploy;
			break;
		default:
self::log(__METHOD__.'() unrecognized Filesystem type: ' . $inst);
			$this->_rel_dir = $this->_ds_plugin->ds_deploy;
		}
self::log(__METHOD__.'() returning ' . $this->_rel_dir);

		return $this->_rel_dir;
	}

	/**
	 * Used to output a result of the DS API call and exit
	 * @param int $code The success/error code. See CODE_* constants
	 * @param string $msg Error message to return to API client
	 * @param array $args Optional arguments to add to JSON data being returned
	 */
	private function _api_result($code, $msg, $args = array())
	{
self::log(__METHOD__.'() code=' . $code . ' - ' . $msg);
		header('Content-Type: application/json');
		if (self::CODE_SUCCESS !== $code) {
			header('HTTP/1.0 400 Error');
		}

		$data = array_merge(array(
			'code' => $code,
			'message' => $msg,
		), $args);
		echo json_encode($data);
		die;
	}

	/**
	 * Used to reset file permissions as necessary on different hosts
	 * @returns boolean TRUE on success; otherwise FALSE
	 */
	private function _reset_permissions()
	{
self::log(__METHOD__.'()');
		// TODO: use _detect_host()
		// if running on WP Engine hosting, reset the file permissions
		if (defined('PWP_NAME') && defined('WPE_APIKEY')) {
self::log(__METHOD__.'() found WPEngine environment');
			$url = 'https://api.wpengine.com/1.2/?method=file-permissions&account_name=' . PWP_NAME . '&wpe_apikey=' . WPE_APIKEY;
			$http = new WP_Http();
			$msg = $http->get($url);

			if (is_a($msg, 'WP_Error')) {
self::log(__METHOD__.'() failed; returned ' . var_export($msg, TRUE));
				return FALSE;
			}
			if (!isset($msg['body'])) {
self::log(__METHOD__.'() no body ' . var_export($msg, TRUE));
				return FALSE;
			}
self::log(__METHOD__.'() success');
			return TRUE;
		}

self::log(__METHOD__.'() no host specific environment found. Using WP_Filesystem to reset permissions');
		// use the WP_Filesystem class to reset permissions
//		$wp_filesystem = $this->_get_filesystem();
//		$dir = $this->_get_rel_dir();
//		$wp_filesystem->chmod($dir . '*', 0664, TRUE);
		return TRUE;
	}

	/**
	 * Perform logging for debugging purposes
	 * @param string $msg Message to log
	 * @param boolean $backtrace TRUE to log a backtrace; FALSE for no backtrace
	 */
	public static function log($msg, $backtrace = FALSE)
	{
return;
		if (self::$_debug_output)
			echo $msg, PHP_EOL;

//		if ((!defined('WP_DEBUG') || !WP_DEBUG) || !self::$_debug)
//			return;

		$file = dirname(__FILE__) . '/~log.txt';
		$fh = @fopen($file, 'a+');
		if (FALSE !== $fh) {
			if (NULL === $msg)
				fwrite($fh, current_time('Y-m-d H:i:s'));
			else
				fwrite($fh, current_time('Y-m-d H:i:s - ') . $msg . "\r\n");

			if ($backtrace) {
				$callers = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
				array_shift($callers);
				$path = dirname(dirname(dirname(plugin_dir_path(__FILE__)))) . DIRECTORY_SEPARATOR;

				$n = 1;
				foreach ($callers as $caller) {
					$func = $caller['function'] . '()';
					if (isset($caller['class']) && !empty($caller['class'])) {
						$type = '->';
						if (isset($caller['type']) && !empty($caller['type']))
							$type = $caller['type'];
						$func = $caller['class'] . $type . $func;
					}
					$file = isset($caller['file']) ? $caller['file'] : '';
					$file = str_replace('\\', '/', str_replace($path, '', $file));
					if (isset($caller['line']) && !empty($caller['line']))
						$file .= ':' . $caller['line'];
					$frame = $func . ' - ' . $file;
					$out = '    #' . ($n++) . ': ' . $frame . PHP_EOL;
					fwrite($fh, $out);
					if (self::$_debug_output)
						echo $out;
				}
			}

			fclose($fh);
		}
	}
}
