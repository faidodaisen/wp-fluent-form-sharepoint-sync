<?php
namespace FFSP\Update;

use FFSP\Contracts\Module;
use FFSP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Updates from GitHub Releases.
 *
 * The plugin header declares `Update URI: https://github.com/<repo>`, so WordPress (5.8+) asks
 * the `update_plugins_github.com` hook for this plugin instead of wordpress.org. The update then
 * shows up on Dashboard → Updates and the Plugins screen with the usual "Update now" link.
 *
 *  - Newest non-draft, non-prerelease release whose tag parses as a version.
 *  - Offered only when strictly newer than the installed version (no phantom updates).
 *  - The built `<slug>-<version>.zip` asset is preferred; GitHub's zipball is the fallback and
 *    its `owner-repo-sha/` folder is renamed back to the plugin folder on install.
 *  - Lookups are cached (6 h on success, 30 min on failure).
 */
class Updater implements Module {

	const REPO      = 'faidodaisen/wp-fluent-form-sharepoint-sync';
	const CACHE_KEY = 'ffsp_update_check';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const ERROR_TTL = 30 * MINUTE_IN_SECONDS;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'check_for_update' ), 10, 3 );
		add_filter( 'site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 0 );

		add_filter( 'plugin_row_meta', array( $this, 'row_meta_link' ), 10, 2 );
		add_action( 'admin_post_ffsp_check_update', array( $this, 'handle_manual_check' ) );
		add_action( 'admin_notices', array( $this, 'manual_check_notice' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Config                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array{repo:string,enabled:bool,prereleases:bool}
	 */
	public static function config() {
		$config   = array(
			'repo'        => self::REPO,
			'enabled'     => true,
			'prereleases' => false,
		);
		$filtered = apply_filters( 'ffsp/update_config', $config );
		if ( ! is_array( $filtered ) ) {
			return $config;
		}
		$repo = isset( $filtered['repo'] ) ? (string) $filtered['repo'] : $config['repo'];
		return array(
			'repo'        => preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : $config['repo'],
			'enabled'     => ! isset( $filtered['enabled'] ) || ! empty( $filtered['enabled'] ),
			'prereleases' => ! empty( $filtered['prereleases'] ),
		);
	}

	public static function basename() {
		return plugin_basename( FFSP_FILE );
	}

	public static function slug() {
		return dirname( self::basename() );
	}

	/* ------------------------------------------------------------------ */
	/* Remote lookup                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * @param bool $force Bypass the cache.
	 * @return array{version:string,package:string,url:string,notes:string,published:string}|null
	 */
	public static function latest_release( $force = false ) {
		$config = self::config();
		if ( ! $config['enabled'] ) {
			return null;
		}
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return empty( $cached['release'] ) ? null : $cached['release'];
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $config['repo'] . '/releases?per_page=10',
			array(
				'timeout'             => 15,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'headers'             => array(
					'User-Agent' => 'FluentSharePointSync/' . FFSP_VERSION,
					'Accept'     => 'application/vnd.github+json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::cache_failure( $response->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d HTTP status */
			self::cache_failure( 403 === $code ? __( 'GitHub rate limit reached — will retry later.', 'fluent-sharepoint-sync' ) : sprintf( __( 'GitHub returned HTTP %d.', 'fluent-sharepoint-sync' ), $code ) );
			return null;
		}
		$releases = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $releases ) ) {
			self::cache_failure( __( 'GitHub returned an unreadable response.', 'fluent-sharepoint-sync' ) );
			return null;
		}

		$best = self::pick_release( $releases, $config['prereleases'] );
		set_site_transient( self::CACHE_KEY, array( 'release' => $best, 'error' => '', 'checked' => time() ), self::CACHE_TTL );
		return $best;
	}

	/**
	 * Pure selection logic (unit-tested).
	 */
	public static function pick_release( array $releases, $prereleases = false ) {
		$best = null;
		foreach ( $releases as $r ) {
			if ( ! is_array( $r ) || ! empty( $r['draft'] ) || ( ! empty( $r['prerelease'] ) && ! $prereleases ) ) {
				continue;
			}
			$version = self::normalize_version( isset( $r['tag_name'] ) ? (string) $r['tag_name'] : '' );
			if ( '' === $version || ( $best && version_compare( $version, $best['version'], '<=' ) ) ) {
				continue;
			}
			$package = '';
			foreach ( isset( $r['assets'] ) && is_array( $r['assets'] ) ? $r['assets'] : array() as $asset ) {
				$name = is_array( $asset ) && isset( $asset['name'] ) ? strtolower( (string) $asset['name'] ) : '';
				if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
					$package = (string) $asset['browser_download_url'];
					break;
				}
			}
			if ( '' === $package && ! empty( $r['zipball_url'] ) ) {
				$package = (string) $r['zipball_url'];
			}
			if ( '' === $package || 0 !== strpos( $package, 'https://' ) ) {
				continue;
			}
			$best = array(
				'version'   => $version,
				'package'   => $package,
				'url'       => isset( $r['html_url'] ) ? (string) $r['html_url'] : '',
				'notes'     => isset( $r['body'] ) ? (string) $r['body'] : '',
				'published' => isset( $r['published_at'] ) ? (string) $r['published_at'] : '',
			);
		}
		return $best;
	}

	private static function normalize_version( $tag ) {
		return preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', trim( $tag ), $m ) ? $m[1] : '';
	}

	public static function is_newer( $remote ) {
		return version_compare( $remote, FFSP_VERSION, '>' );
	}

	private static function cache_failure( $message ) {
		set_site_transient( self::CACHE_KEY, array( 'release' => null, 'error' => (string) $message, 'checked' => time() ), self::ERROR_TTL );
	}

	public static function status() {
		$c = get_site_transient( self::CACHE_KEY );
		return is_array( $c ) ? $c : array();
	}

	public static function clear_cache() {
		delete_site_transient( self::CACHE_KEY );
	}

	/* ------------------------------------------------------------------ */
	/* WordPress update plumbing                                           */
	/* ------------------------------------------------------------------ */

	private function payload( array $release ) {
		return array(
			'id'            => 'github.com/' . self::config()['repo'],
			'slug'          => self::slug(),
			'plugin'        => self::basename(),
			'version'       => $release['version'],
			'new_version'   => $release['version'],
			'url'           => $release['url'],
			'package'       => $release['package'],
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'tested'        => get_bloginfo( 'version' ),
			'requires_php'  => '7.4',
			'compatibility' => new \stdClass(),
		);
	}

	public function check_for_update( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$release = self::latest_release();
		return ( $release && self::is_newer( $release['version'] ) ) ? $this->payload( $release ) : $update;
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$base    = self::basename();
		$release = self::latest_release();
		if ( ! $release || ! self::is_newer( $release['version'] ) ) {
			if ( isset( $transient->response[ $base ] ) ) {
				unset( $transient->response[ $base ] );
			}
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $base ] = (object) $this->payload( $release );
		unset( $transient->no_update[ $base ] );
		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Fluent Forms → SharePoint Sync',
			'slug'          => self::slug(),
			'version'       => $release['version'],
			'author'        => '<a href="https://armiena.com/">Armiena Group</a>',
			'homepage'      => 'https://github.com/' . self::config()['repo'],
			'requires'      => '6.2',
			'requires_php'  => '7.4',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Send Fluent Forms submissions (fields + uploaded files) to Microsoft SharePoint through a Power Automate HTTP endpoint.', 'fluent-sharepoint-sync' ) . '</p>',
				'changelog'   => self::render_notes( $release['notes'], $release['url'] ),
			),
		);
	}

	/**
	 * Minimal Markdown → HTML; everything is escaped first so notes can never inject markup.
	 */
	private static function render_notes( $body, $url ) {
		$out  = '';
		$list = false;
		foreach ( (array) preg_split( '/\R/', trim( (string) $body ) ) as $line ) {
			$t = trim( $line );
			if ( preg_match( '/^[-*]\s+(.*)$/', $t, $m ) ) {
				$out .= ( $list ? '' : '<ul>' ) . '<li>' . self::inline( $m[1] ) . '</li>';
				$list = true;
				continue;
			}
			if ( $list ) {
				$out .= '</ul>';
				$list = false;
			}
			if ( '' === $t ) {
				continue;
			}
			$out .= preg_match( '/^#{1,6}\s+(.*)$/', $t, $m ) ? '<h4>' . self::inline( $m[1] ) . '</h4>' : '<p>' . self::inline( $t ) . '</p>';
		}
		if ( $list ) {
			$out .= '</ul>';
		}
		return $out . '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View this release on GitHub', 'fluent-sharepoint-sync' ) . '</a></p>';
	}

	private static function inline( $text ) {
		$text = esc_html( $text );
		$text = (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		return (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
	}

	/**
	 * A GitHub zipball unpacks to `owner-repo-sha/`; rename it to the installed folder so the
	 * update replaces the plugin in place (and it stays active).
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader = null, $args = array() ) {
		if ( is_wp_error( $source ) || ! is_array( $args ) || ( $args['plugin'] ?? '' ) !== self::basename() ) {
			return $source;
		}
		$slug = self::slug();
		if ( basename( untrailingslashit( $source ) ) === $slug ) {
			return $source;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source;
		}
		$target = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $slug;
		if ( $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}
		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $target ) ) {
			return new \WP_Error( 'ffsp_rename_failed', __( 'Could not rename the downloaded package folder. The update was not installed.', 'fluent-sharepoint-sync' ) );
		}
		return trailingslashit( $target );
	}

	/* ------------------------------------------------------------------ */
	/* Manual "Check for updates"                                          */
	/* ------------------------------------------------------------------ */

	public static function check_url( $redirect = '' ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'ffsp_check_update',
					'redirect' => rawurlencode( $redirect ? $redirect : admin_url( 'plugins.php' ) ),
				),
				admin_url( 'admin-post.php' )
			),
			'ffsp_check_update'
		);
	}

	public function row_meta_link( $links, $file ) {
		if ( self::basename() === $file && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( self::check_url() ) . '">' . esc_html__( 'Check for updates', 'fluent-sharepoint-sync' ) . '</a>';
		}
		return $links;
	}

	public function handle_manual_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to check for updates.', 'fluent-sharepoint-sync' ), 403 );
		}
		check_admin_referer( 'ffsp_check_update' );
		self::clear_cache();
		$release = self::latest_release( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$status   = ! $release ? 'error' : ( self::is_newer( $release['version'] ) ? 'available' : 'current' );
		$redirect = isset( $_GET['redirect'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['redirect'] ) ) ) : '';
		$redirect = wp_validate_redirect( $redirect, admin_url( 'plugins.php' ) );
		wp_safe_redirect( add_query_arg( 'ffsp_checked', $status, $redirect ) );
		exit;
	}

	public function manual_check_notice() {
		if ( ! isset( $_GET['ffsp_checked'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$status  = sanitize_key( wp_unslash( $_GET['ffsp_checked'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$release = self::latest_release();
		$s       = self::status();
		if ( 'available' === $status && $release ) {
			/* translators: %s version */
			$msg   = sprintf( __( 'Fluent Forms → SharePoint Sync %s is available. Use "Update now" on the Plugins screen.', 'fluent-sharepoint-sync' ), $release['version'] );
			$class = 'notice-warning';
		} elseif ( 'current' === $status ) {
			$msg   = __( 'Fluent Forms → SharePoint Sync is up to date.', 'fluent-sharepoint-sync' );
			$class = 'notice-success';
		} elseif ( 'error' === $status ) {
			/* translators: %s reason */
			$msg   = sprintf( __( 'Update check failed: %s', 'fluent-sharepoint-sync' ), $s['error'] ?? '' );
			$class = 'notice-error';
		} else {
			return;
		}
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $msg ) );
	}
}
