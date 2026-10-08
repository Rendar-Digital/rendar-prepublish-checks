<?php
/** Private release updates; no credentials are persisted or displayed. */

final class Rendar_PC_Updater {
	private $slug = 'rendar-prepublish-checks';
	private $file;
	private $base;
	private $token;
	private $cache;

	public function __construct( $plugin_file, $version ) {
		$this->base    = defined( 'RENDAR_UPDATES_URL' ) && is_string( RENDAR_UPDATES_URL ) ? RENDAR_UPDATES_URL : '';
		$this->token   = defined( 'RENDAR_UPDATES_TOKEN' ) && is_string( RENDAR_UPDATES_TOKEN ) ? RENDAR_UPDATES_TOKEN : '';
		add_filter( 'plugins_api', array( $this, 'info' ), 10, 3 );
		if ( ! $this->configured() ) {
			return;
		}
		$this->base = rtrim( $this->base, '/' );
		$this->file = plugin_basename( $plugin_file );
		// Hash the configuration into the key; never persist the bearer itself.
		$this->cache = 'rendar_pc_updates_' . substr( hash( 'sha256', $this->base . "\0" . $this->token ), 0, 24 );
		add_filter( 'update_plugins_updates.rendar.digital', array( $this, 'updates' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'authorize' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'clear' ), 10, 2 );
	}

	private function configured() {
		if ( '' === $this->base || '' === $this->token ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x20\x7f]/', $this->base ) ) {
			return false;
		}
		$p = wp_parse_url( $this->base );
		return '' !== $this->token && is_array( $p ) && 'https' === ( $p['scheme'] ?? '' )
			&& ! empty( $p['host'] ) && ! isset( $p['user'] ) && ! isset( $p['pass'] )
			&& ! isset( $p['query'] ) && ! isset( $p['fragment'] )
			&& ! preg_match( '/[\r\n]/', $this->token )
			&& ( ! isset( $p['path'] ) || ( 0 === strpos( $p['path'], '/' ) && ! preg_match( '~//|/\.|%|\\\\~', $p['path'] ) ) );
	}

	/** Compare parsed origins and the exact path boundary, never substrings of hosts. */
	private function under_api( $url ) {
		// Reject bytes that URL parsers/transports can trim or reinterpret before routing.
		if ( ! is_string( $url ) || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return false;
		}
		$base = wp_parse_url( $this->base );
		$path = wp_parse_url( $url );
		return is_array( $path ) && 'https' === ( $path['scheme'] ?? '' )
			&& strtolower( $path['host'] ?? '' ) === strtolower( $base['host'] )
			&& ( $path['port'] ?? 443 ) === ( $base['port'] ?? 443 )
			&& ! isset( $path['user'] ) && ! isset( $path['pass'] )
			&& ! isset( $path['fragment'] )
			&& ! preg_match( '~%|\\\\|//|/\.~', $path['path'] ?? '' )
			&& ! str_contains( $path['host'] ?? '', '%' )
			&& 0 === strpos( $path['path'] ?? '', ( $base['path'] ?? '' ) . '/v1/' );
	}

	public function authorize( $args, $url ) {
		if ( $this->under_api( $url ) ) {
			if ( ! is_array( $args['headers'] ?? null ) ) {
				$args['headers'] = WP_Http::processHeaders( $args['headers'] ?? '' )['headers'];
			}
			foreach ( array_keys( $args['headers'] ) as $key ) {
				if ( 0 === strcasecmp( (string) $key, 'Authorization' ) ) {
					unset( $args['headers'][ $key ] );
				}
			}
			$args['headers']['Authorization'] = 'Bearer ' . $this->token;
			// WP's HTTP transport can follow redirects without reapplying this filter.
			// Do not carry the bearer to an untrusted redirect destination.
			$args['redirection'] = 0;
		}
		return $args;
	}

	private function release() {
		$cached = get_site_transient( $this->cache );
		if ( false !== $cached ) {
			return $cached ?: null;
		}
		$response = wp_remote_get( $this->base . '/v1/check/' . $this->slug, array( 'timeout' => 3, 'redirection' => 0 ) );
		$data = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! $this->valid( $data ) ) {
				$data = null;
			}
		}
		set_site_transient( $this->cache, $data ?: array(), $data ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
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
			&& $this->under_api( $data['package'] )
			&& $this->base . '/v1/download/' . $this->slug . '/' . $data['tag'] === $data['package']
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
		if ( ! $this->configured() ) {
			return new WP_Error( 'rendar_updates_unavailable', 'Private plugin information is unavailable.' );
		}
		$data = $this->release();
		if ( ! $data ) {
			return new WP_Error( 'rendar_updates_unavailable', 'Private plugin information is unavailable.' );
		}
		return (object) array(
			'name' => esc_html( $data['name'] ), 'slug' => $this->slug, 'version' => $data['version'],
			'download_link' => $data['package'], 'requires' => $data['requires'],
			'requires_php' => $data['requires_php'], 'tested' => $data['tested'],
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
