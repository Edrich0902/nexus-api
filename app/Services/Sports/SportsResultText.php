<?php

namespace App\Services\Sports;

/**
 * TheSportsDB often embeds HTML (especially rugby period lines) in strResult.
 */
class SportsResultText
{
    public static function clean(?string $text, ?int $maxLength = null): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $plain = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/<br\s*\/?>/i', "\n", $plain) ?? $plain;
        $plain = strip_tags($plain);
        $plain = str_replace("\xc2\xa0", ' ', $plain);
        $plain = preg_replace("/[ \t\f\v]+/", ' ', $plain) ?? $plain;
        $plain = preg_replace("/\n{2,}/", "\n", $plain) ?? $plain;
        $plain = trim($plain);

        if ($plain === '') {
            return null;
        }

        // Hollow period scaffolding with no scores / useful content.
        if (
            ! preg_match('/\d/', $plain)
            && preg_match('/first half|second half|overtime/i', $plain)
        ) {
            return null;
        }

        $max = $maxLength ?? max(256, (int) config('services.sportsdb.sync.result_text_max', 2000));
        if (strlen($plain) > $max) {
            return substr($plain, 0, $max)."\n…";
        }

        return $plain;
    }
}
