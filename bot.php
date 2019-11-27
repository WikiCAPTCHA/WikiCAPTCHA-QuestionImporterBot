<?php
# WikiCAPTCHA Question Importer bot
# Copyright (C) 2019 Valerio Bozzolan and contributors
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program. If not, see <http://www.gnu.org/licenses/>.

require 'load.php';

// this script is suitable for Wikimedia commons
$wiki = \wm\Commons::instance();

// the Category name can be provided as first argument
$CATEGORY_NAME = $argv[1] ?? null;

// no Category name no party
while( empty( $CATEGORY_NAME ) ) {
	$CATEGORY_NAME = readline( "Digit a Wikimedia Commons category page title (example: Category:Barack Obama): \n" );
}

// get all the existing connotations
$connotations = ( new Query() )
	->from( 'connotation' )
	->queryGenerator();

// ask the User to connect these photos to some connotations
$CONNOTATION_IDs = [];
foreach( $connotations as $connotation ) {

	// cast some attributes
	$connotation->integers( 'connotation_ID' );

	// read Connotation attributes
	$connotation_ID      = $connotation->get( 'connotation_ID'      );
	$connotation_comment = $connotation->get( 'connotation_comment' );

	// ask if this Connotation connotates the files in that Category
	$line = null;
	do {
		if( $line !== null ) {
			echo "What?\n";
		}

		$line = readline( "Are photos in '$CATEGORY_NAME' $connotation_comment? (y/n/stop) " );
	} while( $line !== 'stop' && $line !== 'y' && $line !== 'n' );

	// assign the statement
	if( $line !== 'stop' ) {
		$CONNOTATION_IDs[ $connotation_ID ] = $line === 'y' ? 'positive' : 'negative';
	}
}

$queries =
	$wiki->createQuery( [
		'action'     => 'query',
		'generator'  => 'categorymembers',
		'prop'       => 'imageinfo',
		'iiprop'     => [ 'url', 'mime' ],
		'iiurlwidth' => 100,
		'gcmtitle'   => $CATEGORY_NAME,
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

		$thumburl = $imageinfo->thumburl;
		if( !$thumburl ) {
			$continue;
		}

		$image = ( new Query() )
			->from( 'image' )
			->whereInt( 'commons_pageid', $page->pageid )
			->queryRow();

		if( $image ) {

			// try to update existing image

			( new Query() )
				->from( 'image' )
				->whereInt( 'image_ID', $image->image_ID )
				->update( [
					'image_src' => $thumburl,
				] );

			foreach( $CONNOTATION_IDs as $CONNOTATION_ID => $positive ) {
				// try to relate if possible
				try {
					insert_row( 'image_connotation', [
						'image_ID'             => $image->image_ID,
						'connotation_ID'       => $CONNOTATION_ID,
						'image_connotation_positive' => $positive,
					] );
					echo "Updated\n";
				} catch( Exception $e ) {
					echo "Already added\n";
				}
			}

		} elseif( $CONNOTATION_IDs ) {

			// create a new Image

			query( 'START TRANSACTION' );

			insert_row( 'image', [
				'image_src'      => $thumburl,
				'commons_pageid' => $page->pageid
			] );

			$inserted = last_inserted_ID();

			foreach( $CONNOTATION_IDs as $CONNOTATION_ID => $positive ) {
				insert_row( 'image_connotation', [
					'image_ID'             => $inserted,
					'connotation_ID'       => $CONNOTATION_ID,
					'image_connotation_positive' => $positive,
				] );
			}

			query( 'COMMIT' );

			echo "created $page->pageid\n";
		}
	}
}
