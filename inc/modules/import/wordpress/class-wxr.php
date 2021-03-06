<?php
/**
 * @author  Pressbooks <code@pressbooks.com>
 * @license GPLv2 (or any later version)
 */

namespace Pressbooks\Modules\Import\WordPress;

use Pressbooks\Modules\Import\Import;
use Pressbooks\Book;

class Wxr extends Import {

	/**
	 * If Pressbooks generated the WXR file
	 *
	 * @var boolean
	 */
	protected $isPbWxr = false;

	/**
	 *
	 */
	function __construct() {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/image.php' );
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
			require_once( ABSPATH . 'wp-admin/includes/media.php' );
		}
	}

	/**
	 * @param array $upload
	 *
	 * @return bool
	 */
	function setCurrentImportOption( array $upload ) {

		try {
			$parser = new Parser();
			$xml = $parser->parse( $upload['file'] );
		} catch ( \Exception $e ) {
			return false;
		}

		$this->pbCheck( $xml );

		$option = [
			'file' => $upload['file'],
			'file_type' => $upload['type'],
			'type_of' => 'wxr',
			'chapters' => [],
			'post_types' => [],
			'allow_parts' => true,
		];

		/**
		 * Allow custom post types to be imported.
		 *
		 * @since 3.6.0
		 *
		 * @param array
		 */
		$supported_post_types = apply_filters( 'pb_import_custom_post_types', [ 'post', 'page', 'front-matter', 'chapter', 'part', 'back-matter', 'metadata' ] );

		if ( $this->isPbWxr ) {
			//put the posts in correct part / menu_order order
			$xml['posts'] = $this->customNestedSort( $xml['posts'] );
		}

		foreach ( $xml['posts'] as $p ) {

			// Skip unsupported post types.
			if ( ! in_array( $p['post_type'], $supported_post_types, true ) ) {
				continue;
			}

			// Skip webbook required pages.
			if ( '<!-- Here be dragons.-->' === $p['post_content'] || '<!-- Here be dragons. -->' === $p['post_content'] ) {
				continue;
			}

			// Set
			$option['chapters'][ $p['post_id'] ] = $p['post_title'];
			$option['post_types'][ $p['post_id'] ] = $p['post_type'];
		}

		return update_option( 'pressbooks_current_import', $option );
	}


	/**
	 * @param array $current_import
	 *
	 * @return bool
	 */
	function import( array $current_import ) {

		try {
			$parser = new Parser();
			$xml = $parser->parse( $current_import['file'] );
		} catch ( \Exception $e ) {
			return false;
		}

		$this->pbCheck( $xml );

		if ( $this->isPbWxr ) {
			$xml['posts'] = $this->customNestedSort( $xml['posts'] );
		}

		$match_ids = array_flip( array_keys( $current_import['chapters'] ) );
		$chapter_parent = $this->getChapterParent();
		$totals = [
			'front-matter' => 0,
			'chapter' => 0,
			'part' => 0,
			'back-matter' => 0,
		];

		/**
		 * Allow custom post taxonomies to be imported.
		 *
		 * @since 3.6.0
		 *
		 * @param array
		 */
		$taxonomies = apply_filters( 'pb_import_custom_taxonomies', [ 'front-matter-type', 'chapter-type', 'back-matter-type' ] );

		$custom_post_types = apply_filters( 'pb_import_custom_post_types', [ 'post', 'page', 'front-matter', 'chapter', 'part', 'back-matter', 'metadata' ] );

		// set custom terms...
		$terms = apply_filters( 'pb_import_custom_terms', $xml['terms'] );

		// and import them if they don't already exist.
		foreach ( $terms as $t ) {
			$term = term_exists( $t['term_name'], $t['term_taxonomy'] );
			if ( null === $term || 0 === $term ) {
				wp_insert_term(
					$t['term_name'],
					$t['term_taxonomy'],
					[
						'description' => $t['term_description'],
						'slug' => $t['slug'],
					]
				);
			}
		}

		libxml_use_internal_errors( true );

		foreach ( $xml['posts'] as $p ) {

			// Skip
			if ( ! $this->flaggedForImport( $p['post_id'] ) ) {
				continue;
			}
			if ( ! isset( $match_ids[ $p['post_id'] ] ) ) {
				continue;
			}

			// Insert
			$post_type = $this->determinePostType( $p['post_id'] );

			// Load HTMl snippet into DOMDocument using UTF-8 hack
			$utf8_hack = '<?xml version="1.0" encoding="UTF-8"?>';
			$doc = new \DOMDocument();
			$doc->loadHTML( $utf8_hack . $this->tidy( $p['post_content'] ) );

			// Download images, change image paths
			$doc = $this->scrapeAndKneadImages( $doc );

			$html = $doc->saveXML( $doc->documentElement );

			// Remove auto-created <html> <body> and <!DOCTYPE> tags.
			$html = preg_replace( '/^<!DOCTYPE.+?>/', '', str_replace( [ '<html>', '</html>', '<body>', '</body>' ], [ '', '', '', '' ], $html ) );

			if ( 'metadata' === $post_type ) {
				$pid = $this->bookInfoPid();
			} else {
				$pid = $this->insertNewPost( $post_type, $p, $html, $chapter_parent, $current_import['default_post_status'] );
				if ( 'part' === $post_type ) {
					$chapter_parent = $pid;
				}
			}

			// if this is a custom post type,
			// and it has terms associated with it...
			if ( ( in_array( $post_type, $custom_post_types, true ) && isset( $p['terms'] ) ) ) {
				// associate post with terms.
				foreach ( $p['terms'] as $t ) {
					if ( in_array( $t['domain'], $taxonomies, true ) ) {
						wp_set_object_terms(
							$pid,
							$t['slug'],
							$t['domain'],
							true
						);
					}
				}
			}

			if ( isset( $p['postmeta'] ) && is_array( $p['postmeta'] ) ) {
				$this->importPbPostMeta( $pid, $post_type, $p );
			}

			Book::consolidatePost( $pid, get_post( $pid ) ); // Reorder
			if ( 'metadata' !== $post_type ) {
				++$totals[ $post_type ];
			}
		}

		$errors = libxml_get_errors(); // TODO: Handle errors gracefully
		libxml_clear_errors();

		// Done
		$_SESSION['pb_notices'][] =

			sprintf(
				_x( 'Imported %1$s, %2$s, %3$s, and %4$s.', 'String which tells user how many front matter, parts, chapters and back matter were imported.', 'pressbooks' ),
				$totals['front-matter'] . ' ' . __( 'front matter', 'pressbooks' ),
				( 1 === $totals['part'] ) ? $totals['part'] . ' ' . __( 'part', 'pressbooks' ) : $totals['part'] . ' ' . __( 'parts', 'pressbooks' ),
				( 1 === $totals['chapter'] ) ? $totals['chapter'] . ' ' . __( 'chapter', 'pressbooks' ) : $totals['chapter'] . ' ' . __( 'chapters', 'pressbooks' ),
				$totals['back-matter'] . ' ' . __( 'back matter', 'pressbooks' )
			);
		return $this->revokeCurrentImport();
	}

	/**
	 * Is it a WXR generated by PB?
	 *
	 * @param array $xml
	 */
	protected function pbCheck( array $xml ) {

		$pt = $ch = $fm = $bm = $meta = 0;

		foreach ( $xml['posts'] as $p ) {

			if ( 'part' === $p['post_type'] ) {
				$pt = 1;
			} elseif ( 'chapter' === $p['post_type'] ) {
				$ch = 1;
			} elseif ( 'front-matter' === $p['post_type'] ) {
				$fm = 1;
			} elseif ( 'back-matter' === $p['post_type'] ) {
				$bm = 1;
			} elseif ( 'metadata' === $p['post_type'] ) {
				$meta = 1;
			}

			if ( $pt + $ch + $fm + $bm + $meta >= 2 ) {
				$this->isPbWxr = true;
				break;
			}
		}

	}

	/**
	 * Custom sort for the xml posts to put them in correct nested order
	 *
	 * @param array $xml
	 *
	 * @return array sorted $xml
	 */
	protected function customNestedSort( $xml ) {
		$array = [];

		//first, put them in ascending menu_order
		usort(
			$xml, function ( $a, $b ) {
				return ( $a['menu_order'] - $b['menu_order'] );
			}
		);

		// Start with book info
		foreach ( $xml as $p ) {
			if ( 'metadata' === $p['post_type'] ) {
				$array[] = $p;
				break;
			}
		}

		//now, list all front matter
		foreach ( $xml as $p ) {
			if ( 'front-matter' === $p['post_type'] ) {
				$array[] = $p;
			}
		}

		//now, list all parts, then their associated chapters
		foreach ( $xml as $p ) {
			if ( 'part' === $p['post_type'] ) {
				$array[] = $p;
				foreach ( $xml as $psub ) {
					if ( 'chapter' === $psub['post_type'] && $psub['post_parent'] === $p['post_id'] ) {
						$array[] = $psub;
					}
				}
			}
		}

		//now, list all back matter
		foreach ( $xml as $p ) {
			if ( 'back-matter' === $p['post_type'] ) {
				$array[] = $p;
			}
		}

		// Remaining custom post types
		$custom_post_types = apply_filters( 'pb_import_custom_post_types', [] );

		foreach ( $xml as $p ) {
			if ( in_array( $p['post_type'], $custom_post_types, true ) ) {
				$array[] = $p;
			}
		}

		return $array;
	}


	/**
	 * Get existing Meta Post, if none exists create one
	 *
	 * @return int Post ID
	 */
	protected function bookInfoPid() {

		$post = ( new \Pressbooks\Metadata() )->getMetaPost();
		if ( empty( $post->ID ) ) {
			$new_post = [
				'post_title' => __( 'Book Info', 'pressbooks' ),
				'post_type' => 'metadata',
				'post_status' => 'publish',
			];
			$pid = wp_insert_post( add_magic_quotes( $new_post ) );
		} else {
			$pid = $post->ID;
		}

		return $pid;
	}

	/**
	 * Insert a new post
	 *
	 * @param string $post_type Post Type
	 * @param array $p Single Item Returned From \Pressbooks\Modules\Import\WordPress\Parser::parse
	 * @param string $html
	 * @param int $chapter_parent
	 * @param string $post_status
	 *
	 * @return int Post ID
	 */
	protected function insertNewPost( $post_type, $p, $html, $chapter_parent, $post_status ) {

		$custom_post_types = apply_filters( 'pb_import_custom_post_types', [] );

		$new_post = [
			'post_title' => wp_strip_all_tags( $p['post_title'] ),
			'post_type' => $post_type,
			'post_status' => ( 'part' === $post_type ) ? 'publish' : $post_status,
		];

		if ( 'part' !== $post_type ) {
			$new_post['post_content'] = $html;
		}
		if ( 'chapter' === $post_type ) {
			$new_post['post_parent'] = $chapter_parent;
		}

		$pid = wp_insert_post( add_magic_quotes( $new_post ) );

		return $pid;
	}

	/**
	 * Import Pressbooks specific post meta
	 *
	 * @param int $pid Post ID
	 * @param string $post_type Post Type
	 * @param array $p Single Item Returned From \Pressbooks\Modules\Import\WordPress\Parser::parse
	 */
	protected function importPbPostMeta( $pid, $post_type, $p ) {

		if ( 'metadata' === $post_type ) {
			$this->importMetaBoxes( $pid, $p );
		} else {
			$meta_to_update = apply_filters( 'pb_import_metakeys', [ 'pb_section_author', 'pb_section_license', 'pb_short_title', 'pb_subtitle', 'pb_show_title', 'pb_export' ] );
			foreach ( $meta_to_update as $meta_key ) {
				$meta_val = $this->searchForMetaValue( $meta_key, $p['postmeta'] );
				if ( is_serialized( $meta_val ) ) {
					$meta_val = unserialize( $meta_val );
				}
				if ( $meta_val ) {
					update_post_meta( $pid, $meta_key, $meta_val );
				}
			}
		}
	}

	/**
	 * @see \Pressbooks\Admin\Metaboxes\add_meta_boxes
	 *
	 * @param int $pid Post ID
	 * @param array $p Single Item Returned From \Pressbooks\Modules\Import\WordPress\Parser::parse
	 */
	protected function importMetaBoxes( $pid, $p ) {

		// List of meta data keys that can support multiple values:
		$multiple = [
			'pb_contributing_authors' => true,
			'pb_keywords_tags' => true,
			'pb_bisac_subject' => true,
		];

		// Clear old meta boxes
		$metadata = get_post_meta( $pid );
		foreach ( $metadata as $key => $val ) {
			// Does key start with pb_ prefix?
			if ( 0 === strpos( $key, 'pb_' ) ) {
				delete_post_meta( $pid, $key );
			}
		}

		// Import post meta
		foreach ( $p['postmeta'] as $meta ) {
			if ( 0 === strpos( $meta['key'], 'pb_' ) ) {
				if ( isset( $multiple[ $meta['key'] ] ) ) {
					// Multi value
					add_post_meta( $pid, $meta['key'], $meta['value'] );
				} else {
					// Single value
					if ( ! add_post_meta( $pid, $meta['key'], $meta['value'], true ) ) {
						update_post_meta( $pid, $meta['key'], $meta['value'] );
					}
				}
			}
		}

	}

	/**
	 * Check for PB specific metadata, returns empty string if not found.
	 *
	 * @param $meta_key , array $postmeta
	 *
	 * @return string meta field value
	 */
	protected function searchForMetaValue( $meta_key, array $postmeta ) {

		if ( empty( $postmeta ) ) {
			return '';
		}

		foreach ( $postmeta as $meta ) {
			// prefer this value, if it's set
			if ( $meta_key === $meta['key'] ) {
				return $meta['value'];
			}
		}

		return '';
	}

	/**
	 * Parse HTML snippet, save all found <img> tags using media_handle_sideload(), return the HTML with changed <img> paths.
	 *
	 * @param \DOMDocument $doc
	 *
	 * @return \DOMDocument
	 */
	protected function scrapeAndKneadImages( \DOMDocument $doc ) {

		$images = $doc->getElementsByTagName( 'img' );

		foreach ( $images as $image ) {
			/** @var \DOMElement $image */
			// Fetch image, change src
			$old_src = $image->getAttribute( 'src' );

			$new_src = $this->fetchAndSaveUniqueImage( $old_src );

			if ( $new_src ) {
				// Replace with new image
				$image->setAttribute( 'src', $new_src );
			} else {
				// Tag broken image
				$image->setAttribute( 'src', "{$old_src}#fixme" );
			}
		}

		return $doc;
	}


	/**
	 * Load remote url of image into WP using media_handle_sideload()
	 * Will return an empty string if something went wrong.
	 *
	 * @param string $url
	 *
	 * @see media_handle_sideload
	 *
	 * @return string filename
	 */
	protected function fetchAndSaveUniqueImage( $url ) {

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$remote_img_location = $url;

		// Cheap cache
		static $already_done = [];
		if ( isset( $already_done[ $remote_img_location ] ) ) {
			return $already_done[ $remote_img_location ];
		}

		/* Process */

		// Basename without query string
		$filename = explode( '?', basename( $url ) );
		$filename = array_shift( $filename );

		$filename = sanitize_file_name( urldecode( $filename ) );

		if ( ! preg_match( '/\.(jpe?g|gif|png)$/i', $filename ) ) {
			// Unsupported image type
			$already_done[ $remote_img_location ] = '';
			return '';
		}

		$tmp_name = download_url( $remote_img_location );
		if ( is_wp_error( $tmp_name ) ) {
			// Download failed
			$already_done[ $remote_img_location ] = '';
			return '';
		}

		if ( ! \Pressbooks\Image\is_valid_image( $tmp_name, $filename ) ) {

			try { // changing the file name so that extension matches the mime type
				$filename = $this->properImageExtension( $tmp_name, $filename );

				if ( ! \Pressbooks\Image\is_valid_image( $tmp_name, $filename ) ) {
					throw new \Exception( 'Image is corrupt, and file extension matches the mime type' );
				}
			} catch ( \Exception $exc ) {
				// Garbage, don't import
				$already_done[ $remote_img_location ] = '';
				unlink( $tmp_name );
				return '';
			}
		}

		$pid = media_handle_sideload( [ 'name' => $filename, 'tmp_name' => $tmp_name ], 0 );
		$src = wp_get_attachment_url( $pid );
		if ( ! $src ) {
			$src = ''; // Change false to empty string
		}
		$already_done[ $remote_img_location ] = $src;
		@unlink( $tmp_name ); // @codingStandardsIgnoreLine

		return $src;
	}

}
