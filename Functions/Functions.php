<?php

namespace BlockFinder;

use WP_Block_Type_Registry;

class Functions
{
    protected $plugin_file;
    protected $version;

    public function __construct($plugin_file, $version)
    {
        $this->plugin_file = $plugin_file;
        $this->version = $version;
    }

    public function enqueueAdminAssets()
    {
        // Check if we are on the dashboard
        $current_screen = get_current_screen();
        if ($current_screen->base !== 'dashboard') {
            return; // Exit if not on the dashboard
        }

        $script_path = 'build/block-finder.js';
        $style_path = 'build/block-finder.css';
        $asset_handle = 'block-finder';

        wp_enqueue_script($asset_handle . '-script', plugins_url($script_path, $this->plugin_file), [], $this->version, true);
        wp_enqueue_style($asset_handle . '-style', plugins_url($style_path, $this->plugin_file), [], $this->version);

        wp_localize_script($asset_handle . '-script', 'blockFinderAjax', [
            'ajax_url' => esc_url(admin_url('admin-ajax.php')),
            'nonce'    => wp_create_nonce('block_finder_nonce')
        ]);
    }

    public function blockFinderDashboard()
    {
        add_meta_box('block_finder', esc_html__('Block Finder', 'block-finder'), [$this, 'findBlockForm'], 'dashboard', 'normal', 'default');
    }

    public function findBlockForm()
    {
        $all_blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();

        // Get only blocks that can be inserted into the editor
        $inserter_blocks = array_filter($all_blocks, function ($block_type) {
            return isset($block_type->supports['inserter']) ? $block_type->supports['inserter'] : true;
        });

        // Sort inserter blocks alphabetically by title
        uasort($inserter_blocks, function ($a, $b) {
            return strcmp($a->title, $b->title);
        });

        // Get post types that support the Gutenberg editor
        $post_types = get_post_types(['public' => true], 'objects');
        $gutenberg_post_types = array_filter($post_types, function ($post_type) {
            return post_type_supports($post_type->name, 'editor');
        });

        // Sort Gutenberg post types alphabetically by label
        uasort($gutenberg_post_types, function ($a, $b) {
            return strcmp($a->label, $b->label);
        });

        echo '<form id="block-finder-form">';
        echo '<label for="post-type-selector">' . esc_html__('Select a post type you wish to search in', 'block-finder') . '</label>';
        echo '<select id="post-type-selector" name="post_type">';
        echo '<option value="">' . esc_html__('-- Select post type --', 'block-finder') . '</option>';
        echo '<option value="all">' . esc_html__('All Post Types', 'block-finder') . '</option>'; // Add this line
        foreach ($gutenberg_post_types as $post_type) {
            echo '<option value="' . esc_attr($post_type->name) . '">' . esc_html($post_type->label) . '</option>';
        }
        echo '</select>';

        echo '<label for="block-finder-selector">' . esc_html__('Select a block you would like to search for', 'block-finder') . '</label>';
        echo '<select id="block-finder-selector" name="block">';
        echo '<option value="">' . esc_html__('-- Select block --', 'block-finder') . '</option>';
        foreach ($inserter_blocks as $block_name => $block_type) {
            if (!empty($block_type->title)) {
                echo '<option value="' . esc_attr($block_name) . '">' . esc_html($block_type->title) . '</option>';
            }
        }
        echo '</select>';

        // Use WordPress button classes
        echo '<button type="submit" class="button button-primary">' . esc_html__('Find Block', 'block-finder') . '</button>';
        echo '</form>';
        echo '<div id="block-finder-results"></div>';
    }

    public function blockQuery()
    {
        if (!check_ajax_referer('block_finder_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Nonce verification failed.', 'block-finder')], 400);
            wp_die();
        }

        if (empty($_POST['block']) || empty($_POST['post_type'])) {
            wp_send_json_error(['message' => esc_html__('Block and post type values are required.', 'block-finder')], 400);
            wp_die();
        }

        $block = sanitize_text_field(wp_unslash($_POST['block']));
        $post_type = sanitize_text_field(wp_unslash($_POST['post_type']));

        $block_name = str_replace('core/', '', $block);
        $patterns = [
            '/<!-- wp:' . preg_quote($block_name, '/') . '(.*?)-->/' => $block_name,
        ];

        $found_elements = [];

        if ($post_type === 'all') {
            $post_types = get_post_types(['public' => true], 'names');
            $gutenberg_post_types = array_filter($post_types, function ($post_type_name) {
                return post_type_supports($post_type_name, 'editor');
            });

            $args = [
                'post_type' => array_values($gutenberg_post_types),
                'nopaging' => true,
                'posts_per_page' => -1,
            ];
        } else {
            $args = [
                'post_type' => [$post_type],
                'nopaging' => true,
                'posts_per_page' => -1,
            ];
        }

        $query = new \WP_Query($args);

        if (!$query->have_posts()) {
            wp_send_json_error(['message' => esc_html__('No posts found for the selected post type.', 'block-finder')], 404);
        }

        while ($query->have_posts()) {
            $query->the_post();
            $content = get_post_field('post_content', get_the_ID());
            $post_id = get_the_ID();
            $post_title = get_the_title();
            $post_url = get_permalink();
            $edit_link = get_edit_post_link($post_id);

            if (!$post_title) {
                $post_title = esc_html__('No title available', 'block-finder');
            }

            foreach ($patterns as $pattern => $category) {
                if (preg_match($pattern, $content)) {
                    $found_elements[$category][] = '<li>' . esc_html($post_title) . '<span><a href="' . esc_url($edit_link) . '">' . esc_html__('Edit', 'block-finder') . '</a><a href="' . esc_url($post_url) . '">' . esc_html__('View', 'block-finder') . '</a></span></li>';
                }
            }
        }

        wp_reset_postdata();

        if (!empty($found_elements)) {
            foreach ($found_elements as $category => $posts) {
                $category_title = esc_html(ucwords(str_replace('-', ' ', $category)) . ' Block');
                echo '<h3>' . esc_html($category_title) . esc_html__(' is used in the following:', 'block-finder') . '</h3>';
                echo '<ul>' . wp_kses_post(implode('', $posts)) . '</ul>';
            }
        } else {
            echo '<ul><li>' . esc_html__('No blocks found', 'block-finder') . '</li></ul>';
        }


        wp_die();
    }
}
