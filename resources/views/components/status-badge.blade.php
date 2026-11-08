@props(['status'])

@php
    $map = [
        'paid' => 'success', 'active' => 'success', 'completed' => 'success',
        'partial' => 'warning', 'pending_exchange' => 'info', 'trial' => 'info',
        'unpaid' => 'danger', 'suspended' => 'danger', 'inactive' => 'secondary',
        'cash' => 'success', 'bank' => 'primary', 'split' => 'info', 'credit' => 'warning', 'exchange' => 'info', 'wallet' => 'primary',
        'owner' => 'primary', 'manager' => 'info', 'cashier' => 'secondary',
    ];
    $color = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', (string) $status));
@endphp

<span {{ $attributes->merge(['class' => "badge bg-{$color}-subtle text-{$color} fw-medium"]) }}>{{ $label }}</span>
