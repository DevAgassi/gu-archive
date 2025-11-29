<?php

if (!defined('ABSPATH')) exit;

add_action('template_redirect', function () {
  global $wpdb, $post;

  if (!is_singular() || !isset($post->ID)) {
    return;
  }
  $start = microtime(true);
  $current_post_id = (int) $post->ID;

  $post_id = get_option('_pending_archive_update_');
  error_log("template_redirect - Checking for archived post. Current Post ID: {$current_post_id}, Pending Archive Update Post ID: {$post_id}");
  // Перевіряємо, чи є сигнал про pending archive update
  if ($post_id == $current_post_id) {
    return;
  }

  $table_name = $wpdb->prefix . 'ge_archived';
  // 1. Читаємо весь рядок, включаючи новий стовпець html_content
  $archived_row = $wpdb->get_row($wpdb->prepare(
    "SELECT html_content FROM $table_name WHERE id = %d",
    $current_post_id
  ));

  $twig_template_path = get_stylesheet_directory() . '/templates/without_content.twig';

  if ($archived_row) {
    $lang = function_exists('apply_filters') ? apply_filters('wpml_current_language', NULL) : '';
    var_dump($lang);
    $cache_key = 'timber_context_' . $lang . '_' . md5($_SERVER['REQUEST_URI']);
    $context = TimberHelper::transient($cache_key, function () {
      return Timber::get_context();
    }, 600);

   // $context = Timber::get_context();
    $context['archived_html'] = $archived_row->html_content;
    $context['hasWPML'] = function_exists('icl_get_languages');
    Timber::render($twig_template_path, $context, 600);

    $time = microtime(true) - $start;
    $ms = round($time * 1000, 2);
    error_log("PAGE TIMER: {$ms} ms");
    exit;
  }
});