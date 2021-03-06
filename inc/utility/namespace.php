<?php
/**
 * Generic utility functions.
 *
 * @author  Pressbooks <code@pressbooks.com>
 * @license GPLv2 (or any later version)
 */

namespace Pressbooks\Utility;

/**
 * Return a value for a given key even if not set
 *
 * @param mixed $arr either an array or a string that points to an array in $GLOBALS
 * @param string $key
 * @param mixed $default
 *
 * @return mixed
 */
function getset( $arr, $key, $default = null ) {

	// Get from array
	if ( is_array( $arr ) ) {
		return isset( $arr[ $key ] ) ? $arr[ $key ] : $default;
	}

	// Get from a global or superglobal
	if ( is_string( $arr ) && isset( $GLOBALS[ $arr ] ) && is_array( $GLOBALS[ $arr ] ) ) {
		return isset( $GLOBALS[ $arr ][ $key ] ) ? $GLOBALS[ $arr ][ $key ] : $default;
	}

	return $default;
}

/**
 * Scan a directory and return the files ordered by date, newest first.
 *
 * @param $dir
 *
 * @return array
 */
function scandir_by_date( $dir ) {

	$ignored = [ '.', '..', '.svn', '.git', '.htaccess' ];

	$files = [];
	foreach ( scandir( $dir ) as $file ) {
		if ( in_array( $file, $ignored, true ) ) {
			continue;
		}
		$files[ $file ] = filemtime( $dir . '/' . $file );
	}
	arsort( $files );
	$files = array_keys( $files );

	return ( $files ) ? $files : [];
}


/**
 * Scan the exports directory, return the files grouped into intervals of 3 minutes, newest first.
 *
 * @param string $dir fullpath to the Exports folder. (optional)
 *
 * @return array
 */
function group_exports( $dir = null ) {

	$ignored = [ '.', '..', '.svn', '.git', '.htaccess' ];

	if ( ! $dir ) {
		$dir = \Pressbooks\Modules\Export\Export::getExportFolder();
	} else {
		$dir = rtrim( $dir, '/' ) . '/';
	}

	$files = [];
	foreach ( scandir( $dir ) as $file ) {
		if ( in_array( $file, $ignored, true ) ) {
			continue;
		}
		$files[ $file ] = filemtime( $dir . $file );
	}
	arsort( $files );

	$interval = 3 * MINUTE_IN_SECONDS; // Three minutes
	$pos = 0;
	$output = [];

	foreach ( $files as $file => $timestamp ) {
		if ( 0 === $pos ) {
			$pos = $timestamp;
		}
		if ( $pos - $timestamp > $interval ) {
			$pos = $timestamp;
		}
		$output[ $pos ][] = $file;
	}

	return $output;
}


/**
 * Truncate the exports directory, delete old files.
 *
 * @param int $max
 * @param string $dir fullpath to the Exports fo
 * lder. (optional)
 */
function truncate_exports( $max, $dir = null ) {

	if ( ! $dir ) {
		$dir = \Pressbooks\Modules\Export\Export::getExportFolder();
	} else {
		$dir = rtrim( $dir, '/' ) . '/';
	}

	$max = absint( $max );
	$files = group_exports( $dir );

	$i = 1;
	foreach ( $files as $date => $exports ) {
		if ( $i > $max ) {
			foreach ( $exports as $export ) {
				$export = realpath( $dir . $export );

				unlink( $export );
			}
		}
		++$i;
	}
}


/**
 * Return the full path to the directory containing media
 * Checks for `ms_files_rewriting` site option; uses /wp-content/blogs.dir/ if present, otherwise uses WordPress 3.5+ standard, /wp-content/uploads/sites/
 *
 * @return string path
 */
function get_media_prefix() {
	if ( get_site_option( 'ms_files_rewriting' ) ) {
		return WP_CONTENT_DIR . '/blogs.dir/' . get_current_blog_id() . '/files/';
	} else {
		return WP_CONTENT_DIR . '/uploads/sites/' . get_current_blog_id() . '/';
	}
}

/**
 * Returns the full path to a media file, given its guid
 * Used for adding cover images to an EPUB file and for ajax deletion of uploaded cover images
 *
 * @param string $guid The guid of a media file (usually image)
 *
 * @return string the full path to the media file on the filesystem
 */
function get_media_path( $guid ) {

	$parts = parse_url( $guid );
	$path = $parts['path'];
	$beginning = strpos( $path, 'files' );
	if ( $beginning ) {
		$path = substr( $path, $beginning );
		return WP_CONTENT_DIR . '/blogs.dir/' . get_current_blog_id() . '/' . $path;
	} else {
		$beginning = strpos( $path, 'uploads' );
		$path = substr( $path, $beginning );
		return WP_CONTENT_DIR . '/' . $path;
	}
}

/**
 * Scan the export directory, return latest of each file type
 *
 * @author Brad Payne <brad@bradpayne.ca>
 * @copyright 2014 Brad Payne
 * @since 3.8.0
 * @return array
 */
function latest_exports() {
	/**
	 * @since 3.9.8
	 * Add custom export formats to the latest exports filetype mapping array.
	 *
	 * For example, here's how one might add a hypothetical Word export format:
	 *
	 * add_filter( 'pb_latest_export_filetypes', function ( $filetypes ) {
	 *    $filetypes['word'] = '.docx';
	 *    return $filetypes;
	 * } );
	 *
	 */
	$filetypes = apply_filters(
		'pb_latest_export_filetypes', [
		'epub3' => '._3.epub',
		'epub' => '.epub',
		'pdf' => '.pdf',
		'print-pdf' => '._print.pdf',
		'mobi' => '.mobi',
		'icml' => '.icml',
		'xhtml' => '.html',
		'wxr' => '.xml',
		'vanillawxr' => '._vanilla.xml',
		'mpdf' => '._oss.pdf',
		'odf' => '.odt',
		]
	);

	$dir = \Pressbooks\Modules\Export\Export::getExportFolder();

	$files = [];

	// group by extension, sort by date newest first
	foreach ( \Pressbooks\Utility\scandir_by_date( $dir ) as $file ) {
		// only interested in the part of filename starting with the timestamp
		preg_match( '/-\d{10,11}(.*)/', $file, $matches );

		// grab the first captured parenthisized subpattern
		$ext = $matches[1];

		$files[ $ext ][] = $file;
	}

	// get only one of the latest of each type
	$latest = [];

	foreach ( $filetypes as $type => $ext ) {
		if ( array_key_exists( $ext, $files ) ) {
			$latest[ $type ] = $files[ $ext ][0];
		}
	}

	return $latest;
}


/**
 * Add sitemap to robots.txt
 */
function add_sitemap_to_robots_txt() {

	if ( 1 === absint( get_option( 'blog_public' ) ) ) {
		echo 'Sitemap: ' . get_option( 'siteurl' ) . "/?feed=sitemap.xml\n\n";
	}
}


/**
 * Echo a sitemap
 */
function do_sitemap() {

	if ( 1 === absint( get_option( 'blog_public' ) ) ) {
		$template = untrailingslashit( PB_PLUGIN_DIR ) . '/templates/pb-sitemap.php';
		load_template( $template );
	} else {
		status_header( 404 );
		nocache_headers();
		echo '<h1>404 Not Found</h1>';
		echo 'The page that you have requested could not be found.';
	}
}

/**
 * Create a temporary file that automatically gets deleted when PHP ends.
 *
 * @return string Path to temporary file
 */
function create_tmp_file() {
	$stream = stream_get_meta_data( $GLOBALS[ mt_rand() ] = tmpfile() );

	return $stream['uri'];
}

/**
 * Lightweight check to see if the Epubcheck executable is installed and up to date.
 *
 * @return boolean
 */
function check_epubcheck_install() {
	if ( ! defined( 'PB_EPUBCHECK_COMMAND' ) ) { // @see wp-config.php
		define( 'PB_EPUBCHECK_COMMAND', '/usr/bin/java -jar /opt/epubcheck/epubcheck.jar' );
	}

	$output = [];
	$return_val = 0;
	exec( PB_EPUBCHECK_COMMAND . ' -h 2>&1', $output, $return_val );

	$output = $output[0];
	if ( false !== strpos( $output, 'EpubCheck' ) ) { // Command found.
		$output = explode( 'EpubCheck v', $output );
		$version = $output[1];
		if ( version_compare( $version, '4.0.0' ) >= 0 ) {
			return true;
		}
	}

	return apply_filters( 'pb_epub_has_dependencies', false );
}

/**
 * Lightweight check to see if the Kindlegen executable is installed and up to date.
 *
 * @return boolean
 */
function check_kindlegen_install() {
	if ( ! defined( 'PB_KINDLEGEN_COMMAND' ) ) { // @see wp-config.php
		define( 'PB_KINDLEGEN_COMMAND', '/opt/kindlegen/kindlegen' );
	}

	$output = [];
	$return_val = 0;
	exec( PB_KINDLEGEN_COMMAND . ' 2>&1', $output, $return_val );

	if ( isset( $output[2] ) && false !== strpos( $output[2], 'kindlegen' ) ) { // Command found.
		$output = explode( ' V', $output[2] );
		$output = explode( ' build', $output[1] );
		$version = $output[0];
		if ( version_compare( $version, '2.9' ) >= 0 ) {
			return true;
		}
	}

	return false;
}

/**
 * Lightweight check to see if the Prince executable is installed and up to date.
 *
 * @return boolean
 */
function check_prince_install() {
	if ( ! defined( 'PB_PRINCE_COMMAND' ) ) { // @see wp-config.php
		define( 'PB_PRINCE_COMMAND', '/usr/bin/prince' );
	}

	$output = [];
	$return_val = 0;
	exec( PB_PRINCE_COMMAND . ' --version 2>&1', $output, $return_val );

	$output = $output[0];
	if ( false !== strpos( $output, 'Prince' ) ) { // Command found.
		$output = explode( 'Prince ', $output );
		$version = $output[1];
		if ( version_compare( $version, '11' ) >= 0 ) {
			return true;
		}
	}

	return false;
}

/**
 * Lightweight check to see if the xmllint executable is installed and up to date.
 *
 * @return boolean
 */
function check_xmllint_install() {
	if ( ! defined( 'PB_XMLLINT_COMMAND' ) ) { // @see wp-config.php
		define( 'PB_XMLLINT_COMMAND', '/usr/bin/xmllint' );
	}

	$output = [];
	$return_val = 0;
	exec( PB_XMLLINT_COMMAND . ' --version 2>&1', $output, $return_val );

	$output = $output[0];
	if ( false !== strpos( $output, 'libxml' ) ) { // Command found.
		$output = explode( PB_XMLLINT_COMMAND . ': using libxml version ', $output );
		$version = $output[1];
		if ( version_compare( $version, '20706' ) >= 0 ) {
			return true;
		}
	}

	return false;
}

/**
 * Lightweight check to see if the Saxon-HE executable is installed and up to date.
 *
 * @return boolean
 */
function check_saxonhe_install() {
	if ( ! defined( 'PB_SAXON_COMMAND' ) ) { // @see wp-config.php
		define( 'PB_SAXON_COMMAND', '/usr/bin/java -jar /opt/saxon-he/saxon-he.jar' );
	}

	$output = [];
	$return_val = 0;
	exec( PB_SAXON_COMMAND . ' -? 2>&1', $output, $return_val );

	$output = $output[0];
	if ( false !== strpos( $output, 'Saxon-HE ' ) ) { // Command found.
		$output = explode( 'Saxon-HE ', $output );
		$version = explode( 'J from Saxonica', $output[1] )[0];
		if ( version_compare( $version, '9.7.0-10' ) >= 0 ) {
			return true;
		}
	}

	/**
	 * @since 3.9.8
	 *
	 * Allows the SaxonHE dependency error to be disabled.
	 */
	return apply_filters( 'pb_odt_has_dependencies', false );
}

/**
 * Function to determine whether or not experimental features should be visible to users.
 *
 * @param $host string
 *
 * @return boolean
 */
function show_experimental_features( $host = '' ) {

	if ( ! $host ) {
		$host = parse_url( network_site_url(), PHP_URL_HOST );
	}

	// hosts where experimental features should be hidden
	$hosts_for_hiding = [
		'pressbooks.com',
		'pressbooks.pub',
	];

	foreach ( $hosts_for_hiding as $host_for_hiding ) {
		if ( $host === $host_for_hiding || strpos( $host, $host_for_hiding ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Include plugins in /symbionts
 *
 * @since 2.5.1
 */
function include_plugins() {
	$plugins = [
		'custom-metadata/custom_metadata.php' => 1,
		'pressbooks-latex/pb-latex.php' => 1,
	];

	$plugins = filter_plugins( $plugins );

	if ( ! empty( $plugins ) ) {
		foreach ( $plugins as $key => $val ) {
			require_once( PB_PLUGIN_DIR . 'symbionts/' . $key );
		}
	}

	// Disable comments
	if ( true === disable_comments() ) {
		require_once( PB_PLUGIN_DIR . 'symbionts/disable-comments-mu/disable-comments-mu.php' );
	}
}

/**
 * Filters out active plugins, to avoid collisions with plugins already installed.
 *
 * @since 2.5.1
 *
 * @param array $plugins An array of plugins, key/values paired like so: 'pressbooks/pressbooks.php' => 1
 *
 * @return array
 */
function filter_plugins( $plugins ) {
	$already_active = get_option( 'active_plugins' );
	$network_already_active = get_site_option( 'active_sitewide_plugins' );

	// Don't include plugins already active at the site level or network level.
	if ( ! empty( $plugins ) ) {
		foreach ( $plugins as $key => $val ) {
			if ( in_array( $key, $already_active, true ) || array_key_exists( $key, $network_already_active ) ) {
				unset( $plugins[ $key ] );
			}
		}
	}

	// Don't include plugins we are trying to activate right now!
	if ( isset( $_REQUEST['action'] ) ) {
		if ( 'activate' === $_REQUEST['action'] && ! empty( $_REQUEST['plugin'] ) ) {
			$key = (string) $_REQUEST['plugin'];
			unset( $plugins[ $key ] );
		} elseif ( 'activate-selected' === $_REQUEST['action'] && is_array( $_REQUEST['checked'] ) ) {
			foreach ( $_REQUEST['checked'] as $key ) {
				unset( $plugins[ $key ] );
			}
		}
	}

	// Don't include Pressbooks LaTeX if QuickLaTeX is active.
	if ( in_array( 'wp-quicklatex', $already_active, true ) || array_key_exists( 'wp-quicklatex/wp-quicklatex.php', $network_already_active ) ) {
		unset( $plugins['pressbooks-latex/pb-latex.php'] );
	}

	return $plugins;
}

/**
 * Check if we should disable comments.
 *
 * @return bool
 */
function disable_comments() {
	if ( ! \Pressbooks\Book::isBook() ) {
		/**
		 * Allows comments to be enabled on the root blog by adding a function to this filter that returns false.
		 *
		 * @since 3.9.6
		 */
		return apply_filters( 'pb_disable_root_comments', true );
	}

	$old_option = get_option( 'disable_comments_options' );
	$new_option = get_option( 'pressbooks_sharingandprivacy_options', [ 'disable_comments' => 1 ] );

	if ( false === absint( $old_option ) ) {
		$retval = absint( $new_option['disable_comments'] );
	} elseif ( is_array( $old_option['disabled_post_types'] ) && in_array( 'chapter', $old_option['disabled_post_types'], true ) && in_array( 'front-matter', $old_option['disabled_post_types'], true ) && in_array( 'front-matter', $old_option['disabled_post_types'], true ) ) {
		$retval = true;
		$new_option['disable_comments'] = 1;
		update_option( 'pressbooks_sharingandprivacy_options', $new_option );
		delete_option( 'disable_comments_options' );
	} else {
		$retval = false;
		$new_option['disable_comments'] = 0;
		update_option( 'pressbooks_sharingandprivacy_options', $new_option );
		delete_option( 'disable_comments_options' );
	}

	return $retval;
}

/**
 * Remove the Featured tab, change order on the others so that Recommended is first.
 * Adapted from https://github.com/secretpizzaparty/better-plugin-recommendations
 *
 * @since 4.0.0
 * @author Joey Kudish <info@jkudish.com>
 * @author Nick Hamze <me@nickhamze.com>
 *
 * @param array $tabs The Plugin Installer tabs.
 *
 * @return array
 */
function install_plugins_tabs( $tabs ) {
	unset( $tabs['featured'] );
	unset( $tabs['popular'] );
	unset( $tabs['favorites'] );
	$tabs['popular'] = _x( 'Popular', 'Plugin Installer' );
	$tabs['favorites'] = _x( 'Favorites', 'Plugin Installer' );
	return $tabs;
}

/**
 * Replace the core Recommended tab with ours.
 * Adapted from https://github.com/secretpizzaparty/better-plugin-recommendations
 *
 * @since 4.0.0
 * @author Joey Kudish <info@jkudish.com>
 * @author Nick Hamze <me@nickhamze.com>
 *
 * @param false|object|array $res The result object or array. Default false.
 * @param string $action The type of information being requested from the Plugin Install API.
 * @param object $args Plugin API arguments.
 *
 * @return object
 */
function hijack_recommended_tab( $res, $action, $args ) {
	if ( ! isset( $args->browse ) || 'recommended' !== $args->browse ) {
		return $res;
	}
	$res = get_site_transient( 'pressbooks_recommended_plugins_data' );
	if ( ! $res || ! isset( $res->plugins ) ) {
		$res = \Pressbooks\Utility\fetch_recommended_plugins();
		if ( isset( $res->plugins ) ) {
			set_site_transient( 'pressbooks_recommended_plugins_data', $res, HOUR_IN_SECONDS );
		}
	}
	return $res;
}

/**
 * Fetch recommended plugins from our server.
 * Adapted from https://github.com/secretpizzaparty/better-plugin-recommendations
 *
 * @since 4.0.0
 * @author Joey Kudish <info@jkudish.com>
 * @author Nick Hamze <me@nickhamze.com>
 *
 * @return object
 */
function fetch_recommended_plugins() {
	/**
	 * Filter the URL of the Pressbooks Recommended Plugins server.
	 *
	 * @since 4.0.0
	 */
	$url = $http_url = apply_filters( 'pb_recommended_plugins_url', 'https://pressbooks-plugins.now.sh' ) . '/api/plugin-recommendations';
	$ssl = wp_http_supports( [ 'ssl' ] );
	if ( $ssl ) {
		$url = set_url_scheme( $url, 'https' );
	}
	$request = wp_remote_get( $url, [ 'timeout' => 15 ] );
	if ( $ssl && is_wp_error( $request ) ) {
		trigger_error(
			__( 'An unexpected error occurred. Something may be wrong with the plugin recommendations server or your site&#8217;s server&#8217;s configuration.', 'pressbooks' ) . ' ' . __( '(Pressbooks could not establish a secure connection to the plugin recommendations server. Please contact your server administrator.)', 'pressbooks' ),
			headers_sent() || WP_DEBUG ? E_USER_WARNING : E_USER_NOTICE
		);
		$request = wp_remote_get( $http_url, [ 'timeout' => 15 ] );
	}
	if ( is_wp_error( $request ) ) {
		$res = new \WP_Error(
			'plugins_api_failed', __( 'An unexpected error occurred. Something may be wrong with the plugin recommendations server or your site&#8217;s server&#8217;s configuration.', 'pressbooks' ),
			$request->get_error_message()
		);
	} else {
		$res = json_decode( wp_remote_retrieve_body( $request ) );
		$res->info = (array) $res->info; // WP wants this as an array...
		$res->plugins = array_map(
			function ( $plugin ) {
				$plugin->icons = (array) $plugin->icons; // WP wants this as an array...
				return $plugin;
			}, $res->plugins
		);
		if ( ! is_object( $res ) && ! is_array( $res ) ) {
			$res = new \WP_Error(
				'plugins_api_failed',
				__( 'An unexpected error occurred. Something may be wrong with the plugin recommendations server or your site&#8217;s server&#8217;s configuration.', 'pressbooks' ),
				wp_remote_retrieve_body( $request )
			);
		}
	}
	return $res;
}

/**
 * Replace the description on the Recommended tab.
 * Adapted from https://github.com/secretpizzaparty/better-plugin-recommendations
 *
 * @since 4.0.0
 * @author Joey Kudish <info@jkudish.com>
 * @author Nick Hamze <me@nickhamze.com>
 *
 * @param string $translation
 * @param string $text
 * @param string $domain
 *
 * @return string
 */
function change_recommendations_sentence( $translation, $text, $domain ) {
	if ( 'These suggestions are based on the plugins you and other users have installed.' === $text ) {
		return __( 'These plugins have been created and/or recommended by the Pressbooks community.', 'pressbooks' );
	}
	return $translation;
}


/**
 * Function to return a string representing max import size by comparing values of upload_max_filesize, post_max_size
 * Uses parse_size helper function since the values in php.ini are strings like 64M and 128K
 * @return string
 */

function file_upload_max_size() {
	static $return_val = false;
	// This function is adapted from Drupal and http://stackoverflow.com/questions/13076480/php-get-actual-maximum-upload-size
	if ( false === $return_val ) {
		$post_max_size_str = ini_get( 'post_max_size' );
		$upload_max_filesize_str = ini_get( 'upload_max_filesize' );
		$post_max_size = parse_size( $post_max_size_str );
		$upload_max_filesize = parse_size( $upload_max_filesize_str );

		// If upload_max_size is less, then reduce. Except if upload_max_size is
		// zero, which indicates no limit.
		$return_val = $post_max_size_str;
		if ( $upload_max_filesize > 0 && $upload_max_filesize < $post_max_size ) {
			$return_val = $upload_max_filesize_str;
		}
	}
	return $return_val;
}

/**
 * parse_size converts php.ini values from strings (like 128M or 64K) into actual numbers that can be compared
 *
 * @param string $size
 *
 * @return float
 */
function parse_size( $size ) {
	$unit = preg_replace( '/[^bkmgtpezy]/i', '', $size ); // Remove the non-unit characters from the size.
	$size = preg_replace( '/[^0-9\.]/', '', $size ); // Remove the non-numeric characters from the size.
	if ( $unit ) { // Find the position of the unit in the ordered string which is the power of magnitude to multiply a kilobyte by.
		return round( $size * pow( 1024, stripos( 'bkmgtpezy', $unit[0] ) ) );
	} else {
		return round( $size );
	}
}

/**
 * format_bytes converts an byte value supplied as an integer into a string suffixed with the appropriate unit of measurement.
 *
 * @param float $bytes
 * @param int $precision
 *
 * @return string
 */
function format_bytes( $bytes, $precision = 2 ) {
	$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	$bytes = max( $bytes, 0 );
	$pow = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
	$pow = min( $pow, count( $units ) - 1 );
	$bytes /= ( 1 << ( 10 * $pow ) );

	return round( $bytes, $precision ) . ' ' . $units[ $pow ];
}


/**
 * Email error to an array of recipients
 *
 * @param array $emails
 * @param string $subject
 * @param string $message
 */
function email_error_log( $emails, $subject, $message ) {

	// ------------------------------------------------------------------------------------------------------------
	// Write to generic error log to be safe

	error_log( $subject . "\n" . $message );

	// ------------------------------------------------------------------------------------------------------------
	// Email logs

	add_filter(
		'wp_mail_from', function ( $from_email ) {
			return str_replace( 'wordpress@', 'pressbooks@', $from_email );
		}
	);
	add_filter(
		'wp_mail_from_name', function ( $from_name ) {
			return 'Pressbooks';
		}
	);

	foreach ( $emails as $email ) {
		// Call pluggable
		\wp_mail( $email, $subject, $message );
	}
}


/**
 * Simple template system.
 *
 * @param string $path
 * @param array $vars (optional)
 *
 * @return string
 * @throws \Exception
 */
function template( $path, array $vars = [] ) {

	if ( ! file_exists( $path ) ) {
		throw new \Exception( "File not found: $path" );
	}

	ob_start();
	extract( $vars ); // @codingStandardsIgnoreLine
	include( $path );
	$output = ob_get_contents();
	ob_end_clean();

	return $output;
}

function remote_get_retry( $url, $args, $retry = 3, $attempts = 0, $response = [] ) {
	$completed = false;

	if ( $attempts >= $retry ) {
		$completed = true;
	}

	if ( $completed ) {
		return $response;
	}

	$attempts++;

	$response = wp_remote_get( $url, $args );

	$retry_response_codes = apply_filters( 'pressbooks_remote_get_retry_response_codes', [ 400 ] );

	if ( ! is_array( $response ) || ! in_array( $response['response']['code'], $retry_response_codes, true ) ) {
		return $response;
	}

	$sleep = apply_filters( 'pressbooks_remote_get_retry_wait_time', 1000 );
	usleep( $sleep );
	return remote_get_retry( $url, $args, $retry, $attempts, $response );
}

/**
 * Set the wp_mail sender address
 *
 * @since 3.9.7
 *
 * @param string $email The default email address
 *
 * @return string
 */
function mail_from( $email ) {
	if ( defined( 'WP_MAIL_FROM' ) ) {
		$email = WP_MAIL_FROM;
	} else {
		$sitename = strtolower( $_SERVER['SERVER_NAME'] );
		if ( substr( $sitename, 0, 4 ) === 'www.' ) {
			$sitename = substr( $sitename, 4 );
		}
		$email = 'pressbooks@' . $sitename;
	}
	return $email;
}

/**
 * Set the wp_mail sender name
 *
 * @since 3.9.7
 *
 * @param string $name The default sender name
 *
 * @return string
 */
function mail_from_name( $name ) {
	if ( defined( 'WP_MAIL_FROM_NAME' ) ) {
		$name = WP_MAIL_FROM_NAME;
	} else {
		$name = 'Pressbooks';
	}
	return $name;
}

/**
 * Recursive directory copy. Props to https://ben.lobaugh.net/blog/864/php-5-recursively-move-or-copy-files
 *
 * @since 3.9.8
 * @author Ben Lobaugh <ben@lobaugh.net>
 *
 * @param string $src
 * @param string $dest
 *
 * @return bool
 */
function rcopy( $src, $dest ) {
	if ( ! is_dir( $src ) ) {
		return false;
	}

	if ( ! is_dir( $dest ) ) {
		if ( ! mkdir( $dest ) ) {
			return false;
		}
	}

	$i = new \DirectoryIterator( $src );
	foreach ( $i as $f ) {
		if ( $f->isFile() ) {
			if ( false === copy( $f->getRealPath(), "$dest/" . $f->getFilename() ) ) {
				return false;
			}
		} elseif ( ! $f->isDot() && $f->isDir() ) {
			\Pressbooks\Utility\rcopy( $f->getRealPath(), "$dest/$f" );
		}
	}
	return true;
}
