<?php
/**
 * Plugin Name: Sheets to Posts
 * Description: Sync Google Sheets rows to WordPress posts with Simple (Markdown) or Developer (Template) modes.
 * Version: 0.3.6
 * Author: Isle Insight
 */

if (!defined('ABSPATH')) { exit; }

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
 * Convert a normal Google Sheets link into a CSV export link.
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

function s2p_render_settings_page() {
  if (!current_user_can('manage_options')) { return; }

  // Load saved options
  $saved_url  = get_option('s2p_sheet_url', '');
  $saved_mode = get_option('s2p_mode', 'simple');
  $saved_tpl  = get_option('s2p_template', "<h2>{{title}}</h2>\n<p>{{content}}</p>");

  /**
   * Helper: load sheet rows and header map.
   */
  $s2p_load_sheet = function() use ($saved_url) {
    $csv_url = s2p_to_csv_url($saved_url);
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
  };

  // Save settings
  if (isset($_POST['s2p_save_settings'])) {
    check_admin_referer('s2p_settings_save');

    $sheet_url = isset($_POST['s2p_sheet_url']) ? esc_url_raw(trim($_POST['s2p_sheet_url'])) : '';
    $mode      = isset($_POST['s2p_mode']) ? sanitize_text_field($_POST['s2p_mode']) : 'simple';
    $template  = isset($_POST['s2p_template']) ? (string)wp_unslash($_POST['s2p_template']) : '';

    update_option('s2p_sheet_url', $sheet_url);
    update_option('s2p_mode', $mode);
    update_option('s2p_template', $template);

    $saved_url  = $sheet_url;
    $saved_mode = $mode;
    $saved_tpl  = $template;

    echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
  }

  /**
   * TEST ANY ROW (preview only)
   */
  if (isset($_POST['s2p_test_row'])) {
    check_admin_referer('s2p_settings_save');

    $requested_index = isset($_POST['s2p_test_row_index']) ? intval($_POST['s2p_test_row_index']) : 1;
    if ($requested_index < 1) { $requested_index = 1; }

    $loaded = $s2p_load_sheet();

    if (is_wp_error($loaded)) {
      echo '<div class="notice notice-error is-dismissible"><p>Error: ' . esc_html($loaded->get_error_message()) . '</p></div>';
    } else {

      $map = $loaded['map'];
      $data_rows = $loaded['data_rows'];
      $max = count($data_rows);

      if ($requested_index > $max) { $requested_index = $max; }

      if (!isset($map['title']) || (!isset($map['content']) && $saved_mode !== 'developer')) {
        echo '<div class="notice notice-error is-dismissible"><p>';
        echo 'Your sheet must have a header row with at least <strong>title</strong>. ';
        if ($saved_mode === 'simple') {
          echo 'Simple Mode also requires <strong>content</strong>.';
        } else {
          echo 'Developer Mode uses your template tokens (ex: {{title}}).';
        }
        echo '</p></div>';
      } else {

        $row = $data_rows[$requested_index - 1];
        $title = sanitize_text_field(s2p_cell($row, $map, 'title'));

        if ($title === '') {
          echo '<div class="notice notice-warning is-dismissible"><p>Test row #' . intval($requested_index) . ': this row has an empty <strong>title</strong>.</p></div>';
        } else {

          if ($saved_mode === 'developer') {
            $final_content = s2p_apply_template($saved_tpl, $row, $map);
          } else {
            $content_raw = s2p_cell($row, $map, 'content');
            $final_content = wp_kses_post(s2p_markdown_to_html($content_raw));
          }

          $category_name = sanitize_text_field(s2p_cell($row, $map, 'category'));
          $tags_string   = s2p_cell($row, $map, 'tags');
          $image_url     = esc_url_raw(s2p_cell($row, $map, 'featured_image'));
          $status_raw    = strtolower(trim(s2p_cell($row, $map, 'status')));
          $status        = ($status_raw === 'publish') ? 'publish' : 'draft';

          $row_hash = s2p_build_row_hash($saved_mode, $saved_tpl, $title, $final_content, $category_name, $tags_string, $image_url, $status);

          $existing_id = s2p_find_post_by_title($title);

          $action = 'Would CREATE a new post';
          $meta_line = '';

          if ($existing_id) {
            $prev_hash = get_post_meta($existing_id, '_s2p_row_hash', true);
            if ($prev_hash && $prev_hash === $row_hash) {
              $action = 'Would do NOTHING (unchanged)';
              $meta_line = 'Matched existing post ID ' . intval($existing_id) . ' and content hash is unchanged.';
            } else {
              $action = 'Would UPDATE an existing post';
              $meta_line = 'Matched existing post ID ' . intval($existing_id) . ' (changes detected).';
            }
          } else {
            $meta_line = 'No existing post found with this exact title.';
          }

          $parsed_tags = s2p_parse_tags($tags_string);
          $tags_preview = !empty($parsed_tags) ? implode(', ', array_map('sanitize_text_field', $parsed_tags)) : '(none)';
          $cat_preview = ($category_name !== '') ? $category_name : '(none)';
          $img_preview = ($image_url !== '') ? $image_url : '(none)';

          echo '<div class="notice notice-info is-dismissible"><p><strong>Test Row Preview</strong> (Row #' . intval($requested_index) . ' of ' . intval($max) . ')</p></div>';

          echo '<div class="s2p-card" style="margin-top:12px;">';
          echo '<div class="s2p-grid">';

          echo '<div><div class="s2p-box"><strong>Result:</strong> ' . esc_html($action) . "\n" . esc_html($meta_line) . '</div></div>';

          echo '<div class="s2p-row">';
          echo '<div><div class="s2p-box"><strong>Title:</strong> ' . esc_html($title) . '</div></div>';
          echo '<div><div class="s2p-box"><strong>Status:</strong> ' . esc_html($status) . '</div></div>';
          echo '</div>';

          echo '<div class="s2p-row">';
          echo '<div><div class="s2p-box"><strong>Category:</strong> ' . esc_html($cat_preview) . '</div></div>';
          echo '<div><div class="s2p-box"><strong>Tags:</strong> ' . esc_html($tags_preview) . '</div></div>';
          echo '</div>';

          echo '<div><div class="s2p-box"><strong>Featured Image URL:</strong> ' . esc_html($img_preview) . '</div></div>';

          echo '<div>';
          echo '<span class="s2p-label">Rendered Content Preview</span>';
          echo '<div style="border:1px solid #dcdcde; border-radius:12px; padding:14px; background:#fff;">';
          echo wp_kses_post($final_content);
          echo '</div>';
          echo '<p class="s2p-help" style="margin-top:10px;"><strong>Note:</strong> This is a preview only. It does not create or update posts.</p>';
          echo '</div>';

          echo '</div>';
          echo '</div>';
        }
      }
    }
  }

  /**
   * SYNC (real create/update)
   */
  if (isset($_POST['s2p_run_sync'])) {
    check_admin_referer('s2p_settings_save');

    $loaded = $s2p_load_sheet();

    if (is_wp_error($loaded)) {
      echo '<div class="notice notice-error is-dismissible"><p>Error: ' . esc_html($loaded->get_error_message()) . '</p></div>';
    } else {

      $map = $loaded['map'];
      $data_rows = $loaded['data_rows'];

      if (!isset($map['title']) || (!isset($map['content']) && $saved_mode !== 'developer')) {
        echo '<div class="notice notice-error is-dismissible"><p>';
        echo 'Your sheet must have a header row with at least <strong>title</strong>. ';
        if ($saved_mode === 'simple') {
          echo 'Simple Mode also requires <strong>content</strong>.';
        } else {
          echo 'Developer Mode uses your template tokens (ex: {{title}}).';
        }
        echo '</p></div>';
      } else {

        $count = count($data_rows);
        echo '<div class="notice notice-info is-dismissible"><p>';
        echo 'Sheet read successfully! I see ' . intval($count) . ' rows to process (header row not counted).';
        echo '</p></div>';

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

          if ($category_name !== '') {
            $cat_id = s2p_ensure_category($category_name);
            if ($cat_id) { wp_set_post_categories($post_id, [$cat_id], false); }
          }

          $tags = s2p_parse_tags($tags_string);
          if (!empty($tags)) { wp_set_post_tags($post_id, $tags, false); }

          if ($image_url !== '') {
            $img_result = s2p_set_featured_image_from_url($post_id, $image_url);
            if (is_wp_error($img_result)) { $img_fail++; } else { $img_set++; }
          }
        }

        echo '<div class="notice notice-success is-dismissible"><p>';
        echo 'Done! Created ' . intval($created) . ', updated ' . intval($updated) . ', unchanged ' . intval($unchanged) . ', skipped ' . intval($skipped) . '. ';
        echo 'Featured images set: ' . intval($img_set) . ' (failed: ' . intval($img_fail) . ').';
        echo '</p></div>';
      }
    }
  }

  // Non-fatal: try to count data rows for nicer UI
  $data_row_count = 0;
  if (!empty($saved_url)) {
    $loaded_for_count = $s2p_load_sheet();
    if (!is_wp_error($loaded_for_count)) {
      $data_row_count = count($loaded_for_count['data_rows']);
    }
  }

  $default_test_row = isset($_POST['s2p_test_row_index']) ? intval($_POST['s2p_test_row_index']) : 1;
  if ($default_test_row < 1) { $default_test_row = 1; }

  ?>
  <div class="wrap">
    <style>
      .s2p-card{
        background:#fff;
        border:1px solid #dcdcde;
        border-radius:12px;
        padding:16px;
        max-width:920px;
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

      .s2p-logo{
        max-width:240px;
        height:auto;
        display:block;
      }

      .s2p-tagline{
        color:#646970;
        font-size:13px;
        line-height:1.4;
      }

      .s2p-grid{ display:grid; grid-template-columns:1fr; gap:14px; }
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

      .s2p-inline{
        display:flex; gap:10px; flex-wrap:wrap; align-items:center;
      }

      .s2p-small-input{
        width:120px;
      }
    </style>

    <h1>Sheets to Posts</h1>

    <div class="s2p-card">

      <div class="s2p-header">
        <img
          class="s2p-logo"
          src="<?php echo esc_url( plugins_url('assets/S2P-logo.jpg', __FILE__) ); ?>"
          alt="Sheets to Posts"
        />
        <div class="s2p-tagline">
          From Google Sheets to WordPress posts. Fast, clean, and repeatable.
        </div>
      </div>

      <form method="post">
        <?php wp_nonce_field('s2p_settings_save'); ?>

        <div class="s2p-grid">
          <div>
            <label class="s2p-label" for="s2p_sheet_url">Google Sheet link</label>
            <input
              type="url"
              id="s2p_sheet_url"
              name="s2p_sheet_url"
              value="<?php echo esc_attr($saved_url); ?>"
              class="regular-text"
              placeholder="Paste your Google Sheets share link here"
              style="width:100%; max-width:920px;"
            />
            <p class="s2p-help">Google Sheet must be shared as: <strong>Anyone with the link → Viewer</strong>.</p>

            <!-- Buttons moved under the textbox -->
            <div class="s2p-actions" style="margin-top:10px;">
              <button type="submit" class="button button-primary" name="s2p_save_settings" value="1">Save Settings</button>
              <button type="submit" class="button" name="s2p_test_row" value="1">Test Row (Preview)</button>
              <button type="submit" class="button button-secondary" name="s2p_run_sync" value="1">Sync Now</button>
            </div>

            <p class="s2p-help" style="margin-top:6px;">
              Tip: Use <strong>Test Row</strong> to preview what will happen. It does not create or update posts.
            </p>
          </div>

          <div class="s2p-row">
            <div>
              <label class="s2p-label" for="s2p_mode">Mode</label>
              <select id="s2p_mode" name="s2p_mode" style="width:100%; max-width:420px;">
                <option value="simple" <?php selected($saved_mode, 'simple'); ?>>Simple Mode (Markdown)</option>
                <option value="developer" <?php selected($saved_mode, 'developer'); ?>>Developer Mode (Template + Tokens)</option>
              </select>

              <p class="s2p-help">
                <strong>Simple Mode:</strong> Put formatting inside the <span class="s2p-code">content</span> cell.
                Wrap the exact word(s) you want to format:
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

              <p class="s2p-help">
                <strong>Developer Mode:</strong> Use a template with tokens like <span class="s2p-code">{{title}}</span>.
              </p>
            </div>

            <div>
              <label class="s2p-label" for="s2p_template">Developer Mode Template</label>
              <textarea id="s2p_template" name="s2p_template" class="s2p-template"><?php echo esc_textarea($saved_tpl); ?></textarea>
              <p class="s2p-help">
                Tokens come from your header row. Examples:
                <span class="s2p-code">{{title}}</span>,
                <span class="s2p-code">{{content}}</span>,
                <span class="s2p-code">{{price}}</span>.
              </p>
            </div>
          </div>

          <div>
            <span class="s2p-label">Supported Sheet Columns</span>
            <div class="s2p-box">Required: title
Simple Mode requires: content
Optional: category, tags, featured_image, status (draft/publish)

Template tokens (Developer Mode): use {{column_name}} for any header.</div>
          </div>

          <div class="s2p-inline">
            <label class="s2p-label" style="margin:0;">Test row #</label>
            <input
              type="number"
              name="s2p_test_row_index"
              class="s2p-small-input"
              min="1"
              value="<?php echo esc_attr($default_test_row); ?>"
            />
            <?php if ($data_row_count > 0): ?>
              <span class="s2p-help" style="margin:0;">(1 to <?php echo intval($data_row_count); ?>, header not counted)</span>
            <?php else: ?>
              <span class="s2p-help" style="margin:0;">(Row 1 = first data row under the headers)</span>
            <?php endif; ?>
          </div>

        </div>
      </form>
    </div>
  </div>
  <?php
}
