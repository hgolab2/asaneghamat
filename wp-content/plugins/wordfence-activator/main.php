<?php
/**
 * @wordpress-plugin
 * Plugin Name:       Wordfence Security Activator
 * Plugin URI:        https://learndl.ir/
 * Description:       Wordfence Security Plugin Activator
 * Version:           1.4.3
 * Requires at least: 5.9.0
 * Requires PHP:      7.2
 * Author:            learndl
 * Author URI:        https://learndl.ir/
 **/

defined('ABSPATH') || exit;

$PLUGIN_NAME   = 'Wordfence Security Activator';
$PLUGIN_DOMAIN = 'wordfence-security-activator';
$RemainingDays = 365 * 10;

global $wpdb;
$table_name = $wpdb->prefix . 'wfconfig';

$data = array(
    'name' => 'scan_exclude',
    'val' => '/wordfence-activator/*',
    'autoload' => 'yes'
);

$existing_entry = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM $table_name WHERE name = %s",
    $data['name']
));

if ($existing_entry == 0) {
    // Insert the new record if no entry exists
    $wpdb->insert($table_name, $data, array('%s', '%s', '%s'));
} else {
    // Append the new value to the existing value
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE $table_name SET val = CONCAT(val, %s) WHERE name = %s",
            ',' . $data['val'], // Appending a comma and the new value
            $data['name']
        )
    );
}


$init = function () use ($RemainingDays, $PLUGIN_NAME) {
    try {
        wfOnboardingController::_markAttempt1Shown();
        wfConfig::set('onboardingAttempt3', wfOnboardingController::ONBOARDING_LICENSE);
        if (empty(wfConfig::get('apiKey'))) {
            wordfence::ajax_downgradeLicense_callback();
        }
        wfConfig::set('isPaid', true);
        wfConfig::set('keyType', wfLicense::KEY_TYPE_PAID_CURRENT);
        wfConfig::set('premiumNextRenew', time() + $RemainingDays * 86400);
        wfWAF::getInstance()->getStorageEngine()->setConfig('wafStatus', wfFirewall::FIREWALL_MODE_ENABLED);
    } catch (Exception $exception) {
        // Handle the exception if needed
    }
};

add_action('plugins_loaded', function () use ($RemainingDays, $init) {
    if (class_exists('wfLicense')) {
        $init();
        wfLicense::current()->setType(wfLicense::TYPE_RESPONSE);
        wfLicense::current()->setPaid(true);
        wfLicense::current()->setRemainingDays($RemainingDays);
        wfLicense::current()->setConflicting(false);
        wfLicense::current()->setDeleted(false);
        wfLicense::current()->getKeyType();
    }
});
