<?php
/**
 * WordPress eXtended RSS file parser implementations
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * WXR Parser that makes use of the XML Parser PHP extension.
 */
class SD_EDI_WXR_Parser_XML {
	/**
	 * An array containing WordPress main tags for parsing.
	 *
	 * @var string[]
	 * @since 1.0.0
	 */
	public $wp_tags = [
		'wp:post_id',
		'wp:post_date',
		'wp:post_date_gmt',
		'wp:comment_status',
		'wp:ping_status',
		'wp:attachment_url',
		'wp:status',
		'wp:post_name',
		'wp:post_parent',
		'wp:menu_order',
		'wp:post_type',
		'wp:post_password',
		'wp:is_sticky',
		'wp:term_id',
		'wp:category_nicename',
		'wp:category_parent',
		'wp:cat_name',
		'wp:category_description',
		'wp:tag_slug',
		'wp:tag_name',
		'wp:tag_description',
		'wp:term_taxonomy',
		'wp:term_parent',
		'wp:term_name',
		'wp:term_description',
		'wp:author_id',
		'wp:author_login',
		'wp:author_email',
		'wp:author_display_name',
		'wp:author_first_name',
		'wp:author_last_name',
	];

	/**
	 * An array containing WordPress sub-tags for parsing.
	 *
	 * @var string[]
	 * @since 1.0.0
	 */
	public $wp_sub_tags = [
		'wp:comment_id',
		'wp:comment_author',
		'wp:comment_author_email',
		'wp:comment_author_url',
		'wp:comment_author_IP',
		'wp:comment_date',
		'wp:comment_date_gmt',
		'wp:comment_content',
		'wp:comment_approved',
		'wp:comment_type',
		'wp:comment_parent',
		'wp:comment_user_id',
	];

	/**
	 * The version of the WordPress WXR file being parsed.
	 *
	 * @var string|null
	 * @since 1.0.0
	 */
	public $wxr_version;

	/**
	 * Flag indicating whether the parser is inside a post element.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	public $in_post;

	/**
	 * The CDATA content being parsed.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $cdata;

	/**
	 * The primary data being parsed.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $data;

	/**
	 * The sub-data being parsed.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $sub_data;

	/**
	 * Flag indicating whether the parser is inside a tag element.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	public $in_tag;

	/**
	 * Flag indicating whether the parser is inside a sub-tag element.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	public $in_sub_tag;

	/**
	 * An array containing parsed author data.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $authors;

	/**
	 * An array containing parsed post data.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $posts;

	/**
	 * An array containing parsed term data.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $term;

	/**
	 * An array containing parsed category data.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $category;

	/**
	 * An array containing parsed tag data.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $tag;

	/**
	 * The base URL for the WordPress site.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $base_url;

	/**
	 * The base URL for the WordPress blog.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $base_blog_url;

	/**
	 * Parses a WordPress WXR (WordPress eXtended RSS) file.
	 *
	 * @param string $file The path to the WXR file to parse.
	 *
	 * @return array|WP_Error An array containing parsed data if successful, or a WP_Error object on failure.
	 * @since 1.0.0
	 */
	public function parse( $file ) {
		$this->wxr_version = false;
		$this->in_post     = false;
		$this->cdata       = false;
		$this->data        = false;
		$this->sub_data    = false;
		$this->in_tag      = false;
		$this->in_sub_tag  = false;
		$this->authors     = [];
		$this->posts       = [];
		$this->term        = [];
		$this->category    = [];
		$this->tag         = [];

		$xml = xml_parser_create( 'UTF-8' );
		xml_parser_set_option( $xml, XML_OPTION_SKIP_WHITE, 1 );
		xml_parser_set_option( $xml, XML_OPTION_CASE_FOLDING, 0 );
		xml_set_character_data_handler( $xml, [ $this, 'cdata' ] );
		xml_set_element_handler( $xml, [ $this, 'tag_open' ], [ $this, 'tag_close' ] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! xml_parse( $xml, file_get_contents( $file ), true ) ) {
			$current_line   = xml_get_current_line_number( $xml );
			$current_column = xml_get_current_column_number( $xml );
			$error_code     = xml_get_error_code( $xml );
			$error_string   = xml_error_string( $error_code );

			return new WP_Error(
				'XML_parse_error',
				'There was an error when reading this WXR file',
				[
					$current_line,
					$current_column,
					$error_string,
				]
			);
		}
		xml_parser_free( $xml );

		if ( ! preg_match( '/^\d+\.\d+$/', $this->wxr_version ) ) {
			return new WP_Error( 'WXR_parse_error', esc_html__( 'This does not appear to be a WXR file, missing/invalid WXR version number', 'easy-demo-importer' ) );
		}

		return [
			'authors'       => $this->authors,
			'posts'         => $this->posts,
			'categories'    => $this->category,
			'tags'          => $this->tag,
			'terms'         => $this->term,
			'base_url'      => $this->base_url,
			'base_blog_url' => $this->base_blog_url,
			'version'       => $this->wxr_version,
		];
	}

	/**
	 * Handles the opening of XML tags during parsing.
	 *
	 * This function is called when an opening XML tag is encountered during parsing.
	 * It checks if the current tag is one of the WordPress tags or sub-tags defined in the $wp_tags and $wp_sub_tags arrays.
	 * If the tag matches, it sets the corresponding property ($in_tag or $in_sub_tag) to indicate that data parsing for that tag has started.
	 *
	 * @param resource $parse The XML parser resource.
	 * @param string   $tag   The name of the opening XML tag.
	 * @param array    $attr  An array of attributes for the tag.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function tag_open( $parse, $tag, $attr ) {
		if ( in_array( $tag, $this->wp_tags, true ) ) {
			$this->in_tag = substr( $tag, 3 );

			return;
		}

		if ( in_array( $tag, $this->wp_sub_tags, true ) ) {
			$this->in_sub_tag = substr( $tag, 3 );

			return;
		}

		switch ( $tag ) {
			case 'category':
				if ( isset( $attr['domain'], $attr['nicename'] ) ) {
					if ( false === $this->sub_data ) {
						$this->sub_data = [];
					}

					$this->sub_data['domain'] = $attr['domain'];
					$this->sub_data['slug']   = $attr['nicename'];
				}
				break;
			case 'item':
				$this->in_post = true;
				break;
			case 'title':
				if ( $this->in_post ) {
					$this->in_tag = 'post_title';
				}
				break;
			case 'guid':
				$this->in_tag = 'guid';
				break;
			case 'dc:creator':
				$this->in_tag = 'post_author';
				break;
			case 'content:encoded':
				$this->in_tag = 'post_content';
				break;
			case 'excerpt:encoded':
				$this->in_tag = 'post_excerpt';
				break;

			case 'wp:term_slug':
				$this->in_tag = 'slug';
				break;
			case 'wp:meta_key':
				$this->in_sub_tag = 'key';
				break;
			case 'wp:meta_value':
				$this->in_sub_tag = 'value';
				break;
		}
	}

	/**
	 * Handles character data (CDATA) within XML elements during parsing.
	 *
	 * This function is called when character data (text) within an XML element is encountered.
	 * It appends the character data to the $cdata property, which is used to accumulate text data until the corresponding closing tag is encountered.
	 * It skips leading and trailing whitespace when appending data.
	 *
	 * @param resource $parser The XML parser resource.
	 * @param string   $cdata  The character data (text) within the XML element.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function cdata( $parser, $cdata ) {
		if ( ! trim( $cdata ) ) {
			return;
		}

		if ( false !== $this->in_tag || false !== $this->in_sub_tag ) {
			$this->cdata .= $cdata;
		} else {
			$this->cdata .= trim( $cdata );
		}
	}

	/**
	 * Handles the closing of XML tags during parsing.
	 *
	 * This function is called when a closing XML tag is encountered.
	 * It performs specific actions based on the closing tag, such as processing comment data, post data, term data, and more.
	 * It accumulates data into the corresponding arrays and clears relevant properties after processing.
	 *
	 * @param resource $parser The XML parser resource.
	 * @param string   $tag    The name of the closing XML tag.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function tag_close( $parser, $tag ) {
		switch ( $tag ) {
			case 'wp:comment':
				unset( $this->sub_data['key'], $this->sub_data['value'] ); // remove meta sub_data.
				if ( ! empty( $this->sub_data ) ) {
					$this->data['comments'][] = $this->sub_data;
				}
				$this->sub_data = false;
				break;
			case 'wp:commentmeta':
				$this->sub_data['commentmeta'][] = [
					'key'   => $this->sub_data['key'],
					'value' => $this->sub_data['value'],
				];
				break;
			case 'category':
				if ( ! empty( $this->sub_data ) ) {
					$this->sub_data['name'] = $this->cdata;
					$this->data['terms'][]  = $this->sub_data;
				}
				$this->sub_data = false;
				break;
			case 'wp:postmeta':
				if ( ! empty( $this->sub_data ) ) {
					$this->data['postmeta'][] = $this->sub_data;
				}
				$this->sub_data = false;
				break;
			case 'item':
				$this->posts[] = $this->data;
				$this->data    = false;
				break;
			case 'wp:category':
			case 'wp:tag':
			case 'wp:term':
				$n = substr( $tag, 3 );
				array_push( $this->$n, $this->data );
				$this->data = false;
				break;
			case 'wp:termmeta':
				if ( ! empty( $this->sub_data ) ) {
					$this->data['termmeta'][] = $this->sub_data;
				}
				$this->sub_data = false;
				break;
			case 'wp:author':
				if ( ! empty( $this->data['author_login'] ) ) {
					$this->authors[ $this->data['author_login'] ] = $this->data;
				}
				$this->data = false;
				break;
			case 'wp:base_site_url':
				$this->base_url = $this->cdata;
				if ( ! isset( $this->base_blog_url ) ) {
					$this->base_blog_url = $this->cdata;
				}
				break;
			case 'wp:base_blog_url':
				$this->base_blog_url = $this->cdata;
				break;
			case 'wp:wxr_version':
				$this->wxr_version = $this->cdata;
				break;

			default:
				if ( $this->in_sub_tag ) {
					if ( false === $this->sub_data ) {
						$this->sub_data = [];
					}

					$this->sub_data[ $this->in_sub_tag ] = ! empty( $this->cdata ) ? $this->cdata : '';
					$this->in_sub_tag                    = false;
				} elseif ( $this->in_tag ) {
					if ( false === $this->data ) {
						$this->data = [];
					}

					$this->data[ $this->in_tag ] = ! empty( $this->cdata ) ? $this->cdata : '';
					$this->in_tag                = false;
				}
		}

		$this->cdata = false;
	}
}
