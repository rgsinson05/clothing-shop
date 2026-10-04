<?php

/*
 * Shared secure image upload helper.
 *
 * Encapsulates the project's existing image upload conventions, the same
 * rules the admin product image flow in admin/product-create.php and
 * admin/product-edit.php already applies:
 *
 *   * Only JPEG, PNG, and WebP are accepted, validated with finfo against
 *     the ACTUAL file contents. The client-provided MIME type and the
 *     original filename extension are never trusted.
 *   * A 5 MB maximum size, consistent with the product image flow.
 *   * A random hex filename with an extension derived from the validated
 *     MIME type. The original filename is never reused, so path traversal
 *     and executable/script uploads through the filename are not possible.
 *   * Files are stored under images/<subdirectory>/ and only the relative
 *     path is returned, so the server filesystem path is never exposed.
 *
 * The caller receives the stored relative path (for example
 * "images/feedback/ab12...png") or null when no file was uploaded. A
 * validation problem raises InvalidArgumentException and a storage problem
 * raises RuntimeException, each with a short user-safe message that never
 * contains a filesystem path.
 */

/**
 * Validate and store a single optional uploaded image.
 *
 * @param array<string, mixed>|null $file         One entry from $_FILES.
 * @param string                    $subdirectory Target folder under images/.
 *
 * @return string|null The stored relative path, or null when no file was uploaded.
 *
 * @throws InvalidArgumentException When the uploaded file is not a supported image.
 * @throws RuntimeException         When a valid image cannot be stored.
 */
function imageUploadStore(?array $file, string $subdirectory): ?string
{
    /*
     * The photo is optional. A missing file field, or an explicitly empty
     * one, is not an error: the caller simply stores no path.
     */
    if (
        $file === null
        || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        || ($file['name'] ?? '') === ''
    ) {
        return null;
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The photo could not be uploaded. Please try again.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    /*
     * is_uploaded_file() guarantees the file genuinely arrived through an
     * HTTP POST upload, so neither a caller nor a crafted request can point
     * this at an arbitrary file already on the server.
     */
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new InvalidArgumentException('The photo could not be uploaded. Please try again.');
    }

    $maxBytes = 5 * 1024 * 1024; // 5 MB, matching the product image flow.

    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('The photo must be 5 MB or smaller.');
    }

    /*
     * Validate the ACTUAL file contents. The filename extension and the
     * browser-supplied MIME type are never trusted.
     */
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpName);

    if (!is_string($mimeType) || !isset($allowedTypes[$mimeType])) {
        throw new InvalidArgumentException('The photo must be a JPG, PNG, or WebP image.');
    }

    /*
     * Resolve a safe target directory under images/. The subdirectory is a
     * fixed code-literal supplied by the caller, but it is still constrained
     * to a single safe path segment to rule out traversal.
     */
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $subdirectory)) {
        throw new RuntimeException('The photo could not be saved. Please try again.');
    }

    $targetDirectory = __DIR__ . '/../images/' . $subdirectory;

    if (
        !is_dir($targetDirectory)
        && !mkdir($targetDirectory, 0755, true)
        && !is_dir($targetDirectory)
    ) {
        throw new RuntimeException('The photo could not be saved. Please try again.');
    }

    /*
     * A random filename with an extension derived from the validated MIME
     * type. The original filename is discarded entirely.
     */
    $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    $destination = $targetDirectory . '/' . $filename;

    /*
     * move_uploaded_file() re-verifies that the source is a genuine upload
     * before moving it into place.
     */
    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('The photo could not be saved. Please try again.');
    }

    // Only the relative path is returned; the absolute path is never exposed.
    return 'images/' . $subdirectory . '/' . $filename;
}
