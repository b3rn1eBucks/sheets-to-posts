<?php
/**
 * Plugin Name: Sheets to Posts
 * Description: Sync Google Sheets rows to WordPress posts.
 * Version: 0.1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
  exit;
}

add_action('admin_menu', 's2p_add_admin_menu');

function s2p_add_admin_menu() {
  add_options_page(
    'Sheets to Posts',
    'Sheets to Posts',
    'manage_options',
    'sheets-to-posts',
    's2p_render_settings_page'
  );
}

function s2p_render_settings_page() {
  if (!current_user_can('manage_options')) {
    return;
  }
  ?>
  <div class="wrap">
    <h1>Sheets to Posts</h1>
    <p>Your plugin is installed and working. Next we’ll add your Google Sheet connection here.</p>
  </div>
  <?php
}
