<?php
/**
 * The settings form, which is the whole plugin page.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Admin;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Prints and saves the settings form.
 *
 * The form draws its own fields: WC_Admin_Settings is the right tool on a shop
 * and does not exist anywhere else, and the plugin runs on plain WordPress too.
 * It knows five types: title, sectionend, checkbox, multiselect and textarea.
 * Adding one means adding it to render_field() and to sanitize_submission()
 * together; a type one knows and the other does not is either unsaveable or
 * unsafe.
 *
 * The layout is the card-and-row pattern of @wordpress/components - the one
 * Site Health and the newer WooCommerce screens use - drawn in PHP and styled
 * by src/admin.scss, so it looks current without a React app. A section is a
 * card, a field is a row with its title and one line of description on the
 * left and its control on the right. A checkbox is drawn as an on/off switch:
 * still a real checkbox, so the form posts exactly what it did, with
 * role="switch" for screen readers. A multiselect and a textarea need the
 * row's width, so their control goes below the text. The multiselect is a
 * native multiple select with WooCommerce's wc-enhanced-select class, which
 * WooCommerce's selectWoo, loaded by Assets, turns into a searchable box whose
 * choices stay as chips with an x. Without the stylesheet and the script
 * everything degrades to plain labels, checkboxes, a multiple select and a
 * text box that post the same.
 */
final class Settings_Page {

	/**
	 * Action on admin-post.php that receives the form.
	 */
	const string SAVE_ACTION = 'rcus_save_settings';

	/**
	 * Name of the hidden list of fields the form drew.
	 */
	const string DRAWN_FIELD = 'rcus_drawn';

	/**
	 * Group hook fired after every save of this screen.
	 *
	 * No plugin prefix, like the menu constants: every plugin of the group
	 * fires this same hook, so whichever plugin clears the page cache hears a
	 * save in any of them. With no listener it does nothing. Must hold the same
	 * value in every plugin of the group.
	 */
	const string SAVED_HOOK = 'rhinnoit_settings_saved';

	/**
	 * Group label printed last in the current card.
	 *
	 * @var string
	 */
	private string $group = '';

	/**
	 * Hooks the save handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
	}

	/**
	 * Prints the form.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->render_saved_notice();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rcus-settings">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>"/>
			<?php
			wp_nonce_field( self::SAVE_ACTION );

			$this->render_fields( Settings::available_fields() );

			// Without the empty name the button is called "submit", which
			// shadows form.submit() for any script touching this form.
			submit_button( '', 'primary', '' );
			?>
		</form>
		<?php
	}

	/**
	 * Prints the rows of these fields, in order.
	 *
	 * Public so the cases can hand it fields no plugin ships, as they hand
	 * Settings::fields_for_marks() theirs.
	 *
	 * @param array<int, array<string, mixed>> $fields Field definitions, in order.
	 * @return void
	 */
	public function render_fields( array $fields ): void {
		foreach ( $fields as $field ) {
			$this->render_field( $field );
		}
	}

	/**
	 * Prints one row of the field list.
	 *
	 * A title opens a card and its matching sectionend closes it; a field
	 * whose group differs from the one before it gets the group label first.
	 * Every value printed here is escaped: titles and descriptions come from
	 * the plugin, but the current value of an option comes from the database,
	 * where anything can have been written, and so may a callback's choices.
	 *
	 * @param array<string, mixed> $field One field definition.
	 * @return void
	 */
	private function render_field( array $field ): void {
		$type  = is_string( $field['type'] ?? null ) ? $field['type'] : '';
		$id    = is_string( $field['id'] ?? null ) ? $field['id'] : '';
		$title = is_string( $field['title'] ?? null ) ? $field['title'] : '';
		$desc  = is_string( $field['desc'] ?? null ) ? $field['desc'] : '';
		$group = is_string( $field['group'] ?? null ) ? $field['group'] : '';

		if ( 'title' === $type ) {
			$this->group = '';

			echo '<section class="rcus-card">';

			// An untitled card is the master switch, above every other card.
			if ( '' !== $title ) {
				printf( '<h2 class="rcus-card__title">%s</h2>', esc_html( $title ) );
			}

			if ( '' !== $desc ) {
				printf( '<p class="rcus-card__desc">%s</p>', esc_html( $desc ) );
			}

			return;
		}

		if ( 'sectionend' === $type ) {
			echo '</section>';

			return;
		}

		if ( '' === $id || ! in_array( $type, array( 'checkbox', 'multiselect', 'textarea' ), true ) ) {
			return;
		}

		if ( '' !== $group && $group !== $this->group ) {
			printf( '<h3 class="rcus-card__group">%s</h3>', esc_html( $group ) );
		}

		$this->group = $group;

		// A switch sits beside its text; a list or a text box needs the row's
		// width, so it goes below.
		printf(
			'<div class="%4$s"><div class="rcus-row__text"><label class="rcus-row__label" for="%1$s">%2$s</label><p class="rcus-row__desc" id="%1$s-desc">%3$s</p></div><div class="rcus-row__control">',
			esc_attr( $id ),
			esc_html( $title ),
			esc_html( $desc ),
			'checkbox' === $type ? 'rcus-row' : 'rcus-row rcus-row--wide'
		);

		if ( 'checkbox' === $type ) {
			$this->render_checkbox( $field, $id );
		} elseif ( 'multiselect' === $type ) {
			$this->render_multiselect( $field, $id );
		} else {
			$this->render_textarea( $field, $id );
		}

		printf(
			'<input type="hidden" name="%1$s[]" value="%2$s"/></div></div>',
			esc_attr( self::DRAWN_FIELD ),
			esc_attr( $id )
		);
	}

	/**
	 * Prints a checkbox, drawn as an on/off switch.
	 *
	 * @param array<string, mixed> $field One field definition.
	 * @param string               $id    Option id.
	 * @return void
	 */
	private function render_checkbox( array $field, string $id ): void {
		$value = get_option( $id, $field['default'] ?? '' );

		printf(
			'<input type="checkbox" role="switch" class="rcus-switch" id="%1$s" name="%1$s" value="yes" aria-describedby="%1$s-desc"%2$s/>',
			esc_attr( $id ),
			'yes' === $value ? ' checked="checked"' : ''
		);
	}

	/**
	 * Prints a multiselect: a native multiple select that selectWoo enhances.
	 *
	 * The wc-enhanced-select class is what WooCommerce's own script looks for,
	 * and data-placeholder what it shows in the empty box. A group of choices
	 * is an optgroup, labelled by the field's 'group_labels' callback or else
	 * by its key. What is selected is what Settings::chosen() would read from
	 * the row, so the screen shows what the plugin acts on; the stored value
	 * itself is only compared, never printed, so markup written into the row
	 * cannot reach the page.
	 *
	 * @param array<string, mixed> $field One field definition.
	 * @param string               $id    Option id.
	 * @return void
	 */
	private function render_multiselect( array $field, string $id ): void {
		$stored = Settings::chosen_from( $field, get_option( $id, null ) );
		$labels = self::group_labels( $field );

		printf(
			'<select multiple="multiple" class="wc-enhanced-select rcus-select" id="%1$s" name="%1$s[]" data-placeholder="%2$s" aria-describedby="%1$s-desc">',
			esc_attr( $id ),
			esc_attr( is_string( $field['placeholder'] ?? null ) ? $field['placeholder'] : '' )
		);

		foreach ( self::choices( $field ) as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				self::render_option( (string) $key, $entry, $stored );

				continue;
			}

			printf( '<optgroup label="%s">', esc_attr( is_string( $labels[ $key ] ?? null ) ? $labels[ $key ] : (string) $key ) );

			foreach ( $entry as $choice => $label ) {
				self::render_option( (string) $choice, $label, $stored );
			}

			echo '</optgroup>';
		}

		echo '</select>';
	}

	/**
	 * Prints one choice of a multiselect.
	 *
	 * @param string             $choice Choice id.
	 * @param mixed              $label  Its label; the id stands in for one that is not a string.
	 * @param array<int, string> $stored The ids selected.
	 * @return void
	 */
	private static function render_option( string $choice, mixed $label, array $stored ): void {
		printf(
			'<option value="%1$s"%3$s>%2$s</option>',
			esc_attr( $choice ),
			esc_html( is_string( $label ) ? $label : $choice ),
			in_array( $choice, $stored, true ) ? ' selected="selected"' : ''
		);
	}

	/**
	 * Prints a textarea holding what Settings::text() would read from the row, as the shop shows it.
	 *
	 * Core's sanitize_textarea_field() stores a lone '<', and the rest of the
	 * text after it, as entities (wp_pre_kses_less_than()). The shop prints
	 * the row through esc_html(), which leaves an entity as it is, so the
	 * shopper reads what was typed; esc_textarea() encodes it once more, so
	 * the box would show the entities, and keep them on every save. The row
	 * is decoded first: the box shows what the shop shows, and saving it
	 * again stores the same row. It is the one stored value this screen
	 * prints, so it still goes through esc_textarea(): markup written into
	 * the row shows as text in the box, and cannot close it.
	 *
	 * @param array<string, mixed> $field One field definition.
	 * @param string               $id    Option id.
	 * @return void
	 */
	private function render_textarea( array $field, string $id ): void {
		printf(
			'<textarea rows="3" class="large-text" id="%1$s" name="%1$s" aria-describedby="%1$s-desc">%2$s</textarea>',
			esc_attr( $id ),
			esc_textarea( wp_specialchars_decode( Settings::text_from( $field, get_option( $id, null ) ), ENT_QUOTES ) )
		);
	}

	/**
	 * A multiselect's choices as the form offers them: id => label, or a group key => (id => label).
	 *
	 * A field whose choices live in the database names an 'options_callback',
	 * which is called here and only here, when the screen is drawn or saved:
	 * a storefront request reads the row through Settings::chosen(), which
	 * never calls it. Any other field offers its static 'options'.
	 *
	 * @param array<string, mixed> $field One field definition.
	 * @return array<mixed>
	 */
	private static function choices( array $field ): array {
		if ( isset( $field['options_callback'] ) ) {
			$callback = $field['options_callback'];
			$choices  = is_callable( $callback ) ? $callback() : null;
		} else {
			$choices = $field['options'] ?? null;
		}

		return is_array( $choices ) ? $choices : array();
	}

	/**
	 * Group key => the label its optgroup shows, from the field's 'group_labels' callback.
	 *
	 * Called only when the screen is drawn, for names that come from
	 * elsewhere, such as WooCommerce's names of a country's regions.
	 *
	 * @param array<string, mixed> $field One field definition.
	 * @return array<mixed>
	 */
	private static function group_labels( array $field ): array {
		$callback = $field['group_labels'] ?? null;
		$labels   = is_callable( $callback ) ? $callback() : null;

		return is_array( $labels ) ? $labels : array();
	}

	/**
	 * Validates and saves the form.
	 *
	 * @return void
	 */
	public function save(): void {
		if ( ! current_user_can( Menu::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'rhinnoit-customizer' ) );
		}

		check_admin_referer( self::SAVE_ACTION );

		/*
		 * Unslashed as a whole and sanitized field by field below: the only
		 * keys ever read are the ids the field list declares, each through the
		 * sanitizer of its own type, so nothing else in $_POST is looked at.
		 */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per declared field in sanitize_submission().
		$posted = wp_unslash( $_POST );

		$posted = is_array( $posted ) ? $posted : array();

		if ( self::drawn_by_an_older_version( $posted ) ) {
			wp_safe_redirect( add_query_arg( 'outdated', '1', Menu::url() ) );
			exit;
		}

		$fields = self::fields_to_save( Settings::available_fields(), $posted );

		foreach ( self::sanitize_submission( $fields, $posted ) as $option => $value ) {
			update_option( $option, $value );
		}

		/**
		 * Fires after the settings screen is saved, whether or not anything
		 * changed. A switch can change what every page prints, so the plugin
		 * of the group that clears the page cache clears on it.
		 *
		 * @since 1.0.0
		 */
		do_action( self::SAVED_HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- A group hook, unprefixed on purpose: see SAVED_HOOK.

		wp_safe_redirect( add_query_arg( 'updated', '1', Menu::url() ) );
		exit;
	}

	/**
	 * Whether the form was drawn by a version whose switches were different.
	 *
	 * A settings tab left open across an update posts the old field list. If a
	 * switch it drew no longer exists, saving the rest and saying "Settings
	 * saved" would quietly drop the one change the admin came to make. A form
	 * that names a retired switch is refused whole, and the screen asks for a
	 * reload.
	 *
	 * So is a form that names nothing. Every form this version draws lists the
	 * master switch at least, so an empty or missing list comes from a version
	 * that drew no list at all - WC_Admin_Settings drew the fields before - or
	 * from a hand-built post. fields_to_save() would save none of it, and the
	 * redirect would still say "Settings saved".
	 *
	 * @param array<mixed> $posted The unslashed submission.
	 * @return bool
	 */
	public static function drawn_by_an_older_version( array $posted ): bool {
		$drawn = $posted[ self::DRAWN_FIELD ] ?? null;
		$drawn = is_array( $drawn ) ? array_filter( $drawn, 'is_string' ) : array();

		if ( array() === $drawn ) {
			return true;
		}

		return array() !== array_intersect( $drawn, Settings::retired_option_ids() );
	}

	/**
	 * The fields of this site that were actually on the submitted form.
	 *
	 * A checkbox that is absent from a submission was unchecked - but only if
	 * it was on the screen to begin with. The field list is rebuilt when the
	 * form is saved, not when it was drawn, so a section that appeared in
	 * between (WooCommerce activated, or updated past the minimum, in another
	 * tab) would have every one of its boxes stored as unchecked. The form says
	 * which fields it drew, and only those are saved.
	 *
	 * The marker cannot widen anything: it is intersected with the fields this
	 * site can use, so a crafted post naming another id still gets nowhere.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields this site can use.
	 * @param array<mixed>                     $posted The unslashed submission.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields_to_save( array $fields, array $posted ): array {
		$drawn = $posted[ self::DRAWN_FIELD ] ?? array();

		if ( ! is_array( $drawn ) ) {
			return array();
		}

		$drawn = array_filter( $drawn, 'is_string' );

		return array_values(
			array_filter(
				$fields,
				static function ( array $field ) use ( $drawn ): bool {
					return in_array( $field['id'] ?? null, $drawn, true );
				}
			)
		);
	}

	/**
	 * Turns a submitted form into the values to store.
	 *
	 * Driven by the field list, never by the submission: a key nobody declared
	 * is never read, so a crafted post cannot create an option. Structural rows
	 * are skipped by the same 'is_option' flag the field list sets. A checkbox
	 * that is absent was unchecked, because browsers send nothing for an
	 * unchecked box; a multiselect with nothing selected is likewise absent,
	 * and stores the empty list. A multiselect keeps only the ids its field
	 * offers now - its callback's, for one whose choices live in the database -
	 * and a textarea is stored as plain text, line breaks kept.
	 *
	 * @param array<int, array<string, mixed>> $fields Field definitions.
	 * @param array<mixed>                     $posted The unslashed submission.
	 * @return array<string, string|array<int, string>> Option id => value to store.
	 */
	public static function sanitize_submission( array $fields, array $posted ): array {
		$values = array();

		foreach ( $fields as $field ) {
			$id   = is_string( $field['id'] ?? null ) ? $field['id'] : '';
			$type = is_string( $field['type'] ?? null ) ? $field['type'] : '';

			if ( '' === $id || false === ( $field['is_option'] ?? true ) ) {
				continue;
			}

			$raw = $posted[ $id ] ?? null;

			if ( 'checkbox' === $type ) {
				$values[ $id ] = is_string( $raw ) && 'yes' === $raw ? 'yes' : 'no';
			}

			if ( 'multiselect' === $type ) {
				// Only the ids this field offers, each once: anything else in the
				// list was not a choice on this form.
				$values[ $id ] = is_array( $raw )
					? array_values( array_unique( array_intersect( array_filter( $raw, 'is_string' ), Settings::choice_ids( self::choices( $field ) ) ) ) )
					: array();
			}

			if ( 'textarea' === $type ) {
				$values[ $id ] = is_string( $raw ) ? sanitize_textarea_field( $raw ) : '';
			}
		}

		return $values;
	}

	/**
	 * Notice shown after the save redirect.
	 *
	 * @return void
	 */
	private function render_saved_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which notice to show.
		if ( isset( $_GET['outdated'] ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'The plugin was updated while this screen was open, so nothing was saved. Check the settings below and save again.', 'rhinnoit-customizer' )
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which notice to show.
		if ( ! isset( $_GET['updated'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Settings saved.', 'rhinnoit-customizer' )
		);
	}
}
