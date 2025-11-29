<?php
if (!defined('ABSPATH')) exit;

function parse_old_acf_structure(int $post_id): array {
    global $old_db;
    if (!isset($old_db)) {
        return ['error' => 'Стара база $old_db не підключена'];
    }

    $meta_rows = $old_db->get_results($old_db->prepare(
        "SELECT meta_key, meta_value FROM {$old_db->postmeta} WHERE post_id = %d",
        $post_id
    ));

    if (empty($meta_rows)) {
        return ['error' => "Пост $post_id не знайдено"];
    }

    $meta = [];
    foreach ($meta_rows as $row) {
        $meta[$row->meta_key] = maybe_unserialize($row->meta_value);
    }

    $parsed = [];
    $max_section = -1;

    foreach ($meta as $key => $value) {
        if (preg_match('/^custom_sections_(\d+)_(?!blocks)/', $key, $m)) {
            $max_section = max($max_section, (int)$m[1]);
        }
    }

    for ($i = 0; $i <= $max_section; $i++) {
        $prefix = "custom_sections_{$i}_";

        $has_fields = false;
        foreach ($meta as $k => $v) {
            if (strpos($k, $prefix) === 0 && strpos($k, '_blocks') === false) {
                $has_fields = true;
                break;
            }
        }
        if (!$has_fields) continue;

        $section = [
            'section_index'   => $i,
            'section_type'    => 'custom_section',
            'section_fields'  => [],
            'blocks'          => []
        ];

        // Поля секції
        foreach ($meta as $k => $v) {
            if (strpos($k, $prefix) === 0 && strpos($k, '_blocks') === false) {
                $field = str_replace($prefix, '', $k);
                $section['section_fields'][$field] = $v;
            }
        }

        // КЛЮЧОВЕ: читаємо реальний список блоків
        $blocks_order_key = "custom_sections_{$i}_blocks";
        $real_block_types = [];
        if (isset($meta[$blocks_order_key]) && is_array($meta[$blocks_order_key])) {
            $real_block_types = $meta[$blocks_order_key]; // масив типу ["block_showContent", "block_heading"]
        }

        // Тепер проходимо по індексах і беремо тип з цього масиву
        $block_indices = [];
        foreach ($meta as $k => $v) {
            if (preg_match("/^custom_sections_{$i}_blocks_(\d+)_/", $k, $m)) {
                $block_indices[] = (int)$m[1];
            }
        }
        $block_indices = array_unique($block_indices);
        sort($block_indices);

        foreach ($block_indices as $j) {
            $block_prefix = "custom_sections_{$i}_blocks_{$j}_";
            $block_data = ['raw_fields' => []];

            // Отримуємо правильний тип блоку з масиву
            $real_block_type = $real_block_types[$j] ?? 'unknown_block';

            foreach ($meta as $k => $v) {
                if (strpos($k, $block_prefix) === 0) {
                    $field = str_replace($block_prefix, '', $k);
                    $block_data['raw_fields'][$field] = $v;
                }
            }

            $section['blocks'][] = [
                'block_index'     => $j,
                'block_type'      => $real_block_type,           // Тепер правильний: block_showContent, block_heading тощо
                'visual_subtype'  => $block_data['raw_fields']['type'] ?? null, // h2, people, text — для внутрішньої логіки
                'fields'          => $block_data['raw_fields']
            ];
        }

        $parsed[] = $section;
    }

    return $parsed;
}