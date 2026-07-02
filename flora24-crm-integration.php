<?php
/**
 * Plugin Name: Flora24 CRM Integration for MFlowers
 * Description: Synchronization of prices and availability via Flora24 API with advanced error logging and quick ID editing.
 * Version: 1.6.1
 * Author: Roman NMSK
 */

if (!defined('ABSPATH')) exit;

// =========================================================================
// 1. SETTINGS & OPTIONS PAGE
// =========================================================================

add_action('admin_menu', 'mflowers_flora24_menu');
function mflowers_flora24_menu() {
    add_options_page(
        'Flora 24 Інтеграція',
        'Flora 24',
        'manage_woocommerce',
        'mflowers-flora24',
        'mflowers_flora24_page_render'
    );
}

function mflowers_flora24_page_render() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('У вас немає достатньо прав для доступу до цієї сторінки.'));
    }

    // Save Settings Process
    if (isset($_POST['mflowers_flora24_save_settings']) && check_admin_referer('flora24_settings_nonce')) {
        update_option('mflowers_flora24_api_key', sanitize_text_field($_POST['flora24_api_key']));
        
        $cron_enable = isset($_POST['flora24_cron_enable']) ? 'yes' : 'no';
        update_option('mflowers_flora24_cron_enable', $cron_enable);
        update_option('mflowers_flora24_cron_interval', intval($_POST['flora24_cron_interval']));

        if (class_exists('ActionScheduler')) {
            as_unschedule_all_actions('mflowers_flora24_cron_sync_event');
            
            if ($cron_enable === 'yes') {
                as_schedule_recurring_action(time(), intval($_POST['flora24_cron_interval']), 'mflowers_flora24_cron_sync_event');
            }
        }

        echo '<div class="updated"><p>Налаштування успішно збережено!</p></div>';
    }

    $api_key       = get_option('mflowers_flora24_api_key', '');
    $cron_enable   = get_option('mflowers_flora24_cron_enable', 'no');
    $cron_interval = get_option('mflowers_flora24_cron_interval', HOUR_IN_SECONDS);

    $intervals = [
        900    => 'Кожні 15 хвилин',
        1800   => 'Кожні 30 хвилин',
        3600   => 'Кожну годину (Рекомендовано)',
        7200   => 'Кожні 2 години',
        10800  => 'Кожні 3 години',
        14400  => 'Кожні 4 години',
        21600  => 'Кожні 6 годин',
        43200  => 'Кожні 12 годин',
        86400  => 'Раз на добу',
        172800 => 'Раз на 2 дні',
    ];
    ?>
    <div class="wrap">
        <h1>Керування синхронізацією Flora 24</h1>
        
        <div class="card" style="max-width: 100%; margin-top: 20px; padding: 15px;">
            <?php if (!empty($api_key)) : ?>
                <details>
                    <summary style="font-size: 14px; font-weight: 600; cursor: pointer; color: #2271b1; user-select: none;">
                        Налаштування інтеграції та автооновлення (Натисніть, щоб розгорнути)
                    </summary>
                    <div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 10px;">
            <?php else : ?>
                <h2>Налаштування підключення</h2>
                <div>
            <?php endif; ?>

                <form method="post" action="">
                    <?php wp_nonce_field('flora24_settings_nonce'); ?>
                    <table class="form-table" style="margin-top: 0;">
                        <tr>
                            <th scope="row" style="width: 180px; padding: 10px 10px 10px 0;"><label for="flora24_api_key">X-API-Key</label></th>
                            <td>
                                <input type="password" id="flora24_api_key" name="flora24_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" style="width: 100%; max-width: 450px;" />
                                <p class="description">Введіть секретний ключ авторизації для api.flora24.online</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding: 10px 10px 10px 0;">Автооновлення</th>
                            <td>
                                <label for="flora24_cron_enable">
                                    <input type="checkbox" id="flora24_cron_enable" name="flora24_cron_enable" value="1" <?php checked($cron_enable, 'yes'); ?> />
                                    Увімкнути автоматичну синхронізацію цін та залишків у фоні
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding: 10px 10px 10px 0;"><label for="flora24_cron_interval">Періодичність</label></th>
                            <td>
                                <select id="flora24_cron_interval" name="flora24_cron_interval" style="min-width: 200px;">
                                    <?php foreach ($intervals as $seconds => $label) : ?>
                                        <option value="<?php echo esc_attr($seconds); ?>" <?php selected($cron_interval, $seconds); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Виберіть оптимальний час між фоновими запитами до CRM</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit" style="margin: 10px 0 0 0; padding: 0;">
                        <input type="submit" name="mflowers_flora24_save_settings" class="button button-primary" value="Зберегти налаштування" />
                    </p>
                </form>

            <?php if (!empty($api_key)) : ?>
                    </div>
                </details>
            <?php else : ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($api_key)) : ?>
            <!-- Actions Block -->
            <div style="margin-top: 20px; margin-bottom: 20px;">
                <button type="button" id="mflowers-scan-products" class="button button-secondary">
                    <span class="dashicons dashicons-search" style="vertical-align: middle; margin-top:-3px;"></span> Знайти товари з Flora24 ID
                </button>
                <button type="button" id="mflowers-sync-selected" class="button button-primary" style="display:none; margin-left: 10px;">
                    Синхронізувати вибрані (<span id="selected-count">0</span>)
                </button>
            </div>

            <!-- Table Wrapper -->
            <div id="mflowers-products-table-wrapper" style="display: none; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px;">
                <table class="wp-list-table widefat fixed striped posts">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column"><input id="cb-select-all-1" type="checkbox"></td>
                            <th>Назва товару</th>
                            <th>ID сайту</th>
                            <th>Flora24 ID</th>
                            <th>Поточна ціна</th>
                            <th>Наявність</th>
                            <th>Статус валідації / CRM</th>
                            <th style="text-align: right; padding-right: 15px;">Дія</th>
                        </tr>
                    </thead>
                    <tbody id="mflowers-products-rows">
                        <!-- Ajax Loaded -->
                    </tbody>
                </table>
            </div>

            <!-- Console Log -->
            <div class="card" style="max-width: 100%; background: #23282d; color: #fff; font-family: monospace; padding: 15px; border-radius: 4px; box-shadow: inset 0 0 10px #000;">
                <h3 style="color: #fff; margin-top: 0; border-bottom: 1px solid #32373c; padding-bottom: 8px;">Консоль оновлення (Лог операцій)</h3>
                <div id="mflowers-log-console" style="height: 250px; overflow-y: auto; line-height: 1.5; font-size: 13px;">
                    <span style="color: #888;">[Очікування запуску сканування каталогу...]</span>
                </div>
                
                <div style="margin-top: 15px; border-top: 1px solid #32373c; padding-top: 10px; display: flex; align-items: center; gap: 10px;">
                    <span style="color: #aaa; font-size: 12px;">Переглянути історію за день:</span>
                    <select id="mflowers-log-file-select" style="background: #32373c; color: #fff; border: 1px solid #464b50; padding: 2px 6px; border-radius: 3px;">
                        <?php
                        $log_files = mflowers_flora24_get_log_files();
                        if (!empty($log_files)) {
                            foreach ($log_files as $file) {
                                echo '<option value="' . esc_attr($file) . '">' . esc_html($file) . '</option>';
                            }
                        } else {
                            echo '<option value="">Логів ще немає</option>';
                        }
                        ?>
                    </select>
                    <button type="button" id="mflowers-load-archive-log" class="button button-small" style="background:#007cba; color:#fff; border:none;">Завантажити лог</button>
                </div>
            </div>
        <?php else : ?>
            <div class="notice notice-warning inline" style="margin-top: 15px;"><p>Будь ласка, введіть та збережіть X-API-Key, щоб відкрити доступ до панелі інструментів синхронізації.</p></div>
        <?php endif; ?>
    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var scannedProducts = [];
        var dashboardNonce = '<?php echo wp_create_nonce("mflowers_flora24_dashboard_action"); ?>';

        function appendLog(message, color) {
            var $console = $('#mflowers-log-console');
            var time = new Date().toLocaleTimeString();
            var style = color ? ' style="color:' + color + ';"' : '';
            $console.append('<div' + style + '>[' + time + '] ' + message + '</div>');
            $console.scrollTop($console[0].scrollHeight);
        }

        $('#mflowers-load-archive-log').on('click', function() {
            var filename = $('#mflowers-log-file-select').val();
            if (!filename) return;

            var $console = $('#mflowers-log-console');
            $console.html('<div style="color:#aaa;">Завантаження файлу ' + filename + '...</div>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mflowers_flora24_read_log_file',
                    filename: filename,
                    _ajax_nonce: dashboardNonce
                },
                success: function(response) {
                    if (response.success) {
                        $console.html('');
                        var lines = response.data.split("\n");
                        $.each(lines, function(i, line) {
                            if (!line.trim()) return;
                            
                            var color = '#fff';
                            if (line.indexOf('успішно') !== -1 || line.indexOf('✓') !== -1 || line.indexOf('оновлено') !== -1) color = '#46b450';
                            if (line.indexOf('Помилка') !== -1 || line.indexOf('Збій') !== -1 || line.indexOf('Не знайдено') !== -1) color = '#dc3232';
                            if (line.indexOf('Запуск') !== -1) color = '#ffb900';
                            if (line.indexOf('Запит') !== -1) color = '#2271b1';

                            $console.append('<div style="color:' + color + ';">' + line + '</div>');
                        });
                        $console.scrollTop($console[0].scrollHeight);
                    } else {
                        var errMsg = (response.data && response.data.message) ? response.data.message : 'Не вдалося прочитати файл логу.';
                        $console.html('<div style="color:#dc3232;">' + errMsg + '</div>');
                    }
                }
            });
        });

        $('#mflowers-scan-products').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Пошук товарів...');
            $('#mflowers-log-console').html('');
            appendLog('Запуск сканування бази даних WooCommerce на наявність Flora24 ID...', '#ffb900');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { 
                    action: 'mflowers_flora24_admin_scan',
                    _ajax_nonce: dashboardNonce
                },
                success: function(response) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-search" style="vertical-align: middle; margin-top:-3px;"></span> Знайти товари з Flora24 ID');
                    
                    if (response.success && response.data.length > 0) {
                        scannedProducts = response.data;
                        $('#mflowers-products-rows').html('');
                        
                        $.each(scannedProducts, function(index, item) {
                            var valClass = 'style="color:#46b450; font-weight:bold;"';
                            var valText = '✓ Коректний';
                            var chkDisabled = '';

                            if (!item.valid) {
                                valClass = 'style="color:#dc3232; font-weight:bold;"';
                                valText = '⚠ Помилка ID';
                                chkDisabled = 'disabled';
                            } else if (item.sync_status === 'not_found') {
                                valClass = 'style="color:#dc3232; font-weight:bold;"';
                                valText = '⚠ Не знайдено в CRM';
                            } else if (item.sync_status === 'synced') {
                                valClass = 'style="color:#00a0d2; font-weight:bold;"';
                                valText = '✓ Синхронізовано';
                            }

                            var row = '<tr id="prod-row-' + item.id + '">' +
                                '<th scope="row" class="check-column"><input type="checkbox" class="prod-checkbox" value="' + item.id + '" data-flora-id="' + item.flora_id + '" ' + chkDisabled + '></th>' +
                                '<td><strong><a href="' + item.edit_url + '" target="_blank">' + item.title + '</a></strong></td>' +
                                '<td>' + item.id + '</td>' +
                                '<td><code>' + item.flora_id + '</code></td>' +
                                '<td class="prod-price">' + item.price + ' грн</td>' +
                                '<td class="prod-stock">' + item.stock + '</td>' +
                                '<td class="prod-status" ' + valClass + '>' + valText + '</td>' +
                                '<td style="text-align: right; padding-right: 15px;"><button type="button" class="button button-small single-sync-btn" data-id="' + item.id + '" data-flora-id="' + item.flora_id + '" ' + chkDisabled + '>Оновити</button></td>' +
                            '</tr>';
                            $('#mflowers-products-rows').append(row);
                        });

                        $('#mflowers-products-table-wrapper').show();
                        $('#mflowers-sync-selected').show();
                        appendLog('Сканування завершено. Знайдено товарів з ID: ' + scannedProducts.length, '#46b450');
                    } else {
                        $('#mflowers-products-table-wrapper').hide();
                        $('#mflowers-sync-selected').hide();
                        appendLog('Товарів із заповненим Flora24 ID не знайдено.', '#dc3232');
                    }
                }
            });
        });

        $(document).on('change', '#cb-select-all-1', function() {
            var isChecked = $(this).prop('checked');
            $('.prod-checkbox:not(:disabled)').prop('checked', isChecked).trigger('change');
        });

        $(document).on('change', '.prod-checkbox', function() {
            var count = $('.prod-checkbox:checked').length;
            $('#selected-count').text(count);
        });

        $(document).on('click', '.single-sync-btn', function() {
            var $btn = $(this);
            var pid = $btn.data('id');
            var fid = $btn.data('flora-id');

            $btn.prop('disabled', true).text('...');
            appendLog('Запит API для товару ID ' + pid + ' (Flora ID: ' + fid + ')...', '#2271b1');

            syncProductBatch([ {id: pid, flora_id: fid} ], function() {
                $btn.prop('disabled', false).text('Оновити');
            });
        });

        $('#mflowers-sync-selected').on('click', function() {
            var itemsToSync = [];
            $('.prod-checkbox:checked').each(function() {
                itemsToSync.push({
                    id: $(this).val(),
                    flora_id: $(this).data('flora-id')
                });
            });

            if (itemsToSync.length === 0) {
                alert('Будь ласка, виберіть хоча б один товар!');
                return;
            }

            $('#mflowers-sync-selected').prop('disabled', true).text('Обробка...');
            appendLog('Запуск масової синхронізації для ' + itemsToSync.length + ' товарів...', '#ffb900');
            
            syncProductBatch(itemsToSync, function() {
                $('#mflowers-sync-selected').prop('disabled', false).text('Синхронізувати вибрані');
                $('.prod-checkbox').prop('checked', false);
                $('#selected-count').text('0');
                $('#cb-select-all-1').prop('checked', false);
            });
        });

        function syncProductBatch(items, callback) {
            if (items.length === 0) {
                appendLog('Пакетна обробка черги завершена успішно.', '#46b450');
                if (typeof callback === 'function') callback();
                return;
            }

            var currentItem = items.shift();
            var row = $('#prod-row-' + currentItem.id);
            row.css('background-color', '#f0f6fa');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mflowers_flora24_execute_single_sync',
                    product_id: currentItem.id,
                    flora_id: currentItem.flora_id,
                    _ajax_nonce: dashboardNonce
                },
                success: function(response) {
                    row.css('background-color', '');
                    if (response.success) {
                        row.find('.prod-price').text(response.data.price + ' грн').css('color', '#46b450');
                        row.find('.prod-stock').html(response.data.stock_html);
                        row.find('.prod-status').text('✓ Синхронізовано').css({'color': '#00a0d2', 'font-weight': 'bold'});
                        
                        appendLog('Товар [' + response.data.title + '] оновлено: ' + response.data.log_changes, '#46b450');
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Помилка';
                        if (response.data && response.data.status_code === 'not_found') {
                            row.find('.prod-status').text('⚠ Не знайдено в CRM').css({'color': '#dc3232', 'font-weight': 'bold'});
                        }
                        appendLog('Помилка оновлення ID ' + currentItem.id + ': ' + msg, '#dc3232');
                    }
                    syncProductBatch(items, callback);
                },
                error: function() {
                    row.css('background-color', '');
                    appendLog('Збій мережі при обробці ID ' + currentItem.id, '#dc3232');
                    syncProductBatch(items, callback);
                }
            });
        }
    });
    </script>
    <?php
}

// =========================================================================
// 2. PRODUCT EDIT PAGE METABOX FIELDS
// =========================================================================

// Display Flora24 ID directly under SKU field
add_action('woocommerce_product_options_sku', function() {
    woocommerce_wp_text_input([
        'id'            => '_flora24_id',
        'label'         => __('Flora24 ID', 'mflowers'),
        'desc_tip'      => 'true',
        'description'   => __('Уникальный идентификатор товара из CRM Flora24 (например, PRD-...)', 'mflowers'),
        'type'          => 'text',
        'wrapper_class' => 'form-row form-row-full',
    ]);
});

// Save Flora24 ID on product update
add_action('woocommerce_admin_process_product_object', function($product) {
    if (isset($_POST['_flora24_id'])) {
        $product->update_meta_data('_flora24_id', sanitize_text_field($_POST['_flora24_id']));
    }
});

// =========================================================================
// 3. PRODUCTS LIST QUICK EDIT COLUMN
// =========================================================================

// Add quick custom column right after 'name'
add_filter('manage_edit-product_columns', function($columns) {
    $new_columns = [];

    foreach ($columns as $key => $title) {
        $new_columns[$key] = $title;
        if ($key === 'name') {
            $new_columns['flora24_id_col'] = 'Flora24 ID';
        }
    }

    return $new_columns;
}, 20);

// Render inline text input inside custom column
add_action('manage_product_posts_custom_column', function($column, $post_id) {
    if ($column === 'flora24_id_col') {
        $flora_id = get_post_meta($post_id, '_flora24_id', true);
        
        echo '<input type="text" 
                     class="mflowers-quick-flora-id" 
                     data-product-id="' . esc_attr($post_id) . '" 
                     value="' . esc_attr($flora_id) . '" 
                     placeholder="PRD-..." 
                     style="width: 100%; max-width: 180px; padding: 4px 8px; border-radius: 4px; border: 1px solid #ccd0d4;" />';
        
        echo '<span class="sync-status-' . esc_attr($post_id) . '" style="display:block; font-size:11px; margin-top:3px; min-height:15px;"></span>';
    }
}, 10, 2);

// Handle inline quick-edit AJAX requests (with Nonce check)
add_action('wp_ajax_mflowers_save_quick_flora_id', function() {
    check_ajax_referer('mflowers_quick_edit_id_nonce', '_ajax_nonce');

    if (!current_user_can('edit_products')) {
        wp_send_json_error(['message' => 'No permission'], 403);
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $flora_id   = isset($_POST['flora_id']) ? sanitize_text_field($_POST['flora_id']) : '';

    if ($product_id > 0) {
        update_post_meta($product_id, '_flora24_id', $flora_id);
        delete_post_meta($product_id, '_flora24_sync_status'); // clear sync state on id change
        wp_send_json_success(['message' => 'Збережено!']);
    }

    wp_send_json_error(['message' => 'Invalid data'], 400);
});

// Enqueue quick-edit inline javascript asset into admin footer
add_action('admin_print_footer_scripts', function() {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'edit-product') {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var quickEditNonce = '<?php echo wp_create_nonce("mflowers_quick_edit_id_nonce"); ?>';

        $(document).on('change', '.mflowers-quick-flora-id', function() {
            var $input = $(this);
            var productId = $input.data('product-id');
            var floraId = $input.val();
            var $status = $('.sync-status-' + productId);

            $status.text('Зберігаю...').css('color', '#666');
            $input.css('border-color', '#3582c4');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mflowers_save_quick_flora_id',
                    product_id: productId,
                    flora_id: floraId,
                    _ajax_nonce: quickEditNonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.text('✓ Збережено').css('color', '#46b450');
                        $input.css('border-color', '#46b450');
                        
                        setTimeout(function() {
                            $status.text('');
                            $input.css('border-color', '#ccd0d4');
                        }, 2000);
                    } else {
                        $status.text('Помилка').css('color', '#dc3232');
                        $input.css('border-color', '#dc3232');
                    }
                },
                error: function() {
                    $status.text('Помилка мережі').css('color', '#dc3232');
                    $input.css('border-color', '#dc3232');
                }
            });
        });
    });
    </script>
    <?php
});

// =========================================================================
// 4. AJAX ACTIONS & AJAX CORE HANDLERS
// =========================================================================

// AJAX: Render log file inside modern custom dashboard terminal (Strict validation rules added)
add_action('wp_ajax_mflowers_flora24_read_log_file', 'mflowers_flora24_read_log_file_handler');
function mflowers_flora24_read_log_file_handler() {
    check_ajax_referer('mflowers_flora24_dashboard_action', '_ajax_nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'No access'], 403);

    $filename = isset($_POST['filename']) ? sanitize_text_field($_POST['filename']) : '';
    
    // Strict filename format validation rule (prevents path traversal completely)
    if (!preg_match('/^sync-\d{4}-\d{2}-\d{2}\.log$/', $filename)) {
        wp_send_json_error(['message' => 'Некоректний формат імені файлу.']);
    }

    $upload_dir = wp_upload_dir();
    $file_path  = path_join($upload_dir['basedir'], 'flora24-logs/' . $filename);

    if (file_exists($file_path)) {
        // Safe memory allocation check constraint limit
        $filesize = filesize($file_path);
        if ($filesize > 2 * MB_IN_BYTES) {
            wp_send_json_error(['message' => 'Файл занадто великий для відображення (> 2MB). Будь ласка, завантажте його через FTP/SSH.']);
        }

        $content = file_get_contents($file_path);
        wp_send_json_success($content);
    } else {
        wp_send_json_error(['message' => 'Файл не знайдено.']);
    }
}

// AJAX: Scan matching WooCommerce catalog products
add_action('wp_ajax_mflowers_flora24_admin_scan', 'mflowers_flora24_admin_scan_handler');
function mflowers_flora24_admin_scan_handler() {
    check_ajax_referer('mflowers_flora24_dashboard_action', '_ajax_nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'No access'], 403);

    $products_query = new WP_Query([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'meta_query'     => [
            ['key' => '_flora24_id', 'compare' => 'EXISTS'],
            ['key' => '_flora24_id', 'value' => '', 'compare' => '!=']
        ]
    ]);

    $data = [];
    if ($products_query->have_posts()) {
        while ($products_query->have_posts()) {
            $products_query->the_post();
            $product = wc_get_product(get_the_ID());
            if (!$product) continue;

            $flora_id = trim(get_post_meta($product->get_id(), '_flora24_id', true));
            $is_valid = (bool) preg_match('/^(PRD|ПРД)-.+$/ui', $flora_id);
            $sync_status = get_post_meta($product->get_id(), '_flora24_sync_status', true);

            $data[] = [
                'id'          => $product->get_id(),
                'title'       => $product->get_name(),
                'flora_id'    => $flora_id,
                'price'       => $product->get_regular_price(),
                'stock'       => $product->is_in_stock() ? '<span style="color:#46b450;">В наявності</span>' : '<span style="color:#dc3232;">Немає</span>',
                'valid'       => $is_valid,
                'sync_status' => $sync_status ? $sync_status : 'pending',
                'edit_url'    => get_edit_post_link($product->get_id())
            ];
        }
        wp_reset_postdata();
    }
    wp_send_json_success($data);
}

// AJAX: Handle synchronous processing queue for products updates (Mutex lock + optimization cache added)
add_action('wp_ajax_mflowers_flora24_execute_single_sync', 'mflowers_flora24_execute_single_sync_handler');
function mflowers_flora24_execute_single_sync_handler() {
    check_ajax_referer('mflowers_flora24_dashboard_action', '_ajax_nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'No access'], 403);

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $flora_id   = isset($_POST['flora_id']) ? sanitize_text_field($_POST['flora_id']) : '';

    if (!$product_id || empty($flora_id)) {
        wp_send_json_error(['message' => 'Невірні параметри']);
    }

    $api_key = get_option('mflowers_flora24_api_key', '');
    if (empty($api_key)) wp_send_json_error(['message' => 'Відсутній API-ключ']);

    $product = wc_get_product($product_id);
    $p_name  = $product ? $product->get_name() : "ID {$product_id}";

    // --- MUTEX / LOCK MECHANISM ---
    if (get_transient('mflowers_flora24_sync_lock')) {
        wp_send_json_error(['message' => 'Процес синхронізації вже виконується у фоні. Зачекайте хвилину.']);
    }
    set_transient('mflowers_flora24_sync_lock', '1', 5 * MINUTE_IN_SECONDS);

    // --- OPTIMIZATION BATCH CACHE ---
    // If mass synchronization is running, we store the api response for 2 minutes to eliminate continuous network calls
    $body = get_transient('mflowers_flora24_api_cache');
    
    if (false === $body) {
        $response = wp_remote_get('https://api.flora24.online/v1/products', [
            'headers' => [
                'X-API-Key'        => $api_key,
                'X-Client-Name'    => 'mflowers-wp-sync',
                'X-Client-Version' => '1.6.0',
                'Accept'           => 'application/json',
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            delete_transient('mflowers_flora24_sync_lock');
            mflowers_flora24_write_log_file("Помилка API при запиті товару [{$p_name}]: " . $response->get_error_message());
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            delete_transient('mflowers_flora24_sync_lock');
            $err_msg = 'API error code ' . wp_remote_retrieve_response_code($response);
            mflowers_flora24_write_log_file("Помилка відповіді API для [{$p_name}]: {$err_msg}");
            wp_send_json_error(['message' => $err_msg]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body) && isset($body['products']) && is_array($body['products'])) {
            set_transient('mflowers_flora24_api_cache', $body, 2 * MINUTE_IN_SECONDS);
        }
    }

    if (empty($body) || !isset($body['products']) || !is_array($body['products'])) {
        delete_transient('mflowers_flora24_sync_lock');
        mflowers_flora24_write_log_file("Помилка відповіді API для [{$p_name}]: Некоректна структура масиву JSON");
        wp_send_json_error(['message' => 'Некоректна структура відповіді від API.']);
    }

    $found_flora = null;
    $normal_flora_id = strtolower(str_replace('ПРД-', 'prd-', $flora_id));

    foreach ($body['products'] as $p) {
        if (isset($p['id']) && strtolower(str_replace('ПРД-', 'prd-', trim($p['id']))) === $normal_flora_id) {
            $found_flora = $p;
            break;
        }
    }

    if (!$found_flora) {
        update_post_meta($product_id, '_flora24_sync_status', 'not_found');
        delete_transient('mflowers_flora24_sync_lock');
        mflowers_flora24_write_log_file("Помилка: Товар ID {$product_id} (Flora ID: {$flora_id}) [{$p_name}] не знайдено в CRM");
        wp_send_json_error(['message' => 'Товар не знайдено в CRM', 'status_code' => 'not_found']);
    }

    if (!$product) {
        delete_transient('mflowers_flora24_sync_lock');
        wp_send_json_error(['message' => 'Товар видалено з сайту']);
    }

    // Strict Object structure checks validation routine
    if (!isset($found_flora['price'])) {
        delete_transient('mflowers_flora24_sync_lock');
        mflowers_flora24_write_log_file("Помилка валідації товару [{$p_name}]: Відсутнє поле price в об'єкті CRM");
        wp_send_json_error(['message' => 'Відсутнє обов\'язкове поле ціни в CRM об\'єкті.']);
    }

    $old_price = $product->get_regular_price();
    $old_stock = $product->get_stock_status();
    $old_stock_text = ($old_stock === 'instock') ? 'В наявності' : 'Немає';

    $new_price = floatval($found_flora['price']) / 100;
    $is_active = isset($found_flora['active']) ? (bool)$found_flora['active'] : false;
    $new_stock_status = $is_active ? 'instock' : 'outofstock';
    $new_stock_text = $is_active ? 'В наявності' : 'Немає';
    $stock_html = $is_active ? '<span style="color:#46b450;">В наявності</span>' : '<span style="color:#dc3232;">Немає</span>';

    $product->set_regular_price($new_price);
    $product->set_stock_status($new_stock_status);
    $product->save();

    update_post_meta($product_id, '_flora24_sync_status', 'synced');

    $log_changes = "Ціна: [{$old_price} грн -> {$new_price} грн] | Наявність: [{$old_stock_text} -> {$new_stock_text}]";
    mflowers_flora24_write_log_file("Товар ID {$product_id} (Flora ID: {$flora_id}) [{$p_name}]: {$log_changes}");

    // Release Lock
    delete_transient('mflowers_flora24_sync_lock');

    wp_send_json_success([
        'price'       => $new_price,
        'stock_html'  => $stock_html,
        'log_changes' => $log_changes,
        'title'       => $p_name
    ]);
}

// =========================================================================
// 5. FILE SYSTEM STORAGE LOGGER
// =========================================================================

function mflowers_flora24_write_log_file($message) {
    $upload_dir = wp_upload_dir();
    $log_dir    = path_join($upload_dir['basedir'], 'flora24-logs');
    
    if (!file_exists($log_dir)) wp_mkdir_p($log_dir);

    // Garbage collector routine (30 days lifespan ceiling limit constraint execution loop)
    $files = glob($log_dir . '/sync-*.log');
    $thirty_days_ago = time() - (30 * DAY_IN_SECONDS);
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $thirty_days_ago) {
            @unlink($file);
        }
    }

    $current_date = date('Y-m-d');
    $log_file     = path_join($log_dir, "sync-{$current_date}.log");
    $time         = date('H:i:s');
    
    $log_entry = "[{$time}] {$message}\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
}

function mflowers_flora24_get_log_files() {
    $upload_dir = wp_upload_dir();
    $log_dir    = path_join($upload_dir['basedir'], 'flora24-logs');
    if (!file_exists($log_dir)) return [];

    $files = glob($log_dir . '/sync-*.log');
    if (empty($files)) return [];

    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    return array_map('basename', $files);
}

// =========================================================================
// 6. ACTION SCHEDULER BACKGROUND AGENT (AUTOMATED CRON SYNC)
// =========================================================================

add_action('init', function() {
    if (!class_exists('ActionScheduler')) return;

    $cron_enable   = get_option('mflowers_flora24_cron_enable', 'no');
    $cron_interval = intval(get_option('mflowers_flora24_cron_interval', HOUR_IN_SECONDS));

    if ($cron_enable === 'yes') {
        if (!as_next_scheduled_action('mflowers_flora24_cron_sync_event')) {
            as_schedule_recurring_action(time(), $cron_interval, 'mflowers_flora24_cron_sync_event'); 
        }
    } else {
        as_unschedule_all_actions('mflowers_flora24_cron_sync_event');
    }
});

add_action('mflowers_flora24_cron_sync_event', 'mflowers_flora24_execute_cron_sync');
function mflowers_flora24_execute_cron_sync() {
    // Prevent overlapping during automation tasks
    if (get_transient('mflowers_flora24_sync_lock')) {
        mflowers_flora24_write_log_file("Авто-Крон | Пропущено: Інший процес синхронізації активний.");
        return;
    }
    set_transient('mflowers_flora24_sync_lock', '1', 10 * MINUTE_IN_SECONDS);

    $api_key = get_option('mflowers_flora24_api_key', '');
    if (empty($api_key)) {
        delete_transient('mflowers_flora24_sync_lock');
        return;
    }

    $products_query = new WP_Query([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            ['key' => '_flora24_id', 'compare' => 'EXISTS'],
            ['key' => '_flora24_id', 'value' => '', 'compare' => '!=']
        ]
    ]);

    if (empty($products_query->posts)) {
        delete_transient('mflowers_flora24_sync_lock');
        return;
    }

    $response = wp_remote_get('https://api.flora24.online/v1/products', [
        'headers' => [
            'X-API-Key'        => $api_key,
            'X-Client-Name'    => 'mflowers-wp-cron-sync',
            'X-Client-Version' => '1.6.1',
            'Accept'           => 'application/json'
        ],
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        delete_transient('mflowers_flora24_sync_lock');
        mflowers_flora24_write_log_file("Помилка автоматичного Крону: " . $response->get_error_message());
        return;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body) || !isset($body['products']) || !is_array($body['products'])) {
        delete_transient('mflowers_flora24_sync_lock');
        mflowers_flora24_write_log_file("Авто-Крон | Помилка структури API.");
        return;
    }

    $flora_map = [];
    foreach ($body['products'] as $p) {
        if (isset($p['id'])) {
            $flora_map[strtolower(str_replace('ПРД-', 'prd-', trim($p['id'])))] = $p;
        }
    }

    foreach ($products_query->posts as $pid) {
        $flora_id = trim(get_post_meta($pid, '_flora24_id', true));
        $norm_site_id = strtolower(str_replace('ПРД-', 'prd-', $flora_id));
        
        $product = wc_get_product($pid);
        $p_name  = $product ? $product->get_name() : "ID {$pid}";

        if (isset($flora_map[$norm_site_id])) {
            if ($product) {
                $p_data = $flora_map[$norm_site_id];
                
                if (!isset($p_data['price'])) continue;

                $old_price = $product->get_regular_price();
                $old_stock = $product->get_stock_status() === 'instock' ? 'В наявності' : 'Немає';
                
                $new_price = floatval($p_data['price']) / 100;
                $new_stock = (isset($p_data['active']) && $p_data['active']) ? 'В наявності' : 'Немає';

                if ($old_price != $new_price || $old_stock != $new_stock) {
                    $product->set_regular_price($new_price);
                    $product->set_stock_status((isset($p_data['active']) && $p_data['active']) ? 'instock' : 'outofstock');
                    $product->save();

                    mflowers_flora24_write_log_file("Авто-Крон | Товар ID {$pid} (Flora ID: {$flora_id}) [{$p_name}]: Ціна [{$old_price} -> {$new_price}] | Наявність [{$old_stock} -> {$new_stock}]");
                }
                update_post_meta($pid, '_flora24_sync_status', 'synced');
            }
        } else {
            update_post_meta($pid, '_flora24_sync_status', 'not_found');
            mflowers_flora24_write_log_file("Авто-Крон | Помилка: Товар ID {$pid} (Flora ID: {$flora_id}) [{$p_name}] не знайдено в CRM");
        }
    }

    delete_transient('mflowers_flora24_sync_lock');
}