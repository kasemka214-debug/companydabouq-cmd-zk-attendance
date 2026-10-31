#!/usr/bin/env node
'use strict';

/*
    استعادةُ نسخةٍ في قاعدة البيانات — الوجهُ الآخر لـ`pull.js`.

    ══ لماذا هذا الملفّ ══

    كان السحبُ أداةً والاستعادةُ سطراً في وثيقة: «تُستعاد بـ
    `mysql … < الملف.sql`». ونسخةٌ لا تُستعاد ليست نسخة — وسطرٌ يُنسخ
    بالعين فيه عنوانُ خادمٍ ومستخدمٌ وكلمةُ مرورٍ واسمُ قاعدة، وكلُّ
    واحدةٍ منها موضعُ خطأ. وأخطرُ الخطأ أن يُكتب اسمُ القاعدة الحيّة في
    موضع التجريبية.

    ══ ما يحرسه ══

      - **يُفحص قبل أن يُرفع**: أربعةُ فحوصٍ تسبق أوّلَ بايت (انظر
        `preflight` أدناه). وكلُّها تقع في ثوانٍ، والرفعُ يقع في دقائق —
        فالفشلُ المبكّر أرحم.
      - **يُقال ما سيُمحى قبل أن يُمحى**: تُعرض صفوفُ الهدف الآن، وصفوفُ
        النسخة من بيانها، ثم يُطلب أن يُكتب اسمُ القاعدة بيدٍ — لا «y».
        فمن كتب اسمَها فقد قرأه.
      - **ويُثبَت التمام بعدَه**: تُعدّ صفوفُ كل جدولٍ وتُقارن ببيان
        النسخة، فلا يُقال «تمّت» على ظنّ.

    ══ الاستعمال ══

        node scripts/db-tool/restore.js                    # أحدثُ نسخةٍ في storage/backups
        node scripts/db-tool/restore.js <ملف.sql>
        node scripts/db-tool/restore.js --env .env.production
        node scripts/db-tool/restore.js --yes              # بلا سؤال (للسكربتات)
*/

const fs       = require('fs');
const path     = require('path');
const crypto   = require('crypto');
const readline = require('readline');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');

/* ───────────────────────── الوسائط ───────────────────────── */

const args = { env: '.env.production', file: null, yes: false, anyway: false };

for (let i = 2; i < process.argv.length; i++) {
    const x = process.argv[i];

    if (x === '--env') args.env = process.argv[++i];
    else if (x === '--yes' || x === '-y') args.yes = true;
    else if (x === '--anyway') args.anyway = true;
    else if (x === '--help' || x === '-h') {
        console.log('استعادةُ نسخةٍ في قاعدة البيانات\n');
        console.log('  <ملف.sql>    النسخةُ المستعادة (الافتراضي أحدثُ ما في storage/backups)');
        console.log('  --env FILE   بياناتُ القاعدة الهدف (الافتراضي .env.production)');
        console.log('  --yes        بلا سؤالِ تأكيد');
        console.log('  --anyway     الاستعادةُ وإن لم يمرّ فحصٌ قاطع — على علمٍ بما قيل');
        process.exit(0);
    } else if (x.startsWith('-')) { console.error('وسيطٌ غير معروف: ' + x); process.exit(2); }
    else args.file = x;
}

/* ───────────────────────── أدوات ───────────────────────── */

const say   = s => console.log(s);
const fmt   = n => Number(n).toLocaleString('en-US');
const fmtMB = b => (b / 1048576).toFixed(1) + ' MB';

function readEnv(file) {
    const abs = path.isAbsolute(file) ? file : path.join(ROOT, file);

    if (!fs.existsSync(abs)) return null;

    const cfg = {};

    for (const line of fs.readFileSync(abs, 'utf8').split(/\r?\n/)) {
        const m = line.match(/^\s*([A-Z_]+)\s*=\s*(.*)$/);
        if (m) cfg[m[1]] = m[2].replace(/^["']|["']$/g, '').trim();
    }

    return cfg;
}

/** أحدثُ نسخةٍ في مجلّد النسخ */
function newest() {
    const dir = path.join(ROOT, 'storage', 'backups');

    if (!fs.existsSync(dir)) return null;

    const files = fs.readdirSync(dir)
        .filter(f => f.endsWith('.sql'))
        .map(f => ({ f, t: fs.statSync(path.join(dir, f)).mtimeMs }))
        .sort((a, b) => b.t - a.t);

    return files.length ? path.join(dir, files[0].f) : null;
}

/**
 * بيانُ المحتوى بجانب النسخة — منه تُعرف الجداولُ وأعدادُها.
 *
 * وهو الذي يجعل «تمّت» قابلةً للإثبات: بغيره لا يُعرف ما كان يجب أن
 * يصل، فلا يُقارَن بما وصل.
 */
function manifestOf(sqlFile) {
    const m = sqlFile.replace(/\.sql$/, '.manifest.txt');

    if (!fs.existsSync(m)) return null;

    const rows = {};

    for (const line of fs.readFileSync(m, 'utf8').split(/\r?\n/)) {
        if (line.startsWith('#') || ! line.trim()) continue;

        const [name, n] = line.split('\t');
        if (name && n !== undefined) rows[name.trim()] = Number(n);
    }

    return Object.keys(rows).length ? rows : null;
}

/** أسطرُ `#` في رأس البيان: البصمةُ وحجمُ أكبرِ عبارةٍ وغيرُها */
function manifestMeta(sqlFile) {
    const m = sqlFile.replace(/\.sql$/, '.manifest.txt');

    if (! fs.existsSync(m)) return {};

    const out = {};

    for (const line of fs.readFileSync(m, 'utf8').split(/\r?\n/)) {
        /* الأرقامُ في المفتاح واجبة: `sql_sha256` أحدُها، وقد أسقطه `[a-z_]+` */
        const h = line.match(/^﻿?#\s*([a-z0-9_]+)\s*:\s*(.+)$/);
        if (h) out[h[1]] = h[2].trim();
    }

    return out;
}

/**
 * مرّةٌ واحدةٌ على الملف تُخرج شيئين: بصمتَه، وأعمدةَ كلِّ جدولٍ فيه.
 *
 * ولم يُقرأ الملفُّ إلى الذاكرة — فهو مئاتُ الميجابايتات، و`readFileSync`
 * عليه يُسقط node على نفاد الذاكرة. يُقرأ تدفّقاً، وتُجمع الأسطرُ سطراً
 * سطراً، ويُحدَّث التعميةُ على كل كتلة.
 */
function scanDump(file) {
    return new Promise((resolve, reject) => {
        const hash   = crypto.createHash('sha256');
        const schema = {};

        let tail  = '';
        let table = null;
        let cols  = [];

        const eat = line => {
            if (table === null) {
                const m = line.match(/^CREATE TABLE `([a-zA-Z0-9_]+)`/);
                if (m) { table = m[1]; cols = []; }
                return;
            }

            if (/^\)\s*ENGINE/i.test(line)) { schema[table] = cols; table = null; return; }

            /* عمودٌ: «`الاسم` نوعُه …» — وتُستثنى المفاتيحُ والقيود */
            const c = line.match(/^\s*`([a-zA-Z0-9_]+)`\s+[^,]/);
            if (c) cols.push(c[1]);
        };

        const s = fs.createReadStream(file);

        s.on('data', chunk => {
            hash.update(chunk);

            const text  = tail + chunk.toString('utf8');
            const lines = text.split('\n');

            tail = lines.pop();              /* آخرُ سطرٍ قد يكون مقطوعاً */
            for (const l of lines) eat(l);
        });

        s.on('end', () => {
            if (tail) eat(tail);
            resolve({ sha256: hash.digest('hex').toUpperCase(), schema });
        });

        s.on('error', reject);
    });
}

/** متغيّراتُ الخادم — وهي في الوقت نفسِه تجربةُ المصادقة */
async function serverVars(cfg) {
    const mysqlp = require('mysql2/promise');

    const db = await mysqlp.createConnection({
        host: cfg.DB_HOST,
        port: Number(cfg.DB_PORT || 3306),
        user: cfg.DB_USERNAME,
        password: cfg.DB_PASSWORD,
        database: cfg.DB_DATABASE,
        connectTimeout: 30000,
    });

    const [[v]]   = await db.query('SELECT VERSION() AS version, @@max_allowed_packet AS packet');
    const [cols]  = await db.query(
        'SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');

    await db.end();

    const live = {};
    for (const r of cols) (live[r.t] = live[r.t] || []).push(r.c);

    return { version: v.version, packet: Number(v.packet), live };
}

/**
 * الفحصُ القبليّ — أربعةُ أسئلةٍ تُسأل قبل أن يُرفع بايتٌ واحد.
 *
 * ══ لماذا ══
 *
 * الرفعُ عشراتُ الدقائق، والفشلُ فيه يترك القاعدةَ نصفَ مستعادة. وكلُّ
 * واحدٍ من هذه الأربعة كان يُكتشف بعد الرفع لا قبله:
 *
 *   ١) **البصمة**: نسخةٌ نقص منها بايتٌ تُستعاد صامتةً وتُسقط جدولاً
 *      في منتصفه. والبيانُ يحمل sha256 فيُقابل.
 *   ٢) **المصادقة**: كلمةٌ خاطئةٌ تُردّ في ثانية — فلا تُنتظر عشرُ دقائق
 *      لتُقال.
 *   ٣) **`max_allowed_packet`**: إن كان في الخادم أصغرَ من أكبرِ عبارةٍ
 *      في النسخة سقطت في منتصفها بـ«packet too large». وهذا يُعرف قبلَه.
 *   ٤) **البِنية**: جدولُ `migrations` ليس في النسخة، فيبقى في الهدف
 *      يقول «رُحّل كلُّ شيء». فإن كانت النسخةُ أقدمَ بعمود، خرجت القاعدةُ
 *      بجدولٍ ناقصٍ و`migrations` يحلف أنه كامل — فلا ترحيلٌ يُصلحه،
 *      والموقعُ يسقط على «عمودٌ غيرُ موجود». **وهذا لا تكشفه مقارنةُ
 *      أعداد الصفوف بعدَه أبداً.**
 *
 * ويُفرَّق في البِنية بين جهتَي الاختلاف:
 *   - عمودٌ **في الهدف وليس في النسخة** → قاتل، لما سبق.
 *   - عمودٌ **في النسخة وليس في الهدف** → تحذير: النسخةُ ستُنشئه، لكنها
 *     أحدثُ من ترحيلات الهدف، فيُنظر.
 */
async function preflight(file, meta, cfg) {
    say('  الفحصُ القبليّ');
    say('  ' + '─'.repeat(46));

    let fatal = 0, warn = 0;

    /* ١) البصمة — وفي المرّة نفسِها تُقرأ بِنيةُ الجداول */
    const scan = await scanDump(file);
    const want = (meta.sql_sha256 || '').toUpperCase();

    if (! want) {
        say('  ⚠ لا بصمةَ في البيان، فلا تُقابَل.');
        warn++;
    } else if (scan.sha256 !== want) {
        say('  ✗ البصمةُ مختلفة — الملفُّ ليس الذي سُحب، أو نقص منه.');
        say('      في البيان : ' + want.slice(0, 32) + '…');
        say('      المحسوبة  : ' + scan.sha256.slice(0, 32) + '…');
        fatal++;
    } else {
        say('  ✓ البصمة مطابقة (sha256)');
    }

    /* ٢) و٣) المصادقةُ وسعةُ العبارة */
    let srv = null;

    try {
        srv = await serverVars(cfg);
        say('  ✓ المصادقة تمّت — ' + srv.version);
    } catch (e) {
        say('  ✗ تعذّر الاتصالُ أو المصادقة: ' + (e.code || e.message));
        say('      راجع البيانات بـ:  node scripts/db-tool/setup-env.js --check');
        return { fatal: fatal + 1, warn };
    }

    const biggest = Number(meta.largest_statement_bytes || 0);

    if (biggest && srv.packet && biggest > srv.packet) {
        say('  ✗ أكبرُ عبارةٍ في النسخة ' + fmt(biggest) + ' بايت،'
            + ' و`max_allowed_packet` في الخادم ' + fmt(srv.packet) + '.');
        say('      ستسقط في منتصفها. ارفع الحدَّ في الخادم أوّلاً.');
        fatal++;
    } else if (biggest) {
        say('  ✓ أكبرُ عبارةٍ ' + fmt(biggest) + ' بايت ضمن حدّ الخادم ' + fmt(srv.packet));
    }

    /* ٤) البِنية */
    const names = Object.keys(scan.schema);

    if (! names.length) {
        say('  ⚠ لم أقرأ من النسخة بِنيةَ جدولٍ واحد — لا أقابل البِنية.');
        warn++;
    } else {
        let bad = 0;

        for (const t of names) {
            const live = srv.live[t];

            if (! live) continue;            /* جديدٌ على الهدف — ستُنشئه النسخة */

            const missing = live.filter(c => ! scan.schema[t].includes(c));
            const extra   = scan.schema[t].filter(c => ! live.includes(c));

            if (missing.length) {
                say('  ✗ ' + t + ': في الهدف ولا في النسخة — ' + missing.join(', '));
                bad++; fatal++;
            }

            if (extra.length) {
                say('  ⚠ ' + t + ': في النسخة ولا في الهدف — ' + extra.join(', '));
                bad++; warn++;
            }
        }

        if (! bad) say('  ✓ بِنيةُ ' + names.length + ' جدولاً تطابق الهدف عموداً بعمود');
    }

    say('  ' + '─'.repeat(46));
    say('');

    return { fatal, warn };
}

/** عميلُ mysql: من المسار، أو من مواضعه المعروفة على ويندوز */
function findClient() {
    const candidates = [
        process.env.MYSQL_CLIENT,
        'C:\\xampp\\mysql\\bin\\mysql.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysql.exe',
        'C:\\Program Files\\MariaDB\\bin\\mysql.exe',
    ].filter(Boolean);

    for (const c of candidates) {
        if (fs.existsSync(c)) return c;
    }

    /* وإلّا فمن PATH — يُجرَّب بتشغيله */
    return 'mysql';
}

function ask(q) {
    const rl = readline.createInterface({
        input: process.stdin,
        output: process.stdout,
        terminal: Boolean(process.stdin.isTTY),
    });

    return new Promise(resolve => {
        process.stdout.write(q);

        const queue = [];
        let done = false;

        rl.on('line', l => { if (! done) { done = true; rl.close(); resolve(l.trim()); } else queue.push(l); });
        rl.on('close', () => { if (! done) { done = true; resolve(''); } });
    });
}

/* ───────────────────────── العدّ ───────────────────────── */

async function counts(cfg, tables) {
    const mysqlp = require('mysql2/promise');

    const db = await mysqlp.createConnection({
        host: cfg.DB_HOST,
        port: Number(cfg.DB_PORT || 3306),
        user: cfg.DB_USERNAME,
        password: cfg.DB_PASSWORD,
        database: cfg.DB_DATABASE,
        connectTimeout: 30000,
        compress: true,
    });

    const out = {};

    for (const t of tables) {
        try {
            const [[r]] = await db.query('SELECT COUNT(*) AS n FROM `' + t + '`');
            out[t] = Number(r.n);
        } catch {
            out[t] = null;   /* لا جدولَ بهذا الاسم بعد */
        }
    }

    await db.end();

    return out;
}

/* ───────────────────────── المسار ───────────────────────── */

(async () => {
    const cfg = readEnv(args.env);

    if (! cfg || ! cfg.DB_HOST) {
        say('لا بياناتِ قاعدةٍ في ' + args.env + ' — شغّل setup-env.js أوّلاً.');
        process.exit(2);
    }

    const file = args.file
        ? (path.isAbsolute(args.file) ? args.file : path.resolve(ROOT, args.file))
        : newest();

    if (! file || ! fs.existsSync(file)) {
        say('لا نسخةَ أستعيدها. مرّر اسمَ ملفٍّ، أو خذ نسخةً أوّلاً.');
        process.exit(2);
    }

    const size     = fs.statSync(file).size;
    const manifest = manifestOf(file);
    const tables   = manifest ? Object.keys(manifest) : [];

    say('');
    say('  استعادةُ نسخةٍ في قاعدة البيانات');
    say('  ─────────────────────────────────');
    say('');
    say('  النسخة : ' + path.basename(file) + '   (' + fmtMB(size) + ')');
    say('  الهدف  : ' + cfg.DB_DATABASE + ' على ' + String(cfg.DB_HOST).slice(0, 34) + '…');
    say('');

    if (! manifest) {
        say('  ⚠ لا بيانَ محتوىً بجانب النسخة، فلا يُقارَن ما وصل بما كان يجب.');
        say('');
    }

    /*
     * الفحصُ القبليّ قبل كل شيء — فهو ثوانٍ، والرفعُ دقائق، والفشلُ في
     * منتصف الرفع يترك القاعدةَ نصفَ مستعادة.
     */
    const pre = await preflight(file, manifestMeta(file), cfg);

    if (pre.fatal && ! args.anyway) {
        say('✗ ' + pre.fatal + ' فحصاً قاطعاً لم يمرّ — ولم يُرفع بايتٌ واحد.');
        say('');
        say('  وإن كنتَ قرأتَ ما فوق وأردتَ الاستعادةَ على علمٍ بذلك،');
        say('  فأضف --anyway. ومعناها: تتحمّل ما سبق بيانُه.');
        process.exit(3);
    }

    /*
     * **ما سيُمحى يُقال قبل أن يُمحى.**
     *
     * فالنسخةُ تُسقط كلَّ جدولٍ فيها ثم تُنشئه — وما في الهدف من صفوف
     * يذهب. فتُعرض الأعدادُ الحاليةُ إلى جانب القادمة، ويُرى الفرقُ قبل
     * أن يقع.
     */
    if (tables.length) {
        say('  الجدول' + ' '.repeat(18) + 'في الهدف الآن' + '   ستصير');
        say('  ' + '─'.repeat(46));

        const before = await counts(cfg, tables);

        for (const t of tables) {
            const b = before[t];
            say('  ' + t.padEnd(24)
                + (b === null ? '—' : fmt(b)).padStart(12)
                + fmt(manifest[t]).padStart(10));
        }

        say('  ' + '─'.repeat(46));
        say('');
        say('  وما سوى هذه الجداول لا يُمَسّ.');
        say('');
    }

    if (! args.yes) {
        say('  **وهذا يمحو ما في الجداول أعلاه ويضع مكانَه ما في النسخة.**');
        say('');

        const typed = await ask('  اكتب اسمَ القاعدة للتأكيد (' + cfg.DB_DATABASE + ') : ');

        if (typed !== cfg.DB_DATABASE) {
            say('\n  لم يُطابق — لم يتغيّر شيء.');
            process.exit(1);
        }

        say('');
    }

    /* ── التنفيذ ── */

    const client = findClient();

    say('  أستعيد ' + fmtMB(size) + ' ...');

    const code = await new Promise(resolve => {
        const p = spawn(client, [
            '--max-allowed-packet=1G',
            '--default-character-set=utf8mb4',
            '-h', cfg.DB_HOST,
            '-P', String(cfg.DB_PORT || 3306),
            '-u', cfg.DB_USERNAME,
            cfg.DB_DATABASE,
        ], {
            /* كلمةُ المرور في البيئة لا في سطر الأوامر — فالسطرُ يُرى في قائمة العمليات */
            env: Object.assign({}, process.env, { MYSQL_PWD: cfg.DB_PASSWORD }),
            stdio: ['pipe', 'inherit', 'inherit'],
        });

        p.on('error', e => {
            say('\n✗ تعذّر تشغيل عميل mysql: ' + e.message);
            say('  ثبّت عميلَ MySQL أو MariaDB، أو اضبط MYSQL_CLIENT على مساره.');
            resolve(127);
        });

        p.on('close', resolve);

        fs.createReadStream(file).pipe(p.stdin);
    });

    if (code !== 0) {
        say('\n✗ انتهت الاستعادةُ بخطأ (الرمز ' + code + ').');
        process.exit(code || 1);
    }

    /* ── الإثبات ── */

    if (! tables.length) {
        say('\n✓ تمّت الاستعادة. (ولا بيانَ محتوىً أقارن به.)');
        process.exit(0);
    }

    say('');
    say('  أعدّ ما وصل ...');
    say('');

    const after = await counts(cfg, tables);

    let bad = 0;

    say('  الجدول' + ' '.repeat(18) + 'المتوقَّع' + '     وصل');
    say('  ' + '─'.repeat(46));

    for (const t of tables) {
        const want = manifest[t];
        const got  = after[t];
        const ok   = got === want;

        if (! ok) bad++;

        say('  ' + (ok ? '✓ ' : '✗ ') + t.padEnd(22)
            + fmt(want).padStart(10)
            + (got === null ? '—' : fmt(got)).padStart(9));
    }

    say('  ' + '─'.repeat(46));
    say('');

    if (bad) {
        say('✗ ' + bad + ' جدولاً لم يُطابق. اقرأ ما فوق قبل أن تعتمد القاعدة.');
        process.exit(1);
    }

    say('✓ كلُّ جدولٍ طابق بيانَه. القاعدةُ جاهزة.');
    say('');
})().catch(e => {
    console.error('\nخطأٌ غيرُ متوقَّع: ' + (e && e.message ? e.message : e));
    process.exit(1);
});
