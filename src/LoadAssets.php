<?php

namespace BlockFinder;

// Import the WP_Block_Type_Registry class
use WP_Block_Type_Registry;

class LoadAssets
{
    public function enqueueAdminAssets()
    {
        $script_path = 'build/block-finder.js';
        $style_path = 'build/block-finder.css';
        $asset_handle = 'block-finder';
        
        wp_enqueue_script($asset_handle . '-script', plugins_url( $script_path, __DIR__), [], false, true);
        wp_enqueue_style($asset_handle . '-style', plugins_url( $style_path, __DIR__), [], false);

        wp_localize_script($asset_handle . '-script', 'blockFinderAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);
    }

    public function blockFinderDashboard()
    {
        add_meta_box('block_finder', 'Block Finder', [$this, 'findBlockForm'], 'dashboard', 'normal', 'default');
    }

    public function findBlockForm()
    {
        $all_blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();

        // echo '<pre>';
        // var_dump($all_blocks);
        // echo '</pre>';

        echo '<form id="block-finder-form">';
        echo '<label for="block-finder-selector">Select a block to return a list of where it is being used on your site.</label>';
        echo '<select id="block-finder-selector" name="block">';
        
        // Iterate over each block and generate the option elements
        foreach ($all_blocks as $block_name => $block_type) {
            if (!empty($block_type->title)) {
                echo '<option value="' . esc_attr($block_name) . '">' . esc_html($block_type->title) . '</option>';
            }
        }
        
        echo '</select>';
        echo '<button type="submit">Find Block</button>';
        echo '</form>';
        echo '<div id="block-finder-results"></div>';
    }

    public function blockQuery()
    {
        
        $block = sanitize_text_field($_POST['block']);
        $block_name = str_replace('core/', '', $block);
        $patterns = [
            '/<!-- wp:' . preg_quote($block_name, '/') . '(.*?)-->/' => $block_name,
        ];

        $post_types = ['page'];
        $found_elements = [];

        foreach ($post_types as $post_type) {
            $args = [
                'post_type' => array($post_type),
                'nopaging' => true,
                'posts_per_page' => '-1',
            ];

            $query = new \WP_Query($args);

            while ($query->have_posts()) {
                $query->the_post();
                $content = get_post_field('post_content', get_the_ID());
                $post_id = get_the_ID();
                $post_title = get_the_title();
                $edit_link = get_edit_post_link($post_id);

                foreach ($patterns as $pattern => $category) {
                    if (preg_match($pattern, $content)) {
                        $found_elements[$category][] = '<li><a href="' . esc_url($edit_link) . '">' . esc_html($post_title) . '</a></li>';
                    }
                }
            }

            wp_reset_postdata();
        }

        if (!empty($found_elements)) {
            foreach ($found_elements as $category => $posts) {
                $category_title = ucwords(str_replace('-', ' ', $category)) . ' Block';
                echo '<h3 style="font-weight:bold;border-top:1px solid #c6c6c6; margin-top:20px;padding-top:20px;">' . esc_html($category_title) . '</h3>';
                echo '<ul>' . implode('', $posts) . '</ul>';
            }
        } else {
            echo "<ul><li>No blocks found</li></ul>";
        }

        wp_die();
    }
}
