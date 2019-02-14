<?php
/**
 * Implements Lazy Loading using page parsing and JS functionality.
 *
 * @link https://ewww.io
 * @package EWWW_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables EWWW IO to filter the page content and replace img elements with Lazy Load markup.
 */
class EWWWIO_Lazy_Load extends EWWWIO_Page_Parser {

	/**
	 * Base64 encoded placeholder image.
	 *
	 * @access protected
	 * @var string $placeholder_src
	 */
	protected $placeholder_src = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

	/**
	 * Indicates if we are filtering ExactDN urls.
	 *
	 * @access protected
	 * @var bool $parsing_exactdn
	 */
	protected $parsing_exactdn = false;

	/**
	 * Register (once) actions and filters for Lazy Load.
	 */
	function __construct() {
		ewwwio_debug_message( 'firing up lazy load' );
		global $ewwwio_lazy_load;
		if ( is_object( $ewwwio_lazy_load ) ) {
			ewwwio_debug_message( 'you are doing it wrong' );
			return 'you are doing it wrong';
		}
		// Start an output buffer before any output starts.
		add_action( 'template_redirect', array( $this, 'buffer_start' ), 1 );

		if ( class_exists( 'ExactDN' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_exactdn' ) ) {
			global $exactdn;
			$this->exactdn_domain = $exactdn->get_exactdn_domain();
			if ( $this->exactdn_domain ) {
				$this->parsing_exactdn = true;
				ewwwio_debug_message( 'parsing an exactdn page' );
			}
		}

		// Filter early, so that others at the default priority take precendence.
		add_filter( 'ewww_image_optimizer_use_lqip', array( $this, 'maybe_lqip' ), 9 );

		// Load the appropriate JS.
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			// Load the non-minified, non-inline version of the webp rewrite script.
			add_action( 'wp_enqueue_scripts', array( $this, 'debug_script' ) );
		} else {
			// Load the minified, non-inline version of the webp rewrite script.
			add_action( 'wp_enqueue_scripts', array( $this, 'min_script' ) );
		}
	}


	/**
	 * Starts an output buffer and registers the callback function to do WebP replacement.
	 */
	function buffer_start() {
		ob_start( array( $this, 'filter_page_output' ) );
	}

	/**
	 * Copies attributes from the original img element to the noscript element.
	 *
	 * @param string $image The full text of the img element.
	 * @param string $nscript A noscript element that will be given all the (known) attributes of $image.
	 * @param string $prefix Optional. Value to prepend to all attribute names. Default 'data-'.
	 * @return string The modified noscript tag.
	 */
	function attr_copy( $image, $nscript, $prefix = 'data-' ) {
		if ( ! is_string( $image ) || ! is_string( $nscript ) ) {
			return $nscript;
		}
		$attributes = array(
			'align',
			'alt',
			'border',
			'crossorigin',
			'height',
			'hspace',
			'ismap',
			'longdesc',
			'usemap',
			'vspace',
			'width',
			'accesskey',
			'class',
			'contenteditable',
			'contextmenu',
			'dir',
			'draggable',
			'dropzone',
			'hidden',
			'id',
			'lang',
			'spellcheck',
			'style',
			'tabindex',
			'title',
			'translate',
			'sizes',
			'data-caption',
			'data-lazy-type',
			'data-attachment-id',
			'data-permalink',
			'data-orig-size',
			'data-comments-opened',
			'data-image-meta',
			'data-image-title',
			'data-image-description',
			'data-event-trigger',
			'data-highlight-color',
			'data-highlight-opacity',
			'data-highlight-border-color',
			'data-highlight-border-width',
			'data-highlight-border-opacity',
			'data-no-lazy',
			'data-lazy',
			'data-large_image_width',
			'data-large_image_height',
		);
		foreach ( $attributes as $attribute ) {
			$attr_value = $this->get_attribute( $image, $attribute );
			if ( $attr_value ) {
				$this->set_attribute( $nscript, $prefix . $attribute, $attr_value );
			}
		}
		return $nscript;
	}

	/**
	 * Replaces images within a srcset attribute with their .webp derivatives.
	 *
	 * @param string $srcset A valid srcset attribute from an img element.
	 * @return bool|string False if no changes were made, or the new srcset if any WebP images replaced the originals.
	 */
	function srcset_replace( $srcset ) {
		$srcset_urls = explode( ' ', $srcset );
		$found_webp  = false;
		if ( ewww_image_optimizer_iterable( $srcset_urls ) && count( $srcset_urls ) > 1 ) {
			ewwwio_debug_message( 'parsing srcset urls' );
			foreach ( $srcset_urls as $srcurl ) {
				if ( is_numeric( substr( $srcurl, 0, 1 ) ) ) {
					continue;
				}
				$trailing = ' ';
				if ( ',' === substr( $srcurl, -1 ) ) {
					$trailing = ',';
					$srcurl   = rtrim( $srcurl, ',' );
				}
				ewwwio_debug_message( "looking for $srcurl from srcset" );
				if ( $this->validate_image_tag( $srcurl ) ) {
					$srcset = str_replace( $srcurl . $trailing, $this->generate_url( $srcurl ) . $trailing, $srcset );
					ewwwio_debug_message( "replaced $srcurl in srcset" );
					$found_webp = true;
				}
			}
		} elseif ( $this->validate_image_tag( $srcset ) ) {
			return $this->generate_url( $srcset );
		}
		if ( $found_webp ) {
			return $srcset;
		} else {
			return false;
		}
	}

	/**
	 * Replaces images with the Jetpack data attributes with their .webp derivatives.
	 *
	 * @param string $image The full text of the img element.
	 * @param string $nscript A noscript element that will be assigned the jetpack data attributes.
	 * @return string The modified noscript tag.
	 */
	function jetpack_replace( $image, $nscript ) {
		$data_orig_file = $this->get_attribute( $image, 'data-orig-file' );
		if ( $data_orig_file ) {
			ewwwio_debug_message( "looking for data-orig-file: $data_orig_file" );
			if ( $this->validate_image_tag( $data_orig_file ) ) {
				$this->set_attribute( $nscript, 'data-webp-orig-file', $this->generate_url( $data_orig_file ) );
				ewwwio_debug_message( "replacing $data_orig_file in data-orig-file" );
			}
			$this->set_attribute( $nscript, 'data-orig-file', $data_orig_file, true );
		}
		$data_medium_file = $this->get_attribute( $image, 'data-medium-file' );
		if ( $data_medium_file ) {
			ewwwio_debug_message( "looking for data-medium-file: $data_medium_file" );
			if ( $this->validate_image_tag( $data_medium_file ) ) {
				$this->set_attribute( $nscript, 'data-webp-medium-file', $this->generate_url( $data_medium_file ) );
				ewwwio_debug_message( "replacing $data_medium_file in data-medium-file" );
			}
			$this->set_attribute( $nscript, 'data-medium-file', $data_medium_file, true );
		}
		$data_large_file = $this->get_attribute( $image, 'data-large-file' );
		if ( $data_large_file ) {
			ewwwio_debug_message( "looking for data-large-file: $data_large_file" );
			if ( $this->validate_image_tag( $data_large_file ) ) {
				$this->set_attribute( $nscript, 'data-webp-large-file', $this->generate_url( $data_large_file ) );
				ewwwio_debug_message( "replacing $data_large_file in data-large-file" );
			}
			$this->set_attribute( $nscript, 'data-large-file', $data_large_file, true );
		}
		return $nscript;
	}

	/**
	 * Replaces images with the WooCommerce data attributes with their .webp derivatives.
	 *
	 * @param string $image The full text of the img element.
	 * @param string $nscript A noscript element that will be assigned the WooCommerce data attributes.
	 * @return string The modified noscript tag.
	 */
	function woocommerce_replace( $image, $nscript ) {
		$data_large_image = $this->get_attribute( $image, 'data-large_image' );
		if ( $data_large_image ) {
			ewwwio_debug_message( "looking for data-large_image: $data_large_image" );
			if ( $this->validate_image_tag( $data_large_image ) ) {
				$this->set_attribute( $nscript, 'data-webp-large_image', $this->generate_url( $data_large_image ) );
				ewwwio_debug_message( "replacing $data_large_image in data-large_image" );
			}
			$this->set_attribute( $nscript, 'data-large_image', $data_large_image );
		}
		$data_src = $this->get_attribute( $image, 'data-src' );
		if ( $data_src ) {
			ewwwio_debug_message( "looking for data-src: $data_src" );
			if ( $this->validate_image_tag( $data_src ) ) {
				$this->set_attribute( $nscript, 'data-webp-src', $this->generate_url( $data_src ) );
				ewwwio_debug_message( "replacing $data_src in data-src" );
			}
			$this->set_attribute( $nscript, 'data-src', $data_src );
		}
		return $nscript;
	}

	/**
	 * Search for img elements and rewrite them for Lazy Load with fallback to noscript elements.
	 *
	 * @param string $buffer The full HTML page generated since the output buffer was started.
	 * @return string The altered buffer containing the full page with Lazy Load attributes.
	 */
	function filter_page_output( $buffer ) {
		ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
		// If this is an admin page, don't filter it.
		if ( ( empty( $buffer ) || is_admin() ) ) {
			return $buffer;
		}
		$uri = $_SERVER['REQUEST_URI'];
		// Based on the uri, if this is a cornerstone editing page, don't filter the response.
		if ( ! empty( $_GET['cornerstone'] ) || strpos( $uri, 'cornerstone-endpoint' ) !== false ) {
			return $buffer;
		}
		if ( ! empty( $_GET['et_fb'] ) ) {
			return $buffer;
		}
		if ( ! empty( $_GET['tatsu'] ) ) {
			return $buffer;
		}
		if ( ! empty( $_POST['action'] ) && 'tatsu_get_concepts' === $_POST['action'] ) {
			return $buffer;
		}
		// If this is XML (not XHTML), don't modify the page.
		if ( preg_match( '/<\?xml/', $buffer ) ) {
			return $buffer;
		}
		if ( strpos( $buffer, 'amp-boilerplate' ) ) {
			ewwwio_debug_message( 'AMP page processing' );
			return $buffer;
		}

		$above_the_fold   = apply_filters( 'ewww_image_optimizer_lazy_fold', 0 );
		$images_processed = 0;

		$images = $this->get_images_from_html( $buffer, false );
		if ( ewww_image_optimizer_iterable( $images[0] ) ) {
			foreach ( $images[0] as $index => $image ) {
				$images_processed++;
				if ( $images_processed <= $above_the_fold ) {
					continue;
				}
				$file = $images['img_url'][ $index ];
				ewwwio_debug_message( "parsing an image: $file" );
				if ( $this->validate_image_tag( $image ) ) {
					ewwwio_debug_message( 'found a valid image tag' );
					$orig_img = $image;
					$noscript = '<noscript>' . $orig_img . '</noscript>';
					$this->set_attribute( $image, 'data-src', $file, true );
					$srcset = $this->get_attribute( $image, 'srcset' );

					$placeholder_src = $this->placeholder_src;
					if ( apply_filters( 'ewww_image_optimizer_use_lqip', true ) && $this->parsing_exactdn && strpos( $file, $this->exactdn_domain ) ) {
						$placeholder_src = add_query_arg( array( 'lazy' => 1 ), $file );
						ewwwio_debug_message( "current placeholder is $placeholder_src" );
					}

					if ( $srcset ) {
						if ( strpos( $placeholder_src, '64,R0lGOD' ) ) {
							$this->set_attribute( $image, 'srcset', $placeholder_src, true );
							$this->remove_attribute( $image, 'src' );
						} else {
							$this->set_attribute( $image, 'src', $placeholder_src, true );
							$this->remove_attribute( $image, 'srcset' );
						}
						$this->set_attribute( $image, 'data-srcset', $srcset, true );
					} else {
						$this->set_attribute( $image, 'src', $placeholder_src, true );
					}
					$this->set_attribute( $image, 'class', $this->get_attribute( $image, 'class' ) . ' lazyload', true );
					$buffer = str_replace( $orig_img, $image . $noscript, $buffer );
					/* ewwwio_debug_message( "going to swap\n$orig_img\nwith\n$image" ); */
				}
				// Rev Slider data-lazyload attribute on image elements.
				if ( false && $this->get_attribute( $image, 'data-lazyload' ) ) {
					$new_image = $image;
					$lazyload  = $this->get_attribute( $new_image, 'data-lazyload' );
					if ( $lazyload ) {
						if ( $this->validate_image_tag( $lazyload ) ) {
							$this->set_attribute( $new_image, 'data-webp-lazyload', $this->generate_url( $lazyload ) );
							ewwwio_debug_message( "replacing with webp for data-lazyload: $lazyload" );
							$buffer = str_replace( $image, $new_image, $buffer );
						}
					}
				}
			} // End foreach().
		} // End if().
		// Look for images to parse WP Retina Lazy Load.
		if ( false && class_exists( 'Meow_WR2X_Core' ) && strpos( $buffer, ' lazyload' ) ) {
			$images = $this->get_elements_from_html( $buffer, 'img' );
			if ( ewww_image_optimizer_iterable( $images ) ) {
				foreach ( $images as $index => $image ) {
					$file = $this->get_attribute( $image, 'src' );
					if ( ( empty( $file ) || strpos( $image, 'R0lGODlhAQABAIAAAAAAAP' ) ) && strpos( $image, ' data-srcset=' ) && strpos( $this->get_attribute( $image, 'class' ), 'lazyload' ) ) {
						$new_image = $image;
						$srcset    = $this->get_attribute( $new_image, 'data-srcset' );
						ewwwio_debug_message( 'checking webp for Retina Lazy Load data-src' );
						if ( $srcset ) {
							$srcset_webp = $this->srcset_replace( $srcset );
							if ( $srcset_webp ) {
								$this->set_attribute( $new_image, 'data-srcset-webp', $srcset_webp );
							}
						}
						if ( $new_image !== $image ) {
							$this->set_attribute( $new_image, 'class', $this->get_attribute( $new_image, 'class' ) . ' ewww_webp_lazy_load', true );
							$buffer = str_replace( $image, $new_image, $buffer );
						}
					}
				}
			}
		}
		// Images listed as picture/source elements. Mostly for NextGEN, but should work anywhere.
		/* $pictures = $this->get_picture_tags_from_html( $buffer ); */
		if ( ewww_image_optimizer_iterable( $pictures ) ) {
			foreach ( $pictures as $index => $picture ) {
				$sources = $this->get_elements_from_html( $picture, 'source' );
				if ( ewww_image_optimizer_iterable( $sources ) ) {
					foreach ( $sources as $source ) {
						ewwwio_debug_message( "parsing a picture source: $source" );
						$srcset = $this->get_attribute( $source, 'srcset' );
						if ( $srcset ) {
							$srcset_webp = $this->srcset_replace( $srcset );
							if ( $srcset_webp ) {
								$source_webp = str_replace( $srcset, $srcset_webp, $source );
								$this->set_attribute( $source_webp, 'type', 'image/webp' );
								$picture = str_replace( $source, $source_webp . $source, $picture );
							}
						}
					}
					if ( $picture != $pictures[ $index ] ) {
						ewwwio_debug_message( 'found webp for picture element' );
						$buffer = str_replace( $pictures[ $index ], $picture, $buffer );
					}
				}
			}
		}
		// NextGEN slides listed as 'a' elements.
		/* $links = $this->get_elements_from_html( $buffer, 'a' ); */
		if ( ewww_image_optimizer_iterable( $links ) ) {
			foreach ( $links as $index => $link ) {
				ewwwio_debug_message( "parsing a link $link" );
				$file  = $this->get_attribute( $link, 'data-src' );
				$thumb = $this->get_attribute( $link, 'data-thumbnail' );
				if ( $file && $thumb ) {
					ewwwio_debug_message( "checking webp for ngg data-src: $file" );
					if ( $this->validate_image_tag( $file ) ) {
						$this->set_attribute( $link, 'data-webp', $this->generate_url( $file ) );
						ewwwio_debug_message( "found webp for ngg data-src: $file" );
					}
					ewwwio_debug_message( "checking webp for ngg data-thumbnail: $thumb" );
					if ( $this->validate_image_tag( $thumb ) ) {
						$this->set_attribute( $link, 'data-webp-thumbnail', $this->generate_url( $thumb ) );
						ewwwio_debug_message( "found webp for ngg data-thumbnail: $thumb" );
					}
				}
				if ( $link != $links[ $index ] ) {
					$buffer = str_replace( $links[ $index ], $link, $buffer );
				}
			}
		}
		// Revolution Slider 'li' elements.
		/* $listitems = $this->get_elements_from_html( $buffer, 'li' ); */
		if ( ewww_image_optimizer_iterable( $listitems ) ) {
			foreach ( $listitems as $index => $listitem ) {
				ewwwio_debug_message( 'parsing a listitem' );
				if ( $this->get_attribute( $listitem, 'data-title' ) === 'Slide' && ( $this->get_attribute( $listitem, 'data-lazyload' ) || $this->get_attribute( $listitem, 'data-thumb' ) ) ) {
					$thumb = $this->get_attribute( $listitem, 'data-thumb' );
					ewwwio_debug_message( "checking webp for revslider data-thumb: $thumb" );
					if ( $this->validate_image_tag( $thumb ) ) {
						$this->set_attribute( $listitem, 'data-webp-thumb', $this->generate_url( $thumb ) );
						ewwwio_debug_message( "found webp for revslider data-thumb: $thumb" );
					}
					$param_num = 1;
					while ( $param_num < 11 ) {
						$parameter = $this->get_attribute( $listitem, 'data-param' . $param_num );
						if ( $parameter ) {
							ewwwio_debug_message( "checking webp for revslider data-param$param_num: $parameter" );
							if ( strpos( $parameter, 'http' ) === 0 ) {
								ewwwio_debug_message( "looking for $parameter" );
								if ( $this->validate_image_tag( $parameter ) ) {
									$this->set_attribute( $listitem, 'data-webp-param' . $param_num, $this->generate_url( $parameter ) );
									ewwwio_debug_message( "found webp for data-param$param_num: $parameter" );
								}
							}
						}
						$param_num++;
					}
					if ( $listitem != $listitems[ $index ] ) {
						$buffer = str_replace( $listitems[ $index ], $listitem, $buffer );
					}
				}
			} // End foreach().
		} // End if().
		// WooCommerce thumbs listed as 'div' elements.
		/* $divs = $this->get_elements_from_html( $buffer, 'div' ); */
		if ( ewww_image_optimizer_iterable( $divs ) ) {
			foreach ( $divs as $index => $div ) {
				ewwwio_debug_message( 'parsing a div' );
				$thumb     = $this->get_attribute( $div, 'data-thumb' );
				$div_class = $this->get_attribute( $div, 'class' );
				if ( $div_class && $thumb && strpos( $div_class, 'woocommerce-product-gallery__image' ) !== false ) {
					ewwwio_debug_message( "checking webp for WC data-thumb: $thumb" );
					if ( $this->validate_image_tag( $thumb ) ) {
						$this->set_attribute( $div, 'data-webp-thumb', $this->generate_url( $thumb ) );
						ewwwio_debug_message( "found webp for WC data-thumb: $thumb" );
						$buffer = str_replace( $divs[ $index ], $div, $buffer );
					}
				}
			}
		}
		// Video elements, looking for poster attributes that are images.
		/* $videos = $this->get_elements_from_html( $buffer, 'video' ); */
		if ( ewww_image_optimizer_iterable( $videos ) ) {
			foreach ( $videos as $index => $video ) {
				ewwwio_debug_message( 'parsing a video element' );
				$file = $this->get_attribute( $video, 'poster' );
				if ( $file ) {
					ewwwio_debug_message( "checking webp for video poster: $file" );
					if ( $this->validate_image_tag( $file ) ) {
						$this->set_attribute( $video, 'data-poster-webp', $this->generate_url( $file ) );
						$this->set_attribute( $video, 'data-poster-image', $file );
						$this->remove_attribute( $video, 'poster' );
						ewwwio_debug_message( "found webp for video poster: $file" );
						$buffer = str_replace( $videos[ $index ], $video, $buffer );
					}
				}
			}
		}
		ewwwio_debug_message( 'all done parsing page for alt webp' );
		if ( true ) { // Set to true for extra logging.
			ewww_image_optimizer_debug_log();
		}
		return $buffer;
	}

	/**
	 * Checks if the tag is allowed to be lazy loaded.
	 *
	 * @param string $image The image (img) tag.
	 * @return bool True if the tag is allowed, false otherwise.
	 */
	function validate_image_tag( $image ) {
		if ( strpos( $image, 'assets/images/dummy.png' ) || strpos( $image, 'base64,R0lGOD' ) || strpos( $image, 'lazy-load/images/1x1' ) || strpos( $image, 'assets/images/transparent.png' ) || strpos( $image, 'assets/images/lazy' ) ) {
			ewwwio_debug_message( 'lazy load placeholder' );
			return false;
		}
		// TODO: properly validate and exclude certain cases (see WP Rocket for some good examples, I'm sure others have some too).
		return true;
		if ( $this->parsing_exactdn && false !== strpos( $image, $this->exactdn_domain ) ) {
			ewwwio_debug_message( 'exactdn image' );
			return true;
		}
		return false;
	}

	/**
	 * Generate a WebP url.
	 *
	 * Adds .webp to the end, or adds a webp parameter for ExactDN urls.
	 *
	 * @param string $url The image url.
	 * @return string The WebP version of the image url.
	 */
	function generate_url( $url ) {
		if ( $this->parsing_exactdn && false !== strpos( $url, $this->exactdn_domain ) ) {
			return add_query_arg( 'webp', 1, $url );
		} else {
			$path_parts = explode( '?', $url );
			return $path_parts[0] . '.webp' . ( ! empty( $path_parts[1] ) && 'is-pending-load=1' !== $path_parts[1] ? '?' . $path_parts[1] : '' );
		}
		return $url;
	}

	/**
	 * Check if LQIP should be used, but allow filters to alter the option.
	 *
	 * @param bool $use_lqip Whether LL should use low-quality image placeholders.
	 * @return bool True to use LQIP, false to skip them.
	 */
	function maybe_lqip( $use_lqip ) {
		if ( defined( 'EWWW_IMAGE_OPTIMIZER_USE_LQIP' ) && ! EWWW_IMAGE_OPTIMIZER_USE_LQIP ) {
			return false;
		}
		return $use_lqip;
	}

	/**
	 * Load full webp script when SCRIPT_DEBUG is enabled.
	 */
	function debug_script() {
		if ( ! ewww_image_optimizer_ce_webp_enabled() ) {
			wp_enqueue_script( 'ewww-lazy-load-script', plugins_url( '/includes/lazysizes.js', EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE ), array(), EWWW_IMAGE_OPTIMIZER_VERSION );
		}
	}

	/**
	 * Load minified webp script when EWWW_IMAGE_OPTIMIZER_WEBP_EXTERNAL_SCRIPT is set.
	 */
	function min_script() {
		if ( ! ewww_image_optimizer_ce_webp_enabled() ) {
			wp_enqueue_script( 'ewww-lazy-load-script', plugins_url( '/includes/lazysizes.min.js', EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE ), array(), EWWW_IMAGE_OPTIMIZER_VERSION );
		}
	}
}

global $ewwwio_lazy_load;
$ewwwio_lazy_load = new EWWWIO_Lazy_Load();