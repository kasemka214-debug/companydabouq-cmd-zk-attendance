<?php

use Illuminate\Support\Facades\Schedule;

/*
 * كنسُ الطلبات الخام: أرشيفُ التشخيص يكبر بسرعةِ نبضات الأجهزة نفسِها،
 * فيُقصَّ إلى مدّةِ الاحتفاظ المكتوبة في الإعدادات.
 */
Schedule::command('zk:prune-raw')->dailyAt('03:30');
