<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Present stored scan evidence as distinct, readable locations.
 */
final class PIXCENSUS_Usage {

	/**
	 * Explain stored evidence without suggesting certainty of safe deletion.
	 *
	 * @param string $label Scan provenance.
	 * @return string
	 */
	public static function evidence( string $label ): string {
		if ( false !== strpos( $label, '_thumbnail_id' ) ) {
			$type = __( 'Featured image', 'pixcensus-media-audit' );
		} elseif ( false !== strpos( $label, ':block:' ) || false !== strpos( $label, ':wp-image' ) ) {
			$type = __( 'Editor image identifier', 'pixcensus-media-audit' );
		} elseif ( false !== strpos( $label, 'builder' ) || false !== strpos( $label, ':id' ) ) {
			$type = __( 'Structured identifier; review its context', 'pixcensus-media-audit' );
		} elseif ( false !== strpos( $label, ':url' ) ) {
			$type = __( 'Image path or URL', 'pixcensus-media-audit' );
		} else {
			$type = __( 'Stored reference', 'pixcensus-media-audit' );
		}
		return $type . ' · ' . $label;
	}

	/**
	 * Resolve titles and links at display time, including older scan results.
	 *
	 * @param array $labels Stored evidence labels.
	 */
	public static function locations( array $labels ): array {
		$locations = array();
		foreach ( $labels as $label ) {
			$url = '';
			if ( preg_match( '/^post:(\d+)\b/', $label, $matches ) ) {
				$key = 'post:' . $matches[1];
				if ( isset( $locations[ $key ] ) ) {
					continue;
				}
				$post = get_post( (int) $matches[1] );
				$name = __( 'Publication unavailable', 'pixcensus-media-audit' );
				if ( $post && current_user_can( 'read_post', $post->ID ) ) {
					$name = get_the_title( $post );
					$name = '' !== $name ? $name : __( 'Untitled publication', 'pixcensus-media-audit' );
					$url  = is_post_publicly_viewable( $post ) ? get_permalink( $post ) : get_edit_post_link( $post->ID, 'raw' );
				}
			} elseif ( preg_match( '/^term:(\d+)\b/', $label, $matches ) ) {
				$key  = 'term:' . $matches[1];
				$term = get_term( (int) $matches[1] );
				$name = __( 'Taxonomy description', 'pixcensus-media-audit' );
				if ( $term instanceof WP_Term ) {
					$name = $term->name;
					$link = get_term_link( $term );
					$url  = is_wp_error( $link ) ? '' : $link;
				}
			} else {
				$names = array(
					'site_icon'   => __( 'Site icon', 'pixcensus-media-audit' ),
					'custom_logo' => __( 'Site logo', 'pixcensus-media-audit' ),
				);
				$key   = isset( $names[ $label ] ) ? $label : 'settings';
				$name  = $names[ $label ] ?? __( 'Site or extension settings', 'pixcensus-media-audit' );
			}
			$locations[ $key ] = array(
				'name' => $name,
				'url'  => $url,
			);
		}
		return array_values( $locations );
	}
}
