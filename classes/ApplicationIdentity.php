<?php
declare(strict_types=1);

/** Identidade exibida e versao funcional da plataforma. */
final class ApplicationIdentity
{
    public const NAME = 'GreenSolutions';
    public const VERSION = '4.0.1';
    public const LABEL = self::NAME . ' ' . self::VERSION;

    public static function title(string $section = ''): string
    {
        return $section === '' ? self::LABEL : $section . ' | ' . self::LABEL;
    }
}
