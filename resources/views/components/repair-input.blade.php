@props(['name', 'label', 'limit', 'rows' => null, 'required' => false])

<div>
    <label class="field-label" for="{{ $name }}">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</label>
    @if ($rows)
        <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $rows }}" maxlength="{{ $limit }}" class="field-input" @required($required) @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror {{ $attributes }}>{{ old($name) }}</textarea>
    @else
        <input id="{{ $name }}" name="{{ $name }}" value="{{ old($name) }}" maxlength="{{ $limit }}" class="field-input" @required($required) @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror {{ $attributes }}>
    @endif
    @error($name) <p id="{{ $name }}-error" class="field-error">{{ $message }}</p> @enderror
</div>
