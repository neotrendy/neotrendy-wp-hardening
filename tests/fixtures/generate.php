<?php
/**
 * Generates the image fixtures with metadata, using only PHP (GD, pack, iptcembed, crc32).
 *
 * Usage: php tests/fixtures/generate.php
 *
 * - gps.jpg:  400x200 JPEG, EXIF (Make, Orientation 6, GPS), XMP, IPTC. Displayed as 200x400.
 * - gps.png:  400x200 PNG, eXIf (Make, GPS), iTXt XMP, tEXt comment.
 * - gps.webp: 400x200 WebP, VP8X with EXIF (Make, GPS) and XMP chunks.
 *
 * The GPS position is the Royal Observatory, Greenwich (51°28'38" N, 0°0'0" E).
 *
 * @package NeotrendyWpHardening
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- Standalone CLI script without WordPress.

$dir = __DIR__;

/**
 * Builds a little-endian TIFF IFD with its data area.
 *
 * @param array $entries Entries as array( tag, type, count, packed value ).
 * @param int   $offset  Offset of this IFD from the start of the TIFF header.
 */
function nwph_fixture_ifd( array $entries, int $offset ): string {
	$sizes = array(
		1 => 1,
		2 => 1,
		3 => 2,
		4 => 4,
		5 => 8,
	);
	$head  = pack( 'v', count( $entries ) );
	$data  = '';
	$base  = $offset + 2 + 12 * count( $entries ) + 4;

	foreach ( $entries as list( $tag, $type, $count, $value ) ) {
		$head .= pack( 'vvV', $tag, $type, $count );

		if ( $sizes[ $type ] * $count <= 4 ) {
			$head .= str_pad( $value, 4, "\0" );
		} else {
			$head .= pack( 'V', $base + strlen( $data ) );
			$data .= $value . ( strlen( $value ) % 2 ? "\0" : '' );
		}
	}

	return $head . pack( 'V', 0 ) . $data;
}

/**
 * Builds TIFF-format EXIF data with Make, optional Orientation and GPS position.
 *
 * @param int|null $orientation EXIF orientation, or null to omit it.
 */
function nwph_fixture_exif( ?int $orientation ): string {
	$rational = static fn( int ...$values ) => implode( '', array_map( static fn( $v ) => pack( 'VV', $v, 1 ), $values ) );
	$ifd0     = static function ( int $gps_offset ) use ( $orientation ) {
		$entries = array( array( 0x010F, 2, 5, "NWPH\0" ) );
		if ( null !== $orientation ) {
			$entries[] = array( 0x0112, 3, 1, pack( 'v', $orientation ) );
		}
		$entries[] = array( 0x8825, 4, 1, pack( 'V', $gps_offset ) );
		return nwph_fixture_ifd( $entries, 8 );
	};

	$gps_offset = 8 + strlen( $ifd0( 0 ) );
	$gps        = nwph_fixture_ifd(
		array(
			array( 0x0000, 1, 4, "\x02\x02\x00\x00" ),
			array( 0x0001, 2, 2, "N\0" ),
			array( 0x0002, 5, 3, $rational( 51, 28, 38 ) ),
			array( 0x0003, 2, 2, "E\0" ),
			array( 0x0004, 5, 3, $rational( 0, 0, 0 ) ),
		),
		$gps_offset
	);

	return "II*\0" . pack( 'V', 8 ) . $ifd0( $gps_offset ) . $gps;
}

/**
 * Returns a minimal XMP packet.
 */
function nwph_fixture_xmp(): string {
	return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
		. '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:exif="http://ns.adobe.com/exif/1.0/">'
		. '<dc:creator><rdf:Seq><rdf:li>NWPH fixture</rdf:li></rdf:Seq></dc:creator><exif:GPSLatitude>51,28.633N</exif:GPSLatitude>'
		. '</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
}

/**
 * Returns encoded image bytes from GD: a 400x200 image with a red left half, so rotation is visible.
 *
 * @param string $format jpeg, png or webp.
 */
function nwph_fixture_pixels( string $format ): string {
	$image = imagecreatetruecolor( 400, 200 );
	imagefilledrectangle( $image, 0, 0, 399, 199, imagecolorallocate( $image, 40, 90, 200 ) );
	imagefilledrectangle( $image, 0, 0, 199, 199, imagecolorallocate( $image, 200, 40, 40 ) );
	ob_start();
	match ( $format ) {
		'jpeg' => imagejpeg( $image, null, 90 ),
		'png'  => imagepng( $image ),
		'webp' => imagewebp( $image, null, 90 ),
	};
	return (string) ob_get_clean();
}

// JPEG: APP1 EXIF and APP1 XMP after the JFIF APP0 segment, then APP13 IPTC via iptcembed().
$segment = static fn( string $marker, string $payload ) => $marker . pack( 'n', strlen( $payload ) + 2 ) . $payload;
$jpeg    = nwph_fixture_pixels( 'jpeg' );
$app0    = 4 + unpack( 'n', substr( $jpeg, 4, 2 ) )[1];
$jpeg    = substr( $jpeg, 0, $app0 )
	. $segment( "\xFF\xE1", "Exif\0\0" . nwph_fixture_exif( 6 ) )
	. $segment( "\xFF\xE1", "http://ns.adobe.com/xap/1.0/\0" . nwph_fixture_xmp() )
	. substr( $jpeg, $app0 );
$tmp     = tempnam( sys_get_temp_dir(), 'nwph' );
file_put_contents( $tmp, $jpeg );
$iptc = '';
foreach ( array(
	90  => 'Greenwich',
	120 => 'NWPH fixture caption',
) as $dataset => $value ) {
	$iptc .= "\x1C\x02" . chr( $dataset ) . pack( 'n', strlen( $value ) ) . $value;
}
file_put_contents( "$dir/gps.jpg", iptcembed( $iptc, $tmp ) );
unlink( $tmp );

// PNG: eXIf, iTXt (XMP) and tEXt chunks after IHDR.
$chunk = static fn( string $type, string $data ) => pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
$png   = nwph_fixture_pixels( 'png' );
$png   = substr( $png, 0, 33 )
	. $chunk( 'eXIf', nwph_fixture_exif( null ) )
	. $chunk( 'iTXt', "XML:com.adobe.xmp\0\0\0\0\0" . nwph_fixture_xmp() )
	. $chunk( 'tEXt', "Comment\0NWPH fixture" )
	. substr( $png, 33 );
file_put_contents( "$dir/gps.png", $png );

// WebP: extended format (VP8X) with EXIF and XMP chunks.
$riff  = static fn( string $type, string $data ) => $type . pack( 'V', strlen( $data ) ) . $data . ( strlen( $data ) % 2 ? "\0" : '' );
$webp  = nwph_fixture_pixels( 'webp' );
$frame = substr( $webp, 12 );
$vp8x  = $riff( 'VP8X', chr( 0x08 | 0x04 ) . "\0\0\0" . substr( pack( 'V', 399 ), 0, 3 ) . substr( pack( 'V', 199 ), 0, 3 ) );
$body  = 'WEBP' . $vp8x . $frame . $riff( 'EXIF', nwph_fixture_exif( null ) ) . $riff( 'XMP ', nwph_fixture_xmp() );
file_put_contents( "$dir/gps.webp", 'RIFF' . pack( 'V', strlen( $body ) ) . $body );

echo "Generated gps.jpg, gps.png and gps.webp in $dir\n";
