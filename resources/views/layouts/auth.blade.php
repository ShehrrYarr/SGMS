{{-- Guest layout for the three login screens. Expects $title; optional $heading, $tagline. --}}
<!DOCTYPE html>
<html @include('layouts.partials.html-attributes') data-layout-locked>
<head>
    @include('layouts.partials.head')
</head>
<body>
    <div class="auth-shell">
        {{ $slot }}
    </div>
    @include('layouts.partials.flash')
</body>
</html>
