<?php
/*
Plugin Name: Getunik Archive Pages
Description: -
Version: 1.0
*/

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'parser/parser.php';

add_action('plugins_loaded', function () {
    error_log("plugins_loaded - Getunik Archive Pages");

    // *** НОВИЙ ХУК ДЛЯ ПЕРЕВІРКИ ЗМІНИ СТАТУСУ ***
    add_action('update_post_meta', 'handle_archive_field_change', 10, 4);

    // Функція, яка обробляє зміну статусу архіву
    function handle_archive_field_change($meta_id, $post_id, $meta_key, $meta_value)
    {
        if ($meta_key !== 'archive') {
            return;
        }

        $old_archive_status = (int) get_post_meta($post_id, 'archive', true);
        $new_archive_status = (int) $meta_value;

        error_log("update_post_meta triggered - OLD:" . $old_archive_status . " NEW:" . $new_archive_status);

        // Якщо користувач в адмінці перемикає з 0 на 1:
        if ($new_archive_status === 1 && $old_archive_status === 0) {
            error_log("Archiving Scheduled for Post ID: {$post_id}");
            // Ми викликаємо функцію АРХІВАЦІЇ, яка запланує фонове завдання.
            // Вона поки що залишить статус поля ACF = 1 (в процесі)
            archive_post_action($post_id);

            // Якщо користувач в адмінці перемикає з 1 на 0 (відновлення):
        } elseif ($new_archive_status === 0) {
            // Це може бути або відміна архівації, або відновлення з уже готового архіву (статус 2 -> 0)
            error_log("Restoring Post ID: {$post_id} - Status set to 0");
            // Викликаємо функцію ВІДНОВЛЕННЯ З АРХІВУ
            restore_post_action($post_id);
        }
    }


    /**
     * Функція, що виконує дії з АРХІВАЦІЇ запису, зберігаючи мета-поля в JSON.
     */
    function archive_post_action($post_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ge_archived';

        // ... (Отримання JSON даних залишається без змін) ...
        $all_meta = get_post_meta($post_id);
        $json_data = json_encode($all_meta, JSON_UNESCAPED_UNICODE);

        // --- НОВА ЛОГІКА ГЕНЕРАЦІЇ СТАТИЧНОГО HTML ---

        // Генеруємо URL сторінки для запиту
        $post_url = get_permalink($post_id);

        // Використовуємо WordPress HTTP API для отримання вмісту сторінки
        $response = wp_remote_get($post_url);

        if (is_wp_error($response)) {
            error_log("ERROR generating static HTML for Post ID: {$post_id}. Error: " . $response->get_error_message());
            $static_html = "<!-- Помилка генерації статичного контенту -->";
        } else {
            $static_html = wp_remote_retrieve_body($response);
            error_log("Successfully generated static HTML for Post ID: {$post_id}. Length: " . strlen($static_html));
        }

        // 2. Підготовка даних для вставки (додаємо html_content)
        $data = array(
            'id'             => $post_id,
            'json'           => $json_data,
            'html_content'   => $static_html, // Додаємо наш HTML сюди
        );

        // 3. Вставка/Оновлення даних у таблицю (оновлюємо формати даних)

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE id = %d", $post_id));

        if ($exists) {
            $result = $wpdb->update(
                $table_name,
                $data,
                array('id' => $post_id),
                array('%d', '%s', '%s'), // Формати: ID (d), JSON (s), HTML (s)
                array('%d')
            );
            // ... (обробка результатів оновлення)
        } else {
            $result = $wpdb->insert(
                $table_name,
                $data,
                array('%d', '%s', '%s') // Формати: ID (d), JSON (s), HTML (s)
            );
            // ... (обробка результатів вставки)
        }

        if (!wp_next_scheduled('generate_static_html_deferred_action', array($post_id))) {
            wp_schedule_single_event(time() + 10, 'generate_static_html_deferred_action', array($post_id));
            error_log("Scheduled deferred HTML generation for Post ID: {$post_id}");
        }
    }

    // Вам також потрібно визначити функцію restore_post_action, якщо ви її використовуєте у своєму коді.
    function restore_post_action($post_id)
    {
        // Додайте тут свою логіку відновлення з архіву
        error_log("Function restore_post_action called for Post ID: {$post_id}");
    }

    add_action('template_redirect', function () {
        global $wpdb, $post;

        if (!is_singular() || !isset($post->ID)) {
            return;
        }

        $current_post_id = (int) $post->ID;
        $table_name = $wpdb->prefix . 'ge_archived';

        // 1. Читаємо весь рядок, включаючи новий стовпець html_content
        $archived_row = $wpdb->get_row($wpdb->prepare(
            "SELECT html_content FROM $table_name WHERE id = %d",
            $current_post_id
        ));

        if ($archived_row) {
            error_log("Intercepted archived post ID: {$current_post_id}. Displaying static HTML content.");

            ob_clean();

            // 2. Просто виводимо збережений HTML
            echo $archived_row->html_content;

            exit();
        }
    });

    // Додаємо новий хук для нашого відкладеного завдання
    add_action('generate_static_html_deferred_action', 'generate_static_html_for_post');

    function generate_static_html_for_post($post_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ge_archived';
        
        $post_url = get_permalink($post_id);
        $response = wp_remote_get($post_url);
        $static_html = "";

        if (is_wp_error($response)) {
            error_log("DEFERRED TASK ERROR... [Error details]");
            $static_html = "<!-- Помилка генерації статичного контенту -->";
            // Якщо помилка, ми можемо встановити статус архіву назад на 0
            update_field('archive', 0, $post_id); 
            return; // Виходимо, не оновлюємо статус на 2
        } else {
            $static_html = wp_remote_retrieve_body($response);
            error_log("DEFERRED TASK Successfully generated static HTML for Post ID: {$post_id}");
        }

        // Оновлюємо тільки стовпець html_content
        $wpdb->update(
            $table_name,
            array('html_content' => $static_html),
            array('id' => $post_id),
            array('%s'),
            array('%d')
        );
        
        // --- ФІНАЛЬНИЙ КРОК: ВСТАНОВЛЕННЯ СТАТУСУ "ГОТОВО" ---
        // Використовуємо функцію ACF для оновлення поля archive на 2 (готово до перехоплення)
        update_field('archive', 2, $post_id);
        error_log("Post ID: {$post_id} archive status set to 2 (READY).");
    }

});

add_action('acf/init', function () {
    // ... (ACF group definition залишається без змін) ...
    if (! function_exists('acf_add_local_field_group')) {
        return;
    }

    acf_add_local_field_group(array(
        'key' => 'group_692add44581fb',
        'title' => 'Archive',
        'fields' => array(
            array(
                'key' => 'field_692add45d8a3a',
                'label' => 'Archive',
                'name' => 'archive',
                'aria-label' => '',
                'type' => 'true_false',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '',
                    'class' => '',
                    'id' => '',
                ),
                'message' => '',
                'default_value' => 0,
                'allow_in_bindings' => 0,
                'ui' => 0,
                'ui_on_text' => '',
                'ui_off_text' => '',
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'post_type',
                    'operator' => '==',
                    'value' => 'post',
                ),
            ),
            array(
                array(
                    'param' => 'post_type',
                    'operator' => '==',
                    'value' => 'page',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
        'display_title' => '',
    ));
});
