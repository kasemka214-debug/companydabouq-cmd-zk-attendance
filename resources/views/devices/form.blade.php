@extends('layouts.app')
@section('title', $device->exists ? 'تعديل جهاز' : 'جهازٌ جديد')

@section('content')

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card"><div class="card-body p-4">
            <h1 class="h5 mb-4">{{ $device->exists ? 'تعديل: '.$device->name : 'جهازٌ جديد' }}</h1>

            <form method="POST" action="{{ $device->exists ? route('devices.update', $device) : route('devices.store') }}">
                @csrf
                @if ($device->exists) @method('PUT') @endif

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">الرقم التسلسلي <span class="text-danger">*</span></label>
                        <input name="serial_number" value="{{ old('serial_number', $device->serial_number) }}"
                               class="form-control mono" required>
                        <div class="form-text">
                            مكتوبٌ على ملصقٍ خلف الجهاز، أو في شاشته:
                            <span class="mono">القائمة ← النظام ← معلومات الجهاز</span>.
                            وهو هويّةُ الجهاز الوحيدة عندنا — إن أخطأتَ فيه حرفاً رُدّ كلُّ ما يرسله.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">اسمُ الجهاز <span class="text-danger">*</span></label>
                        <input name="name" value="{{ old('name', $device->name) }}" class="form-control" required
                               placeholder="مثلاً: بوابة المخزن">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">الموقع</label>
                        <select name="site_id" class="form-select">
                            <option value="">— بلا موقع —</option>
                            @foreach ($sites as $s)
                                <option value="{{ $s->id }}" @selected((int) old('site_id', $device->site_id) === $s->id)>
                                    {{ $s->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            الموقعُ يحدّد أيَّ خريطةِ أرقامٍ تُقرأ بها بصماتُ هذا الجهاز.
                            وأجهزةُ الموقع الواحد تُجمع في التقرير جهازاً واحداً.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">عناوينُ IP المسموحة</label>
                        <input name="allowed_ips" value="{{ old('allowed_ips', $device->allowed_ips) }}"
                               class="form-control mono" placeholder="مثلاً: 41.23.5.7, 41.23.5.8">
                        <div class="form-text">
                            اتركه فارغاً إن كان إنترنتُ الموقع بعنوانٍ متغيّر.
                            وإن كان ثابتاً فاملأه — بروتوكولُ الجهاز بلا مصادقة، وهذا سدٌّ إضافي.
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">ملاحظة</label>
                        <textarea name="note" rows="2" class="form-control">{{ old('note', $device->note) }}</textarea>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="checkbox" name="is_active" value="1" id="act" class="form-check-input"
                                   @checked(old('is_active', $device->is_active ?? true))>
                            <label for="act" class="form-check-label">
                                مُفعّل — والمُطفأُ يُردّ عليه بلا قَبولِ بيانات، وسجلُّه باقٍ.
                            </label>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <div class="d-flex gap-2">
                    <button class="btn btn-primary" style="background: var(--brand); border: 0;">حفظ</button>
                    <a href="{{ route('devices.index') }}" class="btn btn-outline-secondary">رجوع</a>

                    @if ($device->exists)
                        @can('devices.delete')
                            <button form="destroy" class="btn btn-outline-danger ms-auto"
                                    onclick="return confirm('حذفُ الجهاز يمحو نبضاتِه كلَّها. متأكّد؟')">
                                حذف
                            </button>
                        @endcan
                    @endif
                </div>
            </form>
        </div></div>
    </div>
</div>

@if ($device->exists)
    @can('devices.delete')
        <form id="destroy" method="POST" action="{{ route('devices.destroy', $device) }}" class="d-none">
            @csrf @method('DELETE')
        </form>
    @endcan
@endif

@endsection
