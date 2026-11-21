<?php

use PHPUnit\Framework\TestCase;

final class PluginSecurityTest extends TestCase {
	/** @var PIXCENSUS_Plugin */
	private $plugin;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new class() {
			public $options = 'wp_options';
			public function delete( $table, $where, $formats ) {
				$value = get_option( $where['option_name'], false );
				if ( false === $value || maybe_serialize( $value ) !== $where['option_value'] ) {
					return 0;
				}
				delete_option( $where['option_name'] );
				return 1;
			}
		};


		$GLOBALS['pixcensus_test_options']      = array();
		$GLOBALS['pixcensus_test_site_options'] = array();
		$GLOBALS['pixcensus_test_current_blog'] = 1;
		$GLOBALS['pixcensus_test_blog_stack']   = array();
		$GLOBALS['pixcensus_test_sites']        = array( 1 );
		$GLOBALS['pixcensus_test_multisite']    = false;
		$GLOBALS['pixcensus_test_can_manage']   = true;
		$GLOBALS['pixcensus_test_post_types']   = array();
		$_POST                            = array();
		$_SERVER['REQUEST_METHOD']        = 'POST';
		$this->plugin                     = PIXCENSUS_Plugin::instance();
	}

	/**
	 * @param callable $callback Callback expected to emit JSON.
	 * @param bool     $success Expected success flag.
	 * @param int      $status Expected status.
	 * @return PIXCENSUS_Test_Json_Response
	 */
	private function capture_json( callable $callback, bool $success, int $status ): PIXCENSUS_Test_Json_Response {
		try {
			$callback();
			$this->fail( 'Expected a JSON response.' );
		} catch ( PIXCENSUS_Test_Json_Response $response ) {
			$this->assertSame( $success, $response->success );
			$this->assertSame( $status, $response->status );
			$this->assertIsArray( $response->data );
			$this->assertArrayHasKey( 'message', $response->data );

			return $response;
		}
	}

	private function set_request( string $action, string $nonce ): void {
		$_POST = array(
			'action' => $action,
			'nonce'  => $nonce,
		);
	}

	public function test_unauthenticated_ajax_actions_have_stable_json_hooks(): void {
		$actions = array(
			'pixcensus_run_scan',
			'pixcensus_mark_manual_used',
			'pixcensus_unmark_manual_used',
			'pixcensus_mark_manual_used_bulk',
			'pixcensus_unmark_manual_used_bulk',
		);

		foreach ( $actions as $action ) {
			$this->assertArrayHasKey( 'wp_ajax_nopriv_' . $action, $GLOBALS['pixcensus_test_actions'] );
		}

		$this->capture_json( array( $this->plugin, 'ajax_unauthorized' ), false, 401 );
	}

	public function test_ajax_rejects_wrong_method_action_permission_and_nonce(): void {
		$this->set_request( 'pixcensus_mark_manual_used', 'pixcensus_mark_manual_used-valid' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->capture_json( array( $this->plugin, 'ajax_mark_manual_used' ), false, 405 );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->set_request( 'pixcensus_unmark_manual_used', 'pixcensus_mark_manual_used-valid' );
		$this->capture_json( array( $this->plugin, 'ajax_mark_manual_used' ), false, 400 );

		$this->set_request( 'pixcensus_mark_manual_used', 'pixcensus_mark_manual_used-valid' );
		$GLOBALS['pixcensus_test_can_manage'] = false;
		$this->capture_json( array( $this->plugin, 'ajax_mark_manual_used' ), false, 403 );

		$GLOBALS['pixcensus_test_can_manage'] = true;
		$this->set_request( 'pixcensus_mark_manual_used', '' );
		$this->capture_json( array( $this->plugin, 'ajax_mark_manual_used' ), false, 403 );

		$this->set_request( 'pixcensus_mark_manual_used', 'pixcensus_unmark_manual_used-valid' );
		$this->capture_json( array( $this->plugin, 'ajax_mark_manual_used' ), false, 403 );
	}

	public function test_single_ajax_accepts_numeric_ids_and_preserves_action_responses(): void {
		$GLOBALS['pixcensus_test_post_types'][11] = 'attachment';
		$GLOBALS['pixcensus_test_post_types'][12] = 'attachment';

		$this->set_request( 'pixcensus_mark_manual_used', 'pixcensus_mark_manual_used-valid' );
		$_POST['id'] = 11;
		$response     = $this->capture_json( array( $this->plugin, 'ajax_mark_manual_used' ), true, 200 );
		$this->assertSame( 11, $response->data['id'] );
		$this->assertSame( array( 11 ), $response->data['manual'] );

		$this->set_request( 'pixcensus_mark_manual_used', 'pixcensus_mark_manual_used-valid' );
		$_POST['id'] = ' 12 ';
		$response     = $this->capture_json( array( $this->plugin, 'ajax_mark_manual_used' ), true, 200 );
		$this->assertSame( 12, $response->data['id'] );
		$this->assertSame( array( 11, 12 ), $response->data['manual'] );

		$this->set_request( 'pixcensus_unmark_manual_used', 'pixcensus_unmark_manual_used-valid' );
		$_POST['id'] = '11';
		$response     = $this->capture_json( array( $this->plugin, 'ajax_unmark_manual_used' ), true, 200 );
		$this->assertSame( 11, $response->data['id'] );
		$this->assertSame( array( 12 ), $response->data['manual'] );
	}

	public function test_single_ajax_rejects_missing_malformed_and_non_attachment_ids(): void {
		$GLOBALS['pixcensus_test_post_types'][11] = 'attachment';

		$invalid_ids = array(
			null,
			'',
			'0',
			'-11',
			'11.5',
			'11oops',
			array( 11 ),
			(object) array( 'id' => 11 ),
			str_repeat( '9', 21 ),
			'<strong>11</strong>',
			'11%20',
			'999',
		);

		foreach ( $invalid_ids as $invalid_id ) {
			$this->set_request( 'pixcensus_mark_manual_used', 'pixcensus_mark_manual_used-valid' );

			if ( null !== $invalid_id ) {
				$_POST['id'] = $invalid_id;
			}

			$this->capture_json( array( $this->plugin, 'ajax_mark_manual_used' ), false, 400 );
		}

		$this->assertFalse( get_option( 'pixcensus_manual_used_ids' ) );
	}

	public function test_bulk_ajax_accepts_valid_ids_deduplicates_and_filters_non_attachments(): void {
		$GLOBALS['pixcensus_test_post_types'][11] = 'attachment';
		$GLOBALS['pixcensus_test_post_types'][12] = 'attachment';

		$this->set_request( 'pixcensus_mark_manual_used_bulk', 'pixcensus_mark_manual_used_bulk-valid' );
		$_POST['ids'] = array( '11', ' 12 ', '11', '999' );
		$response      = $this->capture_json( array( $this->plugin, 'ajax_mark_manual_used_bulk' ), true, 200 );
		$this->assertSame( array( 11, 12 ), $response->data['ids'] );
		$this->assertSame( array( 11, 12 ), $response->data['manual'] );

		$this->set_request( 'pixcensus_unmark_manual_used_bulk', 'pixcensus_unmark_manual_used_bulk-valid' );
		$_POST['ids'] = array( '11', '12' );
		$response      = $this->capture_json( array( $this->plugin, 'ajax_unmark_manual_used_bulk' ), true, 200 );
		$this->assertSame( array( 11, 12 ), $response->data['ids'] );
		$this->assertSame( array(), $response->data['manual'] );

		$this->set_request( 'pixcensus_mark_manual_used_bulk', 'pixcensus_mark_manual_used_bulk-valid' );
		$_POST['ids'] = array( '999' );
		$response      = $this->capture_json( array( $this->plugin, 'ajax_mark_manual_used_bulk' ), true, 200 );
		$this->assertSame( array(), $response->data['ids'] );
		$this->assertSame( array(), $response->data['manual'] );
	}

	public function test_bulk_ajax_rejects_empty_oversized_malformed_and_unexpected_inputs(): void {
		$GLOBALS['pixcensus_test_post_types'][11] = 'attachment';

		$invalid_selections = array(
			array(),
			array_fill( 0, 501, '11' ),
			array( '11', 'bad' ),
			array( '11', '-12' ),
			array( '11', '12.5' ),
			array( '11', array( '12' ) ),
			array( '11', (object) array( 'id' => '12' ) ),
			array( '11', '<strong>12</strong>' ),
			'11,12',
		);

		foreach ( $invalid_selections as $invalid_selection ) {
			$this->set_request( 'pixcensus_mark_manual_used_bulk', 'pixcensus_mark_manual_used_bulk-valid' );
			$_POST['ids'] = $invalid_selection;
			$this->capture_json( array( $this->plugin, 'ajax_mark_manual_used_bulk' ), false, 400 );
		}

		$this->assertFalse( get_option( 'pixcensus_manual_used_ids' ) );
	}

	public function test_bulk_ajax_rejects_missing_ids(): void {
		$this->set_request( 'pixcensus_mark_manual_used_bulk', 'pixcensus_mark_manual_used_bulk-valid' );
		$this->capture_json( array( $this->plugin, 'ajax_mark_manual_used_bulk' ), false, 400 );
	}

	public function test_concurrent_scan_is_refused_with_conflict_response(): void {
		update_option(
			'pixcensus_scan_lock',
			array(
				'token'      => 'another-request',
				'expires_at' => time() + 300,
			),
			false
		);
		$this->set_request( 'pixcensus_run_scan', 'pixcensus_run_scan-valid' );

		$this->capture_json( array( $this->plugin, 'ajax_run_scan' ), false, 409 );
	}

	public function test_scan_lock_is_non_autoloaded_and_owner_released(): void {
		$acquire = new ReflectionMethod( PIXCENSUS_Plugin::class, 'acquire_scan_lock' );
		$release = new ReflectionMethod( PIXCENSUS_Plugin::class, 'release_scan_lock' );
		$acquire->setAccessible( true );
		$release->setAccessible( true );

		$this->assertTrue( $acquire->invoke( $this->plugin ) );
		$this->assertFalse( $acquire->invoke( $this->plugin ) );
		$this->assertFalse( $GLOBALS['pixcensus_test_autoload'][1]['pixcensus_scan_lock'] );

		$owned_lock = get_option( 'pixcensus_scan_lock' );
		$this->assertIsArray( $owned_lock );
		$this->assertArrayHasKey( 'token', $owned_lock );
		$release->invoke( $this->plugin );
		$this->assertFalse( get_option( 'pixcensus_scan_lock', false ) );

		$this->assertTrue( $acquire->invoke( $this->plugin ) );
		update_option(
			'pixcensus_scan_lock',
			array(
				'token'      => 'replacement-owner',
				'expires_at' => time() + 300,
			),
			false
		);
		$release->invoke( $this->plugin );
		$this->assertSame( 'replacement-owner', get_option( 'pixcensus_scan_lock' )['token'] );
	}

	public function test_protection_change_respects_another_operation_lock(): void {
		$GLOBALS['pixcensus_test_post_types'][11] = 'attachment';
		update_option( 'pixcensus_scan_lock', array( 'token' => 'other', 'expires_at' => time() + 60 ) );
		$this->set_request( 'pixcensus_mark_manual_used', 'pixcensus_mark_manual_used-valid' );
		$_POST['id'] = 11;
		$this->capture_json( array( $this->plugin, 'ajax_mark_manual_used' ), false, 409 );
		$this->assertSame( array(), get_option( 'pixcensus_manual_used_ids', array() ) );
		$this->assertSame( 'other', get_option( 'pixcensus_scan_lock' )['token'] );
	}

	public function test_network_activation_initializes_each_site_and_restores_context(): void {
		$GLOBALS['pixcensus_test_multisite'] = true;
		$GLOBALS['pixcensus_test_sites']     = array( 1, 2, 3 );

		PIXCENSUS_Plugin::activate( true );

		$this->assertSame( 1, $GLOBALS['pixcensus_test_current_blog'] );
		$this->assertSame( array(), $GLOBALS['pixcensus_test_blog_stack'] );
		$this->assertSame( '1', $GLOBALS['pixcensus_test_options']['pixcensus_include_drafts'] );
		$this->assertSame( '1', $GLOBALS['pixcensus_test_site_options'][2]['pixcensus_include_drafts'] );
		$this->assertSame( '1', $GLOBALS['pixcensus_test_site_options'][3]['pixcensus_include_drafts'] );
		$this->assertFalse( $GLOBALS['pixcensus_test_autoload'][2]['pixcensus_manual_used_ids'] );
	}
}
