<?php
/**
 * Prints image size and the metadata blocks found in JPEG, PNG and WebP files.
 *
 * Usage: php tests/metadata.php <file> [<file> ...]
 * Output per file: <basename> <width>x<height> <comma-separated metadata or "none">
 * Metadata names: exif, gps, xmp, iptc, icc, text (PNG text chunks).
 *
 * @package NeotrendyWpHardening
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- Standalone CLI script.

/**
 * Lists metadata in JPEG segments.
 *
 * @param string $data File contents.
 */
function nwph_jpeg_metadata( string $data ): array {
	$found  = array();
	$pos    = 2;
	$length = strlen( $data );

	while ( $pos + 4 <= $length && "\xFF" === $data[ $pos ] ) {
		$marker = ord( $data[ $pos + 1 ] );
		if ( 0xDA === $marker || 0xD9 === $marker ) {
			break;
		}
		$size    = unpack( 'n', substr( $data, $pos + 2, 2 ) )[1];
		$payload = substr( $data, $pos + 4, $size - 2 );

		if ( 0xE1 === $marker && str_starts_with( $payload, "Exif\0" ) ) {
			$found[] = 'exif';
		} elseif ( 0xE1 === $marker && str_contains( $payload, 'http://ns.adobe.com/xap/1.0/' ) ) {
			$found[] = 'xmp';
		} elseif ( 0xED === $marker ) {
			$found[] = 'iptc';
		} elseif ( 0xE2 === $marker && str_starts_with( $payload, 'ICC_PROFILE' ) ) {
			$found[] = 'icc';
		}
		$pos += 2 + $size;
	}

	return $found;
}

/**
 * Lists metadata in PNG chunks.
 *
 * @param string $data File contents.
 */
function nwph_png_metadata( string $data ): array {
	$found = array();
	$map   = array(
		'eXIf' => 'exif',
		'iCCP' => 'icc',
		'tEXt' => 'text',
		'zTXt' => 'text',
		'iTXt' => 'text',
	);
	$pos   = 8;
	$end   = strlen( $data );

	while ( $pos + 8 <= $end ) {
		$length = unpack( 'N', substr( $data, $pos, 4 ) )[1];
		$type   = substr( $data, $pos + 4, 4 );
		if ( 'iTXt' === $type && str_starts_with( substr( $data, $pos + 8, $length ), 'XML:com.adobe.xmp' ) ) {
			$found[] = 'xmp';
		} elseif ( isset( $map[ $type ] ) ) {
			$found[] = $map[ $type ];
		}
		$pos += 12 + $length;
	}

	return $found;
}

/**
 * Lists metadata in WebP (RIFF) chunks.
 *
 * @param string $data File contents.
 */
function nwph_webp_metadata( string $data ): array {
	$found = array();
	$map   = array(
		'EXIF' => 'exif',
		'XMP ' => 'xmp',
		'ICCP' => 'icc',
	);
	$pos   = 12;
	$end   = strlen( $data );

	while ( $pos + 8 <= $end ) {
		$type   = substr( $data, $pos, 4 );
		$length = unpack( 'V', substr( $data, $pos + 4, 4 ) )[1];
		if ( isset( $map[ $type ] ) ) {
			$found[] = $map[ $type ];
		}
		$pos += 8 + $length + ( $length % 2 );
	}

	return $found;
}

$exit_code = 0;

foreach ( array_slice( $argv, 1 ) as $file ) {
	$data = is_readable( $file ) ? file_get_contents( $file ) : false;
	$size = $data ? getimagesize( $file ) : false;

	if ( ! $data || ! $size ) {
		echo basename( $file ), " unreadable\n";
		$exit_code = 1;
		continue;
	}

	$found = match ( $size['mime'] ) {
		'image/jpeg' => nwph_jpeg_metadata( $data ),
		'image/png'  => nwph_png_metadata( $data ),
		'image/webp' => nwph_webp_metadata( $data ),
		default      => array( 'unsupported' ),
	};

	if ( 'image/jpeg' === $size['mime'] && function_exists( 'exif_read_data' ) ) {
		$exif = @exif_read_data( $file, 'GPS' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $exif ) {
			$found[] = 'gps';
		}
	} elseif ( in_array( 'exif', $found, true ) && str_contains( $data, pack( 'v', 0x8825 ) . pack( 'v', 4 ) ) ) {
		$found[] = 'gps';
	}

	$found = array_values( array_unique( $found ) );
	echo basename( $file ), ' ', $size[0], 'x', $size[1], ' ', $found ? implode( ',', $found ) : 'none', "\n";
}

exit( $exit_code );
