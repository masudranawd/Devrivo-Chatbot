<?php
/**
 * Site scan for the setup wizard.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Admin;

use DevRivo\Chatbot\Core\Presets\PresetRepository;
use DevRivo\Chatbot\Knowledge\Sources\PostSource;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Looks at the site to suggest settings: name and tagline, relevant active
 * plugins, published content per post type, privacy policy, a preset with
 * the reasons for it, and contact details found on the front page and the
 * contact page (mailto: and tel: links first, then the visible text).
 *
 * Only published, public, non-password-protected content is read. Nothing is
 * saved: everything here is a suggestion for the admin to confirm.
 */
final class SiteScan {

	/**
	 * Most emails and phone numbers suggested.
	 */
	public const MAX_CONTACTS = 3;

	/**
	 * Published page titles used for the preset suggestion.
	 */
	private const MAX_TITLES = 100;

	/**
	 * Keywords per preset. Matched as whole words in the site title, tagline
	 * and published page titles (lowercase, English).
	 */
	private const KEYWORDS = array(
		'healthcare' => array( 'clinic', 'dental', 'dentist', 'dentists', 'dentistry', 'doctor', 'doctors', 'medical', 'health', 'healthcare', 'patient', 'patients', 'physio', 'physiotherapy', 'therapy', 'therapist', 'surgery', 'hospital', 'pharmacy', 'chiropractor', 'optician', 'wellness', 'veterinary', 'vet', 'nursing', 'care home' ),
		'portfolio'  => array( 'portfolio', 'photography', 'photographer', 'artist', 'illustration', 'illustrator', 'gallery', 'blog', 'writer', 'author', 'journal', 'my work', 'freelancer' ),
		'agency'     => array( 'agency', 'marketing', 'seo', 'digital', 'branding', 'web design', 'web development', 'advertising', 'creative', 'studio', 'consultancy', 'consulting' ),
		'business'   => array( 'services', 'company', 'solutions', 'contractor', 'construction', 'plumbing', 'cleaning', 'repairs', 'law firm', 'accountants', 'business' ),
	);

	/**
	 * Active plugins worth knowing about: basename => [ id, name, kind ].
	 */
	private const PLUGINS = array(
		'woocommerce/woocommerce.php'     => array( 'woocommerce', 'WooCommerce', 'shop' ),
		'elementor/elementor.php'         => array( 'elementor', 'Elementor', 'builder' ),
		'ameliabooking/ameliabooking.php' => array( 'amelia', 'Amelia', 'booking' ),
		'bookly-responsive-appointment-booking-tool/main.php' => array( 'bookly', 'Bookly', 'booking' ),
		'simply-schedule-appointments/simply-schedule-appointments.php' => array( 'simply-schedule-appointments', 'Simply Schedule Appointments', 'booking' ),
		'bookingpress-appointment-booking/bookingpress-appointment-booking.php' => array( 'bookingpress', 'BookingPress', 'booking' ),
		'latepoint/latepoint.php'         => array( 'latepoint', 'LatePoint', 'booking' ),
		'easy-appointments/main.php'      => array( 'easy-appointments', 'Easy Appointments', 'booking' ),
		'booking/wpdev-booking.php'       => array( 'booking-calendar', 'Booking Calendar', 'booking' ),
	);

	/**
	 * Constructor.
	 *
	 * @param PostSource       $posts   Knowledge post source (text extractor, Elementor included).
	 * @param PresetRepository $presets Presets (the suggestion must exist).
	 */
	public function __construct( private PostSource $posts, private PresetRepository $presets ) {}

	/**
	 * Runs the scan.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$name    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$tagline = wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES );
		$plugins = self::plugins();

		return array(
			'site_name'      => $name,
			'tagline'        => $tagline,
			'plugins'        => $plugins,
			'content'        => self::content(),
			'privacy_policy' => self::has_privacy_policy(),
			'admin_email'    => (string) get_option( 'admin_email' ),
			'suggestion'     => $this->suggest( $name, $tagline, in_array( 'woocommerce', array_column( $plugins, 'id' ), true ) ),
			'contacts'       => $this->contacts(),
		);
	}

	/**
	 * Relevant active plugins.
	 *
	 * @return list<array{id: string, name: string, kind: string}>
	 */
	private static function plugins(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$found = array();
		foreach ( self::PLUGINS as $file => $plugin ) {
			if ( is_plugin_active( $file ) ) {
				$found[] = array(
					'id'   => $plugin[0],
					'name' => $plugin[1],
					'kind' => $plugin[2],
				);
			}
		}

		return $found;
	}

	/**
	 * Published items per post type offered in the admin.
	 *
	 * @return list<array{slug: string, label: string, published: int}>
	 */
	private static function content(): array {
		$content = array();
		foreach ( AdminPage::post_types() as $type ) {
			$counts    = wp_count_posts( $type['slug'] );
			$content[] = array(
				'slug'      => $type['slug'],
				'label'     => $type['label'],
				'published' => (int) ( $counts->publish ?? 0 ),
			);
		}

		return $content;
	}

	/**
	 * Whether a published privacy policy page is set.
	 */
	private static function has_privacy_policy(): bool {
		$page_id = (int) get_option( 'wp_page_for_privacy_policy' );

		return $page_id > 0 && 'publish' === get_post_status( $page_id );
	}

	/**
	 * Preset suggestion with short reasons.
	 *
	 * @param string $name        Site title.
	 * @param string $tagline     Tagline.
	 * @param bool   $woocommerce WooCommerce is active.
	 * @return array{preset: string, reasons: string[], note: string}
	 */
	public function suggest( string $name, string $tagline, bool $woocommerce ): array {
		$available = array_keys( $this->presets->all() );
		$fallback  = in_array( 'business', $available, true ) ? 'business' : (string) ( $available[0] ?? '' );

		if ( $woocommerce ) {
			return array(
				'preset'  => $fallback,
				'reasons' => array( __( 'WooCommerce is active, so this looks like an online shop.', 'devrivo-chatbot' ) ),
				'note'    => __( 'A dedicated e-commerce preset arrives in version 1.1. Until then, Business works well for shops.', 'devrivo-chatbot' ),
			);
		}

		$sources = array(
			/* translators: %s: Keyword found. */
			array( $name, __( 'Your site title mentions “%s”.', 'devrivo-chatbot' ) ),
			/* translators: %s: Keyword found. */
			array( $tagline, __( 'Your tagline mentions “%s”.', 'devrivo-chatbot' ) ),
		);
		foreach ( self::page_titles() as $title ) {
			$sources[] = array(
				$title,
				sprintf(
					/* translators: 1: Page title, 2: Keyword found (kept as %s). */
					__( 'The page “%1$s” mentions “%2$s”.', 'devrivo-chatbot' ),
					str_replace( '%', '%%', $title ),
					'%s'
				),
			);
		}

		$scores  = array();
		$reasons = array();
		foreach ( self::KEYWORDS as $preset => $keywords ) {
			if ( ! in_array( $preset, $available, true ) ) {
				continue;
			}
			$scores[ $preset ]  = 0;
			$reasons[ $preset ] = array();
			foreach ( $sources as $source ) {
				$text = ' ' . strtolower( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $source[0] ) ) . ' ';
				foreach ( $keywords as $keyword ) {
					if ( str_contains( $text, ' ' . $keyword . ' ' ) ) {
						++$scores[ $preset ];
						$reasons[ $preset ][] = sprintf( $source[1], $keyword );
					}
				}
			}
		}

		arsort( $scores );
		$best = (string) array_key_first( $scores );

		if ( '' === $best || 0 === $scores[ $best ] ) {
			return array(
				'preset'  => $fallback,
				'reasons' => array( __( 'No industry keywords were found, so the general Business preset is a good start.', 'devrivo-chatbot' ) ),
				'note'    => '',
			);
		}

		return array(
			'preset'  => $best,
			'reasons' => array_slice( array_values( array_unique( $reasons[ $best ] ) ), 0, 3 ),
			'note'    => '',
		);
	}

	/**
	 * Titles of published, public, non-password-protected pages.
	 *
	 * @return string[]
	 */
	private static function page_titles(): array {
		$titles = array();
		foreach ( self::published_pages( self::MAX_TITLES ) as $page ) {
			$titles[] = wp_specialchars_decode( wp_strip_all_tags( $page->post_title ), ENT_QUOTES );
		}

		return $titles;
	}

	/**
	 * Published, non-password-protected pages.
	 *
	 * @param int $limit Most pages.
	 * @return WP_Post[]
	 */
	private static function published_pages( int $limit ): array {
		return get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => $limit,
				'orderby'          => 'menu_order title',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);
	}

	/**
	 * Contact details found on the front page and the contact page.
	 *
	 * @return array{emails: string[], phones: string[], sources: list<array{title: string, url: string}>}
	 */
	public function contacts(): array {
		$pages = array();

		$front = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front > 0 ) {
			$post = get_post( $front );
			if ( $post instanceof WP_Post && self::readable( $post ) ) {
				$pages[ $post->ID ] = $post;
			}
		}

		foreach ( self::published_pages( 200 ) as $page ) {
			if ( str_contains( strtolower( $page->post_name . ' ' . $page->post_title ), 'contact' ) && self::readable( $page ) ) {
				$pages[ $page->ID ] = $page;
				break;
			}
		}

		$emails  = array();
		$phones  = array();
		$sources = array();
		foreach ( $pages as $page ) {
			// Links first (their targets are exact), then the visible text.
			$links     = self::links( self::raw( $page ) );
			$text      = $this->posts->extract( $page );
			$emails    = array_merge( $emails, $links['emails'], self::emails( $text ) );
			$phones    = array_merge( $phones, $links['phones'], self::phones( $text ) );
			$sources[] = array(
				'title' => wp_specialchars_decode( wp_strip_all_tags( get_the_title( $page ) ), ENT_QUOTES ),
				'url'   => (string) get_permalink( $page ),
			);
		}

		return array(
			'emails'  => array_slice( array_values( array_unique( $emails ) ), 0, self::MAX_CONTACTS ),
			'phones'  => array_slice( self::unique_phones( $phones ), 0, self::MAX_CONTACTS ),
			'sources' => $sources,
		);
	}

	/**
	 * Raw content before tags are stripped: post_content plus the Elementor
	 * data (link settings and widget HTML), when the page is built with it.
	 *
	 * @param WP_Post $post Post.
	 */
	private static function raw( WP_Post $post ): string {
		$raw = $post->post_content;
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			$data = get_post_meta( $post->ID, '_elementor_data', true );
			$raw .= "\n" . ( is_string( $data ) ? $data : (string) wp_json_encode( $data ) );
		}

		return $raw;
	}

	/**
	 * Email addresses and phone numbers from mailto: and tel: links (in HTML
	 * attributes or Elementor link settings). Validated like text matches.
	 *
	 * @param string $raw Raw content.
	 * @return array{emails: string[], phones: string[]}
	 */
	public static function links( string $raw ): array {
		preg_match_all( '/\b(mailto|tel):([^"\'\s<>?\\\\]+)/i', $raw, $matches, PREG_SET_ORDER );

		$emails = array();
		$phones = array();
		foreach ( $matches as $match ) {
			$value = trim( html_entity_decode( rawurldecode( $match[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( 'mailto' === strtolower( $match[1] ) ) {
				// mailto: may list several addresses.
				foreach ( explode( ',', $value ) as $address ) {
					$emails = array_merge( $emails, self::emails( $address ) );
				}
			} else {
				$phones = array_merge( $phones, self::phones( $value ) );
			}
		}

		return array(
			'emails' => array_values( array_unique( $emails ) ),
			'phones' => self::unique_phones( $phones ),
		);
	}

	/**
	 * Whether a post is published, public and not password-protected.
	 *
	 * @param WP_Post $post Post.
	 */
	private static function readable( WP_Post $post ): bool {
		return 'publish' === $post->post_status && '' === $post->post_password && is_post_type_viewable( $post->post_type );
	}

	/**
	 * Valid email addresses in a text (lowercase).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function emails( string $text ): array {
		preg_match_all( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $matches );

		$emails = array();
		foreach ( $matches[0] as $match ) {
			$email = strtolower( rtrim( $match, '.' ) );
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Phone numbers in a text: 7–15 digits, written with spaces, brackets,
	 * dots or dashes. Dates and year ranges are left out. Dots become spaces
	 * so the result fits the call action's format.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function phones( string $text ): array {
		// Spaces and tabs only: a number never runs across lines.
		preg_match_all( '/\+?\(?\d[\d \t().-]{5,22}\d/', $text, $matches );

		$phones = array();
		foreach ( $matches[0] as $match ) {
			$match  = trim( $match );
			$digits = (string) preg_replace( '/\D/', '', $match );
			if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
				continue;
			}
			// 2024-05-01, 01.05.2024, 2010 - 2024.
			if ( preg_match( '/^(\d{4}[-.\/]\d{1,2}[-.\/]\d{1,2}|\d{1,2}[-.\/]\d{1,2}[-.\/]\d{4}|\d{4}\s*[-–]\s*\d{4})$/', $match ) ) {
				continue;
			}
			$phones[] = trim( (string) preg_replace( '/\s+/', ' ', str_replace( '.', ' ', $match ) ) );
		}

		return self::unique_phones( $phones );
	}

	/**
	 * Phone numbers without repeats (compared by digits).
	 *
	 * @param string[] $phones Phone numbers.
	 * @return string[]
	 */
	private static function unique_phones( array $phones ): array {
		$unique = array();
		foreach ( $phones as $phone ) {
			$key = (string) preg_replace( '/\D/', '', $phone );
			if ( ! isset( $unique[ $key ] ) ) {
				$unique[ $key ] = $phone;
			}
		}

		return array_values( $unique );
	}
}
