/**
 * Admin JS
 */

/* global rtdiAdminParams */

'use strict';

// Components
// import { rtsbAddToCart } from './components/add-to-cart';

(function ($) {
	// DOM Ready Event
	$(document).ready(() => {
		rtdiDemoImporter.init();
	});

	// Window Load Event
	$(window).on('load', () => {
		// rtsbFrontend.fixBuilderJumping();
	});

	// General Frontend Obj
	const rtdiDemoImporter = {
		init: () => {
			if ($('.rtdi-tab-filter').length > 0) {
				$('.rtdi-tab-group').each(function () {
					$(this).find('.rtdi-tab:first').addClass('rtdi-active');
				});

				// init Isotope
				var $grid = $('.rtdi-demo-box-wrap').imagesLoaded(function () {
					$grid.isotope({
						itemSelector: '.rtdi-demo-box',
					});
				});

				// store filter for each group
				const filters = {};

				$('.rtdi-tab-group').on('click', '.rtdi-tab', function (event) {
					const $button = $(event.currentTarget);
					// get group key
					const $buttonGroup = $button.parents('.rtdi-tab-group');
					const filterGroup = $buttonGroup.attr('data-filter-group');
					// set filter for group
					filters[filterGroup] = $button.attr('data-filter');
					// combine filters
					const filterValue = concatValues(filters);
					// set filter for Isotope
					$grid.isotope({ filter: filterValue });
				});

				// change is-checked class on buttons
				$('.rtdi-tab-group').each(function (i, buttonGroup) {
					const $buttonGroup = $(buttonGroup);
					$buttonGroup.on('click', '.rtdi-tab', function (event) {
						$buttonGroup
							.find('.rtdi-active')
							.removeClass('rtdi-active');
						const $button = $(event.currentTarget);
						$button.addClass('rtdi-active');
					});
				});

				// flatten object by concatting values
				function concatValues(obj) {
					let value = '';
					for (const prop in obj) {
						value += obj[prop];
					}
					return value;
				}
			}

			$('.rtdi-modal-button').on('click', function (e) {
				e.preventDefault();
				$('body').addClass('rtdi-modal-opened');
				const modalId = $(this).attr('href');
				$(modalId).fadeIn();

				$('html, body').animate({ scrollTop: 0 }, 'slow');
			});

			$('.rtdi-modal-back, .rtdi-modal-cancel').on('click', function (e) {
				$('body').removeClass('rtdi-modal-opened');
				$('.rtdi-modal').hide();
				$('html, body').animate({ scrollTop: 0 }, 'slow');
			});

			$('body').on('click', '.rtdi-import-demo', function () {
				const $el = $(this);
				const demo = $(this).attr('data-demo-slug');
				const reset = $('#checkbox-reset-' + demo).is(':checked');
				const excludeImages = $('#checkbox-exclude-image-' + demo).is(
					':checked'
				);
				let resetMessage = '';

				if (reset) {
					resetMessage = rtdiAdminParams.resetDatabase;
					var confirmMessage =
						'Are you sure to proceed? Resetting the database will delete all your contents.';
				} else {
					var confirmMessage = 'Are you sure to proceed?';
				}

				const $importTrue = confirm(confirmMessage);

				if ($importTrue == false) {
					return;
				}

				$('html, body').animate({ scrollTop: 0 }, 'slow');

				$('#rtdi-modal-' + demo).hide();
				$('#rtdi-import-progress').show();

				$('#rtdi-import-progress .rtdi-import-progress-message')
					.html(rtdiAdminParams.prepareImporting)
					.fadeIn();

				const info = {
					demo,
					reset,
					nextPhase: 'rtdi_install_demo',
					excludeImages,
					nextPhaseMessage: resetMessage,
				};

				setTimeout(function () {
					do_ajax(info);
				}, 2000);
			});

			function do_ajax(info) {
				if (info.nextPhase) {
					const data = {
						action: info.nextPhase,
						demo: info.demo,
						reset: info.reset,
						excludeImages: info.excludeImages,
						__rtdi_wpnonce: rtdiAdminParams.__rtdi_wpnonce,
					};

					jQuery.ajax({
						url: rtdiAdminParams.ajaxurl,
						type: 'post',
						data,
						beforeSend() {
							if (info.nextPhaseMessage) {
								$(
									'#rtdi-import-progress .rtdi-import-progress-message'
								)
									.hide()
									.html('')
									.fadeIn()
									.html(info.nextPhaseMessage);
							}
						},
						success(response) {
							const info = JSON.parse(response);

							if (!info.error) {
								if (info.completedMessage) {
									$(
										'#rtdi-import-progress .rtdi-import-progress-message'
									)
										.hide()
										.html('')
										.fadeIn()
										.html(info.completedMessage);
								}
								setTimeout(function () {
									do_ajax(info);
								}, 2000);
							} else {
								$(
									'#rtdi-import-progress .rtdi-import-progress-message'
								).html(info.errorMessage);
								$('#rtdi-import-progress').addClass(
									'import-error'
								);
							}
						},
						error(xhr, status, error) {
							const errorMessage =
								xhr.status + ': ' + xhr.statusText;
							$(
								'#rtdi-import-progress .rtdi-import-progress-message'
							).html(rtdiAdminParams.importError);
							$('#rtdi-import-progress').addClass('import-error');
						},
					});
				} else {
					$(
						'#rtdi-import-progress .rtdi-import-progress-message'
					).html(rtdiAdminParams.importSuccess);
					$('#rtdi-import-progress').addClass('import-success');
				}
			}
		},
	};
})(jQuery);
