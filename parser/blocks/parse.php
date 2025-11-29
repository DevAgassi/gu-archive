<?php
if (!defined('ABSPATH')) exit;

// CTA блок
function parse_cta_block($block) {
    return [
        'button_text' => $block['button_text'] ?? '',
        'button_link' => $block['button_link'] ?? '',
        'style'       => $block['style'] ?? 'default', // якщо є
        // додавай всі поля, які реально використовуються
    ];
}

// Текстовий блок
function parse_text_block($block) {
    return [
        'content'    => $block['content'] ?? '',
        'text_color' => $block['text_color'] ?? '',
        'alignment'  => $block['alignment'] ?? 'left',
    ];
}