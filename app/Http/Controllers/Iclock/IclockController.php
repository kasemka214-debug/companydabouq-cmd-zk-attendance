<?php

namespace App\Http\Controllers\Iclock;

use Illuminate\Http\Response;

/**
 * أصلٌ لمتحكّمات الأجهزة — وكلُّ ما فيه شكلُ الردّ.
 *
 * جهازُ ZKTeco ليس متصفّحاً: لا يقرأ JSON، ولا يفهم إعادةَ التوجيه، ويعتبر
 * كلَّ ما ليس 200 خطأً يُعيد المحاولةَ بعده. فالردُّ نصٌّ صِرفٌ دائماً،
 * بحالة 200 دائماً، بلا BOM ولا سطرٍ زائدٍ في أوّله.
 */
abstract class IclockController
{
    protected function plain(string $text): Response
    {
        return new Response($text, 200, [
            'Content-Type'  => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** أسطرٌ تُجمع بفاصلٍ يفهمه الجهاز — CRLF لا LF. */
    protected function lines(array $lines): Response
    {
        return $this->plain(implode("\r\n", $lines)."\r\n");
    }
}
