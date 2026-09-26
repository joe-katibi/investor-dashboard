@php
    // Sign-in / password-reset pages show a larger stacked logo; everywhere else it sits inline in the sidebar / top bar.
    $stacked = request()->routeIs('filament.*.auth.*');
@endphp
<span @class(['oms-brand', 'oms-brand-stacked' => $stacked])>
    <img src="{{ asset('images/oms-logo-128.png') }}" alt="" class="oms-brand-icon" width="128" height="128">
    <span class="oms-brand-name">{{ config('app.name') }}</span>
</span>
