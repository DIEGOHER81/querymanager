<?php

define('APP_NAME', 'PHPAdmin - Query Manager');
define('APP_VERSION', '1.1.0');
define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
define('CONFIG_DIR', APP_ROOT . '/config');
define('SQLITE_DB', DATA_DIR . '/app.sqlite');
// Encryption key: prioritize environment variable, fallback to file
define('ENCRYPTION_KEY_ENV', getenv('PHPADMIN_ENCRYPTION_KEY') ?: '');
define('ENCRYPTION_KEY_FILE', CONFIG_DIR . '/.encryption_key');
define('AUDIT_ENABLED', true);
define('JSON_RESULT_LIMIT', 10);
define('DEFAULT_QUERY_TIMEOUT', 30);
define('DEBUG_MODE', false);
