@extends('layouts.app')
@section('title', 'كشف الأيام')

@php
    use App\Services\DayBuilder;
    use App\Support\AbsenceJudge;
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">كشف الأيام</h1>
    @can('page.print')
        <div class="no-print">
            <a href="{{ route('days.export', request()->query()) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-file-earmark-spreadsheet"></i> تصدير
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer"></i> طباعة
            </button>
        </div>
    @endcan
</div>

<div class="card mb-3 no-print"><div class="card-body">
    <form class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small">من</label>
            <input type="date" name="from" value="{{ $f['from'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">إلى</label>
            <input type="date" name="to" value="{{ $f['to'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small">الموقع</label>
            <select name="site_id" class="form-select form-select-sm">
                <option value="">كل المواقع</option>
                @foreach ($sites as $s)
                    <option value="{{ $s->id }}" @selected($f['site_id'] === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">رقم الجهاز</label>
            <input name="pin" value="{{ $f['pin'] }}" class="form-control form-control-sm" placeholder="مثلاً 14">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">اعرض</label>
            <select name="only" class="form-select form-select-sm">
                {{--
                    ضابطٌ واحدٌ لا مربّعُ اختيارٍ إلى جانبه: «اعرض» سؤالٌ
                    واحد، وجوابُه واحد. ومربّعٌ يُضاف يصنع حالاتٍ لا معنى
                    لها («غيرُ المربوطين ومعهم من لم يبصم» — ومن لم يبصم
                    مربوطٌ بالضرورة).
                --}}
                <option value="">الكل — ومعه من لم يبصم</option>
                <option value="present" @selected($f['only'] === 'present')>الحضور فقط</option>
                <option value="absent" @selected($f['only'] === 'absent')>من لم يبصم وحدَهم</option>
                <option value="partial" @selected($f['only'] === 'partial')>الأيام الناقصة</option>
                <option value="unlinked" @selected($f['only'] === 'unlinked')>غير المربوطين</option>
            </select>
        </div>
        <div class="col-6 col-md-1">
            <button class="btn btn-sm btn-primary w-100" style="background: var(--brand); border: 0;">عرض</button>
        </div>
    </form>
</div></div>

{{--
    تنبيهٌ يُطبع ولا يُحجب.

    فالورقةُ والملفُّ هما ما يُحفظ ويُقرَّر منه الخصمُ بعد شهر، وقارئٌ لا
    يجد فيهما سطرَ «لم يبصم» يظنّ أن أحداً لم يغب — وإنما لم يُحسب.
    وسكوتٌ يُقرأ براءةً أسوأُ من تنبيهٍ يُقرأ. ولذلك لا `no-print` عليه.
--}}
@if ($notice)
    <div class="alert alert-info border-0 py-2 small">
        <i class="bi bi-info-circle"></i> {{ $notice }}
    </div>
@endif

<div class="mb-2 small text-muted">
    {{ number_format($days->count()) }} سطراً · {{ number_format($people) }} شخصاً ·
    <span class="text-success">{{ number_format($tally['complete']) }} تامّ</span> ·
    <span class="text-warning">{{ number_format($tally['partial']) }} ناقص</span>

    {{-- والأحمرُ لا يُكتب إلا إن وُجد: صفرٌ أحمرُ يُقلق بلا سبب --}}
    @if ($tally['absent'])
        · <span class="text-danger fw-bold">{{ number_format($tally['absent']) }} لم يبصم</span>
    @endif
    @if ($tally['pending'])
        · <span>{{ number_format($tally['pending']) }} لم يبصم بعد</span>
    @endif
    @if ($tally['elsewhere'])
        · <span class="text-info-emphasis">{{ number_format($tally['elsewhere']) }} في موقعٍ آخر</span>
    @endif
    @if ($tally['nodata'])
        · <span>{{ number_format($tally['nodata']) }} بلا بيانات</span>
    @endif
    @if ($tally['on_leave'])
        · <span>{{ number_format($tally['on_leave']) }} في إجازة</span>
    @endif
    @if ($tally['awaiting'])
        · <span>{{ number_format($tally['awaiting']) }} نُقلوا ولم يبصموا</span>
    @endif

    · <span class="text-muted">{{ $calendar['working'] }} يومَ دوام في المدّة</span>
</div>

{{--
    كلُّ موقعٍ قسمٌ قائمٌ بذاته، برأسه ومجموعه.

    والقسمةُ ليست ترتيباً فحسب: مَن يراجع الحضورَ يراجعه موقعاً موقعاً —
    فكشفٌ مختلطٌ يُجبره على غربلة أسماء موقعٍ من بين أسماء أربعة. ورأسُ
    القسم يحمل مجموعَه فلا يُعدّ بيده.
--}}
@forelse ($groups as $g)
    <div class="card mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0">
                <i class="bi bi-geo-alt"></i> {{ $g['name'] }}
            </h2>
            <div class="small text-muted">
                {{ number_format($g['rows']->count()) }} سطراً ·
                {{ number_format($g['people']) }} شخصاً ·
                <span class="text-success">{{ number_format($g['complete']) }} تامّ</span>
                @if ($g['partial'])
                    · <span class="text-warning">{{ number_format($g['partial']) }} ناقص</span>
                @endif
                @if ($g['absent'])
                    · <span class="text-danger fw-bold">{{ number_format($g['absent']) }} لم يبصم</span>
                @endif
                @if ($g['pending'])
                    · <span>{{ number_format($g['pending']) }} لم يبصم بعد</span>
                @endif
                @if ($g['elsewhere'])
                    · <span class="text-info-emphasis">{{ number_format($g['elsewhere']) }} في موقعٍ آخر</span>
                @endif
                @if ($g['nodata'])
                    · <span>{{ number_format($g['nodata']) }} بلا بيانات</span>
                @endif
                @if ($g['on_leave'])
                    · <span>{{ number_format($g['on_leave']) }} في إجازة</span>
                @endif
                @if ($g['awaiting'])
                    · <span>{{ number_format($g['awaiting']) }} نُقلوا ولم يبصموا</span>
                @endif

                {{--
                    ومن انتهت خدمتُه يُعَدّ ولا تُطبع سطورُه: من تركَ في
                    يونيو له في كشف الشهر اثنان وعشرون سطراً، وخمسون
                    تركوا يصيرون ألفاً ومئة.
                --}}
                @if ($g['site_id'] && $g['ended'])
                    ·
                    @can('employees.view')
                        <a href="{{ route('employees.index', ['site_id' => $g['site_id'], 'status' => 'left']) }}"
                           class="text-decoration-none text-muted">
                            {{ number_format($g['ended']) }} انتهت خدمتُهم
                        </a>
                    @else
                        <span>{{ number_format($g['ended']) }} انتهت خدمتُهم</span>
                    @endcan
                @endif
                @if ($g['unlinked'])
                    · <span class="text-warning">{{ number_format($g['unlinked']) }} بلا اسم</span>
                @endif

                {{--
                    الأرقامُ الراكدة — ولا تُطوى صامتة.

                    فجدولُ الربط ليس قائمةَ العاملين: هو قائمةُ كلِّ من
                    سُجّلت بصمتُه في جهازٍ يوماً، والأسماءُ تصل من الأجهزة
                    مفعَّلةً تلقائياً ولا يُقلّمها أحد. فمن لم تصل منه بصمةٌ
                    منذ شهرين لا يُسأل عن غياب — وإلا خرج في كشف الشهر ألفا
                    سطرٍ أحمرَ بأسماء من تركوا العمل في الصيف.

                    ويُقال العددُ ليُعلم أن ثَمّ من استُثني، فلا يُقرأ
                    الفراغُ براءةً.
                --}}
                @if ($g['site_id'] && $g['dormant'])
                    ·
                    @can('employees.view')
                        <a href="{{ route('employees.index', ['site_id' => $g['site_id'], 'status' => 'dormant']) }}"
                           class="text-decoration-none text-muted"
                           title="أرقامٌ لم تصل منها بصمةٌ منذ مدّة ولم تُسجَّل حالتُها — سجّل نهايةَ خدمتهم أو إجازتهم">
                            {{ number_format($g['dormant']) }} لم تُسجَّل حالتُهم
                        </a>
                    @else
                        <span title="أرقامٌ مربوطةٌ لم تصل منها بصمةٌ منذ مدّة">
                            {{ number_format($g['dormant']) }} رقماً راكداً
                        </span>
                    @endcan
                @endif
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead><tr>
                        <th>اليوم</th><th>الموظف</th><th>المسمّى</th><th>الرقم</th>
                        <th>أول بصمة</th><th>آخر بصمة</th><th>المدة</th><th>البصمات</th><th>الحالة</th>
                        {{-- الخليةُ تتبع العمود لا الزرّ: الرأسُ يظهر فتُفتح الخليةُ دائماً --}}
                        @can('photos.view')<th class="no-print"></th>@endcan
                    </tr></thead>
                    <tbody>
                    @foreach ($g['rows'] as $d)
                        @php $missing = AbsenceJudge::synthetic($d['kind']); @endphp

                        {{--
                            صفُّ من لم يبصم يُعلَّم في الصفّ نفسِه لا في
                            شارته وحدَها: الورقةُ تُطبع أبيضَ وأسودَ،
                            فتختفي الألوان. والشريطُ الأيمنُ يبقى.
                        --}}
                        @php
                            $rowClass = match (true) {
                                ! $missing                                => '',
                                $d['kind'] === AbsenceJudge::ABSENT       => 'row-absent',
                                $d['kind'] === AbsenceJudge::ON_LEAVE     => 'row-leave',
                                $d['kind'] === AbsenceJudge::AWAITING     => 'row-unknown',
                                default                                   => 'row-unknown',
                            };
                        @endphp

                        <tr class="{{ $rowClass }}">
                            <td class="num">{{ $d['work_date'] }}</td>
                            <td>
                                @if ($d['employee'])
                                    {{ $d['employee'] }}
                                    @if ($d['employee_ref'])<span class="text-muted small">({{ $d['employee_ref'] }})</span>@endif
                                @else
                                    <span class="text-warning"><i class="bi bi-question-circle"></i> غير مربوط</span>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $d['job_title'] ?: '—' }}</td>
                            <td class="num mono">{{ $d['device_pin'] }}</td>
                            <td class="num">{{ $d['first_time'] }}</td>
                            <td class="num">{{ $d['last_time'] }}</td>
                            <td class="num">{{ DayBuilder::duration($d['minutes']) }}</td>

                            {{-- صفرُ بصماتٍ شرطةٌ لا صفر — فالصفرُ يُقرأ قياساً --}}
                            <td class="num">{{ $missing ? '—' : $d['punches'] }}</td>

                            <td>
                                <span class="badge {{ AbsenceJudge::badge($d['kind']) }}">
                                    {{ AbsenceJudge::label($d['kind']) }}
                                </span>

                                {{-- ويُسمّى الموقعُ لا رقمُه: «بصم في الجهراء» --}}
                                @if ($d['at_site'])
                                    <span class="d-block text-muted" style="font-size:.7rem">{{ $d['at_site'] }}</span>
                                @endif

                                {{--
                                    ومدّةُ الإجازة تحت وسمِها — فسطرٌ يقول
                                    «في إجازة» بلا مدّةٍ لا يُراجَع. وبها
                                    يُرى الخطأُ: إجازةٌ تنتهي أمسِ ورجلٌ
                                    لم يبصم اليومَ تقول إن عليه سؤالاً.
                                --}}
                                @if ($d['moved'] ?? null)
                                    <span class="d-block text-muted num" style="font-size:.7rem">
                                        وصل {{ $d['moved']['since'] }}
                                    </span>
                                @endif

                                @if ($d['leave'] ?? null)
                                    <span class="d-block text-muted num" style="font-size:.7rem">
                                        {{ \App\Support\Employment::kind($d['leave']['kind']) }}
                                        · إلى {{ $d['leave']['to'] }}
                                    </span>
                                @endif
                            </td>

                            @can('photos.view')
                                @php
                                    /* ومن لم يبصم لا صورةَ له — فلا يُسأل عنها أصلاً */
                                    $shot = $missing
                                        ? null
                                        : ($photos[$d['site_id'].'|'.$d['device_pin'].'|'.$d['work_date']] ?? null);
                                @endphp
                                <td class="no-print text-center">
                                    {{--
                                        الزرُّ لا يظهر إلا لمن له صورة. وزرٌّ يُضغط
                                        فلا يفتح شيئاً يُعلّم المستخدمَ ألّا يثق
                                        بالأزرار — وقد وقعنا في مثله مرّة.
                                    --}}
                                    @if ($shot)
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                data-bs-toggle="modal" data-bs-target="#shot"
                                                data-src="{{ route('photos.show', $shot['first']) }}"
                                                data-who="{{ $d['employee'] ?? 'رقم '.$d['device_pin'] }}"
                                                data-when="{{ $d['work_date'] }} · {{ $d['first_time'] }}"
                                                data-all="{{ route('photos.index', [
                                                    'from' => $d['work_date'], 'to' => $d['work_date'],
                                                    'site_id' => $d['site_id'], 'pin' => $d['device_pin'],
                                                ]) }}"
                                                data-count="{{ $shot['total'] }}"
                                                title="صورةُ البصمة">
                                            <i class="bi bi-camera"></i>
                                            @if ($shot['total'] > 1)<span class="small">{{ $shot['total'] }}</span>@endif
                                        </button>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@empty
    {{--
        والفراغُ يقول سببَه.

        فـ«لا بصماتٍ في هذه المدّة» تُقرأ براءةً — أي أن أحداً لم يغب.
        وقد يكون الفراغُ لأن المدّةَ جمعةٌ لا دوامَ فيها، أو لأن حسابَ
        الغياب حُجب لطول المدّة، أو لأن المرشِّحَ نفسَه يطلب شيئاً غيرَ
        موجود. وثلاثتُها غيرُ «لم يغب أحد».
    --}}
    <div class="card"><div class="card-body text-center text-muted py-5">
        @if ($calendar['working'] === 0)
            لا يومَ دوامٍ في هذه المدّة — كلُّها جمعةٌ أو عطلة.
        @elseif ($notice)
            لا بصماتٍ في هذه المدّة. <strong>وحسابُ من لم يبصم لم يُجرَ</strong> — انظر التنبيهَ أعلاه.
        @elseif ($f['only'] === 'absent')
            لم يتخلّف أحدٌ في هذه المدّة — كلُّ مربوطٍ غيرِ راكدٍ بصم.
        @elseif ($f['only'] !== '')
            لا سطرَ يُطابق هذا المرشِّح.
        @else
            لا بصماتٍ في هذه المدّة.
        @endif
    </div></div>
@endforelse

@can('photos.view')
    {{--
        نافذةٌ واحدةٌ تُعاد لكل صف — لا نافذةٌ لكل سطر.

        فكشفُ شهرٍ آلافُ الأسطر، ونافذةٌ لكلٍّ منها تُثقل الصفحةَ بآلاف
        العناصر المخفيّة. والصورةُ تُطلب بمسارها عند الفتح، فلا تُحمَّل
        صورةٌ لم يطلبها أحد.
    --}}
    <div class="modal fade" id="shot" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <div>
                        <div class="fw-bold" id="shot-who"></div>
                        <div class="small text-muted num" id="shot-when"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-2">
                    <img id="shot-img" src="" alt="صورة البصمة" class="img-fluid rounded" style="max-height: 70vh;">
                </div>
                <div class="modal-footer py-1">
                    <a id="shot-all" href="#" class="small">كلُّ صور هذا اليوم</a>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.getElementById('shot')?.addEventListener('show.bs.modal', function (e) {
            const b = e.relatedTarget;
            if (!b) return;

            document.getElementById('shot-img').src  = b.dataset.src;
            document.getElementById('shot-who').textContent  = b.dataset.who;
            document.getElementById('shot-when').textContent = b.dataset.when;

            const all = document.getElementById('shot-all');
            all.href = b.dataset.all;
            all.textContent = b.dataset.count > 1
                ? 'كلُّ صور هذا اليوم (' + b.dataset.count + ')'
                : 'افتحها في شاشة الصور';
        });

        /* إفراغُ المصدر عند الإغلاق — وإلا بقيت الصورةُ في الذاكرة */
        document.getElementById('shot')?.addEventListener('hidden.bs.modal', function () {
            document.getElementById('shot-img').src = '';
        });
    </script>
    @endpush
@endcan

@endsection

@push('head')
<style>
    /*
     * صفُّ من لم يبصم يُعرَف بشريطٍ في حافّته لا باللون وحدَه.
     *
     * فالورقةُ تخرج من الطابعة بالأبيض والأسود في أكثر المكاتب، فتستوي
     * الألوانُ كلُّها رماديّاً — ويصير السطرُ الأحمرُ كالأخضر. والشريطُ
     * يبقى حدّاً أسودَ مرئياً.
     *
     * والأحمرُ للاتّهام وحدَه: «لم يبصم» في يومٍ محسومٍ وموقعٍ مغطّى.
     * وما سواه — جارٍ، أو في موقعٍ آخر، أو لا بيانات — رماديٌّ يقول
     * «لا نعرف» لا «أدانُه».
     */
    tr.row-absent  > td:first-child { border-right: 3px solid var(--bs-danger, #dc3545) !important; }
    tr.row-unknown > td:first-child { border-right: 3px solid var(--bs-secondary, #6c757d) !important; }
    tr.row-absent  { background: #fff5f5; }
    tr.row-unknown { background: #f8f9fa; }

    /*
     * والإجازةُ حدٌّ **مقطَّع** لا لونٌ سادس: الأحمرُ والأصفرُ والأزرقُ
     * والرماديُّ مشغولةٌ كلُّها، ولونٌ سادسٌ لا يُفرَّق على ورقةٍ مصوَّرة.
     * ونمطُ الحدّ لا يُسقطه طابعٌ أبيضُ وأسود.
     */
    tr.row-leave   { background: #f8f9fa; }
    tr.row-leave   > td:first-child { border-right: 3px dashed var(--bs-secondary, #6c757d) !important; }

    /* كلُّ موقعٍ يبدأ صفحةً جديدة في الطباعة، ورأسُه يتكرّر إن طال جدولُه */
    @media print {
        /* الألوانُ تُطبع كما هي، وإلا استوى الصفُّ الأحمرُ بغيره */
        tr.row-absent, tr.row-unknown, tr.row-leave {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        tr.row-absent  > td:first-child { border-right: 2pt solid #000 !important; }
        tr.row-unknown > td:first-child { border-right: 2pt solid #999 !important; }
        tr.row-leave   > td:first-child { border-right: 2pt dashed #000 !important; }
        tr.row-leave   { background: #f5f5f5 !important; }
        tr.row-absent  { background: #fdeaea !important; }
        tr.row-unknown { background: #f1f1f1 !important; }

        .card { break-inside: auto; page-break-inside: auto; }
        .card + .card { break-before: page; page-break-before: always; }
        .card-header { break-after: avoid; page-break-after: avoid; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; page-break-inside: avoid; }
    }
</style>
@endpush
