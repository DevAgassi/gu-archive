<?php
require_once plugin_dir_path(__FILE__) . 'acf.php';

function restore_post_action($post_id)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'ge_archived';

  // 1. Беремо JSON з таблиці
  $json_data = $wpdb->get_var($wpdb->prepare("SELECT json FROM $table_name WHERE id = %d", $post_id));

  if ($json_data) {
    $all_meta = json_decode($json_data, true);

    if (is_array($all_meta)) {
      // 2. Відновлюємо всі мета-поля
      foreach ($all_meta as $key => $values) {
        // $values може бути масивом, зберігаємо кожен елемент
        if (is_array($values)) {
          delete_post_meta($post_id, $key); // очищаємо старі
          foreach ($values as $value) {
            add_post_meta($post_id, $key, maybe_unserialize($value));
          }
        } else {
          update_post_meta($post_id, $key, maybe_unserialize($values));
        }
      }
    }
   // update_post_meta($post_id, 'archive', 0);
    // 3. Видаляємо рядок з таблиці архіву
    $wpdb->delete($table_name, ['id' => $post_id], ['%d']);
    wp_redirect(admin_url("post.php?post={$post_id}&action=edit")); // На сторінку редагування
    exit;
  }

  error_log("Post ID {$post_id} restored from archive");
}