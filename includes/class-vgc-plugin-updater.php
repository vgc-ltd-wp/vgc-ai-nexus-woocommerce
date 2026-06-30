<?php
/**
 * Shared self-hosted plugin updater for VGC plugins.
 *
 * Global namespace + class_exists guard so multiple VGC plugins can each bundle a
 * copy without a fatal "cannot redeclare" — the first one to load defines it and
 * the rest reuse it. Reads a PUBLIC JSON manifest so private source repos stay
 * private and no token is needed on the site.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'VGC_Plugin_Updater', false ) ) :

class VGC_Plugin_Updater {

    private $file;
    private $slug;
    private $basename;
    private $version;
    private $manifest_url;
    private $cache_key;
    private $entry = null;

    public function __construct( $file, $slug, $version, $manifest_url ) {
        $this->file         = $file;
        $this->slug         = $slug;
        $this->basename     = plugin_basename( $file );
        $this->version      = $version;
        $this->manifest_url = $manifest_url;
        $this->cache_key    = 'vgc_upd_' . md5( $this->basename );

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
        add_filter( 'site_transient_update_plugins', array( $this, 'inject_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
    }

    private function entry() {
        if ( is_array( $this->entry ) ) {
            return $this->entry;
        }
        if ( ! empty( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            delete_transient( $this->cache_key );
        } else {
            $cached = get_transient( $this->cache_key );
            if ( is_array( $cached ) ) {
                return $this->entry = $cached;
            }
        }
        $res = wp_remote_get( $this->manifest_url, array(
            'timeout' => 15,
            'headers' => array( 'Accept' => 'application/json' ),
        ) );
        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
            set_transient( $this->cache_key, array(), 15 * MINUTE_IN_SECONDS );
            return $this->entry = array();
        }
        $data  = json_decode( wp_remote_retrieve_body( $res ), true );
        $entry = ( is_array( $data ) && isset( $data[ $this->slug ] ) && is_array( $data[ $this->slug ] ) )
            ? $data[ $this->slug ]
            : array();
        set_transient( $this->cache_key, $entry, HOUR_IN_SECONDS );
        return $this->entry = $entry;
    }

    public function inject_update( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) || empty( $transient->checked ) ) {
            return $transient;
        }
        $info = $this->entry();
        if ( empty( $info['version'] ) || empty( $info['download_url'] ) ) {
            return $transient;
        }
        $item = (object) array(
            'slug'         => $this->slug,
            'plugin'       => $this->basename,
            'new_version'  => (string) $info['version'],
            'package'      => esc_url_raw( $info['download_url'] ),
            'url'          => isset( $info['homepage'] ) ? $info['homepage'] : '',
            'tested'       => isset( $info['tested'] ) ? $info['tested'] : '',
            'requires'     => isset( $info['requires'] ) ? $info['requires'] : '',
            'requires_php' => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
            'icons'        => array(),
            'banners'      => array(),
        );
        if ( version_compare( (string) $info['version'], $this->version, '>' ) ) {
            $transient->response[ $this->basename ] = $item;
            unset( $transient->no_update[ $this->basename ] );
        } else {
            $transient->no_update[ $this->basename ] = $item;
            unset( $transient->response[ $this->basename ] );
        }
        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }
        $info = $this->entry();
        if ( empty( $info ) ) {
            return $result;
        }
        $sections = (array) ( isset( $info['sections'] ) ? $info['sections'] : array( 'description' => '' ) );
        $sections = (array) apply_filters( 'vgc_plugin_updater_sections', $sections, $this->slug, $info );
        return (object) array(
            'name'          => isset( $info['name'] ) ? $info['name'] : $this->slug,
            'slug'          => $this->slug,
            'version'       => isset( $info['version'] ) ? $info['version'] : $this->version,
            'author'        => isset( $info['author'] ) ? $info['author'] : 'VGC',
            'homepage'      => isset( $info['homepage'] ) ? $info['homepage'] : '',
            'requires'      => isset( $info['requires'] ) ? $info['requires'] : '',
            'requires_php'  => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
            'tested'        => isset( $info['tested'] ) ? $info['tested'] : '',
            'last_updated'  => isset( $info['last_updated'] ) ? $info['last_updated'] : '',
            'sections'      => $sections,
            'download_link' => esc_url_raw( isset( $info['download_url'] ) ? $info['download_url'] : '' ),
        );
    }

    public function fix_source_dir( $source, $remote_source, $upgrader, $args = array() ) {
        global $wp_filesystem;
        if ( empty( $args['plugin'] ) || $args['plugin'] !== $this->basename ) {
            return $source;
        }
        $desired = trailingslashit( $remote_source ) . $this->slug;
        if ( untrailingslashit( $source ) === $desired ) {
            return $source;
        }
        if ( $wp_filesystem && $wp_filesystem->move( $source, $desired ) ) {
            return trailingslashit( $desired );
        }
        return $source;
    }

    public function flush_cache() {
        delete_transient( $this->cache_key );
    }
}

endif;
