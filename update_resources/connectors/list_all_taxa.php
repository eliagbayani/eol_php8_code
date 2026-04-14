<?php
namespace php_active_record;

include_once(dirname(__FILE__) . "/../../config/environment.php");
// $GLOBALS['ENV_DEBUG'] = false; //true;

require_library('connectors/DHConnLib');
$func = new DHConnLib(1);
$func->list_all_taxa_in_html();

?>