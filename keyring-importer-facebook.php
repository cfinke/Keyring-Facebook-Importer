<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Facebook_Importer() {

class Keyring_Facebook_Importer extends Keyring_Importer_Base {
	const SLUG              = 'facebook';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Facebook';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Facebook';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?

	var $auto_import = false;

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['category'] ) || !ctype_digit( $_POST['category'] ) )
			$this->error( __( "Make sure you select a valid category to import your statuses into." ) );

		if ( empty( $_POST['author'] ) || !ctype_digit( $_POST['author'] ) )
			$this->error( __( "You must select an author to assign to all statuses." ) );

		if ( isset( $_POST['auto_import'] ) )
			$_POST['auto_import'] = true;
		else
			$_POST['auto_import'] = false;

		// If there were errors, output them, otherwise store options and start importing
		if ( count( $this->errors ) ) {
			$this->step = 'options';
		} else {
			$this->set_option( array(
				'category'    => (int) $_POST['category'],
				'tags'        => explode( ',', $_POST['tags'] ),
				'author'      => (int) $_POST['author'],
				'auto_import' => $_POST['auto_import'],
			) );

			$this->step = 'import';
		}
	}

	function build_request_url() {
		// Base request URL
		$url = "https://graph.facebook.com/me/posts";

		if ( $this->auto_import ) {
			// Get most recent checkin we've imported (if any), and its date so that we can get new ones since then
			$latest = get_posts( array(
				'numberposts' => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'tax_query'   => array( array(
					'taxonomy' => 'keyring_services',
					'field'    => 'slug',
					'terms'    => array( $this->taxonomy->slug ),
					'operator' => 'IN',
				) ),
			) );

			// If we have already imported some, then start since the most recent
			if ( $latest ) {
				$url = add_query_arg( 'since', strtotime( $latest[0]->post_date_gmt ) + 1, $url );
			}
		} else {
			// Handle page offsets (only for non-auto-import requests)
	//		$url = $this->get_option( 'paging', $url );
		}

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		global $wpdb;
print_r($raw);
die;
		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-facebook-importer-failed-download', __( 'Failed to download your statuses from Facebook. Please wait a few minutes and try again.' ) );
		}

		// Make sure we have some checkins to parse
		if ( !is_object( $importdata ) || !count( $importdata->data ) ) {
			$this->finished = true;
			$this->set_option( 'paging', null );
			return;
		}

		if ( isset( $importdata->paging ) && isset( $importdata->paging->next ) )
			$this->set_option( 'paging', $importdata->paging->next );
		else
			$this->set_option( 'paging', null );

		// Parse/convert everything to WP post structs
		foreach ( $importdata->data as $post ) {
			$post_title = '';

			if ( isset( $post->story ) )
				$post_title = $post->story;

			// Construct a post body
			$post_content = '';

			if ( ! empty( $post->message ) )
				$post_content = $post->message;

			// Parse/adjust dates
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $post->created_time ) );
			$post_date = get_date_from_gmt( $post_date_gmt );

			$post_type = 'status';

			if ( isset( $post->type ) ) {
				if ( 'link' == $post->type ) {
					if ( ! empty( $post->name ) ) {
						$post_content .= '<p><a href="' . esc_url( $post->link ) . '">' . esc_html( $post->name ) . '</a></p>';
					}

					if ( ! empty( $post->description ) ) {
						$post_content .= '<blockquote>' . esc_html( $post->description ) . '</blockquote>';
					}
				}
			}

			$tags = $this->get_option( 'tags' );

			preg_match_all( '/#([a-zA-Z0-9_\-]+)/', $post->story . ' ' . $post->message, $tag_matches );
			$tags = array_merge( $tags, $tag_matches[1] );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			$videos = array();

			if ( 'video' == $post->type && ! empty( $post->source ) ) {
				$videos[] = $post->source;
			}
			else {
				$photos = array();

				if ( isset( $post->picture ) ) {
					// The API returns the tiniest thumbnail. Unacceptable.
					$photos[] = preg_replace( '/_s\./', '_n.',  $post->picture );
				}
			}

			// @todo Multi-photo posts don't appear to include all photos.

			// @todo Import Likes?
			// $post->likes->data[]->name

			// @todo Import comments?
			// $post->comments->data[]->from->name and $post->comments->data[]->message and $post->comments->data[]->created_time

			// Other bits
			$post_author    = $this->get_option( 'author' );

			if ( isset( $post->privacy ) && isset( $post->privacy->value ) && ! empty( $post->privacy->value ) )
				$post_status = 'private';
			else
				$post_status = 'publish';

			$facebook_id    = $post->id;
			$facebook_raw   = $post;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_status',
				'post_category',
				'facebook_id',
				'tags',
				'facebook_raw',
				'photos',
				'videos'
			);
		}
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			// See the end of extract_posts_from_data() for what is in here
			extract( $post );

			if (
				!$facebook_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'facebook_id' AND meta_value = %s", $facebook_id ) )
			||
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate
				$skipped++;
			} else {
				$post_id = wp_insert_post( $post );

				if ( is_wp_error( $post_id ) )
					return $post_id;

				if ( !$post_id )
					continue;

				$post['ID'] = $post_id;

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark it as an aside
				set_post_format( $post_id, 'status' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'facebook_id', $facebook_id );

				if ( count( $tags ) )
					wp_set_post_terms( $post_id, implode( ',', $tags ) );

				// Store geodata if it's available
				if ( !empty( $geo ) ) {
					add_post_meta( $post_id, 'geo_latitude', $geo['lat'] );
					add_post_meta( $post_id, 'geo_longitude', $geo['long'] );
					add_post_meta( $post_id, 'geo_public', 1 );
				}

				add_post_meta( $post_id, 'raw_import_data', json_encode( $facebook_raw ) );

				if ( ! empty( $photos ) ) {
					foreach ( $photos as $photo ) {
						$this->sideload_media( $photo, $post_id, $post, apply_filters( 'keyring_facebook_importer_image_embed_size', 'full' ) );
					}
				}

				if ( ! empty( $videos ) ) {
					foreach ( $videos as $video ) {
						$this->sideload_media( $video, $post_id, $post, 'full' );
					}
				}

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_Facebook_Importer


add_action( 'init', function() {
	Keyring_Facebook_Importer(); // Load the class code from above
	keyring_register_importer(
		'facebook',
		'Keyring_Facebook_Importer',
		plugin_basename( __FILE__ ),
		__( 'Download all of your Facebook statuses as individual Posts (with a "status" post format).', 'keyring' )
	);
} );
