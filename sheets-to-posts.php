<?php
/**
 * Plugin Name: Sheets to Posts
 * Description: Sync Google Sheets rows to WordPress posts with Simple (Markdown) or Developer (Template) modes.
 * Version: 0.3.4
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

    if (preg_match('/^-\s+(.*)$/', $trimmed, $m)) {
      if (!$in_list) {
        $html .= "<ul>\n";
        $in_list = true;
      }
      $item = esc_html($m[1]);
      $html .= "<li>{$item}</li>\n";
      continue;
    }

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

  if (isset($map['content_html'])) {
    $raw = isset($row[$map['content_html']]) ? (string)$row[$map['content_html']] : '';
    $out = str_replace('{{content_html}}', $raw, $out);
  }

  return wp_kses_post($out);
}

/**
 * Build row hash.
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

  $saved_url  = get_option('s2p_sheet_url', '');
  $saved_mode = get_option('s2p_mode', 'simple');
  $saved_tpl  = get_option('s2p_template', "<h2>{{title}}</h2>\n<p>{{content}}</p>");

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

      <p><strong>Paste your Google Sheet link and click Sync.</strong></p>

      <form method="post">
        <?php wp_nonce_field('s2p_settings_save'); ?>

        <input
          type="url"
          name="s2p_sheet_url"
          value="<?php echo esc_attr($saved_url); ?>"
          placeholder="Paste your Google Sheets share link here"
          style="width:100%; max-width:920px;"
        />

        <p style="margin-top:12px;">
          <button type="submit" class="button button-primary" name="s2p_save_settings" value="1">
            Save Sheet
          </button>

          <button type="submit" class="button button-secondary" name="s2p_run_sync" value="1">
            Sync Now
          </button>
        </p>
      </form>

    </div>
  </div>
  <?php
}
