<?php
/**
 * Plugin Name: Sheets to Posts
 * Description: Sync Google Sheets rows to WordPress posts.
 * Version: 0.1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) { exit; }

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
  if (!current_user_can('manage_options')) { return; }

  // Save sheet URL
  if (isset($_POST['s2p_save_settings'])) {
    check_admin_referer('s2p_settings_save');

    $sheet_url = isset($_POST['s2p_sheet_url']) ? esc_url_raw(trim($_POST['s2p_sheet_url'])) : '';
    update_option('s2p_sheet_url', $sheet_url);

    echo '<div class="notice notice-success is-dismissible"><p>Sheet saved.</p></div>';
  }

  // Handle Sync button (for now it only shows a message)
  if (isset($_POST['s2p_run_sync'])) {
    echo '<div class="notice notice-info is-dismissible"><p>Sync clicked! (We will connect to Google Sheets next.)</p></div>';
  }

  $saved_url = get_option('s2p_sheet_url', '');
  ?>
  <div class="wrap">
    <h1>Sheets to Posts</h1>

    <form method="post">
      <?php wp_nonce_field('s2p_settings_save'); ?>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="s2p_sheet_url">Google Sheet link</label></th>
          <td>
            <input
              type="url"
              id="s2p_sheet_url"
              name="s2p_sheet_url"
              value="<?php echo esc_attr($saved_url); ?>"
              class="regular-text"
              placeholder="Paste your Google Sheets share link here"
            />
            <p class="description">Make sure the sheet is shared as “Anyone with the link” (Viewer).</p>
          </td>
        </tr>
      </table>

      <p>
        <button type="submit" class="button button-primary" name="s2p_save_settings" value="1">
          Save Sheet
        </button>

        &nbsp;&nbsp;

        <button type="submit" class="button button-secondary" name="s2p_run_sync" value="1">
          Sync Now
        </button>
      </p>
    </form>
  </div>
  <?php
}
