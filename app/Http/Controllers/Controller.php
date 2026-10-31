<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class Controller
{
    /**
     * تصديرُ جدولٍ إلى ملفٍّ يفتحه إكسل.
     *
     * والـ BOM في أوّل الملف ليس زينة: إكسل على ويندوز يقرأ CSV بترميز
     * النظام لا بـ UTF-8، فالعربيةُ بغير BOM تخرج طلاسمَ لا تُقرأ. وهذه
     * علامةُ الثلاثةِ بايتٍ هي كلُّ ما يجعله يستيقظ.
     *
     * والبثُّ سطراً سطراً لا تجميعاً في الذاكرة: كشفُ شهرٍ لخمسةِ مواقع
     * عشراتُ الآلاف من الأسطر، وبناؤه نصّاً واحداً يبلغ حدَّ الذاكرة.
     *
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function csv(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headings);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, [
            'Content-Type'  => 'text/csv; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
