@props(['name', 'label', 'options', 'default' => null, 'placeholder' => 'กรุณาเลือก'])

<div>
    <label class="field-label" for="{{ $name }}">{{ $label }}</label>
    <select class="field-input" id="{{ $name }}" name="{{ $name }}" required @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror>
        <option value="">{{ $placeholder }}</option>
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected((string) old($name, $default) === (string) $value)>{{ $text }}</option>
        @endforeach
    </select>
    @error($name) <p id="{{ $name }}-error" class="field-error">{{ $message }}</p> @enderror
</div>
