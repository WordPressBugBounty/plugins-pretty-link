<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

use PrettyLinks\DeviceDetector\DeviceDetector as MatomoDD;

class DeviceDetector
{
    /**
     * Per-request memo of parsed user agents, keyed by the UA string.
     *
     * Matomo loads its regex rule files on first use — measured at ~42ms, with
     * ~4ms per parse after that — and a single click can reach this from three
     * places: targeting, sequential rotation's click-filter check, and the click
     * write itself. Two of those run before the redirect is sent, so the visitor
     * waits for them.
     *
     * The parse is deterministic for a given UA, so the memo can never go stale
     * within a request.
     *
     * @var array<string, array{type:string,browser:string,btype:string,bversion:string,os:string,is_bot:bool}>
     */
    private static array $memo = [];

    /**
     * Parse a User-Agent string into device/browser/OS fields.
     *
     * @param  string $ua The User-Agent string to parse.
     * @return array{type:string,browser:string,btype:string,bversion:string,os:string,is_bot:bool}
     */
    public static function detect(string $ua): array
    {
        if (!isset(self::$memo[$ua])) {
            self::$memo[$ua] = self::parse($ua);
        }

        return self::$memo[$ua];
    }

    /**
     * The actual parse, wrapped by `detect()`'s memo.
     *
     * @param  string $ua The User-Agent string to parse.
     * @return array{type:string,browser:string,btype:string,bversion:string,os:string,is_bot:bool}
     */
    private static function parse(string $ua): array
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
