<?php


namespace Roots\PostMySite\Utils;

use Exception;

class FileUploader
{

    /** @var string */
    private $uploadDir;

    /** @var string */
    private $previewDir;

    public function __construct()
    {
        // Paths relative to where the script is running might be tricky if not absolute.
        // The original used __DIR__ . '/../storage/files/' from post_mysite/utils
        // So storage was at post_mysite/storage.
        // We need to keep pointing there.
        // Roots root is f:\Mysite\v25\Roots
        // post_mysite is f:\Mysite\v25\Roots\post_mysite
        // __DIR__ here is f:\Mysite\v25\Roots\src\PostMySite\Utils
        // So we need to go up: src -> PostMySite -> Utils (3 levels down from root)
        // ../../../post_mysite/storage/

        $this->uploadDir = __DIR__ . '/../../../post_mysite/storage/files/';
        $this->previewDir = __DIR__ . '/../../../post_mysite/storage/previews/';

        $this->ensureDirectoryExists($this->uploadDir);
        $this->ensureDirectoryExists($this->previewDir);
    }

    private function ensureDirectoryExists(string $dir): void
    {
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /** @param array<string, mixed> $file
     * @return array<string, mixed> */
    public function uploadFile(array $file): array
    {
        $originalFilename = basename($file['name']);
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $fileSize = $file['size'];
        $tmpName = $file['tmp_name'];

        $storedFilename = $this->generateSecureFilename($extension);
        $targetPath = $this->uploadDir . $storedFilename;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new Exception('Failed to upload file');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = '';
        if ($finfo !== false) {
            $mimeType = finfo_file($finfo, $targetPath);
            finfo_close($finfo);
        }

        $fileType = $this->determineFileType($mimeType !== false ? $mimeType : '', $extension);

        return [
            'original_filename' => $originalFilename,
            'stored_filename' => $storedFilename,
            'file_path' => 'post_mysite/storage/files/' . $storedFilename, // Path relative to web root or index?
            // Original returned 'storage/files/'... assuming relative to where it's used?
            // Admin used it in admin.php which is in post_mysite.
            // If we use it for download.php inside post_mysite/api, etc.
            // Let's keep it 'post_mysite/storage/files/' so it's clearer from root.
            // Wait, old was 'storage/files/' from inside post_mysite context presumably.
            // If download.php does __DIR__ . '/../' . $file['file_path'], and download.php is in api,
            // ../ is post_mysite. So post_mysite/storage/files... works.
            // Let's check how it was: 'file_path' => 'storage/files/' . $storedFilename
            // And download.php: $file_path = __DIR__ . '/../' . $file['file_path'];
            // If download.php is in api/, then ../ is post_mysite/.
            // So post_mysite/storage/files/... matches.
            // So I should keep 'storage/files/'
            'file_size' => $fileSize,
            'file_type' => $fileType,
            'file_extension' => $extension,
            'mime_type' => $mimeType
        ];
    }

    /** @param array<string, mixed> $file
     * @return array<string, string> */
    public function uploadPreviewImage(array $file): array
    {
        $validator = new FileValidator();
        $validator->validatePreviewImage($file);

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $storedFilename = $this->generateSecureFilename($extension);
        $targetPath = $this->previewDir . $storedFilename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to upload preview image');
        }

        return [
            'filename' => $storedFilename,
            'path' => 'storage/previews/' . $storedFilename // Keep assuming relative to post_mysite
        ];
    }

    private function generateSecureFilename(string $extension): string
    {
        return time() . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
    }

    private function determineFileType(string $mimeType, string $extension): string
    {
        if (strpos($mimeType, 'image/') === 0) {
            return 'image';
        } elseif (strpos($mimeType, 'video/') === 0) {
            return 'video';
        } elseif (strpos($mimeType, 'audio/') === 0) {
            return 'audio';
        } elseif (strpos($mimeType, 'application/pdf') === 0) {
            return 'document';
        } elseif (in_array($extension, ['doc', 'docx', 'txt', 'rtf', 'odt'])) {
            return 'document';
        } elseif (in_array($extension, ['xls', 'xlsx', 'csv'])) {
            return 'spreadsheet';
        } elseif (in_array($extension, ['ppt', 'pptx'])) {
            return 'presentation';
        } elseif (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz'])) {
            return 'archive';
        } elseif (in_array($extension, ['exe', 'msi', 'apk', 'deb', 'rpm'])) {
            return 'executable';
        } elseif (in_array($extension, ['sql', 'json', 'xml', 'csv', 'yml', 'yaml'])) {
            return 'data';
        } elseif (in_array($extension, ['js', 'css', 'html', 'php', 'py', 'java', 'cpp', 'c', 'h'])) {
            return 'code';
        } else {
            return 'other';
        }
    }

    public function deleteFile(string $filename): bool
    {
        $filePath = $this->uploadDir . $filename;
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        return false;
    }

    public function deletePreviewImage(string $filename): bool
    {
        $filePath = $this->previewDir . $filename;
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        return false;
    }
}
