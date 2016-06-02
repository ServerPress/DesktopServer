<?php
/*
 
Author: Stephen Carnam
Version: 1.0
License: GPLv2
Author URI: http://serverpress.com

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

*/

// Our database_runner app will move files into their correct positions followed by
// processing the database in sequential chunks...

// Attempt to bump up meager memory hosts
if((int) @ini_get('memory_limit') < 64){
    if(strpos(ini_get('disable_functions'), 'ini_set') === false){
        @ini_set('memory_limit', '64M'); 
    }
}
@set_time_limit( 600 );

if ( ! isset($_GET['session_id']) || ! isset($_GET['operation']) ){
    echo 'Invalid session or operation.';
    exit();
}else{
    
    // Recover session from DesktopServer for WordPress plugin
    session_id($_GET['session_id']);
    session_start();
    if ( ! isset($_SESSION['server_details']) ){
        echo 'Missing server_details.';
        exit();
    }
}
global $server_details;
$server_details = $_SESSION['server_details'];

// Move files into position
if ( $_GET['operation'] == 'move_files' ){
    $src = $server_details['DOCUMENT_ROOT'] . '/ds-deploy';
    $dst = rtrim( $server_details['DOCUMENT_ROOT'], '/' );
    $files = scandir ( $src );
    foreach ( $files as $file ){
        if ($file != "." && $file != ".." && 
				strpos( $file, '/ds-deploy/.htaccess' ) === false &&
                strpos( $file, 'database_runner.php' ) === false  &&
                substr( $file, -4 ) != '.sql' ){
            rmove ( "$src/$file", "$dst/$file" );
        }
    }
    echo 'ok';
    exit();
}

// Execute sequential database file
if ( $_GET['operation'] == 'database' ){
    $src = $server_details['DOCUMENT_ROOT'] . '/ds-deploy';
    $files = scandir ( $src );
    
    // Find first file iteration
    for ($n = 1; $n < 999; $n++){
        foreach ( $files as $file ){
            if ( substr( $file, 0, 8) == 'database' && substr( $file, -4) == '.sql' ){
                
                // Process the file and remove it
                import_sql( $src . '/' . $file );
                rrmdir( $src . '/' . $file );
                echo 'ok';
                exit();
            }
        }
    }
}

// Cleanup ds-deploy folder
if ( $_GET['operation'] == 'cleanup' ){
	
	// Move .htaccess last
	if ( is_file( $server_details['DOCUMENT_ROOT'] . '/ds-deploy/.htaccess' ) ) {
		rmove( $server_details['DOCUMENT_ROOT'] . '/ds-deploy/.htaccess', $server_details['DOCUMENT_ROOT'] . '/.htaccess' );
	}
	
	// Remove ds-deploy
    $src = $server_details['DOCUMENT_ROOT'] . '/ds-deploy';
    rrmdir( $src );
    echo 'ok';
    exit();
}
    
// Function to remove folders and files 
function rrmdir($dir) {
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file)
            if ($file != "." && $file != "..") rrmdir("$dir/$file");
        rmdir($dir);
    }
    else if (file_exists($dir)) unlink($dir);
}

// Function to move folders and files       
function rmove($src, $dst) {
    if (file_exists ( $dst ))
        rrmdir ( $dst );
    if (is_dir ( $src )) {
        
        // Try rename first
        if ( ! @rename($src, $dst) ){
                        
            // Otherwise make destination folder and xfer file by file
            mkdir ( $dst );
            $files = scandir ( $src );
            foreach ( $files as $file )
                if ($file != "." && $file != "..")
                    rmove ( "$src/$file", "$dst/$file" );
        }
    } elseif (file_exists ( $src )){
        copy ( $src, $dst );
        @unlink( $src );
    }
}

// Import the givn database sql file
function import_sql( $file ){
    global $server_details;
    $buffer = @file_get_contents( $file );
    
    // Accelerate imports on newer MySQL stacks
    $buffer = "SET NAMES utf8;\n SET autocommit=0;\n SET foreign_key_checks=0;\n SET unique_checks=0;\n SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n " . $buffer;
    $buffer .= "\n COMMIT;\n SET unique_checks=1;\n; SET foreign_key_checks=1;\n";
    if ( false === $buffer ){
        echo 'Error - Unable to get database.sql contents.';
        exit();
    }

    // Connect to database and import our script with adapted phpMyAdmin parser v3.5.7
    $dsn = 'mysql:host=' . $server_details['DB_HOST'];
    $dsn .= ';dbname=' . $server_details['DB_NAME'];
    try {
 	   $dbc = new PDO( $dsn, $server_details['DB_USER'], $server_details['DB_PASSWORD'] );
 	   $dbc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	} catch (PDOException $e) {
    	echo 'Error - ' . $e->getMessage();
    	exit();
	}

    //
    // *** Set up prerequisite to use phpMyAdmin's SQL Import plugin 
    //
    $GLOBALS['finished'] = true;

    // Defaults for parser
    $sql = '';
    $start_pos = 0;
    $i = 0;
    $len= 0;
    $big_value = 2147483647;
    $delimiter_keyword = 'DELIMITER '; // include the space because it's mandatory
    $length_of_delimiter_keyword = strlen($delimiter_keyword);
    $sql_delimiter = ';';

    // Current length of our buffer
    $len = strlen($buffer);

    // Grab some SQL queries out of it
    while ($i < $len) {
        $found_delimiter = false;

        // Find first interesting character
        $old_i = $i;
        // this is about 7 times faster than looking for each sequence one by one with strpos()
        if (preg_match('/(\'|"|#|-- |\/\*|`|(?i)(?<![A-Z0-9_])' . $delimiter_keyword . ')/', $buffer, $matches, PREG_OFFSET_CAPTURE, $i)) {
            // in $matches, index 0 contains the match for the complete
            // expression but we don't use it
            $first_position = $matches[1][1];
        } else {
            $first_position = $big_value;
        }
        /**
         * @todo we should not look for a delimiter that might be
         *       inside quotes (or even double-quotes)
         */
        // the cost of doing this one with preg_match() would be too high
        $first_sql_delimiter = strpos($buffer, $sql_delimiter, $i);
        if ($first_sql_delimiter === false) {
            $first_sql_delimiter = $big_value;
        } else {
            $found_delimiter = true;
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
            if (trim($buffer) == '') {
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
        if (strpos('\'"`', $ch) !== false) {
            $quote = $ch;
            $endq = false;
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
                if ($pos === false && $quote == '`' && $found_delimiter) {
                    $pos = $first_sql_delimiter - 1;
                // No quote? Too short string
                } elseif ($pos === false) {
                    // We hit end of string => unclosed quote, but we handle it as end of query
                    if ($GLOBALS['finished']) {
                        $endq = true;
                        $i = $len - 1;
                    }
                    $found_delimiter = false;
                    break;
                }
                // Was not the quote escaped?
                $j = $pos - 1;
                while ($buffer[$j] == '\\') $j--;
                // Even count means it was not escaped
                $endq = (((($pos - 1) - $j) % 2) == 0);
                // Skip the string
                $i = $pos;

                if ($first_sql_delimiter < $pos) {
                    $found_delimiter = false;
                }
            }
            if (!$endq) {
                break;
            }
            $i++;
            // Aren't we at the end?
            if ($GLOBALS['finished'] && $i == $len) {
                $i--;
            } else {
                continue;
            }
        }

        // Not enough data to decide
        if ((($i == ($len - 1) && ($ch == '-' || $ch == '/'))
          || ($i == ($len - 2) && (($ch == '-' && $buffer[$i + 1] == '-')
            || ($ch == '/' && $buffer[$i + 1] == '*')))) && !$GLOBALS['finished']) {
            break;
        }

        // Comments
        if ($ch == '#'
         || ($i < ($len - 1) && $ch == '-' && $buffer[$i + 1] == '-'
          && (($i < ($len - 2) && $buffer[$i + 2] <= ' ')
           || ($i == ($len - 1)  && $GLOBALS['finished'])))
         || ($i < ($len - 1) && $ch == '/' && $buffer[$i + 1] == '*')
                ) {
            // Copy current string to SQL
            if ($start_pos != $i) {
                $sql .= substr($buffer, $start_pos, $i - $start_pos);
            }
            // Skip the rest
            $start_of_comment = $i;
            // do not use PHP_EOL here instead of "\n", because the export
            // file might have been produced on a different system
            $i = strpos($buffer, $ch == '/' ? '*/' : "\n", $i);
            // didn't we hit end of string?
            if ($i === false) {
                if ($GLOBALS['finished']) {
                    $i = $len - 1;
                } else {
                    break;
                }
            }
            // Skip *
            if ($ch == '/') {
                $i++;
            }
            // Skip last char
            $i++;
            // We need to send the comment part in case we are defining
            // a procedure or function and comments in it are valuable
            $sql .= substr($buffer, $start_of_comment, $i - $start_of_comment);
            // Next query part will start here
            $start_pos = $i;
            // Aren't we at the end?
            if ($i == $len) {
                $i--;
            } else {
                continue;
            }
        }
        // Change delimiter, if redefined, and skip it (don't send to server!)
        if (strtoupper(substr($buffer, $i, $length_of_delimiter_keyword)) == $delimiter_keyword
         && ($i + $length_of_delimiter_keyword < $len)) {
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
        if ($found_delimiter || ($GLOBALS['finished'] && ($i == $len - 1))) {
            $tmp_sql = $sql;
            if ($start_pos < $len) {
                $length_to_grab = $i - $start_pos;

                if (! $found_delimiter) {
                    $length_to_grab++;
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
			    try {
	                if ( $dbc->exec( $sql ) === FALSE ){
		                $error = $dbc->errorInfo();
		                $dbc = null;
	                    echo 'Error - ' . $error[2];
	                    exit();
	                }
				} catch (PDOException $e) {
			    	echo 'Error - ' . $e->getMessage();
			    	exit();
				}
                $buffer = substr($buffer, $i + strlen($sql_delimiter));

                // Reset parser:
                $len = strlen($buffer);
                $sql = '';
                $i = 0;
                $start_pos = 0;
                // Any chance we will get a complete query?
                //if ((strpos($buffer, ';') === false) && !$GLOBALS['finished']) {
                if ((strpos($buffer, $sql_delimiter) === false) && !$GLOBALS['finished']) {
                    break;
                }
            } else {
                $i++;
                $start_pos = $i;
            }
        }
    } // End of parser loop
    $dbc = null;
}