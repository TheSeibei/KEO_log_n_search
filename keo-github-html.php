<?php
/*
Plugin Name: KEO GitHub HTML Embed
Description: Loads HTML files from GitHub-Repo per Shortcode.
Version: 1.0
Author: KEO
*/

if (!defined('ABSPATH')) exit;

function keo_github_html_shortcode($atts) {
    $atts = shortcode_atts([
        'file' => '',
    ], $atts, 'keo_github_html');

    if (empty($atts['file'])) {
        return '<!-- keo_github_html: no file given -->';
    }

    $base_url = 'https://raw.githubusercontent.com/TheSeibei/KEO_Website/main/';

    // nur bestimmte Dateien erlauben
    $allowed_files = [
        'fachbetriebe.html',
        'foerdernde_fachbetriebe.html',
        'guetesiegel.html',
    ];

    $file = basename($atts['file']);

    if (!in_array($file, $allowed_files, true)) {
        return '<!-- keo_github_html: file not allowed -->';
    }

    $cache_key = 'keo_github_html_' . md5($file);
    $cached = get_transient($cache_key);

    if ($cached !== false) {
        return $cached;
    }

    $url = $base_url . rawurlencode($file);

    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'Accept' => 'text/html',
        ],
    ]);

    if (is_wp_error($response)) {
        return '<!-- keo_github_html: request failed -->';
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code !== 200 || empty($body)) {
        return '<!-- keo_github_html: invalid response -->';
    }

    // 10 Minuten cachen
    set_transient($cache_key, $body, 10 * MINUTE_IN_SECONDS);

    return $body;
}

add_shortcode('keo_github_html', 'keo_github_html_shortcode');