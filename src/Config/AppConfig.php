<?php

namespace ROOTS\Config;

class AppConfig
{
    // Application settings
    const MAX_COMMENTS_PER_PAGE = 50;
    const SITE_NAME = 'Comment System';

    // Privacy default settings
    const NAME_PRIVACY_DEFAULT = 'partial'; // Options: 'none', 'partial', 'full'
    const CENSOR_COMMENTS_DEFAULT = true;

    /**
     * Initialize application settings
     */
    public static function init(): void
    {
        // Load environment variables
        EnvLoader::load();

        // Enable error reporting in development, disable in production
        error_reporting(getenv('ENVIRONMENT') === 'production' ? 0 : E_ALL);
        ini_set('display_errors', getenv('ENVIRONMENT') === 'production' ? '0' : '1');
    }
}
