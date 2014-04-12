<?php
/*
  Plugin Name: wp-vcard-manager
  Plugin URI:  https://github.com/simbo/wp-vcard-manager
  Description: A WordPress plugin for managing and generating vcards, hcards and corresponding qrcode images.
  Version:     0.0.5
  Author:      Simon Lepel
  Author URI:  http://simonlepel.de/
  License:     GPL
  Text Domain: vcrdmngr
*/

if( !defined('ABSPATH') )
    exit;

if( !class_exists('QRcode') )
    require_once 'vendor/qrcode/qrlib.php';

require_once 'vendor/plugin-update-checker/plugin-update-checker.php';
PucFactory::buildUpdateChecker('https://raw.githubusercontent.com/simbo/wp-vcard-manager/master/wp-plugin.json',__FILE__,'wp-vcard-manager');

if( !class_exists('WPvCardManager') ) {
    require_once 'src/class-wp-vcard-manager.php';
    WPvCardManager::_(__FILE__);
}

