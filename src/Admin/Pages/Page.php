<?php
namespace FFSP\Admin\Pages;

use FFSP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Base for admin pages: shared helpers.
 */
abstract class Page {

	/** @var Plugin */
	protected $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	abstract public function render();

	protected function badge( $status ) {
		return '<span class="ffsp-badge ffsp-badge--' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
	}

	protected function action_url( $action, array $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	protected function time( $mysql_gmt ) {
		if ( ! $mysql_gmt ) {
			return '—';
		}
		$ts = strtotime( $mysql_gmt . ' UTC' );
		/* translators: %s human time diff */
		return '<span title="' . esc_attr( wp_date( 'Y-m-d H:i:s', $ts ) ) . '">' . esc_html( sprintf( __( '%s ago', 'fluent-sharepoint-sync' ), human_time_diff( $ts ) ) ) . '</span>';
	}

	protected function help( $text ) {
		return '<p class="description">' . wp_kses_post( $text ) . '</p>';
	}
}
