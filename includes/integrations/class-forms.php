<?php
/**
 * Form tools for Contact Form 7 (with Flamingo for entries), WPForms and
 * Gravity Forms: list forms with embed codes, read their fields, and read
 * submitted entries. Entries contain personal data, so they need Full control.
 *
 * @package Viagent
 */

namespace Viagent\Integrations;

use Viagent\Abilities\Abilities;
use Viagent\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Forms integration.
 */
class Forms {

	const CATEGORY = 'viagent-forms';

	/**
	 * Active form plugins, keyed by provider slug.
	 *
	 * @return array<string,string> Slug => name.
	 */
	public static function providers() {
		$providers = array();
		if ( class_exists( 'WPCF7_ContactForm' ) ) {
			$providers['cf7'] = 'Contact Form 7';
		}
		if ( function_exists( 'wpforms' ) ) {
			$providers['wpforms'] = 'WPForms';
		}
		if ( class_exists( 'GFAPI' ) ) {
			$providers['gravityforms'] = 'Gravity Forms';
		}
		return $providers;
	}

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'viagent_tool_summaries', array( self::class, 'summaries' ) );
		add_filter( 'viagent_instructions', array( self::class, 'instructions' ) );
	}

	/**
	 * Registers the ability category.
	 */
	public static function register_categories() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Forms', 'viagent' ),
				/* translators: %s: form plugin names */
				'description' => sprintf( __( 'Forms and entries from %s.', 'viagent' ), implode( ', ', self::providers() ) ),
			)
		);
	}

	/**
	 * Tells AI apps about the form tools.
	 *
	 * @param string[] $lines Instruction lines.
	 * @return string[]
	 */
	public static function instructions( $lines ) {
		$lines[] = sprintf( 'Forms (%s): use list_forms to find forms and their embed code (put it in a page with a shortcode block), get_form for fields, and list_form_entries to read submissions.', implode( ', ', self::providers() ) );
		return $lines;
	}

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$provider = array(
			'type'        => 'string',
			'enum'        => array_keys( self::providers() ),
			'description' => __( 'Form plugin, as returned by list_forms.', 'viagent' ),
		);
		$id       = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		Abilities::add(
			'list-forms',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'List forms', 'viagent' ),
				'description' => __( 'Lists all forms from the active form plugins with their field count, number of stored entries and the shortcode to embed them in a page.', 'viagent' ),
				'execute'     => array( self::class, 'list_forms' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'get-form',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Get form', 'viagent' ),
				'description' => __( 'Gets a form\'s fields (label, type, required, choices) and embed code.', 'viagent' ),
				'input'       => array(
					'provider' => $provider,
					'form_id'  => $id,
				),
				'required'    => array( 'provider', 'form_id' ),
				'execute'     => array( self::class, 'get_form' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'list-form-entries',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'List form entries', 'viagent' ),
				'description' => __( 'Lists submissions of a form, newest first, with every field value. Contact Form 7 needs the Flamingo plugin to store entries; WPForms Lite does not store entries.', 'viagent' ),
				'input'       => array_merge(
					array(
						'provider' => $provider,
						'form_id'  => $id,
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Only entries containing this text.', 'viagent' ),
						),
					),
					Abilities::paging()
				),
				'required'    => array( 'provider', 'form_id' ),
				'execute'     => array( self::class, 'list_entries' ),
				'permission'  => 'manage_options',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);
	}

	/**
	 * Lists forms of every provider.
	 *
	 * @return array
	 */
	public static function list_forms() {
		$forms = array();
		foreach ( array_keys( self::providers() ) as $provider ) {
			foreach ( call_user_func( array( self::class, $provider . '_forms' ) ) as $form ) {
				$forms[] = array_merge( array( 'provider' => $provider ), $form );
			}
		}
		return $forms;
	}

	/**
	 * Gets one form.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_form( $input ) {
		$form = call_user_func( array( self::class, $input['provider'] . '_form' ), (int) $input['form_id'] );
		return is_wp_error( $form ) ? $form : array_merge( array( 'provider' => $input['provider'] ), $form );
	}

	/**
	 * Lists entries of a form.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function list_entries( $input ) {
		$per_page = (int) ( $input['per_page'] ?? 20 );
		return call_user_func(
			array( self::class, $input['provider'] . '_entries' ),
			(int) $input['form_id'],
			(string) ( $input['search'] ?? '' ),
			$per_page,
			( (int) ( $input['page'] ?? 1 ) - 1 ) * $per_page
		);
	}

	/**
	 * Keeps entries containing a search string (used where the plugin has no search).
	 *
	 * @param array  $entries Entries.
	 * @param string $search  Search.
	 * @return array
	 */
	private static function filter_entries( array $entries, $search ) {
		if ( '' === $search ) {
			return $entries;
		}
		return array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $search ) {
					return false !== stripos( wp_json_encode( $entry['fields'] ), $search );
				}
			)
		);
	}

	// Contact Form 7 (+ Flamingo).

	/**
	 * CF7 forms.
	 *
	 * @return array[]
	 */
	private static function cf7_forms() {
		$forms = array();
		foreach ( \WPCF7_ContactForm::find( array( 'posts_per_page' => -1 ) ) as $form ) {
			$forms[] = array(
				'id'      => $form->id(),
				'title'   => $form->title(),
				'fields'  => count( self::cf7_fields( $form ) ),
				'entries' => self::cf7_entry_count( $form->id() ),
				'embed'   => $form->shortcode(),
			);
		}
		return $forms;
	}

	/**
	 * CF7 input fields (submit buttons and quiz answers excluded).
	 *
	 * @param \WPCF7_ContactForm $form Form.
	 * @return array[]
	 */
	private static function cf7_fields( $form ) {
		$fields = array();
		foreach ( $form->scan_form_tags() as $tag ) {
			if ( '' === $tag->name || in_array( $tag->basetype, array( 'submit', 'quiz' ), true ) ) {
				continue;
			}
			$field = array(
				'name'     => $tag->name,
				'type'     => $tag->basetype,
				'required' => $tag->is_required(),
			);
			if ( ! empty( $tag->values ) && in_array( $tag->basetype, array( 'select', 'checkbox', 'radio' ), true ) ) {
				$field['choices'] = $tag->values;
			}
			$fields[] = $field;
		}
		return $fields;
	}

	/**
	 * One CF7 form.
	 *
	 * @param int $id Form ID.
	 * @return array|WP_Error
	 */
	private static function cf7_form( $id ) {
		$form = \WPCF7_ContactForm::get_instance( $id );
		if ( ! $form ) {
			return new WP_Error( 'viagent_not_found', __( 'No Contact Form 7 form found with that ID.', 'viagent' ) );
		}
		return array(
			'id'     => $form->id(),
			'title'  => $form->title(),
			'fields' => self::cf7_fields( $form ),
			'embed'  => $form->shortcode(),
			'email'  => $form->prop( 'mail' )['recipient'] ?? '',
		);
	}

	/**
	 * Flamingo channel that stores a CF7 form's entries.
	 *
	 * @param int $form_id Form ID.
	 * @return int
	 */
	private static function cf7_channel( $form_id ) {
		$meta = get_post_meta( $form_id, '_flamingo', true );
		return (int) ( $meta['channel'] ?? 0 );
	}

	/**
	 * Number of stored CF7 entries, or null without Flamingo.
	 *
	 * @param int $form_id Form ID.
	 * @return int|null
	 */
	private static function cf7_entry_count( $form_id ) {
		if ( ! class_exists( 'Flamingo_Inbound_Message' ) ) {
			return null;
		}
		$channel = self::cf7_channel( $form_id );
		return $channel ? (int) \Flamingo_Inbound_Message::count( array( 'channel_id' => $channel ) ) : 0;
	}

	/**
	 * CF7 entries from Flamingo.
	 *
	 * @param int    $form_id  Form ID.
	 * @param string $search   Search.
	 * @param int    $per_page Per page.
	 * @param int    $offset   Offset.
	 * @return array|WP_Error
	 */
	private static function cf7_entries( $form_id, $search, $per_page, $offset ) {
		if ( ! \WPCF7_ContactForm::get_instance( $form_id ) ) {
			return new WP_Error( 'viagent_not_found', __( 'No Contact Form 7 form found with that ID.', 'viagent' ) );
		}
		if ( ! class_exists( 'Flamingo_Inbound_Message' ) ) {
			return new WP_Error( 'viagent_no_entries', __( 'Contact Form 7 does not store submissions by itself. Install the free Flamingo plugin to keep them.', 'viagent' ) );
		}
		$channel = self::cf7_channel( $form_id );
		if ( ! $channel ) {
			return array(
				'total' => 0,
				'items' => array(),
			);
		}

		$args = array(
			'channel_id'     => $channel,
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$items = array();
		foreach ( \Flamingo_Inbound_Message::find( $args ) as $message ) {
			$items[] = array(
				'id'      => $message->id(),
				'date'    => get_post_field( 'post_date', $message->id() ),
				'from'    => $message->from,
				'subject' => $message->subject,
				'spam'    => (bool) $message->spam,
				'fields'  => (array) $message->fields,
			);
		}
		$count_args = array( 'channel_id' => $channel );
		if ( '' !== $search ) {
			$count_args['s'] = $search;
		}
		return array(
			'total' => (int) \Flamingo_Inbound_Message::count( $count_args ),
			'items' => $items,
		);
	}

	// WPForms.

	/**
	 * Decoded WPForms form data.
	 *
	 * @param \WP_Post $post Form post.
	 * @return array
	 */
	private static function wpforms_data( $post ) {
		$data = function_exists( 'wpforms_decode' ) ? wpforms_decode( $post->post_content ) : json_decode( $post->post_content, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * WPForms fields.
	 *
	 * @param array $data Form data.
	 * @return array[]
	 */
	private static function wpforms_fields( array $data ) {
		$fields = array();
		foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
			$item = array(
				'id'       => (int) $field['id'],
				'label'    => $field['label'] ?? '',
				'type'     => $field['type'] ?? '',
				'required' => ! empty( $field['required'] ),
			);
			if ( ! empty( $field['choices'] ) ) {
				$item['choices'] = array_values( wp_list_pluck( $field['choices'], 'label' ) );
			}
			$fields[] = $item;
		}
		return $fields;
	}

	/**
	 * WPForms entry handler (Pro only), or null.
	 *
	 * @return object|null
	 */
	private static function wpforms_entry_handler() {
		$handler = method_exists( wpforms(), 'get' ) ? wpforms()->get( 'entry' ) : null;
		return is_object( $handler ) && method_exists( $handler, 'get_entries' ) ? $handler : null;
	}

	/**
	 * WPForms forms.
	 *
	 * @return array[]
	 */
	private static function wpforms_forms() {
		$handler = self::wpforms_entry_handler();
		$posts   = get_posts(
			array(
				'post_type'      => 'wpforms',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$forms = array();
		foreach ( $posts as $post ) {
			$forms[] = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'fields'  => count( self::wpforms_fields( self::wpforms_data( $post ) ) ),
				'entries' => $handler ? (int) $handler->get_entries(
					array(
						'form_id' => $post->ID,
						'number'  => -1,
					),
					true
				) : null,
				'embed'   => sprintf( '[wpforms id="%d"]', $post->ID ),
			);
		}
		return $forms;
	}

	/**
	 * One WPForms form.
	 *
	 * @param int $id Form ID.
	 * @return array|WP_Error
	 */
	private static function wpforms_form( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'wpforms' !== $post->post_type ) {
			return new WP_Error( 'viagent_not_found', __( 'No WPForms form found with that ID.', 'viagent' ) );
		}
		return array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'fields' => self::wpforms_fields( self::wpforms_data( $post ) ),
			'embed'  => sprintf( '[wpforms id="%d"]', $post->ID ),
		);
	}

	/**
	 * WPForms entries (Pro).
	 *
	 * @param int    $form_id  Form ID.
	 * @param string $search   Search.
	 * @param int    $per_page Per page.
	 * @param int    $offset   Offset.
	 * @return array|WP_Error
	 */
	private static function wpforms_entries( $form_id, $search, $per_page, $offset ) {
		$handler = self::wpforms_entry_handler();
		if ( ! $handler ) {
			return new WP_Error( 'viagent_no_entries', __( 'WPForms Lite does not store form entries — they are only emailed. Entries are available with WPForms Pro.', 'viagent' ) );
		}

		$items = array();
		foreach ( (array) $handler->get_entries(
			array(
				'form_id' => $form_id,
				'number'  => $per_page,
				'offset'  => $offset,
			)
		) as $entry ) {
			$fields = array();
			foreach ( (array) json_decode( $entry->fields, true ) as $field ) {
				$fields[ $field['name'] ?? $field['id'] ] = $field['value'] ?? '';
			}
			$items[] = array(
				'id'     => (int) $entry->entry_id,
				'date'   => $entry->date,
				'status' => $entry->status,
				'fields' => $fields,
			);
		}
		return array(
			'total' => (int) $handler->get_entries( array( 'form_id' => $form_id ), true ),
			'items' => self::filter_entries( $items, $search ),
		);
	}

	// Gravity Forms.

	/**
	 * Gravity Forms fields.
	 *
	 * @param array $form Form.
	 * @return array[]
	 */
	private static function gravityforms_fields( array $form ) {
		$fields = array();
		foreach ( (array) $form['fields'] as $field ) {
			if ( in_array( $field->type, array( 'section', 'page', 'html', 'captcha' ), true ) ) {
				continue;
			}
			$item = array(
				'id'       => $field->id,
				'label'    => $field->label,
				'type'     => $field->type,
				'required' => (bool) $field->isRequired, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms API.
			);
			if ( ! empty( $field->choices ) ) {
				$item['choices'] = array_values( wp_list_pluck( $field->choices, 'text' ) );
			}
			$fields[] = $item;
		}
		return $fields;
	}

	/**
	 * Gravity Forms forms.
	 *
	 * @return array[]
	 */
	private static function gravityforms_forms() {
		$forms = array();
		foreach ( \GFAPI::get_forms() as $form ) {
			$forms[] = array(
				'id'      => (int) $form['id'],
				'title'   => $form['title'],
				'fields'  => count( self::gravityforms_fields( $form ) ),
				'entries' => (int) \GFAPI::count_entries( $form['id'] ),
				'embed'   => sprintf( '[gravityform id="%d" title="false"]', $form['id'] ),
			);
		}
		return $forms;
	}

	/**
	 * One Gravity Forms form.
	 *
	 * @param int $id Form ID.
	 * @return array|WP_Error
	 */
	private static function gravityforms_form( $id ) {
		$form = \GFAPI::get_form( $id );
		if ( ! $form ) {
			return new WP_Error( 'viagent_not_found', __( 'No Gravity Forms form found with that ID.', 'viagent' ) );
		}
		return array(
			'id'     => (int) $form['id'],
			'title'  => $form['title'],
			'fields' => self::gravityforms_fields( $form ),
			'embed'  => sprintf( '[gravityform id="%d" title="false"]', $form['id'] ),
		);
	}

	/**
	 * Gravity Forms entries.
	 *
	 * @param int    $form_id  Form ID.
	 * @param string $search   Search.
	 * @param int    $per_page Per page.
	 * @param int    $offset   Offset.
	 * @return array|WP_Error
	 */
	private static function gravityforms_entries( $form_id, $search, $per_page, $offset ) {
		$form = \GFAPI::get_form( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'viagent_not_found', __( 'No Gravity Forms form found with that ID.', 'viagent' ) );
		}

		$criteria = array( 'status' => 'active' );
		if ( '' !== $search ) {
			$criteria['field_filters'] = array(
				array(
					'key'      => 0,
					'operator' => 'contains',
					'value'    => $search,
				),
			);
		}
		$total   = 0;
		$entries = \GFAPI::get_entries(
			$form_id,
			$criteria,
			array(
				'key'       => 'date_created',
				'direction' => 'DESC',
			),
			array(
				'offset'    => $offset,
				'page_size' => $per_page,
			),
			$total
		);
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$items = array();
		foreach ( $entries as $entry ) {
			$fields = array();
			foreach ( (array) $form['fields'] as $field ) {
				$inputs = $field->get_entry_inputs();
				if ( is_array( $inputs ) ) {
					$parts = array();
					foreach ( $inputs as $input ) {
						$parts[] = rgar( $entry, (string) $input['id'] );
					}
					$value = trim( implode( ' ', array_filter( $parts ) ) );
				} else {
					$value = rgar( $entry, (string) $field->id );
				}
				if ( '' !== (string) $value ) {
					$fields[ $field->label ] = $value;
				}
			}
			$items[] = array(
				'id'     => (int) $entry['id'],
				'date'   => $entry['date_created'],
				'fields' => $fields,
			);
		}
		return array(
			'total' => (int) $total,
			'items' => $items,
		);
	}

	/**
	 * Tool summaries for the Tools screen.
	 *
	 * @param array $summaries Summaries.
	 * @return array
	 */
	public static function summaries( $summaries ) {
		return array_merge(
			$summaries,
			array(
				'list_forms'        => __( 'See your forms and how to add them to a page.', 'viagent' ),
				'get_form'          => __( 'See the fields of a form.', 'viagent' ),
				'list_form_entries' => __( 'Read what people submitted through your forms.', 'viagent' ),
			)
		);
	}
}
