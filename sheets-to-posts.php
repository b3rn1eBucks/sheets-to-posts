<?php
/**
 * Plugin Name: Sheets to Posts
 * Description: Sync Google Sheets rows to WordPress posts with Simple (Markdown) or Developer (Template) modes. Supports multiple sheets and scheduled sync.
 * Version: 0.4.0
 * Author: Isle Insight
 */

if (!defined('ABSPATH')) { exit; }

/**
 * ----------------------------
 * Admin Menu
 * ----------------------------
 */
add_action('admin_menu', 's2p_add_admin_menu');

function s2p_add_admin_menu() {
  add_menu_page(
    'Sheets to Posts',
    'Sheets to Posts',
    'manage_options',
    'sheets-to-posts',
    's2p_render_settings_page',
    'dashicons-media-spreadsheet',
    58
  );
}

/**
 * ----------------------------
 * Cron schedules (15 min)
 * ----------------------------
 */
add_filter('cron_schedules', 's2p_add_cron_schedules');
function s2p_add_cron_schedules($schedules) {
  if (!isset($schedules['s2p_15min'])) {
    $schedules['s2p_15min'] = [
      'interval' => 15 * 60,
      'display'  => 'Every 15 Minutes (Sheets to Posts)',
    ];
  }
  return $schedules;
}

/**
 * Cron hook for scheduled sync
 */
add_action('s2p_cron_sync', 's2p_run_scheduled_sync');

/**
 * Schedule/unschedule on activation/deactivation
 */
register_activation_hook(__FILE__, 's2p_on_activate');
function s2p_on_activate() {
  s2p_reschedule_cron_from_options();
}

register_deactivation_hook(__FILE__, 's2p_on_deactivate');
function s2p_on_deactivate() {
  $timestamp = wp_next_scheduled('s2p_cron_sync');
  if ($timestamp) {
    wp_unschedule_event($timestamp, 's2p_cron_sync');
  }
}

/**
 * Reschedule cron based on saved settings.
 */
function s2p_reschedule_cron_from_options() {
  $enabled = (bool) get_option('s2p_schedule_enabled', false);
  $freq    = get_option('s2p_schedule_frequency', 'hourly'); // s2p_15min | hourly | daily

  // Clear existing
  $timestamp = wp_next_scheduled('s2p_cron_sync');
  if ($timestamp) {
    wp_unschedule_event($timestamp, 's2p_cron_sync');
  }

  if (!$enabled) {
    return;
  }

  // Schedule new
  if (!wp_next_scheduled('s2p_cron_sync')) {
    // Start in 2 minutes to avoid “I saved settings and nothing happens”
    wp_schedule_event(time() + 120, $freq, 's2p_cron_sync');
  }
}

/**
 * ----------------------------
 * Options helpers
 * ----------------------------
 */
function s2p_get_sheets() {
  $sheets = get_option('s2p_sheets', []);
  if (!is_array($sheets)) { $sheets = []; }

  // Normalize
  $out = [];
  foreach ($sheets as $s) {
    if (!is_array($s)) { continue; }
    $id   = isset($s['id']) ? sanitize_text_field($s['id']) : '';
    $name = isset($s['name']) ? sanitize_text_field($s['name']) : '';
    $url  = isset($s['url']) ? esc_url_raw($s['url']) : '';

    if ($id === '') { continue; }
    $out[] = [
      'id'   => $id,
      'name' => $name,
      'url'  => $url,
    ];
  }

  return $out;
}

function s2p_save_sheets($sheets) {
  if (!is_array($sheets)) { $sheets = []; }
  update_option('s2p_sheets', array_values($sheets));
}

/**
 * ----------------------------
 * Convert a normal Google Sheets link into a CSV export link.
 * NOTE: If user pastes a “gviz” or “export” URL, we still try to pull the /d/<id>/ part.
 * ----------------------------
 */
function s2p_to_csv_url($sheet_url) {
  if (empty($sheet_url)) { return ''; }

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

  $response = wp_remote_get($csv_url, ['timeout' => 25]);

  if (is_wp_error($response)) { return $response; }

  $body = wp_remote_retrieve_body($response);
  if (empty($body)) {
    return new WP_Error('empty_sheet', 'Sheet returned no data.');
  }

  $lines = preg_split("/\r\n|\n|\r/", trim($body));
  $rows = [];

  foreach ($lines as $line) {
    if (trim($line) === '') { continue; }

    $cols = str_getcsv($line);

    // Skip lines where every column is empty/whitespace
    $all_empty = true;
    foreach ($cols as $c) {
      if (trim((string)$c) !== '') {
        $all_empty = false;
        break;
      }
    }
    if ($all_empty) { continue; }

    $rows[] = $cols;
  }

  return $rows;
}

/**
 * Build header map: column_name => index
 */
function s2p_header_map($header_row) {
  $map = [];
  foreach ($header_row as $i => $h) {
    $key = strtolower(trim((string)$h));
    if ($key !== '') { $map[$key] = $i; }
  }
  return $map;
}

/**
 * Get a cell by column name.
 */
function s2p_cell($row, $map, $col_name) {
  $col_name = strtolower($col_name);
  if (!isset($map[$col_name])) { return ''; }
  $idx = $map[$col_name];
  return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
}

/**
 * Find existing post by exact title.
 */
function s2p_find_post_by_title($title) {
  $title = trim((string)$title);
  if ($title === '') { return 0; }

  $existing = get_page_by_title($title, OBJECT, 'post');
  if ($existing && !empty($existing->ID)) { return (int)$existing->ID; }

  return 0;
}

/**
 * Ensure category exists; return term_id.
 */
function s2p_ensure_category($category_name) {
  $category_name = trim((string)$category_name);
  if ($category_name === '') { return 0; }

  $term = term_exists($category_name, 'category');
  if ($term && isset($term['term_id'])) { return (int)$term['term_id']; }

  $created = wp_insert_term($category_name, 'category');
  if (is_wp_error($created)) { return 0; }

  return isset($created['term_id']) ? (int)$created['term_id'] : 0;
}

/**
 * Parse comma-separated tags.
 */
function s2p_parse_tags($tags_string) {
  $tags_string = trim((string)$tags_string);
  if ($tags_string === '') { return []; }

  $parts = explode(',', $tags_string);
  $tags = [];
  foreach ($parts as $p) {
    $t = trim($p);
    if ($t !== '') { $tags[] = $t; }
  }
  return $tags;
}

/**
 * Download image and set featured image.
 */
function s2p_set_featured_image_from_url($post_id, $image_url) {
  $image_url = trim((string)$image_url);
  if ($image_url === '') { return new WP_Error('no_image_url', 'No featured_image URL provided.'); }
  if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
    return new WP_Error('bad_image_url', 'featured_image is not a valid URL.');
  }

  // Skip if same URL already used and thumbnail exists
  $prev_url = get_post_meta($post_id, '_s2p_featured_image_url', true);
  if ($prev_url && $prev_url === $image_url && has_post_thumbnail($post_id)) {
    return true;
  }

  require_once ABSPATH . 'wp-admin/includes/file.php';
  require_once ABSPATH . 'wp-admin/includes/media.php';
  require_once ABSPATH . 'wp-admin/includes/image.php';

  $tmp = download_url($image_url, 25);
  if (is_wp_error($tmp)) { return $tmp; }

  $filename = basename(parse_url($image_url, PHP_URL_PATH));
  if (!$filename) { $filename = 'featured-image.jpg'; }

  $file_array = [
    'name'     => $filename,
    'tmp_name' => $tmp,
  ];

  $attachment_id = media_handle_sideload($file_array, $post_id);

  if (is_wp_error($attachment_id)) {
    @unlink($tmp);
    return $attachment_id;
  }

  set_post_thumbnail($post_id, $attachment_id);
  update_post_meta($post_id, '_s2p_featured_image_url', $image_url);

  return true;
}

/**
 * Minimal Markdown -> HTML for Simple Mode.
 */
function s2p_markdown_to_html($text) {
  $text = (string)$text;
  $text = str_replace(["\r\n", "\r"], "\n", $text);
  $lines = explode("\n", $text);

  $html = '';
  $in_list = false;

  foreach ($lines as $line) {
    $raw = rtrim($line);
    $trimmed = trim($raw);

    if ($trimmed === '') {
      if ($in_list) {
        $html .= "</ul>\n";
        $in_list = false;
      }
      $html .= "\n";
      continue;
    }

    // Headings
    if (preg_match('/^(#{1,3})\s+(.*)$/', $trimmed, $m)) {
      if ($in_list) {
        $html .= "</ul>\n";
        $in_list = false;
      }
      $level = strlen($m[1]);
      $content = esc_html($m[2]);
      $html .= "<h{$level}>{$content}</h{$level}>\n";
      continue;
    }

    // Bullets
    if (preg_match('/^-\s+(.*)$/', $trimmed, $m)) {
      if (!$in_list) {
        $html .= "<ul>\n";
        $in_list = true;
      }
      $item = esc_html($m[1]);
      $html .= "<li>{$item}</li>\n";
      continue;
    }

    // Normal paragraph
    if ($in_list) {
      $html .= "</ul>\n";
      $in_list = false;
    }

    $p = esc_html($trimmed);
    $html .= "<p>{$p}</p>\n";
  }

  if ($in_list) {
    $html .= "</ul>\n";
  }

  // Inline bold/italic
  $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
  $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);

  return $html;
}

/**
 * Developer Mode templating.
 */
function s2p_apply_template($template, $row, $map) {
  $out = (string)$template;

  foreach ($map as $key => $idx) {
    $val = isset($row[$idx]) ? (string)$row[$idx] : '';
    $out = str_replace('{{' . $key . '}}', esc_html($val), $out);
  }

  // Optional raw HTML column
  if (isset($map['content_html'])) {
    $raw = isset($row[$map['content_html']]) ? (string)$row[$map['content_html']] : '';
    $out = str_replace('{{content_html}}', $raw, $out);
  }

  return wp_kses_post($out);
}

/**
 * Build a row hash to detect unchanged rows.
 */
function s2p_build_row_hash($saved_mode, $saved_tpl, $title, $final_content, $category_name, $tags_string, $image_url, $status) {
  $hash_source = json_encode([
    'mode' => $saved_mode,
    'template' => $saved_mode === 'developer' ? (string)$saved_tpl : '',
    'title' => (string)$title,
    'content' => (string)$final_content,
    'category' => (string)$category_name,
    'tags' => (string)$tags_string,
    'featured_image' => (string)$image_url,
    'status' => (string)$status,
  ]);
  return md5($hash_source);
}

/**
 * ----------------------------
 * Core: load + validate sheet
 * ----------------------------
 */
function s2p_load_sheet_by_url($sheet_url) {

  $csv_url = s2p_to_csv_url($sheet_url);
  $rows = s2p_fetch_sheet_rows($csv_url);

  if (is_wp_error($rows)) { return $rows; }
  if (count($rows) < 2) {
    return new WP_Error('not_enough_rows', 'Sheet must have a header row plus at least one data row.');
  }

  $header = array_shift($rows);
  $map    = s2p_header_map($header);

  return [
    'header'    => $header,
    'map'       => $map,
    'data_rows' => $rows,
  ];
}

/**
 * ----------------------------
 * Core: process a sheet (sync)
 * Returns stats array or WP_Error
 * ----------------------------
 */
function s2p_process_sheet_sync($sheet_name, $sheet_url, $saved_mode, $saved_tpl) {

  $loaded = s2p_load_sheet_by_url($sheet_url);
  if (is_wp_error($loaded)) { return $loaded; }

  $map       = $loaded['map'];
  $data_rows = $loaded['data_rows'];

  if (!isset($map['title']) || (!isset($map['content']) && $saved_mode !== 'developer')) {
    return new WP_Error(
      'missing_required_columns',
      'Sheet "' . $sheet_name . '" must have header row with at least "title". Simple Mode also requires "content".'
    );
  }

  $created = 0;
  $updated = 0;
  $unchanged = 0;
  $skipped = 0;
  $img_set = 0;
  $img_fail = 0;

  foreach ($data_rows as $row) {

    $title = sanitize_text_field(s2p_cell($row, $map, 'title'));
    if ($title === '') { $skipped++; continue; }

    $final_content = '';

    if ($saved_mode === 'developer') {
      $final_content = s2p_apply_template($saved_tpl, $row, $map);
    } else {
      $content_raw = s2p_cell($row, $map, 'content');
      if (trim($content_raw) === '') { $skipped++; continue; }
      $final_content = wp_kses_post(s2p_markdown_to_html($content_raw));
    }

    $category_name = sanitize_text_field(s2p_cell($row, $map, 'category'));
    $tags_string   = s2p_cell($row, $map, 'tags');
    $image_url     = esc_url_raw(s2p_cell($row, $map, 'featured_image'));
    $status_raw    = strtolower(trim(s2p_cell($row, $map, 'status')));
    $status        = ($status_raw === 'publish') ? 'publish' : 'draft';

    $row_hash = s2p_build_row_hash($saved_mode, $saved_tpl, $title, $final_content, $category_name, $tags_string, $image_url, $status);

    // No duplicates: find existing by title
    $existing_id = s2p_find_post_by_title($title);

    if ($existing_id) {
      $prev_hash = get_post_meta($existing_id, '_s2p_row_hash', true);
      if ($prev_hash && $prev_hash === $row_hash) {
        $unchanged++;
        continue;
      }

      $result = wp_update_post([
        'ID'           => $existing_id,
        'post_title'   => $title,
        'post_content' => $final_content,
        'post_status'  => $status,
        'post_type'    => 'post',
      ], true);

      if (is_wp_error($result)) { $skipped++; continue; }

      update_post_meta($existing_id, '_s2p_row_hash', $row_hash);
      $post_id = $existing_id;
      $updated++;

    } else {

      $post_id = wp_insert_post([
        'post_title'   => $title,
        'post_content' => $final_content,
        'post_status'  => $status,
        'post_type'    => 'post',
      ], true);

      if (is_wp_error($post_id)) { $skipped++; continue; }

      update_post_meta($post_id, '_s2p_row_hash', $row_hash);
      $created++;
    }

    // Category
    if ($category_name !== '') {
      $cat_id = s2p_ensure_category($category_name);
      if ($cat_id) { wp_set_post_categories($post_id, [$cat_id], false); }
    }

    // Tags
    $tags = s2p_parse_tags($tags_string);
    if (!empty($tags)) { wp_set_post_tags($post_id, $tags, false); }

    // Featured image
    if ($image_url !== '') {
      $img_result = s2p_set_featured_image_from_url($post_id, $image_url);
      if (is_wp_error($img_result)) { $img_fail++; } else { $img_set++; }
    }
  }

  return [
    'sheet_name' => $sheet_name,
    'rows_total' => count($data_rows),
    'created' => $created,
    'updated' => $updated,
    'unchanged' => $unchanged,
    'skipped' => $skipped,
    'img_set' => $img_set,
    'img_fail' => $img_fail,
  ];
}

/**
 * ----------------------------
 * Scheduled sync runner
 * ----------------------------
 */
function s2p_run_scheduled_sync() {
  // Lock to prevent overlap
  if (get_transient('s2p_sync_lock')) {
    return;
  }
  set_transient('s2p_sync_lock', 1, 10 * MINUTE_IN_SECONDS);

  $sheets = s2p_get_sheets();
  $mode   = get_option('s2p_mode', 'simple');
  $tpl    = get_option('s2p_template', "<h2>{{title}}</h2>\n<p>{{content}}</p>");

  $log = [
    'time' => gmdate('Y-m-d H:i:s') . ' UTC',
    'mode' => $mode,
    'results' => [],
  ];

  foreach ($sheets as $s) {
    $name = $s['name'] !== '' ? $s['name'] : 'Untitled Sheet';
    $url  = $s['url'];

    if ($url === '') {
      $log['results'][] = [
        'sheet' => $name,
        'ok' => false,
        'message' => 'Missing sheet URL.',
      ];
      continue;
    }

    $res = s2p_process_sheet_sync($name, $url, $mode, $tpl);

    if (is_wp_error($res)) {
      $log['results'][] = [
        'sheet' => $name,
        'ok' => false,
        'message' => $res->get_error_message(),
      ];
    } else {
      $log['results'][] = [
        'sheet' => $name,
        'ok' => true,
        'message' =>
          'Created ' . intval($res['created']) .
          ', updated ' . intval($res['updated']) .
          ', unchanged ' . intval($res['unchanged']) .
          ', skipped ' . intval($res['skipped']) .
          '. Images set ' . intval($res['img_set']) .
          ' (fail ' . intval($res['img_fail']) . ').',
      ];
    }
  }

  // Save last run log
  update_option('s2p_last_cron_log', $log);

  // Release lock
  delete_transient('s2p_sync_lock');
}

/**
 * ----------------------------
 * Admin UI
 * ----------------------------
 */
function s2p_render_settings_page() {
  if (!current_user_can('manage_options')) { return; }

  // Load saved options
  $saved_mode = get_option('s2p_mode', 'simple');
  $saved_tpl  = get_option('s2p_template', "<h2>{{title}}</h2>\n<p>{{content}}</p>");
  $sheets     = s2p_get_sheets();

  $schedule_enabled = (bool) get_option('s2p_schedule_enabled', false);
  $schedule_freq    = get_option('s2p_schedule_frequency', 'hourly');
  $last_log         = get_option('s2p_last_cron_log', []);

  /**
   * Add a sheet
   */
  if (isset($_POST['s2p_add_sheet'])) {
    check_admin_referer('s2p_save');

    $name = isset($_POST['s2p_new_sheet_name']) ? sanitize_text_field(wp_unslash($_POST['s2p_new_sheet_name'])) : '';
    $url  = isset($_POST['s2p_new_sheet_url']) ? esc_url_raw(trim(wp_unslash($_POST['s2p_new_sheet_url']))) : '';

    $id = 's_' . wp_generate_password(8, false, false);

    $sheets[] = [
      'id'   => $id,
      'name' => $name,
      'url'  => $url,
    ];

    s2p_save_sheets($sheets);

    echo '<div class="notice notice-success is-dismissible"><p>Sheet added.</p></div>';
  }

  /**
   * Delete a sheet
   */
  if (isset($_POST['s2p_delete_sheet'])) {
    check_admin_referer('s2p_save');

    $delete_id = isset($_POST['s2p_delete_sheet_id']) ? sanitize_text_field($_POST['s2p_delete_sheet_id']) : '';

    $new = [];
    foreach ($sheets as $s) {
      if ($s['id'] !== $delete_id) { $new[] = $s; }
    }
    $sheets = $new;
    s2p_save_sheets($sheets);

    echo '<div class="notice notice-success is-dismissible"><p>Sheet removed.</p></div>';
  }

  /**
   * Save global settings (mode/template + schedule)
   */
  if (isset($_POST['s2p_save_settings'])) {
    check_admin_referer('s2p_save');

    $mode = isset($_POST['s2p_mode']) ? sanitize_text_field($_POST['s2p_mode']) : 'simple';
    $tpl  = isset($_POST['s2p_template']) ? (string) wp_unslash($_POST['s2p_template']) : '';

    $enabled = isset($_POST['s2p_schedule_enabled']) ? true : false;
    $freq    = isset($_POST['s2p_schedule_frequency']) ? sanitize_text_field($_POST['s2p_schedule_frequency']) : 'hourly';

    // Guard: allow only known frequencies
    $allowed_freq = ['s2p_15min', 'hourly', 'daily'];
    if (!in_array($freq, $allowed_freq, true)) { $freq = 'hourly'; }

    update_option('s2p_mode', $mode);
    update_option('s2p_template', $tpl);
    update_option('s2p_schedule_enabled', $enabled);
    update_option('s2p_schedule_frequency', $freq);

    $saved_mode = $mode;
    $saved_tpl  = $tpl;
    $schedule_enabled = $enabled;
    $schedule_freq = $freq;

    // Apply cron schedule now
    s2p_reschedule_cron_from_options();

    echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
  }

  /**
   * Manual sync a single sheet
   */
  if (isset($_POST['s2p_sync_one'])) {
    check_admin_referer('s2p_save');

    $sheet_id = isset($_POST['s2p_sheet_id']) ? sanitize_text_field($_POST['s2p_sheet_id']) : '';
    $target = null;
    foreach ($sheets as $s) {
      if ($s['id'] === $sheet_id) { $target = $s; break; }
    }

    if (!$target) {
      echo '<div class="notice notice-error is-dismissible"><p>Could not find that sheet.</p></div>';
    } else {
      $name = $target['name'] !== '' ? $target['name'] : 'Untitled Sheet';
      $url  = $target['url'];

      $res = s2p_process_sheet_sync($name, $url, $saved_mode, $saved_tpl);

      if (is_wp_error($res)) {
        echo '<div class="notice notice-error is-dismissible"><p>Error (' . esc_html($name) . '): ' . esc_html($res->get_error_message()) . '</p></div>';
      } else {
        echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html($name) . ':</strong> ';
        echo 'Created ' . intval($res['created']) . ', updated ' . intval($res['updated']) . ', unchanged ' . intval($res['unchanged']) . ', skipped ' . intval($res['skipped']) . '. ';
        echo 'Images set ' . intval($res['img_set']) . ' (fail ' . intval($res['img_fail']) . ').</p></div>';
      }
    }
  }

  /**
   * Manual sync all sheets
   */
  if (isset($_POST['s2p_sync_all'])) {
    check_admin_referer('s2p_save');

    $ok = 0; $fail = 0;
    foreach ($sheets as $s) {
      $name = $s['name'] !== '' ? $s['name'] : 'Untitled Sheet';
      $url  = $s['url'];

      if ($url === '') { $fail++; continue; }

      $res = s2p_process_sheet_sync($name, $url, $saved_mode, $saved_tpl);
      if (is_wp_error($res)) { $fail++; } else { $ok++; }
    }

    echo '<div class="notice notice-info is-dismissible"><p>Sync all complete. Success: ' . intval($ok) . ', Failed: ' . intval($fail) . '.</p></div>';
  }

  /**
   * Test row (per sheet)
   */
  if (isset($_POST['s2p_test_row'])) {
    check_admin_referer('s2p_save');

    $sheet_id = isset($_POST['s2p_sheet_id']) ? sanitize_text_field($_POST['s2p_sheet_id']) : '';
    $row_index = isset($_POST['s2p_test_row_index']) ? intval($_POST['s2p_test_row_index']) : 1;
    if ($row_index < 1) { $row_index = 1; }

    $target = null;
    foreach ($sheets as $s) {
      if ($s['id'] === $sheet_id) { $target = $s; break; }
    }

    if (!$target) {
      echo '<div class="notice notice-error is-dismissible"><p>Could not find that sheet.</p></div>';
    } else {
      $name = $target['name'] !== '' ? $target['name'] : 'Untitled Sheet';
      $url  = $target['url'];

      $loaded = s2p_load_sheet_by_url($url);
      if (is_wp_error($loaded)) {
        echo '<div class="notice notice-error is-dismissible"><p>Error (' . esc_html($name) . '): ' . esc_html($loaded->get_error_message()) . '</p></div>';
      } else {
        $map = $loaded['map'];
        $data_rows = $loaded['data_rows'];
        $max = count($data_rows);
        if ($row_index > $max) { $row_index = $max; }

        if (!isset($map['title']) || (!isset($map['content']) && $saved_mode !== 'developer')) {
          echo '<div class="notice notice-error is-dismissible"><p>';
          echo 'Sheet "' . esc_html($name) . '" must have header row with at least <strong>title</strong>. ';
          if ($saved_mode === 'simple') {
            echo 'Simple Mode also requires <strong>content</strong>.';
          } else {
            echo 'Developer Mode uses your template tokens.';
          }
          echo '</p></div>';
        } else {

          $row = $data_rows[$row_index - 1];
          $title = sanitize_text_field(s2p_cell($row, $map, 'title'));

          if ($saved_mode === 'developer') {
            $final_content = s2p_apply_template($saved_tpl, $row, $map);
          } else {
            $content_raw = s2p_cell($row, $map, 'content');
            $final_content = wp_kses_post(s2p_markdown_to_html($content_raw));
          }

          echo '<div class="notice notice-info is-dismissible"><p><strong>Test Row Preview:</strong> ' . esc_html($name) . ' (Row #' . intval($row_index) . ' of ' . intval($max) . ')</p></div>';

          echo '<div class="s2p-preview-box">';
          echo '<div class="s2p-preview-title"><strong>Title:</strong> ' . esc_html($title) . '</div>';
          echo '<div class="s2p-preview-render">' . wp_kses_post($final_content) . '</div>';
          echo '<div class="s2p-help" style="margin-top:10px;"><strong>Note:</strong> Preview only. It does not create or update posts.</div>';
          echo '</div>';
        }
      }
    }
  }

  // Useful display: next cron time
  $next_run = wp_next_scheduled('s2p_cron_sync');

  ?>
  <div class="wrap">
    <style>
      .s2p-wrapbox{
        background:#fff;
        border:1px solid #dcdcde;
        border-radius:12px;
        padding:16px;
        max-width:980px;
        box-shadow:0 1px 2px rgba(0,0,0,.04);
      }
      .s2p-header{
        display:flex;
        flex-direction:column;
        gap:6px;
        padding:4px 0 14px 0;
        border-bottom:1px solid #dcdcde;
        margin-bottom:14px;
      }
      .s2p-logo{ max-width:240px; height:auto; display:block; }
      .s2p-tagline{ color:#646970; font-size:13px; line-height:1.4; }

      .s2p-grid{ display:grid; grid-template-columns:1fr; gap:16px; }
      .s2p-row{ display:flex; gap:12px; flex-wrap:wrap; align-items:flex-start; }
      .s2p-row > *{ flex:1; min-width:280px; }
      .s2p-help{ color:#646970; margin:6px 0 0; line-height:1.45; }

      .s2p-code{
        font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        background:#f6f7f7;
        border:1px solid #dcdcde;
        border-radius:6px;
        padding:2px 6px;
        display:inline-block;
        white-space:nowrap;
      }

      .s2p-box{
        font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        background:#f6f7f7;
        border:1px solid #dcdcde;
        border-radius:10px;
        padding:12px;
        white-space:pre-wrap;
        line-height:1.45;
      }

      .s2p-label{ font-weight:600; margin-bottom:6px; display:block; }

      .s2p-template{
        width:100%;
        min-height:140px;
        font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
      }

      .s2p-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
      .s2p-actions .button{ padding:6px 14px; height:auto; line-height:1.4; }

      .s2p-table{
        width:100%;
        border-collapse:collapse;
      }
      .s2p-table th, .s2p-table td{
        border-top:1px solid #dcdcde;
        padding:10px 8px;
        vertical-align:top;
      }
      .s2p-table th{
        text-align:left;
        font-weight:600;
        background:#f6f7f7;
      }
      .s2p-mini{
        display:flex; gap:8px; flex-wrap:wrap; align-items:center;
      }
      .s2p-mini input[type="number"]{ width:110px; }

      .s2p-preview-box{
        max-width:980px;
        background:#fff;
        border:1px solid #dcdcde;
        border-radius:12px;
        padding:14px;
        margin-top:12px;
        box-shadow:0 1px 2px rgba(0,0,0,.04);
      }
      .s2p-preview-render{
        border:1px solid #dcdcde;
        border-radius:12px;
        padding:14px;
        background:#fff;
        margin-top:10px;
      }
      .s2p-pill{
        display:inline-block;
        padding:2px 8px;
        border:1px solid #dcdcde;
        border-radius:999px;
        background:#f6f7f7;
        font-size:12px;
        color:#1d2327;
      }
    </style>

    <h1>Sheets to Posts</h1>

    <div class="s2p-wrapbox">
      <div class="s2p-header">
        <img
          class="s2p-logo"
          src="<?php echo esc_url( plugins_url('assets/S2P-logo.jpg', __FILE__) ); ?>"
          alt="Sheets to Posts"
        />
        <div class="s2p-tagline">From Google Sheets to WordPress posts. Fast, clean, and repeatable.</div>
      </div>

      <form method="post">
        <?php wp_nonce_field('s2p_save'); ?>

        <div class="s2p-grid">

          <!-- SHEETS LIST -->
          <div>
            <span class="s2p-label">Your Sheets</span>

            <?php if (empty($sheets)): ?>
              <p class="s2p-help">Add your first sheet below. Share it as: <strong>Anyone with the link → Viewer</strong>.</p>
            <?php endif; ?>

            <?php if (!empty($sheets)): ?>
              <table class="s2p-table" role="presentation">
                <thead>
                  <tr>
                    <th style="width:180px;">Name</th>
                    <th>Google Sheet Link</th>
                    <th style="width:260px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($sheets as $s): ?>
                    <tr>
                      <td>
                        <strong><?php echo esc_html($s['name'] !== '' ? $s['name'] : 'Untitled'); ?></strong><br>
                        <span class="s2p-pill"><?php echo esc_html($s['id']); ?></span>
                      </td>
                      <td>
                        <div style="word-break:break-word;"><?php echo esc_html($s['url']); ?></div>
                        <div class="s2p-help">Tip: keep your sheet headers consistent across sheets.</div>
                      </td>
                      <td>
                        <div class="s2p-mini">
                          <input type="hidden" name="s2p_sheet_id" value="<?php echo esc_attr($s['id']); ?>" />
                          <button type="submit" class="button" name="s2p_sync_one" value="1">Sync</button>
                          <input type="number" name="s2p_test_row_index" min="1" value="1" />
                          <button type="submit" class="button" name="s2p_test_row" value="1">Test</button>
                          <button type="submit" class="button-link-delete" name="s2p_delete_sheet" value="1" onclick="return confirm('Remove this sheet from the list?');" style="margin-left:6px;">Remove</button>
                          <input type="hidden" name="s2p_delete_sheet_id" value="<?php echo esc_attr($s['id']); ?>" />
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>

              <div class="s2p-actions" style="margin-top:12px;">
                <button type="submit" class="button button-secondary" name="s2p_sync_all" value="1">Sync All Sheets</button>
              </div>
            <?php endif; ?>
          </div>

          <!-- ADD NEW SHEET -->
          <div>
            <span class="s2p-label">Add a Sheet</span>
            <div class="s2p-row">
              <div>
                <label class="s2p-label" for="s2p_new_sheet_name">Sheet name</label>
                <input type="text" id="s2p_new_sheet_name" name="s2p_new_sheet_name" class="regular-text" placeholder="Example: Blog Posts" style="width:100%;" />
              </div>
              <div>
                <label class="s2p-label" for="s2p_new_sheet_url">Google Sheet link</label>
                <input type="url" id="s2p_new_sheet_url" name="s2p_new_sheet_url" class="regular-text" placeholder="Paste your Google Sheets share link here" style="width:100%;" />
                <p class="s2p-help">Share as: <strong>Anyone with the link → Viewer</strong>.</p>
              </div>
            </div>
            <div class="s2p-actions">
              <button type="submit" class="button" name="s2p_add_sheet" value="1">Add Sheet</button>
            </div>
          </div>

          <!-- MODE + TEMPLATE -->
          <div class="s2p-row">
            <div>
              <label class="s2p-label" for="s2p_mode">Mode</label>
              <select id="s2p_mode" name="s2p_mode" style="width:100%; max-width:420px;">
                <option value="simple" <?php selected($saved_mode, 'simple'); ?>>Simple Mode (Markdown)</option>
                <option value="developer" <?php selected($saved_mode, 'developer'); ?>>Developer Mode (Template + Tokens)</option>
              </select>

              <p class="s2p-help">
                <strong>Simple Mode:</strong> Put formatting inside the <span class="s2p-code">content</span> cell. Wrap the exact word(s):
              </p>

              <div class="s2p-box">**bold** → bold
*italic* → italic
# Heading → heading
- Bullet → bullet list item

Paste this into a content cell:

# My Post Heading
This is **bold** and this is *italic*.
- First point
- Second point</div>

              <p class="s2p-help"><strong>Developer Mode:</strong> Use tokens like <span class="s2p-code">{{title}}</span> from your header row.</p>
            </div>

            <div>
              <label class="s2p-label" for="s2p_template">Developer Mode Template</label>
              <textarea id="s2p_template" name="s2p_template" class="s2p-template"><?php echo esc_textarea($saved_tpl); ?></textarea>
              <p class="s2p-help">Example tokens: <span class="s2p-code">{{title}}</span>, <span class="s2p-code">{{content}}</span>, <span class="s2p-code">{{price}}</span>.</p>
            </div>
          </div>

          <!-- SCHEDULED SYNC -->
          <div>
            <span class="s2p-label">Scheduled Sync (Pro)</span>

            <div class="s2p-row">
              <div>
                <label class="s2p-label" style="margin-bottom:8px;">
                  <input type="checkbox" name="s2p_schedule_enabled" value="1" <?php checked($schedule_enabled, true); ?> />
                  Enable scheduled sync
                </label>

                <label class="s2p-label" for="s2p_schedule_frequency">Frequency</label>
                <select id="s2p_schedule_frequency" name="s2p_schedule_frequency" style="width:100%; max-width:320px;">
                  <option value="s2p_15min" <?php selected($schedule_freq, 's2p_15min'); ?>>Every 15 minutes</option>
                  <option value="hourly" <?php selected($schedule_freq, 'hourly'); ?>>Hourly</option>
                  <option value="daily" <?php selected($schedule_freq, 'daily'); ?>>Daily</option>
                </select>

                <p class="s2p-help">
                  WordPress scheduled tasks run when your site gets visits. Low-traffic sites may run a little late (that’s normal).
                </p>
              </div>

              <div>
                <div class="s2p-box"><?php
                  if ($schedule_enabled) {
                    echo "Status: ENABLED\n";
                    echo "Next run: " . ($next_run ? date_i18n('Y-m-d H:i:s', $next_run) : 'Soon') . "\n";
                    echo "Frequency: " . esc_html($schedule_freq) . "\n";
                  } else {
                    echo "Status: OFF\n";
                    echo "Tip: turn this on for “set it and forget it”.\n";
                  }
                ?></div>

                <?php if (is_array($last_log) && !empty($last_log)): ?>
                  <p class="s2p-help" style="margin-top:10px;"><strong>Last scheduled run log:</strong></p>
                  <div class="s2p-box"><?php
                    $time = isset($last_log['time']) ? (string)$last_log['time'] : '';
                    $mode = isset($last_log['mode']) ? (string)$last_log['mode'] : '';
                    echo "Time: " . esc_html($time) . "\n";
                    echo "Mode: " . esc_html($mode) . "\n\n";
                    if (isset($last_log['results']) && is_array($last_log['results'])) {
                      foreach ($last_log['results'] as $r) {
                        $sheet = isset($r['sheet']) ? (string)$r['sheet'] : 'Sheet';
                        $ok    = isset($r['ok']) ? (bool)$r['ok'] : false;
                        $msg   = isset($r['message']) ? (string)$r['message'] : '';
                        echo ($ok ? "[OK] " : "[FAIL] ") . $sheet . ": " . $msg . "\n";
                      }
                    }
                  ?></div>
                <?php endif; ?>
              </div>
            </div>

            <div class="s2p-actions">
              <button type="submit" class="button button-primary" name="s2p_save_settings" value="1">Save Settings</button>
            </div>

            <p class="s2p-help" style="margin-top:8px;">
              Tip: Use manual <strong>Sync</strong> buttons anytime. Scheduled sync is just “automatic Sync All.”
            </p>
          </div>

          <!-- SUPPORTED COLUMNS -->
          <div>
            <span class="s2p-label">Supported Sheet Columns</span>
            <div class="s2p-box">Required: title
Simple Mode requires: content
Optional: category, tags, featured_image, status (draft/publish)

Template tokens (Developer Mode): use {{column_name}} for any header.</div>
          </div>

        </div>
      </form>
    </div>
  </div>
  <?php
}
