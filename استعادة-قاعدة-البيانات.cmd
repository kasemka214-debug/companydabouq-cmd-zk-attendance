@echo off
rem استعادةُ نسخةٍ احتياطيةٍ في قاعدة البيانات — انقر مرّتين.
rem
rem **ويمحو ما في جداول النسخة ويضع مكانَه ما فيها.** ولذلك يسأل عن اسم
rem القاعدة بيدك قبل أن يفعل، ويُثبت التمامَ بعدَه بعدِّ الصفوف.
chcp 65001 >nul
cd /d "%~dp0"
title استعادة قاعدة بيانات حضور البصمة

where node >nul 2>nul
if errorlevel 1 (
    echo Node.js غير مثبّت على هذا الجهاز. ثبّته من https://nodejs.org ثم أعد التشغيل.
    pause
    exit /b 1
)

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

if not exist ".env.production" (
    echo لا بياناتِ قاعدةٍ بعد. شغّل «سحب-قاعدة-البيانات.cmd» مرّةً ليسألك عنها.
    pause
    exit /b 1
)

node scripts\db-tool\restore.js %*
set RC=%errorlevel%
echo.
if %RC% neq 0 echo انتهى بخطأ ^(الرمز %RC%^) — اقرأ ما فوق.
pause
exit /b %RC%
