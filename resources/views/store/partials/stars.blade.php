{{-- $rating (0-5, may be fractional) --}}
@php($rating = round((float) $rating * 2) / 2)
<span class="sf-stars" role="img" aria-label="Rated {{ rtrim(rtrim(number_format($rating, 1), '0'), '.') }} out of 5">
    @for ($i = 1; $i <= 5; $i++)
        <x-store.icon name="star" :class="\Illuminate\Support\Arr::toCssClasses(['is-on' => $i <= $rating, 'is-half' => $i - 0.5 == $rating])" />
    @endfor
</span>
