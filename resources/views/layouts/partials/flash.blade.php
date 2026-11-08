@foreach (['success', 'error', 'warning', 'info'] as $type)
    @if (session()->has($type))
        <span data-sgms-flash data-type="{{ $type }}" data-message="{{ session($type) }}" hidden></span>
    @endif
@endforeach
