<?php

use PHPUnit\Framework\TestCase;

final class ReviewImprovementTest extends TestCase {
	protected function tearDown(): void {
		$GLOBALS['pixcensus_test_current_blog'] = 1;
		$GLOBALS['pixcensus_test_options'] = array();
		$GLOBALS['pixcensus_test_site_options'] = array();
		parent::tearDown();
	}

	public function test_partial_checkpoint_cannot_be_published(): void {
		$this->expectException( LogicException::class );
		( new PIXCENSUS_Scanner() )->finish( array( 'stage' => 13 ) );
	}

	public function test_complete_checkpoint_has_disjoint_classifications_and_protection(): void {
		$GLOBALS['pixcensus_test_options'] = array( 'pixcensus_manual_used_ids' => array( 3, 999 ) );
		$result = ( new PIXCENSUS_Scanner() )->finish( array(
			'stage' => 14, 'ids' => array( 1, 2, 3, 4 ), 'used' => array( 1 => true, 999 => true ),
			'draft' => array( 1 => true, 2 => true, 3 => true ), 'orphans' => array(),
			'provenance' => array(), 'details' => array(), 'include_drafts' => true, 'revision' => 'original',
		) );
		$this->assertSame( array( 1, 3 ), $result['used_ids'] );
		$this->assertSame( array( 2 ), $result['draft_only_ids'] );
		$this->assertSame( array( 4 ), $result['unused_ids'] );
		$this->assertSame( 'original', $result['revision'] );
	}

	public function test_history_is_bounded_expires_and_is_site_local(): void {
		$GLOBALS['pixcensus_test_options'] = array( 'pixcensus_history' => array( array( 'time' => time() - 91 * 86400, 'actor' => 1, 'action' => 'trash', 'id' => 7 ) ) );
		$this->assertSame( array(), PIXCENSUS_Review::history() );
		$this->assertSame( array(), get_option( 'pixcensus_history' ) );
		for ( $i = 0; $i < 505; ++$i ) { PIXCENSUS_Review::record( 'protect', $i + 1 ); }
		$this->assertCount( 500, PIXCENSUS_Review::history() );
		$this->assertSame( 6, PIXCENSUS_Review::history()[0]['id'] );
		$GLOBALS['pixcensus_test_current_blog'] = 2;
		$this->assertSame( array(), PIXCENSUS_Review::history() );
		PIXCENSUS_Review::record( 'restore', 44 );
		$this->assertCount( 1, PIXCENSUS_Review::history() );
		$GLOBALS['pixcensus_test_current_blog'] = 1;
		$this->assertCount( 500, PIXCENSUS_Review::history() );
	}
}
