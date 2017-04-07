<?php
/**
 * Class EWWWIO_Optimize_Tests
 *
 * @link https://ewww.io
 * @package Ewww_Image_Optimizer
 */

// TODO: do the autorotation functions too.
// TODO: add a PDF file for testing also.
// TODO: add webp to the keep meta test for JPG.
/**
 * Optimization test cases.
 */
class EWWWIO_Optimize_Tests extends WP_UnitTestCase {

	/**
	 * The location of the test JPG image.
	 *
	 * @var string $test_jpg
	 */
	public static $test_jpg = '';

	/**
	 * The location of the test PNG image.
	 *
	 * @var string $test_png
	 */
	public static $test_png = '';

	/**
	 * The location of the test GIF image.
	 *
	 * @var string $test_gif
	 */
	public static $test_gif = '';

	/**
	 * Downloads test images.
	 */
	public static function setUpBeforeClass() {
		self::$test_jpg = download_url( 'https://s3-us-west-2.amazonaws.com/exactlywww/20170314_174652.jpg' );
		self::$test_png = download_url( 'https://s3-us-west-2.amazonaws.com/exactlywww/books.png' );
		self::$test_gif = download_url( 'https://s3-us-west-2.amazonaws.com/exactlywww/gifsiclelogo.gif' );
		ewww_image_optimizer_set_defaults();
		update_option( 'ewww_image_optimizer_webp', true );
		update_option( 'ewww_image_optimizer_png_level', 40 );
		ewww_image_optimizer_install_tools();
		ewww_image_optimizer_install_pngout();
		update_option( 'ewww_image_optimizer_webp', '' );
		update_option( 'ewww_image_optimizer_png_level', 10 );
	}

	/**
	 * Initializes the plugin and installs the ewwwio_images table.
	 */
	function setUp() {
		parent::setUp();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		ewww_image_optimizer_install_table();
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
	}

	/**
	 * Copies the test JPG to a temp file, optimizes it, and returns the results.
	 *
	 * @return array The results of the ewww_image_optimizer() function.
	 */
	protected function optimize_jpg() {
		$_REQUEST['ewww_force'] = 1;
		$filename = self::$test_jpg . ".jpg";
		copy( self::$test_jpg, $filename );
		$results = ewww_image_optimizer( $filename );
		return $results;
	}

	/**
	 * Copies the test PNG to a temp file, optimizes it, and returns the results.
	 *
	 * @return array The results of the ewww_image_optimizer() function.
	 */
	protected function optimize_png() {
		$_REQUEST['ewww_force'] = 1;
		$filename = self::$test_png . ".png";
		copy( self::$test_png, $filename );
		$results = ewww_image_optimizer( $filename );
		return $results;
	}

	/**
	 * Copies the test GIF to a temp file, optimizes it, and returns the results.
	 *
	 * @return array The results of the ewww_image_optimizer() function.
	 */
	protected function optimize_gif() {
		$_REQUEST['ewww_force'] = 1;
		$filename = self::$test_gif . ".gif";
		copy( self::$test_gif, $filename );
		$results = ewww_image_optimizer( $filename );
		return $results;
	}

	/**
	 * Test default JPG optimization.
	 */
	function test_optimize_jpg_10() {
		update_option( 'ewww_image_optimizer_jpegtran_copy', true );
		update_option( 'ewww_image_optimizer_jpg_level', 10 );
		update_option( 'ewww_image_optimizer_webp', true );
		$results = $this->optimize_jpg();
		update_option( 'ewww_image_optimizer_webp', '' );
		$this->assertEquals( 1395225, filesize( $results[0] ) );
		unlink( $results[0] );
		$this->assertEquals( 394774, filesize( $results[0] . '.webp' ) );
		if ( is_file( $results[0] . '.webp' ) ) {
			unlink( $results[0] . '.webp' );
		}
	}

	/**
	 * Test lossless JPG and keeps meta.
	 */
	function test_optimize_jpg_10_keep_meta() {
		update_option( 'ewww_image_optimizer_jpegtran_copy', '' );
		update_option( 'ewww_image_optimizer_jpg_level', 10 );
		$results = $this->optimize_jpg();
		$this->assertEquals( 1413662, filesize( $results[0] ) );
		unlink( $results[0] );
	}

	/**
	 * Test lossless PNG with optipng.
	 */
	function test_optimize_png_10_optipng() {
		update_option( 'ewww_image_optimizer_png_level', 10 );
		update_option( 'ewww_image_optimizer_disable_pngout', true );
		update_option( 'ewww_image_optimizer_optipng_level', 2 );
		update_option( 'ewww_image_optimizer_jpegtran_copy', true );
		update_option( 'ewww_image_optimizer_webp', true );
		$results = $this->optimize_png();
		update_option( 'ewww_image_optimizer_webp', '' );
		$this->assertEquals( 188043, filesize( $results[0] ) );
		unlink( $results[0] );
		$this->assertEquals( 137258, filesize( $results[0] . '.webp' ) );
		if ( is_file( $results[0] . '.webp' ) ) {
			unlink( $results[0] . '.webp' );
		}
	}

	/**
	 * Test lossless PNG with optipng, keeping metadata.
	 */
	function test_optimize_png_10_optipng_keep_meta() {
		update_option( 'ewww_image_optimizer_png_level', 10 );
		update_option( 'ewww_image_optimizer_disable_pngout', true );
		update_option( 'ewww_image_optimizer_optipng_level', 2 );
		update_option( 'ewww_image_optimizer_jpegtran_copy', '' );
		$results = $this->optimize_png();
		$this->assertEquals( 190775, filesize( $results[0] ) );
		unlink( $results[0] );
	}

	/**
	 * Test lossless PNG with optipng and PNGOUT.
	 */
	function test_optimize_png_10_optipng_pngout() {
		update_option( 'ewww_image_optimizer_png_level', 10 );
		update_option( 'ewww_image_optimizer_disable_pngout', '' );
		update_option( 'ewww_image_optimizer_optipng_level', 2 );
		update_option( 'ewww_image_optimizer_pngout_level', 1 );
		update_option( 'ewww_image_optimizer_jpegtran_copy', true );
		$results = $this->optimize_png();
		$this->assertEquals( 180779, filesize( $results[0] ) );
		unlink( $results[0] );
	}

	/**
	 * Test lossy local PNG with optipng.
	 */
	function test_optimize_png_40_optipng() {
		update_option( 'ewww_image_optimizer_png_level', 40 );
		update_option( 'ewww_image_optimizer_disable_pngout', true );
		update_option( 'ewww_image_optimizer_optipng_level', 2 );
		update_option( 'ewww_image_optimizer_jpegtran_copy', true );
		$results = $this->optimize_png();
		$this->assertEquals( 35105, filesize( $results[0] ) );
		unlink( $results[0] );
	}

	/**
	 * Test lossless GIF.
	 */
	function test_optimize_gif_10() {
		update_option( 'ewww_image_optimizer_gif_level', 10 );
		$results = $this->optimize_gif();
		$this->assertEquals( 8900, filesize( $results[0] ) );
		unlink( $results[0] );
	}

	/**
	 * Cleans up ewwwio_images table.
	 */
	function tearDown() {
		global $wpdb;
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( "DROP TABLE IF EXISTS $wpdb->ewwwio_images" );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		delete_option( 'ewww_image_optimizer_version' );
		delete_option( 'ewww_image_optimizer_cloud_key' );
		parent::tearDown();
	}

	/**
	 * Cleans up the temp images.
	 */
	public static function tearDownAfterClass() {
		if ( is_file( self::$test_jpg ) ) {
			unlink( self::$test_jpg );
		}
		if ( is_file( self::$test_png ) ) {
			unlink( self::$test_png );
		}
		if ( is_file( self::$test_gif ) ) {
			unlink( self::$test_gif );
		}
		ewww_image_optimizer_remove_binaries();
	}
}