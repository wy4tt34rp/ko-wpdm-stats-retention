<?php
/**
 * Plugin Name: KO WPDM Stats Retention (90 Days)
 * Description: Keeps WP Download Manager Download History (ahm_download_stats) for the last 90 days by purging older rows daily. Includes manual run tool under Tools.
 * Author: KO helper
 * Version: 1.2.0
 */

if ( ! defined('ABSPATH') ) exit;

class KO_WPDM_Stats_Retention {

	// === CONFIG ===
	const DEFAULT_DAYS      = 90;   // retention window
	const DEFAULT_BATCH     = 5000; // rows per DELETE
	const DEFAULT_MAX_LOOPS = 50;   // max batch loops per run

	const CRON_HOOK = 'ko_wpdm_stats_retention_daily';

	// Tools page + actions
	const TOOLS_SLUG        = 'ko-wpdm-stats-retention';
	const ACTION_RUN        = 'ko_wpdm_stats_retention_run';
	const ACTION_CLEAR_LOG  = 'ko_wpdm_stats_retention_clear_log';

	public static function init() {
		add_action('init', [__CLASS__, 'schedule_cron']);
		add_action(self::CRON_HOOK, [__CLASS__, 'run_cleanup']);

		// Admin UI
		add_action('admin_menu', [__CLASS__, 'register_tools_page']);
		add_action('admin_post_' . self::ACTION_RUN, [__CLASS__, 'handle_manual_run']);
		add_action('admin_post_' . self::ACTION_CLEAR_LOG, [__CLASS__, 'handle_clear_log']);
	}

	/* =========================
	 * Cron scheduling
	 * ========================= */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
			// Start ~10 minutes from now to avoid running during peak deploy/load moments.
			wp_schedule_event(time() + 600, 'daily', self::CRON_HOOK);
			self::log('Scheduled daily cron event.');
		}
	}

	/* =========================
	 * Core cleanup
	 * ========================= */
	public static function run_cleanup() {
		global $wpdb;

		$days      = (int) apply_filters('ko_wpdm_stats_retention_days', self::DEFAULT_DAYS);
		$batch     = (int) apply_filters('ko_wpdm_stats_retention_batch', self::DEFAULT_BATCH);
		$max_loops = (int) apply_filters('ko_wpdm_stats_retention_max_loops', self::DEFAULT_MAX_LOOPS);

		if ( $days < 1 ) {
			self::log("Abort: retention days invalid ({$days}).");
			return;
		}

		/**
		 * IMPORTANT: Your WPDM "Download History" is stored in:
		 *   {$wpdb->prefix}ahm_download_stats
		 *
		 * And the date is stored as a Unix timestamp in the column:
		 *   timestamp (int)
		 */
		$table   = $wpdb->prefix . 'ahm_download_stats';
		$dateCol = 'timestamp';

		// Confirm table exists (some environments may differ)
		$exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table) );
		if ( empty($exists) ) {
			// Fallback if someone has an unprefixed table (rare, but you had one unprefixed AHM table listed)
			$fallback = 'ahm_download_stats';
			$exists2  = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $fallback) );
			if ( empty($exists2) ) {
				self::log("Abort: stats table not found ({$table} or {$fallback}). Nothing deleted.");
				return;
			}
			$table = $fallback;
		}

		$table_sql = self::escape_identifier($table);
		$col_sql   = self::escape_identifier($dateCol);

		if ( ! $table_sql || ! $col_sql ) {
			self::log('Abort: unsafe table/column identifier detected.');
			return;
		}

		self::log("Using table: {$table}, date column: {$dateCol} (UNIX timestamp). Retention: {$days} days. Batch: {$batch}. Max loops: {$max_loops}.");

		// Delete in batches to avoid long locks on big tables.
		$total_deleted = 0;
		$loops = 0;

		while ( $loops < $max_loops ) {
			$loops++;

			// timestamp is an INT (unix time), so compare to UNIX_TIMESTAMP(...)
			$sql = $wpdb->prepare(
				"DELETE FROM {$table_sql}
				 WHERE {$col_sql} < UNIX_TIMESTAMP(UTC_TIMESTAMP() - INTERVAL %d DAY)
				 LIMIT %d",
				$days,
				$batch
			);

			$deleted = $wpdb->query($sql);

			if ( $deleted === false ) {
				self::log('Delete query failed: ' . $wpdb->last_error);
				break;
			}

			$total_deleted += (int) $deleted;

			// If we deleted less than the batch size, we’re done.
			if ( $deleted < $batch ) {
				break;
			}
		}

		self::log("Cleanup complete. Deleted {$total_deleted} rows in {$loops} loop(s).");
	}

	/* =========================
	 * Tools page (manual run)
	 * ========================= */
	public static function register_tools_page() {
		add_management_page(
			'WPDM Stats Retention',
			'WPDM Stats Retention',
			'manage_options',
			self::TOOLS_SLUG,
			[__CLASS__, 'render_tools_page']
		);
	}

	public static function render_tools_page() {
		if ( ! current_user_can('manage_options') ) {
			wp_die('You do not have permission to access this page.');
		}

		$log_path = self::get_log_path();
		$log_exists = file_exists($log_path);
		$log_size = $log_exists ? filesize($log_path) : 0;

		// Show last ~200 lines for convenience (best effort)
		$tail = '';
		if ( $log_exists && is_readable($log_path) ) {
			$tail = self::tail_file($log_path, 200);
		}

		$run_url = admin_url('admin-post.php');
		$clear_url = admin_url('admin-post.php');

		?>
		<div class="wrap">
			<h1>WPDM Stats Retention</h1>

			<p>
				This tool purges WP Download Manager download history records older than <strong><?php echo esc_html((string) apply_filters('ko_wpdm_stats_retention_days', self::DEFAULT_DAYS)); ?> days</strong>
				from <code><?php echo esc_html($GLOBALS['wpdb']->prefix . 'ahm_download_stats'); ?></code>.
			</p>

			<hr />

			<h2>Manual Run</h2>
			<p>Clicking “Run Cleanup Now” will immediately execute the same cleanup routine used by the daily cron job.</p>

			<form method="post" action="<?php echo esc_url($run_url); ?>" style="display:inline-block;margin-right:12px;">
				<?php wp_nonce_field(self::ACTION_RUN, '_wpnonce'); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RUN); ?>">
				<?php submit_button('Run Cleanup Now', 'primary', 'submit', false); ?>
			</form>

			<form method="post" action="<?php echo esc_url($clear_url); ?>" style="display:inline-block;">
				<?php wp_nonce_field(self::ACTION_CLEAR_LOG, '_wpnonce'); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_CLEAR_LOG); ?>">
				<?php submit_button('Clear Log File', 'secondary', 'submit', false); ?>
			</form>

			<hr />

			<h2>Log</h2>
			<p>
				Log file: <code><?php echo esc_html($log_path); ?></code><br>
				Status: <strong><?php echo $log_exists ? 'Present' : 'Not found yet'; ?></strong>
				<?php if ( $log_exists ) : ?>
					(<?php echo esc_html(size_format($log_size)); ?>)
				<?php endif; ?>
			</p>

			<?php if ( $tail ) : ?>
				<h3>Recent entries (tail)</h3>
				<textarea readonly style="width:100%;max-width:1200px;height:360px;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?php echo esc_textarea($tail); ?></textarea>
			<?php else : ?>
				<p><em>No log entries yet.</em></p>
			<?php endif; ?>

			<hr />

			<h2>Notes</h2>
			<ul style="list-style:disc;padding-left:22px;">
				<li>For large tables, consider adding an index on <code>timestamp</code> for faster purges.</li>
				<li>This tool only deletes old download-history rows. It does not affect packages/files.</li>
			</ul>
		</div>
		<?php
	}

	public static function handle_manual_run() {
		if ( ! current_user_can('manage_options') ) {
			wp_die('You do not have permission to do that.');
		}
		check_admin_referer(self::ACTION_RUN);

		self::log('Manual run triggered from Tools → WPDM Stats Retention.');
		self::run_cleanup();

		wp_safe_redirect( admin_url('tools.php?page=' . self::TOOLS_SLUG . '&ran=1') );
		exit;
	}

	public static function handle_clear_log() {
		if ( ! current_user_can('manage_options') ) {
			wp_die('You do not have permission to do that.');
		}
		check_admin_referer(self::ACTION_CLEAR_LOG);

		$path = self::get_log_path();
		if ( file_exists($path) ) {
			@unlink($path);
		}

		wp_safe_redirect( admin_url('tools.php?page=' . self::TOOLS_SLUG . '&cleared=1') );
		exit;
	}

	/* =========================
	 * Helpers
	 * ========================= */
	private static function escape_identifier($ident) {
		$ident = (string) $ident;

		// Allow only letters, numbers, underscore
		if ( ! preg_match('/^[A-Za-z0-9_]+$/', $ident) ) {
			return false;
		}

		return '`' . $ident . '`';
	}

	private static function get_log_path() {
		$uploads = wp_upload_dir();
		return trailingslashit($uploads['basedir']) . 'wpdm-stats-retention.log';
	}

	private static function log($message) {
		$path = self::get_log_path();
		$line = sprintf("[%s] %s\n", gmdate('Y-m-d H:i:s') . ' UTC', $message);
		@file_put_contents($path, $line, FILE_APPEND);
	}

	/**
	 * Best-effort "tail" for a text file without reading the whole thing into memory.
	 */
	private static function tail_file($path, $max_lines = 200) {
		$max_lines = max(1, (int) $max_lines);

		$fp = @fopen($path, 'rb');
		if ( ! $fp ) return '';

		$buffer = '';
		$chunk_size = 8192;

		fseek($fp, 0, SEEK_END);
		$pos = ftell($fp);

		$lines = 0;

		while ( $pos > 0 && $lines <= $max_lines ) {
			$read = min($chunk_size, $pos);
			$pos -= $read;

			fseek($fp, $pos);
			$chunk = fread($fp, $read);
			if ( $chunk === false ) break;

			$buffer = $chunk . $buffer;
			$lines = substr_count($buffer, "\n");
		}

		fclose($fp);

		$parts = explode("\n", trim($buffer));
		if ( count($parts) > $max_lines ) {
			$parts = array_slice($parts, -$max_lines);
		}
		return implode("\n", $parts) . "\n";
	}
}

KO_WPDM_Stats_Retention::init();