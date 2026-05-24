<?php
// Mail configuration central file
// Set MAIL_ENABLED to false to disable actual sending (useful for testing or pausing emails)
// When enabling, fill in MAIL_USERNAME and MAIL_PASSWORD with an app password.

if (!defined('MAIL_CONFIG_LOADED')) {
    define('MAIL_CONFIG_LOADED', true);

    // Toggle to enable/disable sending
    $MAIL_ENABLED = false;

    // SMTP credentials (use app password when enabling)
    $MAIL_USERNAME = '';
    $MAIL_PASSWORD = '';

    // From address and name
    $MAIL_FROM = '';
    $MAIL_FROM_NAME = '器材借用系統';
}
