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

const args = { env: '.env.production', check: false, from: null };

for (let i = 2; i < process.argv.length; i++) {
    const x = process.argv[i];
    if (x === '--env') args.env = process.argv[++i];
    else if (x === '--from') args.from = process.argv[++i];
    else if (x === '--check') args.check = true;
    else if (x === '--help' || x === '-h') {
        console.log('تهيئةُ بيانات الاتصال بقاعدة الإنتاج\n');
        console.log('  --env FILE   الملفُّ المكتوب (الافتراضي .env.production)');
        console.log('  --from FILE  خذِ العنوانَ والمستخدمَ وكلمةَ المرور من ملفِّ');
        console.log('               مشروعٍ آخرَ على هذا الجهاز، ولا تسأل إلا عن');
        console.log('               اسم القاعدة — إن كان التطبيقان على خادمٍ واحد');
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

        /*
         * **ورمزُ الخطأ يُقال ولو خلت الرسالة.**
         *
         * فـ`ETIMEDOUT` تأتي من mysql2 بلا نصٍّ أحياناً، فتخرج الرسالةُ
         * «فشل:» ولا شيءَ بعدها — وصاحبُها لا يدري أمن كلمة المرور أم من
         * الشبكة. وقد وقع.
         */
        const why = (e && e.message) ? e.message : (e && e.code ? e.code : 'سببٌ غيرُ معروف');

        return { ok: false, why, code: e && e.code ? e.code : null };
    }
}

/**
 * أعنوانُ الخادم خاصٌّ لا يُرى من خارج شبكته؟
 *
 * وهذا أكثرُ ما يُحيّر: البياناتُ صحيحةٌ تماماً، والقاعدةُ حيّةٌ يستعملها
 * التطبيقُ المنشور، ومع ذلك ينتهي الوقتُ بلا جواب. والعلّةُ أن اسمَ
 * الخادم يُترجَم إلى عنوانٍ في شبكةٍ خاصّة (`10.x` أو `172.16–31.x` أو
 * `192.168.x`) — فهو يُرى من داخل المنصّة وحدَها.
 *
 * فيُقال ذلك صراحةً ويُقال علاجُه، بدل أن يُعاد النظرُ في كلمة مرورٍ
 * ليس فيها عيب.
 */
async function privateHost(host) {
    try {
        const dns = require('dns').promises;
        const addrs = await dns.lookup(host, { all: true });

        /*
         * **ويُفحص كلُّ فصيلةٍ على حدة.**
         *
         * فاسمُ خادم Laravel Cloud يُترجَم إلى عنوانَي IPv4 خاصّين
         * (`10.x`) **وعنوانَي IPv6 عامّين**. فشرطُ «كلُّ العناوين خاصّة»
         * لا يتحقّق أبداً، فيسكت الفحصُ عن العلّة وهي قائمة — وقد وقع.
         *
         * والذي يهمّ: أكلُّ عناوين IPv4 خاصّةٌ؟ فأكثرُ الأجهزة لا طريقَ
         * لها إلى IPv6 أصلاً.
         */
        const v4 = addrs.filter(a => a.family === 4).map(a => a.address);
        const v6 = addrs.filter(a => a.family === 6).map(a => a.address);

        const hidden = v4.filter(ip => /^10\./.test(ip)
            || /^192\.168\./.test(ip)
            || /^172\.(1[6-9]|2\d|3[01])\./.test(ip));

        if (v4.length === 0 || hidden.length !== v4.length) return null;

        return { hidden, v6 };
    } catch {
        return null;
    }
}


/**
 * شرحُ سبب الفشل — بالعلاج لا بالعَرَض.
 */
async function explain(cfg, r) {
    say('');

    const hidden = await privateHost(cfg.DB_HOST);

    if (hidden) {
        say('  **وعنوانُ الخادم خاصٌّ لا يُرى من خارج شبكته.**');
        say('  يُترجَم اسمُه إلى: ' + hidden.hidden.join('، '));
        say('');
        say('  فالبياناتُ صحيحةٌ والقاعدةُ حيّةٌ يستعملها تطبيقُك المنشور — لكنها');
        say('  مغلقةٌ على شبكة Laravel Cloud، ولا تُرى من هذا الجهاز.');

        if (hidden.v6.length) {
            say('');
            say('  ولها عنوانٌ عامٌّ من الجيل السادس (IPv6)، فإن كان لجهازك طريقٌ');
            say('  إليه عمل الاتصالُ بلا تغييرٍ في اللوحة. وأكثرُ الشبكات لا طريقَ');
            say('  لها إليه.');
        }

        say('');
        say('  **والعلاج:** لوحةُ Laravel Cloud ← قاعدةُ البيانات ← فعّل الوصولَ');
        say('  العامّ (Public access)، وقيّده بعنوان جهازك إن أمكن. ثم أعد التشغيل.');

        return;
    }

    if (r.code === 'ETIMEDOUT' || r.code === 'ECONNREFUSED') {
        say('  انتهى الوقتُ بلا جواب: القاعدةُ قد تكون نائمةً (Scale to zero)،');
        say('  أو الوصولُ العامُّ مغلقٌ عنها، أو جدارُ شبكتك يحجب المنفذ 3306.');

        return;
    }

    if (/Access denied/i.test(r.why)) {
        say('  المستخدمُ أو كلمةُ المرور — والعنوانُ سليم.');

        return;
    }

    if (/Unknown database/i.test(r.why)) {
        say('  العنوانُ والمستخدمُ سليمان، واسمُ القاعدة خطأ.');
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

function parseEnvFile(file) {
    if (!fs.existsSync(file)) return null;

    const cfg = {};

    for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
        const m = line.match(/^\s*([A-Z_]+)\s*=\s*(.*)$/);
        if (m) cfg[m[1]] = m[2].replace(/^["']|["']$/g, '').trim();
    }

    return cfg;
}

const readExisting = () => parseEnvFile(envPath);

/**
 * أخذُ العنوان والمستخدم وكلمة المرور من ملفِّ مشروعٍ آخرَ على هذا الجهاز.
 *
 * وعلّتُه أن صاحبَ البرنامج قد يكون له تطبيقان على **خادمِ قاعدةٍ واحد**
 * في Laravel Cloud، فبياناتُ الاتصال واحدةٌ ولا يختلف إلا اسمُ القاعدة.
 * فبدل أن يفتح لوحةً ويُنقّب عن قيمٍ هي عنده أصلاً، تُنسخ من ملفّه.
 *
 * **ولا تمرّ هذه القيمُ بأحدٍ غيره:** الأداةُ على جهازه تقرأ وتكتب،
 * ولا تُطبع على الشاشة ولا تُرسَل. ويبقى الحارسُ قائماً — إن لم تكن
 * القاعدةُ على ذلك الخادم فشل الاتصالُ ولم يُكتب شيء.
 */
function borrow(file) {
    const abs = path.isAbsolute(file) ? file : path.resolve(ROOT, file);

    const cfg = parseEnvFile(abs);

    if (!cfg) return { ok: false, why: 'لا يوجد ملفٌّ في ' + abs };

    const need = ['DB_HOST', 'DB_USERNAME', 'DB_PASSWORD'].filter(k => !cfg[k]);

    if (need.length) return { ok: false, why: 'الملفُّ ناقصٌ: ' + need.join('، ') };

    return {
        ok: true,
        abs,
        cfg: {
            DB_HOST: cfg.DB_HOST,
            DB_PORT: cfg.DB_PORT || '3306',
            DB_USERNAME: cfg.DB_USERNAME,
            DB_PASSWORD: cfg.DB_PASSWORD,
        },
    };
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

        if (!r.ok) {
            say('\n✗ فشل: ' + r.why);
            await explain(cfg, r);
            process.exit(1);
        }

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

    let cfg = null;

    /*
     * `--from`: التطبيقان على خادمٍ واحد، فلا يُسأل إلا عن اسم القاعدة.
     */
    if (args.from) {
        const b = borrow(args.from);

        if (!b.ok) { say('✗ ' + b.why); rl.close(); process.exit(2); }

        say('  سآخذ العنوانَ والمستخدمَ وكلمةَ المرور من:');
        say('    ' + b.abs);
        say('  ولن تُطبع هذه القيمُ ولا تُرسَل — تُقرأ وتُكتب على هذا الجهاز وحدَه.');
        say('');

        const name = await ask('  اسمُ قاعدة البصمة على ذلك الخادم : ');

        if (!name) { say('\n✗ لا اسمَ قاعدة — لم يُكتب شيء.'); rl.close(); process.exit(2); }

        cfg = Object.assign({}, b.cfg, { DB_DATABASE: name });

        say('');
    }

    /*
     * وإلّا فالرابطُ الكامل: لصقةٌ واحدةٌ أقلُّ خطأً من نقل خمسة حقولٍ
     * بالعين. ومن لم يكن عنده رابطٌ سُئل حقلاً حقلاً.
     */
    const url = cfg ? '' : await ask('  ألصق رابطَ الاتصال إن كان عندك (mysql://…) — أو اتركه فارغاً للسؤال حقلاً حقلاً:\n  > ');

    if (!cfg) cfg = url ? parseUrl(url) : null;

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
        say('  لم يُكتب شيء.');
        await explain(cfg, r);
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
