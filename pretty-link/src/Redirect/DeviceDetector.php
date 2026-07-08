<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

use PrettyLinks\DeviceDetector\DeviceDetector as MatomoDD;

class DeviceDetector
{
    /**
     * Parse a User-Agent string into device/browser/OS fields.
     *
     * @param  string $ua The User-Agent string to parse.
     * @return array{type:string,browser:string,btype:string,bversion:string,os:string,is_bot:bool}
     */
    public static function detect(string $ua): array
    {
        $empty = [
            'type'     => '',
            'browser'  => '',
            'btype'    => '',
            'bversion' => '',
            'os'       => '',
            'is_bot'   => false,
        ];

        if ($ua === '') {
            return $empty;
        }

        $dd = new MatomoDD($ua);
        $dd->parse();

        if ($dd->isBot()) {
            return array_merge($empty, ['is_bot' => true]);
        }

        if ($dd->isTablet()) {
            $type = 'tablet';
        } elseif ($dd->isMobile()) {
            $type = 'mobile';
        } else {
            $type = 'desktop';
        }

        $browser  = (string) ($dd->getClient('name') ?? '');
        $bversion = (string) ($dd->getClient('version') ?? '');
        $os       = self::normalizeOs((string) ($dd->getOs('name') ?? ''));

        return [
            'type'     => $type,
            'browser'  => $browser,
            'btype'    => $browser,
            'bversion' => $bversion,
            'os'       => $os,
            'is_bot'   => false,
        ];
    }

    /**
     * Normalize Matomo OS names to Pretty Links' canonical labels.
     *
     * @param  string $name Raw OS name from the device detector.
     * @return string Canonical OS label.
     */
    private static function normalizeOs(string $name): string
    {
        if ($name === 'Mac') {
            return 'macOS';
        }
        if ($name === 'GNU/Linux') {
            return 'Linux';
        }
        return $name;
    }
}
