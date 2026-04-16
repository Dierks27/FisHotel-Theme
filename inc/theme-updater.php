<?php
/**
 * FisHotel Theme Self-Updater
 *
 * Checks the raw main branch on GitHub for version changes.
 * No plugin required. Works with private repos via access token.
 *
 * How it works:
 * 1. Every 12 hours, fetches style.css from the raw main branch
 * 2. Parses the Version: header and compares to installed version
 * 3. If newer, tells WordPress to download the zip from GitHub
 *
 * For private repos: set FISHOTEL_GITHUB_TOKEN in wp-config.php
 *   define( 'FISHOTEL_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx' );
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Theme_Updater {

	const REPO       = 'Dierks27/FisHotel-Theme';
	const BRANCH     = 'main';
	const SLUG       = 'FisHotel-Theme';
	const TRANSIENT  = 'fishotel_update_check';
	const CHECK_INTERVAL = 43200; // 12 hours

	public static function init() {
		add_filter( 'pre_set_site_transient_update_themes', [ __CLASS__, 'check_for_update' ] );
		add_filter( 'themes_api',                           [ __CLASS__, 'theme_info' ], 20, 3 );
		add_filter( 'upgrader_source_selection',            [ __CLASS__, 'fix_directory_name' ], 10, 4 );
	}

	/** GitHub API/raw request with optional auth token */
	private static function remote_get( $url ) {
		$args = [
			'timeout' => 15,
			'headers' => [ 'Accept' => 'application/vnd.github.v3.raw' ],
		];

		$token = defined( 'FISHOTEL_GITHUB_TOKEN' ) ? FISHOTEL_GITHUB_TOKEN : '';
		if ( $token ) {
			$args['headers']['Authorization'] = 'token ' . $token;
		}

		return wp_remote_get( $url, $args );
	}

	/** Fetch remote version from raw style.css on main branch */
	private static function get_remote_version() {
		$cached = get_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = sprintf(
			'https://raw.githubusercontent.com/%s/%s/style.css',
			self::REPO,
			self::BRANCH
		);

		$response = self::remote_get( $url );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache failure for 1 hour to avoid hammering
			set_transient( self::TRANSIENT, '0.0.0', 3600 );
			return '0.0.0';
		}

		$body = wp_remote_retrieve_body( $response );

		if ( preg_match( '/Version:\s*(\S+)/i', $body, $m ) ) {
			$version = $m[1];
		} else {
			$version = '0.0.0';
		}

		set_transient( self::TRANSIENT, $version, self::CHECK_INTERVAL );
		return $version;
	}

	/** Hook: check if remote version is newer */
	public static function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$theme          = wp_get_theme( self::SLUG );
		$current_ver    = $theme->get( 'Version' );
		$remote_ver     = self::get_remote_version();

		if ( version_compare( $remote_ver, $current_ver, '>' ) ) {
			$transient->response[ self::SLUG ] = [
				'theme'       => self::SLUG,
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

		$token = defined( 'FISHOTEL_GITHUB_TOKEN' ) ? FISHOTEL_GITHUB_TOKEN : '';
		if ( $token ) {
			$url = add_query_arg( 'access_token', $token, $url );
		}

		return $url;
	}

	/** Hook: provide theme info for the update details modal */
	public static function theme_info( $result, $action, $args ) {
		if ( 'theme_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== self::SLUG ) {
			return $result;
		}

		$theme      = wp_get_theme( self::SLUG );
		$remote_ver = self::get_remote_version();

		return (object) [
			'name'          => $theme->get( 'Name' ),
			'slug'          => self::SLUG,
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
	 * GitHub zips extract to "Dierks27-FisHotel-Theme-abc123/" — rename to "FisHotel-Theme/"
	 */
	public static function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['theme'] ) || $hook_extra['theme'] !== self::SLUG ) {
			return $source;
		}

		$correct_dest = trailingslashit( $remote_source ) . self::SLUG . '/';

		if ( $source !== $correct_dest ) {
			global $wp_filesystem;
			if ( $wp_filesystem->move( $source, $correct_dest ) ) {
				return $correct_dest;
			}
		}

		return $source;
	}
}

FisHotel_Theme_Updater::init();
