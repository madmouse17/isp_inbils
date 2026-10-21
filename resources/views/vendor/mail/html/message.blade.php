@props(['brand' => null])
@php
    $brandName = $brand['name'] ?? config('app.name');
    $brandLogo = $brand['logo_url'] ?? null;
@endphp
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
@if ($brandLogo)
<img src="{{ $brandLogo }}" class="logo" alt="{!! e($brandName) !!}" style="height: auto; width: auto; max-height: 64px; max-width: 200px;">
@else
<span>{{ $brandName }}</span>
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ \App\Support\MailBrand::literal($brandName) }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
