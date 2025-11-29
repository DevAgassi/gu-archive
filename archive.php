<?php

use plainview\sdk_broadcast\form2\validation\error;

require_once plugin_dir_path(__FILE__) . 'acf.php';

function archive_post_action($post_id)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'ge_archived';

  $all_meta = get_post_meta($post_id);
  $json_data = json_encode($all_meta, JSON_UNESCAPED_UNICODE);
  // --- Заміна на внутрішній рендер ---
  $data = [
    'id' => $post_id,
    'json' => $json_data,
  ];
  error_log("Archiving Post ID: {$post_id} - Meta count: " . count($all_meta));
  $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE id = %d", $post_id));

  if ($exists) {
    $wpdb->update($table_name, $data, ['id' => $post_id], ['%d', '%s'], ['%d']);
  } else {
    $wpdb->insert($table_name, $data, ['%d', '%s']);
  }

  update_option('_pending_archive_update_', $post_id);
}

add_action('wp', function () {
  $post_id = get_option('_pending_archive_update_');

  // Перевіряємо, чи є сигнал про pending archive update
  if ($post_id) {
    $content = updateHtmlContent($post_id);
    $parsed_content = extract_redboxed_content($content);
    // Зберігаємо в БД
    global $wpdb;
    $table_name = $wpdb->prefix . 'ge_archived';
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE id = %d", $post_id));

    $data = [
      'html_content' => $parsed_content,
    ];
    if ($exists) {
      $wpdb->update(
        $table_name,
        $data,
        ['id' => $post_id],
        ['%s'],
        ['%d']
      );
    }

    $all_meta = get_post_meta($post_id);
    $exclude_keys = ['archive', '_edit_lock', '_edit_last'];
    foreach ($all_meta as $key => $values) {
      if (!in_array($key, $exclude_keys)) {
        delete_post_meta($post_id, $key);
      }
    }
    // Чистимо опцію, щоб більше не рендерити
    delete_option('_pending_archive_update_');
    echo $content;
    exit;
  }
});

function extract_redboxed_content($full_html)
{
  libxml_use_internal_errors(true); // Щоб не було warning'ів

  $dom = new DOMDocument();
  // Тримати кодування!
  $dom->loadHTML('<?xml encoding="utf-8" ?>' . $full_html);

  $xpath = new DOMXPath($dom);

  // XPath по елементах (потрібно перебрати всі, які ти позначив)
  // Вибираємо елементи по класу (header/header, section/searchform, header/keyVisual, main, a/backToTop)
  $wanted = [
    '//*[@data-migrate]',
    '//a[@class="backToTop"]'
  ];

  $fragment = '';

  foreach ($wanted as $xp) {
    $nodes = $xpath->query($xp);
    foreach ($nodes as $node) {
      // Забираємо HTML вузла
      $fragment .= $dom->saveHTML($node);
    }
  }

  return $fragment;
}

function updateHtmlContent($post_id)
{
  error_log("gu_archive_generate_html triggered for Post ID: {$post_id}");
  if (!class_exists('Timber')) {
    error_log("<!-- Timber plugin not found -->");
  }

  $context = Timber::get_context();
  $context['post'] = new TimberPost($post_id);
  $context['hasWPML'] = function_exists('icl_get_languages');
  // Безпечний WPML-дані
  $langs = $context['hasWPML'] ? icl_get_languages('skip_missing=0') : [];
  if (!is_array($langs)) $langs = [];
  $context['wpml_languages'] = ['de'];

  // Додавайте інші специфічні дані, якщо потрібно

  // Як шаблон обирайте той, що відповідає вашому фронту
  // 'base.twig' або, наприклад, 'single.twig', 'page.twig'
  $twig_template_path = get_stylesheet_directory() . '/templates/index.twig';
  error_log("Rendering HTML for Post ID: {$post_id} using template: {$twig_template_path}");
  try {
    $content = Timber::compile($twig_template_path, $context);
  } catch (Exception $e) {
    error_log("Timber render error: " . $e->getMessage());
    $content = "<!-- Timber render error: " . $e->getMessage() . " -->";
  }

  return $content;
};
