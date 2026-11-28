<?php
if (!defined('ABSPATH')) {
    exit;
}

class CLP_Window
{
    const TZ = 'Europe/Paris';

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone(self::TZ);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::tz());
    }

    public static function parse_date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (array('Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d', 'd/m/Y') as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, self::tz());
            if (!$date instanceof DateTimeImmutable) {
                continue;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                continue;
            }
            return $date;
        }
        return null;
    }

    public static function status(): array
    {
        $settings = CLP_Settings::get();
        $open = self::parse_date((string) $settings['window_open']);
        $close = self::parse_date((string) $settings['window_close']);
        $now = self::now();
        $base = array('open' => $open, 'close' => $close, 'now' => $now);
        if ($open === null || $close === null) {
            return $base + array('status' => 'unconfigured');
        }
        if ($now < $open) {
            return $base + array('status' => 'before');
        }
        if ($now > $close) {
            return $base + array('status' => 'closed');
        }
        return $base + array('status' => 'open');
    }

    public static function is_open(): bool
    {
        return self::status()['status'] === 'open';
    }

    public static function format_label(?DateTimeImmutable $date): string
    {
        if ($date === null) {
            return '';
        }
        return $date->format('d/m/Y à H:i');
    }
}
