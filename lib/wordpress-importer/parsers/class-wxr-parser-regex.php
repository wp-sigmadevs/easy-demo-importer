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
 * WXR Parser that uses regular expressions. Fallback for installs without an XML parser.
 */
class SD_EDI_WXR_Parser_Regex {
	/**
	 * Authors
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $authors = [];

	/**
	 * Posts
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $posts = [];

	/**
	 * Categories
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $categories = [];

	/**
	 * Tags
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $tags = [];

	/**
	 * Terms
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $terms = [];

	/**
	 * Base URL
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $base_url = '';

	/**
	 * Base Blog URL
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $base_blog_url = '';

	/**
	 * Indicates whether GZIP compression is enabled or not.
	 *
	 * @var bool|null
	 */
	public $has_gzip;

	/**
	 * Class Constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->has_gzip = is_callable( 'gzopen' );
	}

	/**
	 * Parses a WordPress XML-RPC (WXR) file and extracts data.
	 *
	 * This method reads a WordPress XML-RPC (WXR) file and extracts data such as authors, posts,
	 * categories, tags, terms, base URLs, and the WXR version.
	 *
	 * @param string $file The path to the WordPress XML-RPC (WXR) file to parse.
	 *
	 * @return array|WP_Error An array containing the extracted data or a WP_Error object if parsing fails.
	 *
	 * @since 1.0.0
	 */
	public function parse( $file ) {
		$wxr_version  = false;
		$in_multiline = false;

		$multiline_content = '';

		$multiline_tags = [
			'item'        => [ 'posts', [ $this, 'process_post' ] ],
			'wp:category' => [ 'categories', [ $this, 'process_category' ] ],
			'wp:tag'      => [ 'tags', [ $this, 'process_tag' ] ],
			'wp:term'     => [ 'terms', [ $this, 'process_term' ] ],
		];

		$fp = $this->fopen( $file, 'r' );
		if ( $fp ) {
			while ( ! $this->feof( $fp ) ) {
				$is_tag_line = false;
				$importline  = rtrim( $this->fgets( $fp ) );

				if ( ! $wxr_version && preg_match( '|<wp:wxr_version>(\d+\.\d+)</wp:wxr_version>|', $importline, $version ) ) {
					$wxr_version = $version[1];
				}

				if ( false !== strpos( $importline, '<wp:base_site_url>' ) ) {
					preg_match( '|<wp:base_site_url>(.*?)</wp:base_site_url>|is', $importline, $url );
					$this->base_url = $url[1];
					continue;
				}

				if ( false !== strpos( $importline, '<wp:base_blog_url>' ) ) {
					preg_match( '|<wp:base_blog_url>(.*?)</wp:base_blog_url>|is', $importline, $blog_url );
					$this->base_blog_url = $blog_url[1];
					continue;
				} elseif ( empty( $this->base_blog_url ) ) {
					$this->base_blog_url = $this->base_url;
				}

				if ( false !== strpos( $importline, '<wp:author>' ) ) {
					preg_match( '|<wp:author>(.*?)</wp:author>|is', $importline, $author );
					$a                                   = $this->process_author( $author[1] );
					$this->authors[ $a['author_login'] ] = $a;
					continue;
				}

				foreach ( $multiline_tags as $tag => $handler ) {
					// Handle multi-line tags on a singular line.
					$pos         = strpos( $importline, "<$tag>" );
					$pos_closing = strpos( $importline, "</$tag>" );
					if ( preg_match( '|<' . $tag . '>(.*?)</' . $tag . '>|is', $importline, $matches ) ) {
						$this->{$handler[0]}[] = call_user_func( $handler[1], $matches[1] );

					} elseif ( false !== $pos ) {
						// Take note of any content after the opening tag.
						$multiline_content = trim( substr( $importline, $pos + strlen( $tag ) + 2 ) );

						// We don't want to have this line added to `$is_multiline` below.
						$in_multiline = $tag;
						$is_tag_line  = true;

					} elseif ( false !== $pos_closing ) {
						$in_multiline       = false;
						$multiline_content .= trim( substr( $importline, 0, $pos_closing ) );

						$this->{$handler[0]}[] = call_user_func( $handler[1], $multiline_content );
					}
				}

				if ( $in_multiline && ! $is_tag_line ) {
					$multiline_content .= $importline . "\n";
				}
			}

			$this->fclose( $fp );
		}

		if ( ! $wxr_version ) {
			return new WP_Error( 'WXR_parse_error', esc_html__( 'This does not appear to be a WXR file, missing/invalid WXR version number', 'easy-demo-importer' ) );
		}

		return [
			'authors'       => $this->authors,
			'posts'         => $this->posts,
			'categories'    => $this->categories,
			'tags'          => $this->tags,
			'terms'         => $this->terms,
			'base_url'      => $this->base_url,
			'base_blog_url' => $this->base_blog_url,
			'version'       => $wxr_version,
		];
	}

	/**
	 * Extracts and returns the content of a specified XML tag from a given string.
	 *
	 * This method searches for the opening and closing XML tag specified by the $tag parameter within
	 * the provided $string and returns the content enclosed by those tags. If the content is wrapped
	 * in CDATA sections, they are properly processed and the content is extracted.
	 *
	 * @param string $string The input string containing XML content.
	 * @param string $tag    The XML tag to search for and extract.
	 *
	 * @return string The content of the specified XML tag, or an empty string if the tag is not found.
	 *
	 * @since 1.0.0
	 */
	public function get_tag( $string, $tag ) {
		preg_match( "|<$tag.*?>(.*?)</$tag>|is", $string, $return );
		if ( isset( $return[1] ) ) {
			if ( substr( $return[1], 0, 9 ) == '<![CDATA[' ) {
				if ( strpos( $return[1], ']]]]><![CDATA[>' ) !== false ) {
					preg_match_all( '|<!\[CDATA\[(.*?)\]\]>|s', $return[1], $matches );
					$return = '';

					foreach ( $matches[1] as $match ) {
						$return .= $match;
					}
				} else {
					$return = preg_replace( '|^<!\[CDATA\[(.*)\]\]>$|s', '$1', $return[1] );
				}
			} else {
				$return = $return[1];
			}
		} else {
			$return = '';
		}

		return $return;
	}

	/**
	 * Process and extract category information from a given XML element.
	 *
	 * This function takes an XML element representing a category and extracts relevant information,
	 * such as term ID, category name, category nicename, parent category, and category description.
	 * Additionally, it processes any term metadata associated with the category and includes it in the result
	 * if available.
	 *
	 * @param string $c The XML element containing category data.
	 *
	 * @return array An array containing the extracted category information.
	 *
	 * @since 1.0.0
	 */
	public function process_category( $c ) {
		$term = [
			'term_id'              => $this->get_tag( $c, 'wp:term_id' ),
			'cat_name'             => $this->get_tag( $c, 'wp:cat_name' ),
			'category_nicename'    => $this->get_tag( $c, 'wp:category_nicename' ),
			'category_parent'      => $this->get_tag( $c, 'wp:category_parent' ),
			'category_description' => $this->get_tag( $c, 'wp:category_description' ),
		];

		$term_meta = $this->process_meta( $c, 'wp:termmeta' );

		if ( ! empty( $term_meta ) ) {
			$term['termmeta'] = $term_meta;
		}

		return $term;
	}

	/**
	 * Process and extract tag information from a given XML element.
	 *
	 * This function takes an XML element representing a tag and extracts relevant information,
	 * such as term ID, tag name, tag slug, and tag description. It also processes any term metadata
	 * associated with the tag and includes it in the result if available.
	 *
	 * @param string $t The XML element containing tag data.
	 *
	 * @return array An array containing the extracted tag information.
	 *
	 * @since 1.0.0
	 */
	public function process_tag( $t ) {
		$term = [
			'term_id'         => $this->get_tag( $t, 'wp:term_id' ),
			'tag_name'        => $this->get_tag( $t, 'wp:tag_name' ),
			'tag_slug'        => $this->get_tag( $t, 'wp:tag_slug' ),
			'tag_description' => $this->get_tag( $t, 'wp:tag_description' ),
		];

		$term_meta = $this->process_meta( $t, 'wp:termmeta' );

		if ( ! empty( $term_meta ) ) {
			$term['termmeta'] = $term_meta;
		}

		return $term;
	}

	/**
	 * Process and extract term information from a given XML element.
	 *
	 * This function takes an XML element representing a term and extracts relevant information,
	 * such as term ID, term taxonomy, slug, term parent, term name, and term description.
	 * Additionally, it processes any term metadata associated with the term and includes it in the result
	 * if available.
	 *
	 * @param string $t The XML element containing term data.
	 *
	 * @return array An array containing the extracted term information.
	 *
	 * @since 1.0.0
	 */
	public function process_term( $t ) {
		$term = [
			'term_id'          => $this->get_tag( $t, 'wp:term_id' ),
			'term_taxonomy'    => $this->get_tag( $t, 'wp:term_taxonomy' ),
			'slug'             => $this->get_tag( $t, 'wp:term_slug' ),
			'term_parent'      => $this->get_tag( $t, 'wp:term_parent' ),
			'term_name'        => $this->get_tag( $t, 'wp:term_name' ),
			'term_description' => $this->get_tag( $t, 'wp:term_description' ),
		];

		$term_meta = $this->process_meta( $t, 'wp:termmeta' );

		if ( ! empty( $term_meta ) ) {
			$term['termmeta'] = $term_meta;
		}

		return $term;
	}

	/**
	 * Process and extract metadata from a given XML element.
	 *
	 * This function takes an XML element representing metadata and extracts key-value pairs
	 * of metadata. It expects the metadata to be enclosed within the specified XML tag.
	 *
	 * @param string $string The XML element containing metadata.
	 * @param string $tag    The XML tag that encloses the metadata.
	 *
	 * @return array An array containing the extracted metadata in the form of key-value pairs.
	 *
	 * @since 1.0.0
	 */
	public function process_meta( $string, $tag ) {
		$parsed_meta = [];

		preg_match_all( "|<$tag>(.+?)</$tag>|is", $string, $meta );

		if ( ! isset( $meta[1] ) ) {
			return $parsed_meta;
		}

		foreach ( $meta[1] as $m ) {
			$parsed_meta[] = [
				'key'   => $this->get_tag( $m, 'wp:meta_key' ),
				'value' => $this->get_tag( $m, 'wp:meta_value' ),
			];
		}

		return $parsed_meta;
	}

	/**
	 * Process and extract author information from a given XML element.
	 *
	 * This function takes an XML element representing an author and extracts relevant information,
	 * such as author ID, author login, author email, author display name, first name, and last name.
	 *
	 * @param string $a The XML element containing author data.
	 *
	 * @return array An array containing the extracted author information.
	 *
	 * @since 1.0.0
	 */
	public function process_author( $a ) {
		return [
			'author_id'           => $this->get_tag( $a, 'wp:author_id' ),
			'author_login'        => $this->get_tag( $a, 'wp:author_login' ),
			'author_email'        => $this->get_tag( $a, 'wp:author_email' ),
			'author_display_name' => $this->get_tag( $a, 'wp:author_display_name' ),
			'author_first_name'   => $this->get_tag( $a, 'wp:author_first_name' ),
			'author_last_name'    => $this->get_tag( $a, 'wp:author_last_name' ),
		];
	}

	/**
	 * Process and extract post information from a given XML element.
	 *
	 * This function takes an XML element representing a post and extracts relevant information,
	 * such as post ID, post title, post dates, comment status, ping status, post status, post name,
	 * post parent, menu order, post type, post password, sticky status, GUID, post author, post excerpt,
	 * post content, attachment URL, terms, comments, and post metadata.
	 *
	 * @param string $post The XML element containing post data.
	 *
	 * @return array An array containing the extracted post information.
	 *
	 * @since 1.0.0
	 */
	public function process_post( $post ) {
		$post_id        = $this->get_tag( $post, 'wp:post_id' );
		$post_title     = $this->get_tag( $post, 'title' );
		$post_date      = $this->get_tag( $post, 'wp:post_date' );
		$post_date_gmt  = $this->get_tag( $post, 'wp:post_date_gmt' );
		$comment_status = $this->get_tag( $post, 'wp:comment_status' );
		$ping_status    = $this->get_tag( $post, 'wp:ping_status' );
		$status         = $this->get_tag( $post, 'wp:status' );
		$post_name      = $this->get_tag( $post, 'wp:post_name' );
		$post_parent    = $this->get_tag( $post, 'wp:post_parent' );
		$menu_order     = $this->get_tag( $post, 'wp:menu_order' );
		$post_type      = $this->get_tag( $post, 'wp:post_type' );
		$post_password  = $this->get_tag( $post, 'wp:post_password' );
		$is_sticky      = $this->get_tag( $post, 'wp:is_sticky' );
		$guid           = $this->get_tag( $post, 'guid' );
		$post_author    = $this->get_tag( $post, 'dc:creator' );

		$post_excerpt = $this->get_tag( $post, 'excerpt:encoded' );
		$post_excerpt = preg_replace_callback( '|<(/?[A-Z]+)|', [ &$this, '_normalize_tag' ], $post_excerpt );
		$post_excerpt = str_replace( '<br>', '<br />', $post_excerpt );
		$post_excerpt = str_replace( '<hr>', '<hr />', $post_excerpt );

		$post_content = $this->get_tag( $post, 'content:encoded' );
		$post_content = preg_replace_callback( '|<(/?[A-Z]+)|', [ &$this, '_normalize_tag' ], $post_content );
		$post_content = str_replace( '<br>', '<br />', $post_content );
		$post_content = str_replace( '<hr>', '<hr />', $post_content );

		$postdata = compact(
			'post_id',
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_content',
			'post_excerpt',
			'post_title',
			'status',
			'post_name',
			'comment_status',
			'ping_status',
			'guid',
			'post_parent',
			'menu_order',
			'post_type',
			'post_password',
			'is_sticky'
		);

		$attachment_url = $this->get_tag( $post, 'wp:attachment_url' );

		if ( $attachment_url ) {
			$postdata['attachment_url'] = $attachment_url;
		}

		preg_match_all( '|<category domain="([^"]+?)" nicename="([^"]+?)">(.+?)</category>|is', $post, $terms, PREG_SET_ORDER );

		foreach ( $terms as $t ) {
			$post_terms[] = [
				'slug'   => $t[2],
				'domain' => $t[1],
				'name'   => str_replace( [ '<![CDATA[', ']]>' ], '', $t[3] ),
			];
		}

		if ( ! empty( $post_terms ) ) {
			$postdata['terms'] = $post_terms;
		}

		preg_match_all( '|<wp:comment>(.+?)</wp:comment>|is', $post, $comments );
		$comments = $comments[1];

		if ( $comments ) {
			foreach ( $comments as $comment ) {
				$post_comments[] = [
					'comment_id'           => $this->get_tag( $comment, 'wp:comment_id' ),
					'comment_author'       => $this->get_tag( $comment, 'wp:comment_author' ),
					'comment_author_email' => $this->get_tag( $comment, 'wp:comment_author_email' ),
					'comment_author_IP'    => $this->get_tag( $comment, 'wp:comment_author_IP' ),
					'comment_author_url'   => $this->get_tag( $comment, 'wp:comment_author_url' ),
					'comment_date'         => $this->get_tag( $comment, 'wp:comment_date' ),
					'comment_date_gmt'     => $this->get_tag( $comment, 'wp:comment_date_gmt' ),
					'comment_content'      => $this->get_tag( $comment, 'wp:comment_content' ),
					'comment_approved'     => $this->get_tag( $comment, 'wp:comment_approved' ),
					'comment_type'         => $this->get_tag( $comment, 'wp:comment_type' ),
					'comment_parent'       => $this->get_tag( $comment, 'wp:comment_parent' ),
					'comment_user_id'      => $this->get_tag( $comment, 'wp:comment_user_id' ),
					'commentmeta'          => $this->process_meta( $comment, 'wp:commentmeta' ),
				];
			}
		}

		if ( ! empty( $post_comments ) ) {
			$postdata['comments'] = $post_comments;
		}

		$post_meta = $this->process_meta( $post, 'wp:postmeta' );

		if ( ! empty( $post_meta ) ) {
			$postdata['postmeta'] = $post_meta;
		}

		return $postdata;
	}

	/**
	 * Normalize and convert the tag to lowercase in a callback.
	 *
	 * This function is used as a callback to normalize and convert an XML tag to lowercase.
	 *
	 * @param array $matches An array of matches from a regular expression.
	 *
	 * @return string The normalized XML tag in lowercase.
	 *
	 * @since 1.0.0
	 */
	public function _normalize_tag( $matches ) {
		return '<' . strtolower( $matches[1] );
	}

	/**
	 * Opens a file for reading or writing, considering GZIP compression if enabled.
	 *
	 * This method opens a file for reading or writing, taking into account the GZIP compression status
	 * of the instance. If GZIP compression is enabled, it uses `gzopen`, otherwise, it uses `fopen`.
	 *
	 * @param string $filename The name of the file to open.
	 * @param string $mode     The mode in which to open the file (e.g., 'r' for reading, 'w' for writing).
	 *
	 * @return resource|false A file pointer resource if successful, or false on failure.
	 *
	 * @since 1.0.0
	 */
	public function fopen( $filename, $mode = 'r' ) {
		if ( $this->has_gzip ) {
			return gzopen( $filename, $mode );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		return fopen( $filename, $mode );
	}

	/**
	 * Tests for end-of-file on a file pointer, considering GZIP compression if enabled.
	 *
	 * This method checks if the end of the file has been reached on a given file pointer resource,
	 * taking into account the GZIP compression status of the instance. If GZIP compression is enabled,
	 * it uses `gzeof`, otherwise, it uses `feof`.
	 *
	 * @param resource $fp A file pointer resource to test for end-of-file.
	 *
	 * @return bool Returns true if the end of the file has been reached, or false otherwise.
	 *
	 * @since 1.0.0
	 */
	public function feof( $fp ) {
		if ( $this->has_gzip ) {
			return gzeof( $fp );
		}

		return feof( $fp );
	}

	/**
	 * Reads a line from a file pointer, considering GZIP compression if enabled.
	 *
	 * This method reads a line from a given file pointer resource, taking into account the GZIP compression
	 * status of the instance. If GZIP compression is enabled, it uses `gzgets`, otherwise, it uses `fgets`.
	 *
	 * @param resource $fp  A file pointer resource to read from.
	 * @param int      $len The maximum length of the line to read.
	 *
	 * @return string|false Returns a string containing the line read from the file, or false on failure or EOF.
	 *
	 * @since 1.0.0
	 */
	public function fgets( $fp, $len = 8192 ) {
		if ( $this->has_gzip ) {
			return gzgets( $fp, $len );
		}

		return fgets( $fp, $len );
	}

	/**
	 * Closes a file pointer, considering GZIP compression if enabled.
	 *
	 * This method closes a given file pointer resource, taking into account the GZIP compression
	 * status of the instance. If GZIP compression is enabled, it uses `gzclose`, otherwise, it uses `fclose`.
	 *
	 * @param resource $fp A file pointer resource to close.
	 *
	 * @return bool Returns true on success or false on failure.
	 *
	 * @since 1.0.0
	 */
	public function fclose( $fp ) {
		if ( $this->has_gzip ) {
			return gzclose( $fp );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return fclose( $fp );
	}
}
