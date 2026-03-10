<?php
require_once 'wp-load.php';
delete_option('sd_edi_import_lock');
// Also clear any transients that might be holding it
global $wpdb;
$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_sd_edi_session_%'");
$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_sd_edi_session_%'");
echo "Import lock cleared successfully.";
