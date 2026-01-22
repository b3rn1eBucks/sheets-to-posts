=== Sheets to Posts ===
Contributors: isleinsight
Tags: google sheets, csv, content import, post sync, automation
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync Google Sheets rows to WordPress posts safely and automatically.

== Description ==

Sheets to Posts lets you manage content in Google Sheets and publish or update posts in WordPress with one click or on a schedule.

No APIs. No complex setup. Just paste your Sheet link and sync.

== Features ==

- Sync Google Sheets to WordPress posts
- Multiple sheets support
- Simple Mode (Markdown formatting)
- Developer Mode (Template with {{tokens}})
- Scheduled sync (15 minutes, hourly, daily)
- Safe updates (never overwrites manual posts)
- Featured image support
- Categories and tags support
- Status control (draft, publish, private, pending, future)

== Safety ==

Sheets to Posts matches posts by exact title.

For safety, the plugin only updates posts it originally created.  
It adds a hidden post meta tag:

_s2p_source = sheets_to_posts

If a sheet row matches a post that was NOT created by this plugin, it will be skipped.

Your manual posts will never be overwritten.

== Installation ==

1. Upload the plugin ZIP file through Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Go to “Sheets to Posts” in the WordPress admin menu.
4. Add your Google Sheet link.
5. Click Sync.

== Quick Start ==

1. Open your Google Sheet.
2. Click Share and set to: Anyone with the link → Viewer.
3. In WordPress, go to Sheets to Posts.
4. Paste your Google Sheet link.
5. Click Sync.

== Required Sheet Columns ==

Minimum required:

- title
- content (required in Simple Mode)

Optional columns:

- category
- tags (comma separated)
- featured_image (direct image URL)
- status (draft, publish, private, pending, future)
- post_date (or date) for scheduled posts

Example header row:

title | content | category | tags | featured_image | status | post_date

== Simple Mode (Markdown) ==

Use formatting directly inside the content cell:

# Heading
**Bold text**
*Italic text*
- Bullet
- Bullet

== Developer Mode ==

Use tokens in your template:

<h2>{{title}}</h2>
<p>{{content}}</p>
<p>Price: {{price}}</p>

Any column name can be used as:

{{column_name}}

== Status Rules ==

New posts:
If the sheet provides a status, it is used.
If blank, the plugin uses your “Default status for NEW posts” setting.

Existing posts:
By default, the plugin preserves the current WordPress status.
Published posts stay published.

Optional:
Enable “Force status from sheet” if you want the sheet to control status on updates.

== Scheduled Sync ==

You can enable automatic syncing:

- Every 15 minutes
- Hourly
- Daily

WordPress scheduled tasks run when your site receives visits, so low-traffic sites may run slightly late.

Sheets to Posts includes a catch-up watchdog to reduce delays.

For exact timing, enable Real Cron (instructions available inside the plugin settings).

== Troubleshooting ==

Posts did not import:
- Check that your sheet is shared as “Anyone with the link → Viewer”.
- Make sure required columns exist.

Posts did not update:
- Titles must match exactly.
- Only posts created by this plugin will update.

Images did not set:
- Ensure the image URL is a direct link to the image file.

== Changelog ==

= 0.4.1 =
* Status preservation improvements
* Safe update system with _s2p_source meta tag
* Scheduled sync support
* Catch-up watchdog for low-traffic sites
* Multi-sheet support
