<?php
/*
Plugin Name: DesktopServer for WordPress
Plugin URI: http://serverpress.com/products/desktopserver/
Description: DesktopServer for WordPress eases localhost to live server deployment by publishing hosting provider server details via a protected XML-RPC feed to an authorized administrator only. It also provides assisted deployments to hosting providers that support file system direct. For more information, please visit http://serverpress.com/.
Author: Stephen Carnam
Author URI: http://ServerPress.com/
Version: 1.6.1
Text Domain: desktopserver
*/

/**
 * @package DesktopServer for WordPress
 * @version 1.6.1
 */
class DesktopServer
{
	public $ds_deploy;
	public $auth_error;
	public $doc_root;

	const ENDPOINT_NAME = 'desktopserver_api';		// an underscore is less likely to have naming collisions

	public function __construct()
	{
		// setup 'old school' XML RPC connections
		add_filter('xmlrpc_methods', array($this, 'xmlrpc_methods'));

		// setup 'new school' custom endpoint connections
		add_action('init', array(&$this, 'register_api'), 1000);
		add_filter('request', array(&$this, 'endpoint_set_query_var'));
		add_action('template_redirect', array(&$this, 'check_endpoint'));
require_once(dirname(__FILE__) . '/class-ds-api-xfer.php');
DS_API_Xfer::log('starting...');
DS_API_Xfer::log(' url=' . $_SERVER['REQUEST_URI']);

		// TODO: add cron process to delete the ds-deploy directory if the files are more than 4 hours old

		// TODO: I think the following can be moved into a function that's called only after authentication
		if (isset($_SERVER['REAL_DOCUMENT_ROOT'])) {
			$this->doc_root = $_SERVER['REAL_DOCUMENT_ROOT'];
		} else {
			if (isset($_SERVER['SUBDOMAIN_DOCUMENT_ROOT'])) {
				$this->doc_root = $_SERVER['SUBDOMAIN_DOCUMENT_ROOT'];
			} else {
				$this->doc_root = $_SERVER['DOCUMENT_ROOT'];
			}
		}

		$dirs = wp_upload_dir();
		$this->ds_deploy = trailingslashit($dirs['basedir']) . 'ds-deploy';
		$this->error = '';
	}

	/**
	 * Callback for 'xmlrpc_methods' filter. Adds the DesktopServer methods to the list
	 * @param array $methods Array of available methods
	 * @return array Modified list of available methods
	 */
	public function xmlrpc_methods($methods)
	{
		$methods['ds_get_server_details'] = array($this, 'ds_get_server_details');
		$methods['ds_receive_xfer'] = array($this, 'ds_receive_xfer');
		$methods['ds_begin_xfer'] = array($this, 'ds_begin_xfer');
		$methods['ds_end_xfer'] = array($this, 'ds_end_xfer');
		return $methods;
	}

	/**
	 * Callback for the 'init' method. Adds the custom endpoint used by the DesktopServer API
	 */
	public function register_api()
	{
DS_API_Xfer::log(__METHOD__.'()');
		add_rewrite_endpoint(self::ENDPOINT_NAME, EP_ROOT);
	}

	public function endpoint_set_query_var(array $vars)
	{
DS_API_Xfer::log(__METHOD__.'()');
		if (!empty($vars[self::ENDPOINT_NAME]))
			return $vars;

		// If a static page is set as front page, the WP endpoint API does strange things. This attempts to fix that.
		if (isset($vars[self::ENDPOINT_NAME]) ||
			(isset($vars['pagename']) && self::ENDPOINT_NAME === $vars['pagename']) ||
			(isset($vars['page']) && isset($vars['name']) && self::ENDPOINT_NAME === $vars['name'])) {
			// in some cases WP misinterprets the request as a page request and returns a 404
			$vars['page'] = $vars['pagename'] = $vars['name'] = FALSE;
			$vars[self::ENDPOINT_NAME] = TRUE;
		}
		return $vars;
	}

	/**
	 * Callback for the 'template_redirect' action. Checks the query variables, looking for the 'desktopserver_api' endpoint
	 */
	public function check_endpoint()
	{
DS_API_Xfer::log(__METHOD__.'()');

		$api = get_query_var(self::ENDPOINT_NAME);
DS_API_Xfer::log('api=' . var_export($api, TRUE));
		if ('' === $api) {
DS_API_Xfer::log('returning!');
			return;
		}
DS_API_Xfer::log('authenticating');
		// we have a DesktopServer API request

		$action = isset($_POST['action']) ? $_POST['action'] : '';
		$api = new DS_API_Xfer();
DS_API_Xfer::log('action: ' . $action);
		$api->process($action, $this);
DS_API_Xfer::log('***returned from process()');
		exit;	// process() should exit, this is a just in case
	}

	/**
	 * Authenticates user and password information for the request
	 * @param string $user Username to authenticate
	 * @param string $pass Password to authenticate
	 * @return boolean TRUE if username and password authenticate; otherwise FALSE
	 */
	private function authenticate($user, $pass)
	{
		// reset value of ds_deploy directory for (old) XMLRPC API calls
		$this->ds_deploy = $this->doc_root . '/ds-deploy';

		// Authenticate the user
		global $wp_xmlrpc_server;
		if ( !$wp_xmlrpc_server->login( $user, $pass) ) {
			$this->auth_error = $wp_xmlrpc_server->error;
			return FALSE;
		}

		// Check user capability for core admin rights
		if ( !current_user_can('update_core')) {
			$this->auth_error = array( 'code' => 403,
				'message' => 'Error - User does not have update core capability.');
			return FALSE;
		}
		return TRUE;
	}

	/**
	 * Returns information on server configuration details
	 * @param array $args Array containing authentication information
	 * @return Array Server details returned in an associative array
	 */
	public function ds_get_server_details( $args )
	{
		// Check user credentials
		if ( !$this->authenticate( array_shift($args), array_shift($args) ) ) {
			return $this->auth_error;
		}

		// Return server details needed for DesktopServer deployment
		$wp_constants = array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST');
		$server_details = array();
		foreach ($wp_constants as $constant) {
			$value = '';
			if ( defined( $constant ) ) {
				$value = constant( $constant );
			}
			$server_details[$constant] = $value;
		}
		global $wp_version;
		$server_details['DOCUMENT_ROOT'] = $this->doc_root;
		$pdata = get_plugin_data(__FILE__);
		$server_details['DS_VERSION'] = $pdata['Version'];
		$server_details['WP_VERSION'] = $wp_version;
		$server_details['MAX_UPLOAD'] = wp_max_upload_size();

		// Test for compatible WP_Filesystem availability
		$fsm = '';
		WP_Filesystem();
		global $wp_filesystem;
		if ( defined( 'FS_METHOD' ) ){
			$fsm = constant( 'FS_METHOD' );
		} else {
			if ( isset( $wp_filesystem ) ){
				if ( isset( $wp_filesystem->method )){
					$fsm = $wp_filesystem->method;
				}
			}
		}
		$server_details['FS_METHOD'] = $fsm;

		// Save details to session for db_runnner.php
		if ( !session_id() )
			@session_start();
		$server_details['session_id'] = session_id();
		$_SESSION['server_details'] = $server_details;
		return $server_details;
	}

	/**
	 * Process upload request data
	 * @param array $args Array containing authentication information
	 * @return string A string containing 'ok' on success or a string starting with 'Error' on failure
	 */
	public function ds_receive_xfer( $args )
	{
		// Check user credentials
		if ( !$this->authenticate( array_shift($args), array_shift($args) ) ) {
			return $this->auth_error;
		}

		// Validate WP_Filesystem availability
		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			return 'Error - Unable to initialize compatible WP_Filesystem.';
		}

		// Create our temp deployment folder if missing
		if (!$wp_filesystem->is_dir( $this->ds_deploy ) ) {
			// first time through. directory doesn't exist so create it and copy database_runner
			if (!$wp_filesystem->mkdir( $this->ds_deploy ) ) {
				return 'Error - Cannot create ds-deploy folder. ';
			}

			// Precopy our current database_runner.php
			$wp_filesystem->copy( dirname( __FILE__ ) . '/database_runner.no-execute', $this->ds_deploy . '/database_runner.php' , true );
			$wp_filesystem->chmod( $this->ds_deploy . '/database_runner.php', FS_CHMOD_FILE );
		}

		// Process data chunks using WP_Filesystem where ever possible
		$temp = $this->ds_deploy . '/temp-ds-deploy';
		while ( 0 !== count($args)) {
			$file = $this->ds_deploy . array_shift($args);
			$is_zip = array_shift($args);
			$data = array_shift($args);

			// Decompress any compressed data chunks
			if ( $is_zip ) {
				// Write chunk to temp file
				$wp_filesystem->put_contents( $temp . '.zip', $data );

				// Unzip chunk
				$result = unzip_file( $temp . '.zip', $this->ds_deploy );
				if ( is_wp_error($result) ){
					return 'Error decompressing. ' . $result->get_error_message();
				}
				$data = $wp_filesystem->get_contents( $temp );
			}

			// Create path if it doesn't exist (WP_Filesystem doesn't do recursive paths)
			// TODO: wouldn't basename() do the same thing??
			$path = $this->delRightMost($file, '/');
			$path = str_replace('/', DIRECTORY_SEPARATOR, $path);
			if ( !is_dir( $path )) {
				if ( !mkdir( $path, FS_CHMOD_DIR, TRUE ) ) {
					return 'Error - Cannot create folder path. ';
				}
			}

			// Ignore old database_runner.php and use our new plugin version
			if ( FALSE === strpos( $file, 'database_runner.php' ) ) {
				// Create/append data to our file
				// (WP_Filesystem get/put would use too much memory for large file appending)
				$fh = fopen( $file, 'a' );
				if ( FALSE === $fh ){
					return 'Error opening ' . $file;
				}
				if ( FALSE === fwrite( $fh, $data ) ) {
					return 'Error writing to ' . $file;
				}
				fclose( $fh );
				// TODO: use get_contents() and put_contents() via Filesystem
				$wp_filesystem->chmod($file, FS_CHMOD_FILE);
			}
		}

		// Remove our subfolder temp files
		$wp_filesystem->delete( $temp . '.zip' );
		$wp_filesystem->delete( $temp );
		return 'ok';
	}

	/**
	 * Called to start a file transfer operation
	 * @param array $args User credentials to authenticate
	 * @return string The string 'ok' on success or a string starting with 'Error' on error
	 */
	public function ds_begin_xfer( $args )
	{
		// Check user credentials
		if ( !$this->authenticate( array_shift($args), array_shift($args) ) ) {
			return $this->auth_error;
		}

		// Validate WP_Filesystem availability
		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			return 'Error - Unable to initialize compatible WP_Filesystem.';
		}

		// Destory any prior deployment folder
		$wp_filesystem->rmdir( $this->ds_deploy, TRUE );

		return 'ok';
	}

	/**
	 * Called to complete a file transfer operation
	 * @param array $args The user credentials to authenticate
	 * @return string The string 'ok' on success; otherwise a string starting with 'Error'
	 */
	public function ds_end_xfer( $args )
	{
		// Check user credentials
		if ( !$this->authenticate( array_shift($args), array_shift($args) ) ) {
			return $this->auth_error;
		}

		// TODO: should we move WP_Filesystem check to the ds_begin_xfer call and abort if it's not available?
		// Validate WP_Filesystem availability
		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			return 'Error - Unable to initialize compatible WP_Filesystem.';
		}

		// Move files into place
		foreach ( $wp_filesystem->dirlist( $this->ds_deploy ) as $fi) {
			// check for the database.sql file and don't move it
			if ( 'database.sql' !== $fi['name'] ) {
				$src = $this->ds_deploy . '/' . $fi['name'];
				$dest = ABSPATH . $fi['name'];
				if ($wp_filesystem->is_dir( $dest )) {
					$wp_filesystem->rmdir( $dest, TRUE );
				}
				$wp_filesystem->move( $src, $dest, TRUE );
			}
		}

		// Execute 3.5.8 and older databases, if present... these users will still get warnings.
		if ( $wp_filesystem->is_file( $this->ds_deploy . '/database.sql' ) ) {
			$res = $this->_process_sql($this->_ds_deploy . '/database.sql');
			if ('ok' !== $res)
				return $res;
		}

		// Destroy any prior deployment folder
		$wp_filesystem->rmdir( $this->ds_deploy, TRUE );

		return 'ok';
	}

	/**
	 * Processes SQL file, running commands on the server
	 * @param string $file Full path to the .sql file to execute
	 * @return string The string 'ok' on success; otherwise a string starting with 'Error'
	 */
	private function _process_sql($file)
	{
		// TODO: the file is on the filesystem now, can we switch to fgets()??
		// TODO: using fgets() would allow us to run the .sql file in chunks so large transfers are more reliable
		$buffer = $wp_filesystem->get_contents( $this->ds_deploy . '/database.sql' );
		if ( FALSE === $buffer ) {
			return 'Error - Unable to get database.sql contents.';
		}

		// Connect to database and import our script with adapted phpMyAdmin parser v3.5.7
		$dbc = mysql_connect( DB_HOST, DB_USER, DB_PASSWORD, TRUE );
		if ( !$dbc ) {
			return 'Error - Could not connect: ' . mysql_error();
		}
		mysql_select_db( DB_NAME );

		//
		// *** Set up prerequisite to use phpMyAdmin's SQL Import plugin
		//

		if ( FALSE === mysql_query( 'SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";' ) ) {
			return 'Error - ' . mysql_error();
		}
		$GLOBALS['finished'] = TRUE;

		// Defaults for parser
		$sql = '';
		$start_pos = 0;
		$i = 0;
		$len = 0;
		$big_value = 2147483647;
		$delimiter_keyword = 'DELIMITER '; // include the space because it's mandatory
		$length_of_delimiter_keyword = strlen($delimiter_keyword);
		$sql_delimiter = ';';

		// Current length of our buffer
		$len = strlen($buffer);

		// Grab some SQL queries out of it
		while ($i < $len) {
			$found_delimiter = FALSE;

			// Find first interesting character
			$old_i = $i;
			// this is about 7 times faster that looking for each sequence i
			// one by one with strpos()
			if (preg_match('/(\'|"|#|-- |\/\*|`|(?i)(?<![A-Z0-9_])' . $delimiter_keyword . ')/', $buffer, $matches, PREG_OFFSET_CAPTURE, $i)) {
				// in $matches, index 0 contains the match for the complete
				// expression but we don't use it
				$first_position = $matches[1][1];
			} else {
				$first_position = $big_value;
			}
			/**
			 * @todo we should not look for a delimiter that might be
			 *		inside quotes (or even double-quotes)
			 */
			// the cost of doing this one with preg_match() would be too high
			$first_sql_delimiter = strpos($buffer, $sql_delimiter, $i);
			if (FALSE === $first_sql_delimiter) {
				$first_sql_delimiter = $big_value;
			} else {
				$found_delimiter = TRUE;
			}

			// set $i to the position of the first quote, comment.start or delimiter found
			$i = min($first_position, $first_sql_delimiter);

			if ($i == $big_value) {
				// none of the above was found in the string

				$i = $old_i;
				if (!$GLOBALS['finished']) {
					break;
				}
				// at the end there might be some whitespace...
				if ('' === trim($buffer)) {
					$buffer = '';
					$len = 0;
					break;
				}
				// We hit end of query, go there!
				$i = strlen($buffer) - 1;
			}

			// Grab current character
			$ch = $buffer[$i];

			// Quotes
			if (FALSE !== strpos('\'"`', $ch)) {
				$quote = $ch;
				$endq = FALSE;
				while (!$endq) {
					// Find next quote
					$pos = strpos($buffer, $quote, $i + 1);
					/*
					 * Behave same as MySQL and accept end of query as end of backtick.
					 * I know this is sick, but MySQL behaves like this:
					 *
					 * SELECT * FROM `table
					 *
					 * is treated like
					 *
					 * SELECT * FROM `table`
					 */
					if (FALSE === $pos && '`' === $quote && $found_delimiter) {
						$pos = $first_sql_delimiter - 1;
					// No quote? Too short string
					} else if (FALSE === $pos) {
						// We hit end of string => unclosed quote, but we handle it as end of query
						if ($GLOBALS['finished']) {
							$endq = TRUE;
							$i = $len - 1;
						}
						$found_delimiter = FALSE;
						break;
					}
					// Was not the quote escaped?
					$j = $pos - 1;
					while ($buffer[$j] == '\\')
						--$j;
					// Even count means it was not escaped
					$endq = (((($pos - 1) - $j) % 2) === 0);
					// Skip the string
					$i = $pos;

					if ($first_sql_delimiter < $pos) {
						$found_delimiter = FALSE;
					}
				}
				if (!$endq) {
					break;
				}
				++$i;
				// Aren't we at the end?
				if ($GLOBALS['finished'] && $i == $len) {
					--$i;
				} else {
					continue;
				}
			}

			// Not enough data to decide
			if ((($i == ($len - 1) && ('-' === $ch || '/' === $ch)) ||
				($i == ($len - 2) && (('-' === $ch && '-' === $buffer[$i + 1]) ||
				('/' === $ch && '*' === $buffer[$i + 1])))) && !$GLOBALS['finished']) {
				break;
			}

			// Comments
			if ('#' === $ch ||
				($i < ($len - 1) && '-' === $ch && '-' === $buffer[$i + 1] &&
				(($i < ($len - 2) && $buffer[$i + 2] <= ' ') ||
				($i === ($len - 1) && $GLOBALS['finished']))) ||
				($i < ($len - 1) && '/' === $ch && '*' === $buffer[$i + 1])) {
				// Copy current string to SQL
				if ($start_pos != $i) {
					$sql .= substr($buffer, $start_pos, $i - $start_pos);
				}
				// Skip the rest
				$start_of_comment = $i;
				// do not use PHP_EOL here instead of "\n", because the export
				// file might have been produced on a different system
				$i = strpos($buffer, ('/' === $ch ? '*/' : "\n"), $i);
				// didn't we hit end of string?
				if (FALSE === $i) {
					if ($GLOBALS['finished']) {
						$i = $len - 1;
					} else {
						break;
					}
				}

				// Skip *
				if ('/' === $ch) {
					++$i;
				}
				// Skip last char
				++$i;
				// We need to send the comment part in case we are defining
				// a procedure or function and comments in it are valuable
				$sql .= substr($buffer, $start_of_comment, $i - $start_of_comment);
				// Next query part will start here
				$start_pos = $i;
				// Aren't we at the end?
				if ($i == $len) {
					--$i;
				} else {
					continue;
				}
			}

			// Change delimiter, if redefined, and skip it (don't send to server!)
			if (strtoupper(substr($buffer, $i, $length_of_delimiter_keyword)) === $delimiter_keyword &&
				($i + $length_of_delimiter_keyword < $len)) {
				// look for EOL on the character immediately after 'DELIMITER '
				// (see previous comment about PHP_EOL)
				$new_line_pos = strpos($buffer, "\n", $i + $length_of_delimiter_keyword);
				// it might happen that there is no EOL
				if (false === $new_line_pos) {
					$new_line_pos = $len;
				}
				$sql_delimiter = substr($buffer, $i + $length_of_delimiter_keyword, $new_line_pos - $i - $length_of_delimiter_keyword);
				$i = $new_line_pos + 1;
				// Next query part will start here
				$start_pos = $i;
				continue;
			}

			// End of SQL
			if ($found_delimiter || ($GLOBALS['finished'] && ($i === $len - 1))) {
				$tmp_sql = $sql;
				if ($start_pos < $len) {
					$length_to_grab = $i - $start_pos;

					if (! $found_delimiter) {
						++$length_to_grab;
					}
					$tmp_sql .= substr($buffer, $start_pos, $length_to_grab);
					unset($length_to_grab);
				}
				// Do not try to execute empty SQL
				if (! preg_match('/^([\s]*;)*$/', trim($tmp_sql))) {
					$sql = $tmp_sql;

					//
					// Execute on our connection
					//
					if ( FALSE === mysql_query( $sql ) ){
						return 'Error - ' . mysql_error();
					}
					$buffer = substr($buffer, $i + strlen($sql_delimiter));

					// Reset parser:
					$len = strlen($buffer);
					$sql = '';
					$i = 0;
					$start_pos = 0;
					// Any chance we will get a complete query?
					//if ((strpos($buffer, ';') === false) && !$GLOBALS['finished']) {
					if ((FALSE === strpos($buffer, $sql_delimiter)) && !$GLOBALS['finished']) {
						break;
					}
				} else {
					++$i;
					$start_pos = $i;
				}
			}
		} // End of parser loop

		mysql_close( $dbc );
		return 'ok';
	}

	/**
	 * Removes everything after the search string within the source string, including the search string
	 * @param string $sSource The source string to modify and return a portion of
	 * @param string $sSearch The string to search for within the source string
	 * @return string Everything up to, but not including the last occurance of the search string
	 */
    private function delRightMost( $sSource, $sSearch )
	// TODO: replace with use of dirname()
	{
        for ( $i = strlen( $sSource ); $i >= 0; $i = $i - 1 ) {
            $f = strpos( $sSource, $sSearch, $i );
            if ( FALSE !== $f ) {
                return substr( $sSource, 0, $f );
                break;
            }
        }
        return $sSource;
    }
}

// Attempt to bump up meager memory hosts
// TODO: this should only be done after we know we're running one of the XML RPC commands
if ((int) @ini_get('memory_limit') < 64) {
    if (FALSE !== strpos(ini_get('disable_functions'), 'ini_set')) {
        @ini_set('memory_limit', '64M');
    }
}
@set_time_limit( 600 );
new DesktopServer();
