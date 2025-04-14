<?php
/*
Plugin Name: FacetWP - Exclude
Description: Exclude facet type
Version: 0.1
Author: FacetWP, LLC
Author URI: https://facetwp.com/
GitHub URI: facetwp/facetwp-exclude
*/

defined( 'ABSPATH' ) or exit;

define( 'FACETWP_EXCLUDE_VERSION', '0.1' );


/**
 * FacetWP registration hook
 */
add_filter( 'facetwp_facet_types', function( $types ) {
    include( dirname( __FILE__ ) . '/class-exclude.php' );
    $types['exclude'] = new FacetWP_Facet_Exclude_Addon();

    return $types;
} );
