<?php
/**
 * FisHotel Theme Self-Updater
 *
 * Checks the raw main branch on GitHub for version changes.
 * No plugin required. Works with private repos via access token.
 *
 * For private repos: set FISHOTEL_GITHUB_TOKEN in wp-config.php
 *   define( 'FISHOTEL_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx' );
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Theme_Updater {

	const REPO           = 'Dierks27/FisHotel-Theme';
	const BRANCH         = 'main';
	const TRANSIENT      = 'fishotel_update_check';
	const TRANSIENT_FAIL = 'fishotel_update_check_fail'; // negative cache after an unreachable GitHub
	const CHECK_INTERVAL = 43200; // 12 hours
	const FAIL_INTERVAL  = 600;   // 10 minutes — retry failures sooner than a good result

	/** Get the active theme's directory name (works regardless of folder naming) */
	private static function get_slug() {
		return get_option( 'stylesheet' );
	}

	public static function init() {
		add_filter( 'pre_set_site_transient_update_themes', [ __CLASS__, 'check_for_update' ] );
		add_filter( 'themes_api',                           [ __CLASS__, 'theme_info' ], 20, 3 );
		add_filter( 'upgrader_source_selection',            [ __CLASS__, 'fix_directory_name' ], 10, 4 );
		add_action( 'wp_ajax_fishotel_check_update',        [ __CLASS__, 'ajax_check_update' ] );
	}

	/** AJAX: flush cache and check now */
	public static function ajax_check_update() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		check_ajax_referer( 'fishotel_check_update' );

		delete_transient( self::TRANSIENT );
		delete_transient( self::TRANSIENT_FAIL );

		$current_ver = self::get_current_version();
		$remote_ver  = self::get_remote_version();

		// false = GitHub unreachable. Say so instead of reporting a bogus
		// "up to date … Remote: 0.0.0" success.
		if ( false === $remote_ver ) {
			wp_send_json_error( 'Could not reach GitHub to check for updates. Try again in a few minutes.' );
		}

		if ( version_compare( $remote_ver, $current_ver, '>' ) ) {
			delete_site_transient( 'update_themes' );
			wp_send_json_success( [
				'message' => "Update available! Current: {$current_ver} — Remote: {$remote_ver}. Go to Appearance → Themes to update.",
				'update'  => true,
			] );
		} else {
			wp_send_json_success( [
				'message' => "You're up to date. Installed: {$current_ver}, Remote: {$remote_ver}.",
				'update'  => false,
			] );
		}
	}

	/** Get installed theme version safely */
	private static function get_current_version() {
		$theme = wp_get_theme( self::get_slug() );
		if ( ! $theme->exists() ) {
			return '0.0.0';
		}
		return $theme->get( 'Version' ) ?: '0.0.0';
	}

	/** GitHub request with optional auth token */
	private static function remote_get( $url ) {
		$args = [
			'timeout' => 15,
			'headers' => [],
		];

		$token = defined( 'FISHOTEL_GITHUB_TOKEN' ) ? FISHOTEL_GITHUB_TOKEN : '';
		if ( $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		return wp_remote_get( $url, $args );
	}

	/**
	 * Fetch remote version from raw style.css on main branch.
	 *
	 * Returns false when GitHub can't be reached or style.css has no
	 * Version header. The failure is cached under its own short-lived key
	 * (not the version transient) so a flaky request doesn't masquerade as
	 * a real "0.0.0" release for the next hour.
	 */
	private static function get_remote_version() {
		$cached = get_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return $cached;
		}
		if ( get_transient( self::TRANSIENT_FAIL ) ) {
			return false;
		}

		$url = sprintf(
			'https://raw.githubusercontent.com/%s/%s/style.css',
			self::REPO,
			self::BRANCH
		);

		$response = self::remote_get( $url );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::TRANSIENT_FAIL, 1, self::FAIL_INTERVAL );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! preg_match( '/Version:\s*(\S+)/i', $body, $m ) ) {
			set_transient( self::TRANSIENT_FAIL, 1, self::FAIL_INTERVAL );
			return false;
		}
		$version = $m[1];

		set_transient( self::TRANSIENT, $version, self::CHECK_INTERVAL );
		return $version;
	}

	/** Hook: check if remote version is newer */
	public static function check_for_update( $transient ) {
		// Guard: $transient can be false or non-object on first run
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$slug        = self::get_slug();
		$current_ver = self::get_current_version();
		$remote_ver  = self::get_remote_version();

		// Unreachable GitHub → leave the transient alone; nothing to offer.
		if ( false === $remote_ver ) {
			return $transient;
		}

		if ( $current_ver !== '0.0.0' && version_compare( $remote_ver, $current_ver, '>' ) ) {
			$transient->response[ $slug ] = [
				'theme'       => $slug,
				'new_version' => $remote_ver,
				'url'         => 'https://github.com/' . self::REPO,
				'package'     => self::get_download_url(),
			];
		}

		return $transient;
	}

	/** Build the zip download URL for the branch */
	private static function get_download_url() {
		$url = sprintf(
			'https://api.github.com/repos/%s/zipball/%s',
			self::REPO,
			self::BRANCH
		);

		return $url;
	}

	/** Hook: provide theme info for the update details modal */
	public static function theme_info( $result, $action, $args ) {
		if ( 'theme_information' !== $action ) {
			return $result;
		}

		$slug = self::get_slug();
		if ( ! isset( $args->slug ) || $args->slug !== $slug ) {
			return $result;
		}

		$theme      = wp_get_theme( $slug );
		$remote_ver = self::get_remote_version();
		// Can't reach GitHub → show the installed version rather than 0.0.0.
		if ( false === $remote_ver ) {
			$remote_ver = self::get_current_version();
		}

		return (object) [
			'name'          => $theme->get( 'Name' ),
			'slug'          => $slug,
			'version'       => $remote_ver,
			'author'        => $theme->get( 'Author' ),
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => self::get_download_url(),
			'requires'      => $theme->get( 'RequiresWP' ),
			'requires_php'  => $theme->get( 'RequiresPHP' ),
			'sections'      => [
				'description' => $theme->get( 'Description' ),
				'changelog'   => '<p>See <a href="https://github.com/' . self::REPO . '/commits/' . self::BRANCH . '">commit history</a> for changes.</p>',
			],
		];
	}

	/**
	 * Hook: fix directory name after extraction.
	 * GitHub zips extract to "Dierks27-FisHotel-Theme-abc123/" — rename to match current slug.
	 */
	public static function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
		$slug = self::get_slug();
		if ( ! isset( $hook_extra['theme'] ) || $hook_extra['theme'] !== $slug ) {
			return $source;
		}

		$correct_dest = trailingslashit( $remote_source ) . $slug . '/';

		if ( $source !== $correct_dest ) {
			global $wp_filesystem;
			if ( $wp_filesystem && $wp_filesystem->move( $source, $correct_dest ) ) {
				return $correct_dest;
			}
		}

		return $source;
	}

	/**
	 * Add auth header for GitHub API download requests.
	 *
	 * Anchored to scheme https + host api.github.com + path prefix
	 * /repos/<REPO>/ — the old unanchored substring match would have handed
	 * the PAT to ANY outbound WP HTTP request whose URL merely contained the
	 * repo path (e.g. an XML-RPC pingback target or a crafted oEmbed URL).
	 */
	public static function inject_auth_header( $parsed_args, $url ) {
		$p = wp_parse_url( (string) $url );
		if ( ! is_array( $p )
			|| empty( $p['scheme'] ) || 'https' !== strtolower( $p['scheme'] )
			|| empty( $p['host'] ) || 'api.github.com' !== strtolower( $p['host'] )
			|| empty( $p['path'] ) || 0 !== strpos( $p['path'], '/repos/' . self::REPO . '/' ) ) {
			return $parsed_args;
		}

		$token = defined( 'FISHOTEL_GITHUB_TOKEN' ) ? FISHOTEL_GITHUB_TOKEN : '';
		if ( $token ) {
			$parsed_args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		return $parsed_args;
	}
}

// Hook the auth header injection for download requests
add_filter( 'http_request_args', [ 'FisHotel_Theme_Updater', 'inject_auth_header' ], 10, 2 );

FisHotel_Theme_Updater::init();
