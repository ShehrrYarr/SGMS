@props(['status', 'label' => null])

@php
    $map = [
        'paid' => 'success', 'active' => 'success', 'completed' => 'success',
        'partial' => 'warning', 'pending_exchange' => 'info', 'trial' => 'info',
        'unpaid' => 'danger', 'suspended' => 'danger', 'inactive' => 'secondary',
        'cash' => 'success', 'bank' => 'primary', 'split' => 'info', 'credit' => 'warning', 'exchange' => 'info', 'wallet' => 'primary',
        'owner' => 'primary', 'manager' => 'info', 'cashier' => 'secondary',
        'pending' => 'warning', 'confirmed' => 'info', 'shipped' => 'primary', 'delivered' => 'success', 'cancelled' => 'secondary',
        'awaiting' => 'info', 'refunded' => 'secondary', 'approved' => 'success', 'rejected' => 'danger',
    ];
    $color = $map[$status] ?? 'secondary';
    $label = $label ?? ucwords(str_replace('_', ' ', (string) $status));
@endphp

<span {{ $attributes->merge(['class' => "badge bg-{$color}-subtle text-{$color} fw-medium"]) }}>{{ $label }}</span>
