<?php
/**
 * Model Class: Widgets
 *
 * This class is responsible for importing widgets.
 * Code is mostly from the Widget Importer & Exporter plugin.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Models;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Model Class: Widgets
 *
 * @since 1.0.0
 */
class Widgets {
	/**
	 * Import widget JSON data.
	 *
	 * @param string $widgetFile Widget import file.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function import( $widgetFile ) {
		global $wp_registered_sidebars, $wp_registered_widget_controls;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( file_get_contents( $widgetFile ) );

		// Have valid data?
		// If no data or could not decode.
		if ( empty( $data ) || ! is_object( $data ) ) {
			wp_die(
				esc_html__( 'Widget data is not available', 'easy-demo-importer' ),
				'',
				[ 'back_link' => true ]
			);
		}

		$widgetControls   = $wp_registered_widget_controls;
		$availableWidgets = [];
		$widgetInstances  = [];
		$results          = [];

		foreach ( $widgetControls as $widget ) {
			if ( ! empty( $widget['id_base'] ) && ! isset( $availableWidgets[ $widget['id_base'] ] ) ) {
				$availableWidgets[ $widget['id_base'] ]['id_base'] = $widget['id_base'];
				$availableWidgets[ $widget['id_base'] ]['name']    = $widget['name'];
			}
		}

		// Get all existing widget instances.
		foreach ( $availableWidgets as $widgetData ) {
			$widgetInstances[ $widgetData['id_base'] ] = get_option( 'widget_' . $widgetData['id_base'] );
		}

		// Loop import data's sidebars.
		foreach ( $data as $sidebarId => $widgets ) {

			// Skip inactive widgets (should not be in export file).
			if ( 'wp_inactive_widgets' === $sidebarId ) {
				continue;
			}

			// Check if sidebar is available on this site. Otherwise, add widgets to inactive, and say so.
			if ( isset( $wp_registered_sidebars[ $sidebarId ] ) ) {
				$sidebarAvailable   = true;
				$useSidebarId       = $sidebarId;
				$sidebarMessageType = 'success';
				$sidebarMessage     = '';
			} else {
				$sidebarAvailable   = false;
				$useSidebarId       = 'wp_inactive_widgets'; // Add to inactive if sidebar does not exist in theme.
				$sidebarMessageType = 'error';
				$sidebarMessage     = esc_html__( 'Sidebar does not exist in theme (moving widget to Inactive)', 'easy-demo-importer' );
			}

			// Result for sidebar.
			$results[ $sidebarId ]['name']         = ! empty( $wp_registered_sidebars[ $sidebarId ]['name'] ) ? $wp_registered_sidebars[ $sidebarId ]['name'] : $sidebarId; // Sidebar name if theme supports it; otherwise ID.
			$results[ $sidebarId ]['message_type'] = $sidebarMessageType;
			$results[ $sidebarId ]['message']      = $sidebarMessage;
			$results[ $sidebarId ]['widgets']      = [];

			// Loop widgets.
			foreach ( $widgets as $widgetInstanceId => $widget ) {
				$fail = false;

				// Replace the old nav_manu ID with the new one.
				if ( isset( $widget->nav_menu ) ) {
					$widget->nav_menu = sd_edi()->getNewID( $widget->nav_menu );
				}

				// Get id_base (remove -# from end) and instance ID number.
				$idBase           = preg_replace( '/-[0-9]+$/', '', $widgetInstanceId );
				$instanceIdNumber = str_replace( $idBase . '-', '', $widgetInstanceId );

				// Does site support this widget?
				if ( ! $fail && ! isset( $availableWidgets[ $idBase ] ) ) {
					$fail              = true;
					$widgetMessageType = 'error';
					$widgetMessage     = esc_html__( 'Site does not support widget', 'easy-demo-importer' ); // Explain why widget not imported.
				}

				// Filter to modify settings object before conversion to array and import
				// Leave this filter here for backwards compatibility with manipulating objects (before conversion to array below)
				// Ideally the newer wie_widget_settings_array below will be used instead of this.
				$widget = apply_filters( 'wie_widget_settings', $widget ); // object
				// Convert multidimensional objects to multidimensional arrays
				// Some plugins like Jetpack Widget Visibility store settings as multidimensional arrays
				// Without this, they are imported as objects and cause fatal error on Widgets page
				// If this creates problems for plugins that do actually intend settings in objects then may need to consider other approach: https://wordpress.org/support/topic/problem-with-array-of-arrays
				// It is probably much more likely that arrays are used than objects, however.
				$widget = json_decode( wp_json_encode( $widget ), true );

				// Filter to modify settings array
				// This is preferred over the older wie_widget_settings filter above
				// Do before identical check because changes may make it identical to end result (such as URL replacements).
				$widget = apply_filters( 'wie_widget_settings_array', $widget );

				// Does widget with identical settings already exist in same sidebar?
				if ( ! $fail && isset( $widgetInstances[ $idBase ] ) ) {

					// Get existing widgets in this sidebar.
					$sidebarsWidgets = get_option( 'sidebars_widgets' );
					$sidebarWidgets  = ! empty( $sidebarsWidgets[ $useSidebarId ] ) ? $sidebarsWidgets[ $useSidebarId ] : []; // Check Inactive if that's where will go.
					// Loop widgets with ID base.
					$singleWidgetInstances = ! empty( $widgetInstances[ $idBase ] ) ? $widgetInstances[ $idBase ] : [];
					foreach ( $singleWidgetInstances as $checkId => $check_widget ) {

						// Is widget in same sidebar and has identical settings?
						if ( in_array( "$idBase-$checkId", $sidebarWidgets ) && (array) $widget == $check_widget ) {
							$fail              = true;
							$widgetMessageType = 'warning';
							$widgetMessage     = esc_html__( 'Widget already exists', 'easy-demo-importer' );

							break;
						}
					}
				}

				// No failure.
				if ( ! $fail ) {

					// Add widget instance.
					$singleWidgetInstances   = get_option( 'widget_' . $idBase ); // all instances for that widget ID base, get fresh every time.
					$singleWidgetInstances   = ! empty( $singleWidgetInstances ) ? $singleWidgetInstances : [ '_multiwidget' => 1 ]; // start fresh if we have to.
					$singleWidgetInstances[] = $widget; // add it
					// Get the key it was given.
					end( $singleWidgetInstances );
					$newInstanceIdNumber = key( $singleWidgetInstances );

					// If key is 0, make it 1
					// When 0, an issue can occur where adding a widget causes data from other widget to load, and the widget doesn't stick (reload wipes it).
					if ( '0' === strval( $newInstanceIdNumber ) ) {
						$newInstanceIdNumber                           = 1;
						$singleWidgetInstances[ $newInstanceIdNumber ] = $singleWidgetInstances[0];
						unset( $singleWidgetInstances[0] );
					}

					// Move _multiwidget to end of array for uniformity.
					if ( isset( $singleWidgetInstances['_multiwidget'] ) ) {
						$multiwidget = $singleWidgetInstances['_multiwidget'];
						unset( $singleWidgetInstances['_multiwidget'] );
						$singleWidgetInstances['_multiwidget'] = $multiwidget;
					}

					// Update option with new widget.
					update_option( 'widget_' . $idBase, $singleWidgetInstances );

					// Assign widget instance to sidebar.
					$sidebarsWidgets = get_option( 'sidebars_widgets' ); // which sidebars have which widgets, get fresh every time
					// Avoid rarely fatal error when the option is an empty string
					// https://github.com/churchthemes/widget-importer-exporter/pull/11.
					if ( ! $sidebarsWidgets ) {
						$sidebarsWidgets = [];
					}

					$newInstanceId                      = $idBase . '-' . $newInstanceIdNumber; // use ID number from new widget instance.
					$sidebarsWidgets[ $useSidebarId ][] = $newInstanceId; // add new instance to sidebar.

					update_option( 'sidebars_widgets', $sidebarsWidgets ); // save the amended data
					// After widget import action.
					$afterWidgetImport = [
						'sidebar'           => $useSidebarId,
						'sidebar_old'       => $sidebarId,
						'widget'            => $widget,
						'widget_type'       => $idBase,
						'widget_id'         => $newInstanceId,
						'widget_id_old'     => $widgetInstanceId,
						'widget_id_num'     => $newInstanceIdNumber,
						'widget_id_num_old' => $instanceIdNumber,
					];
					do_action( 'wie_after_widget_import', $afterWidgetImport );

					// Success message.
					if ( $sidebarAvailable ) {
						$widgetMessageType = 'success';
						$widgetMessage     = esc_html__( 'Imported', 'easy-demo-importer' );
					} else {
						$widgetMessageType = 'warning';
						$widgetMessage     = esc_html__( 'Imported to Inactive', 'easy-demo-importer' );
					}
				}

				// Result for widget instance.
				$results[ $sidebarId ]['widgets'][ $widgetInstanceId ]['name']         = ! empty( $availableWidgets[ $idBase ]['name'] ) ? $availableWidgets[ $idBase ]['name'] : $idBase;
				$results[ $sidebarId ]['widgets'][ $widgetInstanceId ]['title']        = ! empty( $widget['title'] ) ? $widget['title'] : esc_html__( 'No Title', 'easy-demo-importer' );
				$results[ $sidebarId ]['widgets'][ $widgetInstanceId ]['message_type'] = $widgetMessageType;
				$results[ $sidebarId ]['widgets'][ $widgetInstanceId ]['message']      = $widgetMessage;
			}
		}
	}
}
