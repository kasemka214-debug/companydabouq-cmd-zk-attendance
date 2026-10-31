@extends('layouts.app')
@section('title', 'ربط الأرقام بالأسماء')

@php use App\Support\Clock; @endphp

@section('content')

<h1 class="h5 mb-3">ربط الأرقام بالأسماء</h1>

@if ($orphans->count())
    <div class="card mb-3 border-warning"><div class="card-body">
        <h2 class="h6 text-warning-emphasis">
            <i class="bi bi-exclamation-triangle"></i>
            {{ $orphans->count() }} رقماً يبصم بلا اسم
        </h2>
        <p class="small text-muted">
            هذه أرقامٌ وصلت منها بصمات ولا ربطَ لها <strong>في موقعها</strong>.
            وأصحابُها يحضرون كلَّ يومٍ ويظهرون في الكشوف «غير مربوطين».
        </p>

        @php $moved = $orphans->filter(fn ($o) => $o->elsewhere); @endphp

        @if ($moved->count())
            {{--
                السببُ الأشيع، ولا يُخمَّن: الرقمُ مربوطٌ باسمٍ في موقعٍ
                آخر. ويقع حين يُنقل جهازٌ بين المواقع — فبصماتُه تنتقل معه
                وأسماؤه تبقى حيث كانت.
            --}}
            <div class="alert alert-info py-2 small mb-2">
                <i class="bi bi-info-circle"></i>
                <strong>{{ $moved->count() }}</strong> منها اسمُه مربوطٌ في <strong>موقعٍ آخر</strong> —
                وهذا يقع حين يُنقل جهازٌ بين المواقع: بصماتُه تنتقل معه وأسماؤه لا تنتقل.
                واضغط «انسخ الاسم» ليُربط هنا أيضاً.
            </div>
        @endif

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>الموقع</th><th>الرقم</th><th>البصمات</th><th>آخر بصمة</th>
                    <th>الاسمُ في موقعٍ آخر</th>
                    @can('pins.create')<th class="no-print"></th>@endcan
                </tr></thead>
                <tbody>
                @foreach ($orphans->take(60) as $o)
                    <tr>
                        <td>{{ $sites->firstWhere('id', $o->site_id)?->name ?? '— بلا موقع —' }}</td>
                        <td class="num mono fw-bold">{{ $o->device_pin }}</td>
                        <td class="num">{{ number_format($o->total) }}</td>
                        <td class="num small">{{ Clock::stamp($o->last_at) }}</td>
                        <td class="small">
                            @if ($o->elsewhere)
                                <strong>{{ $o->elsewhere->employee_name }}</strong>
                                <span class="text-muted">في {{ $o->elsewhere->site?->name }}</span>
                            @else
                                <span class="text-muted">— مجهولٌ تماماً —</span>
                            @endif
                        </td>

                        {{-- الخليةُ تتبع العمود لا الزرّ --}}
                        @can('pins.create')
                            <td class="no-print">
                                @if ($o->elsewhere && $o->site_id)
                                    <form method="POST" action="{{ route('pins.store') }}">
                                        @csrf
                                        <input type="hidden" name="site_id" value="{{ $o->site_id }}">
                                        <input type="hidden" name="device_pin" value="{{ $o->device_pin }}">
                                        <input type="hidden" name="employee_name" value="{{ $o->elsewhere->employee_name }}">
                                        <input type="hidden" name="employee_ref" value="{{ $o->elsewhere->employee_ref }}">
                                        <input type="hidden" name="job_title" value="{{ $o->elsewhere->job_title }}">
                                        <button class="btn btn-sm btn-outline-primary py-0">انسخ الاسم</button>
                                    </form>
                                @endif
                            </td>
                        @endcan
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div></div>
@endif

<div class="row g-3">
    @canany(['pins.create'])
        <div class="col-lg-4">
            <div class="card mb-3"><div class="card-body">
                <h2 class="h6">ربطٌ واحد</h2>
                <form method="POST" action="{{ route('pins.store') }}">
                    @csrf
                    <div class="mb-2">
                        <label class="form-label small">الموقع</label>
                        <select name="site_id" class="form-select form-select-sm" required>
                            <option value="">اختر…</option>
                            @foreach ($sites as $s)
                                <option value="{{ $s->id }}" @selected($siteId === $s->id)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">رقمه في الجهاز</label>
                        <input name="device_pin" class="form-control form-control-sm mono" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">اسم الموظف</label>
                        <input name="employee_name" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">رقمه في دابوق</label>
                        <input name="employee_ref" class="form-control form-control-sm">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">المسمّى</label>
                        <input name="job_title" class="form-control form-control-sm">
                    </div>
                    <button class="btn btn-sm btn-primary w-100" style="background: var(--brand); border: 0;">إضافة</button>
                </form>
            </div></div>

            <div class="card"><div class="card-body">
                <h2 class="h6">لصقُ قائمة</h2>

                @if ($prefill !== '')
                    {{--
                        الأرقامُ مكتوبةٌ سلفاً وينقصها الأسماء. وهذا يمنع
                        أكثرَ الخطأ شيوعاً: أن يُقرأ رقمٌ من جدولٍ ويُكتب
                        في مربّعٍ آخر فيسقط رقمٌ أو يُبدَّل.
                    --}}
                    <div class="alert alert-success py-2 small mb-2">
                        <i class="bi bi-check2-circle"></i>
                        كُتبت لك <strong>{{ $orphans->count() }}</strong> رقماً بلا اسم، مرتَّبةً بالأكثر بصماتٍ أوّلاً.
                        <strong>اكتب الاسمَ بعد الفاصلة في كل سطر</strong> واحذف ما لا تعرفه.
                    </div>
                @else
                    <p class="small text-muted">
                        سطرٌ لكل موظف: <span class="mono">الرقم، الاسم، رقمه في دابوق، المسمّى</span>
                        — والفاصلةُ أو الجدولةُ تفصل. والموجودُ يُحدَّث ولا يُكرَّر.
                        <br><strong>واختر موقعاً في المرشّح أعلاه</strong> لتُكتب لك أرقامُه بلا اسمٍ جاهزةً.
                    </p>
                @endif

                <form method="POST" action="{{ route('pins.import') }}">
                    @csrf
                    <div class="mb-2">
                        <select name="site_id" class="form-select form-select-sm" required>
                            <option value="">اختر الموقع…</option>
                            @foreach ($sites as $s)
                                <option value="{{ $s->id }}" @selected($siteId === $s->id)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <textarea name="list" rows="{{ $prefill !== '' ? 10 : 6 }}"
                              class="form-control form-control-sm mono mb-2" required dir="ltr"
                              placeholder="1, أحمد محمد, 1043&#10;2, سالم عبدالله, 1044">{{ old('list', $prefill) }}</textarea>
                    <button class="btn btn-sm btn-outline-primary w-100">استيراد</button>
                </form>
            </div></div>
        </div>
    @endcanany

    <div class="col-lg-8">
        <div class="card"><div class="card-body">
            <form class="row g-2 mb-3 no-print">
                <div class="col-md-5">
                    <select name="site_id" class="form-select form-select-sm">
                        <option value="">كل المواقع</option>
                        @foreach ($sites as $s)
                            <option value="{{ $s->id }}" @selected($siteId === $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-5">
                    <input name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="اسم أو رقم…">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-outline-secondary w-100">بحث</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th>الموقع</th><th>الرقم</th><th>الاسم</th><th>رقمه في دابوق</th><th>المسمّى</th>
                        {{--
                            التفعيلُ كان مخفيّاً، فعُطّلت أرقامٌ ولا يُرى
                            سببُ اختفائها من الكشوف. وما يؤثّر في كشفٍ
                            يجب أن يُرى في الشاشة التي تضبطه.
                        --}}
                        <th class="text-center">مفعّل</th>
                        @canany(['pins.edit', 'pins.delete'])<th class="no-print"></th>@endcanany
                    </tr></thead>
                    <tbody>
                    @forelse ($pins as $pin)
                        <tr>
                            <td class="small">{{ $pin->site?->name }}</td>
                            <td class="num mono">{{ $pin->device_pin }}</td>
                            <td>
                                @can('pins.edit')
                                    <input form="p-{{ $pin->id }}" name="employee_name" value="{{ $pin->employee_name }}"
                                           class="form-control form-control-sm">
                                @else
                                    {{ $pin->employee_name }}
                                @endcan
                            </td>
                            <td>
                                @can('pins.edit')
                                    <input form="p-{{ $pin->id }}" name="employee_ref" value="{{ $pin->employee_ref }}"
                                           class="form-control form-control-sm mono" style="max-width: 7rem">
                                @else
                                    {{ $pin->employee_ref }}
                                @endcan
                            </td>
                            <td>
                                @can('pins.edit')
                                    <input form="p-{{ $pin->id }}" name="job_title" value="{{ $pin->job_title }}"
                                           class="form-control form-control-sm">
                                @else
                                    {{ $pin->job_title }}
                                @endcan
                            </td>

                            <td class="text-center">
                                @can('pins.edit')
                                    <input form="p-{{ $pin->id }}" type="checkbox" name="is_active" value="1"
                                           class="form-check-input" @checked($pin->is_active)>
                                @else
                                    {!! $pin->is_active ? '✓' : '<span class="text-danger">✗</span>' !!}
                                @endcan
                            </td>

                            @canany(['pins.edit', 'pins.delete'])
                                <td class="no-print text-nowrap">
                                    @can('pins.edit')
                                        <button form="p-{{ $pin->id }}" class="btn btn-sm btn-outline-success py-0">حفظ</button>
                                    @endcan
                                    @can('pins.delete')
                                        <button form="d-{{ $pin->id }}" class="btn btn-sm btn-outline-danger py-0"
                                                onclick="return confirm('حذفُ الربط لا يمحو البصمات — تبقى بأرقامها بلا اسم. متأكّد؟')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    @endcan
                                </td>
                            @endcanany
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">لا ربطَ بعد.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2 no-print">{{ $pins->links() }}</div>
        </div></div>
    </div>
</div>

{{-- النماذجُ خارج الجدول، والحقولُ تُنسب إليها بالسمة form --}}
@foreach ($pins as $pin)
    @can('pins.edit')
        <form id="p-{{ $pin->id }}" method="POST" action="{{ route('pins.update', $pin) }}" class="d-none">
            @csrf @method('PUT')
            <input type="hidden" name="site_id" value="{{ $pin->site_id }}">
            <input type="hidden" name="device_pin" value="{{ $pin->device_pin }}">
        </form>
    @endcan
    @can('pins.delete')
        <form id="d-{{ $pin->id }}" method="POST" action="{{ route('pins.destroy', $pin) }}" class="d-none">
            @csrf @method('DELETE')
        </form>
    @endcan
@endforeach

@endsection
