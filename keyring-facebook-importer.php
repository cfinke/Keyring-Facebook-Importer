<?php

/*
Plugin Name: Keyring Facebook Importer
Plugin URI: http://www.chrisfinke.com/2013/11/20/export-your-facebook-posts-to-wordpress/
Description: Imports your data from Facebook.
Version: 1.0.1
Author: Christopher Finke
Author URI: http://www.chrisfinke.com/
License: GPL2
Depends: Keyring, Keyring Social Importers
*/

function keyring_facebook_enable_importer( $importers ) {
	$importers[] = plugin_dir_path( __FILE__ ) . 'keyring-facebook-importer/keyring-importer-facebook.php';
	
	return $importers;
}

add_filter( 'keyring_importers', 'keyring_facebook_enable_importer' );