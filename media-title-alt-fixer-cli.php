<?php
/**
 * Plugin Name: Media Title & ALT Fixer (CLI)
 * Description: WP-CLI tool to audit and fix image attachment titles and alt text in bulk without changing slugs or files.
 * Version: 1.1.0
 * Author: Winston Porras
 * License: MIT
 * Author URI:  https://winstondev.site/
 * Plugin URI:  https://github.com/winston9310/media-title-alt-fixer-cli
 */

if (!defined('ABSPATH')) { exit; }

if (defined('WP_CLI') && WP_CLI) {

    /**
     * Class Media_Title_Alt_Fixer_CLI
     *
     * Usage: wp media-fixer fix [--execute] [--update-alt] [--include-keyword="Fresh Dog Food"] [--food-cats=food,recipes]
     *                    [--limit=500] [--batch-size=500] [--min-title-length=3] [--search-parent]
     *                    [--mapping=/path/to/mapping.csv] [--mime-include=image/jpeg,image/png]
     *                    [--mime-exclude=image/svg+xml] [--uploaded-after=2024-01-01] [--uploaded-before=2025-01-01]
     */
    class Media_Title_Alt_Fixer_CLI {

        /** @var array */
        private $mapping = array();

        /** @var bool */
        private $dry_run = true;

        /** @var bool */
        private $update_alt = false;

        /** @var string */
        private $include_kw = '';

        /** @var array */
        private $food_cats = array();

        /** @var bool */
        private $search_parent = false;

        /** @var int */
        private $limit = 0;

        /** @var int */
        private $batch_size = 500;

        /** @var int */
        private $min_len = 3;

        /** @var array|string[] */
        private $mime_include = array();

        /** @var array|string[] */
        private $mime_exclude = array();

        /** @var string|null */
        private $uploaded_after = null;

        /** @var string|null */
        private $uploaded_before = null;

        /**
         * Main command.
         *
         * ## OPTIONS
         *
         * [--execute]                 Persist changes (default is dry-run).
         * [--update-alt]              Update ALT text when missing or weird.
         * [--include-keyword=<kw>]    Keyword to append to titles (e.g., "Fresh Dog Food").
         * [--food-cats=<slugs>]       Comma list of category slugs that trigger keyword append (e.g., food,recipes).
         * [--limit=<n>]               Max attachments to process (default: no limit).
         * [--batch-size=<n>]          Batch size per query (default: 500; min: 50).
         * [--min-title-length=<n>]    Min title length to consider “not weird” (default: 3).
         * [--search-parent]           Try to find parent by content reference when orphaned (slower).
         * [--mapping=<path>]          CSV with attachment_id, proposed_title, proposed_alt.
         * [--mime-include=<list>]     Only process these MIME types (comma list).
         * [--mime-exclude=<list>]     Skip these MIME types (comma list).
         * [--uploaded-after=<YYYY-MM-DD>]  Only attachments uploaded after this date.
         * [--uploaded-before=<YYYY-MM-DD>] Only attachments uploaded before this date.
         *
         * ## EXAMPLES
         *
         *     # Dry-run (no changes)
         *     wp media-fixer fix
         *
         *     # Execute and update ALT
         *     wp media-fixer fix --execute --update-alt
         *
         *     # Append keyword when parent post is in food/recipes
         *     wp media-fixer fix --execute --update-alt --include-keyword="Fresh Dog Food" --food-cats=food,recipes
         *
         *     # Use mapping CSV
         *     wp media-fixer fix --execute --update-alt --mapping=/path/to/bad-images_proposed-fixes.csv
         *
         * @when after_wp_load
         */
        public function fix($args, $assoc_args) {
            $this->dry_run        = !$this->flag($assoc_args, 'execute');
            $this->update_alt     = $this->flag($assoc_args, 'update-alt');
            $this->search_parent  = $this->flag($assoc_args, 'search-parent');
            $this->include_kw     = isset($assoc_args['include-keyword']) ? trim($assoc_args['include-keyword']) : '';
            $this->food_cats      = $this->csv_to_array(isset($assoc_args['food-cats']) ? $assoc_args['food-cats'] : '');
            $this->limit          = isset($assoc_args['limit']) ? max(1, (int)$assoc_args['limit']) : 0;
            $this->batch_size     = isset($assoc_args['batch-size']) ? max(50, (int)$assoc_args['batch-size']) : 500;
            $this->min_len        = isset($assoc_args['min-title-length']) ? max(1, (int)$assoc_args['min-title-length']) : 3;
            $this->mime_include   = $this->csv_to_array(isset($assoc_args['mime-include']) ? $assoc_args['mime-include'] : '');
            $this->mime_exclude   = $this->csv_to_array(isset($assoc_args['mime-exclude']) ? $assoc_args['mime-exclude'] : '');
            $this->uploaded_after = isset($assoc_args['uploaded-after']) ? $this->sanitize_date($assoc_args['uploaded-after']) : null;
            $this->uploaded_before= isset($assoc_args['uploaded-before']) ? $this->sanitize_date($assoc_args['uploaded-before']) : null;

            $this->load_mapping(isset($assoc_args['mapping']) ? trim($assoc_args['mapping']) : '');

            WP_CLI::log('--- Media Title & ALT Fixer ---');
            WP_CLI::log('Mode: ' . ($this->dry_run ? 'DRY-RUN' : 'EXECUTE'));
            if ($this->update_alt)   WP_CLI::log('Will update ALT when missing/weird.');
            if ($this->include_kw)   WP_CLI::log('Keyword: "' . $this->include_kw . '" (cats: ' . implode(', ', $this->food_cats) . ')');
            if ($this->limit)        WP_CLI::log('Limit: ' . $this->limit);
            WP_CLI::log('Batch size: ' . $this->batch_size);
            if (!empty($this->mime_include)) WP_CLI::log('MIME include: ' . implode(', ', $this->mime_include));
            if (!empty($this->mime_exclude)) WP_CLI::log('MIME exclude: ' . implode(', ', $this->mime_exclude));
            if ($this->uploaded_after)  WP_CLI::log('Uploaded after: ' . $this->uploaded_after);
            if ($this->uploaded_before) WP_CLI::log('Uploaded before: ' . $this->uploaded_before);
            if ($this->search_parent) WP_CLI::warning('search-parent enabled: this may be slow.');

            $paged   = 1;
            $updated_titles = 0;
            $updated_alts   = 0;
            $scanned = 0;
            $skipped_no_parent = 0;
            $skipped_ok = 0;

            do {
                $query_args = array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'post_mime_type' => 'image',
                    'posts_per_page' => $this->batch_size,
                    'paged'          => $paged,
                    'fields'         => 'ids',
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                );

                // Uploaded date filters use post_date_gmt
                $meta_query = array();
                if ($this->uploaded_after || $this->uploaded_before) {
                    // We'll filter later using SQL WHERE via date bounds; WP_Query lacks direct range for attachments efficiently.
                }

                $q = new WP_Query($query_args);
                if (!$q->have_posts()) break;

                $progress = \WP_CLI\Utils\make_progress_bar("Batch $paged processing", count($q->posts));

                global $wpdb;

                foreach ($q->posts as $att_id) {
                    if ($this->limit && $scanned >= $this->limit) {
                        $progress->finish();
                        $this->finish($scanned, $updated_titles, $updated_alts, $skipped_no_parent, $skipped_ok);
                        return;
                    }

                    $scanned++;
                    $att = get_post($att_id);
                    if (!$att) { $progress->tick(); continue; }

                    // Filter by date range if provided
                    if ($this->uploaded_after || $this->uploaded_before) {
                        $dt = $att->post_date_gmt; // 'YYYY-MM-DD HH:MM:SS'
                        if ($this->uploaded_after && substr($dt,0,10) <= $this->uploaded_after) {
                            $progress->tick(); continue;
                        }
                        if ($this->uploaded_before && substr($dt,0,10) >= $this->uploaded_before) {
                            $progress->tick(); continue;
                        }
                    }

                    // MIME include/exclude
                    $mime = get_post_mime_type($att_id);
                    if (!empty($this->mime_include) && !in_array($mime, $this->mime_include, true)) {
                        $progress->tick(); continue;
                    }
                    if (!empty($this->mime_exclude) && in_array($mime, $this->mime_exclude, true)) {
                        $progress->tick(); continue;
                    }

                    $filename = $this->get_filename_basename($att_id);
                    $current_title = trim($att->post_title);

                    $parent_post = null;
                    if ((int)$att->post_parent > 0) {
                        $parent_post = get_post((int)$att->post_parent);
                    }

                    // If mapping exists and ID not present, skip (explicit control)
                    if (!empty($this->mapping) && !isset($this->mapping[$att_id])) {
                        $progress->tick();
                        continue;
                    }

                    $needs_title = false;
                    $new_title   = $current_title;

                    // If mapping provides title, use it directly
                    if (!empty($this->mapping) && isset($this->mapping[$att_id]['title']) && $this->mapping[$att_id]['title'] !== '') {
                        $new_title   = $this->mapping[$att_id]['title'];
                        $needs_title = (strcasecmp($new_title, $current_title) !== 0);
                    } else {
                        // No mapping: use heuristics
                        $needs_title = $this->is_weird_title($current_title, $filename, $this->min_len);

                        if ($needs_title) {
                            if (!$parent_post && $this->search_parent) {
                                $parent_post = $this->find_parent_by_reference($att_id);
                            }
                            if (!$parent_post) {
                                $skipped_no_parent++;
                                $progress->tick();
                                continue;
                            }

                            $base = trim(get_the_title($parent_post));
                            if ($base === '') {
                                $base = ($filename !== '' ? $filename : 'Image');
                            }

                            if ($this->include_kw !== '') {
                                if (!empty($this->food_cats)) {
                                    if ($this->post_has_any_category($parent_post->ID, $this->food_cats)) {
                                        $new_title = $base . ' – ' . $this->include_kw;
                                    } else {
                                        $new_title = $base;
                                    }
                                } else {
                                    $new_title = $base . ' – ' . $this->include_kw;
                                }
                            } else {
                                $new_title = $base;
                            }
                        }
                    }

                    // Update title if needed
                    if ($needs_title) {
                        $keep_slug = $att->post_name;
                        if ($this->dry_run) {
                            WP_CLI::log("[DRY] #$att_id title: '{$current_title}' => '{$new_title}' (keep slug: {$keep_slug})");
                        } else {
                            wp_update_post(array(
                                'ID'         => $att_id,
                                'post_title' => $new_title,
                                'post_name'  => $keep_slug, // keep slug
                            ));
                            $updated_titles++;
                        }
                    } else {
                        $skipped_ok++;
                    }

                    // ALT handling
                    if ($this->update_alt) {
                        $alt_now = get_post_meta($att_id, '_wp_attachment_image_alt', true);
                        $alt_is_weird = $this->is_weird_title(trim((string)$alt_now), $filename, $this->min_len);

                        // Mapping ALT overrides if provided
                        if (!empty($this->mapping) && isset($this->mapping[$att_id]['alt']) && $this->mapping[$att_id]['alt'] !== '') {
                            $alt_target = $this->mapping[$att_id]['alt'];
                            if ($this->dry_run) {
                                WP_CLI::log("[DRY] #$att_id alt: '{$alt_now}' => '{$alt_target}' (from mapping)");
                            } else {
                                update_post_meta($att_id, '_wp_attachment_image_alt', $alt_target);
                                $updated_alts++;
                            }
                        } elseif ($alt_is_weird) {
                            $alt_val = $needs_title ? $new_title : ($current_title !== '' ? $current_title : ($filename ?: 'Image'));
                            if ($this->dry_run) {
                                WP_CLI::log("[DRY] #$att_id alt: '{$alt_now}' => '{$alt_val}'");
                            } else {
                                update_post_meta($att_id, '_wp_attachment_image_alt', $alt_val);
                                $updated_alts++;
                            }
                        }
                    }

                    $progress->tick();
                }

                $progress->finish();
                $paged++;

            } while (true);

            $this->finish($scanned, $updated_titles, $updated_alts, $skipped_no_parent, $skipped_ok);
        }

        // -------- Helpers --------

        private function finish($scanned, $updated_titles, $updated_alts, $skipped_no_parent, $skipped_ok) {
            WP_CLI::success("Scanned: $scanned | Updated titles: $updated_titles | Updated ALTs: $updated_alts | Skipped (no parent): $skipped_no_parent | Skipped OK: $skipped_ok");
            if ($this->dry_run) {
                WP_CLI::log('Dry run only. Re-run with --execute to persist changes.');
            }
        }

        private function flag($assoc_args, $key) {
            return isset($assoc_args[$key]) && (false !== $assoc_args[$key]);
        }

        private function csv_to_array($str) {
            $str = trim((string)$str);
            if ($str === '') return array();
            $parts = explode(',', $str);
            $out = array();
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') $out[] = $p;
            }
            return $out;
        }

        private function sanitize_date($date) {
            $date = trim((string)$date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $date;
            }
            WP_CLI::warning("Invalid date format: $date (expected YYYY-MM-DD). Ignoring.");
            return null;
        }

        private function get_filename_basename($att_id) {
            $file = get_attached_file($att_id);
            if (!$file) {
                $guid = get_post_field('guid', $att_id);
                if ($guid) {
                    $path = wp_parse_url($guid, PHP_URL_PATH);
                    if ($path) $file = basename($path);
                }
            }
            if ($file) {
                $base = pathinfo($file, PATHINFO_FILENAME);
                // humanize
                $base = preg_replace('/[_\-]+/u', ' ', (string)$base);
                $base = trim(preg_replace('/\s+/u', ' ', $base));
                return $base;
            }
            return '';
        }

        private function is_weird_title($title, $filename_base, $min_len) {
            $t = trim((string)$title);
            if ($t === '' || mb_strlen($t) < $min_len) return true;

            $patterns = array(
                '/^\d+$/u',                                   // only numbers
                '/^[a-f0-9]{8,}$/iu',                         // long hex
                '/^(img|image|dsc|photo|screenshot)[\s_\-]*\d{1,6}$/iu',
                '/^\d{8}[\s_\-]\d{6}$/u',                     // 20240708_123456
                '/^untitled$/iu',
                '/^copy of .*$/iu',
            );
            foreach ($patterns as $re) {
                if (preg_match($re, $t)) return true;
            }

            $fb = trim((string)$filename_base);
            if ($fb !== '' && strcasecmp($t, $fb) === 0) {
                foreach ($patterns as $re) {
                    if (preg_match($re, $fb)) return true;
                }
            }

            return false;
        }

        private function post_has_any_category($post_id, $slugs) {
            $terms = get_the_terms($post_id, 'category');
            if (is_wp_error($terms) || empty($terms)) return false;
            foreach ($terms as $t) {
                if (in_array($t->slug, $slugs, true)) return true;
            }
            return false;
        }

        private function find_parent_by_reference($att_id) {
            global $wpdb;

            // Try by “wp-image-ID” reference
            $like1 = '%wp-image-' . (int)$att_id . '%';
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type IN ('post','page')
                 AND post_status IN ('publish','future','draft','pending','private')
                 AND post_content LIKE %s
                 ORDER BY post_date_gmt DESC
                 LIMIT 1",
                $like1
            ));
            if ($post_id) return get_post((int)$post_id);

            // Try by GUID basename reference
            $guid = get_post_field('guid', $att_id);
            if ($guid) {
                $like2 = '%' . $wpdb->esc_like(basename($guid)) . '%';
                $post_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type IN ('post','page')
                     AND post_status IN ('publish','future','draft','pending','private')
                     AND post_content LIKE %s
                     ORDER BY post_date_gmt DESC
                     LIMIT 1",
                    $like2
                ));
                if ($post_id) return get_post((int)$post_id);
            }

            return null;
        }

        private function load_mapping($mapping_path) {
            $this->mapping = array();
            if ($mapping_path === '') return;

            if (!file_exists($mapping_path)) {
                WP_CLI::warning('Mapping file not found: ' . $mapping_path);
                return;
            }
            $h = fopen($mapping_path, 'r');
            if (!$h) {
                WP_CLI::warning('Could not open mapping file: ' . $mapping_path);
                return;
            }

            $header = fgetcsv($h);
            if ($header === false) {
                fclose($h);
                WP_CLI::warning('Empty mapping file.');
                return;
            }
            $idx = array_flip(array_map('strtolower', $header));

            $id_k   = isset($idx['attachment_id']) ? $idx['attachment_id'] : null;
            $title_k= isset($idx['proposed_title']) ? $idx['proposed_title'] : null;
            $alt_k  = isset($idx['proposed_alt']) ? $idx['proposed_alt'] : null;

            if ($id_k === null) {
                fclose($h);
                WP_CLI::warning('Mapping missing required column: attachment_id');
                return;
            }

            $loaded = 0;
            while (($row = fgetcsv($h)) !== false) {
                $id = (int)$row[$id_k];
                if ($id <= 0) continue;
                $title = ($title_k !== null && isset($row[$title_k])) ? trim((string)$row[$title_k]) : '';
                $alt   = ($alt_k   !== null && isset($row[$alt_k]))   ? trim((string)$row[$alt_k])   : '';
                $this->mapping[$id] = array('title' => $title, 'alt' => $alt);
                $loaded++;
            }
            fclose($h);
            WP_CLI::log('Loaded mapping rows: ' . $loaded);
        }
    }

    WP_CLI::add_command('media-fixer', 'Media_Title_Alt_Fixer_CLI');
}