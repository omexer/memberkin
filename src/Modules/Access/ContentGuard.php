<?php
/**
 * Applies access decisions to rendered content.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Access;

use Memberkin\Core\Settings;
use Memberkin\Core\Templates;
use Memberkin\Domain\Access\AccessDecision;
use Memberkin\Domain\Level;
use Memberkin\Repository\LevelRepository;
use Memberkin\Service\AccessService;

defined( 'ABSPATH' ) || exit;

/**
 * Content, excerpts, feeds, comments, REST, embeds, XML-RPC and redirects.
 */
final class ContentGuard {

	/**
	 * Re-entrancy guard for the_content.
	 *
	 * @var bool
	 */
	private bool $filtering = false;

	/**
	 * Constructor.
	 *
	 * @param AccessService $access Access.
	 */
	public function __construct( private AccessService $access ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'filter_content' ), 5 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 100, 2 );
		add_filter( 'the_content_feed', array( $this, 'filter_feed' ), 5 );
		add_filter( 'the_excerpt_rss', array( $this, 'filter_feed' ), 5 );
		add_filter( 'the_excerpt_embed', array( $this, 'filter_feed' ), 5 );
		add_filter( 'comments_array', array( $this, 'filter_comments' ), 10, 2 );
		add_filter( 'comments_open', array( $this, 'filter_comments_open' ), 10, 2 );
		add_filter( 'pings_open', array( $this, 'filter_comments_open' ), 10, 2 );
		add_filter( 'post_class', array( $this, 'post_class' ), 10, 3 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_rest_filters' ) );
		add_filter( 'xmlrpc_prepare_post', array( $this, 'filter_xmlrpc' ), 10, 2 );
		add_filter( 'rest_prepare_comment', array( $this, 'filter_rest_comment' ), 10, 2 );
	}

	/**
	 * Decision for the current post in the loop.
	 *
	 * @param int|null $post_id Post id (current when null).
	 */
	private function decision( ?int $post_id = null ): ?AccessDecision {
		$post_id = $post_id ?? (int) get_the_ID();
		return $post_id ? $this->access->decide_post( $post_id ) : null;
	}

	/**
	 * Replaces restricted content with the unauthorized message.
	 *
	 * @param string $content Content.
	 */
	public function filter_content( $content ) {
		if ( $this->filtering || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $content;
		}
		$decision = $this->decision();
		if ( ! $decision || $decision->granted ) {
			return $content;
		}
		$this->filtering = true;
		try {
			return $this->restricted_html( (int) get_the_ID(), $decision );
		} finally {
			$this->filtering = false;
		}
	}

	/**
	 * Restricted excerpts never contain the full content.
	 *
	 * @param string        $excerpt Excerpt.
	 * @param \WP_Post|null $post    Post.
	 */
	public function filter_excerpt( $excerpt, $post = null ) {
		$post_id  = $post instanceof \WP_Post ? $post->ID : (int) get_the_ID();
		$decision = $this->decision( $post_id );
		if ( ! $decision || $decision->granted ) {
			return $excerpt;
		}
		return Settings::get( 'access', 'show_excerpts', true ) ? $this->teaser( $post_id ) : wp_strip_all_tags( $this->message( $decision, $post_id ) );
	}

	/**
	 * Feeds and embeds get a teaser only.
	 *
	 * @param string $content Content.
	 */
	public function filter_feed( $content ) {
		$decision = $this->decision();
		if ( ! $decision || $decision->granted ) {
			return $content;
		}
		$teaser = Settings::get( 'access', 'feed_teaser', true ) ? $this->teaser( (int) get_the_ID() ) : '';
		return $teaser . ( '' !== $teaser ? ' ' : '' ) . wp_strip_all_tags( $this->message( $decision, (int) get_the_ID() ) );
	}

	/**
	 * Hides comments of restricted posts.
	 *
	 * @param array $comments Comments.
	 * @param int   $post_id  Post.
	 * @return array
	 */
	public function filter_comments( $comments, $post_id ) {
		$decision = $this->decision( (int) $post_id );
		return $decision && ! $decision->granted ? array() : $comments;
	}

	/**
	 * Closes comments on restricted posts.
	 *
	 * @param bool $open    Open.
	 * @param int  $post_id Post.
	 */
	public function filter_comments_open( $open, $post_id ) {
		if ( ! $open ) {
			return $open;
		}
		$decision = $this->decision( (int) $post_id );
		return $decision && ! $decision->granted ? false : $open;
	}

	/**
	 * Post classes.
	 *
	 * @param array      $classes Classes.
	 * @param array      $css     Extra.
	 * @param int|string $post_id Post.
	 * @return array
	 */
	public function post_class( $classes, $css, $post_id ) {
		$decision = $this->decision( (int) $post_id );
		if ( $decision && $decision->is_restricted() ) {
			$classes[] = 'memberkin-restricted';
			if ( ! $decision->granted ) {
				$classes[] = 'memberkin-no-access';
			}
		}
		return $classes;
	}

	/**
	 * Body classes on singular restricted content.
	 *
	 * @param array $classes Classes.
	 * @return array
	 */
	public function body_class( $classes ) {
		if ( is_singular() ) {
			$decision = $this->decision( (int) get_queried_object_id() );
			if ( $decision && ! $decision->granted ) {
				$classes[] = 'memberkin-no-access';
			}
		}
		return $classes;
	}

	/**
	 * Redirects for singular restricted content (if configured) and for protected URLs.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return;
		}
		$member_pages = AccessService::member_page_ids();
		if ( is_singular() && in_array( (int) get_queried_object_id(), $member_pages, true ) ) {
			return;
		}
		$decision = null;
		if ( is_singular() ) {
			$decision = $this->access->decide_post( (int) get_queried_object_id() );
			$behavior = $decision->rule ? $decision->rule->unauthorized_behavior() : 'default';
			$redirect = 'redirect' === $behavior || ( 'default' === $behavior && Settings::get( 'access', 'redirect_unauthorized', false ) );
			if ( $decision->granted || ! $redirect ) {
				return;
			}
		} else {
			$path     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$decision = $this->access->decide_uri( $path );
			if ( $decision->granted ) {
				return;
			}
		}
		$target = $this->redirect_target( $decision );
		/**
		 * Fires before a visitor without access is redirected.
		 *
		 * @param AccessDecision $decision Decision.
		 * @param string         $target   Redirect URL.
		 */
		do_action( 'memberkin_access_denied', $decision, $target );
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Where to send a visitor without access.
	 *
	 * @param AccessDecision $decision Decision.
	 */
	private function redirect_target( AccessDecision $decision ): string {
		$current = isset( $_SERVER['REQUEST_URI'] ) ? home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : home_url( '/' );
		if ( $decision->rule && '' !== $decision->rule->redirect_url() ) {
			$url = $decision->rule->redirect_url();
		} elseif ( '' !== (string) Settings::get( 'access', 'redirect_url', '' ) ) {
			$url = (string) Settings::get( 'access', 'redirect_url', '' );
		} elseif ( AccessDecision::NOT_LOGGED_IN === $decision->reason ) {
			$url = wp_login_url( $current );
		} else {
			$url = memberkin_page_url( 'levels' );
		}
		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * REST filters for restrictable post types.
	 */
	public function register_rest_filters(): void {
		if ( ! Settings::get( 'access', 'rest_protection', true ) ) {
			return;
		}
		foreach ( AccessService::restrictable_post_types() as $type ) {
			add_filter( 'rest_prepare_' . $type, array( $this, 'filter_rest_post' ), 10, 2 );
		}
		add_filter( 'rest_prepare_attachment', array( $this, 'filter_rest_post' ), 10, 2 );
	}

	/**
	 * Redacts restricted REST post responses.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 */
	public function filter_rest_post( $response, $post ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post ) {
			return $response;
		}
		$decision = $this->access->decide_post( $post->ID );
		$data     = $response->get_data();
		if ( $decision->is_restricted() ) {
			$data['memberkin_restricted'] = true;
		}
		if ( ! $decision->granted ) {
			$message = $this->message( $decision, $post->ID );
			$teaser  = Settings::get( 'access', 'show_excerpts', true ) ? $this->teaser( $post->ID ) : '';
			if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
				$data['content']['rendered'] = wp_kses_post( $message );
				unset( $data['content']['raw'] );
				$data['content']['protected'] = true;
			}
			if ( isset( $data['excerpt'] ) && is_array( $data['excerpt'] ) ) {
				$data['excerpt']['rendered'] = esc_html( $teaser );
				unset( $data['excerpt']['raw'] );
			}
			if ( 'attachment' === $post->post_type ) {
				unset( $data['source_url'], $data['media_details'], $data['guid'], $data['link'] );
			}
			$data['memberkin_access'] = $decision->jsonSerialize();
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Hides comment text of restricted posts in the REST API.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Comment       $comment  Comment.
	 */
	public function filter_rest_comment( $response, $comment ) {
		if ( ! Settings::get( 'access', 'rest_protection', true ) || ! $comment instanceof \WP_Comment ) {
			return $response;
		}
		$decision = $this->access->decide_post( (int) $comment->comment_post_ID );
		if ( ! $decision->granted ) {
			return new \WP_Error( 'memberkin_restricted', __( 'This content is for members only.', 'memberkin' ), array( 'status' => 403 ) );
		}
		return $response;
	}

	/**
	 * XML-RPC post output.
	 *
	 * @param array $data Post data.
	 * @param array $post Raw post.
	 * @return array
	 */
	public function filter_xmlrpc( $data, $post ) {
		$decision = $this->access->decide_post( (int) ( $post['ID'] ?? 0 ) );
		if ( ! $decision->granted ) {
			$data['post_content'] = wp_strip_all_tags( $this->message( $decision, (int) $post['ID'] ) );
			$data['post_excerpt'] = $this->teaser( (int) $post['ID'] );
		}
		return $data;
	}

	/**
	 * Plain teaser: manual excerpt or the first N words (never runs shortcodes).
	 *
	 * @param int $post_id Post.
	 */
	public function teaser( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}
		$words = (int) Settings::get( 'access', 'excerpt_length', 55 );
		if ( $words <= 0 ) {
			return '';
		}
		$text = excerpt_remove_blocks( strip_shortcodes( (string) $post->post_content ) );
		// Content after a <!--more--> tag is never shown.
		$text = (string) preg_replace( '/<!--more(.*?)?-->.*$/s', '', $text );
		return wp_trim_words( wp_strip_all_tags( $text ), $words );
	}

	/**
	 * Unauthorized message with merge tags replaced.
	 *
	 * @param AccessDecision $decision Decision.
	 * @param int            $post_id  Post.
	 */
	public function message( AccessDecision $decision, int $post_id = 0 ): string {
		if ( AccessDecision::DRIP_PENDING === $decision->reason && $decision->available_at ) {
			/* translators: %s: date. */
			$text = sprintf( __( 'This content will be available to you on %s.', 'memberkin' ), wp_date( get_option( 'date_format' ), $decision->available_at->getTimestamp() ) );
		} elseif ( AccessDecision::EXPIRED === $decision->reason ) {
			$text = __( 'Your access to this content has ended.', 'memberkin' );
		} elseif ( $decision->rule && '' !== trim( $decision->rule->unauthorized_message() ) ) {
			$text = $decision->rule->unauthorized_message();
		} elseif ( AccessDecision::NOT_LOGGED_IN === $decision->reason ) {
			$text = (string) Settings::get( 'access', 'logged_out_message', '' );
		} else {
			$text = (string) Settings::get( 'access', 'restricted_message', '' );
		}

		$levels = array();
		$repo   = memberkin()->get( LevelRepository::class );
		foreach ( $decision->required_levels as $level_id ) {
			$level = $repo->find( $level_id );
			if ( $level && $level->is_listed() ) {
				$levels[] = $level->name;
			}
		}
		$current = $post_id ? (string) get_permalink( $post_id ) : home_url( '/' );
		$text    = strtr(
			$text,
			array(
				'{levels}'     => esc_html( wp_sprintf( '%l', $levels ) ),
				'{levels_url}' => esc_url( memberkin_page_url( 'levels' ) ),
				'{login_url}'  => esc_url( wp_login_url( $current ) ),
			)
		);
		/**
		 * Filters the unauthorized message (HTML).
		 *
		 * @param string         $text     Message.
		 * @param AccessDecision $decision Decision.
		 * @param int            $post_id  Post.
		 */
		return (string) apply_filters( 'memberkin_unauthorized_message', $text, $decision, $post_id );
	}

	/**
	 * Full restricted block (message, teaser, join/login links, optional login form).
	 *
	 * @param int            $post_id  Post.
	 * @param AccessDecision $decision Decision.
	 */
	public function restricted_html( int $post_id, AccessDecision $decision ): string {
		$behavior = $decision->rule ? $decision->rule->unauthorized_behavior() : 'default';
		$teaser   = ( 'message' !== $behavior && Settings::get( 'access', 'show_excerpts', true ) ) || 'excerpt' === $behavior ? $this->teaser( $post_id ) : '';
		$levels   = array();
		$repo     = memberkin()->get( LevelRepository::class );
		foreach ( $decision->required_levels as $level_id ) {
			$level = $repo->find( $level_id );
			if ( $level instanceof Level && $level->is_listed() ) {
				$levels[] = $level;
			}
		}
		wp_enqueue_style( 'memberkin-frontend' );
		return Templates::render(
			'access/restricted',
			array(
				'decision'   => $decision,
				'message'    => $this->message( $decision, $post_id ),
				'teaser'     => $teaser,
				'levels'     => $levels,
				'levels_url' => memberkin_page_url( 'levels' ),
				'login_url'  => wp_login_url( (string) get_permalink( $post_id ) ),
				'show_login' => 'login' === $behavior && ! is_user_logged_in(),
				'post_id'    => $post_id,
			)
		);
	}
}
