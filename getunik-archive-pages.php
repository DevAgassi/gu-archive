<?php
/*
Plugin Name: Getunik Archive Pages
Description: -
Version: 1.0
*/

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'acf.php';
require_once plugin_dir_path(__FILE__) . 'redirect.php';
require_once plugin_dir_path(__FILE__) . 'restore_post.php';
require_once plugin_dir_path(__FILE__) . 'archive.php';

add_action('plugins_loaded', function () {
    //error_log("plugins_loaded - Getunik Archive Pages");

    // *** НОВИЙ ХУК ДЛЯ ПЕРЕВІРКИ ЗМІНИ СТАТУСУ ***
    add_action('update_post_meta', function ($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key !== 'archive') {
            return;
        }

        $old_archive_status = (int) get_post_meta($post_id, 'archive', true);
        $new_archive_status = (int) $meta_value;

        error_log("update_post_meta triggered - OLD:" . $old_archive_status . " NEW:" . $new_archive_status);

        // Якщо користувач в адмінці перемикає з 0 на 1:
        if ($new_archive_status === 1 && $old_archive_status === 0) {
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
    }, 10, 4);
});
