<?php
/**
 * Plugin Name: Sheets to Posts
 * Description: Sync Google Sheets rows to WordPress content with Simple (Markdown) or Developer (Template) modes. Supports multiple Sheets.
 * Version: 0.4.0
 * Author: Isle Insight
 */

if (!defined('ABSPATH')) { exit; }

/**
 * Admin menu (top-level)
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
 * Option name for multi-sheet config
 */
define('S2P_OPTION_SHEETS', 's2p_sheets');

/**
 * Convert a normal Google Sheets link into a CSV export link.
 */
function s2p_to_csv_url($sheet_url) {
  $sheet_url = trim((string)$sheet_url);
  if ($sheet_url === '') { return ''; }

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
  $col_name = strtolower((string)$col_name);
  if (!isset($map[$col_name])) { return ''; }
  $idx = $map[$col_name];
  return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
}

/**
 * Find existing post by exact title (by post_type).
 */
function s2p_find_post_by_title($title, $post_type) {
  $title = trim((string)$title);
  $post_type = trim((string)$post_type);
  if ($title === '' || $post_type === '') { return 0; }

  $existing = get_page_by_title($title, OBJECT, $post_type);
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
function s2p_build_row_hash($mode, $tpl, $title, $content, $category, $tags, $image, $status, $post_type, $sheet_key) {
  $hash_source = json_encode([
    'sheet_key' => (string)$sheet_key,
    'mode' => (string)$mode,
    'template' => $mode === 'developer' ? (string)$tpl : '',
    'title' => (string)$title,
    'content' => (string)$content,
    'category' => (string)$category,
    'tags' => (string)$tags,
    'featured_image' => (string)$image,
    'status' => (string)$status,
    'post_type' => (string)$post_type,
  ]);
  return md5($hash_source);
}

/**
 * Default sheet config
 */
function s2p_default_sheet() {
  return [
    'name'      => 'Sheet 1',
    'sheet_url' => '',
    'mode'      => 'simple', // simple|developer
    'template'  => "<h2>{{title}}</h2>\n<p>{{content}}</p>",
    'post_type' => 'post',
  ];
}

/**
 * Load sheet list. If none exists, try migrating from older single options.
 */
function s2p_get_sheets() {
  $sheets = get_option(S2P_OPTION_SHEETS, null);

  if (is_array($sheets) && !empty($sheets)) {
    return $sheets;
  }

  // Migration: older single settings
  $old_url  = get_option('s2p_sheet_url', '');
  $old_mode = get_option('s2p_mode', 'simple');
  $old_tpl  = get_option('s2p_template', "<h2>{{title}}</h2>\n<p>{{content}}</p>");

  $one = s2p_default_sheet();
  $one['name']      = 'Main Sheet';
  $one['sheet_url'] = (string)$old_url;
  $one['mode']      = (string)$old_mode;
  $one['template']  = (string)$old_tpl;

  $sheets = [$one];
  update_option(S2P_OPTION_SHEETS, $sheets);

  return $sheets;
}

/**
 * Save sheets list safely
 */
function s2p_save_sheets($raw) {
  $out = [];

  if (!is_array($raw)) {
    update_option(S2P_OPTION_SHEETS, []);
    return [];
  }

  foreach ($raw as $i => $s) {
    if (!is_array($s)) { continue; }

    $name      = isset($s['name']) ? sanitize_text_field($s['name']) : '';
    $sheet_url = isset($s['sheet_url']) ? esc_url_raw(trim($s['sheet_url'])) : '';
    $mode      = isset($s['mode']) ? sanitize_text_field($s['mode']) : 'simple';
    $template  = isset($s['template']) ? (string)wp_unslash($s['template']) : '';
    $post_type = isset($s['post_type']) ? sanitize_key($s['post_type']) : 'post';

    if ($name === '') { $name = 'Sheet ' . (count($out) + 1); }
    if ($mode !== 'developer') { $mode = 'simple'; }
    if ($template === '') { $template = "<h2>{{title}}</h2>\n<p>{{content}}</p>"; }
    if ($post_type === '') { $post_type = 'post'; }

    $out[] = [
      'name'      => $name,
      'sheet_url' => $sheet_url,
      'mode'      => $mode,
      'template'  => $template,
      'post_type' => $post_type,
    ];
  }

  if (empty($out)) {
    $out[] = s2p_default_sheet();
  }

  update_option(S2P_OPTION_SHEETS, $out);
  return $out;
}

/**
 * Load a single sheet (rows + map)
 */
function s2p_load_sheet_data($sheet_url) {
  $csv_url = s2p_to_csv_url($sheet_url);
  $rows = s2p_fetch_sheet_rows($csv_url);

  if (is_wp_error($rows)) { return $rows; }
  if (count($rows) < 2) {
    return new WP_Error('not_enough_rows', 'Sheet must have a header row plus at least one data row.');
  }

  $header = array_shift($rows);
  $map = s2p_header_map($header);

  return [
    'header' => $header,
    'map' => $map,
    'data_rows' => $rows,
  ];
}

/**
 * Sync logic for a given sheet config.
 * If $dry_run = true, no posts are created/updated (used for Test Row preview).
 */
function s2p_process_sheet($sheet_key, $sheet_cfg, $dry_run = false, $test_row_index = null) {
  $sheet_key = (string)$sheet_key;

  $name      = isset($sheet_cfg['name']) ? (string)$sheet_cfg['name'] : 'Sheet';
  $sheet_url = isset($sheet_cfg['sheet_url']) ? (string)$sheet_cfg['sheet_url'] : '';
  $mode      = isset($sheet_cfg['mode']) ? (string)$sheet_cfg['mode'] : 'simple';
  $tpl       = isset($sheet_cfg['template']) ? (string)$sheet_cfg['template'] : "<h2>{{title}}</h2>\n<p>{{content}}</p>";
  $post_type = isset($sheet_cfg['post_type']) ? (string)$sheet_cfg['post_type'] : 'post';

  $loaded = s2p_load_sheet_data($sheet_url);
  if (is_wp_error($loaded)) { return $loaded; }

  $map = $loaded['map'];
  $data_rows = $loaded['data_rows'];

  // Required columns
  if (!isset($map['title']) || (!isset($map['content']) && $mode !== 'developer')) {
    return new WP_Error(
      'missing_columns',
      ($mode === 'simple')
        ? 'This sheet must have headers: title, content (Simple Mode).'
        : 'This sheet must have at least: title (Developer Mode uses template tokens).'
    );
  }

  $created = 0;
  $updated = 0;
  $unchanged = 0;
  $skipped = 0;
  $img_set = 0;
  $img_fail = 0;

  // If testing a single row
  if ($dry_run && $test_row_index !== null) {
    $idx = (int)$test_row_index;
    if ($idx < 1) { $idx = 1; }
    $max = count($data_rows);
    if ($idx > $max) { $idx = $max; }

    $row = $data_rows[$idx - 1];

    $title = sanitize_text_field(s2p_cell($row, $map, 'title'));
    if ($title === '') {
      return [
        'dry_run' => true,
        'sheet_name' => $name,
        'row_index' => $idx,
        'max_rows' => $max,
        'error' => 'That row has an empty title.',
      ];
    }

    if ($mode === 'developer') {
      $final_content = s2p_apply_template($tpl, $row, $map);
    } else {
      $content_raw = s2p_cell($row, $map, 'content');
      $final_content = wp_kses_post(s2p_markdown_to_html($content_raw));
    }

    $category_name = sanitize_text_field(s2p_cell($row, $map, 'category'));
    $tags_string   = s2p_cell($row, $map, 'tags');
    $image_url     = esc_url_raw(s2p_cell($row, $map, 'featured_image'));
    $status_raw    = strtolower(trim(s2p_cell($row, $map, 'status')));
    $status        = ($status_raw === 'publish') ? 'publish' : 'draft';

    $row_hash = s2p_build_row_hash($mode, $tpl, $title, $final_content, $category_name, $tags_string, $image_url, $status, $post_type, $sheet_key);

    $existing_id = s2p_find_post_by_title($title, $post_type);

    $action = 'Would CREATE';
    $meta = 'No existing item found with this exact title.';
    if ($existing_id) {
      $prev_hash = get_post_meta($existing_id, '_s2p_row_hash', true);
      if ($prev_hash && $prev_hash === $row_hash) {
        $action = 'Would do NOTHING (unchanged)';
        $meta = 'Matched existing ID ' . intval($existing_id) . ' and hash is unchanged.';
      } else {
        $action = 'Would UPDATE';
        $meta = 'Matched existing ID ' . intval($existing_id) . ' (changes detected).';
      }
    }

    return [
      'dry_run' => true,
      'sheet_name' => $name,
      'row_index' => $idx,
      'max_rows' => $max,
      'action' => $action,
      'meta' => $meta,
      'title' => $title,
      'status' => $status,
      'post_type' => $post_type,
      'category' => $category_name,
      'tags' => s2p_parse_tags($tags_string),
      'featured_image' => $image_url,
      'rendered_content' => $final_content,
    ];
  }

  // Real sync: process all rows
  foreach ($data_rows as $row) {

    $title = sanitize_text_field(s2p_cell($row, $map, 'title'));
    if ($title === '') { $skipped++; continue; }

    $final_content = '';

    if ($mode === 'developer') {
      $final_content = s2p_apply_template($tpl, $row, $map);
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

    $row_hash = s2p_build_row_hash($mode, $tpl, $title, $final_content, $category_name, $tags_string, $image_url, $status, $post_type, $sheet_key);

    $existing_id = s2p_find_post_by_title($title, $post_type);

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
        'post_type'    => $post_type,
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
        'post_type'    => $post_type,
      ], true);

      if (is_wp_error($post_id)) { $skipped++; continue; }

      update_post_meta($post_id, '_s2p_row_hash', $row_hash);
      $created++;
    }

    // Category only makes sense for standard posts; if post_type isn't "post" it won't break,
    // but categories may not apply. We'll still try.
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
    'dry_run' => false,
    'sheet_name' => $name,
    'count_rows' => count($data_rows),
    'created' => $created,
    'updated' => $updated,
    'unchanged' => $unchanged,
    'skipped' => $skipped,
    'img_set' => $img_set,
    'img_fail' => $img_fail,
  ];
}

/**
 * Render settings page
 */
function s2p_render_settings_page() {
  if (!current_user_can('manage_options')) { return; }

  $sheets = s2p_get_sheets();

  // Handle Save
  if (isset($_POST['s2p_save_settings'])) {
    check_admin_referer('s2p_save_settings');

    $raw_sheets = isset($_POST['s2p_sheets']) ? (array)$_POST['s2p_sheets'] : [];
    $sheets = s2p_save_sheets($raw_sheets);

    echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
  }

  // Handle actions: Sync one / Sync all / Test row
  $last_preview = null;

  if (isset($_POST['s2p_action'])) {
    check_admin_referer('s2p_save_settings');

    $action = sanitize_text_field($_POST['s2p_action']);
    $sheet_index = isset($_POST['s2p_sheet_index']) ? intval($_POST['s2p_sheet_index']) : -1;
    $test_row = isset($_POST['s2p_test_row_index']) ? intval($_POST['s2p_test_row_index']) : 1;

    // Make sure we use the latest saved sheets in the form (if user edited but didn't click Save)
    $raw_sheets_live = isset($_POST['s2p_sheets']) ? (array)$_POST['s2p_sheets'] : $sheets;
    $live_sheets = s2p_save_sheets($raw_sheets_live);
    $sheets = $live_sheets;

    if ($action === 'sync_all') {
      foreach ($sheets as $i => $cfg) {
        $key = 'sheet_' . $i;
        $res = s2p_process_sheet($key, $cfg, false, null);
        if (is_wp_error($res)) {
          echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html($cfg['name']) . ':</strong> ' . esc_html($res->get_error_message()) . '</p></div>';
        } else {
          echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html($cfg['name']) . ':</strong> '
            . 'Processed ' . intval($res['count_rows']) . ' rows. '
            . 'Created ' . intval($res['created']) . ', updated ' . intval($res['updated']) . ', unchanged ' . intval($res['unchanged']) . ', skipped ' . intval($res['skipped']) . '. '
            . 'Images set ' . intval($res['img_set']) . ' (failed ' . intval($res['img_fail']) . ').'
            . '</p></div>';
        }
      }
    }

    if ($action === 'sync_one' && $sheet_index >= 0 && isset($sheets[$sheet_index])) {
      $cfg = $sheets[$sheet_index];
      $key = 'sheet_' . $sheet_index;

      $res = s2p_process_sheet($key, $cfg, false, null);

      if (is_wp_error($res)) {
        echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html($cfg['name']) . ':</strong> ' . esc_html($res->get_error_message()) . '</p></div>';
      } else {
        echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html($cfg['name']) . ':</strong> '
          . 'Processed ' . intval($res['count_rows']) . ' rows. '
          . 'Created ' . intval($res['created']) . ', updated ' . intval($res['updated']) . ', unchanged ' . intval($res['unchanged']) . ', skipped ' . intval($res['skipped']) . '. '
          . 'Images set ' . intval($res['img_set']) . ' (failed ' . intval($res['img_fail']) . ').'
          . '</p></div>';
      }
    }

    if ($action === 'test_row' && $sheet_index >= 0 && isset($sheets[$sheet_index])) {
      $cfg = $sheets[$sheet_index];
      $key = 'sheet_' . $sheet_index;

      $preview = s2p_process_sheet($key, $cfg, true, $test_row);

      if (is_wp_error($preview)) {
        echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html($cfg['name']) . ':</strong> ' . esc_html($preview->get_error_message()) . '</p></div>';
      } else {
        $last_preview = $preview;
      }
    }
  }

  ?>
  <div class="wrap">
    <style>
      .s2p-wrap{ max-width: 980px; }
      .s2p-shell{
        background:#fff;
        border:1px solid #dcdcde;
        border-radius:14px;
        padding:16px;
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

      .s2p-help{ color:#646970; margin:6px 0 0; line-height:1.45; }
      .s2p-label{ font-weight:600; margin-bottom:6px; display:block; }
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

      /* Sheet list */
      .s2p-sheets{ display:flex; flex-direction:column; gap:12px; margin-top:12px; }
      details.s2p-sheet{
        border:1px solid #dcdcde;
        border-radius:12px;
        background:#fff;
        overflow:hidden;
      }
      details.s2p-sheet > summary{
        cursor:pointer;
        padding:12px 14px;
        font-weight:600;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        background:#fbfbfb;
      }
      .s2p-sheet-meta{ color:#646970; font-weight:400; font-size:12px; }
      .s2p-sheet-body{ padding:14px; border-top:1px solid #dcdcde; }

      .s2p-grid{ display:grid; grid-template-columns:1fr; gap:12px; }
      .s2p-row{ display:flex; gap:12px; flex-wrap:wrap; align-items:flex-start; }
      .s2p-row > *{ flex:1; min-width:260px; }

      .s2p-template{
        width:100%;
        min-height:120px;
        font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
      }

      .s2p-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:10px; }
      .s2p-actions .button{ padding:6px 14px; height:auto; line-height:1.4; }

      .s2p-inline{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
      .s2p-small-input{ width:120px; }

      .s2p-danger{ color:#b32d2e; font-weight:600; }
      .s2p-muted{ color:#646970; }

      .s2p-preview{
        border:1px solid #dcdcde;
        border-radius:12px;
        padding:14px;
        background:#fff;
      }
    </style>

    <div class="s2p-wrap">
      <h1>Sheets to Posts</h1>

      <div class="s2p-shell">
        <div class="s2p-header">
          <img
            class="s2p-logo"
            src="<?php echo esc_url( plugins_url('assets/S2P-logo.jpg', __FILE__) ); ?>"
            alt="Sheets to Posts"
          />
          <div class="s2p-tagline">From Google Sheets to WordPress content. Fast, clean, repeatable.</div>
        </div>

        <form method="post">
          <?php wp_nonce_field('s2p_save_settings'); ?>

          <div>
            <span class="s2p-label">Supported Sheet Columns</span>
            <div class="s2p-box">Required: title
Simple Mode requires: content
Optional: category, tags, featured_image, status (draft/publish)

Developer Mode tokens: use {{column_name}} for any header.
Optional: content_html (insert raw HTML if your template uses {{content_html}})</div>
          </div>

          <div class="s2p-help" style="margin-top:10px;">
            <strong>Simple Mode formatting example (inside the content cell):</strong>
            <div class="s2p-box" style="margin-top:8px;"># My Heading
This is **bold** and this is *italic*.
- First point
- Second point</div>
          </div>

          <div class="s2p-actions" style="margin-top:14px;">
            <button type="submit" class="button button-primary" name="s2p_save_settings" value="1">Save Settings</button>

            <button type="submit" class="button button-secondary" name="s2p_action" value="sync_all">Sync ALL Sheets</button>

            <button type="button" class="button" id="s2p-add-sheet">+ Add Sheet</button>
          </div>

          <div class="s2p-sheets" id="s2p-sheets">
            <?php foreach ($sheets as $i => $sheet): 
              $name = isset($sheet['name']) ? $sheet['name'] : 'Sheet';
              $mode = isset($sheet['mode']) ? $sheet['mode'] : 'simple';
              $post_type = isset($sheet['post_type']) ? $sheet['post_type'] : 'post';
              $has_url = !empty($sheet['sheet_url']);
            ?>
              <details class="s2p-sheet" <?php echo $i === 0 ? 'open' : ''; ?>>
                <summary>
                  <div>
                    <?php echo esc_html($name); ?>
                    <div class="s2p-sheet-meta">
                      <?php echo $has_url ? esc_html($post_type . ' • ' . ($mode === 'developer' ? 'Developer' : 'Simple')) : 'No sheet URL yet'; ?>
                    </div>
                  </div>
                  <div class="s2p-muted">Sheet #<?php echo intval($i + 1); ?></div>
                </summary>

                <div class="s2p-sheet-body">
                  <div class="s2p-grid">

                    <div class="s2p-row">
                      <div>
                        <label class="s2p-label">Sheet Name</label>
                        <input type="text" name="s2p_sheets[<?php echo intval($i); ?>][name]" value="<?php echo esc_attr($sheet['name']); ?>" style="width:100%;" />
                        <p class="s2p-help">This is just a label for your dashboard.</p>
                      </div>

                      <div>
                        <label class="s2p-label">Post Type</label>
                        <input type="text" name="s2p_sheets[<?php echo intval($i); ?>][post_type]" value="<?php echo esc_attr($sheet['post_type']); ?>" style="width:100%;" />
                        <p class="s2p-help">Use <span class="s2p-code">post</span> (default) or <span class="s2p-code">page</span>. Custom types later.</p>
                      </div>
                    </div>

                    <div>
                      <label class="s2p-label">Google Sheet link</label>
                      <input type="url" name="s2p_sheets[<?php echo intval($i); ?>][sheet_url]" value="<?php echo esc_attr($sheet['sheet_url']); ?>" style="width:100%;" placeholder="Paste your Google Sheets share link here" />
                      <p class="s2p-help">Must be shared as: <strong>Anyone with the link → Viewer</strong>.</p>
                    </div>

                    <div class="s2p-row">
                      <div>
                        <label class="s2p-label">Mode</label>
                        <select name="s2p_sheets[<?php echo intval($i); ?>][mode]" class="s2p-mode" data-index="<?php echo intval($i); ?>" style="width:100%; max-width:420px;">
                          <option value="simple" <?php selected($mode, 'simple'); ?>>Simple Mode (Markdown)</option>
                          <option value="developer" <?php selected($mode, 'developer'); ?>>Developer Mode (Template + Tokens)</option>
                        </select>
                        <p class="s2p-help">
                          Simple Mode uses <span class="s2p-code">content</span>. Developer Mode uses your template tokens like <span class="s2p-code">{{title}}</span>.
                        </p>
                      </div>

                      <div class="s2p-template-wrap" id="s2p-template-wrap-<?php echo intval($i); ?>" style="<?php echo $mode === 'developer' ? '' : 'display:none;'; ?>">
                        <label class="s2p-label">Developer Template</label>
                        <textarea class="s2p-template" name="s2p_sheets[<?php echo intval($i); ?>][template]"><?php echo esc_textarea($sheet['template']); ?></textarea>
                        <p class="s2p-help">Tokens come from your header row. Example: <span class="s2p-code">{{title}}</span>, <span class="s2p-code">{{price}}</span>.</p>
                      </div>
                    </div>

                    <div class="s2p-actions">
                      <input type="hidden" name="s2p_sheet_index" value="<?php echo intval($i); ?>" />

                      <div class="s2p-inline">
                        <label class="s2p-label" style="margin:0;">Test row #</label>
                        <input class="s2p-small-input" type="number" name="s2p_test_row_index" min="1" value="1" />
                        <button type="submit" class="button" name="s2p_action" value="test_row">Test Row (Preview)</button>
                      </div>

                      <button type="submit" class="button button-secondary" name="s2p_action" value="sync_one">Sync This Sheet</button>

                      <button type="button" class="button s2p-remove-sheet" data-index="<?php echo intval($i); ?>">
                        <span class="s2p-danger">Remove Sheet</span>
                      </button>
                    </div>

                    <p class="s2p-help">
                      Tip: Use <strong>Test Row</strong> before syncing. It previews what would happen, without creating/updating anything.
                    </p>
                  </div>
                </div>
              </details>
            <?php endforeach; ?>
          </div>

          <?php if (is_array($last_preview) && !empty($last_preview)): ?>
            <div style="margin-top:16px;">
              <div class="notice notice-info is-dismissible">
                <p><strong>Test Row Preview</strong> (<?php echo esc_html($last_preview['sheet_name']); ?> • Row #<?php echo intval($last_preview['row_index']); ?> of <?php echo intval($last_preview['max_rows']); ?>)</p>
              </div>

              <?php if (!empty($last_preview['error'])): ?>
                <div class="notice notice-warning is-dismissible"><p><?php echo esc_html($last_preview['error']); ?></p></div>
              <?php else: ?>
                <div class="s2p-preview">
                  <div class="s2p-box"><strong>Result:</strong> <?php echo esc_html($last_preview['action']); ?>

<?php echo esc_html($last_preview['meta']); ?></div>

                  <div class="s2p-row" style="margin-top:12px;">
                    <div class="s2p-box"><strong>Title:</strong> <?php echo esc_html($last_preview['title']); ?></div>
                    <div class="s2p-box"><strong>Status:</strong> <?php echo esc_html($last_preview['status']); ?></div>
                    <div class="s2p-box"><strong>Post Type:</strong> <?php echo esc_html($last_preview['post_type']); ?></div>
                  </div>

                  <div class="s2p-row" style="margin-top:12px;">
                    <div class="s2p-box"><strong>Category:</strong> <?php echo esc_html($last_preview['category'] !== '' ? $last_preview['category'] : '(none)'); ?></div>
                    <div class="s2p-box"><strong>Tags:</strong> <?php echo esc_html(!empty($last_preview['tags']) ? implode(', ', $last_preview['tags']) : '(none)'); ?></div>
                  </div>

                  <div class="s2p-box" style="margin-top:12px;"><strong>Featured Image URL:</strong> <?php echo esc_html($last_preview['featured_image'] !== '' ? $last_preview['featured_image'] : '(none)'); ?></div>

                  <div style="margin-top:12px;">
                    <span class="s2p-label">Rendered Content Preview</span>
                    <div class="s2p-preview" style="box-shadow:none;">
                      <?php echo wp_kses_post($last_preview['rendered_content']); ?>
                    </div>
                    <p class="s2p-help" style="margin-top:10px;"><strong>Note:</strong> Preview only. No posts were created or updated.</p>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

        </form>
      </div>
    </div>

    <script>
      (function(){
        const sheetsWrap = document.getElementById('s2p-sheets');
        const addBtn = document.getElementById('s2p-add-sheet');

        function nextIndex(){
          const all = sheetsWrap.querySelectorAll('details.s2p-sheet');
          return all.length;
        }

        function makeSheetHTML(i){
          return `
            <details class="s2p-sheet" open>
              <summary>
                <div>
                  Sheet ${i+1}
                  <div class="s2p-sheet-meta">No sheet URL yet</div>
                </div>
                <div class="s2p-muted">Sheet #${i+1}</div>
              </summary>

              <div class="s2p-sheet-body">
                <div class="s2p-grid">

                  <div class="s2p-row">
                    <div>
                      <label class="s2p-label">Sheet Name</label>
                      <input type="text" name="s2p_sheets[${i}][name]" value="Sheet ${i+1}" style="width:100%;" />
                      <p class="s2p-help">This is just a label for your dashboard.</p>
                    </div>

                    <div>
                      <label class="s2p-label">Post Type</label>
                      <input type="text" name="s2p_sheets[${i}][post_type]" value="post" style="width:100%;" />
                      <p class="s2p-help">Use <span class="s2p-code">post</span> (default) or <span class="s2p-code">page</span>. Custom types later.</p>
                    </div>
                  </div>

                  <div>
                    <label class="s2p-label">Google Sheet link</label>
                    <input type="url" name="s2p_sheets[${i}][sheet_url]" value="" style="width:100%;" placeholder="Paste your Google Sheets share link here" />
                    <p class="s2p-help">Must be shared as: <strong>Anyone with the link → Viewer</strong>.</p>
                  </div>

                  <div class="s2p-row">
                    <div>
                      <label class="s2p-label">Mode</label>
                      <select name="s2p_sheets[${i}][mode]" class="s2p-mode" data-index="${i}" style="width:100%; max-width:420px;">
                        <option value="simple" selected>Simple Mode (Markdown)</option>
                        <option value="developer">Developer Mode (Template + Tokens)</option>
                      </select>
                      <p class="s2p-help">
                        Simple Mode uses <span class="s2p-code">content</span>. Developer Mode uses your template tokens like <span class="s2p-code">{{title}}</span>.
                      </p>
                    </div>

                    <div class="s2p-template-wrap" id="s2p-template-wrap-${i}" style="display:none;">
                      <label class="s2p-label">Developer Template</label>
                      <textarea class="s2p-template" name="s2p_sheets[${i}][template]"><h2>{{title}}</h2>
<p>{{content}}</p></textarea>
                      <p class="s2p-help">Tokens come from your header row. Example: <span class="s2p-code">{{title}}</span>, <span class="s2p-code">{{price}}</span>.</p>
                    </div>
                  </div>

                  <div class="s2p-actions">
                    <input type="hidden" name="s2p_sheet_index" value="${i}" />

                    <div class="s2p-inline">
                      <label class="s2p-label" style="margin:0;">Test row #</label>
                      <input class="s2p-small-input" type="number" name="s2p_test_row_index" min="1" value="1" />
                      <button type="submit" class="button" name="s2p_action" value="test_row">Test Row (Preview)</button>
                    </div>

                    <button type="submit" class="button button-secondary" name="s2p_action" value="sync_one">Sync This Sheet</button>

                    <button type="button" class="button s2p-remove-sheet" data-index="${i}">
                      <span class="s2p-danger">Remove Sheet</span>
                    </button>
                  </div>

                  <p class="s2p-help">
                    Tip: Use <strong>Test Row</strong> before syncing. It previews what would happen, without creating/updating anything.
                  </p>

                </div>
              </div>
            </details>
          `;
        }

        // Show/hide template when mode changes
        document.addEventListener('change', function(e){
          const el = e.target;
          if (el && el.classList && el.classList.contains('s2p-mode')) {
            const idx = el.getAttribute('data-index');
            const wrap = document.getElementById('s2p-template-wrap-' + idx);
            if (!wrap) return;
            wrap.style.display = (el.value === 'developer') ? '' : 'none';
          }
        });

        // Remove sheet (client-side)
        document.addEventListener('click', function(e){
          const btn = e.target.closest('.s2p-remove-sheet');
          if (!btn) return;
          const details = btn.closest('details.s2p-sheet');
          if (!details) return;

          // Soft confirm
          if (!confirm('Remove this sheet from the list? (You can re-add it later.)')) return;

          details.remove();
        });

        // Add new sheet
        if (addBtn) {
          addBtn.addEventListener('click', function(){
            const i = nextIndex();
            const div = document.createElement('div');
            div.innerHTML = makeSheetHTML(i);
            sheetsWrap.appendChild(div.firstElementChild);
          });
        }
      })();
    </script>
  </div>
  <?php
}
