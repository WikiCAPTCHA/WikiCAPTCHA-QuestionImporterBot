
<?php
require 'load.php';

// change these
$CATEGORY_NAME = "Category:Birds drinking";
$CONNOTATION_IDs = [ 3 ];



$wiki = \wm\Commons::instance();

$queries =
	$wiki->createQuery( [
		'action'    => 'query',
		'generator' => 'categorymembers',
		'prop'      => 'imageinfo',
		'iiprop'    => [ 'url', 'mime' ],
		'gcmtitle'  => $CATEGORY_NAME,
	] );

foreach( $queries as $query ) {

	foreach( $query->query->pages as $page ) {

		if( !isset( $page->imageinfo ) ) {
			continue;
		}

		$imageinfo = $page->imageinfo[0] ?? null;
		if( !$imageinfo ) {
			continue;
		}

		$mime = $imageinfo->mime ?? null;
		if( $mime !== 'image/jpeg' ) {
			echo "bad mime $mime\n";
			continue;
		}

		$image = ( new Query() )
			->from( 'image' )
			->whereInt( 'commons_pageid', $page->pageid )
			->queryRow();

		if( $image ) {

			// try to update existing image

			foreach( $CONNOTATION_IDs as $CONNOTATION_ID ) {
				// try to relate if possible
				try {
					insert_row( 'image_connotation', [
						'image_ID'       => $image->image_ID,
						'connotation_ID' => $CONNOTATION_ID,
					] );
					echo "Updated\n";
				} catch( Exception $e ) {
					echo "Already added\n";
				}
			}

		} else {

			// create a new Image

			query( 'START TRANSACTION' );

			insert_row( 'image', [
				'image_src'      => $imageinfo->url,
				'commons_pageid' => $page->pageid
			] );

			$inserted = last_inserted_ID();

			foreach( $CONNOTATION_IDs as $CONNOTATION_ID ) {
				insert_row( 'image_connotation', [
					'image_ID'       => $inserted,
					'connotation_ID' => $CONNOTATION_ID,
				] );
			}

			query( 'COMMIT' );

			echo "created $page->pageid\n";
		}
	}


}
