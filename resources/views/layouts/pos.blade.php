@php
    /** Full-screen point of sale layout (no sidebar). */
    $user = auth('web')->user();
    $shop = tenant();
@endphp
<!DOCTYPE html>
<html @include('layouts.partials.html-attributes') data-layout-locked>
<head>
    @include('layouts.partials.head', ['pos' => true, 'charts' => true, 'brand' => $shop->name])
</head>
<body class="pos-body">
    {{ $slot }}
    @include('layouts.partials.flash')
</body>
</html>
