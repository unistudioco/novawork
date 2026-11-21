<?php
/**
 * Private file storage.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Services;

use NovaWork\Settings;
use WP_Error;

/**
 * Chat attachments are private to members.
 *
 * Files go in uploads/novawork/ under random 32-character names with no
 * extension, so nothing there can be guessed or executed. The folder carries
 * an index.php and Apache deny rules; on nginx (which ignores .htaccess) the
 * random names are the protection. Files are only ever served through the
 * authenticated REST route, and never added to the Media Library.
 */
final class Files {

	/**
	 * Folder name under uploads.
	 */
	public const FOLDER = 'novawork';

	/**
	 * Image types safe to show inline; everything else downloads.
	 */
	private const INLINE = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Absolute path of the private folder.
	 */
	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( (string) $uploads['basedir'] ) . self::FOLDER;
	}

	/**
	 * Create the folder and its protection files.
	 */
	public static function ensure_folder(): bool {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return true;
		}
		$files = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "# NovaWork: files are served only through the app.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
		);
		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! $wp_filesystem->exists( $path ) ) {
				$wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
			}
		}
		return true;
	}

	/**
	 * Store an uploaded file.
	 *
	 * @param array<string, mixed> $file     One entry of $_FILES (name, type, tmp_name, error, size).
	 * @param bool                 $sideload True for a file that did not arrive by HTTP upload (tests, imports).
	 * @return array{stored_name: string, file_name: string, mime: string, size: int}|WP_Error
	 */
	public static function store( array $file, bool $sideload = false ): array|WP_Error {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $error ) {
			$too_big = in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return new WP_Error(
				$too_big ? 'novawork_file_too_big' : 'novawork_upload_failed',
				$too_big ? self::too_big_message() : __( 'The file did not upload. Try again.', 'novawork' ),
				array( 'status' => 400 )
			);
		}

		$size = (int) ( $file['size'] ?? 0 );
		if ( $size > Settings::upload_max_bytes() ) {
			return new WP_Error( 'novawork_file_too_big', self::too_big_message(), array( 'status' => 413 ) );
		}

		// The stored name is random, so the original name is only a label:
		// keep it readable for people, and check the type on a strict copy.
		$label = sanitize_text_field( wp_basename( (string) ( $file['name'] ?? '' ) ) );
		$name  = sanitize_file_name( $label );
		$mimes = self::allowed_mimes();
		$check = wp_check_filetype_and_ext( (string) ( $file['tmp_name'] ?? '' ), $name, $mimes );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new WP_Error( 'novawork_file_type', __( 'This type of file can’t be shared here.', 'novawork' ), array( 'status' => 415 ) );
		}
		if ( ! empty( $check['proper_filename'] ) ) {
			$name  = (string) $check['proper_filename'];
			$label = $name;
		}

		if ( ! self::ensure_folder() ) {
			return new WP_Error( 'novawork_upload_failed', __( 'The upload folder isn’t writable.', 'novawork' ), array( 'status' => 500 ) );
		}

		$stored = bin2hex( random_bytes( 16 ) );
		// Resolve the path now: calling dir() inside the filter would re-enter
		// wp_upload_dir() and run this same filter forever.
		$dir    = self::dir();
		$folder = static function ( array $uploads ) use ( $dir ): array {
			$uploads['path']   = $dir;
			$uploads['url']    = '';
			$uploads['subdir'] = '';
			$uploads['error']  = false;
			return $uploads;
		};
		$rename = static fn(): string => $stored;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		add_filter( 'upload_dir', $folder, 999 );
		$file['name'] = $name;
		$overrides    = array(
			'test_form'                => false,
			'mimes'                    => $mimes,
			'unique_filename_callback' => $rename,
		);
		$result       = $sideload ? wp_handle_sideload( $file, $overrides ) : wp_handle_upload( $file, $overrides );
		remove_filter( 'upload_dir', $folder, 999 );

		if ( isset( $result['error'] ) || empty( $result['file'] ) ) {
			return new WP_Error( 'novawork_upload_failed', (string) ( $result['error'] ?? __( 'The file did not upload. Try again.', 'novawork' ) ), array( 'status' => 400 ) );
		}

		return array(
			'stored_name' => $stored,
			'file_name'   => '' === $label ? $name : $label,
			'mime'        => (string) $check['type'],
			'size'        => $size,
		);
	}

	/**
	 * Absolute path of a stored file, or '' for a malformed name.
	 *
	 * @param string $stored_name Random stored name.
	 */
	public static function path( string $stored_name ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $stored_name ) ) {
			return '';
		}
		return trailingslashit( self::dir() ) . $stored_name;
	}

	/**
	 * Delete a stored file.
	 *
	 * @param string $stored_name Random stored name.
	 */
	public static function remove( string $stored_name ): void {
		$path = self::path( $stored_name );
		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Stream a stored file to the browser and stop.
	 *
	 * @param array<string, mixed> $row Attachment row.
	 */
	public static function send( array $row ): void {
		$path = self::path( (string) $row['stored_name'] );
		if ( '' === $path || ! is_readable( $path ) ) {
			status_header( 404 );
			exit;
		}
		$mime   = (string) $row['mime'];
		$inline = in_array( $mime, self::INLINE, true );
		$name   = (string) $row['file_name'];

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: ' . ( $inline ? $mime : 'application/octet-stream' ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( array( '"', '\\', "\r", "\n" ), '', remove_accents( $name ) ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox" );
		header( 'Cache-Control: private, max-age=3600' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the file without loading it into memory; WP_Filesystem has no streaming read.
		readfile( $path );
		exit;
	}

	/**
	 * Allowed extensions mapped to MIME types: the NovaWork setting,
	 * narrowed to what this site lets the current user upload.
	 *
	 * @return array<string, string>
	 */
	public static function allowed_mimes(): array {
		$allowed = Settings::upload_types();
		$out     = array();
		foreach ( get_allowed_mime_types() as $exts => $mime ) {
			$keep = array_values( array_intersect( explode( '|', (string) $exts ), $allowed ) );
			if ( $keep ) {
				$out[ implode( '|', $keep ) ] = (string) $mime;
			}
		}
		return $out;
	}

	/**
	 * "Files can be up to N MB."
	 */
	private static function too_big_message(): string {
		return sprintf(
			/* translators: %s: maximum upload size, e.g. "10 MB". */
			__( 'Files can be up to %s.', 'novawork' ),
			size_format( Settings::upload_max_bytes() )
		);
	}
}
