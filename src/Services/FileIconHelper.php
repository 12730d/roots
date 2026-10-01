<?php


namespace ROOTS\Services;

class FileIconHelper
{
    const ICON_FILE_WORD = 'fas fa-file-word';
    const ICON_FILE_IMAGE = 'fas fa-file-image';
    const ICON_FILE_ZIPPER = 'fas fa-file-zipper';
    const ICON_FILE_EXCEL = 'fas fa-file-excel';
    const ICON_FILE_POWERPOINT = 'fas fa-file-powerpoint';
    const ICON_FILE_VIDEO = 'fas fa-file-video';
    const ICON_FILE_AUDIO = 'fas fa-file-audio';
    const ICON_FILE_CODE = 'fas fa-file-code';
    const ICON_FILE_ARROW_DOWN = 'fas fa-file-arrow-down';
    const ICON_FONT = 'fas fa-font';

    const COLOR_RED = '#dc3545';
    const COLOR_BLUE = '#2b579a';
    const COLOR_GREEN = '#28a745';
    const COLOR_ORANGE = '#fd7e14';
    const COLOR_DARK_GREEN = '#198754';
    const COLOR_PINK = '#d63384';
    const COLOR_LIGHT_BLUE = '#0d6efd';
    const COLOR_PURPLE = '#6f42c1';
    const COLOR_TEAL = '#20c997';
    const COLOR_YELLOW = '#ffc107';

    public static function getFileType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $types = [
            'documents' => ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt'],
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'tiff', 'ico'],
            'archives' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'],
            'spreadsheets' => ['xls', 'xlsx', 'csv', 'ods'],
            'presentations' => ['ppt', 'pptx', 'odp'],
            'videos' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', '3gp', 'm4v'],
            'audio' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a'],
            'code' => ['html', 'htm', 'css', 'js', 'php', 'py', 'java', 'cpp', 'c', 'sql', 'json', 'xml', 'yaml', 'yml'],
            'executables' => ['exe', 'msi', 'deb', 'rpm', 'dmg', 'apk', 'ipa'],
            'fonts' => ['ttf', 'otf', 'woff', 'woff2']
        ];

        foreach ($types as $type => $extensions) {
            if (in_array($ext, $extensions)) {
                return $type;
            }
        }

        return 'other';
    }

    public static function getFileTypeArabic(string $type): string
    {
        $translations = [
            'documents' => 'Document',
            'images' => 'Image',
            'archives' => 'Compressed File',
            'spreadsheets' => 'Spreadsheet',
            'presentations' => 'Presentation',
            'videos' => 'Video',
            'audio' => 'Audio File',
            'code' => 'Code File',
            'executables' => 'Executable File',
            'fonts' => 'Font',
            'other' => 'Other File'
        ];

        return $translations[$type] ?? 'Other File';
    }

    public static function getFileIcon(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $icons = [
            'pdf' => 'fas fa-file-pdf',
            'doc' => self::ICON_FILE_WORD,
            'docx' => self::ICON_FILE_WORD,
            'txt' => 'fas fa-file-alt',
            'rtf' => self::ICON_FILE_WORD,
            'jpg' => self::ICON_FILE_IMAGE,
            'jpeg' => self::ICON_FILE_IMAGE,
            'png' => self::ICON_FILE_IMAGE,
            'gif' => self::ICON_FILE_IMAGE,
            'bmp' => self::ICON_FILE_IMAGE,
            'svg' => self::ICON_FILE_IMAGE,
            'webp' => self::ICON_FILE_IMAGE,
            'tiff' => self::ICON_FILE_IMAGE,
            'ico' => self::ICON_FILE_IMAGE,
            'zip' => self::ICON_FILE_ZIPPER,
            'rar' => self::ICON_FILE_ZIPPER,
            '7z' => self::ICON_FILE_ZIPPER,
            'tar' => self::ICON_FILE_ZIPPER,
            'gz' => self::ICON_FILE_ZIPPER,
            'bz2' => self::ICON_FILE_ZIPPER,
            'xls' => self::ICON_FILE_EXCEL,
            'xlsx' => self::ICON_FILE_EXCEL,
            'csv' => 'fas fa-file-csv',
            'ods' => self::ICON_FILE_EXCEL,
            'ppt' => self::ICON_FILE_POWERPOINT,
            'pptx' => self::ICON_FILE_POWERPOINT,
            'odp' => self::ICON_FILE_POWERPOINT,
            'mp4' => self::ICON_FILE_VIDEO,
            'avi' => self::ICON_FILE_VIDEO,
            'mov' => self::ICON_FILE_VIDEO,
            'wmv' => self::ICON_FILE_VIDEO,
            'flv' => self::ICON_FILE_VIDEO,
            'mkv' => self::ICON_FILE_VIDEO,
            'webm' => self::ICON_FILE_VIDEO,
            '3gp' => self::ICON_FILE_VIDEO,
            'm4v' => self::ICON_FILE_VIDEO,
            'mp3' => self::ICON_FILE_AUDIO,
            'wav' => self::ICON_FILE_AUDIO,
            'flac' => self::ICON_FILE_AUDIO,
            'aac' => self::ICON_FILE_AUDIO,
            'ogg' => self::ICON_FILE_AUDIO,
            'wma' => self::ICON_FILE_AUDIO,
            'm4a' => self::ICON_FILE_AUDIO,
            'html' => self::ICON_FILE_CODE,
            'htm' => self::ICON_FILE_CODE,
            'css' => self::ICON_FILE_CODE,
            'js' => self::ICON_FILE_CODE,
            'php' => self::ICON_FILE_CODE,
            'py' => self::ICON_FILE_CODE,
            'java' => self::ICON_FILE_CODE,
            'cpp' => self::ICON_FILE_CODE,
            'c' => self::ICON_FILE_CODE,
            'sql' => 'fas fa-database',
            'json' => self::ICON_FILE_CODE,
            'xml' => self::ICON_FILE_CODE,
            'yaml' => self::ICON_FILE_CODE,
            'yml' => self::ICON_FILE_CODE,
            'exe' => self::ICON_FILE_ARROW_DOWN,
            'msi' => self::ICON_FILE_ARROW_DOWN,
            'deb' => self::ICON_FILE_ARROW_DOWN,
            'rpm' => self::ICON_FILE_ARROW_DOWN,
            'dmg' => self::ICON_FILE_ARROW_DOWN,
            'apk' => 'fas fa-mobile-alt',
            'ipa' => 'fas fa-mobile-alt',
            'ttf' => self::ICON_FONT,
            'otf' => self::ICON_FONT,
            'woff' => self::ICON_FONT,
            'woff2' => self::ICON_FONT,
            'eot' => self::ICON_FONT,
            'iso' => 'fas fa-compact-disc',
            'torrent' => 'fas fa-share-alt',
        ];
        return $icons[$ext] ?? 'fas fa-file';
    }

    public static function getFileIconColor(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $colors = [
            'pdf' => self::COLOR_RED,
            'doc' => self::COLOR_BLUE,
            'docx' => self::COLOR_BLUE,
            'rtf' => self::COLOR_BLUE,
            'txt' => '#6c757d',
            'jpg' => self::COLOR_GREEN,
            'jpeg' => self::COLOR_GREEN,
            'png' => self::COLOR_GREEN,
            'gif' => self::COLOR_GREEN,
            'bmp' => self::COLOR_GREEN,
            'svg' => self::COLOR_GREEN,
            'webp' => self::COLOR_GREEN,
            'tiff' => self::COLOR_GREEN,
            'ico' => self::COLOR_GREEN,
            'zip' => self::COLOR_ORANGE,
            'rar' => self::COLOR_ORANGE,
            '7z' => self::COLOR_ORANGE,
            'tar' => self::COLOR_ORANGE,
            'gz' => self::COLOR_ORANGE,
            'bz2' => self::COLOR_ORANGE,
            'xls' => self::COLOR_DARK_GREEN,
            'xlsx' => self::COLOR_DARK_GREEN,
            'csv' => self::COLOR_DARK_GREEN,
            'ods' => self::COLOR_DARK_GREEN,
            'ppt' => self::COLOR_PINK,
            'pptx' => self::COLOR_PINK,
            'odp' => self::COLOR_PINK,
            'mp4' => self::COLOR_LIGHT_BLUE,
            'avi' => self::COLOR_LIGHT_BLUE,
            'mov' => self::COLOR_LIGHT_BLUE,
            'wmv' => self::COLOR_LIGHT_BLUE,
            'flv' => self::COLOR_LIGHT_BLUE,
            'mkv' => self::COLOR_LIGHT_BLUE,
            'webm' => self::COLOR_LIGHT_BLUE,
            '3gp' => self::COLOR_LIGHT_BLUE,
            'm4v' => self::COLOR_LIGHT_BLUE,
            'mp3' => self::COLOR_PURPLE,
            'wav' => self::COLOR_PURPLE,
            'flac' => self::COLOR_PURPLE,
            'aac' => self::COLOR_PURPLE,
            'ogg' => self::COLOR_PURPLE,
            'wma' => self::COLOR_PURPLE,
            'm4a' => self::COLOR_PURPLE,
            'html' => self::COLOR_TEAL,
            'htm' => self::COLOR_TEAL,
            'css' => self::COLOR_TEAL,
            'js' => self::COLOR_TEAL,
            'php' => self::COLOR_TEAL,
            'py' => self::COLOR_TEAL,
            'java' => self::COLOR_TEAL,
            'cpp' => self::COLOR_TEAL,
            'c' => self::COLOR_TEAL,
            'json' => self::COLOR_TEAL,
            'xml' => self::COLOR_TEAL,
            'yaml' => self::COLOR_TEAL,
            'yml' => self::COLOR_TEAL,
            'sql' => '#17a2b8',
            'exe' => self::COLOR_RED,
            'msi' => self::COLOR_RED,
            'deb' => self::COLOR_RED,
            'rpm' => self::COLOR_RED,
            'dmg' => self::COLOR_RED,
            'apk' => self::COLOR_GREEN,
            'ipa' => self::COLOR_GREEN,
            'ttf' => self::COLOR_YELLOW,
            'otf' => self::COLOR_YELLOW,
            'woff' => self::COLOR_YELLOW,
            'woff2' => self::COLOR_YELLOW,
            'eot' => self::COLOR_YELLOW,
            'iso' => '#6c757d',
            'torrent' => '#dc3545',
        ];
        return $colors[$ext] ?? 'var(--primary-green)';
    }

    public static function displayFileIcon(string $filename, string $size = '1.2rem', bool $showShadow = true): string
    {
        $icon = self::getFileIcon($filename);
        $color = self::getFileIconColor($filename);
        $shadowStyle = $showShadow ? "filter: drop-shadow(0 0 6px {$color}33);" : '';

        return "<i class=\"{$icon}\" style=\"color: {$color}; font-size: {$size}; {$shadowStyle}\"></i>";
    }

    public static function displayFileNameWithIcon(string $filename, int $maxLength = 50): string
    {
        $displayName = strlen($filename) > $maxLength ? substr($filename, 0, $maxLength) . '...' : $filename;
        $icon = self::displayFileIcon($filename);

        return "<span class=\"file-name-with-icon\">{$icon} {$displayName}</span>";
    }

    public static function getFileCategory(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $categories = [
            'document' => ['pdf', 'doc', 'docx', 'txt', 'rtf'],
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'tiff', 'ico'],
            'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'],
            'spreadsheet' => ['xls', 'xlsx', 'csv', 'ods'],
            'presentation' => ['ppt', 'pptx', 'odp'],
            'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', '3gp', 'm4v'],
            'audio' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a'],
            'code' => ['html', 'htm', 'css', 'js', 'php', 'py', 'java', 'cpp', 'c', 'json', 'xml', 'yaml', 'yml', 'sql'],
            'executable' => ['exe', 'msi', 'deb', 'rpm', 'dmg', 'apk', 'ipa'],
            'font' => ['ttf', 'otf', 'woff', 'woff2', 'eot'],
            'disk' => ['iso', 'dmg'],
            'torrent' => ['torrent']
        ];

        foreach ($categories as $category => $extensions) {
            if (in_array($ext, $extensions)) {
                return $category;
            }
        }

        return 'other';
    }

    public static function getFileCategoryName(string $filename): string
    {
        $category = self::getFileCategory($filename);

        $names = [
            'document' => 'Documents',
            'image' => 'Images',
            'archive' => 'Archive',
            'spreadsheet' => 'Spreadsheets',
            'presentation' => 'Presentations',
            'video' => 'Video',
            'audio' => 'Audio',
            'code' => 'Programming',
            'executable' => 'Applications',
            'font' => 'Fonts',
            'disk' => 'Disks',
            'torrent' => 'Torrent',
            'other' => 'Other'
        ];

        return $names[$category] ?? 'Undefined';
    }

    public static function getFileIconCSS(): string
    {
        return '
        <style>
        .file-name-with-icon {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }
        .file-icon-large { font-size: 2.5rem !important; margin-bottom: 10px; }
        .file-icon-medium { font-size: 1.8rem !important; }
        .file-icon-small { font-size: 1rem !important; }
        .file-category-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        </style>';
    }

    public static function formatFileSize(int $bytes): string
    {
        $result = $bytes . ' bytes';

        if ($bytes >= 1073741824) {
            $result = number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            $result = number_format($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            $result = number_format($bytes / 1024, 1) . ' KB';
        }

        return $result;
    }

    public static function timeAgo(string $datetime): string
    {
        $time = time() - strtotime($datetime);
        $result = floor($time / 31536000) . ' years';

        if ($time < 60) {
            $result = 'Now';
        } elseif ($time < 3600) {
            $result = floor($time / 60) . ' minutes';
        } elseif ($time < 86400) {
            $result = floor($time / 3600) . ' hours';
        } elseif ($time < 2592000) {
            $result = floor($time / 86400) . ' days';
        } elseif ($time < 31536000) {
            $result = floor($time / 2592000) . ' months';
        }

        return $result;
    }
}
