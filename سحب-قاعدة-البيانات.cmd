@echo off
rem سحبُ قاعدة بيانات حضور البصمة كاملةً — انقر مرّتين ويكفي.
rem يحتاج Node.js وحده. وأوّلَ مرّةٍ يسأل عن بيانات الاتصال ويكتبها بنفسه.
chcp 65001 >nul
cd /d "%~dp0"
title سحب قاعدة بيانات حضور البصمة

where node >nul 2>nul
if errorlevel 1 (
    echo Node.js غير مثبّت على هذا الجهاز. ثبّته من https://nodejs.org ثم أعد التشغيل.
    pause
    exit /b 1
)

rem المكتبةُ أوّلاً: التهيئةُ نفسُها تُجرّب الاتصالَ فتحتاجها.
if not exist "scripts\db-tool\node_modules\mysql2" (
    echo تثبيتُ مكتبة الاتصال — مرّةً واحدة ...
    pushd scripts\db-tool
    call npm ci --no-audit --no-fund --loglevel=error
    if errorlevel 1 (
        popd
        echo تعذّر التثبيت — تحقّق من الإنترنت ثم أعد التشغيل.
        pause
        exit /b 1
    )
    popd
)

rem لا ملفَّ بيانات؟ لا رسالةَ خطأٍ تُحيل إلى عملٍ يدويّ — بل تهيئةٌ تسأل
rem وتُجرّب الاتصالَ ثم تكتب. والقيمُ تبقى بين الشاشة وصاحبها.
if not exist ".env.production" (
    node scripts\db-tool\setup-env.js
    if errorlevel 1 (
        echo.
        echo لم تكتمل التهيئة. أعد التشغيل حين تجهز بيانات الاتصال.
        pause
        exit /b 1
    )
    echo.
)

node scripts\db-tool\pull.js %*
set RC=%errorlevel%
echo.
if %RC% neq 0 echo انتهى بخطأ ^(الرمز %RC%^) — اقرأ ما فوق، وإن كان انقطاعاً فأعد التشغيل وسيُستأنف.
pause
exit /b %RC%
