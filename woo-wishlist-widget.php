<?php
/*
Plugin Name: WooCommerce Wishlist Widget - Favorite Products And More
Description: A WooCommerce add-on that allows customers to wishlist products via a widget.
Version: 1.0
Author: Connor Bryant
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

/*
 * This plugin, all included libraries, and any other included assets are licensed as GPL or are under a GPL compatible license.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) exit;

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', function() {
    $version = '1.0';
    wp_enqueue_style('wishlist-mode-style', plugin_dir_url(__FILE__) . 'wishlist-mode.css', array(), $version);
    wp_enqueue_script('wishlist-mode-script', plugin_dir_url(__FILE__) . 'wishlist-mode.js', array('jquery'), $version, true);
});

// Inject wishlist widget on the side