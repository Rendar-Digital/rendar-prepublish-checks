<?php
/** Public GitHub Release updates; no credentials or site setup required. */

final class Rendar_PC_Updater {
	private $slug = 'rendar-prepublish-checks';
	private $file;
	private $cache = 'rendar_pc_updates';

	public function __construct( $plugin_file, $version ) {
		if ( defined( 'RENDAR_UPDATES_DISABLED' ) && RENDAR_UPDATES_DISABLED ) {
			return;
		}

		$this->file = function_exists( 'plugin_basename' ) ? plugin_basename( $plugin_file ) : basename( $plugin_file );
		add_filter( 'plugins_api', array( $this, 'info' ), 10, 3 );
		add_filter( 'update_plugins_updates.rendar.digital', array( $this, 'updates' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear' ), 10, 2 );
	}

	private function info_url() {
		return 'https://github.com/Rendar-Digital/' . $this->slug . '/releases/latest/download/info.json';
	}

	private function package_url( $tag ) {
		return 'https://github.com/Rendar-Digital/' . $this->slug . '/releases/download/' . $tag . '/' . $this->slug . '.zip';
	}

	private function release() {
		$cached = get_site_transient( $this->cache );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$data = null;
		$response = wp_remote_get( $this->info_url(), array( 'timeout' => 5, 'redirection' => 5 ) );
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! $this->valid( $data ) ) {
				$data = null;
			}
		}

		set_site_transient( $this->cache, $data ?: array(), $data ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
	}

	private function valid( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}
		foreach ( array( 'slug', 'name', 'version', 'tag', 'package', 'requires', 'requires_php', 'tested', 'last_updated', 'changelog' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				return false;
			}
		}

		return $this->slug === $data['slug']
			&& (bool) preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $data['version'] )
			&& 'v' . $data['version'] === $data['tag']
			&& $this->package_url( $data['tag'] ) === $data['package']
			&& (bool) preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $data['requires'] )
			&& (bool) preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $data['requires_php'] )
			&& (bool) preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $data['tested'] );
	}

	/** Core calls this for each Update URI plugin, including WP-CLI and cron checks. */
	public function updates( $update, $plugin_data, $plugin_file, $locales ) {
		if ( $plugin_file !== $this->file ) {
			return $update;
		}
		$data = $this->release();
		if ( ! $data ) {
			return false;
		}

		return array(
			'version' => $data['version'],
			'slug' => $this->slug,
			'url' => 'https://updates.rendar.digital/' . $this->slug,
			'package' => $data['package'],
			'requires' => $data['requires'],
			'requires_php' => $data['requires_php'],
			'tested' => $data['tested'],
		);
	}

	public function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== $this->slug ) {
			return $result;
		}
		$data = $this->release();
		if ( ! $data ) {
			return new WP_Error( 'rendar_updates_unavailable', 'Plugin information is unavailable.' );
		}

		return (object) array(
			'name' => esc_html( $data['name'] ),
			'slug' => $this->slug,
			'version' => $data['version'],
			'download_link' => $data['package'],
			'requires' => $data['requires'],
			'requires_php' => $data['requires_php'],
			'tested' => $data['tested'],
			'last_updated' => esc_html( $data['last_updated'] ),
			'sections' => array( 'changelog' => nl2br( esc_html( $data['changelog'] ) ) ),
		);
	}

	public function clear( $upgrader, $options ) {
		if ( isset( $options['type'], $options['action'] ) && 'plugin' === $options['type']
			&& 'update' === $options['action'] && ( ( $options['plugin'] ?? null ) === $this->file
				|| in_array( $this->file, $options['plugins'] ?? array(), true ) ) ) {
			delete_site_transient( $this->cache );
		}
	}
}
