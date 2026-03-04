<?php
/**
 * Plugin Name: Wondalizer Commentador
 * Plugin URI: https://dealazer.com/wondalizer-commentador 
 * Description: Advanced comment management system with movable comments, list interpreter with search. Backend only - search shows posts only. Possible to move Woocommerce and Digital Downloads comments all around towards all posts in the comment sections.
 * Version: 1.0.0
 * Author: Cyprian Patryk Derda
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html 
 * Text Domain: wondalizer-commentador
 */

if (!defined('ABSPATH')) {
    exit;
}

class Commentador {
    
    private static $instance = null;
    private $plugin_path;
    private $plugin_url;
    private $option_name = 'commentador_settings';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);
        
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('wp_ajax_commentador_search_posts', array($this, 'ajax_search_posts'));
        add_action('wp_ajax_commentador_search_authors', array($this, 'ajax_search_authors'));
        add_action('wp_ajax_commentador_search_comments', array($this, 'ajax_search_comments'));
        add_action('wp_ajax_commentador_move_comment', array($this, 'ajax_move_comment'));
        add_action('wp_ajax_commentador_update_parent', array($this, 'ajax_update_parent'));
        add_action('add_meta_boxes', array($this, 'add_commentador_meta_box'));
        add_action('edit_form_after_title', array($this, 'modify_comment_edit_form'));
    }
    
    public function init() {
        load_plugin_textdomain('commentador', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function get_settings() {
        $defaults = array(
            'post' => '1',
            'page' => '1',
            'download' => '1',
            'product' => '1',
            'name_mode' => '0', // Default: post mode (off)
        );
        $settings = get_option($this->option_name, $defaults);
        return wp_parse_args($settings, $defaults);
    }
    
    public function is_name_mode_enabled() {
        $settings = $this->get_settings();
        return isset($settings['name_mode']) && $settings['name_mode'] === '1';
    }
    
    public function get_enabled_post_types() {
        $settings = $this->get_settings();
        $post_types = array();
        foreach ($settings as $key => $value) {
            if ($key !== 'name_mode' && $value === '1') {
                $post_types[] = $key;
            }
        }
        return $post_types;
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Commentador Settings',
            'Commentador',
            'manage_options',
            'commentador-settings',
            array($this, 'render_settings_page')
        );
    }
    
    public function register_settings() {
        register_setting('commentador_settings_group', $this->option_name, array($this, 'sanitize_settings'));
    }
    
    public function sanitize_settings($input) {
        $sanitized = array();
        $post_types = $this->get_all_post_types();
        
        foreach ($post_types as $pt => $label) {
            $sanitized[$pt] = isset($input[$pt]) ? '1' : '0';
        }
        
        // Sanitize name mode toggle
        $sanitized['name_mode'] = isset($input['name_mode']) ? '1' : '0';
        
        return $sanitized;
    }
    
    public function get_all_post_types() {
        $post_types = get_post_types(array('public' => true), 'objects');
        $exclude = array('attachment');
        $list = array();
        
        foreach ($post_types as $pt => $obj) {
            if (!in_array($pt, $exclude)) {
                $list[$pt] = $obj->label . ' (' . $pt . ')';
            }
        }
        
        return $list;
    }
    
    public function render_settings_page() {
        $settings = $this->get_settings();
        $post_types = $this->get_all_post_types();
        $name_mode = $this->is_name_mode_enabled();
        ?>
        <div class="wrap">
            <h1>Commentador Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('commentador_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Commentador for:</th>
                        <td>
                            <?php foreach ($post_types as $pt => $label) : 
                                $checked = isset($settings[$pt]) && $settings[$pt] === '1' ? 'checked' : '';
                                if (!isset($settings[$pt]) && in_array($pt, array('post', 'page', 'download', 'product'))) {
                                    $checked = 'checked';
                                }
                            ?>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="<?php echo esc_attr($this->option_name . '[' . $pt . ']'); ?>" value="1" <?php echo $checked; ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">Select which post types should have Commentador features enabled.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Search Mode</th>
                        <td>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="<?php echo esc_attr($this->option_name . '[name_mode]'); ?>" value="0" <?php checked($name_mode, false); ?>>
                                <strong>Post Mode</strong> - Search and move comments to posts (titles/content only)
                            </label>
                            <label style="display: block;">
                                <input type="radio" name="<?php echo esc_attr($this->option_name . '[name_mode]'); ?>" value="1" <?php checked($name_mode, true); ?>>
                                <strong>Name/Author Mode</strong> - Search authors/names and assign comments to specific users
                            </label>
                            <p class="description">
                                <strong>Post Mode:</strong> Shows only posts in search results. Best for organizing comments by content/topic.<br>
                                <strong>Name Mode:</strong> Shows author names with comment counts. Best for support tickets, team assignments, or user-based organization.
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <div class="card" style="max-width: 600px; margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #c3c4c7;">
                <h2>How It Works</h2>
                <?php if ($name_mode) : ?>
                <p><strong>Currently Active: Name/Author Mode</strong></p>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li>Search shows unique author names with comment counts</li>
                    <li>Click "View comments" to see all comments by that author</li>
                    <li>Select a name to move/assign comments to that author</li>
                    <li>System uses the most recent comment by selected author as parent</li>
                </ul>
                <?php else : ?>
                <p><strong>Currently Active: Post Mode</strong></p>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li>Search shows only posts (titles and content)</li>
                    <li>No author names or mixed information displayed</li>
                    <li>Select a post to move comment to that post</li>
                    <li>Comment becomes top-level on destination post</li>
                </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    public function admin_scripts($hook) {
        if ($hook !== 'edit-comments.php' && $hook !== 'comment.php' && $hook !== 'settings_page_commentador-settings') {
            return;
        }
        
        $name_mode = $this->is_name_mode_enabled();
        
        wp_enqueue_style('commentador-admin', $this->plugin_url . 'assets/admin.css', array(), '2.3.0');
        wp_enqueue_script('commentador-admin', $this->plugin_url . 'assets/admin.js', array('jquery', 'jquery-ui-sortable', 'jquery-ui-dialog'), '2.3.0', true);
        
        wp_localize_script('commentador-admin', 'commentador_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('commentador_nonce'),
            'name_mode' => $name_mode ? '1' : '0',
            'strings' => array(
                'search_posts_placeholder' => __('Search posts...', 'commentador'),
                'search_names_placeholder' => __('Search author names...', 'commentador'),
                'search_comments_placeholder' => __('Search comments...', 'commentador'),
                'move_to_post' => __('Move to post:', 'commentador'),
                'assign_to_name' => __('Assign to name:', 'commentador'),
                'select_post' => __('Select Post', 'commentador'),
                'select_name' => __('Select Name', 'commentador'),
                'select_comment' => __('Select Comment', 'commentador'),
                'in_response_to' => __('In response to:', 'commentador'),
                'no_results' => __('No posts found', 'commentador'),
                'no_names_found' => __('No authors found', 'commentador'),
                'no_comments' => __('No comments found', 'commentador'),
                'view_comments' => __('View comments', 'commentador'),
                'back_to_names' => __('Back to names', 'commentador'),
                'comments_by' => __('Comments by', 'commentador'),
                'confirm_move' => __('Confirm Move', 'commentador'),
                'cancel' => __('Cancel', 'commentador')
            )
        ));
        
        wp_add_inline_style('commentador-admin', $this->get_admin_inline_styles());
        wp_add_inline_script('commentador-admin', $this->get_admin_inline_script(), 'before');
    }
    
    private function get_admin_inline_styles() {
        return '
        .commentador-container {
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin: 20px 0;
            padding: 20px;
        }
        
        .commentador-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #2271b1;
        }
        
        .commentador-title {
            font-size: 16px;
            font-weight: 600;
            color: #1d2327;
            margin: 0;
        }
        
        .commentador-search-box {
            position: relative;
            margin-bottom: 15px;
        }
        
        .commentador-search-input {
            width: 100%;
            padding: 10px 15px;
            font-size: 14px;
            border: 2px solid #8c8f94;
            border-radius: 4px;
            background: #f6f7f7;
            transition: all 0.3s ease;
        }
        
        .commentador-search-input:focus {
            border-color: #2271b1;
            background: #fff;
            box-shadow: 0 0 0 1px #2271b1;
            outline: none;
        }
        
        .commentador-list-interpreter {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            background: #f6f7f7;
        }
        
        /* Post items - clean, no author info */
        .commentador-post-item {
            padding: 12px 15px;
            border-bottom: 1px solid #c3c4c7;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #fff;
        }
        
        .commentador-post-item:hover {
            background: #f0f6fc;
        }
        
        .commentador-post-item.selected {
            background: #2271b1;
            color: #fff;
            border-left: 4px solid #135e96;
        }
        
        .commentador-post-item.selected .commentador-post-meta,
        .commentador-post-item.selected .commentador-post-excerpt {
            color: #c5d9ed;
        }
        
        .commentador-post-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 4px;
            color: #1d2327;
        }
        
        .commentador-post-item.selected .commentador-post-title {
            color: #fff;
        }
        
        .commentador-post-meta {
            font-size: 11px;
            color: #646970;
            margin-bottom: 6px;
        }
        
        .commentador-post-excerpt {
            font-size: 12px;
            color: #3c434a;
            line-height: 1.4;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Name/Author items */
        .commentador-name-item {
            padding: 12px 15px;
            border-bottom: 1px solid #c3c4c7;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
        }
        
        .commentador-name-item:hover {
            background: #f0f6fc;
        }
        
        .commentador-name-item.selected {
            background: #2271b1;
            color: #fff;
        }
        
        .commentador-name-item.selected .commentador-name-count {
            color: #c5d9ed;
        }
        
        .commentador-name-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .commentador-name-content {
            flex: 1;
        }
        
        .commentador-name-author {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .commentador-name-count {
            font-size: 12px;
            color: #646970;
        }
        
        .commentador-view-comments-btn {
            padding: 4px 12px;
            background: #2271b1;
            color: #fff;
            border: none;
            border-radius: 3px;
            font-size: 12px;
            cursor: pointer;
        }
        
        .commentador-name-item.selected .commentador-view-comments-btn {
            background: #fff;
            color: #2271b1;
        }
        
        .commentador-back-btn {
            margin-bottom: 10px;
            padding: 6px 12px;
            background: #f6f7f7;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }
        
        .commentador-back-btn:hover {
            background: #f0f0f1;
        }
        
        /* Comment items */
        .commentador-comment-item {
            padding: 12px 15px;
            border-bottom: 1px solid #c3c4c7;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .commentador-comment-item:hover {
            background: #f0f0f1;
        }
        
        .commentador-comment-item.selected {
            background: #2271b1;
            color: #fff;
            border-left: 4px solid #135e96;
        }
        
        .commentador-comment-item.selected .commentador-comment-meta,
        .commentador-comment-item.selected .commentador-comment-excerpt {
            color: #fff;
        }
        
        .commentador-comment-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .commentador-comment-content {
            flex: 1;
            min-width: 0;
        }
        
        .commentador-comment-author {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 4px;
        }
        
        .commentador-comment-meta {
            font-size: 11px;
            color: #646970;
            margin-bottom: 6px;
        }
        
        .commentador-comment-excerpt {
            font-size: 12px;
            color: #3c434a;
            line-height: 1.4;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .commentador-move-controls {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #c3c4c7;
        }
        
        .commentador-btn {
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .commentador-btn-primary {
            background: #2271b1;
            color: #fff;
        }
        
        .commentador-btn-primary:hover {
            background: #135e96;
        }
        
        .commentador-btn-secondary {
            background: #f6f7f7;
            color: #2271b1;
            border: 1px solid #2271b1;
        }
        
        .commentador-btn-secondary:hover {
            background: #f0f6fc;
        }
        
        .commentador-response-field {
            background: #f0f6fc;
            border: 2px solid #2271b1;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
            display: none;
        }
        
        .commentador-response-field.active {
            display: block;
            animation: commentadorSlideIn 0.3s ease;
        }
        
        @keyframes commentadorSlideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .commentador-response-label {
            font-weight: 600;
            color: #2271b1;
            margin-bottom: 8px;
            display: block;
        }
        
        .commentador-response-content {
            background: #fff;
            padding: 10px;
            border-radius: 4px;
            border-left: 4px solid #2271b1;
        }
        
        .commentador-floating-panel {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-radius: 8px;
            padding: 20px;
            width: 350px;
            z-index: 1000;
            display: none;
        }
        
        .commentador-floating-panel.active {
            display: block;
            animation: commentadorSlideUp 0.3s ease;
        }
        
        @keyframes commentadorSlideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .commentador-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .commentador-panel-title {
            font-weight: 600;
            font-size: 14px;
        }
        
        .commentador-close-btn {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #8c8f94;
            line-height: 1;
        }
        
        .commentador-close-btn:hover {
            color: #d63638;
        }
        
        .commentador-quick-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }
        
        .commentador-status-message {
            position: fixed;
            top: 50px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 4px;
            font-weight: 500;
            z-index: 9999;
            display: none;
            animation: commentadorSlideInRight 0.3s ease;
        }
        
        @keyframes commentadorSlideInRight {
            from { opacity: 0; transform: translateX(100%); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .commentador-status-message.success {
            background: #00a32a;
            color: #fff;
            display: block;
        }
        
        .commentador-status-message.error {
            background: #d63638;
            color: #fff;
            display: block;
        }
        
        .commentador-row-actions {
            display: inline-flex;
            gap: 5px;
            margin-left: 10px;
        }
        
        .commentador-row-btn {
            padding: 2px 8px;
            font-size: 11px;
            border-radius: 3px;
            cursor: pointer;
            border: 1px solid #c3c4c7;
            background: #f6f7f7;
            color: #3c434a;
        }
        
        .commentador-row-btn:hover {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
        }
        
        .commentador-loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #2271b1;
            border-radius: 50%;
            animation: commentadorSpin 1s linear infinite;
        }
        
        @keyframes commentadorSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        ';
    }
    
    private function get_admin_inline_script() {
        return '
        jQuery(document).ready(function($) {
            if (pagenow === "edit-comments") {
                commentadorInitAdmin();
            }
            
            function commentadorInitAdmin() {
                const isNameMode = commentador_ajax.name_mode === "1";
                
                $("#the-comment-list tr").each(function() {
                    const commentId = $(this).attr("id").replace("comment-", "");
                    const actions = $(this).find(".row-actions");
                    
                    if (!actions.find(".commentador-move").length) {
                        actions.append(`<span class="commentador-move"> | <button class="commentador-row-btn commentador-move-btn" data-comment-id="${commentId}">Move</button></span>`);
                    }
                });
                
                if (!$("#commentador-admin-panel").length) {
                    const placeholder = isNameMode ? commentador_ajax.strings.search_names_placeholder : commentador_ajax.strings.search_posts_placeholder;
                    const title = isNameMode ? "Commentador - Assign to Name" : "Commentador - Move to Post";
                    const responseLabel = isNameMode ? commentador_ajax.strings.assign_to_name : commentador_ajax.strings.move_to_post;
                    
                    $("body").append(`
                        <div id="commentador-admin-panel" class="commentador-floating-panel">
                            <div class="commentador-panel-header">
                                <span class="commentador-panel-title">${title}</span>
                                <button class="commentador-close-btn">&times;</button>
                            </div>
                            <div class="commentador-search-box">
                                <input type="text" class="commentador-search-input" placeholder="${placeholder}">
                            </div>
                            <div class="commentador-list-interpreter" id="commentador-search-results"></div>
                            <div class="commentador-response-field" id="commentador-selected-target">
                                <span class="commentador-response-label">${responseLabel}</span>
                                <div class="commentador-response-content"></div>
                            </div>
                            <div class="commentador-quick-actions">
                                <button class="commentador-btn commentador-btn-primary" id="commentador-confirm-move">${commentador_ajax.strings.confirm_move}</button>
                                <button class="commentador-btn commentador-btn-secondary" id="commentador-cancel-move">${commentador_ajax.strings.cancel}</button>
                            </div>
                        </div>
                        <div id="commentador-status" class="commentador-status-message"></div>
                    `);
                }
                
                $(document).on("click", ".commentador-move-btn", function(e) {
                    e.preventDefault();
                    const commentId = $(this).data("comment-id");
                    $("#commentador-admin-panel").addClass("active").data("moving-comment", commentId);
                    $("#commentador-admin-panel").removeData("selected-name").removeData("target-post-id");
                    $("#commentador-admin-panel .commentador-search-input").focus();
                });
                
                $(".commentador-close-btn, #commentador-cancel-move").click(function() {
                    $("#commentador-admin-panel").removeClass("active");
                    resetCommentadorPanel();
                });
                
                let searchTimeout;
                $("#commentador-admin-panel .commentador-search-input").on("input", function() {
                    clearTimeout(searchTimeout);
                    const query = $(this).val();
                    if (query.length < 2) return;
                    
                    searchTimeout = setTimeout(function() {
                        if (isNameMode) {
                            commentadorSearchAuthors(query);
                        } else {
                            commentadorSearchPosts(query);
                        }
                    }, 300);
                });
                
                // Post mode: Click on post
                $(document).on("click", ".commentador-post-item", function() {
                    $(".commentador-post-item").removeClass("selected");
                    $(this).addClass("selected");
                    
                    const postId = $(this).data("post-id");
                    const title = $(this).find(".commentador-post-title").text();
                    const type = $(this).find(".commentador-post-meta").text();
                    
                    $("#commentador-selected-target").addClass("active")
                        .data("target-post-id", postId)
                        .find(".commentador-response-content")
                        .html(`<strong>${title}</strong><br><small>${type}</small>`);
                });
                
                // Name mode: Click on name
                $(document).on("click", ".commentador-name-item", function(e) {
                    if ($(e.target).hasClass("commentador-view-comments-btn")) return;
                    
                    $(".commentador-name-item").removeClass("selected");
                    $(this).addClass("selected");
                    
                    const authorName = $(this).data("author-name");
                    const commentCount = $(this).find(".commentador-name-count").text();
                    
                    $("#commentador-selected-target").addClass("active")
                        .data("target-name", authorName)
                        .find(".commentador-response-content")
                        .html(`<strong>${authorName}</strong><br><small>${commentCount}</small>`);
                });
                
                // Name mode: View comments button
                $(document).on("click", ".commentador-view-comments-btn", function(e) {
                    e.stopPropagation();
                    const authorName = $(this).closest(".commentador-name-item").data("author-name");
                    commentadorShowAuthorComments(authorName);
                });
                
                // Name mode: Back button
                $(document).on("click", ".commentador-back-btn", function() {
                    const query = $("#commentador-admin-panel .commentador-search-input").val();
                    commentadorSearchAuthors(query);
                });
                
                // Name mode: Select comment from list
                $(document).on("click", ".commentador-comment-item", function() {
                    $(".commentador-comment-item").removeClass("selected");
                    $(this).addClass("selected");
                    
                    const commentId = $(this).data("comment-id");
                    const author = $(this).find(".commentador-comment-author").text();
                    const excerpt = $(this).find(".commentador-comment-excerpt").text();
                    
                    $("#commentador-selected-target").data("target-comment-id", commentId);
                    $("#commentador-selected-target .commentador-response-content")
                        .html(`<strong>${author}</strong><br><small>${excerpt}</small>`);
                });
                
                $("#commentador-confirm-move").click(function() {
                    const movingId = $("#commentador-admin-panel").data("moving-comment");
                    
                    if (isNameMode) {
                        const targetName = $("#commentador-selected-target").data("target-name");
                        const targetCommentId = $("#commentador-selected-target").data("target-comment-id");
                        
                        if (!targetName && !targetCommentId) {
                            commentadorShowStatus("Please select an author name or comment", "error");
                            return;
                        }
                        
                        if (targetCommentId) {
                            commentadorMoveComment(movingId, targetCommentId);
                        } else {
                            commentadorMoveCommentToName(movingId, targetName);
                        }
                    } else {
                        const targetPostId = $("#commentador-selected-target").data("target-post-id");
                        
                        if (!targetPostId) {
                            commentadorShowStatus("Please select a target post", "error");
                            return;
                        }
                        
                        commentadorMoveCommentToPost(movingId, targetPostId);
                    }
                });
            }
            
            function commentadorSearchPosts(query) {
                $.ajax({
                    url: commentador_ajax.ajax_url,
                    type: "POST",
                    data: {
                        action: "commentador_search_posts",
                        nonce: commentador_ajax.nonce,
                        query: query
                    },
                    beforeSend: function() {
                        $("#commentador-search-results").html("<div style=\'padding:20px;text-align:center;\'><span class=\'commentador-loading\'></span></div>");
                    },
                    success: function(response) {
                        if (response.success) {
                            renderPostResults(response.data.posts);
                        } else {
                            $("#commentador-search-results").html("<div style=\'padding:20px;color:#646970;\'>" + commentador_ajax.strings.no_results + "</div>");
                        }
                    }
                });
            }
            
            function renderPostResults(posts) {
                let html = "";
                posts.forEach(function(post) {
                    html += `
                        <div class="commentador-post-item" data-post-id="${post.id}">
                            <div class="commentador-post-title">${post.title}</div>
                            <div class="commentador-post-meta">${post.type} | ${post.date} | ${post.comment_count} comments</div>
                            <div class="commentador-post-excerpt">${post.excerpt}</div>
                        </div>
                    `;
                });
                $("#commentador-search-results").html(html);
            }
            
            function commentadorSearchAuthors(query) {
                $.ajax({
                    url: commentador_ajax.ajax_url,
                    type: "POST",
                    data: {
                        action: "commentador_search_authors",
                        nonce: commentador_ajax.nonce,
                        query: query,
                        exclude_comment: $("#commentador-admin-panel").data("moving-comment")
                    },
                    beforeSend: function() {
                        $("#commentador-search-results").html("<div style=\'padding:20px;text-align:center;\'><span class=\'commentador-loading\'></span></div>");
                    },
                    success: function(response) {
                        if (response.success) {
                            renderNameResults(response.data.authors);
                        } else {
                            $("#commentador-search-results").html("<div style=\'padding:20px;color:#646970;\'>" + commentador_ajax.strings.no_names_found + "</div>");
                        }
                    }
                });
            }
            
            function renderNameResults(authors) {
                let html = "";
                authors.forEach(function(author) {
                    html += `
                        <div class="commentador-name-item" data-author-name="${author.name}">
                            <img src="${author.avatar}" class="commentador-name-avatar" alt="">
                            <div class="commentador-name-content">
                                <div class="commentador-name-author">${author.name}</div>
                                <div class="commentador-name-count">${author.comment_count} comments</div>
                            </div>
                            <button class="commentador-view-comments-btn">${commentador_ajax.strings.view_comments}</button>
                        </div>
                    `;
                });
                $("#commentador-search-results").html(html);
            }
            
            function commentadorShowAuthorComments(authorName) {
                $.ajax({
                    url: commentador_ajax.ajax_url,
                    type: "POST",
                    data: {
                        action: "commentador_search_comments",
                        nonce: commentador_ajax.nonce,
                        query: "",
                        author: authorName,
                        exclude: $("#commentador-admin-panel").data("moving-comment")
                    },
                    beforeSend: function() {
                        $("#commentador-search-results").html("<div style=\'padding:20px;text-align:center;\'><span class=\'commentador-loading\'></span></div>");
                    },
                    success: function(response) {
                        if (response.success && response.data.comments.length) {
                            let html = `<button class="commentador-back-btn">← ${commentador_ajax.strings.back_to_names}</button>`;
                            html += `<div style="padding:10px;font-weight:600;">${commentador_ajax.strings.comments_by} ${authorName}:</div>`;
                            
                            response.data.comments.forEach(function(comment) {
                                html += `
                                    <div class="commentador-comment-item" data-comment-id="${comment.id}">
                                        <img src="${comment.avatar}" class="commentador-comment-avatar" alt="">
                                        <div class="commentador-comment-content">
                                            <div class="commentador-comment-author">${comment.author}</div>
                                            <div class="commentador-comment-meta">${comment.date} on "${comment.post_title}"</div>
                                            <div class="commentador-comment-excerpt">${comment.excerpt}</div>
                                        </div>
                                    </div>
                                `;
                            });
                            $("#commentador-search-results").html(html);
                        } else {
                            $("#commentador-search-results").html(`<div style=\'padding:20px;color:#646970;\'>${commentador_ajax.strings.no_comments}</div>`);
                        }
                    }
                });
            }
            
            function commentadorMoveCommentToPost(commentId, postId) {
                $.ajax({
                    url: commentador_ajax.ajax_url,
                    type: "POST",
                    data: {
                        action: "commentador_move_comment",
                        nonce: commentador_ajax.nonce,
                        comment_id: commentId,
                        post_id: postId
                    },
                    success: function(response) {
                        if (response.success) {
                            commentadorShowStatus("Comment moved to post successfully!", "success");
                            $("#commentador-admin-panel").removeClass("active");
                            resetCommentadorPanel();
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            commentadorShowStatus(response.data.message || "Error moving comment", "error");
                        }
                    }
                });
            }
            
            function commentadorMoveCommentToName(commentId, authorName) {
                // Find most recent comment by this author
                $.ajax({
                    url: commentador_ajax.ajax_url,
                    type: "POST",
                    data: {
                        action: "commentador_search_comments",
                        nonce: commentador_ajax.nonce,
                        query: "",
                        author: authorName,
                        limit: 1
                    },
                    success: function(response) {
                        if (response.success && response.data.comments.length > 0) {
                            const targetId = response.data.comments[0].id;
                            commentadorMoveComment(commentId, targetId);
                        } else {
                            commentadorShowStatus("No comments found for this author", "error");
                        }
                    }
                });
            }
            
            function commentadorMoveComment(commentId, targetId) {
                $.ajax({
                    url: commentador_ajax.ajax_url,
                    type: "POST",
                    data: {
                        action: "commentador_move_comment",
                        nonce: commentador_ajax.nonce,
                        comment_id: commentId,
                        target_id: targetId
                    },
                    success: function(response) {
                        if (response.success) {
                            commentadorShowStatus("Comment moved successfully!", "success");
                            $("#commentador-admin-panel").removeClass("active");
                            resetCommentadorPanel();
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            commentadorShowStatus(response.data.message || "Error moving comment", "error");
                        }
                    }
                });
            }
            
            function commentadorShowStatus(message, type) {
                const status = $("#commentador-status");
                status.removeClass("success error").addClass(type).text(message).fadeIn();
                setTimeout(function() {
                    status.fadeOut();
                }, 3000);
            }
            
            function resetCommentadorPanel() {
                $("#commentador-admin-panel .commentador-search-input").val("");
                $("#commentador-search-results").empty();
                $("#commentador-selected-target").removeClass("active")
                    .removeData("target-post-id")
                    .removeData("target-name")
                    .removeData("target-comment-id");
            }
        });
        ';
    }
    
    // Search posts only - case insensitive
    public function ajax_search_posts() {
        check_ajax_referer('commentador_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $query = sanitize_text_field($_POST['query']);
        $enabled_types = $this->get_enabled_post_types();
        
        if (empty($enabled_types)) {
            wp_send_json_error(array('message' => 'No post types enabled'));
        }
        
        $args = array(
            'post_type' => $enabled_types,
            'post_status' => 'publish',
            'posts_per_page' => 20,
            's' => $query,
            'suppress_filters' => false,
        );
        
        // Case insensitive search
        add_filter('posts_where', array($this, 'case_insensitive_posts_where'), 10, 2);
        $query_obj = new WP_Query($args);
        remove_filter('posts_where', array($this, 'case_insensitive_posts_where'), 10);
        
        $posts = array();
        
        if ($query_obj->have_posts()) {
            while ($query_obj->have_posts()) {
                $query_obj->the_post();
                $post_type_obj = get_post_type_object(get_post_type());
                
                $posts[] = array(
                    'id' => get_the_ID(),
                    'title' => get_the_title(),
                    'excerpt' => wp_trim_words(get_the_content(), 15),
                    'type' => $post_type_obj ? $post_type_obj->label : get_post_type(),
                    'date' => get_the_date(),
                    'comment_count' => get_comments_number(),
                    'url' => get_permalink()
                );
            }
            wp_reset_postdata();
        }
        
        wp_send_json_success(array('posts' => $posts));
    }
    
    public function case_insensitive_posts_where($where, $wp_query) {
        global $wpdb;
        
        if (!empty($wp_query->get('s'))) {
            $where = preg_replace(
                "/\({$wpdb->posts}\.post_title\s+LIKE\s+('[^']+')\)/i",
                "(LOWER({$wpdb->posts}.post_title) LIKE LOWER($1))",
                $where
            );
            $where = preg_replace(
                "/\({$wpdb->posts}\.post_content\s+LIKE\s+('[^']+')\)/i",
                "(LOWER({$wpdb->posts}.post_content) LIKE LOWER($1))",
                $where
            );
        }
        
        return $where;
    }
    
    // Search authors/names
    public function ajax_search_authors() {
        check_ajax_referer('commentador_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $query = sanitize_text_field($_POST['query']);
        $exclude_comment = isset($_POST['exclude_comment']) ? intval($_POST['exclude_comment']) : 0;
        
        global $wpdb;
        
        $sql = "SELECT c.comment_author, c.comment_author_email, COUNT(*) as comment_count 
                FROM {$wpdb->comments} c 
                WHERE c.comment_approved = '1'";
        
        $params = array();
        
        if (!empty($query)) {
            $search_term = strtolower($query);
            $sql .= " AND LOWER(c.comment_author) LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search_term) . '%';
        }
        
        if ($exclude_comment) {
            $sql .= " AND c.comment_ID != %d";
            $params[] = $exclude_comment;
        }
        
        $sql .= " GROUP BY c.comment_author, c.comment_author_email 
                  ORDER BY comment_count DESC 
                  LIMIT 20";
        
        $authors = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        $formatted_authors = array();
        foreach ($authors as $author) {
            $formatted_authors[] = array(
                'name' => $author->comment_author,
                'email' => $author->comment_author_email,
                'comment_count' => $author->comment_count,
                'avatar' => get_avatar_url($author->comment_author_email, array('size' => 40))
            );
        }
        
        wp_send_json_success(array('authors' => $formatted_authors));
    }
    
    // Search comments - case insensitive
    public function ajax_search_comments() {
        check_ajax_referer('commentador_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
        $author = isset($_POST['author']) ? sanitize_text_field($_POST['author']) : '';
        $exclude = isset($_POST['exclude']) ? intval($_POST['exclude']) : 0;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
        
        global $wpdb;
        
        $sql = "SELECT c.comment_ID, c.comment_author, c.comment_author_email, c.comment_content, c.comment_date, c.comment_post_ID, p.post_title 
                FROM {$wpdb->comments} c 
                LEFT JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID 
                WHERE c.comment_approved = '1'";
        
        $params = array();
        
        if (!empty($query)) {
            $search_term = strtolower($query);
            $sql .= " AND (LOWER(c.comment_author) LIKE %s OR LOWER(c.comment_content) LIKE %s)";
            $params[] = '%' . $wpdb->esc_like($search_term) . '%';
            $params[] = '%' . $wpdb->esc_like($search_term) . '%';
        }
        
        if (!empty($author)) {
            $sql .= " AND c.comment_author = %s";
            $params[] = $author;
        }
        
        if ($exclude) {
            $sql .= " AND c.comment_ID != %d";
            $params[] = $exclude;
        }
        
        $sql .= " ORDER BY c.comment_date DESC LIMIT %d";
        $params[] = $limit;
        
        $comments = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        $formatted_comments = array();
        foreach ($comments as $comment) {
            $formatted_comments[] = array(
                'id' => $comment->comment_ID,
                'author' => $comment->comment_author,
                'email' => $comment->comment_author_email,
                'excerpt' => wp_trim_words($comment->comment_content, 15),
                'content' => $comment->comment_content,
                'date' => human_time_diff(strtotime($comment->comment_date), current_time('timestamp')) . ' ago',
                'avatar' => get_avatar_url($comment->comment_author_email, array('size' => 40)),
                'post_title' => $comment->post_title ? $comment->post_title : '(No title)',
                'post_id' => $comment->comment_post_ID
            );
        }
        
        wp_send_json_success(array('comments' => $formatted_comments));
    }
    
    public function ajax_move_comment() {
        check_ajax_referer('commentador_nonce', 'nonce');
        
        if (!current_user_can('moderate_comments')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $comment_id = intval($_POST['comment_id']);
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $target_id = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;
        
        if (!$comment_id) {
            wp_send_json_error(array('message' => 'Invalid comment ID'));
        }
        
        // Moving to a different post (post mode)
        if ($post_id && !$target_id) {
            $post = get_post($post_id);
            if (!$post) {
                wp_send_json_error(array('message' => 'Target post not found'));
            }
            
            $update_data = array(
                'comment_ID' => $comment_id,
                'comment_post_ID' => $post_id,
                'comment_parent' => 0
            );
            
            $result = wp_update_comment($update_data);
            
            if ($result) {
                update_comment_meta($comment_id, '_commentador_moved_to_post', $post_id);
                update_comment_meta($comment_id, '_commentador_moved', current_time('mysql'));
                update_comment_meta($comment_id, '_commentador_moved_by', get_current_user_id());
                
                wp_send_json_success(array(
                    'message' => 'Comment moved to post successfully',
                    'new_post' => $post_id
                ));
            }
        }
        // Moving under specific comment (name mode)
        elseif ($target_id) {
            $target_comment = get_comment($target_id);
            if (!$target_comment) {
                wp_send_json_error(array('message' => 'Target comment not found'));
            }
            
            $update_data = array(
                'comment_ID' => $comment_id,
                'comment_parent' => $target_id,
                'comment_post_ID' => $target_comment->comment_post_ID
            );
            
            $result = wp_update_comment($update_data);
            
            if ($result) {
                update_comment_meta($comment_id, '_commentador_moved', current_time('mysql'));
                update_comment_meta($comment_id, '_commentador_moved_to', $target_id);
                update_comment_meta($comment_id, '_commentador_moved_by', get_current_user_id());
                
                wp_send_json_success(array(
                    'message' => 'Comment moved successfully',
                    'new_parent' => $target_id,
                    'new_post' => $target_comment->comment_post_ID
                ));
            }
        } else {
            wp_send_json_error(array('message' => 'Invalid target'));
        }
        
        wp_send_json_error(array('message' => 'Failed to update comment'));
    }
    
    public function ajax_update_parent() {
        check_ajax_referer('commentador_nonce', 'nonce');
        
        $comment_id = intval($_POST['comment_id']);
        $parent_id = intval($_POST['parent_id']);
        
        if (!$comment_id) {
            wp_send_json_error();
        }
        
        wp_update_comment(array(
            'comment_ID' => $comment_id,
            'comment_parent' => $parent_id
        ));
        
        wp_send_json_success();
    }
    
    public function add_commentador_meta_box() {
        add_meta_box(
            'commentador_meta_box',
            'Commentador - Comment Relations',
            array($this, 'render_commentador_meta_box'),
            'comment',
            'normal',
            'high'
        );
    }
    
    public function render_commentador_meta_box($comment) {
        $moved = get_comment_meta($comment->comment_ID, '_commentador_moved', true);
        $moved_to = get_comment_meta($comment->comment_ID, '_commentador_moved_to', true);
        $moved_to_post = get_comment_meta($comment->comment_ID, '_commentador_moved_to_post', true);
        ?>
        <div class="commentador-meta-box">
            <?php if ($moved) : ?>
                <div class="notice notice-info inline">
                    <p>This comment was moved using Commentador on <?php echo esc_html($moved); ?> 
                    <?php if ($moved_to) : ?>
                        to be a reply to comment #<?php echo esc_html($moved_to); ?>
                    <?php elseif ($moved_to_post) : ?>
                        to post: <?php echo esc_html(get_the_title($moved_to_post)); ?>
                    <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <div class="commentador-current-parent">
                <h4>Current Parent Comment:</h4>
                <?php if ($comment->comment_parent) : 
                    $parent = get_comment($comment->comment_parent);
                    if ($parent) : ?>
                        <div class="commentador-parent-preview">
                            <strong><?php echo esc_html($parent->comment_author); ?></strong>
                            <p><?php echo esc_html(wp_trim_words($parent->comment_content, 20)); ?></p>
                            <a href="<?php echo esc_url(get_edit_comment_link($parent->comment_ID)); ?>" target="_blank">View parent comment</a>
                        </div>
                    <?php else : ?>
                        <p class="description">Parent comment not found (may have been deleted)</p>
                    <?php endif; ?>
                <?php else : ?>
                    <p class="description">This is a top-level comment (no parent)</p>
                <?php endif; ?>
            </div>
            
            <div class="commentador-quick-search">
                <h4>Change Parent Comment:</h4>
                <input type="text" id="commentador-meta-search" class="widefat" placeholder="Search comments by content...">
                <div id="commentador-meta-results" style="margin-top: 10px; max-height: 200px; overflow-y: auto;"></div>
            </div>
        </div>
        
        <style>
            .commentador-meta-box { padding: 10px 0; }
            .commentador-parent-preview { 
                background: #f0f6fc; 
                padding: 10px; 
                border-left: 4px solid #2271b1;
                margin: 10px 0;
            }
            .commentador-quick-search { margin-top: 20px; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            let searchTimeout;
            $("#commentador-meta-search").on("input", function() {
                clearTimeout(searchTimeout);
                const query = $(this).val();
                if (query.length < 2) return;
                
                searchTimeout = setTimeout(function() {
                    $.ajax({
                        url: commentador_ajax.ajax_url,
                        type: "POST",
                        data: {
                            action: "commentador_search_comments",
                            nonce: commentador_ajax.nonce,
                            query: query,
                            exclude: <?php echo intval($comment->comment_ID); ?>
                        },
                        success: function(response) {
                            if (response.success) {
                                let html = "<ul style=\'margin:0;padding:0;list-style:none;\'>";
                                response.data.comments.forEach(function(c) {
                                    html += `<li style=\'padding:8px;border-bottom:1px solid #eee;cursor:pointer;\' onclick=\'commentadorSetParent(${c.id}, "${c.author.replace(/"/g, "&quot;")}")\'>
                                        <strong>${c.author}</strong> - ${c.excerpt}
                                    </li>`;
                                });
                                html += "</ul>";
                                $("#commentador-meta-results").html(html);
                            }
                        }
                    });
                }, 300);
            });
        });
        
        function commentadorSetParent(parentId, author) {
            if (confirm("Set this comment to be a reply to: " + author + "?")) {
                jQuery.ajax({
                    url: commentador_ajax.ajax_url,
                    type: "POST",
                    data: {
                        action: "commentador_update_parent",
                        nonce: commentador_ajax.nonce,
                        comment_id: <?php echo intval($comment->comment_ID); ?>,
                        parent_id: parentId
                    },
                    success: function() {
                        location.reload();
                    }
                });
            }
        }
        </script>
        <?php
    }
    
    public function modify_comment_edit_form($comment) {
        if (!is_object($comment) || !isset($comment->comment_ID)) return;
        
        $name_mode = $this->is_name_mode_enabled();
        $button_text = $name_mode ? 'Move to Different Name/Author' : 'Move to Different Post';
        ?>
        <div class="commentador-edit-panel" style="background:#fff;border:1px solid #c3c4c7;padding:15px;margin:15px 0;">
            <h3 style="margin-top:0;">Commentador Quick Actions</h3>
            <button type="button" class="button" onclick="commentadorOpenMoveModal(<?php echo intval($comment->comment_ID); ?>)">
                <?php echo esc_html($button_text); ?>
            </button>
            <button type="button" class="button" onclick="commentadorMakeTopLevel(<?php echo intval($comment->comment_ID); ?>)">
                Make Top-Level Comment
            </button>
        </div>
        
        <script>
        function commentadorOpenMoveModal(commentId) {
            jQuery("#commentador-admin-panel").addClass("active").data("moving-comment", commentId);
        }
        
        function commentadorMakeTopLevel(commentId) {
            if (confirm("Make this a top-level comment (remove parent)?")) {
                jQuery.ajax({
                    url: commentador_ajax.ajax_url,
                    type: "POST",
                    data: {
                        action: "commentador_update_parent",
                        nonce: commentador_ajax.nonce,
                        comment_id: commentId,
                        parent_id: 0
                    },
                    success: function() {
                        location.reload();
                    }
                });
            }
        }
        </script>
        <?php
    }
}

// Initialize
Commentador::get_instance();

// Activation hook
register_activation_hook(__FILE__, 'commentador_activate');

function commentador_activate() {
    $defaults = array(
        'post' => '1',
        'page' => '1',
        'download' => '1',
        'product' => '1',
        'name_mode' => '0',
    );
    add_option('commentador_settings', $defaults);
    add_option('commentador_version', '2.3.0');
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'commentador_deactivate');

function commentador_deactivate() {
    // Cleanup if necessary
}