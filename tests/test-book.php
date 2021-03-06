<?php

// require_once( PB_PLUGIN_DIR . 'inc/class-book.php' );

class BookTest extends \WP_UnitTestCase {

	use utilsTrait;

	/**
	 * @return int
	 */
	protected function createChapter() {
		$new_post = [
			'post_title' => 'test chapter',
			'post_type' => 'chapter',
			'post_status' => 'publish',
			'post_content' => 'some content',
		];
		$pid = wp_insert_post( $new_post );
		update_post_meta( $pid, 'pb_export', 'on' );

		return $pid;
	}


	public function test_isBook() {

		$book = new \Pressbooks\Book();

		switch_to_blog( get_network()->site_id );
		$this->assertFalse( $book::isBook() );

		$this->_book();
		$this->assertTrue( $book::isBook() );
	}

	public function test_getBookStructure() {

		$book = new \Pressbooks\Book();

		// Returns export value
		$this->_book();
		$this->createChapter();
		$structure = $book::getBookStructure();
		$page = $structure['__orphans'][0]; // In __orphans because doesn't belong to a part
		$this->assertTrue( $page['export'] );
		$this->assertEquals( $structure['front-matter'][0]['post_title'], __( 'Introduction', 'pressbooks' ) );
		$this->assertArrayHasKey( 'part', $structure );
		$this->assertArrayHasKey( 'chapters', $structure['part'][0] );
		$this->assertArrayHasKey( 'back-matter', $structure );

		// Returns cached export value, with $blog_id as param
		global $blog_id;
		delete_post_meta( $page['ID'], 'pb_export' );
		$structure = $book::getBookStructure( $blog_id );
		$page = $structure['__orphans'][0];
		$this->assertTrue( $page['export'] );

		// Returns latest export value no cache
		delete_post_meta( $page['ID'], 'pb_export' );
		$book::deleteBookObjectCache();
		$structure = $book::getBookStructure();
		$page = $structure['__orphans'][0];
		$this->assertFalse( $page['export'] );
	}

	public function test_getBookContents() {

		$book = new \Pressbooks\Book();

		// Returns export value
		$this->_book();
		$this->createChapter();
		$contents = $book::getBookContents();
		$page = $contents['__orphans'][0]; // In __orphans because doesn't belong to a part
		$this->assertTrue( $page['export'] );
		$this->assertEquals( $contents['front-matter'][0]['post_content'], __( 'This is where you can write your introduction.', 'pressbooks' ) );
		$this->assertArrayHasKey( 'part', $contents );
		$this->assertArrayHasKey( 'chapters', $contents['part'][0] );
		$this->assertArrayHasKey( 'back-matter', $contents );

		// Returns cached export value
		delete_post_meta( $page['ID'], 'pb_export' );
		$contents = $book::getBookContents();
		$page = $contents['__orphans'][0];
		$this->assertTrue( $page['export'] );

		// Returns latest export value no cache
		delete_post_meta( $page['ID'], 'pb_export' );
		$book::deleteBookObjectCache();
		$contents = $book::getBookContents();
		$page = $contents['__orphans'][0];
		$this->assertFalse( $page['export'] );
	}

	public function test_getBookInformation() {

		$book = new \Pressbooks\Book();

		$this->_book();
		$mp = ( new \Pressbooks\Metadata() )->getMetaPost();
		add_post_meta( $mp->ID, 'pb_about_unlimited', 'Hello world!', true );

		// Returns texturized pb_about_unlimited value
		$info = $book::getBookInformation();
		$this->assertArrayHasKey( 'pb_about_unlimited', $info );
		$this->assertEquals( $info['pb_about_unlimited'], '<p>Hello world!</p>' );
		foreach ( $info as $key => $val ) {
			$this->assertStringStartsWith( 'pb_', $key );
		}

		// Returns cached pb_about_unlimited value, with $blog_id as param
		global $blog_id;
		delete_post_meta( $mp->ID, 'pb_about_unlimited' );
		$info = $book::getBookInformation( $blog_id );
		$this->assertArrayHasKey( 'pb_about_unlimited', $info );
		$this->assertEquals( $info['pb_about_unlimited'], '<p>Hello world!</p>' );

		// Returns latest pb_about_unlimited value no cache
		delete_post_meta( $mp->ID, 'pb_about_unlimited' );
		$book::deleteBookObjectCache();
		$info = $book::getBookInformation();
		$this->assertArrayNotHasKey( 'pb_about_unlimited', $info );
	}

}
