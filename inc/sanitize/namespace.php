<?php
/**
 * @author  Pressbooks <code@pressbooks.com>
 * @license GPLv2 (or any later version)
 */

namespace Pressbooks\Sanitize;

/**
 * Convert HTML5 tags to XHTML11 divs.
 *
 * Will give a converted tag two classes.
 * Example: <aside>Howdy</aside> will become <div class="bc-aside aside">Howdy</div> (bc = Backward Compatible)
 * This function is used by htmLawed's hook.
 *
 * @param string $html
 * @param array $config (optional)
 * @param array $spec (optional) Extra HTML specifications using the $spec parameter
 *
 * @return string
 */
function html5_to_xhtml11( $html, $config = [], $spec = [] ) {

	$html5 = [
		'article',
		'aside',
		'audio',
		'bdi',
		'canvas',
		'command',
		'data',
		'datalist',
		'details',
		'embed',
		'figcaption',
		'figure',
		'footer',
		'header',
		'hgroup',
		'keygen',
		'mark',
		'meter',
		'nav',
		'output',
		'progress',
		'rp',
		'rt',
		'ruby',
		'section',
		'source',
		'summary',
		'time',
		'track',
		'video',
		'wbr',
	];

	$search_open = $replace_open = $search_closed = $replace_closed = [];

	foreach ( $html5 as $tag ) {
		$search_open[] = '`(<' . $tag . ')([^\w])`i';
		$replace_open[] = "<div class='bc-$tag $tag' $2";
		$search_closed[] = "</$tag>";
		$replace_closed[] = '</div>';
	}

	$html = preg_replace( $search_open, $replace_open, str_replace( $search_closed, $replace_closed, $html ) );

	return $html;
}

/**
 * Convert HTML5 to Epub3 compatible soup
 *
 * @param string $html
 * @param array $config (optional)
 * @param array $spec (optional) Extra HTML specifications using the $spec parameter
 *
 * @return string
 */
function html5_to_epub3( $html, $config = [], $spec = [] ) {

	// HTML5 elements we don't want to deal with just yet
	$html5 = [ 'command', 'embed', 'track' ];

	$search_open = $replace_open = $search_closed = $replace_closed = [];

	foreach ( $html5 as $tag ) {
		$search_open[] = '`(<' . $tag . ')([^\w])`i';
		$replace_open[] = "<div class='bc-$tag $tag' $2";
		$search_closed[] = "</$tag>";
		$replace_closed[] = '</div>';
	}

	$html = preg_replace( $search_open, $replace_open, str_replace( $search_closed, $replace_closed, $html ) );

	return $html;
}

/**
 * Setup a filter that removes style from WP audio shortcode
 *
 * @see \wp_audio_shortcode (in /wp-includes/media.php line ~1658)
 */
function fix_audio_shortcode() {

	add_filter(
		'wp_audio_shortcode', function ( $html, $atts, $audio, $post_id, $library ) {
			$html = preg_replace( '/(id=\"audio[0-9\-]*\")(.*)(style="[^\"]*\")/ui', '$1', $html );
			return $html;
		}, 10, 5
	);

}

/**
 * Sanitize XML attribute
 *
 * Here's what is allowed in an XML attribute value:
 *     '"' ([^<&"] | Reference)* '"'  |  "'" ([^<&'] | Reference)* "'"
 *
 * So, you can't have:
 *  + the same character that opens/closes the attribute value (either ' or ")
 *  + a naked ampersand (& must be &amp;)
 *  + a left angle bracket (< must be &lt;)
 *
 * You should also not being using any characters that are outright not legal anywhere in an XML document (such as
 * form feeds, etc).
 *
 * @param string $slug
 *
 * @return string
 */
function sanitize_xml_attribute( $slug ) {

	$slug = trim( $slug );
	$slug = html_entity_decode( $slug, ENT_COMPAT | ENT_XHTML, 'UTF-8' );
	$slug = htmlspecialchars( $slug, ENT_COMPAT | ENT_XHTML, 'UTF-8', false );
	$slug = str_replace( [ "\f" ], '', $slug );

	return $slug;
}


/**
 * Sanitize XML id
 *
 * @param string $slug
 *
 * @return string
 */
function sanitize_xml_id( $slug ) {

	$slug = trim( $slug );
	$slug = html_entity_decode( $slug, ENT_COMPAT | ENT_XHTML, 'UTF-8' );
	$slug = remove_accents( $slug );
	$slug = preg_replace( '([^a-zA-Z0-9-])', '', $slug );

	if ( empty( $slug ) ) {
		$slug = uniqid( 'slug-' );
	} elseif ( ! preg_match( '/^[a-z]/i', $slug ) ) {
		$slug = 'slug-' . $slug;
	}

	return $slug;
}


/**
 * Get rid of control characters. Strange hidden characters that mess things up.
 *
 * @param string $slug
 *
 * @return string
 */
function remove_control_characters( $slug ) {

	$slug = preg_replace( '/[\x00-\x1F\x7F]/', '', $slug );

	return $slug;
}


/**
 * Force ASCII (no control characters)
 *
 * @param $slug
 *
 * @return string
 */
function force_ascii( $slug ) {

	$slug = preg_replace( '/[^(\x20-\x7E)]*/', '', $slug );

	return $slug;
}


/**
 * Reverse htmlspecialchars() except ampersands.
 *
 * @param $slug
 *
 * @return mixed
 */
function decode( $slug ) {

	$slug = html_entity_decode( $slug, ENT_NOQUOTES | ENT_XHTML, 'UTF-8' );
	$slug = preg_replace( '/&([^#])(?![a-z1-4]{1,8};)/i', '&#038;$1', $slug );

	return $slug;
}


/**
 * Strip <br /> tags.
 *
 * @param $slug
 *
 * @return string
 */
function strip_br( $slug ) {

	$slug = preg_replace( '/&lt;br\W*?\/&gt;/', ' ', $slug );
	$slug = preg_replace( '/<br\W*?\/>/', ' ', $slug );

	return $slug;
}

/**
 * Filter post_title according to our specifications.
 *
 * @param $title
 *
 * @return string
 */
function filter_title( $title ) {
	$allowed = [
		'br' => [],
		'span' => [ 'class' => [] ],
		'em' => [],
		'strong' => [],
		'del' => [],
	];
	return wp_kses( $title, $allowed );
}

/**
 * Canonicalize URL
 *
 * @param $url
 *
 * @return string
 */
function canonicalize_url( $url ) {

	// remove trailing slash
	$url = rtrim( trim( $url ), '/' );

	if ( preg_match( '#^mailto:#i', $url ) ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}

	// Add http:// if it's missing
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		// Remove ftp://, gopher://, fake://, etc
		if ( mb_strpos( $url, '://' ) ) {
			list( $garbage, $url ) = mb_split( '://', $url );
		}
		// Prepend http
		$url = 'http://' . $url;
	}

	// protocol and domain to lowercase (but NOT the rest of the URL),
	$scheme = parse_url( $url, PHP_URL_SCHEME );
	$url = preg_replace( '/' . preg_quote( $scheme ) . '/', mb_strtolower( $scheme ), $url, 1 );
	$host = parse_url( $url, PHP_URL_HOST );
	$url = preg_replace( '/' . preg_quote( $host ) . '/', mb_strtolower( $host ), $url, 1 );

	// Sanitize for good measure
	$url = filter_var( $url, FILTER_SANITIZE_URL );

	return $url;

}


/**
 * Maybe change http:// to https://, depending on server state.
 *
 * @param $url
 *
 * @return string
 */
function maybe_https( $url ) {
	if ( empty( $_SERVER['HTTPS'] ) ) {
		return $url;
	} else {
		return preg_replace( '/^http:/', 'https:', $url );
	}
}

/**
 * Allow language tagging on more inline elements.
 *
 * @return null
 */
function allow_post_content() {
	global $allowedposttags;

	$allowedposttags['caption']['lang'] = true;
	$allowedposttags['caption']['xml:lang'] = true;

	$allowedposttags['cite']['xml:lang'] = true;

	$allowedposttags['code']['lang'] = true;
	$allowedposttags['code']['xml:lang'] = true;

	$allowedposttags['dd']['lang'] = true;
	$allowedposttags['dd']['xml:lang'] = true;

	$allowedposttags['del']['lang'] = true;
	$allowedposttags['del']['xml:lang'] = true;

	$allowedposttags['dl']['lang'] = true;
	$allowedposttags['dl']['xml:lang'] = true;

	$allowedposttags['dt']['lang'] = true;
	$allowedposttags['dt']['xml:lang'] = true;

	$allowedposttags['em']['lang'] = true;
	$allowedposttags['em']['xml:lang'] = true;

	$allowedposttags['h1']['lang'] = true;
	$allowedposttags['h1']['xml:lang'] = true;

	$allowedposttags['h2']['lang'] = true;
	$allowedposttags['h2']['xml:lang'] = true;

	$allowedposttags['h3']['lang'] = true;
	$allowedposttags['h3']['xml:lang'] = true;

	$allowedposttags['h4']['lang'] = true;
	$allowedposttags['h4']['xml:lang'] = true;

	$allowedposttags['h5']['lang'] = true;
	$allowedposttags['h5']['xml:lang'] = true;

	$allowedposttags['h6']['lang'] = true;
	$allowedposttags['h6']['xml:lang'] = true;

	$allowedposttags['ins']['lang'] = true;
	$allowedposttags['ins']['xml:lang'] = true;

	$allowedposttags['kbd']['lang'] = true;
	$allowedposttags['kbd']['xml:lang'] = true;

	$allowedposttags['label']['lang'] = true;
	$allowedposttags['label']['xml:lang'] = true;

	$allowedposttags['legend']['lang'] = true;
	$allowedposttags['legend']['xml:lang'] = true;

	$allowedposttags['li']['lang'] = true;
	$allowedposttags['li']['xml:lang'] = true;

	$allowedposttags['ol']['lang'] = true;
	$allowedposttags['ol']['xml:lang'] = true;

	$allowedposttags['q']['lang'] = true;
	$allowedposttags['q']['xml:lang'] = true;

	$allowedposttags['strong']['lang'] = true;
	$allowedposttags['strong']['xml:lang'] = true;

	$allowedposttags['q']['lang'] = true;
	$allowedposttags['q']['xml:lang'] = true;

	$allowedposttags['td']['lang'] = true;
	$allowedposttags['td']['xml:lang'] = true;

	$allowedposttags['th']['lang'] = true;
	$allowedposttags['th']['xml:lang'] = true;

	$allowedposttags['ul']['lang'] = true;
	$allowedposttags['ul']['xml:lang'] = true;

	$allowedposttags['var']['lang'] = true;
	$allowedposttags['var']['xml:lang'] = true;

	return;
}
