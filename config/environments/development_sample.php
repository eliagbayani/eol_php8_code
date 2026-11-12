<?php

ini_set('display_errors', true);

// make sure you define the proper URL to the root directory of this installation
define('WEB_ROOT', 'http://localhost/eol_php_code/');

define('FLICKR_API_KEY', 'xxx');
define('FLICKR_SHARED_SECRET', 'xxx');
define('FLICKR_AUTH_TOKEN', 'xxx');

define('SOLR_SERVER', 'http://localhost:8983/solr/');

define('LOGGING_DB', 'eol_logging_development');

// the maintenance tools password: [Upload to Zenodo] on applications/terms_maintenance/main.php and the changes on
// applications/reference_maintenance/main.php (no password = uploading/changes from those pages are disabled)
// define('TERMS_MTCE_PASSWORD', 'choose-a-password');

?>