#!/usr/bin/env node
'use strict';

/*
    تهيئةُ `.env.production` — مرّةً واحدة.

    ══ لماذا هذا الملفّ ══

    كان على صاحب البرنامج أن ينسخ قالباً ويفتحه بمحرّرٍ ويملأ خمسةَ حقولٍ
    بأسمائها الإنجليزية ويحفظه بالترميز الصحيح. وكلُّ خطوةٍ منها موضعُ
    خطأ: مسافةٌ زائدة، أو اقتباسٌ حول القيمة، أو حفظٌ بترميزٍ يُفسد شيئاً،
    أو حقلٌ يُترك فارغاً فتخرج رسالةٌ لا تقول أيُّه.

    فصار يُسأل سؤالاً سؤالاً، ويُكتب الملفُّ على الوجه الصحيح، **ثم
    يُجرَّب الاتصالُ فوراً** — فلا يُقال «تمّ» حتى تُقرأ جداولُ القاعدة
    فعلاً.

    ══ ولماذا لا يُكتب هذا في محادثة ══

    بياناتُ قاعدة الإنتاج لا تُكتب في محادثةٍ ولا رسالةٍ ولا تذكرة —
    قاعدةٌ في `CLAUDE.md`. فهي تُكتب هنا، بين يدي صاحبها وشاشته، وتُحفظ
    في ملفٍّ مستثنىً من git. وكلمةُ المرور لا تظهر على الشاشة وهي تُكتب.

    ══ الاستعمال ══

        node scripts/db-tool/setup-env.js
        node scripts/db-tool/setup-env.js --env .env.production
        node scripts/db-tool/setup-env.js --check      # يُجرّب الموجودَ ولا يسأل
*/

const fs       = require('fs');
const path     = require('path');
const readline = require('readline');

const ROOT = path.resolve(__dirname, '..', '..');

/* ───────────────────────── الوسائط ───────────────────────── */

const args = { env: '.env.production', check: false };

for (let i = 2; i < process.argv.length; i++) {
    const x = process.argv[i];
    if (x === '--env') args.env = process.argv[++i];
    else if (x === '--check') args.check = true;
    else if (x === '--help' || x === '-h') {
        console.log('تهيئةُ بيانات الاتصال بقاعدة الإنتاج\n');
        console.log('  --env FILE   الملفُّ المكتوب (الافتراضي .env.production)');
        console.log('  --check      جرِّب الموجودَ ولا تسأل');
        process.exit(0);
    } else { console.error('وسيطٌ غير معروف: ' + x); process.exit(2); }
}

const envPath = path.isAbsolute(args.env) ? args.env : path.join(ROOT, args.env);

/* ───────────────────────── أدواتٌ صغيرة ───────────────────────── */

const say = s => console.log(s);

/*
 * قارئُ أسطرٍ بطابور.
 *
 * و`rl.question` وحدَه لا يكفي: حين لا تكون الطرفيةُ حقيقيةً — كأن
 * يُشغَّل البرنامجُ من سكربتٍ أو تُلصق الإجاباتُ دفعةً واحدة — يُطلق
 * `readline` حدثَ السطر لكل ما وصل **فوراً**، فما وصل قبل أن يُطرح
 * السؤالُ يسقط ولا يُردّ. فيَعلَق السؤالُ التالي على انتظارٍ لا ينتهي،
 * وينتهي البرنامجُ بصمتٍ **ورمزِ نجاح** — وهو لم يكتب شيئاً ولم يُجرّب
 * اتصالاً. وقد وقع عندي في أوّل تجربة.
 *
 * فالأسطرُ تُجمع في طابورٍ أوّلاً، والسؤالُ يأخذ منه إن كان فيه شيء
 * وإلّا انتظر. فلا يضيع سطر.
 */
const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout,
    terminal: Boolean(process.stdin.isTTY),
});

const queue   = [];
const waiting = [];
let   closed  = false;

rl.on('line', line => {
    const w = waiting.shift();

    if (w) w(line.trim());
    else queue.push(line.trim());
});

rl.on('close', () => {
    closed = true;

    /* وما بقي منتظِراً يُردّ فارغاً — فلا يعلق البرنامجُ على ما لن يأتي */
    while (waiting.length) waiting.shift()('');
});

function readLine() {
    if (queue.length) return Promise.resolve(queue.shift());
    if (closed) return Promise.resolve('');

    return new Promise(r => waiting.push(r));
}

async function ask(q) {
    process.stdout.write(q);

    return readLine();
}

/**
 * سؤالٌ لا تظهر إجابتُه على الشاشة.
 *
 * ويعمل في طرفيةٍ حقيقية بإطفاء الصدى. وإن لم تكن الطرفيةُ حقيقية —
 * كأن يُشغَّل من سكربتٍ — قُرئ السطرُ كغيره، لأن الإخفاءَ حينئذٍ لا معنى
 * له ولا يصحّ أن يُعلَّق البرنامجُ انتظاراً لما لا يأتي.
 */
async function askHidden(q) {
    if (!process.stdin.isTTY) return ask(q);

    /* الصدى يُستبدل بنجومٍ بعدد الأحرف — فلا تُقرأ كلمةُ المرور من الشاشة */
    const onData = ch => {
        const t = ch.toString('utf8');

        if (t === '\n' || t === '\r' || t === '\u0004') return;

        readline.moveCursor(process.stdout, -t.length, 0);
        readline.clearLine(process.stdout, 1);
        process.stdout.write('*'.repeat(t.length));
    };

    process.stdin.on('data', onData);

    const answer = await ask(q);

    process.stdin.removeListener('data', onData);
    process.stdout.write('\n');

    return answer;
}

/**
 * قراءةُ رابط اتصالٍ كامل — لصقةٌ واحدةٌ بدل خمس.
 *
 *     mysql://user:pass@host:3306/dbname
 *
 * ولوحةُ Laravel Cloud تعرضه هكذا، فلصقُه أقلُّ خطأً من نقل خمسة حقولٍ
 * بالعين. وكلمةُ المرور تُفكَّك من ترميز العنوان (`%40` تصير `@`).
 */
function parseUrl(raw) {
    let u;

    try {
        u = new URL(raw);
    } catch {
        return null;
    }

    if (!/^mysql/.test(u.protocol)) return null;

    return {
        DB_HOST: u.hostname,
        DB_PORT: u.port || '3306',
        DB_DATABASE: decodeURIComponent(u.pathname.replace(/^\//, '')),
        DB_USERNAME: decodeURIComponent(u.username),
        DB_PASSWORD: decodeURIComponent(u.password),
    };
}

/* ───────────────────────── التجربة ───────────────────────── */

async function probe(cfg) {
    let mysqlp;

    try {
        mysqlp = require('mysql2/promise');
    } catch {
        return { ok: false, why: 'مكتبةُ الاتصال غير مثبّتة — شغّل «سحب-قاعدة-البيانات.cmd» وهو يثبّتها.' };
    }

    let db;

    try {
        db = await mysqlp.createConnection({
            host: cfg.DB_HOST,
            port: Number(cfg.DB_PORT || 3306),
            user: cfg.DB_USERNAME,
            password: cfg.DB_PASSWORD,
            database: cfg.DB_DATABASE,
            connectTimeout: 20000,
            /* الرابطُ بعيدٌ بطيء، والضغطُ يوفّر منه كثيراً */
            compress: true,
        });

        const [rows] = await db.query(
            'SELECT TABLE_NAME AS n, TABLE_ROWS AS r FROM information_schema.TABLES'
            + ' WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
            [cfg.DB_DATABASE, 'BASE TABLE'],
        );

        await db.end();

        return { ok: true, tables: rows };
    } catch (e) {
        try { if (db) await db.end(); } catch { /* لا يعني */ }

        return { ok: false, why: e.message };
    }
}

/* ───────────────────────── الكتابة ───────────────────────── */

function write(cfg) {
    const body = [
        '# بياناتُ الاتصال بقاعدة الإنتاج — لسحب النسخ الاحتياطية وحدَه.',
        '# كُتب بـ scripts/db-tool/setup-env.js في ' + new Date().toISOString().slice(0, 16).replace('T', ' '),
        '#',
        '# **هذا الملفُّ مستثنىً من git ولا يدخل أيَّ ملفٍّ مضغوط،**',
        '# ولا تُكتب بياناتُه في محادثةٍ ولا رسالةٍ ولا تذكرة.',
        '',
        'APP_KEY=' + (cfg.APP_KEY || ''),
        '',
        'DB_HOST=' + cfg.DB_HOST,
        'DB_PORT=' + (cfg.DB_PORT || '3306'),
        'DB_DATABASE=' + cfg.DB_DATABASE,
        'DB_USERNAME=' + cfg.DB_USERNAME,
        'DB_PASSWORD=' + cfg.DB_PASSWORD,
        '',
    ].join('\n');

    /*
     * بلا علامة ترتيبِ بايتات (BOM): الملفُّ يُقرأ بمفكّكٍ بسيطٍ يقسم
     * السطرَ على أوّل `=`، وعلامةُ الترتيب تلتصق باسم أوّل مفتاحٍ فيصير
     * `﻿APP_KEY` فلا يُطابق شيئاً — وتبقى الرسالةُ «ناقصٌ APP_KEY»
     * والقيمةُ مكتوبةٌ أمام العين.
     */
    fs.writeFileSync(envPath, body, { encoding: 'utf8' });
}

function readExisting() {
    if (!fs.existsSync(envPath)) return null;

    const cfg = {};

    for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
        const m = line.match(/^\s*([A-Z_]+)\s*=\s*(.*)$/);
        if (m) cfg[m[1]] = m[2].replace(/^["']|["']$/g, '').trim();
    }

    return cfg;
}

/* ───────────────────────── المسار ───────────────────────── */

(async () => {
    const rel = path.relative(ROOT, envPath) || envPath;

    if (args.check) {
        const cfg = readExisting();

        if (!cfg) { say('لا يوجد ' + rel + ' — شغّل الأداةَ بلا ‎--check‎ لتهيئته.'); process.exit(1); }

        say('أجرّب الاتصالَ بما في ' + rel + ' ...');

        const r = await probe(cfg);

        rl.close();

        if (!r.ok) { say('\n✗ فشل: ' + r.why); process.exit(1); }

        say('\n✓ الاتصالُ سليم — ' + r.tables.length + ' جدولاً في القاعدة.');
        process.exit(0);
    }

    say('');
    say('  تهيئةُ الاتصال بقاعدة إنتاج «حضور البصمة»');
    say('  ──────────────────────────────────────────');
    say('');
    say('  القيمُ من: لوحة Laravel Cloud ← التطبيق ← Database.');
    say('  ولا تُكتب هذه القيمُ في أيّ محادثةٍ أو رسالة.');
    say('');

    if (fs.existsSync(envPath)) {
        const again = await ask('  يوجد ' + rel + ' بالفعل. أُعيد كتابتَه؟ (y/n) ');

        if (!/^y/i.test(again)) { say('  تُرك كما هو.'); rl.close(); process.exit(0); }

        say('');
    }

    /*
     * الرابطُ الكامل أوّلاً: لصقةٌ واحدةٌ أقلُّ خطأً من نقل خمسة حقولٍ
     * بالعين. ومن لم يكن عنده رابطٌ سُئل حقلاً حقلاً.
     */
    const url = await ask('  ألصق رابطَ الاتصال إن كان عندك (mysql://…) — أو اتركه فارغاً للسؤال حقلاً حقلاً:\n  > ');

    let cfg = url ? parseUrl(url) : null;

    if (url && !cfg) {
        say('\n  لم أفهم هذا الرابط — أسألك حقلاً حقلاً.\n');
    }

    if (!cfg) {
        cfg = {};
        cfg.DB_HOST     = await ask('  DB_HOST      (العنوان)        : ');
        cfg.DB_PORT     = await ask('  DB_PORT      (المنفذ، 3306)   : ') || '3306';
        cfg.DB_DATABASE = await ask('  DB_DATABASE  (اسم القاعدة)    : ');
        cfg.DB_USERNAME = await ask('  DB_USERNAME  (المستخدم)       : ');
        cfg.DB_PASSWORD = await askHidden('  DB_PASSWORD  (كلمة المرور)    : ');
    }

    say('');
    say('  و APP_KEY من: Settings ← Environment variables (اختياريٌّ لكنه يُحفظ مع كل نسخة).');
    cfg.APP_KEY = await ask('  APP_KEY : ');

    const missing = ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']
        .filter(k => !cfg[k]);

    if (missing.length) {
        say('\n✗ ناقصٌ: ' + missing.join('، ') + ' — لم يُكتب شيء.');
        rl.close();
        process.exit(2);
    }

    /*
     * **يُجرَّب قبل أن يُكتب.**
     *
     * فملفٌّ يُكتب ثم يفشل يترك صاحبَه لا يدري أالخطأُ في القيم أم في
     * الملفّ. والتجربةُ أوّلاً تقول أيَّهما.
     */
    say('\n  أجرّب الاتصالَ قبل الحفظ ...');

    const r = await probe(cfg);

    if (!r.ok) {
        say('\n✗ تعذّر الاتصال: ' + r.why);
        say('  لم يُكتب شيء. راجع القيمَ في لوحة Laravel Cloud وأعد المحاولة.');
        say('');
        say('  وإن كان العنوانُ صحيحاً والخطأُ «Access denied» فالمستخدمُ أو كلمةُ');
        say('  المرور، وإن كان «timed out» فقد تكون القاعدةُ نائمةً (Scale to zero)');
        say('  أو العنوانُ محجوبٌ عن جهازك.');
        rl.close();
        process.exit(1);
    }

    write(cfg);
    rl.close();

    say('\n✓ الاتصالُ سليم، وكُتب ' + rel);
    say('  وفي القاعدة ' + r.tables.length + ' جدولاً:');

    for (const t of r.tables.slice(0, 20)) {
        say('    · ' + String(t.n).padEnd(28) + (t.r === null ? '' : '~' + Number(t.r).toLocaleString('en-US') + ' صفّ'));
    }

    if (r.tables.length > 20) say('    … و' + (r.tables.length - 20) + ' غيرها');

    say('');
    say('  الآن: انقر مرّتين على «سحب-قاعدة-البيانات.cmd» لأخذ نسخة.');
    say('');
})().catch(e => {
    console.error('\nخطأٌ غيرُ متوقَّع: ' + (e && e.message ? e.message : e));
    process.exit(1);
});
