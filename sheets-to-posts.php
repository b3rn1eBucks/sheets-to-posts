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

/**
 * Convert a normal Google Sheets link into a CSV export link.
 * Example share link:
 * https://docs.google.com/spreadsheets/d/SHEET_ID/edit?usp=sharing
 * CSV export:
 * https://docs.google.com/spreadsheets/d/SHEET_ID/export?format=csv
 */
function s2p_to_csv_url($sheet_url) {

  if (empty($sheet_url)) {
    return '';
  }

  if (preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $sheet_url, $matches)) {
    $sheet_id = $matches[1];
    return "https://docs.google.com/spreadsheets/d/{$sheet_id}/export?format=csv";
  }

  return '';
}

/**
 * Fetch the sheet as CSV and return rows as an array (including header row).
 */
function s2p_fetch_sheet_rows($csv_url) {

  if (empty($csv_url)) {
    return new WP_Error('no_url', 'No valid sheet URL found.');
  }

  $response = wp_remote_get($csv_url, [
    'timeout' => 20,
  ]);

  if (is_wp_error($response)) {
    return $response;
  }

  $body = wp_remote_retrieve_body($response);

  if (empty($body)) {
    return new WP_Error('empty_sheet', 'Sheet returned no data.');
  }

  // Split into lines (handles different line endings)
  $lines = preg_split("/\r\n|\n|\r/", trim($body));
  $rows = [];

  foreach ($lines as $line) {

    if (trim($line) === '') {
      continue;
    }

    $cols = str_getcsv($line);

    // Skip lines where every column is empty/whitespace
    $all_empty = true;
    foreach ($cols as $c) {
      if (trim((string)$c) !== '') {
        $all_empty = false;
        break;
      }
    }
    if ($all_empty) {
      continue;
    }

    $rows[] = $cols;
  }

  return $rows;
}

function s2p_render_settings_page() {

  if (!current_user_can('manage_options')) { return; }

  // Save sheet URL
  if (isset($_POST['s2p_save_settings'])) {
    check_admin_referer('s2p_settings_save');

    $sheet_url = isset($_POST['s2p_sheet_url'])
      ? esc_url_raw(trim($_POST['s2p_sheet_url']))
      : '';

    update_option('s2p_sheet_url', $sheet_url);

    echo '<div class="notice notice-success is-dismissible"><p>Sheet saved.</p></div>';
  }

  // Handle Sync button
  if (isset($_POST['s2p_run_sync'])) {

    $sheet_url = get_option('s2p_sheet_url', '');
    $csv_url   = s2p_to_csv_url($sheet_url);

    $rows = s2p_fetch_sheet_rows($csv_url);

    if (is_wp_error($rows)) {

      echo '<div class="notice notice-error is-dismissible"><p>Error: '
        . esc_html($rows->get_error_message())
        . '</p></div>';

    } else {

      // First row is header (title/content)
      $header = array_shift($rows);

      // Remaining rows are data
      $data_rows = $rows;

      $count = count($data_rows);

      echo '<div class="notice notice-info is-dismissible"><p>';
      echo 'Sheet read successfully! I see ' . intval($count) . ' posts to import (the header row with titles is not counted).';
      echo '</p></div>';

      if ($count > 0) {

        $created = 0;
        $skipped = 0;

        foreach ($data_rows as $row) {

          $title   = isset($row[0]) ? sanitize_text_field($row[0]) : '';
          $content = isset($row[1]) ? wp_kses_post($row[1]) : '';

          if ($title === '' || $content === '') {
            $skipped++;
            continue;
          }

          $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft', // keep safe
            'post_type'    => 'post',
          ], true);

          if (is_wp_error($post_id)) {
            $skipped++;
          } else {
            $created++;
          }
        }

        echo '<div class="notice notice-success is-dismissible"><p>';
        echo 'Created ' . intval($created) . ' new draft posts. Skipped ' . intval($skipped) . ' row(s).';
        echo '</p></div>';

      } else {

        echo '<div class="notice notice-warning is-dismissible"><p>No data rows found to import.</p></div>';
      }
    }
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
            <p class="description">Share as “Anyone with the link → Viewer”.</p>
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
