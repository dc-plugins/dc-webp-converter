<?php
/**
 * @wordpress-plugin
 * Plugin Name: DC WebP Converter
 * Plugin URI:  https://github.com/dc-plugins/dc-webp-converter
 * Description: Converts product-attached PNG and JPG images to WebP in batches via WP-Cron. Saves bandwidth, improves Core Web Vitals.
 * Version:     1.3.0
 * Author:      Dampcig
 * Author URI:  https://www.dampcig.dk
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dc-webp-converter
 * Domain Path: /languages
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Tested up to: 6.9
 */

defined( 'ABSPATH' ) || exit;

class DC_WebP_Converter {

	const OPT_SETTINGS       = 'dc_p2w_settings';
	const OPT_SYSCHECK       = 'dc_p2w_syscheck';   // cached server capability report
	const TRANSIENT_FOOTER   = 'dc_p2w_footer_strategy'; // 'copyright' | 'none'
	const OPT_QUEUE    = 'dc_p2w_queue';     // array of attachment IDs pending
	const OPT_TOTAL    = 'dc_p2w_total';     // int – total IDs when queue was built
	const OPT_DONE     = 'dc_p2w_done';      // int – successfully converted
	const OPT_ERRORS   = 'dc_p2w_errors';    // int – failed
	const OPT_LOG      = 'dc_p2w_log';       // array of last-run log entries
	const OPT_RUNNING      = 'dc_p2w_running';       // bool – cron lock
	const OPT_REPAIR_LOG   = 'dc_p2w_repair_log';   // array – last repair run entries
	const OPT_REPAIR_STATS = 'dc_p2w_repair_stats'; // array – { fixed, skipped, errors, ts }
	const CRON_HOOK        = 'dc_p2w_cron';

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init() {
		// Register custom cron intervals as early as possible so they are
		// available when WP-Cron resolves the recurring event interval.
		add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ], 1 );
		add_action( 'admin_menu',                [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_enqueue_scripts',     [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_post_dc_p2w_save',    [ __CLASS__, 'save_settings' ] );
		add_action( 'admin_post_dc_p2w_build',   [ __CLASS__, 'build_queue' ] );
		add_action( 'admin_post_dc_p2w_runnow',  [ __CLASS__, 'run_now' ] );
		add_action( 'admin_post_dc_p2w_reset',   [ __CLASS__, 'reset_all' ] );
		add_action( 'admin_post_dc_p2w_syscheck', [ __CLASS__, 'handle_syscheck' ] );
		add_action( 'admin_post_dc_p2w_repair',   [ __CLASS__, 'handle_repair' ] );
		add_action( self::CRON_HOOK,             [ __CLASS__, 'process_batch' ] );
		add_action( 'admin_notices',             [ __CLASS__, 'admin_notices' ] );
		// Footer credit: only register when the checkbox is ticked AND no other DC plugin
		// has already claimed ownership via the dc_footer_credit_owner() sentinel.
		$dc_p2w_s = self::get_settings();
		if ( ! empty( $dc_p2w_s['footer_credit_enabled'] )
			&& ! function_exists( 'dc_footer_credit_owner' )
		) {
			function dc_footer_credit_owner(): void {} // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- intentional cross-plugin sentinel
			add_action( 'wp_footer', [ __CLASS__, 'footer_credit_js' ], PHP_INT_MAX );
		}
		unset( $dc_p2w_s );

		register_activation_hook( __FILE__,   [ __CLASS__, 'activate' ] );
		register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );

		// Admin footer — only on this plugin's own page.
		add_filter( 'admin_footer_text', static function( $text ) {
			$screen = get_current_screen();
			if ( $screen && $screen->id === 'toplevel_page_dc-webp-converter' ) {
				return sprintf(
					/* translators: %s: URL to DC Plugins GitHub organisation */
					__( 'More plugins by <a href="%s" target="_blank" rel="noopener">DC Plugins</a>', 'dc-webp-converter' ),
					'https://github.com/dc-plugins'
				);
			}
			return $text;
		} );
	}

	// -------------------------------------------------------------------------
	// Server capability check
	// -------------------------------------------------------------------------

	/**
	 * Runs all server checks and returns a structured report array.
	 * Each entry: [ 'label', 'status' (pass|warn|fail), 'value', 'note' ]
	 * Also sets a top-level 'qualified' bool.
	 */
	public static function run_syscheck() {
		$checks = [];

		// 1. PHP version
		$php    = PHP_VERSION;
		$php_ok = version_compare( $php, '7.4', '>=' );
		$checks[] = [
			'label'  => __( 'PHP Version', 'dc-webp-converter' ),
			'status' => $php_ok ? 'pass' : 'fail',
			'value'  => $php,
			'note'   => $php_ok ? __( 'PHP 7.4+ required.', 'dc-webp-converter' ) : __( 'PHP 7.4 or higher is required.', 'dc-webp-converter' ),
		];

		// 2. Imagick extension
		$imagick  = class_exists( 'Imagick' );
		$checks[] = [
			'label'  => __( 'Imagick Extension', 'dc-webp-converter' ),
			'status' => $imagick ? 'pass' : 'fail',
			'value'  => $imagick ? __( 'Loaded', 'dc-webp-converter' ) : __( 'Not available', 'dc-webp-converter' ),
			'note'   => $imagick ? __( 'Required for image conversion.', 'dc-webp-converter' ) : __( 'Imagick must be enabled in PHP.', 'dc-webp-converter' ),
		];

		// 3. Imagick WebP support
		$webp_imagick = false;
		if ( $imagick ) {
			try {
				$formats      = Imagick::queryFormats( 'WEBP' );
				$webp_imagick = ! empty( $formats );
			} catch ( Exception $e ) {
				$webp_imagick = false;
			}
		}
		$checks[] = [
			'label'  => __( 'Imagick WebP Support', 'dc-webp-converter' ),
			'status' => $webp_imagick ? 'pass' : 'fail',
			'value'  => $webp_imagick ? __( 'Supported', 'dc-webp-converter' ) : __( 'Not supported', 'dc-webp-converter' ),
			'note'   => $webp_imagick
				? __( 'Imagick can encode WebP files.', 'dc-webp-converter' )
				: __( 'Your ImageMagick build lacks WebP codec (install libwebp and recompile, or ask your host).', 'dc-webp-converter' ),
		];

		// 4. GD WebP (informational)
		$gd_webp  = function_exists( 'imagewebp' );
		$checks[] = [
			'label'  => __( 'GD WebP Support', 'dc-webp-converter' ),
			'status' => $gd_webp ? 'pass' : 'warn',
			'value'  => $gd_webp ? __( 'Available', 'dc-webp-converter' ) : __( 'Not available', 'dc-webp-converter' ),
			'note'   => __( 'Plugin uses Imagick; GD is informational only.', 'dc-webp-converter' ),
		];

		// 5. WooCommerce active
		$woo      = class_exists( 'WooCommerce' );
		$checks[] = [
			'label'  => __( 'WooCommerce', 'dc-webp-converter' ),
			'status' => $woo ? 'pass' : 'fail',
			'value'  => $woo ? __( 'Active', 'dc-webp-converter' ) : __( 'Not active', 'dc-webp-converter' ),
			'note'   => $woo
				? __( 'Product image scanning requires WooCommerce.', 'dc-webp-converter' )
				: __( 'WooCommerce must be installed and active.', 'dc-webp-converter' ),
		];

		// 6. WP-Cron
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$checks[]      = [
			'label'  => __( 'WP-Cron', 'dc-webp-converter' ),
			'status' => $cron_disabled ? 'warn' : 'pass',
			'value'  => $cron_disabled ? __( 'Disabled (DISABLE_WP_CRON)', 'dc-webp-converter' ) : __( 'Enabled', 'dc-webp-converter' ),
			'note'   => $cron_disabled
				? __( 'Automatic batch processing will not run. Use “Run Batch Now” manually, or set up a real server cron job targeting wp-cron.php.', 'dc-webp-converter' )
				: __( 'Automatic batch processing will run on visitor traffic.', 'dc-webp-converter' ),
		];

		// 7. Memory limit
		$mem_raw   = WP_MEMORY_LIMIT;
		$mem_bytes = wp_convert_hr_to_bytes( $mem_raw );
		$mem_ok    = ( $mem_bytes === -1 ) || ( $mem_bytes >= 128 * MB_IN_BYTES );
		$checks[]  = [
			'label'  => __( 'PHP Memory Limit', 'dc-webp-converter' ),
			'status' => $mem_ok ? 'pass' : 'warn',
			'value'  => $mem_raw,
			'note'   => $mem_ok
				? __( 'Sufficient for processing large images.', 'dc-webp-converter' )
				: __( 'Less than 128 MB may cause out-of-memory errors on large images. Reduce batch size if problems occur.', 'dc-webp-converter' ),
		];

		// 8. Max execution time
		$max_exec   = (int) ini_get( 'max_execution_time' );
		$exec_ok    = ( $max_exec === 0 ) || ( $max_exec >= 30 );
		$exec_label = $max_exec === 0 ? __( 'Unlimited', 'dc-webp-converter' ) : $max_exec . 's';
		$checks[]   = [
			'label'  => __( 'Max Execution Time', 'dc-webp-converter' ),
			'status' => $exec_ok ? 'pass' : 'warn',
			'value'  => $exec_label,
			'note'   => $exec_ok
				? __( 'Enough time to process a batch.', 'dc-webp-converter' )
				: __( 'Under 30 s may cause batch timeouts. Reduce batch size or ask your host to increase the limit.', 'dc-webp-converter' ),
		];

		// 9. Object cache
		$obj_cache = wp_using_ext_object_cache();
		$checks[]  = [
			'label'  => __( 'Object Cache (Redis/Memcached)', 'dc-webp-converter' ),
			'status' => $obj_cache ? 'pass' : 'warn',
			'value'  => $obj_cache ? __( 'Active', 'dc-webp-converter' ) : __( 'Not active', 'dc-webp-converter' ),
			'note'   => $obj_cache
				? __( 'Footer strategy cache will use in-memory store (0 DB queries).', 'dc-webp-converter' )
				: __( 'Optional but recommended. Plugin will fall back to transient (1 cheap DB read per uncached page).', 'dc-webp-converter' ),
		];

		// 10. Uploads writable
		$upload_dir = wp_upload_dir();
		$writable   = wp_is_writable( $upload_dir['basedir'] );
		$checks[]   = [
			'label'  => __( 'Uploads Directory Writable', 'dc-webp-converter' ),
			'status' => $writable ? 'pass' : 'fail',
			'value'  => $writable ? $upload_dir['basedir'] : __( 'Not writable', 'dc-webp-converter' ),
			'note'   => $writable
				? __( 'WebP files can be written.', 'dc-webp-converter' )
				: __( 'The uploads folder is not writable. Fix directory permissions.', 'dc-webp-converter' ),
		];

		// Overall verdict
		$qualified = true;
		foreach ( $checks as $c ) {
			if ( $c['status'] === 'fail' ) { $qualified = false; break; }
		}

		$report = [
			'qualified' => $qualified,
			'timestamp' => current_time( 'mysql' ),
			'checks'    => $checks,
		];
		update_option( self::OPT_SYSCHECK, $report );
		return $report;
	}

	/** POST handler – re-run syscheck from the admin page button. */
	public static function handle_syscheck() {
		check_admin_referer( 'dc_p2w_syscheck' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		self::run_syscheck();
		wp_safe_redirect( add_query_arg( [ 'page' => 'dc-png-to-webp', 'msg' => 'syscheck' ], admin_url( 'tools.php' ) ) );
		exit;
	}

	/** Admin notice shown on all admin pages when syscheck reports a failure. */
	public static function admin_notices() {
		$report = get_option( self::OPT_SYSCHECK );

		if ( ! $report ) {
			$url = add_query_arg( [ 'page' => 'dc-png-to-webp' ], admin_url( 'tools.php' ) );
			echo '<div class="notice notice-warning"><p>';
			printf(
				wp_kses(
					/* translators: %s: URL to the plugin settings page */
					__( '<strong>PNG → WebP Converter:</strong> Run the <a href="%s">server requirements check</a> before starting conversion.', 'dc-webp-converter' ),
					[ 'strong' => [], 'a' => [ 'href' => [] ] ]
				),
				esc_url( $url )
			);
			echo '</p></div>';
			return;
		}

		if ( empty( $report['qualified'] ) ) {
			$url = add_query_arg( [ 'page' => 'dc-png-to-webp' ], admin_url( 'tools.php' ) );
			echo '<div class="notice notice-error"><p>';
			printf(
				wp_kses(
					/* translators: %s: URL to the plugin settings page */
					__( '<strong>PNG → WebP Converter:</strong> Your server does not meet all requirements. <a href="%s">View details</a>.', 'dc-webp-converter' ),
					[ 'strong' => [], 'a' => [ 'href' => [] ] ]
				),
				esc_url( $url )
			);
			echo '</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// Footer credit
	// -------------------------------------------------------------------------

	/**
	 * Output a tiny inline TreeWalker script that wraps the first © text node
	 * inside <footer> with a link to dampcig.dk.
	 */
	public static function footer_credit_js() {
		if ( is_admin() ) {
			return;
		}
		$url   = 'https://www.dampcig.dk';
		$title = esc_js( 'Powered by Dampcig.dk' );
		?>
<script>(function(){
var f=document.querySelector('footer');
if(!f)return;
var w=document.createTreeWalker(f,NodeFilter.SHOW_TEXT,null,false);
var node;
while((node=w.nextNode())){
	if(node.nodeValue.indexOf('\u00A9')===-1)continue;
	var idx=node.nodeValue.indexOf('\u00A9');
	var frag=document.createDocumentFragment();
	if(idx>0)frag.appendChild(document.createTextNode(node.nodeValue.slice(0,idx)));
	var a=document.createElement('a');
	a.href=<?php echo wp_json_encode( $url ); ?>;
	a.title=<?php echo wp_json_encode( $title ); ?>;
	a.target='_blank';
	a.rel='noopener noreferrer';
	a.textContent='\u00A9';
	frag.appendChild(a);
	var rest=node.nodeValue.slice(idx+1);
	if(rest)frag.appendChild(document.createTextNode(rest));
	node.parentNode.replaceChild(frag,node);
	break;
}
})();</script>
		<?php
	}

	/** Clean up legacy footer strategy transient left by older versions. */
	public static function clear_footer_strategy_cache() {
		wp_cache_delete( self::TRANSIENT_FOOTER, 'dc_p2w' );
		delete_transient( self::TRANSIENT_FOOTER );
	}

	// -------------------------------------------------------------------------
	// Activation / Deactivation
	// -------------------------------------------------------------------------

	public static function activate() {
		// Run syscheck immediately so the admin sees results on first visit.
		self::run_syscheck();

		$s = self::get_settings();
		if ( $s['cron_enabled'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), $s['cron_schedule'], self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::clear_footer_strategy_cache();
		delete_option( self::OPT_SYSCHECK );
	}

	// -------------------------------------------------------------------------
	// Cron schedules
	// -------------------------------------------------------------------------

	public static function cron_schedules( $schedules ) {
		$schedules['dc_every_minute'] = [
			'interval' => 60,
			'display'  => __( 'Every Minute', 'dc-webp-converter' ),
		];
		$schedules['dc_every_5_min'] = [
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'dc-webp-converter' ),
		];
		return $schedules;
	}

	// -------------------------------------------------------------------------
	// Settings helpers
	// -------------------------------------------------------------------------

	public static function get_settings() {
		return wp_parse_args( get_option( self::OPT_SETTINGS, [] ), [
			'batch_size'            => 20,
			'cron_schedule'         => 'dc_every_minute',
			'cron_enabled'          => 1,
			'quality'               => 82,
			'footer_credit_enabled' => 0,
		] );
	}

	public static function reschedule_cron( $settings ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );

		// Always keep cron alive when a queue is actively being processed,
		// regardless of the cron_enabled setting — don't let a settings save
		// silently kill an in-progress run.
		$queue         = get_option( self::OPT_QUEUE );
		$queue_running = is_array( $queue ) && ! empty( $queue );

		if ( $settings['cron_enabled'] || $queue_running ) {
			wp_schedule_event( time(), $settings['cron_schedule'], self::CRON_HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Admin menu
	// -------------------------------------------------------------------------

	public static function admin_menu() {
		add_management_page(
			__( 'PNG → WebP Converter', 'dc-webp-converter' ),
			__( 'PNG → WebP', 'dc-webp-converter' ),
			'manage_options',
			'dc-png-to-webp',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( $hook !== 'tools_page_dc-png-to-webp' ) return;
		// Auto-refresh every 10 s while processing.
		// Must wait for DOMContentLoaded: wp_add_inline_script runs *before*
		// the page body exists, so getElementById would always return null.
		wp_add_inline_script( 'jquery', '
			document.addEventListener("DOMContentLoaded", function(){
				if (document.getElementById("dc-p2w-processing")) {
					setTimeout(function(){ location.reload(); }, 10000);
				}
			});
		' );
	}

	// -------------------------------------------------------------------------
	// Queue builder – only product-attached images
	// -------------------------------------------------------------------------

	/**
	 * Returns all PNG and JPG attachment IDs that are attached to products.
	 * Sources:
	 *   1. post_parent = product (featured + uploaded-to-product images)
	 *   2. _product_image_gallery post meta on any product
	 *   3. _thumbnail_id meta on products
	 */
	public static function get_product_image_ids() {
		global $wpdb;

		// 1. Attachments whose post_parent is a 'product'
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$direct = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT a.ID FROM {$wpdb->posts} a
				INNER JOIN {$wpdb->posts} p ON p.ID = a.post_parent
				WHERE a.post_type     = 'attachment'
				  AND a.post_mime_type IN ('image/png','image/jpeg')
				  AND a.post_status   = %s
				  AND p.post_type     = %s",
				'inherit',
				'product'
			)
		);

		// 2. Gallery images stored in _product_image_gallery meta
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$gallery_raw = $wpdb->get_col( "
			SELECT meta_value FROM {$wpdb->postmeta}
			WHERE meta_key = '_product_image_gallery'
			  AND meta_value != ''
		" );
		$gallery_ids = [];
		foreach ( $gallery_raw as $v ) {
			foreach ( explode( ',', $v ) as $id ) {
				$id = (int) trim( $id );
				if ( $id > 0 ) $gallery_ids[] = $id;
			}
		}

		if ( $gallery_ids ) {
			$gallery_ids_unique = array_unique( array_map( 'intval', $gallery_ids ) );
			$placeholders       = implode( ',', array_fill( 0, count( $gallery_ids_unique ), '%d' ) );
			// $placeholders contains only '%d' literals from array_fill — safe to interpolate.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$gngs = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_mime_type IN ('image/png','image/jpeg') AND post_status = 'inherit'",
				...$gallery_ids_unique
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$direct = array_merge( $direct, $gngs );
		}

		// 3. _thumbnail_id attachments linked to products
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$thumb_ids = $wpdb->get_col( "
			SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
			INNER JOIN {$wpdb->posts} a ON a.ID = pm.meta_value
			WHERE pm.meta_key      = '_thumbnail_id'
			  AND a.post_mime_type IN ('image/png','image/jpeg') -- phpcs:ignore WordPress.DB.DirectDatabaseQuery
			  AND a.post_status    = 'inherit'
		" );
		$direct = array_merge( $direct, $thumb_ids );

		return array_values( array_unique( array_map( 'intval', $direct ) ) );
	}

	/** BC alias */
	public static function get_product_png_ids() {
		return self::get_product_image_ids();
	}

	// -------------------------------------------------------------------------
	// Repair: find WebP attachments with missing thumbnail files on disk
	// -------------------------------------------------------------------------

	/**
	 * Returns an array of attachment IDs whose metadata says .webp for one or
	 * more thumbnail sizes but the actual .webp file is missing from disk.
	 * Only considers attachments that have already been converted to WebP
	 * (post_mime_type = image/webp).
	 */
	public static function get_repair_candidates() {
		global $wpdb;

		// All WebP attachments that belong to products (by post_parent or thumbnail meta)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			"SELECT DISTINCT a.ID
			FROM {$wpdb->posts} a
			WHERE a.post_type      = 'attachment'
			  AND a.post_mime_type = 'image/webp'
			  AND a.post_status    = 'inherit'"
		);

		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] );
		$candidates = [];

		foreach ( $ids as $id ) {
			$meta = wp_get_attachment_metadata( (int) $id );
			if ( empty( $meta['sizes'] ) || empty( $meta['file'] ) ) {
				continue;
			}
			$size_dir = trailingslashit( $base . dirname( $meta['file'] ) );
			foreach ( $meta['sizes'] as $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$webp_path = $size_dir . $size_data['file'];
				if ( ! file_exists( $webp_path ) ) {
					$candidates[] = (int) $id;
					break; // one missing size is enough to flag this attachment
				}
			}
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Convert missing thumbnail WebP files for the given attachment IDs.
	 * Returns [ 'fixed' => int, 'skipped' => int, 'errors' => int, 'log' => array ].
	 */
	public static function repair_thumbnails( array $ids ) {
		$s          = self::get_settings();
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] );
		$fixed      = 0;
		$skipped    = 0;
		$errors     = 0;
		$log        = [];

		foreach ( $ids as $id ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( empty( $meta['sizes'] ) || empty( $meta['file'] ) ) {
				$log[] = [ 'id' => $id, 'status' => 'skip', 'msg' => 'No sizes in metadata' ];
				$skipped++;
				continue;
			}

			$size_dir        = trailingslashit( $base . dirname( $meta['file'] ) );
			$any_converted   = false;
			$meta_updated    = false;

			foreach ( $meta['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}

				$webp_file = $size_dir . $size_data['file'];

				// Already on disk — nothing to do for this size.
				if ( file_exists( $webp_file ) ) {
					continue;
				}

				// Locate the original source (PNG or JPG alongside the WebP path).
				$base_path = preg_replace( '/\.webp$/i', '', $webp_file );
				$src       = null;
				foreach ( [ '.png', '.PNG', '.jpg', '.JPG', '.jpeg', '.JPEG' ] as $ext ) {
					if ( file_exists( $base_path . $ext ) ) {
						$src = $base_path . $ext;
						break;
					}
				}

				if ( ! $src ) {
					$log[]  = [ 'id' => $id, 'status' => 'warn', 'msg' => '[' . $size_name . '] Source not found: ' . basename( $webp_file ) ];
					$errors++;
					continue;
				}

				try {
					if ( ! class_exists( 'Imagick' ) ) {
						throw new RuntimeException( 'Imagick not available' );
					}
					$img = new Imagick( $src );
					$img->setImageFormat( 'webp' );
					if ( $img->getImageColors() <= 512 ) {
						$img->setOption( 'webp:lossless', 'true' );
					} else {
						$img->setImageCompressionQuality( (int) $s['quality'] );
						$img->setOption( 'webp:method', '6' );
					}
					$img->writeImage( $webp_file );
					$img->destroy();
					$log[]         = [ 'id' => $id, 'status' => 'ok', 'msg' => '[' . $size_name . '] ' . basename( $webp_file ) ];
					$any_converted = true;
					$fixed++;
				} catch ( Exception $e ) {
					$log[]  = [ 'id' => $id, 'status' => 'error', 'msg' => '[' . $size_name . '] ' . $e->getMessage() ];
					$errors++;
				}
			}

			if ( ! $any_converted ) {
				$skipped++;
			}
		}

		return compact( 'fixed', 'skipped', 'errors', 'log' );
	}

	/** POST handler – scan all WebP attachments and repair missing thumbnails immediately. */
	public static function handle_repair() {
		check_admin_referer( 'dc_p2w_repair' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$ids    = self::get_repair_candidates();
		$result = self::repair_thumbnails( $ids );

		update_option( self::OPT_REPAIR_LOG, $result['log'] );
		update_option( self::OPT_REPAIR_STATS, [
			'fixed'   => $result['fixed'],
			'skipped' => $result['skipped'],
			'errors'  => $result['errors'],
			'ts'      => current_time( 'mysql' ),
		] );

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'dc-png-to-webp', 'msg' => 'repaired', 'r_fixed' => $result['fixed'], 'r_errors' => $result['errors'] ],
			admin_url( 'tools.php' )
		) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Batch processor
	// -------------------------------------------------------------------------

	public static function process_batch() {
		// Prevent overlapping runs
		if ( get_transient( 'dc_p2w_lock' ) ) return;
		set_transient( 'dc_p2w_lock', 1, 120 );

		$s     = self::get_settings();
		$queue = get_option( self::OPT_QUEUE );

		if ( empty( $queue ) || ! is_array( $queue ) ) {
			delete_transient( 'dc_p2w_lock' );
			// Queue finished or not built – disable cron
			wp_clear_scheduled_hook( self::CRON_HOOK );
			update_option( 'dc_p2w_cron_status', 'finished' );
			return;
		}

		$batch       = array_splice( $queue, 0, (int) $s['batch_size'] );
		$upload_dir  = wp_upload_dir();
		$base        = trailingslashit( $upload_dir['basedir'] );
		$log_entries = [];
		$done        = (int) get_option( self::OPT_DONE,   0 );
		$errors      = (int) get_option( self::OPT_ERRORS, 0 );

		foreach ( $batch as $id ) {
			$rel_path = get_post_meta( $id, '_wp_attached_file', true );
			$src_path = $base . $rel_path;

			if ( ! $rel_path || ! file_exists( $src_path ) ) {
				$log_entries[] = [ 'id' => $id, 'status' => 'skip', 'msg' => 'File not found: ' . $rel_path ];
				continue;
			}

			$webp_rel  = preg_replace( '/\.(png|jpe?g)$/i', '.webp', $rel_path );
			$webp_path = $base . $webp_rel;

			// Convert if not already done
			$mode = 'lossy';
			if ( ! file_exists( $webp_path ) ) {
				try {
					if ( ! class_exists( 'Imagick' ) ) {
						throw new RuntimeException( 'Imagick not available' );
					}
					$img = new Imagick( $src_path );
					$img->setImageFormat( 'webp' );
					// Auto-detect: logos/icons have few unique colours → lossless.
					// Photos have thousands of colours → lossy (quality setting).
					$unique_colors = $img->getImageColors();
					if ( $unique_colors <= 512 ) {
						$img->setOption( 'webp:lossless', 'true' );
						$mode = 'lossless';
					} else {
						$img->setImageCompressionQuality( (int) $s['quality'] );
						$img->setOption( 'webp:method', '6' );
					}
					$img->writeImage( $webp_path );
					$img->destroy();
				} catch ( Exception $e ) {
					$log_entries[] = [ 'id' => $id, 'status' => 'error', 'msg' => $e->getMessage() ];
					$errors++;
					update_option( self::OPT_ERRORS, $errors );
					continue;
				}
			}

			// Update DB
			wp_update_post( [ 'ID' => $id, 'post_mime_type' => 'image/webp' ] );
			update_post_meta( $id, '_wp_attached_file', $webp_rel );

			$meta = wp_get_attachment_metadata( $id ) ?: [];
			$size = @getimagesize( $webp_path );
			if ( $size ) { $meta['width'] = $size[0]; $meta['height'] = $size[1]; }
			$meta['file'] = $webp_rel;
			// Convert each thumbnail size on disk, then update its metadata reference.
			// Preserves width/height per-size so WordPress can emit correct
			// width/height HTML attributes — critical for CLS prevention.
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				$size_dir = trailingslashit( $base . dirname( $rel_path ) );
				foreach ( $meta['sizes'] as $size_name => $size_data ) {
					if ( empty( $size_data['file'] ) ) {
						continue;
					}
					$thumb_src  = $size_dir . $size_data['file'];
					$thumb_webp = preg_replace( '/\.(png|jpe?g)$/i', '.webp', $thumb_src );
					// Convert the thumbnail file if the source exists and WebP not yet made.
					if ( file_exists( $thumb_src ) && ! file_exists( $thumb_webp ) ) {
						try {
							if ( ! class_exists( 'Imagick' ) ) {
								throw new RuntimeException( 'Imagick not available' );
							}
							$timg = new Imagick( $thumb_src );
							$timg->setImageFormat( 'webp' );
							if ( $timg->getImageColors() <= 512 ) {
								$timg->setOption( 'webp:lossless', 'true' );
							} else {
								$timg->setImageCompressionQuality( (int) $s['quality'] );
								$timg->setOption( 'webp:method', '6' );
							}
							$timg->writeImage( $thumb_webp );
							$timg->destroy();
						} catch ( Exception $e ) {
							// Non-fatal: log but keep processing other sizes.
							$log_entries[] = [ 'id' => $id, 'status' => 'warn', 'msg' => 'Thumbnail convert failed (' . $size_name . '): ' . $e->getMessage() ];
						}
					}
					// Update metadata reference regardless (file may have been converted on a previous run).
					$meta['sizes'][ $size_name ]['file']      = preg_replace( '/\.(png|jpe?g)$/i', '.webp', $size_data['file'] );
					$meta['sizes'][ $size_name ]['mime-type'] = 'image/webp';
				}
			}
			wp_update_attachment_metadata( $id, $meta );

			global $wpdb;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->posts,
				[ 'guid' => preg_replace( '/\.(png|jpe?g)$/i', '.webp', get_post( $id )->guid ) ],
				[ 'ID' => $id ]
			);

			$done++;
			update_option( self::OPT_DONE, $done );
			$log_entries[] = [ 'id' => $id, 'status' => 'ok', 'msg' => $webp_rel . ' [' . $mode . ']' ];
		}

		// Save remaining queue
		update_option( self::OPT_QUEUE, $queue );
		update_option( self::OPT_ERRORS, $errors );

		// Keep last 200 log lines
		$log = get_option( self::OPT_LOG, [] );
		$log = array_merge( array_slice( $log, -190 ), $log_entries );
		update_option( self::OPT_LOG, $log );

		// Self-heal: if items still remain but the recurring cron event was
		// somehow dropped (hosting runner issue, transient/cache flush, plugin
		// conflict), re-add it here so processing is never silently stalled.
		if ( ! empty( $queue ) ) {
			$s = self::get_settings();
			if ( $s['cron_enabled'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + (int) $s['batch_size'], $s['cron_schedule'], self::CRON_HOOK );
			}
		}

		delete_transient( 'dc_p2w_lock' );
	}

	// -------------------------------------------------------------------------
	// POST handlers
	// -------------------------------------------------------------------------

	public static function save_settings() {
		check_admin_referer( 'dc_p2w_settings' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$s = [
			'batch_size'            => max( 1, min( 200, absint( wp_unslash( $_POST['batch_size'] ?? 20 ) ) ) ),
			'cron_schedule'         => in_array( sanitize_key( wp_unslash( $_POST['cron_schedule'] ?? '' ) ), [ 'dc_every_minute', 'dc_every_5_min', 'hourly', 'twicedaily' ] )
			                          ? sanitize_key( wp_unslash( $_POST['cron_schedule'] ?? '' ) ) : 'dc_every_minute',
			'cron_enabled'          => ! empty( $_POST['cron_enabled'] ) ? 1 : 0,
			'quality'               => max( 50, min( 100, absint( wp_unslash( $_POST['quality'] ?? 82 ) ) ) ),
			'footer_credit_enabled' => ! empty( $_POST['footer_credit_enabled'] ) ? 1 : 0,
		];
		update_option( self::OPT_SETTINGS, $s );
		self::reschedule_cron( $s );

		wp_safe_redirect( add_query_arg( [ 'page' => 'dc-png-to-webp', 'msg' => 'saved' ], admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function build_queue() {
		check_admin_referer( 'dc_p2w_build' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$ids = self::get_product_image_ids();
		update_option( self::OPT_QUEUE,  $ids );
		update_option( self::OPT_TOTAL,  count( $ids ) );
		update_option( self::OPT_DONE,   0 );
		update_option( self::OPT_ERRORS, 0 );
		update_option( self::OPT_LOG,    [] );

		// Ensure cron is running
		$s = self::get_settings();
		if ( $s['cron_enabled'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), $s['cron_schedule'], self::CRON_HOOK );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => 'dc-png-to-webp', 'msg' => 'built', 'found' => count( $ids ) ], admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function run_now() {
		check_admin_referer( 'dc_p2w_runnow' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		self::process_batch();

		wp_safe_redirect( add_query_arg( [ 'page' => 'dc-png-to-webp', 'msg' => 'ran' ], admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function reset_all() {
		check_admin_referer( 'dc_p2w_reset' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		delete_option( self::OPT_QUEUE );
		delete_option( self::OPT_TOTAL );
		delete_option( self::OPT_DONE );
		delete_option( self::OPT_ERRORS );
		delete_option( self::OPT_LOG );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::clear_footer_strategy_cache();

		wp_safe_redirect( add_query_arg( [ 'page' => 'dc-png-to-webp', 'msg' => 'reset' ], admin_url( 'tools.php' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Admin page render
	// -------------------------------------------------------------------------

	public static function render_page() {
		$s       = self::get_settings();
		$queue   = get_option( self::OPT_QUEUE );
		$total   = (int) get_option( self::OPT_TOTAL, 0 );
		$done    = (int) get_option( self::OPT_DONE,  0 );
		$errors  = (int) get_option( self::OPT_ERRORS, 0 );
		$log     = get_option( self::OPT_LOG, [] );
		$pending = is_array( $queue ) ? count( $queue ) : null;
		$next    = wp_next_scheduled( self::CRON_HOOK );
		$pct     = ( $total > 0 ) ? round( $done / $total * 100 ) : 0;
		$running = ( $pending !== null && $pending > 0 );

		// Self-heal: if a queue is actively running but the cron event somehow
		// fell off (server restart, transient flush, stray settings save), put
		// it back immediately so processing continues without manual action.
		if ( $running && $s['cron_enabled'] && ! $next ) {
			wp_schedule_event( time() + 30, $s['cron_schedule'], self::CRON_HOOK );
			$next = wp_next_scheduled( self::CRON_HOOK );
		}
		$msg     = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap" <?php if ( $running ) echo 'id="dc-p2w-processing"'; ?>>
		<h1><?php esc_html_e( 'PNG + JPG → WebP Converter', 'dc-webp-converter' ); ?></h1>

		<?php if ( $msg === 'saved'    ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'dc-webp-converter' ); ?></p></div><?php endif; ?>
		<?php if ( $msg === 'built'    ) : ?><div class="notice notice-success is-dismissible"><p><?php
		/* translators: %d: number of images added to the queue */
		printf( esc_html__( 'Queue built: %d images queued.', 'dc-webp-converter' ), absint( isset( $_GET['found'] ) ? wp_unslash( $_GET['found'] ) : 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?></p></div><?php endif; ?>
		<?php if ( $msg === 'ran'      ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Batch processed.', 'dc-webp-converter' ); ?></p></div><?php endif; ?>
		<?php if ( $msg === 'reset'    ) : ?><div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Queue and progress reset.', 'dc-webp-converter' ); ?></p></div><?php endif; ?>
		<?php if ( $msg === 'syscheck' ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Server requirements re-checked.', 'dc-webp-converter' ); ?></p></div><?php endif; ?>
		<?php if ( $msg === 'repaired' ) :
			$r_fixed  = absint( isset( $_GET['r_fixed'] )  ? wp_unslash( $_GET['r_fixed'] )  : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$r_errors = absint( isset( $_GET['r_errors'] ) ? wp_unslash( $_GET['r_errors'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?><div class="notice notice-success is-dismissible"><p><?php
			/* translators: %1$d: thumbnails fixed, %2$d: errors */
			printf( esc_html__( 'Repair complete: %1$d thumbnail(s) generated, %2$d error(s).', 'dc-webp-converter' ), $r_fixed, $r_errors );
		?></p></div><?php endif; ?>

		<?php
		// ── Server Requirements Panel ──────────────────────────────────────
		$report = get_option( self::OPT_SYSCHECK );
		if ( ! $report ) {
			$report = self::run_syscheck(); // auto-run if somehow not cached
		}
		$qualified = ! empty( $report['qualified'] );
		$badge_color = $qualified ? '#2ea44f' : '#cf222e';
		$badge_text  = $qualified
			? __( '✓ Server qualifies', 'dc-webp-converter' )
			: __( '✗ Server does not qualify', 'dc-webp-converter' );
		$has_fails   = false;
		$has_warns   = false;
		foreach ( $report['checks'] as $c ) {
			if ( $c['status'] === 'fail' ) $has_fails = true;
			if ( $c['status'] === 'warn' ) $has_warns = true;
		}
		?>
		<details id="dc-p2w-syscheck" <?php echo ( ! $qualified || $msg === 'syscheck' ) ? 'open' : ''; ?> style="margin-bottom:20px;border:1px solid #c3c4c7;border-radius:4px;background:#fff;">
			<summary style="padding:12px 16px;cursor:pointer;display:flex;align-items:center;gap:12px;user-select:none;">
				<strong style="font-size:1.05em;"><?php esc_html_e( 'Server Requirements', 'dc-webp-converter' ); ?></strong>
				<span style="background:<?php echo esc_attr( $badge_color ); ?>;color:#fff;padding:2px 10px;border-radius:3px;font-size:.85em;font-weight:600;"><?php echo esc_html( $badge_text ); ?></span>
				<?php if ( $has_warns && $qualified ) : ?>
					<span style="background:#f0a500;color:#fff;padding:2px 10px;border-radius:3px;font-size:.85em;font-weight:600;"><?php esc_html_e( '⚠ Warnings', 'dc-webp-converter' ); ?></span>
				<?php endif; ?>
				<span style="margin-left:auto;font-size:.8em;color:#666;"><?php
				/* translators: %s: date/time of the last server requirements check */
				printf( esc_html__( 'Last checked: %s', 'dc-webp-converter' ), esc_html( $report['timestamp'] ) ); ?></span>
			</summary>
			<div style="padding:0 16px 16px;">
			<table class="widefat striped" style="margin-top:12px;">
			<thead><tr>
				<th style="width:220px;"><?php esc_html_e( 'Requirement', 'dc-webp-converter' ); ?></th>
				<th style="width:180px;"><?php esc_html_e( 'Detected Value', 'dc-webp-converter' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Status', 'dc-webp-converter' ); ?></th>
				<th><?php esc_html_e( 'Notes', 'dc-webp-converter' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $report['checks'] as $c ) :
				$s_color = $c['status'] === 'pass' ? '#2ea44f' : ( $c['status'] === 'fail' ? '#cf222e' : '#f0a500' );
				$s_icon  = $c['status'] === 'pass' ? '✓' : ( $c['status'] === 'fail' ? '✗' : '⚠' );
			?>
				<tr>
					<td><strong><?php echo esc_html( $c['label'] ); ?></strong></td>
					<td><code><?php echo esc_html( $c['value'] ); ?></code></td>
					<td style="color:<?php echo esc_attr( $s_color ); ?>;font-weight:700;"><?php echo esc_html( $s_icon ) . ' ' . esc_html( ucfirst( $c['status'] ) ); ?></td>
					<td style="color:#555;font-size:.9em;"><?php echo esc_html( $c['note'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( 'dc_p2w_syscheck' ); ?>
				<input type="hidden" name="action" value="dc_p2w_syscheck">
				<button type="submit" class="button"><?php esc_html_e( '↺ Re-run Requirements Check', 'dc-webp-converter' ); ?></button>
			</form>
			</div>
		</details>

		<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:0;">

		<!-- ── Settings ────────────────────────────────────────────────── -->
		<div style="flex:1;min-width:300px;">
		<h2 style="margin-top:0"><?php esc_html_e( 'Settings', 'dc-webp-converter' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'dc_p2w_settings' ); ?>
		<input type="hidden" name="action" value="dc_p2w_save">
		<table class="form-table" style="margin:0">
		<tr>
			<th><?php esc_html_e( 'Batch Size', 'dc-webp-converter' ); ?></th>
			<td>
				<input type="number" name="batch_size" value="<?php echo esc_attr( $s['batch_size'] ); ?>" min="1" max="200" style="width:80px">
				<p class="description"><?php esc_html_e( 'Images converted per cron run (1–200).', 'dc-webp-converter' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Cron Interval', 'dc-webp-converter' ); ?></th>
			<td>
				<select name="cron_schedule">
					<?php foreach ( [
						'dc_every_minute' => __( 'Every Minute',    'dc-webp-converter' ),
						'dc_every_5_min'  => __( 'Every 5 Minutes', 'dc-webp-converter' ),
						'hourly'          => __( 'Hourly',          'dc-webp-converter' ),
						'twicedaily'      => __( 'Twice Daily',     'dc-webp-converter' ),
					] as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['cron_schedule'], $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Cron Enabled', 'dc-webp-converter' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="cron_enabled" value="1" <?php checked( $s['cron_enabled'] ); ?>>
					<?php esc_html_e( 'Automatically convert via WP-Cron', 'dc-webp-converter' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'WebP Quality', 'dc-webp-converter' ); ?></th>
			<td>
				<input type="number" name="quality" value="<?php echo esc_attr( $s['quality'] ); ?>" min="50" max="100" style="width:80px">
				<p class="description"><?php esc_html_e( 'Lossy quality for photos (50–100). Default 82. Images with ≤512 unique colours (logos, icons) are always encoded lossless regardless of this setting.', 'dc-webp-converter' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Footer Credit', 'dc-webp-converter' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="footer_credit_enabled" value="1" <?php checked( $s['footer_credit_enabled'] ); ?>>
					<?php esc_html_e( 'Vis kærlighed og støt udviklingen ved at tilføje et lille link i footeren', 'dc-webp-converter' ); ?>
				</label>
			</td>
		</tr>
		</table>
		<?php submit_button( __( 'Save Settings', 'dc-webp-converter' ), 'primary', 'submit', false ); ?>
		</form>
		</div>

		<!-- ── Progress ─────────────────────────────────────────────────── -->
		<div style="flex:1;min-width:300px;">
		<h2 style="margin-top:0"><?php esc_html_e( 'Progress', 'dc-webp-converter' ); ?></h2>

		<?php if ( $pending === null ) : ?>
			<p><?php printf( wp_kses( __( 'No queue built yet. Click <strong>Scan &amp; Build Queue</strong> to find all product images.', 'dc-webp-converter' ), [ 'strong' => [] ] ) ); ?></p>
		<?php else : ?>

		<table class="widefat fixed striped" style="width:auto">
		<tr><th><?php esc_html_e( 'Total in queue', 'dc-webp-converter' ); ?></th><td><?php echo number_format( $total ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Converted',      'dc-webp-converter' ); ?></th><td><?php echo number_format( $done ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Errors',          'dc-webp-converter' ); ?></th><td><?php echo number_format( $errors ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Remaining',       'dc-webp-converter' ); ?></th><td><?php echo number_format( $pending ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Next cron run',   'dc-webp-converter' ); ?></th><td><?php
		/* translators: %s: human-readable time until next cron run, e.g. "2 minutes" */
		echo $next ? sprintf( esc_html__( '%s from now', 'dc-webp-converter' ), esc_html( human_time_diff( $next ) ) ) : '<em>' . esc_html__( 'Not scheduled', 'dc-webp-converter' ) . '</em>';
		?></td></tr>
		</table>

		<?php if ( $total > 0 ) : ?>
		<div style="margin-top:12px;background:#e0e0e0;border-radius:4px;height:22px;overflow:hidden">
			<div style="height:100%;width:<?php echo (int) $pct; ?>%;background:<?php echo (int) $errors > 0 ? '#f0a500' : '#2271b1'; ?>;transition:width .4s;border-radius:4px"></div>
		</div>
		<p style="margin-top:4px"><strong><?php echo (int) $pct; ?>%</strong> &ndash; <?php
		/* translators: %1$d: number of converted images, %2$d: total images */
		printf( esc_html__( '%1$d / %2$d converted', 'dc-webp-converter' ), (int) $done, (int) $total );
		?><?php echo (int) $errors > 0 ? ' (' . (int) $errors . ' ' . esc_html__( 'errors', 'dc-webp-converter' ) . ')' : ''; ?></p>
		<?php if ( $pending === 0 ) : ?>
			<p style="color:green;font-weight:600"><?php esc_html_e( '✓ All images converted!', 'dc-webp-converter' ); ?></p>
		<?php elseif ( $running ) : ?>
			<p style="color:#2271b1"><?php esc_html_e( '⟳ Processing… page refreshes every 10 s', 'dc-webp-converter' ); ?></p>
		<?php endif; ?>
		<?php endif; ?>

		<?php endif; ?>

		<!-- Actions -->
		<div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'dc_p2w_build' ); ?>
		<input type="hidden" name="action" value="dc_p2w_build">
		<button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'This will re-scan all products and reset progress. Proceed?', 'dc-webp-converter' ) ); ?>')">
			<?php esc_html_e( 'Scan &amp; Build Queue (PNG + JPG)', 'dc-webp-converter' ); ?>
		</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'dc_p2w_runnow' ); ?>
		<input type="hidden" name="action" value="dc_p2w_runnow">
		<button type="submit" class="button" <?php disabled( empty( $queue ) ); ?>>
			<?php esc_html_e( '▶ Run Batch Now', 'dc-webp-converter' ); ?>
		</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'dc_p2w_reset' ); ?>
		<input type="hidden" name="action" value="dc_p2w_reset">
		<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Reset all progress?', 'dc-webp-converter' ) ); ?>')">
			<?php esc_html_e( 'Reset', 'dc-webp-converter' ); ?>
		</button>
		</form>

		</div><!-- /actions -->
		</div><!-- /progress -->

		<!-- ── Repair Missing Thumbnails ───────────────────────────────── -->
		<?php
		$repair_stats = get_option( self::OPT_REPAIR_STATS );
		$repair_log   = get_option( self::OPT_REPAIR_LOG, [] );
		// Quick count of currently missing thumbnails (lightweight — skips file_exists calls
		// on large libraries; runs only on page load, results are not cached).
		$missing_count = count( self::get_repair_candidates() );
		?>
		<div style="flex:1;min-width:300px;">
		<h2 style="margin-top:0"><?php esc_html_e( 'Repair Missing Thumbnails', 'dc-webp-converter' ); ?></h2>
		<p style="color:#555;margin-top:0"><?php esc_html_e( 'Finds WebP attachments whose thumbnail size files are missing on disk and re-generates them from the original PNG/JPG source. Use this after a conversion run that completed before v1.3.0.', 'dc-webp-converter' ); ?></p>

		<?php if ( $missing_count > 0 ) : ?>
		<p><strong style="color:#cf222e;"><?php
			/* translators: %d: number of attachments with missing thumbnails */
			printf( esc_html__( '%d attachment(s) have missing thumbnail files.', 'dc-webp-converter' ), $missing_count );
		?></strong></p>
		<?php else : ?>
		<p style="color:#2ea44f;font-weight:600"><?php esc_html_e( '✓ No missing thumbnails detected.', 'dc-webp-converter' ); ?></p>
		<?php endif; ?>

		<?php if ( $repair_stats ) : ?>
		<table class="widefat fixed striped" style="width:auto;margin-bottom:12px;">
		<tr><th><?php esc_html_e( 'Last run', 'dc-webp-converter' ); ?></th><td><?php echo esc_html( $repair_stats['ts'] ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Fixed',   'dc-webp-converter' ); ?></th><td style="color:#2ea44f;font-weight:600"><?php echo (int) $repair_stats['fixed']; ?></td></tr>
		<tr><th><?php esc_html_e( 'Skipped', 'dc-webp-converter' ); ?></th><td><?php echo (int) $repair_stats['skipped']; ?></td></tr>
		<tr><th><?php esc_html_e( 'Errors',  'dc-webp-converter' ); ?></th><td style="<?php echo $repair_stats['errors'] > 0 ? 'color:#cf222e;font-weight:600' : ''; ?>"><?php echo (int) $repair_stats['errors']; ?></td></tr>
		</table>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'dc_p2w_repair' ); ?>
		<input type="hidden" name="action" value="dc_p2w_repair">
		<button type="submit" class="button<?php echo $missing_count > 0 ? ' button-primary' : ''; ?>" onclick="return confirm('<?php echo esc_js( __( 'Scan all WebP attachments and convert any missing thumbnail files now?', 'dc-webp-converter' ) ); ?>')">
			<?php esc_html_e( '🔧 Repair Missing Thumbnails', 'dc-webp-converter' ); ?>
		</button>
		</form>

		<?php if ( ! empty( $repair_log ) ) : ?>
		<details style="margin-top:14px">
			<summary style="cursor:pointer;font-weight:600"><?php
				/* translators: %d: number of log entries */
				printf( esc_html__( 'Last repair log (%d entries)', 'dc-webp-converter' ), count( $repair_log ) );
			?></summary>
			<table class="widefat fixed striped" style="margin-top:8px">
			<thead><tr>
				<th width="60"><?php esc_html_e( 'ID', 'dc-webp-converter' ); ?></th>
				<th width="70"><?php esc_html_e( 'Status', 'dc-webp-converter' ); ?></th>
				<th><?php esc_html_e( 'File / Message', 'dc-webp-converter' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( array_reverse( $repair_log ) as $entry ) :
				$c = $entry['status'] === 'ok' ? '#2ea44f' : ( $entry['status'] === 'error' ? '#cf222e' : ( $entry['status'] === 'warn' ? '#f0a500' : '#888' ) );
			?>
			<tr>
				<td><?php echo (int) $entry['id']; ?></td>
				<td><span style="color:<?php echo esc_attr( $c ); ?>;font-weight:600"><?php echo esc_html( $entry['status'] ); ?></span></td>
				<td><code><?php echo esc_html( $entry['msg'] ); ?></code></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
			</table>
		</details>
		<?php endif; ?>

		</div><!-- /repair -->

		</div><!-- /flex -->

		<!-- ── Log ──────────────────────────────────────────────────────── -->
		<?php if ( ! empty( $log ) ) :
			$per_page    = 100;
			$log_rev     = array_reverse( $log );
			$log_total   = count( $log_rev );
			$total_pages = (int) ceil( $log_total / $per_page );
			$cur_page    = max( 1, min( $total_pages, absint( isset( $_GET['logpage'] ) ? wp_unslash( $_GET['logpage'] ) : 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$offset      = ( $cur_page - 1 ) * $per_page;
			$page_items  = array_slice( $log_rev, $offset, $per_page );
			$base_url    = add_query_arg( [ 'page' => 'dc-png-to-webp' ], admin_url( 'tools.php' ) );
		?>
		<h2 style="display:flex;align-items:center;gap:16px;">
			<?php esc_html_e( 'Last Processed', 'dc-webp-converter' ); ?>
			<span style="font-size:.75em;font-weight:400;color:#666;"><?php
			/* translators: %1$s: total number of log entries (formatted), %2$d: current page, %3$d: total pages */
			printf( esc_html__( '%1$s entries — page %2$d of %3$d', 'dc-webp-converter' ), number_format( $log_total ), (int) $cur_page, (int) $total_pages ); ?></span>
		</h2>

		<?php if ( $total_pages > 1 ) :
			$prev_url = $cur_page > 1          ? esc_url( add_query_arg( 'logpage', $cur_page - 1, $base_url ) ) : null;
			$next_url = $cur_page < $total_pages ? esc_url( add_query_arg( 'logpage', $cur_page + 1, $base_url ) ) : null;
		?>
		<div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
			<?php if ( $prev_url ) : ?><a href="<?php echo esc_url( $prev_url ); ?>" class="button button-small"><?php esc_html_e( '« Newer', 'dc-webp-converter' ); ?></a><?php else : ?><button class="button button-small" disabled><?php esc_html_e( '« Newer', 'dc-webp-converter' ); ?></button><?php endif; ?>
			<?php for ( $p = 1; $p <= $total_pages; $p++ ) :
				if ( $p === $cur_page ) : ?>
					<strong style="padding:0 6px;"><?php echo (int) $p; ?></strong>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'logpage', $p, $base_url ) ); ?>" class="button button-small"><?php echo (int) $p; ?></a>
				<?php endif;
				endfor; ?>
			<?php if ( $next_url ) : ?><a href="<?php echo esc_url( $next_url ); ?>" class="button button-small"><?php esc_html_e( 'Older »', 'dc-webp-converter' ); ?></a><?php else : ?><button class="button button-small" disabled><?php esc_html_e( 'Older »', 'dc-webp-converter' ); ?></button><?php endif; ?>
		<?php endif; ?>

		<table class="widefat fixed striped">
		<thead><tr><th width="60"><?php esc_html_e( 'ID', 'dc-webp-converter' ); ?></th><th width="80"><?php esc_html_e( 'Status', 'dc-webp-converter' ); ?></th><th><?php esc_html_e( 'File / Message', 'dc-webp-converter' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $page_items as $entry ) :
			$color = $entry['status'] === 'ok' ? '#2ea44f' : ( $entry['status'] === 'error' ? '#cf222e' : '#888' );
		?>
		<tr>
			<td><?php echo (int) $entry['id']; ?></td>
			<td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600"><?php echo esc_html( $entry['status'] ); ?></span></td>
			<td><code><?php echo esc_html( $entry['msg'] ); ?></code></td>
		</tr>
		<?php endforeach; ?>
		</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
		<div style="display:flex;align-items:center;gap:6px;margin-top:8px;">
			<?php if ( $prev_url ) : ?><a href="<?php echo esc_url( $prev_url ); ?>" class="button button-small"><?php esc_html_e( '« Newer', 'dc-webp-converter' ); ?></a><?php else : ?><button class="button button-small" disabled><?php esc_html_e( '« Newer', 'dc-webp-converter' ); ?></button><?php endif; ?>
			<?php for ( $p = 1; $p <= $total_pages; $p++ ) :
				if ( $p === $cur_page ) : ?>
					<strong style="padding:0 6px;"><?php echo (int) $p; ?></strong>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'logpage', $p, $base_url ) ); ?>" class="button button-small"><?php echo (int) $p; ?></a>
				<?php endif;
				endfor; ?>
			<?php if ( $next_url ) : ?><a href="<?php echo esc_url( $next_url ); ?>" class="button button-small"><?php esc_html_e( 'Older »', 'dc-webp-converter' ); ?></a><?php else : ?><button class="button button-small" disabled><?php esc_html_e( 'Older »', 'dc-webp-converter' ); ?></button><?php endif; ?>
		<?php endif; ?>

		<?php endif; ?>

		</div><!-- /wrap -->
		<?php
	}
}

DC_WebP_Converter::init();
