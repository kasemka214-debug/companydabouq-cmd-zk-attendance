<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'حضور البصمة') — {{ config('app.name') }}</title>

    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --brand: #0d5c63;
            --brand-soft: #e8f2f2;
            --brand-deep: #083b40;
            --side: 15.5rem;
        }
        body { font-family: 'Cairo', system-ui, sans-serif; background: #f6f7f9; }
        .navbar-brand, .nav-link { font-weight: 600; }
        .navbar { background: var(--brand) !important; }

        /* ═══ الشريطُ الجانبيّ ══════════════════════════════════════════
         *
         * وكان شريطاً أفقياً، فلمّا بلغت الأبوابُ ثلاثةَ عشرَ التفّ سطرين
         * على شاشةٍ عادية، فصار البحثُ عن بابٍ مسحاً بالعين في كل مرّة.
         * والجانبيُّ يتّسع لها كلِّها مقسَّمةً معنونة، ويترك عرضَ الشاشة
         * للجداول — وهي ستّةٌ وثلاثون عموداً في الكشف الشهريّ.
         *
         * والجهةُ يمينٌ لا يسار: الصفحةُ `dir="rtl"`، فمبدأُ القراءة
         * اليمينُ، والقائمةُ تُقرأ قبل المحتوى.
         */
        .side {
            position: fixed;
            top: 0; right: 0; bottom: 0;
            width: var(--side);
            background: var(--brand);
            display: flex;
            flex-direction: column;
            z-index: 1040;
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .side-brand {
            padding: .9rem 1rem;
            font-weight: 700;
            color: #fff;
            text-decoration: none;
            border-bottom: 1px solid rgba(255,255,255,.14);
        }

        .side-title {
            font-size: .68rem;
            color: rgba(255,255,255,.55);
            padding: .85rem 1rem .25rem;
            font-weight: 700;
        }

        .side-link {
            display: flex;
            align-items: center;
            gap: .55rem;
            padding: .42rem 1rem;
            color: rgba(255,255,255,.85);
            text-decoration: none;
            font-size: .88rem;
            font-weight: 600;
            border-right: 3px solid transparent;
        }
        .side-link:hover { background: rgba(255,255,255,.08); color: #fff; }

        /*
         * والبابُ المفتوحُ يُعلَّم بخلفيةٍ وشريطٍ معاً لا بلونِ خطٍّ وحدَه —
         * فلونُ الخطّ على خلفيةٍ داكنةٍ لا يكاد يُرى.
         */
        .side-link.on {
            background: var(--brand-deep);
            color: #fff;
            border-right-color: #fff;
        }

        .side-foot {
            margin-top: auto;
            padding: .75rem 1rem;
            border-top: 1px solid rgba(255,255,255,.14);
        }

        /* المحتوى ينزاح بعرض الشريط — وعلى الهاتف لا شريطَ فلا انزياح */
        .shell { margin-right: var(--side); }

        .side-veil { display: none; }

        @media (max-width: 991.98px) {
            .side { transform: translateX(100%); transition: transform .2s ease; }
            .side.open { transform: none; box-shadow: -8px 0 24px rgba(0,0,0,.25); }
            .shell { margin-right: 0; }

            /* ستارةٌ تُغلق القائمةَ بلمسةٍ خارجها */
            .side.open ~ .side-veil {
                display: block;
                position: fixed; inset: 0;
                background: rgba(0,0,0,.35);
                z-index: 1035;
            }
        }
        .card { border: 0; box-shadow: 0 1px 3px rgba(16,24,40,.08); }
        .table > :not(caption) > * > * { padding: .55rem .6rem; }
        .table thead th { background: var(--brand-soft); font-weight: 700; white-space: nowrap; }
        .num { font-variant-numeric: tabular-nums; direction: ltr; text-align: center; }
        .dot { width: .6rem; height: .6rem; border-radius: 50%; display: inline-block; }
        .dot-on { background: #16a34a; } .dot-off { background: #dc2626; }
        .stat { font-size: 1.65rem; font-weight: 700; line-height: 1; }
        .mono { font-family: ui-monospace, Consolas, monospace; direction: ltr; font-size: .85rem; }

        /* أزرارُ التنقّل صغيرةٌ ملتصقة — سطرٌ في ذيل الجدول لا كتلةٌ تزاحمه */
        .pagination {
            --bs-pagination-padding-y: .12rem;
            --bs-pagination-padding-x: .5rem;
            --bs-pagination-font-size: .8rem;
            --bs-pagination-border-radius: .25rem;
            --bs-pagination-active-bg: var(--brand);
            --bs-pagination-active-border-color: var(--brand);
            --bs-pagination-color: var(--brand);
            margin-bottom: 0;
        }
        .pagination .page-link { min-width: 1.9rem; text-align: center; }
        nav[role="navigation"] p { font-size: .78rem; margin-bottom: 0; }

        /* ترويسةُ الورقة — لا تُرى على الشاشة */
        .print-head { display: none; }

        /* ═══ الطباعة ═══════════════════════════════════════════════════
         *
         * وقواعدُها هنا لا في كل صفحة: كانت كلُّ شاشةٍ تُطبع على حِدة
         * فتُصلَح واحدةٌ وتبقى البقيةُ على عيبها، ويُعاد الطلبُ مرّةً بعد
         * مرّة. فما يصلح لكلّ صفحةٍ موضعُه القالبُ الجامع، ولا يبقى في
         * الصفحة إلا ما يخصّها: قياسُ الورقة ومقاسُ خطّها.
         */
        @media print {

            /*
             * **العلّةُ الأولى وأخطرُها.** `table-responsive` صندوقٌ
             * يُمرَّر أفقياً على الشاشة، وفي الورقة لا تمرير — فيُقصّ ما
             * جاوز عرضَه ويُطبع نصفُ الجدول بلا إنذار. وهذا ما كان: تظهر
             * أيامُ الشهر إلى السابع عشر ويُبتر الباقي.
             */
            .table-responsive { overflow: visible !important; max-width: none !important; }

            /*
             * والألوانُ تُطبع صراحةً — وإلا ضاع تظليلُ الجمعة والمجاميع،
             * فالمتصفّحُ يُطفئ الخلفياتِ افتراضاً.
             */
            *, *::before, *::after {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body { background: #fff !important; font-size: 10pt; }

            /*
             * والشريطُ الجانبيُّ يُخفى **وينزاح المحتوى معه**.
             *
             * فإخفاؤه وحدَه يُبقي `margin-right` على الغلاف، فتخرج الورقةُ
             * بهامشٍ فارغٍ بعرض خمسَ عشرةَ وحدة — يُقصّ من الجدول بقدره،
             * وهو ما يُفقد أعمدةَ آخرِ الشهر في الكشف الشهريّ.
             */
            .no-print, .navbar, .side, .side-veil,
            .btn-close, .pagination, nav[role="navigation"] { display: none !important; }

            .shell { margin-right: 0 !important; }

            /* البطاقاتُ إطاراتٌ على الشاشة، وعلى الورق حبرٌ ضائع */
            .card { border: 0 !important; box-shadow: none !important; margin-bottom: 3mm !important; }
            .card-body { padding: 0 !important; }
            .card-header {
                background: #fff !important;
                border: 0 !important;
                border-bottom: 1pt solid #000 !important;
                padding: 0 0 1mm 0 !important;
                break-after: avoid; page-break-after: avoid;
            }

            table { width: 100% !important; border-collapse: collapse !important; }
            thead { display: table-header-group; }          /* الرأسُ يتكرّر في كل ورقة */
            tr, img { break-inside: avoid; page-break-inside: avoid; }

            a { text-decoration: none !important; color: #000 !important; }

            .alert { border: 1pt solid #666 !important; background: #fff !important; padding: 1.5mm 2mm !important; }

            .print-head {
                display: block !important;
                border-bottom: 1.5pt solid #000;
                margin-bottom: 2.5mm;
                padding-bottom: 1.5mm;
            }
        }
    </style>
    @stack('head')
</head>
<body>

@auth
{{--
    البابُ يُعرض بمفتاحه المكتوب في Menu، والمتحكّمُ يحرسه بالمفتاح نفسِه.
    فلا يقع بابٌ يُرى ولا يُفتح. والقسمةُ في Menu::grouped، وقسمٌ فرغت
    أبوابُه يسقط عنوانُه معها.
--}}
<aside class="side no-print" id="side">
    <a class="side-brand" href="{{ route('dashboard') }}">
        <i class="bi bi-fingerprint"></i> {{ config('app.name') }}
    </a>

    @php
        /*
         * القسمةُ تُحسب هنا لا داخل `@foreach`: وسيطُ الدالّة دالّةٌ
         * سهمية، وفاحصُ القوالب يُصرّف ما بين القوسين كما هو فيقف عندها.
         * وإخراجُها أوضحُ على كل حال.
         */
        $menu = \App\Support\Menu::grouped(fn ($key) => auth()->user()->can($key));
    @endphp

    @foreach ($menu as $section)
        @if ($section['title'])
            <div class="side-title">{{ $section['title'] }}</div>
        @endif

        @foreach ($section['items'] as $item)
            <a class="side-link {{ request()->routeIs($item['route']) ? 'on' : '' }}"
               href="{{ route($item['route']) }}">
                <i class="bi bi-{{ $item['icon'] }}"></i>
                <span>{{ $item['label'] }}</span>

                {{--
                    جرسُ أحداث الساعة ملصقٌ ببابه لا معلَّقٌ في زاوية:
                    فالرقمُ يقول «هنا»، والضغطُ عليه يفتح ما يقوله.

                    ويُعدّ ما لم يُقَرَّ به لا ما هو مفتوح — والفرقُ يُسقط
                    أخطرَ الحالات: من قدّم الساعةَ وبصم ثم أعادها أُغلق
                    حدثُه في دقائق، فلو عُدَّ المفتوحُ وحدَه لَما رآه أحد.
                --}}
                @if ($item['route'] === 'clock.index' && ($clockAlerts ?? 0) > 0)
                    <span class="badge bg-warning text-dark num ms-auto">{{ $clockAlerts }}</span>
                @endif
            </a>
        @endforeach
    @endforeach

    <div class="side-foot">
        <form method="POST" action="{{ route('logout') }}" class="d-flex align-items-center gap-2">
            @csrf
            <span class="text-white-50 small text-truncate">{{ auth()->user()->name }}</span>
            <button class="btn btn-sm btn-outline-light ms-auto">خروج</button>
        </form>
    </div>
</aside>

<div class="side-veil" onclick="document.getElementById('side').classList.remove('open')"></div>
@endauth

<div class="shell">

@auth
    {{-- شريطٌ علويٌّ للهاتف وحدَه — مفتاحُ القائمة واسمُ الشاشة --}}
    <nav class="navbar navbar-dark d-lg-none no-print">
        <div class="container-fluid">
            <button class="btn btn-sm btn-outline-light"
                    onclick="document.getElementById('side').classList.toggle('open')">
                <i class="bi bi-list"></i>
            </button>
            <span class="navbar-brand mb-0">@yield('title', 'حضور البصمة')</span>
        </div>
    </nav>
@endauth

<main class="container-fluid py-3">

    {{--
        ترويسةُ الورقة — تظهر في الطباعة وحدَها.

        فورقةٌ تخرج من الطابعة بلا اسمٍ ولا تاريخٍ لا يُعرف بعد شهرٍ ممّ
        هي ولا متى طُبعت، وتُوضع في ملفٍّ فتصير شاهداً على غير ما فيه.
    --}}
    <div class="print-head d-flex justify-content-between align-items-end">
        <div>
            <strong>@yield('title', 'كشف')</strong>
            @hasSection('print_note')<span> — @yield('print_note')</span>@endif
        </div>
        <div style="font-size: 8pt">
            {{ config('app.name') }} ·
            طُبع {{ \App\Support\Clock::stamp(now(), 'Y-m-d H:i') }}
        </div>
    </div>

    @if (session('ok'))
        <div class="alert alert-success alert-dismissible no-print">
            {{ session('ok') }}
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session('err'))
        <div class="alert alert-danger alert-dismissible no-print">
            {{ session('err') }}
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger no-print">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

</div>{{-- shell --}}

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
