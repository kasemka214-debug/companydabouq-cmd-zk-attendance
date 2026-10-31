#!/usr/bin/env node
'use strict';

/*
    سحبُ قاعدة بيانات حضور البصمة كاملةً — بلا mysqldump.

    ══ لماذا هذا الملفّ ══

    كان السحبُ بـ`db-pull.ps1` يحتاج mysqldump من XAMPP، والجهازُ الجديد لا
    XAMPP فيه ولا MySQL. وهذا يتكلّم مع القاعدة بنفسه (مكتبة mysql2) ويكتب
    ملفَّ SQL يُستعاد بعميل mysql العاديّ — فلا يحتاج إلا Node.

    ══ كيف يصمد ══

    الرابطُ إلى الخادم بعيدٌ بطيء (us-east-2) وينقطع في أثناء السحب، وجداولُ
    الصورِ فيها صفوفٌ بعشرات الكيلوبايتات (الصورةُ base64 في القاعدة). فـ:

      - كلُّ جدولٍ يُسحب على دفعاتٍ بمفتاحه (`WHERE id > آخر id LIMIT n`)،
        وحجمُ الدفعة يتبع حجمَ الصفّ: ألفُ صفٍّ للصغيرة، وصفٌّ أو صفّان
        للصور.
      - انقطع الاتصال؟ يُعاد ويُستأنف من آخر صفٍّ كُتب، لا من أوّل الجدول،
        والدفعةُ تصغر إلى النصف كل مرّة. وصفٌّ لا يُحتمل وحدَه يُسجَّل
        باسمه ويُتجاوَز، فلا يُسقط ما بعده.
      - انقطع البرنامجُ نفسُه (كهرباء، إغلاق)؟ يُحفظ الحالُ بعد كل دفعة،
        وعند التشغيل التالي يُستأنف من حيث وقف.
      - الصغيرةُ أوّلاً، والثقيلةُ آخراً: البياناتُ الحقيقية تكتمل في
        دقائق، وما يطول هو ما يُعاد إن سقط.

    ══ ما يضمن التمام ══

    آخرُ سطرٍ في الملف لا يشهد، والحجمُ لا يشهد. الشاهدُ عددُ الصفوف: يُعدّ
    كلُّ ما كُتب، ويُقرأ عددُ الصفوف من الخادم بعد السحب، ويُقارَنان في
    بيان المحتوى (manifest) بالصيغة التي يقرؤها `db-verify.ps1`.

    ══ الاستعمال ══

        node scripts/db-tool/pull.js              # سحبٌ كامل
        node scripts/db-tool/pull.js --gzip       # ويضغط الناتج .sql.gz
        node scripts/db-tool/pull.js --no-files   # بلا الصور وأرشيف الطلبات
        node scripts/db-tool/pull.js --schema     # البنية وحدها
        node scripts/db-tool/pull.js --only zk_punches,zk_pins
        node scripts/db-tool/pull.js --list       # الجداول وأحجامها فقط
        node scripts/db-tool/pull.js --fresh      # لا تستأنف نسخةً ناقصة

    بياناتُ الاتصال من `.env.production` (مستثنىً من git) ولا تُكتب في مكان.
*/

const fs       = require('fs');
const path     = require('path');
const zlib     = require('zlib');
const crypto   = require('crypto');
const readline = require('readline');
const { once } = require('events');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');

let mysql, mysqlp;
try {
    mysql  = require('mysql2');
    mysqlp = require('mysql2/promise');
} catch {
    console.error('مكتبةُ الاتصال غير مثبّتة. شغّل مرّةً واحدة:');
    console.error('    cd scripts\\db-tool && npm ci');
    console.error('أو افتح «سحب-قاعدة-البيانات.cmd» وهو يثبّتها من نفسه.');
    process.exit(2);
}

/* ───────────────────────── الوسائط ───────────────────────── */

const args = parseArgs(process.argv.slice(2));

function parseArgs(list) {
    const a = { env: '.env.production', out: null, gzip: false, schema: false, noFiles: false, only: null, list: false, fresh: false, yes: false, noOpen: false };
    for (let i = 0; i < list.length; i++) {
        const x = list[i];
        const next = () => list[++i];
        if (x === '--env')          a.env = next();
        else if (x === '--out')     a.out = next();
        else if (x === '--gzip')    a.gzip = true;
        else if (x === '--schema')  a.schema = true;
        else if (x === '--no-files') a.noFiles = true;
        else if (x === '--only')    a.only = next().split(',').map(s => s.trim()).filter(Boolean);
        else if (x === '--list')    a.list = true;
        else if (x === '--fresh')   a.fresh = true;
        else if (x === '--yes' || x === '-y') a.yes = true;
        else if (x === '--no-open') a.noOpen = true;
        else if (x === '--help' || x === '-h') { printHelp(); process.exit(0); }
        else { console.error('وسيطٌ غير معروف: ' + x); printHelp(); process.exit(2); }
    }
    return a;
}

function printHelp() {
    console.log(`سحبُ قاعدة بيانات حضور البصمة

  --gzip        ضغطُ الناتج بعد تمامه (.sql.gz) وحذفُ الأصل
  --no-files    بلا الجداول الثقيلة: صورُ البصمات وأرشيفُ الطلبات
  --schema      البنيةُ وحدها بلا بيانات
  --only a,b    جداولُ بعينها
  --list        عرضُ الجداول وأحجامها ثم الخروج
  --fresh       بدءٌ من الصفر ولو وُجدت نسخةٌ ناقصة
  --yes         بلا أسئلة (استئنافٌ تلقائي)
  --no-open     لا تفتح مجلّد النسخ في النهاية
  --env FILE    ملفُّ البيئة (الافتراضي .env.production)
  --out DIR     مجلّدُ الناتج (الافتراضي storage/backups)`);
}

/* ───────────────────────── الإعدادات ───────────────────────── */

/**
 * الجداولُ الثقيلة: صفوفُها بعشرات الكيلوبايتات أو أكثر.
 *
 *   `zk_punch_photos`  صورةُ كل بصمةٍ base64 في القاعدة — والواحدةُ ثلاثون
 *                       كيلوبايتاً فأكثر، وعشرون جهازاً بخمسين بصمةً يومياً
 *                       تكتب جيجابايتاً في الشهر.
 *   `zk_raw_logs`      جسدُ كلّ طلبٍ وصل من جهاز، مقبولاً كان أو مردوداً.
 *
 * وعلامةُ الثِّقَل تقود شيئين: الترتيبَ — الثقيلُ آخراً، فتكتمل البياناتُ
 * الحقيقيةُ في دقائق وما يطول هو ما يُعاد إن سقط — و`--no-files` الذي
 * يُخرجها فتصير النسخةُ خفيفةً تُنقل في ثوان.
 *
 * **وهي قائمةُ أسماءٍ لا نمط.** فأسماءُ هذا المشروع كلُّها تبدأ بـ`zk_`،
 * فنمطٌ على البادئة يبتلع الجداولَ كلَّها، ونمطٌ على لاحقةٍ لا يُطابق
 * شيئاً. والقائمةُ تُقرأ فيُعلم ما فيها.
 */
const HEAVY_TABLES = ['zk_punch_photos', 'zk_raw_logs'];

/** أكبرُ جملة INSERT تُكتب — ما لم يكن الصفُّ الواحد أكبرَ منها. */
const MAX_STATEMENT_BYTES = 1024 * 1024;

/** بايتاتٌ مستهدَفةٌ للدفعة الواحدة على السلك، ومنها يُشتقّ عددُ صفوفها. */
const CHUNK_TARGET_BYTES = 4 * 1024 * 1024;

/** مهلةُ الدفعة الواحدة قبل أن يُعدّ الاتصالُ معلّقاً فيُقطع ويُعاد. */
const CHUNK_TIMEOUT_MS = 10 * 60 * 1000;

const MAX_RETRIES = 8;

/* ───────────────────────── أدواتٌ صغيرة ───────────────────────── */

const fmtInt = n => Number(n).toLocaleString('en-US');
const fmtMB  = b => (b / 1048576).toFixed(b < 10485760 ? 2 : 1) + ' MB';
const now    = () => new Date();
const pad    = n => String(n).padStart(2, '0');
const stamp  = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + '-' + pad(d.getHours()) + pad(d.getMinutes());
// الوقتُ المحليّ كما تراه الساعة — لا UTC — فيطابق اسمَ الملف
const local  = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
const sleep  = ms => new Promise(r => setTimeout(r, ms));
const elapsed = from => {
    const s = Math.round((Date.now() - from) / 1000);
    return s < 60 ? s + ' ث' : Math.floor(s / 60) + ' د ' + (s % 60) + ' ث';
};

const isTTY = !!process.stdout.isTTY;
let lineOpen = false;

function say(msg) {
    if (lineOpen) { process.stdout.write('\n'); lineOpen = false; }
    console.log(msg);
}
function status(msg) {
    // سطرٌ يُكتب فوق نفسه في الطرفية، ويُطبع عادياً في ملفّ
    if (isTTY) { process.stdout.write('\r' + msg.padEnd(100).slice(0, 100)); lineOpen = true; }
    else console.log(msg);
}

function readEnv(file) {
    const full = path.isAbsolute(file) ? file : path.join(ROOT, file);
    if (!fs.existsSync(full)) {
        say('لا يوجد ' + file);
        say('انسخ .env.production.example إليه واملأ بيانات الاتصال من لوحة Laravel Cloud.');
        process.exit(1);
    }
    const cfg = {};
    for (const raw of fs.readFileSync(full, 'utf8').split(/\r?\n/)) {
        const line = raw.trim();
        if (!line || line.startsWith('#') || !line.includes('=')) continue;
        const i = line.indexOf('=');
        const key = line.slice(0, i).trim();
        let val = line.slice(i + 1).trim();
        if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) val = val.slice(1, -1);
        cfg[key] = val;
    }
    for (const k of ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']) {
        if (!cfg[k]) { say('ناقصٌ في ' + file + ': ' + k); process.exit(1); }
    }
    return cfg;
}

function ask(question) {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    return new Promise(res => rl.question(question, a => { rl.close(); res(a.trim()); }));
}

/** قيمةٌ من الصفّ → نصٌّ في جملة SQL. */
function esc(v) {
    if (v === null || v === undefined) return 'NULL';
    if (Buffer.isBuffer(v)) return v.length ? "X'" + v.toString('hex') + "'" : "''";
    if (typeof v === 'number') return Number.isFinite(v) ? String(v) : 'NULL';
    if (typeof v === 'bigint') return v.toString();
    if (typeof v === 'boolean') return v ? '1' : '0';
    if (v instanceof Date) return mysql.escape(v);
    if (typeof v === 'object') return mysql.escape(JSON.stringify(v));   // عمودُ JSON إن عاد كائناً
    return mysql.escape(String(v));
}
const id = s => mysql.escapeId(s);

/* ───────────────────────── الاتصال ───────────────────────── */

class Db {
    constructor(cfg) {
        this.cfg = cfg;
        this.conn = null;
        this.ssl = null;        // يُعرف عند أوّل اتصال
        this.compress = true;
        this.snapshotBreaks = -1; // أوّلُ اتصالٍ ليس انقطاعاً
    }

    async connect() {
        if (this.conn) { try { this.conn.destroy(); } catch {} this.conn = null; }

        const base = {
            host: this.cfg.DB_HOST,
            port: Number(this.cfg.DB_PORT || 3306),
            user: this.cfg.DB_USERNAME,
            password: this.cfg.DB_PASSWORD,
            database: this.cfg.DB_DATABASE,
            charset: 'utf8mb4',
            dateStrings: true,          // التواريخ كما هي نصّاً — لا تحويلَ منطقةٍ زمنية
            supportBigNumbers: true,
            bigNumberStrings: true,     // bigint نصّاً فلا يُقرَّب
            decimalNumbers: false,      // decimal نصّاً فلا يُقرَّب
            jsonStrings: true,          // JSON نصّاً كما خُزّن
            connectTimeout: 30000,
            compress: this.compress,    // الضغطُ على السلك: base64 يُضغط أضعافاً
        };

        let lastErr;
        for (const ssl of (this.ssl !== null ? [this.ssl] : [false, true])) {
            try {
                const opts = { ...base };
                if (ssl) {
                    opts.ssl = this.cfg.DB_SSL_CA && fs.existsSync(this.cfg.DB_SSL_CA)
                        ? { ca: fs.readFileSync(this.cfg.DB_SSL_CA), minVersion: 'TLSv1.2' }
                        : { rejectUnauthorized: false, minVersion: 'TLSv1.2' };
                }
                this.conn = await mysqlp.createConnection(opts);
                this.ssl = ssl;
                break;
            } catch (e) {
                lastErr = e;
                // الخادمُ يرفض غيرَ المشفَّر: يُعاد بـTLS
                if (!ssl && /secure transport|ER_SECURE_TRANSPORT_REQUIRED|3159/i.test(String(e.code) + ' ' + e.message)) continue;
                // الضغطُ لم يُقبل: يُعاد بلا ضغط
                if (this.compress && /compress/i.test(e.message)) { this.compress = false; return this.connect(); }
                throw e;
            }
        }
        if (!this.conn) throw lastErr;

        const c = this.conn;
        /* اتصالٌ أسقطه الخادمُ أو الرابط يُنسى فوره، فالاستعلامُ التالي يفتح غيرَه.
           كان يبقى في `this.conn` مغلقاً، فكلُّ إعادةٍ تطرق الميتَ نفسَه («Can't add
           new command when connection is in closed state») حتى تفنى الإعادات —
           وأوقف ذلك سحباً كاملاً عند وصف جدولٍ بين جدولين. */
        const forget = () => { if (this.conn === c) this.conn = null; };
        c.on('error', forget);
        c.on('end', forget);
        await c.query("SET NAMES utf8mb4");
        await c.query("SET time_zone = '+00:00'");
        for (const sql of [
            'SET SESSION net_read_timeout = 600',
            'SET SESSION net_write_timeout = 600',
            'SET SESSION wait_timeout = 28800',
            'SET SESSION max_execution_time = 0',
        ]) { try { await c.query(sql); } catch {} }

        // لقطةٌ متّسقة: كلُّ الجداول كما كانت في لحظةٍ واحدة — ما بقي الاتصالُ حيّاً
        await c.query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        await c.query('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        this.snapshotBreaks++;
        return c;
    }

    /** استعلامٌ بمهلة: اتصالٌ صامتٌ لا يُنتظر إلى الأبد. */
    async query(sql, params, timeoutMs = CHUNK_TIMEOUT_MS) {
        if (!this.conn) await this.connect();
        let timer;
        const guard = new Promise((_, rej) => {
            timer = setTimeout(() => {
                try { this.conn.destroy(); } catch {}
                this.conn = null;
                rej(Object.assign(new Error('انقضت مهلةُ الدفعة بلا ردّ'), { code: 'TIMEOUT', retryable: true }));
            }, timeoutMs);
        });
        try {
            const [rows] = await Promise.race([this.conn.query(sql, params), guard]);
            return rows;
        } catch (e) {
            // انقطاعٌ: يُرمى الاتصالُ فتفتح الإعادةُ (`withRetry`) اتصالاً جديداً لا تطرق الميت
            if (isRetryable(e)) { try { this.conn && this.conn.destroy(); } catch {} this.conn = null; }
            throw e;
        } finally {
            clearTimeout(timer);
        }
    }

    close() { try { this.conn && this.conn.end(); } catch {} this.conn = null; }
}

/** أخطاءٌ تمرّ بإعادة المحاولة، وأخطاءٌ لا يُجدي معها (كلمة مرور، قاعدة لا وجود لها). */
function isRetryable(e) {
    if (e.retryable) return true;
    const code = String(e.code || '');
    const msg  = String(e.message || '');
    if (/ER_ACCESS_DENIED|ER_BAD_DB_ERROR|ER_DBACCESS_DENIED|ER_NO_SUCH_TABLE|ER_PARSE_ERROR/.test(code)) return false;
    return /PROTOCOL_CONNECTION_LOST|ECONNRESET|ETIMEDOUT|ECONNREFUSED|EPIPE|ENOTFOUND|EAI_AGAIN|PROTOCOL_SEQUENCE_TIMEOUT|ER_NET_|ER_LOCK_WAIT_TIMEOUT|ER_QUERY_INTERRUPTED|Lost connection|Packets out of order|read ECONN|socket hang up|closed state|TIMEOUT|ER_SERVER_SHUTDOWN|ER_CON_COUNT|ER_TOO_MANY_USER_CONNECTIONS|Too many connections/i.test(code + ' ' + msg);
}

/* ───────────────────────── الكتابة ───────────────────────── */

class Writer {
    constructor(file, append) {
        this.file = file;
        this.stream = fs.createWriteStream(file, { flags: append ? 'a' : 'w' });
        this.bytes = append && fs.existsSync(file) ? fs.statSync(file).size : 0;
        this.maxStatement = 0;
    }
    async write(text) {
        const buf = Buffer.from(text, 'utf8');
        this.bytes += buf.length;
        if (!this.stream.write(buf)) await once(this.stream, 'drain');
    }
    async close() {
        this.stream.end();
        await once(this.stream, 'finish');
    }
}

function header(cfg, mode) {
    return [
        '-- نسخةُ قاعدة حضور البصمة — سُحبت بـ scripts/db-tool/pull.js',
        '-- database: ' + cfg.DB_DATABASE,
        '-- taken_at: ' + local(now()),
        '-- mode: ' + mode,
        '--',
        '-- تُستعاد بـ:  mysql --max-allowed-packet=1G <قاعدة> < هذا-الملف.sql',
        '',
        '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;',
        '/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;',
        '/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;',
        '/*!40101 SET NAMES utf8mb4 */;',
        "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;",
        "/*!40103 SET TIME_ZONE='+00:00' */;",
        '/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;',
        '/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;',
        "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;",
        '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;',
        '',
    ].join('\n');
}

function footer() {
    return [
        '',
        '/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;',
        '/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;',
        '/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;',
        '/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;',
        '/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;',
        '/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;',
        '/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;',
        '/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;',
        '',
        '-- Dump completed on ' + local(now()),
        '',
    ].join('\n');
}

/* ───────────────────────── الجداول ───────────────────────── */

async function catalog(db) {
    const rows = await db.query(
        `SELECT TABLE_NAME AS name, TABLE_TYPE AS type,
                IFNULL(TABLE_ROWS, 0) AS est_rows,
                IFNULL(DATA_LENGTH, 0) + IFNULL(INDEX_LENGTH, 0) AS bytes,
                IFNULL(AVG_ROW_LENGTH, 0) AS avg_row
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME`, [], 120000);

    const tables = rows.filter(r => r.type === 'BASE TABLE').map(r => ({
        name: r.name, estRows: Number(r.est_rows), bytes: Number(r.bytes), avgRow: Number(r.avg_row),
        heavy: HEAVY_TABLES.includes(r.name),
    }));
    const views = rows.filter(r => r.type === 'VIEW').map(r => r.name);

    // الصغيرةُ أوّلاً والثقيلةُ آخراً: البياناتُ الحقيقية تكتمل في دقائق
    tables.sort((a, b) => (a.heavy - b.heavy) || (a.bytes - b.bytes) || a.name.localeCompare(b.name));

    return { tables, views };
}

async function describe(db, table) {
    const cols = await db.query(
        `SELECT COLUMN_NAME AS name, DATA_TYPE AS type, COLUMN_KEY AS keyType, EXTRA AS extra
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION`, [table.name], 120000);

    // العمودُ المحسوب لا يُدرج — يُعاد حسابُه عند الاستعادة
    const insertable = cols.filter(c => !/GENERATED/i.test(c.extra));
    const pk = cols.filter(c => c.keyType === 'PRI');
    const keyset = pk.length === 1 && /int$/i.test(pk[0].type) ? pk[0].name : null;

    const [create] = await db.query('SHOW CREATE TABLE ' + id(table.name), [], 120000);
    const createSql = create['Create Table'];

    return { columns: insertable.map(c => c.name), pkCols: pk.map(c => c.name), keyset, createSql };
}

/** عددُ صفوف الدفعة: بحجم الصفّ، لا رقمٌ واحدٌ لكل الجداول. */
function initialChunk(table) {
    const avg = table.avgRow > 0 ? table.avgRow : 512;
    return Math.max(1, Math.min(1000, Math.floor(CHUNK_TARGET_BYTES / avg)));
}

/* ───────────────────────── السحب ───────────────────────── */

async function main() {
    const cfg = readEnv(args.env);
    const outDir = args.out ? path.resolve(ROOT, args.out) : path.join(ROOT, 'storage', 'backups');
    fs.mkdirSync(outDir, { recursive: true });

    const mode = args.schema ? 'schema' : args.noFiles ? 'nofiles' : args.only ? 'only' : 'full';
    const db = new Db(cfg);

    say('أتّصل بقاعدة «' + cfg.DB_DATABASE + '» ...');
    const t0 = Date.now();
    await withRetry(() => db.connect(), 'الاتصال', { tries: 5 });
    say('  اتصلت' + (db.ssl ? ' (TLS)' : '') + (db.compress ? ' — مضغوطاً' : '') + ' في ' + elapsed(t0));

    let { tables, views } = await catalog(db);

    if (args.only) {
        const want = new Set(args.only);
        const missing = args.only.filter(n => !tables.some(t => t.name === n));
        if (missing.length) { say('جداولُ غيرُ موجودة: ' + missing.join('، ')); process.exit(1); }
        tables = tables.filter(t => want.has(t.name));
        views = [];
    }
    if (args.noFiles) tables = tables.filter(t => !t.heavy);

    if (args.list) {
        say('');
        say(String(tables.length) + ' جدولاً' + (views.length ? ' و' + views.length + ' منظراً' : '') + ':');
        for (const t of tables) say('  ' + (t.heavy ? '▮' : '·') + ' ' + t.name.padEnd(36) + fmtInt(t.estRows).padStart(9) + ' صفّ ~ ' + fmtMB(t.bytes));
        say('  ▮ = جدولٌ ثقيل (يُسحب آخراً، صفّاً صفّاً إن لزم)');
        db.close();
        return;
    }

    /* ── الاستئنافُ: نسخةٌ ناقصةٌ من تشغيلٍ سابق ── */
    const suffix = mode === 'full' ? '' : '-' + mode;
    let state = null, statePath = null, sqlPath = null;

    if (!args.fresh) {
        const parts = fs.readdirSync(outDir).filter(f => f.endsWith(suffix + '.sql.part')).sort();
        for (const p of parts.reverse()) {
            const sp = path.join(outDir, p + '.state.json');
            if (!fs.existsSync(sp)) continue;
            const st = JSON.parse(fs.readFileSync(sp, 'utf8'));
            if (st.mode !== mode) continue;
            const doneCount = Object.keys(st.done).length;
            const q = 'وُجدت نسخةٌ ناقصةٌ من ' + st.started_at.slice(0, 16) + ' (' + doneCount + ' من ' + st.order.length + ' جدولاً) — أستأنفها؟ [ن/ل] ';
            const ans = args.yes ? 'ن' : await ask(q);
            if (/^(ن|y|نعم|yes)?$/i.test(ans)) { state = st; statePath = sp; sqlPath = path.join(outDir, p); }
            break;
        }
    }

    if (!state) {
        const base = 'zk-' + stamp(now()) + suffix;
        sqlPath   = path.join(outDir, base + '.sql.part');
        statePath = sqlPath + '.state.json';
        state = {
            mode, started_at: local(now()), database: cfg.DB_DATABASE,
            order: tables.map(t => t.name), done: {}, current: null,
            bytes: 0, snapshot_breaks: 0, failed_rows: {},
        };
    } else {
        // الجداولُ كما كانت يومَ بدأت النسخة — لا يُعاد تخطيطُها في المنتصف
        const byName = new Map(tables.map(t => [t.name, t]));
        tables = state.order.map(n => byName.get(n)).filter(Boolean);
        say('أستأنف: ' + path.basename(sqlPath) + ' — أكملَ ' + Object.keys(state.done).length + ' جدولاً');
    }

    const resuming = fs.existsSync(sqlPath) && state.bytes > 0;
    if (resuming) {
        // ما بعد آخر دفعةٍ سجّلها الحالُ قد يكون نصفَ جملة: يُقصّ
        fs.truncateSync(sqlPath, state.bytes);
    }
    const w = new Writer(sqlPath, resuming);
    if (!resuming) { await w.write(header(cfg, mode)); state.bytes = w.bytes; }

    const saveState = () => fs.writeFileSync(statePath, JSON.stringify(state, null, 1));
    saveState();

    let stopping = false;
    const stop = () => {
        if (stopping) process.exit(130);
        stopping = true;
        say('\nأوقف بعد هذه الدفعة — والحالُ محفوظ، وتستأنف بالتشغيل التالي.');
    };
    process.on('SIGINT', stop);
    process.on('SIGTERM', stop);

    /* ── الجداول ── */
    const totalBytesEst = tables.reduce((s, t) => s + t.bytes, 0);
    let doneBytesEst = 0;
    const written = {};   // table → rows written

    for (let i = 0; i < tables.length; i++) {
        const t = tables[i];
        if (state.done[t.name]) { written[t.name] = state.done[t.name].rows; doneBytesEst += t.bytes; continue; }
        if (stopping) break;

        const label = '[' + (i + 1) + '/' + tables.length + '] ' + t.name;
        const tStart = Date.now();
        const before = w.bytes;

        const meta = await withRetry(() => describe(db, t), 'وصف ' + t.name);

        let cur = state.current && state.current.table === t.name ? state.current : null;
        if (!cur) {
            await w.write('\n-- ─────── ' + t.name + ' ───────\n'
                + 'DROP TABLE IF EXISTS ' + id(t.name) + ';\n'
                + meta.createSql + ';\n');
            cur = { table: t.name, lastId: null, offset: 0, rows: 0 };
            state.current = cur; state.bytes = w.bytes; saveState();
        }

        if (!args.schema) {
            await dumpRows(db, w, t, meta, cur, state, saveState, label, () => stopping);
        }

        if (stopping) { state.bytes = w.bytes; saveState(); break; }

        written[t.name] = cur.rows;
        state.done[t.name] = { rows: cur.rows, bytes: w.bytes - before };
        state.current = null; state.bytes = w.bytes; saveState();
        doneBytesEst += t.bytes;

        const pct = totalBytesEst ? Math.round(100 * doneBytesEst / totalBytesEst) : 100;
        say('✓ ' + label.padEnd(44) + fmtInt(cur.rows).padStart(9) + ' صفّ  ' + fmtMB(w.bytes - before).padStart(10) + '  ' + elapsed(tStart).padStart(8) + '   ' + pct + '%');
    }

    if (stopping) {
        await w.close();
        say('تُستأنف بالتشغيل التالي: ' + path.basename(sqlPath));
        db.close();
        process.exit(130);
    }

    /* ── المناظر والقوادح والإجراءات — إن كانت ── */
    const skipped = [];
    for (const v of views) {
        try {
            const [r] = await withRetry(() => db.query('SHOW CREATE VIEW ' + id(v), [], 120000), 'المنظر ' + v);
            // بلا DEFINER: يُستعاد على خادمٍ مستخدمُه غيرُ هذا
            const sql = String(r['Create View']).replace(/\sDEFINER=`[^`]*`@`[^`]*`/i, '');
            await w.write('\nDROP VIEW IF EXISTS ' + id(v) + ';\n' + sql + ';\n');
        } catch (e) { skipped.push('المنظر ' + v + ': ' + e.message); }
    }
    if (!args.only) {
        try {
            const trg = await db.query('SHOW TRIGGERS', [], 120000);
            for (const tr of trg) {
                const [r] = await db.query('SHOW CREATE TRIGGER ' + id(tr.Trigger), [], 120000);
                const sql = String(r['SQL Original Statement']).replace(/\sDEFINER=`[^`]*`@`[^`]*`/i, '');
                await w.write('\nDELIMITER ;;\n' + sql + ';;\nDELIMITER ;\n');
            }
        } catch (e) { skipped.push('القوادح: ' + e.message); }
    }

    await w.write(footer());
    await w.close();

    /* ── العدُّ من الخادم: الشاهدُ على التمام ── */
    say('');
    say('أعدّ الصفوف على الخادم ...');
    const counts = {};
    for (const t of tables) {
        try {
            const [r] = await withRetry(() => db.query('SELECT COUNT(*) AS n FROM ' + id(t.name), [], 300000), 'عدّ ' + t.name);
            counts[t.name] = Number(r.n);
        } catch (e) { counts[t.name] = null; }
    }
    db.close();

    /* ── الناتج النهائي ── */
    let finalPath = sqlPath.replace(/\.sql\.part$/, '.sql');
    fs.renameSync(sqlPath, finalPath);
    fs.unlinkSync(statePath);

    say('أحسب البصمة ...');
    const sha = await sha256(finalPath);
    const sqlBytes = fs.statSync(finalPath).size;

    let gzPath = null, gzSha = null;
    if (args.gzip) {
        say('أضغط ...');
        gzPath = finalPath + '.gz';
        await gzipFile(finalPath, gzPath);
        gzSha = await sha256(gzPath);
        fs.unlinkSync(finalPath);
    }

    const failedRows = state.failed_rows || {};
    const mismatches = [];
    let totalRows = 0;
    for (const t of tables) {
        const wrote = written[t.name] ?? 0;
        totalRows += wrote;
        if (!args.schema && counts[t.name] !== null && counts[t.name] !== wrote) {
            mismatches.push({ table: t.name, wrote, server: counts[t.name] });
        }
    }

    const manifestPath = finalPath.replace(/\.sql$/, '.manifest.txt');
    const lines = [
        '# نسخة حضور البصمة — بيانُ المحتوى',
        '# database: ' + cfg.DB_DATABASE,
        '# host: ' + cfg.DB_HOST,
        '# taken_at: ' + state.started_at,
        '# finished_at: ' + local(now()),
        '# mode: ' + mode,
        '# sql_file: ' + path.basename(finalPath),
        '# sql_bytes: ' + sqlBytes,
        '# sql_sha256: ' + sha.toUpperCase(),
        ...(gzPath ? ['# gz_file: ' + path.basename(gzPath), '# gz_sha256: ' + gzSha.toUpperCase()] : []),
        '# tables: ' + tables.length,
        '# rows: ' + totalRows,
        '# largest_statement_bytes: ' + w.maxStatement,
        '# snapshot_breaks: ' + Math.max(0, db.snapshotBreaks),
        '# tool: scripts/db-tool/pull.js',
        '#',
        '# للتحقّق:  .\\scripts\\db-verify.ps1 -File ' + path.basename(finalPath),
        ...tables.map(t => t.name + '\t' + (counts[t.name] ?? written[t.name] ?? 0)),
    ];
    fs.writeFileSync(manifestPath, '﻿' + lines.join('\n') + '\n', 'utf8');

    // مفتاحُ التطبيق مع النسخة: بدونه لا تُقرأ الجلساتُ ولا ما شُفّر به
    if (cfg.APP_KEY) {
        const keyPath = finalPath.replace(/\.sql$/, '.key.txt');
        fs.writeFileSync(keyPath, '﻿# مفتاحُ تشفير الإنتاج يومَ هذه النسخة — يلزم مع استعادتها لقراءة ما شُفّر به. لا يُرسَل ولا يُنشر.\nAPP_KEY=' + cfg.APP_KEY + '\n', 'utf8');
    }

    /* ── الخلاصة ── */
    say('');
    say('══════════════════════════════════════════════');
    say('تمّ: ' + (gzPath || finalPath));
    say('الحجم: ' + fmtMB(gzPath ? fs.statSync(gzPath).size : sqlBytes) + (gzPath ? ' (قبل الضغط ' + fmtMB(sqlBytes) + ')' : ''));
    say('الجداول: ' + tables.length + '   الصفوف: ' + fmtInt(totalRows) + '   الزمن: ' + elapsed(t0));
    say('البيان: ' + path.basename(manifestPath));
    if (w.maxStatement > 64 * 1048576) say('تنبيه: أكبرُ جملةٍ ' + fmtMB(w.maxStatement) + ' — عند الاستعادة: mysql --max-allowed-packet=1G');

    let ok = true;
    const failedList = Object.entries(failedRows).filter(([, ids]) => ids.length);
    if (failedList.length) {
        ok = false;
        say('');
        say('صفوفٌ تعذّر نقلُها (أضخمُ من أن تمرّ في اتصالٍ واحد):');
        for (const [t, ids] of failedList) say('  · ' + t + ': ' + ids.join(', '));
    }
    if (mismatches.length) {
        say('');
        say('اختلافٌ بين المكتوب وعدّ الخادم بعد السحب:');
        for (const m of mismatches) say('  · ' + m.table + ': كُتب ' + fmtInt(m.wrote) + ' والخادمُ يعدّ ' + fmtInt(m.server));
        say(db.snapshotBreaks > 0
            ? '  انقطع الاتصالُ ' + db.snapshotBreaks + ' مرّةً فتبدّلت لقطةُ القراءة — فما كُتب بعدها أحدثُ ممّا قبلها. تُعاد النسخةُ متى استقرّ الرابط إن أردتها لحظةً واحدة.'
            : '  البرنامجُ قيدَ الاستعمال وقتَ السحب فأُضيف أو حُذف صفٌّ بين الكتابة والعدّ — لا نقصَ في المكتوب.');
    }
    if (skipped.length) { say(''); for (const s of skipped) say('لم يُسحب: ' + s); }
    say(ok && !mismatches.length ? 'النسخةُ تامّة ✓' : (ok ? 'النسخةُ مكتوبةٌ — راجع الاختلاف أعلاه' : 'النسخةُ ناقصة — راجع ما تعذّر أعلاه'));
    say('══════════════════════════════════════════════');

    if (!args.noOpen && process.platform === 'win32') {
        try { spawn('explorer.exe', ['/select,', gzPath || finalPath], { detached: true, stdio: 'ignore' }).unref(); } catch {}
    }
    process.exitCode = ok ? 0 : 1;
}

/** صفوفُ جدولٍ على دفعات، مع الاستئناف من آخر صفٍّ كُتب. */
async function dumpRows(db, w, t, meta, cur, state, saveState, label, stopping) {
    const cols = meta.columns;
    const colList = cols.map(id).join(', ');
    const insertHead = 'INSERT INTO ' + id(t.name) + ' (' + colList + ') VALUES\n';
    let chunk = initialChunk(t);
    let attempt = 0;
    const failed = state.failed_rows[t.name] = state.failed_rows[t.name] || [];

    const orderBy = meta.keyset
        ? ' ORDER BY ' + id(meta.keyset)
        : (meta.pkCols.length ? ' ORDER BY ' + meta.pkCols.map(id).join(', ') : '');

    while (!stopping()) {
        let sql;
        if (meta.keyset) {
            sql = 'SELECT ' + colList + ' FROM ' + id(t.name)
                + (cur.lastId !== null ? ' WHERE ' + id(meta.keyset) + ' > ' + esc(cur.lastId) : '')
                + orderBy + ' LIMIT ' + chunk;
        } else {
            sql = 'SELECT ' + colList + ' FROM ' + id(t.name) + orderBy + ' LIMIT ' + chunk + ' OFFSET ' + cur.offset;
        }

        let rows;
        try {
            rows = await db.query(sql);
            attempt = 0;
        } catch (e) {
            if (!isRetryable(e)) throw e;
            attempt++;
            const smaller = Math.max(1, Math.floor(chunk / 2));

            /* دفعةُ صفٍّ واحدٍ سقطت مراراً: صفٌّ لا يُحتمل. يُسجَّل باسمه
               ويُتجاوَز، فلا يُسقط ما بعده — وتُقال أسماءُ المتعذّر في الخلاصة. */
            if (chunk === 1 && attempt >= 3 && meta.keyset) {
                const nextId = await withRetry(async () => {
                    const r = await db.query('SELECT ' + id(meta.keyset) + ' AS k FROM ' + id(t.name)
                        + (cur.lastId !== null ? ' WHERE ' + id(meta.keyset) + ' > ' + esc(cur.lastId) : '')
                        + orderBy + ' LIMIT 1', [], 120000);
                    return r.length ? r[0].k : null;
                }, 'تحديد الصفّ المتعذّر');
                if (nextId === null) break;
                failed.push(String(nextId));
                status('  ' + label + '  ✗ تُجوِّز الصفّ ' + nextId);
                cur.lastId = nextId; attempt = 0; saveState();
                continue;
            }
            if (attempt > MAX_RETRIES) throw e;

            status('  ' + label + '  ↻ انقطاع (' + attempt + ') — أُعيد الاتصال، الدفعة ' + smaller + ' صفّ');
            chunk = smaller;
            await sleep(Math.min(30000, 2000 * attempt));
            db.conn = null;
            try { await db.connect(); } catch (ce) { if (!isRetryable(ce)) throw ce; }
            continue;
        }

        if (!rows.length) break;

        // تُجمَّع في جملٍ لا تتجاوز حدّاً — والصفُّ الأضخمُ منه يُكتب وحدَه
        let stmt = '', stmtRows = 0;
        const flush = async () => {
            if (!stmtRows) return;
            const text = insertHead + stmt + ';\n';
            w.maxStatement = Math.max(w.maxStatement, Buffer.byteLength(text, 'utf8'));
            await w.write(text);
            stmt = ''; stmtRows = 0;
        };
        for (const row of rows) {
            const vals = '(' + cols.map(c => esc(row[c])).join(',') + ')';
            if (stmtRows && (stmt.length + vals.length > MAX_STATEMENT_BYTES || stmtRows >= 1000)) await flush();
            stmt += (stmtRows ? ',\n' : '') + vals;
            stmtRows++;
        }
        await flush();

        cur.rows += rows.length;
        if (meta.keyset) cur.lastId = rows[rows.length - 1][meta.keyset];
        else cur.offset += rows.length;
        state.bytes = w.bytes;
        saveState();

        status('  ' + label + '  ' + fmtInt(cur.rows) + (t.estRows ? ' / ~' + fmtInt(t.estRows) : '') + ' صفّ   ' + fmtMB(w.bytes));

        if (rows.length < chunk) break;
        // الرابطُ مستقرّ: الدفعةُ تعود تكبر إلى حدّها
        if (chunk < initialChunk(t)) chunk = Math.min(initialChunk(t), chunk * 2);
    }
}

async function withRetry(fn, what, { tries = MAX_RETRIES } = {}) {
    let last;
    for (let i = 1; i <= tries; i++) {
        try { return await fn(); } catch (e) {
            last = e;
            if (!isRetryable(e) || i === tries) break;
            status('  ↻ ' + what + ' — انقطاع (' + i + ')، أُعيد ...');
            await sleep(Math.min(30000, 2000 * i));
        }
    }
    throw last;
}

function sha256(file) {
    return new Promise((res, rej) => {
        const h = crypto.createHash('sha256');
        fs.createReadStream(file).on('data', d => h.update(d)).on('end', () => res(h.digest('hex'))).on('error', rej);
    });
}

function gzipFile(src, dst) {
    return new Promise((res, rej) => {
        fs.createReadStream(src).pipe(zlib.createGzip({ level: 6 })).pipe(fs.createWriteStream(dst))
            .on('finish', res).on('error', rej);
    });
}

// يُشغَّل برنامجاً، ويُستورَد لاختبار الاتصال وإعادته بلا سحب
if (require.main !== module) {
    module.exports = { Db, withRetry, isRetryable };
} else main().catch(e => {
    say('');
    say('فشل: ' + (e.message || e));
    if (/ER_ACCESS_DENIED/.test(e.code)) say('  بياناتُ الاتصال في .env.production ناقصةٌ أو خاطئة.');
    else if (/ENOTFOUND|EAI_AGAIN/.test(e.code)) say('  لم يُعثر على الخادم — تحقّق من DB_HOST ومن الإنترنت.');
    else if (/ETIMEDOUT|ECONNREFUSED/.test(e.code)) say('  لا يصل إلى الخادم — عنوانُ جهازك قد لا يكون مسموحاً به في إعدادات قاعدة Laravel Cloud.');
    else if (isRetryable(e)) say('  انقطع الرابط بعد كل الإعادات — أعد التشغيل وسيُستأنف من حيث وقف.');
    process.exit(1);
});
