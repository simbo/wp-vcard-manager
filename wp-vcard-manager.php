<?php
/*
  Plugin Name: wp-vcard-manager
  Plugin URI:  https://github.com/simbo/wp-vcard-manager
  Description: A WordPress plugin for managing and generating vcards, hcards and corresponding qrcode images.
  Version:     0.0.2
  Author:      Simon Lepel
  Author URI:  http://simonlepel.de/
  License:     GPL
  Text Domain: vcrdmngr
*/

if( !defined('ABSPATH') )
    exit;

if( !class_exists('QRcode') )
    require_once 'vendor/qrcode/qrlib.php';

if( !class_exists('WPvCardManager') ) {
    require_once 'src/class-wp-vcard-manager.php';
    WPvCardManager::_(__FILE__);
}

