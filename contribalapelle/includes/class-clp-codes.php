<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_Codes
{
    const OPTION = 'clp_human_codes';
    const WORDS_FILE = 'assets/mots-francais.json';

    public static function words_file(): string
    {
        return CLP_PLUGIN_DIR . self::WORDS_FILE;
    }

    public static function list(): array
    {
        $codes = get_option(self::OPTION, array());
        if (!is_array($codes) || $codes === array()) {
            $codes = self::generate();
        }
        return $codes;
    }

    public static function generate(): array
    {
        $settings = CLP_Settings::get();
        $count = max(10, min(5000, (int) $settings['codes_count']));
        $words = array();
        $path = self::words_file();
        if (is_readable($path)) {
            $json = json_decode((string) file_get_contents($path), true);
            if (is_array($json)) {
                foreach ($json as $word) {
                    if (is_string($word) && preg_match('/^[a-zàâäéèêëîïôöùûüç]{6}$/u', $word)) {
                        $words[] = $word;
                    }
                }
            }
        }
        $words = array_values(array_unique($words));
        if ($words === array()) {
            $words = array('soleil', 'maison', 'encore', 'moment', 'besoin', 'groupe', 'partie', 'chaque', 'compte', 'grande', 'jamais', 'depuis', 'autres', 'contre', 'mettre');
        }
        shuffle($words);
        $codes = array_slice($words, 0, min($count, count($words)));
        update_option(self::OPTION, $codes, false);
        return $codes;
    }

    public static function pick(string $existing = ''): string
    {
        if ($existing !== '') {
            return $existing;
        }
        $codes = self::list();
        if ($codes === array()) {
            return 'code';
        }
        return (string) $codes[array_rand($codes)];
    }
}
