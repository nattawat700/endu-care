@if (isset($profile['logo']))
    <img class="mb-2 max-h-16 max-w-44 object-contain" src="{{ route('branch-settings.document.logo', [$branch, $profile['logo']]) }}" alt="โลโก้ {{ $profile['name'] }}">
@elseif (isset($profile['default_logo']))
    <img class="mb-2 max-h-16 max-w-44 object-contain" src="{{ asset($profile['default_logo']) }}" alt="โลโก้ {{ $profile['name'] }}">
@endif
<p class="font-bold wrap-break-word">{{ $profile['name'] }}</p>
@if (isset($profile['office']) && $profile['office'] !== $profile['name'])
    <p class="wrap-break-word">{{ $profile['office'] }}</p>
@endif
@isset($profile['address']) <p class="whitespace-pre-wrap wrap-break-word text-sm">{{ $profile['address'] }}</p> @endisset
@isset($profile['tax_id']) <p class="text-sm">เลขประจำตัวผู้เสียภาษี {{ $profile['tax_id'] }}</p> @endisset
@isset($profile['phone']) <p class="text-sm">โทรศัพท์ {{ $profile['phone'] }}</p> @endisset
@if (isset($profile['registration_id']) && ($profile['tax_id'] ?? null) !== $profile['registration_id'])
    <p class="text-sm">เลขทะเบียน / เลขประจำตัว {{ $profile['registration_id'] }}</p>
@endif
@isset($profile['email']) <p class="wrap-break-word text-sm">{{ $profile['email'] }}</p> @endisset
