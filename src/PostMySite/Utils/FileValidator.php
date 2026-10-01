<?php


namespace Roots\PostMySite\Utils;

use Exception;

// Define constant if not already defined (or handle it inside class)
if (!defined('MIME_TYPE_JPEG')) {
    define('MIME_TYPE_JPEG', 'image/jpeg');
}

class FileValidator
{

    /** @var array<int, string> */
    private $dangerousExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar',
        'asp', 'aspx', 'jsp', 'jspx',
        'sh', 'bash', 'bat', 'cmd', 'com', 'exe', 'scr', 'vbs',
        'pl', 'py', 'rb', 'perl',
        'htaccess', 'htpasswd'
    ];

    /** @var array<int, string> */
    private $allowedExtensions = [
        'pdf',
        'doc',
        'docx',
        'txt',
        'rtf',
        'odt',
        'jpg',
        'jpeg',
        'png',
        'gif',
        'bmp',
        'webp',
        'svg',
        'zip',
        'rar',
        '7z',
        'tar',
        'gz',
        'sql',
        'json',
        'xml',
        'csv',
        'yml',
        'yaml',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'mp3',
        'wav',
        'ogg',
        'flac',
        'mp4',
        'avi',
        'mov',
        'mkv',
        'wmv',
        'msi',
        'apk',
        'deb',
        'rpm',
        'js',
        'css',
        'html',
        'java',
        'cpp',
        'c',
        'h',
        'psd',
        'ai',
        'sketch',
        'fig'
    ];

    /** @var int */
    private $maxFileSize = 100 * 1024 * 1024;

    /** @param array<string, mixed> $file */
    public function validateFile(array $file): bool
    {
        // Security: Check if file was actually uploaded
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Invalid file upload');
        }

        $fileSize = $file['size'];
        $fileName = $file['name'];
        $fileTmpName = $file['tmp_name'];

        // Security: Validate file name (prevent directory traversal)
        $fileName = basename($fileName);
        if (preg_match('/[\/\\\\]/', $fileName)) {
            throw new Exception('Invalid file name: directory traversal detected');
        }

        // Security: Check for null bytes
        if (strpos($fileName, "\0") !== false) {
            throw new Exception('Invalid file name: null byte detected');
        }

        if ($fileSize <= 0) {
            throw new Exception('File is empty');
        }

        if ($fileSize > $this->maxFileSize) {
            throw new Exception('File size too large (Maximum 100MB)');
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Security: Block dangerous extensions
        if (in_array($extension, $this->dangerousExtensions)) {
            throw new Exception('File type not allowed for security reasons');
        }

        if (!in_array($extension, $this->allowedExtensions)) {
            throw new Exception('File type not supported');
        }

        // Security: Double-check extension (prevent double extensions like file.php.jpg)
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        if (preg_match('/\.(php|phtml|php3|php4|php5|asp|jsp|sh|bat|exe|pl|py|rb)/i', $baseName)) {
            throw new Exception('File name contains dangerous patterns');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = '';
        if ($finfo !== false) {
            $mimeType = finfo_file($finfo, $fileTmpName);
            finfo_close($finfo);
        }

        // Security: Validate MIME type matches extension
        if (!$this->isValidMimeType($mimeType !== false ? $mimeType : '', $extension)) {
            throw new Exception('File type does not match extension');
        }

        // Security: Additional check for executable content
        if (in_array($mimeType, ['application/x-executable', 'application/x-sharedlib', 'application/x-msdownload'])) {
            throw new Exception('Executable files are not allowed');
        }

        return true;
    }

    private function isValidMimeType(string $mimeType, string $extension): bool
    {
        $validMimeTypes = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'txt' => ['text/plain'],
            'jpg' => [MIME_TYPE_JPEG],
            'jpeg' => [MIME_TYPE_JPEG],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'rar' => ['application/x-rar-compressed', 'application/vnd.rar'],
            'mp3' => ['audio/mpeg'],
            'mp4' => ['video/mp4'],
        ];

        if (isset($validMimeTypes[$extension])) {
            return in_array($mimeType, $validMimeTypes[$extension]);
        }

        return true;
    }

    /** @param array<string, mixed> $file */
    public function validatePreviewImage(array $file): bool
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Invalid preview image');
        }

        $fileSize = $file['size'];
        $maxPreviewSize = 5 * 1024 * 1024;

        if ($fileSize > $maxPreviewSize) {
            throw new Exception('Preview image size too large (Maximum 5MB)');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = '';
        if ($finfo !== false) {
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }

        $allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if ($mimeType === false || !in_array($mimeType, $allowedImageTypes)) {
            throw new Exception('Preview image must be JPG, PNG, GIF, or WEBP');
        }

        return true;
    }
}
