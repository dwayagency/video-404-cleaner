<?php
/**
 * Plugin Name: Video 404 Cleaner
 * Plugin URI: https://dway.agency
 * Description: Verifica i video presenti nella Media Library che restituiscono errore 404, li rimuove dai post collegati e li sposta nel cestino.
 * Version: 1.1.0
 * Author: DWAY SRL
 * Author URI: https://dway.agency
 * License: GPLv2 or later
 * Text Domain: video-404-cleaner
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

class Video_404_Cleaner {

    const NONCE_ACTION = 'video_404_run';
    const NONCE_SETTINGS = 'video_404_settings';
    const OPTION_LAST_REPORT = 'video_404_last_report';
    const OPTION_SETTINGS = 'video_404_settings';
    const CRON_HOOK = 'video_404_cron_scan';
    const BATCH_SIZE = 50; // Process videos in batches to avoid memory issues
    const HTTP_TIMEOUT = 15; // Increased timeout for better reliability

    private $errors = [];
    private $log_file = '';

    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/uploads/video-404-cleaner.log';
        
        add_action('admin_menu', [$this, 'add_tools_page']);
        add_action('admin_post_video_404_run', [$this, 'handle_manual_scan']);
        add_action('admin_post_video_404_settings', [$this, 'handle_settings_save']);
        add_action('wp_ajax_video_404_batch', [$this, 'handle_batch_scan']);
        add_action(self::CRON_HOOK, [$this, 'cron_scan']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);

        add_filter('cron_schedules', [$this, 'add_weekly_schedule']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('video-404 check', [$this, 'wpcli_check_command']);
        }
    }

    public function activate() {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'weekly', self::CRON_HOOK);
        }
        $this->log('Plugin activated');
    }

    public function deactivate() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
        $this->log('Plugin deactivated');
    }

    /**
     * Log messages to file
     */
    private function log($message, $level = 'INFO') {
        $settings = $this->get_settings();
        
        if (!$settings['log_enabled'] || !is_writable(dirname($this->log_file))) {
            return;
        }
        
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Add error to errors array
     */
    private function add_error($message) {
        $this->errors[] = $message;
        $this->log($message, 'ERROR');
    }

    public function add_weekly_schedule($schedules) {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => __('Once Weekly')
            ];
        }
        return $schedules;
    }

    public function enqueue_admin_styles($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'video-404-cleaner') === false) {
            return;
        }

        wp_add_inline_style('wp-admin', '
            .video-404-cleaner-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 20px;
                margin: -20px -20px 20px -20px;
                border-radius: 0 0 8px 8px;
            }
            .video-404-cleaner-header h1 {
                color: white;
                margin: 0;
                font-size: 24px;
            }
            .video-404-cleaner-stats {
                display: flex;
                gap: 20px;
                margin-top: 15px;
            }
            .video-404-cleaner-stat {
                background: rgba(255,255,255,0.1);
                padding: 10px 15px;
                border-radius: 5px;
                text-align: center;
            }
            .video-404-cleaner-stat-number {
                font-size: 20px;
                font-weight: bold;
                display: block;
            }
            .video-404-cleaner-stat-label {
                font-size: 12px;
                opacity: 0.8;
            }
        ');
    }

    public function add_tools_page() {
        // Add main menu page
        add_menu_page(
            __('Video 404 Cleaner', 'video-404-cleaner'),
            __('Video 404 Cleaner', 'video-404-cleaner'),
            'manage_options',
            'video-404-cleaner',
            [$this, 'render_tools_page'],
            'dashicons-video-alt3',
            30
        );

        // Add submenu pages
        add_submenu_page(
            'video-404-cleaner',
            __('Scan Videos', 'video-404-cleaner'),
            __('Scan Videos', 'video-404-cleaner'),
            'manage_options',
            'video-404-cleaner',
            [$this, 'render_tools_page']
        );

        add_submenu_page(
            'video-404-cleaner',
            __('Settings', 'video-404-cleaner'),
            __('Settings', 'video-404-cleaner'),
            'manage_options',
            'video-404-cleaner-settings',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'video-404-cleaner',
            __('Logs', 'video-404-cleaner'),
            __('Logs', 'video-404-cleaner'),
            'manage_options',
            'video-404-cleaner-logs',
            [$this, 'render_logs_page']
        );
    }

    public function render_tools_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $last_report = get_option(self::OPTION_LAST_REPORT);
        $total_videos = $this->get_total_video_count();
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <div class="video-404-cleaner-header">
                <h1><?php _e('Video 404 Cleaner', 'video-404-cleaner'); ?></h1>
                <div class="video-404-cleaner-stats">
                    <div class="video-404-cleaner-stat">
                        <span class="video-404-cleaner-stat-number"><?php echo intval($total_videos); ?></span>
                        <span class="video-404-cleaner-stat-label"><?php _e('Total Videos', 'video-404-cleaner'); ?></span>
                    </div>
                    <div class="video-404-cleaner-stat">
                        <span class="video-404-cleaner-stat-number"><?php echo intval($last_report['broken_count'] ?? 0); ?></span>
                        <span class="video-404-cleaner-stat-label"><?php _e('Last Scan - Broken', 'video-404-cleaner'); ?></span>
                    </div>
                    <div class="video-404-cleaner-stat">
                        <span class="video-404-cleaner-stat-number"><?php echo $last_report ? date('M j', strtotime($last_report['when'])) : '—'; ?></span>
                        <span class="video-404-cleaner-stat-label"><?php _e('Last Scan Date', 'video-404-cleaner'); ?></span>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($this->errors)): ?>
                <div class="notice notice-error">
                    <p><strong><?php _e('Errors occurred:', 'video-404-cleaner'); ?></strong></p>
                    <ul>
                        <?php foreach ($this->errors as $error): ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card">
                <h2><?php _e('About this tool', 'video-404-cleaner'); ?></h2>
                <p><?php _e('This tool checks all videos uploaded to the media library. If a video file returns a 404 error:', 'video-404-cleaner'); ?></p>
                <ul style="list-style:disc; margin-left:20px;">
                    <li><?php _e('it gets unlinked from the parent post', 'video-404-cleaner'); ?></li>
                    <li><?php _e('video references are removed from the post content', 'video-404-cleaner'); ?></li>
                    <li><?php _e('the video is moved to trash', 'video-404-cleaner'); ?></li>
                </ul>
            </div>

            <div class="card">
                <h2><?php _e('Scan Options', 'video-404-cleaner'); ?></h2>
                <p><strong><?php _e('Total videos in library:', 'video-404-cleaner'); ?></strong> <?php echo intval($total_videos); ?></p>
                
                <?php if ($total_videos > $settings['batch_size']): ?>
                    <div class="notice notice-warning">
                        <p><?php printf(__('Large media library detected (%d videos). The scan will be processed in batches of %d videos to avoid timeout issues.', 'video-404-cleaner'), $total_videos, $settings['batch_size']); ?></p>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="scan-form">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="video_404_run" />
                    <?php submit_button(__('Run scan now', 'video-404-cleaner'), 'primary large', 'submit', false, ['id' => 'scan-button']); ?>
                </form>

                <div id="scan-progress" style="display: none;">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 0%;"></div>
                    </div>
                    <p id="scan-status"><?php _e('Preparing scan...', 'video-404-cleaner'); ?></p>
                </div>
            </div>

            <?php if ($last_report && is_array($last_report)) : ?>
                <div class="card">
                    <h2><?php _e('Last Report', 'video-404-cleaner'); ?></h2>
                    <p><strong><?php _e('Date:', 'video-404-cleaner'); ?></strong> <?php echo esc_html($last_report['when'] ?? ''); ?></p>
                    <p><strong><?php _e('Total videos:', 'video-404-cleaner'); ?></strong> <?php echo intval($last_report['total']); ?></p>
                    <p><strong><?php _e('404 errors found:', 'video-404-cleaner'); ?></strong> <?php echo intval($last_report['broken_count']); ?></p>
                    
                    <?php if (!empty($last_report['broken'])): ?>
                        <h3><?php _e('Broken Videos', 'video-404-cleaner'); ?></h3>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Attachment ID', 'video-404-cleaner'); ?></th>
                                    <th><?php _e('URL', 'video-404-cleaner'); ?></th>
                                    <th><?php _e('Parent Post', 'video-404-cleaner'); ?></th>
                                    <th><?php _e('Actions Taken', 'video-404-cleaner'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($last_report['broken'] as $row): ?>
                                <tr>
                                    <td><?php echo intval($row['attachment_id']); ?></td>
                                    <td style="word-break:break-all;"><?php echo esc_html($row['url']); ?></td>
                                    <td><?php echo $row['parent_id'] ? intval($row['parent_id']) : '—'; ?></td>
                                    <td><?php echo esc_html(implode(', ', $row['actions'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <style>
        .progress-bar {
            width: 100%;
            height: 20px;
            background-color: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-fill {
            height: 100%;
            background-color: #0073aa;
            transition: width 0.3s ease;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#scan-form').on('submit', function(e) {
                e.preventDefault();
                $('#scan-button').prop('disabled', true);
                $('#scan-progress').show();
                
                var totalVideos = <?php echo intval($total_videos); ?>;
                var batchSize = <?php echo intval($settings['batch_size']); ?>;
                var currentBatch = 0;
                var totalBatches = Math.ceil(totalVideos / batchSize);
                
                function processBatch() {
                    $.post(ajaxurl, {
                        action: 'video_404_batch',
                        batch: currentBatch,
                        nonce: '<?php echo wp_create_nonce(self::NONCE_ACTION); ?>'
                    }, function(response) {
                        if (response.success) {
                            currentBatch++;
                            var progress = (currentBatch / totalBatches) * 100;
                            $('.progress-fill').css('width', progress + '%');
                            $('#scan-status').text('Processing batch ' + currentBatch + ' of ' + totalBatches + '...');
                            
                            if (currentBatch < totalBatches) {
                                setTimeout(processBatch, 1000);
                            } else {
                                $('#scan-status').text('Scan completed! Reloading page...');
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            }
                        } else {
                            $('#scan-status').text('Error: ' + response.data);
                            $('#scan-button').prop('disabled', false);
                        }
                    });
                }
                
                processBatch();
            });
        });
        </script>
        </div>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php _e('Video 404 Cleaner - Settings', 'video-404-cleaner'); ?></h1>
            <?php $this->render_settings_tab($settings); ?>
        </div>
        <?php
    }

    public function render_logs_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Handle clear logs action
        if (isset($_GET['action']) && $_GET['action'] === 'clear_logs') {
            if (file_exists($this->log_file)) {
                file_put_contents($this->log_file, '');
                echo '<div class="notice notice-success"><p>' . __('Logs cleared successfully!', 'video-404-cleaner') . '</p></div>';
            }
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Video 404 Cleaner - Logs', 'video-404-cleaner'); ?></h1>
            <?php $this->render_logs_tab(); ?>
        </div>
        <?php
    }

    public function handle_manual_scan() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', self::NONCE_ACTION)) {
            wp_die(__('Security check failed. Please try again.'));
        }

        $this->log('Manual scan started');
        $report = $this->run_scan();
        update_option(self::OPTION_LAST_REPORT, $report, false);
        $this->log('Manual scan completed');

        wp_redirect(admin_url('admin.php?page=video-404-cleaner'));
        exit;
    }

    public function handle_batch_scan() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have sufficient permissions.'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE_ACTION)) {
            wp_send_json_error(__('Security check failed.'));
        }

        $batch = intval($_POST['batch'] ?? 0);
        $settings = $this->get_settings();
        $offset = $batch * $settings['batch_size'];
        
        $this->log("Processing batch {$batch} (offset: {$offset})");
        $result = $this->run_batch_scan($offset, $settings['batch_size']);
        
        wp_send_json_success($result);
    }

    public function handle_settings_save() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', self::NONCE_SETTINGS)) {
            wp_die(__('Security check failed. Please try again.'));
        }

        $settings = [
            'batch_size' => intval($_POST['batch_size'] ?? self::BATCH_SIZE),
            'http_timeout' => intval($_POST['http_timeout'] ?? self::HTTP_TIMEOUT),
            'auto_scan_enabled' => isset($_POST['auto_scan_enabled']),
            'scan_frequency' => sanitize_text_field($_POST['scan_frequency'] ?? 'weekly'),
            'log_enabled' => isset($_POST['log_enabled']),
            'error_codes' => array_map('intval', $_POST['error_codes'] ?? [404]),
        ];

        update_option(self::OPTION_SETTINGS, $settings);
        $this->log('Settings updated');

        wp_redirect(admin_url('admin.php?page=video-404-cleaner-settings&updated=1'));
        exit;
    }

    private function get_settings() {
        $defaults = [
            'batch_size' => self::BATCH_SIZE,
            'http_timeout' => self::HTTP_TIMEOUT,
            'auto_scan_enabled' => true,
            'scan_frequency' => 'weekly',
            'log_enabled' => true,
            'error_codes' => [404, 403, 500, 502, 503, 504],
        ];

        return wp_parse_args(get_option(self::OPTION_SETTINGS, []), $defaults);
    }

    private function render_settings_tab($settings) {
        ?>
        <div class="card">
            <h2><?php _e('Plugin Settings', 'video-404-cleaner'); ?></h2>
            
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success">
                    <p><?php _e('Settings saved successfully!', 'video-404-cleaner'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_SETTINGS); ?>
                <input type="hidden" name="action" value="video_404_settings" />

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Batch Size', 'video-404-cleaner'); ?></th>
                        <td>
                            <input type="number" name="batch_size" value="<?php echo esc_attr($settings['batch_size']); ?>" 
                                   min="10" max="200" class="regular-text" />
                            <p class="description"><?php _e('Number of videos to process in each batch (10-200). Lower values for slower servers.', 'video-404-cleaner'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('HTTP Timeout', 'video-404-cleaner'); ?></th>
                        <td>
                            <input type="number" name="http_timeout" value="<?php echo esc_attr($settings['http_timeout']); ?>" 
                                   min="5" max="60" class="regular-text" />
                            <p class="description"><?php _e('Timeout in seconds for HTTP requests (5-60).', 'video-404-cleaner'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Auto Scan', 'video-404-cleaner'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_scan_enabled" value="1" 
                                       <?php checked($settings['auto_scan_enabled']); ?> />
                                <?php _e('Enable automatic scanning', 'video-404-cleaner'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Scan Frequency', 'video-404-cleaner'); ?></th>
                        <td>
                            <select name="scan_frequency">
                                <option value="daily" <?php selected($settings['scan_frequency'], 'daily'); ?>><?php _e('Daily', 'video-404-cleaner'); ?></option>
                                <option value="weekly" <?php selected($settings['scan_frequency'], 'weekly'); ?>><?php _e('Weekly', 'video-404-cleaner'); ?></option>
                                <option value="monthly" <?php selected($settings['scan_frequency'], 'monthly'); ?>><?php _e('Monthly', 'video-404-cleaner'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Error Codes', 'video-404-cleaner'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="checkbox" name="error_codes[]" value="404" <?php checked(in_array(404, $settings['error_codes'])); ?> /> 404 - Not Found</label><br>
                                <label><input type="checkbox" name="error_codes[]" value="403" <?php checked(in_array(403, $settings['error_codes'])); ?> /> 403 - Forbidden</label><br>
                                <label><input type="checkbox" name="error_codes[]" value="500" <?php checked(in_array(500, $settings['error_codes'])); ?> /> 500 - Internal Server Error</label><br>
                                <label><input type="checkbox" name="error_codes[]" value="502" <?php checked(in_array(502, $settings['error_codes'])); ?> /> 502 - Bad Gateway</label><br>
                                <label><input type="checkbox" name="error_codes[]" value="503" <?php checked(in_array(503, $settings['error_codes'])); ?> /> 503 - Service Unavailable</label><br>
                                <label><input type="checkbox" name="error_codes[]" value="504" <?php checked(in_array(504, $settings['error_codes'])); ?> /> 504 - Gateway Timeout</label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Logging', 'video-404-cleaner'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="log_enabled" value="1" 
                                       <?php checked($settings['log_enabled']); ?> />
                                <?php _e('Enable logging to file', 'video-404-cleaner'); ?>
                            </label>
                            <p class="description"><?php _e('Log file location:', 'video-404-cleaner'); ?> <code><?php echo esc_html($this->log_file); ?></code></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'video-404-cleaner')); ?>
            </form>
        </div>
        <?php
    }

    private function render_logs_tab() {
        $log_content = '';
        if (file_exists($this->log_file) && is_readable($this->log_file)) {
            $log_content = file_get_contents($this->log_file);
            $log_content = esc_html($log_content);
        }
        ?>
        <div class="card">
            <h2><?php _e('Plugin Logs', 'video-404-cleaner'); ?></h2>
            
            <?php if (empty($log_content)): ?>
                <p><?php _e('No logs available.', 'video-404-cleaner'); ?></p>
            <?php else: ?>
                <p>
                    <button type="button" class="button" onclick="document.getElementById('log-content').select();">
                        <?php _e('Select All', 'video-404-cleaner'); ?>
                    </button>
                    <button type="button" class="button" onclick="if(confirm('<?php _e('Are you sure you want to clear the logs?', 'video-404-cleaner'); ?>')) { window.location.href='<?php echo admin_url('admin.php?page=video-404-cleaner-logs&action=clear_logs'); ?>'; }">
                        <?php _e('Clear Logs', 'video-404-cleaner'); ?>
                    </button>
                </p>
                <textarea id="log-content" readonly style="width: 100%; height: 400px; font-family: monospace; font-size: 12px;"><?php echo $log_content; ?></textarea>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get total count of video attachments
     */
    private function get_total_video_count() {
        $mimes = [
            'video/mp4', 'video/quicktime', 'video/x-ms-wmv', 'video/x-flv',
            'video/webm', 'video/ogg', 'application/ogg', 'video/x-matroska',
        ];

        $count = wp_count_posts('attachment');
        $total = 0;
        
        foreach ($mimes as $mime) {
            $total += $count->$mime ?? 0;
        }
        
        return $total;
    }

    public function cron_scan() {
        $this->log('Cron scan started');
        $report = $this->run_scan();
        update_option(self::OPTION_LAST_REPORT, $report, false);
        $this->log('Cron scan completed');
    }

    public function wpcli_check_command($args, $assoc_args) {
        $report = $this->run_scan();
        \WP_CLI::success("Total videos: {$report['total']}; 404 errors found: {$report['broken_count']}");
        if (!empty($report['broken'])) {
            foreach ($report['broken'] as $row) {
                \WP_CLI::log("ID {$row['attachment_id']} | {$row['url']} | Actions: " . implode(', ', $row['actions']));
            }
        }
    }

    /**
     * Run batch scan for AJAX requests
     */
    private function run_batch_scan($offset, $limit) {
        $videos = $this->get_video_attachments($offset, $limit);
        $broken = [];

        foreach ($videos as $att) {
            $att_id = (int) $att->ID;
            $url = wp_get_attachment_url($att_id);
            
            if (!$url) {
                $this->add_error("Could not get URL for attachment ID {$att_id}");
                continue;
            }

            $is404 = $this->is_404($url);
            if ($is404) {
                $actions = [];
                $parent_id = (int) get_post_field('post_parent', $att_id);

                try {
                    if ($parent_id > 0 && get_post_status($parent_id)) {
                        if ($this->remove_video_references_from_post($parent_id, $att_id, $url)) {
                            $actions[] = "cleaned post {$parent_id}";
                        }
                        wp_update_post(['ID' => $att_id, 'post_parent' => 0]);
                        $actions[] = 'unlinked from post';
                    }

                    wp_trash_post($att_id);
                    $actions[] = 'moved to trash';

                    $broken[] = [
                        'attachment_id' => $att_id,
                        'url' => $url,
                        'parent_id' => $parent_id ?: null,
                        'actions' => $actions,
                    ];
                } catch (Exception $e) {
                    $this->add_error("Error processing attachment {$att_id}: " . $e->getMessage());
                }
            }
        }

        return [
            'processed' => count($videos),
            'broken' => $broken,
            'errors' => $this->errors
        ];
    }

    protected function run_scan() {
        $this->log('Starting full scan');
        $videos = $this->get_video_attachments();
        $total = count($videos);
        $broken = [];

        $this->log("Found {$total} videos to scan");

        foreach ($videos as $att) {
            $att_id = (int) $att->ID;
            $url = wp_get_attachment_url($att_id);
            
            if (!$url) {
                $this->add_error("Could not get URL for attachment ID {$att_id}");
                continue;
            }

            $is404 = $this->is_404($url);
            if ($is404) {
                $actions = [];
                $parent_id = (int) get_post_field('post_parent', $att_id);

                try {
                    if ($parent_id > 0 && get_post_status($parent_id)) {
                        if ($this->remove_video_references_from_post($parent_id, $att_id, $url)) {
                            $actions[] = "cleaned post {$parent_id}";
                        }
                        wp_update_post(['ID' => $att_id, 'post_parent' => 0]);
                        $actions[] = 'unlinked from post';
                    }

                    wp_trash_post($att_id);
                    $actions[] = 'moved to trash';

                    $broken[] = [
                        'attachment_id' => $att_id,
                        'url' => $url,
                        'parent_id' => $parent_id ?: null,
                        'actions' => $actions,
                    ];

                    $this->log("Processed broken video ID {$att_id}: " . implode(', ', $actions));
                } catch (Exception $e) {
                    $this->add_error("Error processing attachment {$att_id}: " . $e->getMessage());
                }
            }
        }

        $this->log("Scan completed: {$total} total, " . count($broken) . " broken");

        return [
            'when' => current_time('mysql'),
            'total' => $total,
            'broken_count' => count($broken),
            'broken' => $broken,
            'errors' => $this->errors
        ];
    }

    protected function get_video_attachments($offset = 0, $limit = -1) {
        $mimes = [
            'video/mp4', 'video/quicktime', 'video/x-ms-wmv', 'video/x-flv',
            'video/webm', 'video/ogg', 'application/ogg', 'video/x-matroska',
            'video/avi', 'video/mov', 'video/wmv', 'video/3gp', 'video/mkv'
        ];

        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => $mimes,
            'fields'         => 'all',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];

        if ($limit > 0) {
            $args['posts_per_page'] = $limit;
            $args['offset'] = $offset;
        } else {
            $args['posts_per_page'] = -1;
        }

        $q = new WP_Query($args);
        return $q->posts ?: [];
    }

    protected function is_404($url) {
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->add_error("Invalid URL: {$url}");
            return true;
        }

        $settings = $this->get_settings();
        
        $args = [
            'timeout'     => $settings['http_timeout'],
            'redirection' => 3,
            'headers'     => [
                'Accept' => '*/*',
                'User-Agent' => 'WordPress Video 404 Cleaner Plugin'
            ],
            'sslverify'   => false, // Allow self-signed certificates
        ];

        // Try HEAD request first (more efficient)
        $res = wp_remote_head($url, $args);
        
        // If HEAD fails or returns no code, try GET
        if (is_wp_error($res) || empty($res['response']['code'])) {
            $res = wp_remote_get($url, $args);
        }

        if (is_wp_error($res)) {
            $this->add_error("HTTP error for {$url}: " . $res->get_error_message());
            return true;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        
        // Use configured error codes
        return in_array($code, $settings['error_codes']);
    }

    protected function remove_video_references_from_post($post_id, $attachment_id, $url) {
        $post = get_post($post_id);
        if (!$post) {
            $this->add_error("Post {$post_id} not found");
            return false;
        }
        
        $content = $post->post_content;
        $original = $content;

        // Escape URL for regex
        $url_escaped = preg_quote($url, '/');
        $url_path = parse_url($url, PHP_URL_PATH);
        $url_path_escaped = preg_quote($url_path, '/');

        // Patterns to match various video formats
        $patterns = [
            // Gutenberg video blocks by attachment ID
            '/<!--\s*wp:video\b[^>]*\{[^}]*"id"\s*:\s*' . $attachment_id . '[^}]*\}[^>]*-->.*?<!--\s*\/wp:video\s*-->/is',
            // Gutenberg video blocks by URL
            '/<!--\s*wp:video\b[^>]*-->.*?' . $url_escaped . '.*?<!--\s*\/wp:video\s*-->/is',
            // Shortcode videos
            '/\[video[^\]]*src=["\']' . $url_escaped . '["\'][^\]]*\](?:.*?)\[\/video\]/is',
            '/\[video[^\]]*src=["\']' . $url_path_escaped . '["\'][^\]]*\](?:.*?)\[\/video\]/is',
            // HTML video tags
            '/<video\b[^>]*src=["\']' . $url_escaped . '["\'][^>]*>.*?<\/video>/is',
            '/<video\b[^>]*src=["\']' . $url_path_escaped . '["\'][^>]*>.*?<\/video>/is',
            // Video with source tags
            '/<video\b[^>]*>.*?<source\b[^>]*src=["\']' . $url_escaped . '["\'][^>]*>.*?<\/video>/is',
            '/<video\b[^>]*>.*?<source\b[^>]*src=["\']' . $url_path_escaped . '["\'][^>]*>.*?<\/video>/is',
            // Links to video files
            '/<a\b[^>]*href=["\']' . $url_escaped . '["\'][^>]*>.*?<\/a>/is',
            '/<a\b[^>]*href=["\']' . $url_path_escaped . '["\'][^>]*>.*?<\/a>/is',
        ];

        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '', $content);
        }

        // Clean up empty paragraphs and whitespace
        $content = preg_replace('/(<p>\s*<\/p>)+/is', '', $content);
        $content = preg_replace('/\n\s*\n\s*\n/', "\n\n", $content);

        if ($content !== $original) {
            $result = wp_update_post([
                'ID' => $post_id, 
                'post_content' => $content
            ]);
            
            if (is_wp_error($result)) {
                $this->add_error("Failed to update post {$post_id}: " . $result->get_error_message());
                return false;
            }
            
            $this->log("Cleaned video references from post {$post_id}");
            return true;
        }
        
        return false;
    }
}

new Video_404_Cleaner();
