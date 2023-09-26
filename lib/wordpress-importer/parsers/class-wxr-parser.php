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
 * WordPress Importer class for managing parsing of WXR files.
 */
class SD_EDI_WXR_Parser {
	/**
	 * Attempts to parse a WordPress WXR (WordPress eXtended RSS) file using available XML parsers.
	 *
	 * This method first attempts to use proper XML parsers (SimpleXML and XMLParser) to parse the WXR file.
	 * If parsing with XML parsers succeeds or the WXR file is invalid, it returns the parsed results.
	 * If parsing with XML parsers fails due to malformed XML, it displays error details and then falls back to parsing with regular expressions.
	 *
	 * @param string $file The path to the WXR file to parse.
	 *
	 * @return array|WP_Error An array containing parsed data including authors, posts, categories, tags, and terms,
	 *                        or a WP_Error object if there was an error during parsing.
	 *
	 * @since 1.0.0
	 */
	public function parse( $file ) {
		// Attempt to use proper XML parsers first.
		if ( extension_loaded( 'simplexml' ) ) {
			$parser = new SD_EDI_WXR_Parser_SimpleXML();
			$result = $parser->parse( $file );

			// If SimpleXML succeeds or this is an invalid WXR file then return the results.
			if ( ! is_wp_error( $result ) || 'SimpleXML_parse_error' != $result->get_error_code() ) {
				return $result;
			}
		} elseif ( extension_loaded( 'xml' ) ) {
			$parser = new SD_EDI_WXR_Parser_XML();
			$result = $parser->parse( $file );

			// If XMLParser succeeds or this is an invalid WXR file then return the results.
			if ( ! is_wp_error( $result ) || 'XML_parse_error' != $result->get_error_code() ) {
				return $result;
			}
		}

		// use regular expressions if nothing else available or this is bad XML.
		$parser = new SD_EDI_WXR_Parser_Regex();

		return $parser->parse( $file );
	}
}
