<?php
/**
 * Admin page for GP Bulk Download Translations
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class GP_Bulk_Download_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_gpbd_get_projects', array($this, 'ajax_get_projects'));
        add_action('wp_ajax_gpbd_generate_link', array($this, 'ajax_generate_link'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'options-general.php',
            __('批量下载翻译', 'gp-bulk-download'),
            __('批量下载翻译', 'gp-bulk-download'),
            'manage_options',
            'gp-bulk-download',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'settings_page_gp-bulk-download') {
            return;
        }
        
        wp_enqueue_style('gp-bulk-download-admin', plugins_url('admin-style.css', __FILE__));
        wp_enqueue_script('gp-bulk-download-admin', plugins_url('admin-script.js', __FILE__), array('jquery'), '1.0', true);
        
        wp_localize_script('gp-bulk-download-admin', 'gpbdAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gpbd_admin_nonce'),
            'siteUrl' => site_url(),
            'downloadKey' => defined('GP_DOWNLOAD_KEY') ? GP_DOWNLOAD_KEY : ''
        ));
    }
    
    /**
     * Get all projects via AJAX
     */
    public function ajax_get_projects() {
        check_ajax_referer('gpbd_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }
        
        $projects = GP::$project->all();
        $project_tree = $this->build_project_tree($projects);
        
        wp_send_json_success($project_tree);
    }
    
    /**
     * Build project tree structure
     */
    private function build_project_tree($projects) {
        $tree = array();
        
        foreach ($projects as $project) {
            $tree[] = array(
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'path' => $project->path,
                'parent_id' => $project->parent_project_id
            );
        }
        
        return $tree;
    }
    
    /**
     * Generate download link via AJAX
     */
    public function ajax_generate_link() {
        check_ajax_referer('gpbd_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }

        $projects = isset($_POST['projects']) ? $_POST['projects'] : array();
        $flat = isset($_POST['flat']) ? filter_var($_POST['flat'], FILTER_VALIDATE_BOOLEAN) : false;
        $with_key = isset($_POST['with_key']) ? filter_var($_POST['with_key'], FILTER_VALIDATE_BOOLEAN) : false;

        if (empty($projects)) {
            wp_send_json_error('请选择至少一个项目');
        }

        // Single project: use gp_url() which returns absolute path from domain root
        if (count($projects) === 1) {
            $project = $projects[0];
            // gp_url() returns: /buddypress/translate/bulk-export/project
            $path = gp_url('bulk-export/' . $project);
            
            // In multisite, use network home URL to avoid path duplication
            $base_url = is_multisite() ? network_home_url() : home_url();
            
            $params = array('format' => 'zip');
            if ($with_key && defined('GP_DOWNLOAD_KEY')) {
                $params['download_key'] = GP_DOWNLOAD_KEY;
            }
            
            // Build URL: domain + path + query params
            $download_url = add_query_arg($params, $base_url . ltrim($path, '/'));
            
            // Generate filename with locale for consistency
            $locale = get_locale();
            $filename = str_replace('/', '-', $project) . '-' . $locale . '.zip';
        } 
        // Multiple projects: use gp_url() for consistency
        else {
            // gp_url() returns: /buddypress/translate/bulk-export-multi
            $path = gp_url('bulk-export-multi');
            
            // In multisite, use network home URL to avoid path duplication
            $base_url = is_multisite() ? network_home_url() : home_url();
            
            $params = array(
                'projects' => implode(',', $projects)
            );

            if ($flat) {
                $params['flat'] = '1';
            }

            if ($with_key && defined('GP_DOWNLOAD_KEY')) {
                $params['download_key'] = GP_DOWNLOAD_KEY;
            }

            // Build URL: domain + path + query params
            $download_url = add_query_arg($params, $base_url . ltrim($path, '/'));

            // Generate filename preview
            $first_project = str_replace('/', '-', $projects[0]);
            $locale = get_locale();
            $filename = $first_project . '-package-' . $locale . '.zip';
        }

        wp_send_json_success(array(
            'url' => $download_url,
            'filename' => $filename
        ));
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('批量下载翻译', 'gp-bulk-download'); ?></h1>
            
            <div class="gpbd-admin-container">
                
                <!-- Tab Navigation -->
                <h2 class="nav-tab-wrapper">
                    <a href="#tab-download" class="nav-tab nav-tab-active"><?php _e('下载链接', 'gp-bulk-download'); ?></a>
                    <a href="#tab-settings" class="nav-tab"><?php _e('设置', 'gp-bulk-download'); ?></a>
                    <a href="#tab-help" class="nav-tab"><?php _e('帮助', 'gp-bulk-download'); ?></a>
                </h2>
                
                <!-- Tab: Download -->
                <div id="tab-download" class="gpbd-tab-content">
                    <div class="gpbd-two-columns">
                        
                        <!-- Left Column: Project Selection -->
                        <div class="gpbd-column">
                            <h2><?php _e('选择项目', 'gp-bulk-download'); ?></h2>
                            
                            <div class="gpbd-search-box">
                                <input type="text" id="project-search" placeholder="<?php _e('搜索项目...', 'gp-bulk-download'); ?>">
                            </div>
                            
                            <div class="gpbd-actions">
                                <button type="button" id="select-all" class="button"><?php _e('全选', 'gp-bulk-download'); ?></button>
                                <button type="button" id="deselect-all" class="button"><?php _e('取消全选', 'gp-bulk-download'); ?></button>
                            </div>
                            
                            <div id="project-list" class="gpbd-project-list">
                                <p><?php _e('加载中...', 'gp-bulk-download'); ?></p>
                            </div>
                        </div>
                        
                        <!-- Right Column: Options & Link -->
                        <div class="gpbd-column">
                            <h2><?php _e('下载选项', 'gp-bulk-download'); ?></h2>
                            
                            <table class="form-table">
                                <tr>
                                    <th><?php _e('文件格式', 'gp-bulk-download'); ?></th>
                                    <td>
                                        <p class="description"><?php _e('格式在 wp-config.php 中配置', 'gp-bulk-download'); ?></p>
                                        <p>
                                            <label><input type="checkbox" checked disabled> PO</label>
                                            <label><input type="checkbox" checked disabled> MO</label>
                                            <label><input type="checkbox" checked disabled> PHP</label>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php _e('文件结构', 'gp-bulk-download'); ?></th>
                                    <td>
                                        <label>
                                            <input type="radio" name="structure" value="folder" checked>
                                            <?php _e('分文件夹（推荐多项目）', 'gp-bulk-download'); ?>
                                        </label><br>
                                        <label>
                                            <input type="radio" name="structure" value="flat">
                                            <?php _e('扁平结构（推荐单项目）', 'gp-bulk-download'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php _e('包含密钥', 'gp-bulk-download'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" id="with-key">
                                            <?php _e('在链接中包含下载密钥', 'gp-bulk-download'); ?>
                                        </label>
                                        <p class="description">
                                            <?php _e('用于外部访问或 WooCommerce 产品', 'gp-bulk-download'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <h3><?php _e('生成的下载链接', 'gp-bulk-download'); ?></h3>
                            
                            <div class="gpbd-link-preview">
                                <textarea id="generated-link" rows="4" readonly><?php _e('请先选择项目...', 'gp-bulk-download'); ?></textarea>
                            </div>
                            
                            <div class="gpbd-file-preview">
                                <strong><?php _e('文件名预览:', 'gp-bulk-download'); ?></strong>
                                <code id="filename-preview">-</code>
                            </div>
                            
                            <p class="gpbd-button-group">
                                <button type="button" id="generate-link" class="button button-primary button-large">
                                    <?php _e('生成链接', 'gp-bulk-download'); ?>
                                </button>
                                <button type="button" id="copy-link" class="button button-large" disabled>
                                    <?php _e('复制链接', 'gp-bulk-download'); ?>
                                </button>
                                <button type="button" id="direct-download" class="button button-large" disabled>
                                    <?php _e('直接下载', 'gp-bulk-download'); ?>
                                </button>
                            </p>
                        </div>
                        
                    </div>
                </div>
                
                <!-- Tab: Settings -->
                <div id="tab-settings" class="gpbd-tab-content" style="display:none;">
                    <h2><?php _e('密钥管理', 'gp-bulk-download'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th><?php _e('当前密钥', 'gp-bulk-download'); ?></th>
                            <td>
                                <?php if (defined('GP_DOWNLOAD_KEY')): ?>
                                    <code><?php echo esc_html(GP_DOWNLOAD_KEY); ?></code>
                                    <p class="description">
                                        <?php _e('密钥在 wp-config.php 中配置', 'gp-bulk-download'); ?>
                                    </p>
                                <?php else: ?>
                                    <p class="description" style="color: #d63638;">
                                        <?php _e('未配置密钥。请在 wp-config.php 中添加 GP_DOWNLOAD_KEY 常量。', 'gp-bulk-download'); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('生成新密钥', 'gp-bulk-download'); ?></th>
                            <td>
                                <button type="button" id="generate-key" class="button">
                                    <?php _e('生成随机密钥', 'gp-bulk-download'); ?>
                                </button>
                                <p class="description">
                                    <?php _e('生成后需要手动复制到 wp-config.php', 'gp-bulk-download'); ?>
                                </p>
                                <div id="new-key-display" style="display:none; margin-top:10px;">
                                    <code id="new-key-value" style="background:#f0f0f1;padding:10px;display:block;"></code>
                                    <button type="button" id="copy-new-key" class="button" style="margin-top:5px;">
                                        <?php _e('复制密钥', 'gp-bulk-download'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </table>
                    
                    <h2><?php _e('当前配置', 'gp-bulk-download'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('站点语言', 'gp-bulk-download'); ?></th>
                            <td><code><?php echo get_locale(); ?></code></td>
                        </tr>
                        <tr>
                            <th><?php _e('启用的格式', 'gp-bulk-download'); ?></th>
                            <td>
                                <?php
                                $formats = array();
                                if (defined('GP_BULK_DOWNLOAD_TRANSLATIONS_FORMAT_PO') && GP_BULK_DOWNLOAD_TRANSLATIONS_FORMAT_PO) $formats[] = 'PO';
                                if (defined('GP_BULK_DOWNLOAD_TRANSLATIONS_FORMAT_MO') && GP_BULK_DOWNLOAD_TRANSLATIONS_FORMAT_MO) $formats[] = 'MO';
                                if (defined('GP_BULK_DOWNLOAD_TRANSLATIONS_FORMAT_PHP') && GP_BULK_DOWNLOAD_TRANSLATIONS_FORMAT_PHP) $formats[] = 'PHP';
                                echo !empty($formats) ? implode(', ', $formats) : __('无', 'gp-bulk-download');
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Tab: Help -->
                <div id="tab-help" class="gpbd-tab-content" style="display:none;">
                    <h2><?php _e('使用帮助', 'gp-bulk-download'); ?></h2>
                    
                    <h3><?php _e('基本使用', 'gp-bulk-download'); ?></h3>
                    <ol>
                        <li><?php _e('在左侧选择要下载的项目（可多选）', 'gp-bulk-download'); ?></li>
                        <li><?php _e('选择文件结构（分文件夹或扁平）', 'gp-bulk-download'); ?></li>
                        <li><?php _e('决定是否包含密钥（外部访问需要）', 'gp-bulk-download'); ?></li>
                        <li><?php _e('点击"生成链接"按钮', 'gp-bulk-download'); ?></li>
                        <li><?php _e('复制链接或直接下载', 'gp-bulk-download'); ?></li>
                    </ol>
                    
                    <h3><?php _e('URL 参数说明', 'gp-bulk-download'); ?></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('参数', 'gp-bulk-download'); ?></th>
                                <th><?php _e('说明', 'gp-bulk-download'); ?></th>
                                <th><?php _e('示例', 'gp-bulk-download'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>projects</code></td>
                                <td><?php _e('项目列表（逗号分隔）', 'gp-bulk-download'); ?></td>
                                <td><code>akismet,jetpack</code></td>
                            </tr>
                            <tr>
                                <td><code>flat</code></td>
                                <td><?php _e('扁平结构（1=是）', 'gp-bulk-download'); ?></td>
                                <td><code>flat=1</code></td>
                            </tr>
                            <tr>
                                <td><code>download_key</code></td>
                                <td><?php _e('下载密钥', 'gp-bulk-download'); ?></td>
                                <td><code>download_key=xxx</code></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <h3><?php _e('文档链接', 'gp-bulk-download'); ?></h3>
                    <p><?php _e('详细文档请参考插件目录中的 README 文件', 'gp-bulk-download'); ?></p>
                </div>
                
            </div>
        </div>
        <?php
    }
}

// Initialize admin page
new GP_Bulk_Download_Admin();
