<?php

declare(strict_types=1);

/**
 * Line at the bottom of every configuration form that shows the installed version,
 * read from the installed library.json – never maintained by hand.
 */
final class FaikoutVersion
{
    private const NAME = 'Faikout';

    public static function Text(): string
    {
        $file = __DIR__ . '/../library.json';
        $info = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($info)) {
            return self::NAME;
        }
        $text = self::NAME . ' ' . ($info['version'] ?? '?');
        if (isset($info['build'])) {
            $text .= ' (Build ' . $info['build'] . ')';
        }
        if (!empty($info['date'])) {
            $text .= ' – ' . date('d.m.Y', (int) $info['date']);
        }
        return $text;
    }

    public static function Append(array $form): array
    {
        $form['actions'] = array_values($form['actions'] ?? []);
        $form['actions'][] = ['type' => 'Label', 'caption' => self::Text(), 'italic' => true, 'color' => 0x808080];
        return $form;
    }
}
