<?php
if (!defined('ABSPATH')) {
    exit;
}

class DAC_Counters
{
    public static function activate(): void
    {
        foreach (self::keys() as $key) {
            if (get_option($key) === false) {
                add_option($key, 0);
            }
        }
    }

    public static function keys(): array
    {
        return array(
            'dac_count_nouvelles',
            'dac_count_poemes',
            'dac_count_photos',
        );
    }

    public static function increment(string $category): void
    {
        $key = self::key_for_category($category);
        if ($key !== '') {
            update_option($key, (int) get_option($key, 0) + 1, true);
        }
    }

    public static function all(): array
    {
        return array(
            'nouvelles' => (int) get_option('dac_count_nouvelles', 0),
            'poemes' => (int) get_option('dac_count_poemes', 0),
            'photos' => (int) get_option('dac_count_photos', 0),
        );
    }

    public static function key_for_category(string $category): string
    {
        if ($category === 'nouvelle') {
            return 'dac_count_nouvelles';
        }
        if ($category === 'poeme') {
            return 'dac_count_poemes';
        }
        if ($category === 'photo') {
            return 'dac_count_photos';
        }
        return '';
    }

    public static function render(): string
    {
        $counts = self::all();
        return sprintf(
            '<div class="dac-counters"><div class="dac-counter"><span class="dac-counter-number">%d</span><span class="dac-counter-label">%s</span></div><div class="dac-counter"><span class="dac-counter-number">%d</span><span class="dac-counter-label">%s</span></div><div class="dac-counter"><span class="dac-counter-number">%d</span><span class="dac-counter-label">%s</span></div></div>',
            $counts['nouvelles'],
            esc_html__('Nouvelles', 'depot-appel-couleur'),
            $counts['poemes'],
            esc_html__('Poèmes', 'depot-appel-couleur'),
            $counts['photos'],
            esc_html__('Photographies', 'depot-appel-couleur')
        );
    }
}
