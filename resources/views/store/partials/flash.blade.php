@foreach (['success' => false, 'error' => true] as $key => $isError)
    @if (session($key))
        <div class="sf-container">
            <div class="sf-notice {{ $isError ? 'sf-notice--error' : '' }}" role="{{ $isError ? 'alert' : 'status' }}">
                <x-store.icon :name="$isError ? 'info' : 'check'" />
                <span>{{ session($key) }}</span>
            </div>
        </div>
    @endif
@endforeach
