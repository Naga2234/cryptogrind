<?php
/**
 * Plugin Name: Crypto News Auto Poster
 * Version: 3.5.0
 * Description: Clean Edition - без тегов и хэштегов
 */

if (!defined('ABSPATH')) exit;

register_activation_hook(__FILE__, 'cnap_install');
function cnap_install() {
    add_option('cnap_enabled', 0);
    add_option('cnap_interval', 'five_minutes');
    add_option('cnap_count', 3);
    add_option('cnap_sources', array('cryptonewsz'));
    add_option('cnap_stopwords', "Subscribe\nFollow us\nJoin us\nSign up\nNewsletter\nDisclaimer\nAdvertisement");
    add_option('cnap_stats', array(
        'total_checked' => 0,
        'total_published' => 0,
        'total_filtered' => 0,
        'last_run' => '',
        'start_time' => 0,
        'uptime' => 0
    ));
}

add_filter('cron_schedules', 'cnap_cron_schedules');
function cnap_cron_schedules($schedules) {
    $schedules['five_minutes'] = array('interval' => 300, 'display' => 'Каждые 5 минут');
    $schedules['ten_minutes'] = array('interval' => 600, 'display' => 'Каждые 10 минут');
    return $schedules;
}

register_deactivation_hook(__FILE__, 'cnap_uninstall');
function cnap_uninstall() {
    wp_clear_scheduled_hook('cnap_cron');
}

add_action('init', 'cnap_maybe_schedule_cron');
function cnap_maybe_schedule_cron() {
    $enabled = get_option('cnap_enabled', 0);
    $interval = get_option('cnap_interval', 'five_minutes');

    $current_schedule = wp_get_schedule('cnap_cron');
    $next_scheduled = wp_next_scheduled('cnap_cron');

    if ($enabled) {
        if ($current_schedule !== $interval) {
            wp_clear_scheduled_hook('cnap_cron');
            $next_scheduled = wp_next_scheduled('cnap_cron');
        }
        if (!$next_scheduled) {
            wp_schedule_event(time() + 60, $interval, 'cnap_cron');
        }
    } elseif ($next_scheduled) {
        wp_clear_scheduled_hook('cnap_cron');
    }
}

add_action('admin_menu', 'cnap_menu');
function cnap_menu() {
    add_menu_page('Crypto News', 'Crypto News', 'manage_options', 'crypto-news', 'cnap_page', 'dashicons-rss');
}

add_action('wp_head', 'cnap_post_styles');
function cnap_post_styles() {
    if (is_single()) {
        echo '<style>
        .cnap-post-content {
            max-width: 800px;
            margin: 0 auto;
            font-size: 18px;
            line-height: 1.8;
            color: #333;
        }
        .cnap-post-content p {
            margin: 1.2em 0;
            text-align: justify;
        }
        .cnap-post-content img {
            max-width: 100%;
            width: 100%;
            height: auto;
            object-fit: cover;
            max-height: 500px;
            border-radius: 8px;
            margin: 1em 0;
            display: block;
        }
        .cnap-post-content figure {
            margin: 1em 0;
        }
        .cnap-post-content figcaption,
        .cnap-post-content .img-caption {
            font-weight: 700;
            font-size: 16px;
            color: #555;
            margin: 0.5em 0 1em 0;
            text-align: center;
            font-style: italic;
        }
        .cnap-post-content h2, .cnap-post-content h3 {
            margin: 1.8em 0 0.8em;
            font-weight: 700;
        }
        .cnap-post-content blockquote {
            border-left: 4px solid #2563EB;
            padding-left: 1.5em;
            margin: 1.5em 0;
            font-style: italic;
            color: #555;
        }
        </style>';
    }
}

function cnap_get_available_sources() {
    return array(
        'cryptonewsz' => array(
            'name' => 'CryptoNewsZ',
            'feed' => 'https://www.cryptonewsz.com/feed/'
        ),
        'coindesk' => array(
            'name' => 'CoinDesk',
            'feed' => 'https://www.coindesk.com/arc/outboundfeeds/rss/'
        ),
        'cointelegraph' => array(
            'name' => 'Cointelegraph',
            'feed' => 'https://cointelegraph.com/rss'
        ),
        'u-today' => array(
            'name' => 'U.Today',
            'feed' => 'https://u.today/rss'
        )
    );
}

function cnap_get_selected_sources() {
    $selected = get_option('cnap_sources', array('cryptonewsz'));
    if (!is_array($selected)) {
        $selected = array($selected);
    }

    $available = cnap_get_available_sources();
    $valid = array_values(array_intersect($selected, array_keys($available)));

    if (empty($valid)) {
        $valid = array('cryptonewsz');
    }

    return $valid;
}

function cnap_page() {
    $enabled = get_option('cnap_enabled', 0);
    $count = get_option('cnap_count', 3);
    $interval = get_option('cnap_interval', 'five_minutes');
    $stopwords = get_option('cnap_stopwords', '');
    $selected_sources = cnap_get_selected_sources();
    $available_sources = cnap_get_available_sources();

    $stats = get_option('cnap_stats', array(
        'total_checked' => 0,
        'total_published' => 0,
        'total_filtered' => 0,
        'last_run' => '',
        'start_time' => 0,
        'uptime' => 0
    ));

    $start_time = isset($stats['start_time']) ? $stats['start_time'] : 0;
    $uptime = isset($stats['uptime']) ? $stats['uptime'] : 0;

    $uptime_seconds = 0;
    if ($enabled && $start_time > 0) {
        $uptime_seconds = time() - $start_time;
    } elseif (!$enabled && $uptime > 0) {
        $uptime_seconds = $uptime;
    }

    $uptime_hours = floor($uptime_seconds / 3600);
    $uptime_minutes = floor(($uptime_seconds % 3600) / 60);
    $uptime_display = sprintf('%dч %dмин', $uptime_hours, $uptime_minutes);

    ?>
    <div style="max-width:1200px;margin:20px;">
        <div style="background:linear-gradient(135deg,#10b981,#059669);color:white;padding:40px;border-radius:12px;margin-bottom:20px;box-shadow:0 4px 6px rgba(0,0,0,0.1);">
            <h1 style="margin:0;font-size:32px;">🚀 Crypto News Auto Poster v3.5.0</h1>
            <p style="margin:10px 0 0 0;opacity:0.95;font-size:16px;">Clean Edition - без тегов и хэштегов</p>
        </div>

        <?php if ($enabled): ?>
        <div style="background:#dcfce7;padding:20px;border-radius:12px;margin-bottom:20px;border-left:4px solid #10b981;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <h3 style="margin:0 0 5px 0;color:#065f46;">⏱️ АВТОПОСТИНГ РАБОТАЕТ</h3>
                    <p style="margin:0;color:#166534;">Время работы: <strong><?php echo $uptime_display; ?></strong></p>
                </div>
                <div style="font-size:48px;">🟢</div>
            </div>
        </div>
        <?php endif; ?>

        <div style="background:#e0e7ff;padding:20px;border-radius:12px;margin-bottom:20px;border-left:4px solid #6366f1;">
            <h3 style="margin:0 0 10px 0;color:#3730a3;">🆕 Новое в v3.5.0 CLEAN</h3>
            <p style="margin:0;color:#3730a3;line-height:1.6;">
                ✅ ПОЛНОСТЬЮ УДАЛЕНЫ теги<br>
                ✅ ПОЛНОСТЬЮ УДАЛЕНЫ хэштеги<br>
                ✅ Чистые посты без меток<br>
                ✅ Идеальное форматирование<br>
                ✅ Без "Также читайте"<br>
                ✅ Без дублей фото
            </p>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-bottom:20px;">
            <div style="background:white;padding:20px;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 15px 0;font-size:14px;color:#6b7280;text-transform:uppercase;">📊 Проверено</h3>
                <p style="margin:0;font-size:36px;font-weight:bold;color:#10b981;"><?php echo number_format($stats['total_checked']); ?></p>
            </div>
            <div style="background:white;padding:20px;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 15px 0;font-size:14px;color:#6b7280;text-transform:uppercase;">✅ Опубликовано</h3>
                <p style="margin:0;font-size:36px;font-weight:bold;color:#10b981;"><?php echo number_format($stats['total_published']); ?></p>
            </div>
            <div style="background:white;padding:20px;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 15px 0;font-size:14px;color:#6b7280;text-transform:uppercase;">🚫 Отфильтровано</h3>
                <p style="margin:0;font-size:36px;font-weight:bold;color:#ef4444;"><?php echo number_format($stats['total_filtered']); ?></p>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
            <div style="background:white;padding:25px;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                <h2 style="margin-top:0;">⚙️ Автопостинг</h2>
                <p><strong>Статус:</strong> <span style="font-size:20px;"><?php echo $enabled ? '🟢' : '🔴'; ?></span> <?php echo $enabled ? 'Включен' : 'Выключен'; ?></p>
                <?php if ($enabled): ?>
                <p><strong>Время:</strong><br><?php echo $uptime_display; ?></p>
                <?php endif; ?>
                <form method="post" style="margin-top:20px;">
                    <?php wp_nonce_field('cnap_save_settings', 'cnap_settings_nonce'); ?>
                    <label><strong>Интервал:</strong></label><br>
                    <select name="interval" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin:5px 0 15px 0;">
                        <option value="five_minutes" <?php selected($interval, 'five_minutes'); ?>>5 минут</option>
                        <option value="ten_minutes" <?php selected($interval, 'ten_minutes'); ?>>10 минут</option>
                        <option value="hourly" <?php selected($interval, 'hourly'); ?>>1 час</option>
                    </select>
                    <label><strong>Постов:</strong></label><br>
                    <input type="number" name="count" value="<?php echo $count; ?>" min="1" max="20" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin:5px 0 15px 0;">
                    <label><strong>Источники:</strong></label><br>
                    <div style="margin:8px 0 15px 0;padding:10px;border:1px solid #ddd;border-radius:6px;max-height:160px;overflow:auto;">
                        <?php foreach ($available_sources as $key => $source): ?>
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <input type="checkbox" name="sources[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $selected_sources, true)); ?>>
                                <span><?php echo esc_html($source['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="cnap_save_settings" value="1">
                    <button type="submit" class="button button-primary" style="width:100%;">💾 Сохранить</button>
                </form>
                <div style="border-top:1px solid #e5e7eb;padding-top:15px;margin-top:15px;">
                    <button onclick="cnap_toggle()" class="button button-primary" style="margin-right:10px;width:48%;">
                        <?php echo $enabled ? '⏸️ Стоп' : '▶️ Старт'; ?>
                    </button>
                    <button onclick="cnap_fetch()" class="button button-primary" style="width:48%;">
                        🔄 Вручную
                    </button>
                </div>
            </div>

            <div style="background:white;padding:25px;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                <h2 style="margin-top:0;">🚫 Стоп-слова</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('cnap_save_stopwords', 'cnap_stopwords_nonce'); ?>
                    <textarea name="stopwords" rows="10" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-family:monospace;font-size:14px;"><?php echo esc_textarea($stopwords); ?></textarea>
                    <input type="hidden" name="cnap_save_stopwords" value="1">
                    <button type="submit" class="button button-primary" style="width:100%;margin-top:10px;">💾 Сохранить</button>
                </form>
            </div>
        </div>

        <div style="background:white;padding:25px;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
            <h2 style="margin-top:0;">📰 Последние посты</h2>
            <?php
            $posts = get_posts(array('posts_per_page' => 15, 'meta_key' => 'cnap_post'));
            if ($posts) {
                echo '<div style="display:grid;gap:15px;">';
                foreach ($posts as $p) {
                    $photos_count = get_post_meta($p->ID, 'cnap_photos_count', true);

                    echo '<div style="padding:15px;background:#f9fafb;border-radius:8px;display:flex;gap:15px;align-items:center;">';
                    if (has_post_thumbnail($p->ID)) {
                        echo '<div style="flex-shrink:0;">' . get_the_post_thumbnail($p->ID, array(80,80), array('style' => 'border-radius:6px;')) . '</div>';
                    }
                    echo '<div style="flex:1;">';
                    echo '<strong>' . esc_html($p->post_title) . '</strong><br>';
                    echo '<small style="color:#6b7280;">';
                    if ($photos_count) echo '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:11px;margin-right:8px;">📷 ' . $photos_count . '</span>';
                    echo get_the_date('d.m.Y H:i', $p->ID);
                    echo '</small>';
                    echo '</div>';
                    echo '<a href="' . get_edit_post_link($p->ID) . '" class="button button-small">Ред.</a>';
                    echo '</div>';
                }
                echo '</div>';
            } else {
                echo '<p style="text-align:center;color:#6b7280;padding:40px;">Нет постов</p>';
            }
            ?>
        </div>

        <div id="cnap-result" style="margin-top:20px;padding:20px;background:#dcfce7;border:2px solid #10b981;border-radius:12px;display:none;"></div>
        <div id="cnap-loading" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.8);z-index:9999;align-items:center;justify-content:center;">
            <div style="text-align:center;color:white;">
                <div style="width:60px;height:60px;border:4px solid rgba(255,255,255,0.3);border-top-color:white;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 20px;"></div>
                <p style="font-size:18px;margin:0;">Загрузка...</p>
            </div>
        </div>
    </div>

    <style>@keyframes spin { to { transform: rotate(360deg); }}</style>

    <script>
    const cnapNonce = '<?php echo esc_js(wp_create_nonce('cnap_ajax')); ?>';
    function cnap_toggle() {
        jQuery.post(ajaxurl, {action: 'cnap_toggle', nonce: cnapNonce}, function(r) {
            alert(r.data);
            location.reload();
        });
    }

    function cnap_fetch() {
        jQuery('#cnap-loading').css('display', 'flex');
        jQuery('#cnap-result').hide();

        jQuery.post(ajaxurl, {action: 'cnap_fetch', nonce: cnapNonce}, function(r) {
            jQuery('#cnap-loading').hide();
            jQuery('#cnap-result').html(r.data).slideDown();
            setTimeout(function() { location.reload(); }, 3000);
        });
    }
    </script>
    <?php

    if (isset($_POST['cnap_save_settings'])) {
        check_admin_referer('cnap_save_settings', 'cnap_settings_nonce');
        update_option('cnap_count', intval($_POST['count']));
        $new_interval = sanitize_text_field($_POST['interval']);
        $available_sources = cnap_get_available_sources();
        $sources_input = isset($_POST['sources']) ? (array) $_POST['sources'] : array();
        $sources_sanitized = array();
        foreach ($sources_input as $source_key) {
            $source_key = sanitize_text_field($source_key);
            if (isset($available_sources[$source_key])) {
                $sources_sanitized[] = $source_key;
            }
        }
        if (empty($sources_sanitized)) {
            $sources_sanitized = array('cryptonewsz');
        }
        update_option('cnap_sources', array_values(array_unique($sources_sanitized)));
        update_option('cnap_interval', $new_interval);
        if (get_option('cnap_enabled', 0)) {
            wp_clear_scheduled_hook('cnap_cron');
            wp_schedule_event(time() + 60, $new_interval, 'cnap_cron');
        }
        echo '<div style="background:#d1fae5;border:2px solid #10b981;padding:15px;margin:20px 0;border-radius:8px;color:#065f46;"><strong>✅ Сохранено!</strong></div>';
    }

    if (isset($_POST['cnap_save_stopwords'])) {
        check_admin_referer('cnap_save_stopwords', 'cnap_stopwords_nonce');
        update_option('cnap_stopwords', sanitize_textarea_field($_POST['stopwords']));
        echo '<div style="background:#d1fae5;border:2px solid #10b981;padding:15px;margin:20px 0;border-radius:8px;color:#065f46;"><strong>✅ Сохранено!</strong></div>';
    }
}

add_action('wp_ajax_cnap_toggle', 'cnap_ajax_toggle');
function cnap_ajax_toggle() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостаточно прав');
    }
    check_ajax_referer('cnap_ajax', 'nonce');

    $enabled = get_option('cnap_enabled', 0);
    $stats = get_option('cnap_stats', array());
    $interval = get_option('cnap_interval', 'five_minutes');

    if ($enabled) {
        if (isset($stats['start_time']) && $stats['start_time'] > 0) {
            $stats['uptime'] = time() - $stats['start_time'];
        }
        update_option('cnap_enabled', 0);
        update_option('cnap_stats', $stats);
        wp_clear_scheduled_hook('cnap_cron');
        wp_send_json_success('⏸️ Остановлен');
    } else {
        $stats['start_time'] = time();
        $stats['uptime'] = 0;
        update_option('cnap_enabled', 1);
        update_option('cnap_stats', $stats);
        wp_clear_scheduled_hook('cnap_cron');
        wp_schedule_event(time() + 60, $interval, 'cnap_cron');
        wp_send_json_success('✅ Запущен!');
    }
}

add_action('wp_ajax_cnap_fetch', 'cnap_ajax_fetch');
function cnap_ajax_fetch() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостаточно прав');
    }
    check_ajax_referer('cnap_ajax', 'nonce');

    $result = cnap_get_news();

    $msg = '<strong>📊 РЕЗУЛЬТАТ:</strong><br><br>';
    $msg .= 'Найдено: ' . $result['fetched'] . '<br>';
    $msg .= 'Опубликовано: ' . $result['published'] . '<br>';
    $msg .= 'Пропущено: ' . $result['skipped'] . '<br>';
    if ($result['total_photos'] > 0) {
        $msg .= 'Фото: ' . $result['total_photos'];
    }

    wp_send_json_success($msg);
}

add_action('cnap_cron', 'cnap_cron_run');
function cnap_cron_run() {
    if (get_option('cnap_enabled', 0)) {
        cnap_get_news();
    }
}

function cnap_get_news() {
    $count = get_option('cnap_count', 3);
    $stopwords_text = get_option('cnap_stopwords', '');
    $stopwords = array_filter(array_map('trim', explode("\n", $stopwords_text)));
    $selected_sources = cnap_get_selected_sources();
    $available_sources = cnap_get_available_sources();

    $stats = array(
        'fetched' => 0,
        'published' => 0,
        'skipped' => 0,
        'total_photos' => 0
    );

    foreach ($selected_sources as $source_key) {
        if ($stats['published'] >= $count) {
            break;
        }

        if (!isset($available_sources[$source_key])) {
            continue;
        }

        $source = $available_sources[$source_key];
        $rss = fetch_feed($source['feed']);
        if (is_wp_error($rss)) {
            continue;
        }

        $items = $rss->get_items(0, 20);
        $stats['fetched'] += count($items);

        foreach ($items as $item) {
            if ($stats['published'] >= $count) break;

            $title = $item->get_title();
            $link = $item->get_permalink();

            $exists = get_posts(array(
                'post_type' => 'post',
                'meta_key' => 'cnap_link',
                'meta_value' => $link,
                'posts_per_page' => 1,
                'fields' => 'ids'
            ));

            if (!empty($exists)) {
                $stats['skipped']++;
                continue;
            }

            $full = cnap_deep_parse_v35($link, $stopwords);

            if (empty($full['content']) || empty($full['featured_image']) || $full['photos_count'] == 0) {
                $stats['skipped']++;
                continue;
            }

            $post_content = '<div class="cnap-post-content">' . $full['content'] . '</div>';

            $post_id = wp_insert_post(array(
                'post_title' => $title,
                'post_content' => $post_content,
                'post_status' => 'publish',
                'post_author' => 1
            ));

            if ($post_id) {
                update_post_meta($post_id, 'cnap_post', 1);
                update_post_meta($post_id, 'cnap_link', $link);
                update_post_meta($post_id, 'cnap_source', $source['name']);
                update_post_meta($post_id, 'cnap_photos_count', $full['photos_count']);

                cnap_set_thumb($post_id, $full['featured_image']);

                $stats['published']++;
                $stats['total_photos'] += $full['photos_count'];
            }
        }
    }

    $global_stats = get_option('cnap_stats', array());
    $global_stats['total_checked'] = (isset($global_stats['total_checked']) ? $global_stats['total_checked'] : 0) + $stats['fetched'];
    $global_stats['total_published'] = (isset($global_stats['total_published']) ? $global_stats['total_published'] : 0) + $stats['published'];
    $global_stats['total_filtered'] = (isset($global_stats['total_filtered']) ? $global_stats['total_filtered'] : 0) + $stats['skipped'];
    $global_stats['last_run'] = date('d.m.Y H:i:s');
    update_option('cnap_stats', $global_stats);

    return $stats;
}

function cnap_deep_parse_v35($url, $stopwords) {
    $result = array('content' => '', 'featured_image' => '', 'photos_count' => 0);

    $response = wp_remote_get($url, array('timeout' => 30, 'user-agent' => 'Mozilla/5.0'));
    if (is_wp_error($response)) return $result;

    $html = wp_remote_retrieve_body($response);
    if (empty($html)) return $result;

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $content_selectors = array(
        '//*[contains(@class, "entry-content")]',
        '//*[contains(@class, "post-content")]',
        '//*[contains(@class, "article-content")]'
    );

    $content_node = null;
    foreach ($content_selectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            $content_node = $nodes->item(0);
            break;
        }
    }

    if (!$content_node) return $result;

    $remove_selectors = array(
        './/script', './/style', './/*[contains(@class, "share")]',
        './/*[contains(@class, "social")]', './/*[contains(@class, "subscribe")]',
        './/*[contains(@class, "related")]', './/*[contains(@class, "sidebar")]',
        './/*[contains(@class, "author")]', './/*[contains(@class, "comments")]',
        './/ul', './/ol'
    );

    foreach ($remove_selectors as $selector) {
        $nodes = $xpath->query($selector, $content_node);
        foreach ($nodes as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    $images = $xpath->query('.//img', $content_node);
    $all_images = array();
    $seen_urls = array();
    $image_nodes = array();

    foreach ($images as $img) {
        $src = $img->getAttribute('src');
        if (empty($src)) $src = $img->getAttribute('data-src');

        $width = $img->getAttribute('width');
        if ($width && $width < 150) continue;

        if (stripos($src, 'icon') !== false || stripos($src, 'avatar') !== false || stripos($src, 'logo') !== false) {
            continue;
        }

        if (strpos($src, 'http') !== 0) {
            $parsed = parse_url($url);
            $base = $parsed['scheme'] . '://' . $parsed['host'];
            $src = $base . (strpos($src, '/') === 0 ? '' : '/') . $src;
        }

        if (in_array($src, $seen_urls)) {
            continue;
        }
        $seen_urls[] = $src;

        $caption = '';
        $alt = $img->getAttribute('alt');
        $title = $img->getAttribute('title');

        $parent = $img->parentNode;
        if ($parent) {
            $caption_node = $xpath->query('.//*[contains(@class, "caption")]', $parent);
            if ($caption_node->length > 0) {
                $caption = trim($caption_node->item(0)->textContent);
            }
        }

        if (empty($caption) && !empty($alt)) $caption = $alt;
        if (empty($caption) && !empty($title)) $caption = $title;

        $all_images[] = array('src' => $src, 'caption' => $caption);
        $image_nodes[] = $img;
    }

    if (empty($all_images)) {
        return $result;
    }

    foreach ($image_nodes as $img) {
        if ($img->parentNode) {
            $img->parentNode->removeChild($img);
        }
    }

    $result['featured_image'] = $all_images[0]['src'];
    $result['photos_count'] = count($all_images);

    $text_content = $content_node->textContent;

    $cut_patterns = array(
        '/Также\s+читайте\s*:?.*/ius',
        '/Читайте\s+также\s*:?.*/ius',
        '/Read\s+also\s*:?.*/ius',
        '/See\s+also\s*:?.*/ius',
        '/Related\s+articles?\s*:?.*/ius',
        '/Also\s+read\s*:?.*/ius'
    );

    $was_cut = false;
    foreach ($cut_patterns as $pattern) {
        if (preg_match($pattern, $text_content)) {
            $text_content = preg_replace($pattern, '', $text_content);
            $was_cut = true;
            break;
        }
    }

    $content_html = $dom->saveHTML($content_node);

    if ($was_cut) {
        $markers = array('Также читайте', 'Читайте также', 'Read also', 'See also', 'Related article', 'Also read');
        foreach ($markers as $marker) {
            $pos = mb_stripos($content_html, $marker);
            if ($pos !== false) {
                $content_html = mb_substr($content_html, 0, $pos);
                break;
            }
        }
    }

    $default_stopwords = array('Subscribe', 'Follow us', 'Join us', 'Sign up', 'Newsletter', 'Disclaimer');
    $all_stopwords = array_merge($stopwords, $default_stopwords);

    foreach ($all_stopwords as $stopword) {
        if (empty($stopword)) continue;
        $content_html = str_ireplace($stopword, '', $content_html);
    }

    $content_html = preg_replace('/#[a-zA-Z0-9_]+/u', '', $content_html);
    $content_html = preg_replace('/<p>\s*<\/p>/', '', $content_html);
    $content_html = preg_replace('/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/i', '', $content_html);
    $content_html = preg_replace('/<br\s*\/?>{2,}/i', '<br>', $content_html);
    $content_html = preg_replace('/\n{3,}/', "\n\n", $content_html);
    $content_html = preg_replace('/\s*(<img[^>]*>)\s*/i', '$1', $content_html);
    $content_html = preg_replace('/<\/p>\s*<img/i', '</p><img', $content_html);
    $content_html = preg_replace('/<img([^>]*)>\s*<p>/i', '<img$1><p>', $content_html);
    $content_html = preg_replace('/[ \t]+/', ' ', $content_html);
    $content_html = preg_replace('/<p>\s+/', '<p>', $content_html);
    $content_html = preg_replace('/\s+<\/p>/', '</p>', $content_html);
    $content_html = strip_tags($content_html, '<p><br><strong><b><em><i><h2><h3><h4><blockquote><img><figure><figcaption>');

    if (count($all_images) > 0) {
        $paragraphs = preg_split('/(<\/p>)/', $content_html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $para_count = count($paragraphs);

        $max_photos = min(count($all_images), 5);
        $inserted = 0;

        for ($i = 0; $i < $max_photos; $i++) {
            $img_data = $all_images[$i];

            if ($i == 0) {
                $insert_pos = 2;
            } else {
                $interval = intval($para_count / ($max_photos + 1));
                $insert_pos = min($interval * ($i + 1), $para_count - 1);
            }

            $img_html = '<figure class="cnap-figure"><img src="' . esc_url($img_data['src']) . '" alt="Crypto News">';

            if (!empty($img_data['caption'])) {
                $img_html .= '<figcaption><strong>' . esc_html($img_data['caption']) . '</strong></figcaption>';
            }
            $img_html .= '</figure>';

            if (isset($paragraphs[$insert_pos])) {
                $paragraphs[$insert_pos] .= $img_html;
                $inserted++;
            }
        }

        $content_html = implode('', $paragraphs);
    }

    $result['content'] = trim($content_html);

    return $result;
}

function cnap_set_thumb($post_id, $url) {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $tmp = download_url($url, 20);
    if (is_wp_error($tmp)) return;

    $file = array('name' => basename($url), 'tmp_name' => $tmp);
    $id = media_handle_sideload($file, $post_id);

    if (!is_wp_error($id)) {
        set_post_thumbnail($post_id, $id);
    }
}
